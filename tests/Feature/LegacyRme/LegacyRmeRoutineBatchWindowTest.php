<?php

/**
 * FIX-LEGACY-RME-ROUTINE-OPS-1 — a routine batch is time-bounded, and both
 * ways of opening one are bound by the same rule.
 *
 * THE INCIDENT. An operator opened ROUTINE-20260819-TLK1-01 over SSH. It
 * registered with `planned_start_date = null` and `planned_end_date = null`,
 * because `legacy-rme:wave-admin register` had no options to express them —
 * the ordering rule lived in the wave FormRequest, which the CLI never touches.
 * `legacy-rme:ops-readiness` then reported WATCH: "The batch declares no
 * planned end date, so its approval has no expiry." The runbook had always
 * required that window; nothing enforced it.
 *
 * These tests pin the fix at the layer that makes it true for every caller:
 * the invariant is asserted in `LegacyRmeWaveGovernanceService::createWave()`,
 * so the CLI cannot be a weaker entry point than the browser, and neither can
 * whatever calls it next.
 */

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Services\LegacyRmeSteadyStateOpsService;
use App\Modules\LegacyRme\Services\LegacyRmeWaveGovernanceService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeBatchWindowRule;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\LegacyRme\Support\SeparatePublisherGuard;
use App\Support\Clinical\ClinicalTimezone;
use App\Support\Clinical\InvalidClinicalTimezoneException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);
    legacyRmeBranch('TLK1', 'Cabang Telkomas');
    legacyRmeApproveWave('ROUTINE-APPROVAL-2026-08-19', ['TLK1']);
    legacyRmeAdmittedBranches(['TLK1']);
});

function windowGovernance(): LegacyRmeWaveGovernanceService
{
    return app(LegacyRmeWaveGovernanceService::class);
}

/** The account shape that registers batches: `manage`, never `approve`. */
function windowOperator(): User
{
    return userWith([
        'manage_legacy_rme_migration_operations',
        'view_legacy_rme_migration_operations',
    ]);
}

/**
 * Run `legacy-rme:wave-admin` and return [exitCode, output].
 *
 * Artisan::call rather than the expectsOutput chain: the command emits one
 * multi-line JSON document, and expectsOutputToContain consumes a single
 * writeln per expectation.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runWaveAdmin(array $options): array
{
    $exit = Artisan::call('legacy-rme:wave-admin', $options);

    return [$exit, Artisan::output()];
}

// ---------------------------------------------------------------------
// The shared rule — caller-agnostic by construction
// ---------------------------------------------------------------------

it('normalises a valid window to canonical calendar strings', function () {
    $rule = app(LegacyRmeBatchWindowRule::class);

    expect($rule->normalize('2026-08-19', '2026-08-25'))->toBe([
        'planned_start_date' => '2026-08-19',
        'planned_end_date' => '2026-08-25',
    ]);
});

it('accepts a single-day window because the end date is inclusive', function () {
    // A one-day routine batch is the common case; "through the 19th" is open
    // on the 19th, matching how checkBatchWindow() compares it later.
    $rule = app(LegacyRmeBatchWindowRule::class);

    expect($rule->normalize('2026-08-19', '2026-08-19'))->toBe([
        'planned_start_date' => '2026-08-19',
        'planned_end_date' => '2026-08-19',
    ]);
});

it('refuses a date that does not exist on the calendar', function () {
    // createFromFormat would roll 2026-02-31 into March. Silently migrating
    // under a window nobody typed is worse than refusing it.
    app(LegacyRmeBatchWindowRule::class)->normalize('2026-02-31', '2026-03-05');
})->throws(ValidationException::class);

it('refuses a loosely-parseable date that is not the canonical format', function () {
    app(LegacyRmeBatchWindowRule::class)->normalize('19-08-2026', '2026-08-25');
})->throws(ValidationException::class);

it('refuses an end date earlier than the start date even when the window is optional', function () {
    // A reversed window is malformed whatever the policy says about presence.
    app(LegacyRmeBatchWindowRule::class)->normalize('2026-08-25', '2026-08-19', required: false);
})->throws(ValidationException::class);

it('leaves a fully absent window alone when policy does not require one', function () {
    $rule = app(LegacyRmeBatchWindowRule::class);

    expect($rule->normalize(null, null, required: false))->toBe([
        'planned_start_date' => null,
        'planned_end_date' => null,
    ]);
});

it('requires a bounded window by default', function () {
    expect(LegacyRmeBatchWindowRule::requiredByPolicy())->toBeTrue();
});

it('refuses year zero, which survives PHP but not the database', function () {
    // '0000-01-01' round-trips through createFromFormat, so the format check
    // alone lets it through — and PostgreSQL then rejects it at INSERT as an
    // unhandled QueryException. A 500 where a field error belongs.
    app(LegacyRmeBatchWindowRule::class)->normalize('0000-01-01', '0000-01-02');
})->throws(ValidationException::class);

it('keeps the window required when the env flag is present but empty', function () {
    // `(bool) env(...)` would read an empty LEGACY_RME_ROUTINE_BATCH_WINDOW_REQUIRED=
    // as false and silently switch the invariant off. The fail-safe resolver
    // treats anything that is not an explicit false/0/off/no as ON.
    foreach (['', ' ', null, 'yes', '1', 'true'] as $raw) {
        expect(SeparatePublisherGuard::resolveEnabledFromEnv($raw))->toBeTrue();
    }

    foreach (['false', '0', 'off', 'no', 'FALSE'] as $raw) {
        expect(SeparatePublisherGuard::resolveEnabledFromEnv($raw))->toBeFalse();
    }
});

it('marks the form fields required only while the policy requires them', function () {
    $operator = windowOperator();

    $this->actingAs($operator)
        ->get(route('settings.rme.migration-operations.index'))
        ->assertOk()
        ->assertSee('name="planned_start_date"', escape: false)
        ->assertSee('required', escape: false);

    // Turned off deliberately: the browser must not demand a field the server
    // has been told is optional. The service is still the authority either way.
    config()->set('legacy_rme_operations.routine_batch_window.required', false);

    $html = $this->actingAs($operator)
        ->get(route('settings.rme.migration-operations.index'))
        ->assertOk()
        ->getContent();

    $startField = substr($html, (int) strpos($html, 'name="planned_start_date"'), 400);

    expect($startField)->not->toContain('required');
});

it('lets a misconfigured clinical timezone surface as itself, not as a bad date', function () {
    // ClinicalClock is contractually fail-loud about an unusable timezone — it
    // never degrades to UTC. If the window rule swallowed that, a deployment
    // misconfiguration would be reported on the operator's date field and they
    // would go and "fix" the one thing that was correct.
    config()->set(ClinicalTimezone::CONFIG_KEY, 'Asia/Makasar'); // the canonical typo

    $thrown = null;

    try {
        app(LegacyRmeBatchWindowRule::class)->normalize('2026-08-19', '2026-08-25');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(InvalidClinicalTimezoneException::class);
    expect($thrown)->not->toBeInstanceOf(ValidationException::class);
});

// ---------------------------------------------------------------------
// The service — one rule, both callers
// ---------------------------------------------------------------------

it('refuses to register a batch with no window at all', function () {
    // THE REGRESSION, at the layer that now owns it.
    windowGovernance()->createWave(windowOperator(), 'ROUTINE-NOWINDOW', 'Batch tanpa jendela', ['TLK1'], 25, 25);
})->throws(ValidationException::class);

it('refuses to register a batch that declares only a start date', function () {
    windowGovernance()->createWave(
        windowOperator(), 'ROUTINE-HALFOPEN', 'Batch setengah terbuka', ['TLK1'], 25, 25, '2026-08-19', null
    );
})->throws(ValidationException::class);

it('refuses to register a batch whose window runs backwards', function () {
    windowGovernance()->createWave(
        windowOperator(), 'ROUTINE-REVERSED', 'Batch terbalik', ['TLK1'], 25, 25, '2026-08-25', '2026-08-19'
    );
})->throws(ValidationException::class);

it('writes nothing when the window is refused', function () {
    // Validation runs before the transaction, so a refusal must leave no row
    // behind for an operator to trip over later.
    try {
        windowGovernance()->createWave(windowOperator(), 'ROUTINE-ABORTED', 'Batch gagal', ['TLK1'], 25, 25);
    } catch (ValidationException) {
        // expected
    }

    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-ABORTED')->exists())->toBeFalse();
});

it('persists the window the operator declared', function () {
    $wave = windowGovernance()->createWave(
        windowOperator(), 'ROUTINE-OK', 'Batch rutin', ['TLK1'], 25, 25, '2026-08-19', '2026-08-19'
    );

    expect($wave->planned_start_date?->toDateString())->toBe('2026-08-19');
    expect($wave->planned_end_date?->toDateString())->toBe('2026-08-19');
    expect($wave->status)->toBe(LegacyRmeWaveStatus::DRAFT);
});

// ---------------------------------------------------------------------
// Historical batches are untouched
// ---------------------------------------------------------------------

it('leaves batches registered before this rule readable and unbackfilled', function () {
    // The rule is a creation-time rule. WAVE-1, WAVE-2R and the cancelled
    // ROUTINE-...-01 keep their null dates: they are audit evidence, and
    // rewriting them to satisfy a rule invented afterwards would be a lie.
    $historical = LegacyRmeMigrationWave::factory()->create([
        'code' => 'HISTORICAL-WAVE',
        'status' => LegacyRmeWaveStatus::CANCELLED,
        'planned_start_date' => null,
        'planned_end_date' => null,
    ]);

    expect($historical->fresh()->planned_start_date)->toBeNull();
    expect($historical->fresh()->planned_end_date)->toBeNull();

    // And registering a new, compliant batch does not disturb them.
    windowGovernance()->createWave(
        windowOperator(), 'ROUTINE-NEXT', 'Batch berikutnya', ['TLK1'], 25, 25, '2026-08-19', '2026-08-20'
    );

    expect($historical->fresh()->planned_end_date)->toBeNull();
});

// ---------------------------------------------------------------------
// CLI — the entry point that could not express a window at all
// ---------------------------------------------------------------------

it('exposes the planned window options on the register command', function () {
    $definition = Artisan::all()['legacy-rme:wave-admin']->getDefinition();

    expect($definition->hasOption('planned-start-date'))->toBeTrue();
    expect($definition->hasOption('planned-end-date'))->toBeTrue();
});

it('registers a bounded batch from the CLI and persists the window', function () {
    $operator = windowOperator();

    [$exit] = runWaveAdmin([
        'action' => 'register',
        '--wave' => 'ROUTINE-CLI-01',
        '--name' => 'Batch Rutin CLI',
        '--branches' => 'TLK1',
        '--daily-quota' => 25,
        '--per-branch-daily-quota' => 25,
        '--planned-start-date' => '2026-08-19',
        '--planned-end-date' => '2026-08-19',
        '--actor' => (string) $operator->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(0);

    $wave = LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-CLI-01')->first();

    expect($wave)->not->toBeNull();
    expect($wave->planned_start_date?->toDateString())->toBe('2026-08-19');
    expect($wave->planned_end_date?->toDateString())->toBe('2026-08-19');
});

it('refuses a CLI registration that omits the window', function () {
    // The exact shape of the incident: same command, same operator, no window.
    $operator = windowOperator();

    [$exit] = runWaveAdmin([
        'action' => 'register',
        '--wave' => 'ROUTINE-CLI-NOWINDOW',
        '--name' => 'Batch tanpa jendela',
        '--branches' => 'TLK1',
        '--actor' => (string) $operator->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->not->toBe(0);
    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-CLI-NOWINDOW')->exists())->toBeFalse();
});

it('refuses a CLI registration whose window runs backwards', function () {
    $operator = windowOperator();

    [$exit] = runWaveAdmin([
        'action' => 'register',
        '--wave' => 'ROUTINE-CLI-REVERSED',
        '--name' => 'Batch terbalik',
        '--branches' => 'TLK1',
        '--planned-start-date' => '2026-08-25',
        '--planned-end-date' => '2026-08-19',
        '--actor' => (string) $operator->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->not->toBe(0);
    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-CLI-REVERSED')->exists())->toBeFalse();
});

it('refuses a CLI registration whose window is not a real calendar date', function () {
    $operator = windowOperator();

    [$exit] = runWaveAdmin([
        'action' => 'register',
        '--wave' => 'ROUTINE-CLI-BADDATE',
        '--name' => 'Batch tanggal salah',
        '--branches' => 'TLK1',
        '--planned-start-date' => '2026-02-31',
        '--planned-end-date' => '2026-03-05',
        '--actor' => (string) $operator->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->not->toBe(0);
    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-CLI-BADDATE')->exists())->toBeFalse();
});

it('reports the intended window on a dry run without writing anything', function () {
    $operator = windowOperator();

    [$exit, $output] = runWaveAdmin([
        'action' => 'register',
        '--wave' => 'ROUTINE-CLI-DRY',
        '--name' => 'Batch kering',
        '--branches' => 'TLK1',
        '--planned-start-date' => '2026-08-19',
        '--planned-end-date' => '2026-08-20',
        '--actor' => (string) $operator->getKey(),
        '--json' => true,
    ]);

    expect($exit)->toBe(0);

    $payload = json_decode($output, true);

    expect($payload['applied'])->toBeFalse();
    expect($payload['planned_start_date'])->toBe('2026-08-19');
    expect($payload['planned_end_date'])->toBe('2026-08-20');
    expect($payload['batch_window_required'])->toBeTrue();

    // Genuinely read-only.
    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-CLI-DRY')->exists())->toBeFalse();
});

it('leaves the other CLI actions working', function () {
    // The register path changed; approve must not have.
    $operator = windowOperator();
    $checker = userWith(['approve_legacy_rme_migration_wave', 'view_legacy_rme_migration_operations']);

    runWaveAdmin([
        'action' => 'register',
        '--wave' => 'ROUTINE-CLI-FLOW',
        '--name' => 'Batch alur',
        '--branches' => 'TLK1',
        '--planned-start-date' => '2026-08-19',
        '--planned-end-date' => '2026-08-20',
        '--actor' => (string) $operator->getKey(),
        '--apply' => true,
    ]);

    [$exit] = runWaveAdmin([
        'action' => 'approve',
        '--wave' => 'ROUTINE-CLI-FLOW',
        '--actor' => (string) $checker->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->toBe(0);
    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-CLI-FLOW')->first()->status)
        ->toBe(LegacyRmeWaveStatus::APPROVED);
});

// ---------------------------------------------------------------------
// HTTP — the form that never offered the fields
// ---------------------------------------------------------------------

it('offers both planned window fields on the registration form', function () {
    $this->actingAs(windowOperator())
        ->get(route('settings.rme.migration-operations.index'))
        ->assertOk()
        ->assertSee('name="planned_start_date"', escape: false)
        ->assertSee('name="planned_end_date"', escape: false)
        ->assertSee('Tanggal Mulai Batch')
        ->assertSee('Tanggal Berakhir Batch');
});

it('registers a bounded batch through the form', function () {
    $this->actingAs(windowOperator())
        ->post(route('settings.rme.migration-operations.store'), [
            'code' => 'ROUTINE-HTTP-01',
            'name' => 'Batch Rutin HTTP',
            'branch_codes' => ['TLK1'],
            'daily_quota' => 25,
            'per_branch_daily_quota' => 25,
            'planned_start_date' => '2026-08-19',
            'planned_end_date' => '2026-08-20',
        ])
        ->assertSessionHasNoErrors();

    $wave = LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-HTTP-01')->first();

    expect($wave)->not->toBeNull();
    expect($wave->planned_start_date?->toDateString())->toBe('2026-08-19');
    expect($wave->planned_end_date?->toDateString())->toBe('2026-08-20');
});

it('refuses a form submission with no window and reports it on the field', function () {
    // Posting without the inputs is exactly what an old bookmark or a scripted
    // client does; the server refuses rather than trusting the form.
    $this->actingAs(windowOperator())
        ->post(route('settings.rme.migration-operations.store'), [
            'code' => 'ROUTINE-HTTP-NOWINDOW',
            'name' => 'Batch tanpa jendela',
            'branch_codes' => ['TLK1'],
        ])
        ->assertSessionHasErrors('planned_start_date');

    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-HTTP-NOWINDOW')->exists())->toBeFalse();
});

it('refuses a form submission whose window runs backwards', function () {
    $this->actingAs(windowOperator())
        ->post(route('settings.rme.migration-operations.store'), [
            'code' => 'ROUTINE-HTTP-REVERSED',
            'name' => 'Batch terbalik',
            'branch_codes' => ['TLK1'],
            'planned_start_date' => '2026-08-25',
            'planned_end_date' => '2026-08-19',
        ])
        ->assertSessionHasErrors('planned_end_date');

    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-HTTP-REVERSED')->exists())->toBeFalse();
});

it('keeps registration authorization exactly where it was', function () {
    // Adding fields to a form must not widen who may submit it. An account
    // that may only APPROVE still may not register.
    $this->actingAs(userWith(['approve_legacy_rme_migration_wave', 'view_legacy_rme_migration_operations']))
        ->post(route('settings.rme.migration-operations.store'), [
            'code' => 'ROUTINE-HTTP-FORBIDDEN',
            'name' => 'Batch terlarang',
            'branch_codes' => ['TLK1'],
            'planned_start_date' => '2026-08-19',
            'planned_end_date' => '2026-08-20',
        ])
        ->assertForbidden();

    expect(LegacyRmeMigrationWave::query()->where('code', 'ROUTINE-HTTP-FORBIDDEN')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------
// FIX-LEGACY-WAVE4-WINDOW-EXTENSION-1 — extending an approved window
//
// THE GAP. `createWave()` was the only writer of `planned_end_date` and it
// create()s against a UNIQUE code, so a registered batch's window was
// immutable. The only reaction to a lapsed approval was cancel-and-re-register,
// which discards the branch enrollments and operator assignments — a
// destructive answer to an administrative question. Production hit this on
// WAVE-4 with a window that ended 2026-09-30.
//
// These tests pin the capability AND its narrowness: it moves one column
// forward, it is the approver's to use rather than the manager's, and it cannot
// be turned into a way to retroactively shorten an approval.
// ---------------------------------------------------------------------

/** The account shape that may extend: `approve`, never `manage`. */
function windowApprover(): User
{
    return userWith([
        'approve_legacy_rme_migration_wave',
        'view_legacy_rme_migration_operations',
    ]);
}

/** An ACTIVE wave carrying a known window, built through the real lifecycle. */
function windowActiveWave(string $code, ?string $start, ?string $end): LegacyRmeMigrationWave
{
    $wave = windowGovernance()->createWave(
        actor: windowOperator(),
        code: $code,
        name: 'Batch '.$code,
        branchCodes: ['TLK1'],
        dailyQuota: 25,
        perBranchDailyQuota: 10,
        plannedStartDate: $start,
        plannedEndDate: $end,
    );

    windowGovernance()->approve(windowApprover(), $wave);

    return windowGovernance()->activate(windowOperator(), $wave->refresh());
}

it('lets an approver extend an eligible wave and records the move', function () {
    $wave = windowActiveWave('EXT-OK', '2026-08-19', '2026-08-20');
    $approver = windowApprover();

    $updated = windowGovernance()->extendBatchWindow($approver, $wave, '2026-09-30', 'owner approval 2026-08-21');

    expect($updated->planned_end_date->toDateString())->toBe('2026-09-30')
        // (4) the start date is never touched
        ->and($updated->planned_start_date->toDateString())->toBe('2026-08-19')
        // (3) nothing else moves
        ->and($updated->status)->toBe(LegacyRmeWaveStatus::ACTIVE)
        ->and($updated->approval_reference)->toBe('ROUTINE-APPROVAL-2026-08-19');

    // (10) exactly one audit row, carrying BOTH ends of the move.
    $rows = DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::WAVE_WINDOW_EXTENDED)
        ->get();

    expect($rows)->toHaveCount(1);

    $payload = json_decode((string) $rows->first()->new_values, true);

    expect($payload['wave'])->toBe('EXT-OK')
        ->and($payload['planned_end_date_before'])->toBe('2026-08-20')
        ->and($payload['planned_end_date_after'])->toBe('2026-09-30')
        ->and($payload['planned_start_date'])->toBe('2026-08-19')
        ->and($payload['extension_reason'])->toBe('owner approval 2026-08-21')
        ->and((int) $rows->first()->performed_by)->toBe((int) $approver->getKey());
});

it('refuses the manager who runs the rollout, because extending is an approval', function () {
    // Separation of duties: `manage` opens and drives a batch; only `approve`
    // may grant it more time. Otherwise the operator running the migration
    // could quietly extend their own approval.
    $wave = windowActiveWave('EXT-SOD', '2026-08-19', '2026-08-20');

    [$exit, $output] = runWaveAdmin([
        'action' => 'extend',
        '--wave' => 'EXT-SOD',
        '--planned-end-date' => '2026-09-30',
        '--reason' => 'trying it as the manager',
        '--actor' => (string) windowOperator()->getKey(),
        '--apply' => true,
    ]);

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('tidak memiliki izin');

    expect(LegacyRmeMigrationWave::query()->where('code', 'EXT-SOD')->firstOrFail()->planned_end_date->toDateString())
        ->toBe('2026-08-20');
});

it('denies the extend ability to everyone except an approver', function () {
    // The authorization boundary itself, asserted where it lives. The service
    // deliberately does not re-check permissions — the policy is the single
    // answer, and both the CLI and any future HTTP surface ask it.
    $wave = windowActiveWave('EXT-POLICY', '2026-08-19', '2026-08-20');

    expect(windowApprover()->can('approve', $wave))->toBeTrue()
        // The manager drives the rollout but cannot grant it more time.
        ->and(windowOperator()->can('approve', $wave))->toBeFalse()
        ->and(User::factory()->create()->can('approve', $wave))->toBeFalse();
});

it('leaves assignments, enrollment and quota exactly as they were', function () {
    $wave = windowActiveWave('EXT-UNTOUCHED', '2026-08-19', '2026-08-20');
    $operator = windowOperator();
    legacyRmeAssignOperator($operator, 'TLK1', 'EXT-UNTOUCHED');

    $branchBefore = $wave->branches()->where('branch_code', 'TLK1')->firstOrFail();
    $assignmentsBefore = DB::table('ops_rme_legacy_wave_operators')->orderBy('id')->get()->toArray();

    windowGovernance()->extendBatchWindow(windowApprover(), $wave, '2026-10-31', 'owner approval recorded');

    $after = $wave->refresh();
    $branchAfter = $after->branches()->where('branch_code', 'TLK1')->firstOrFail();

    // (7) quota untouched — both levels
    expect($after->daily_quota)->toBe(25)
        ->and($after->per_branch_daily_quota)->toBe(10)
        // (6) branch admission/enrollment untouched
        ->and($branchAfter->status)->toBe($branchBefore->status)
        ->and($branchAfter->branch_id)->toBe($branchBefore->branch_id)
        ->and($after->approved_branch_codes)->toBe($wave->approved_branch_codes)
        // (5) assignments untouched, byte for byte
        ->and(DB::table('ops_rme_legacy_wave_operators')->orderBy('id')->get()->toArray())
        ->toEqual($assignmentsBefore);
});

it('refuses to shorten a window, and names the verb that does close a batch early', function () {
    // (8) An earlier end date would retroactively de-approve work already
    // accepted inside the window. `drain` and `complete` are the honest verbs.
    $wave = windowActiveWave('EXT-SHORTEN', '2026-08-19', '2026-09-30');

    $thrown = null;

    try {
        windowGovernance()->extendBatchWindow(windowApprover(), $wave, '2026-08-25', 'shrink it, please');
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->errors()[LegacyRmeBatchWindowRule::FIELD_END][0])->toContain('drain');

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-09-30');
});

it('accepts an idempotent re-extension to the same date', function () {
    // Not a shortening, so not refused: re-running the approved extension must
    // be safe for an operator who is unsure whether the first attempt landed.
    $wave = windowActiveWave('EXT-SAME', '2026-08-19', '2026-09-30');

    $updated = windowGovernance()->extendBatchWindow(windowApprover(), $wave, '2026-09-30', 'same date again, confirming');

    expect($updated->planned_end_date->toDateString())->toBe('2026-09-30');
});

it('rejects a malformed date rather than reinterpreting it', function () {
    // (9) Carbon::parse() would happily accept "31-10-2026" or "next friday".
    $wave = windowActiveWave('EXT-BADDATE', '2026-08-19', '2026-08-20');

    foreach (['31-10-2026', 'next friday', '2026-13-45', 'tomorrow'] as $bad) {
        expect(fn () => windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), $bad, 'owner approval recorded'))
            ->toThrow(ValidationException::class);
    }

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-08-20');
});

it('refuses to remove the expiry altogether', function () {
    $wave = windowActiveWave('EXT-NOEXPIRY', '2026-08-19', '2026-08-20');

    foreach ([null, '', '   '] as $empty) {
        expect(fn () => windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), $empty, 'owner approval recorded'))
            ->toThrow(ValidationException::class);
    }

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-08-20');
});

it('requires a reason, because an extension has to say what it rests on', function () {
    $wave = windowActiveWave('EXT-NOREASON', '2026-08-19', '2026-08-20');

    // Blank AND too-short: the shared assertReason() floor
    // (min_reason_length, 10) applies here exactly as it does to pause, drain,
    // cancel and complete. "ok" is not an audit trail.
    foreach (['', '   ', 'x', 'too short'] as $blank) {
        expect(fn () => windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), '2026-09-30', $blank))
            ->toThrow(ValidationException::class);
    }

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-08-20');
});

it('refuses to extend a closed batch rather than reopening it', function () {
    $wave = windowActiveWave('EXT-CLOSED', '2026-08-19', '2026-08-20');
    windowGovernance()->cancelWave(windowOperator(), $wave, 'closed for the test');

    expect(fn () => windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), '2026-09-30', 'reopen me if you can'))
        ->toThrow(ValidationException::class);

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-08-20');
});

it('serializes competing extensions by re-reading the row under the lock', function () {
    // (11) The guard is not a stale in-memory comparison: the current end date
    // is read back INSIDE the transaction after lockForUpdate(). Two operators
    // racing therefore cannot both extend from the same stale value — the
    // second sees the first's write.
    //
    // Both callers below hold the SAME pre-race model instance, whose
    // planned_end_date is still 2026-08-20. The first moves it to 2026-10-31.
    // The second asks for 2026-09-30: later than the value it is holding, but
    // EARLIER than what is now committed. It is refused, which it could only be
    // by having re-read under the lock.
    $wave = windowActiveWave('EXT-RACE', '2026-08-19', '2026-08-20');
    $stale = LegacyRmeMigrationWave::query()->findOrFail($wave->getKey());

    windowGovernance()->extendBatchWindow(windowApprover(), $stale, '2026-10-31', 'first writer extending');

    expect(fn () => windowGovernance()->extendBatchWindow(windowApprover(), $stale, '2026-09-30', 'second writer extending'))
        ->toThrow(ValidationException::class);

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-10-31');

    // One row per applied extension, never one per attempt.
    expect(DB::table('sys_audit_logs')->where('action', LegacyRmeAuditEvent::WAVE_WINDOW_EXTENDED)->count())->toBe(1);
});

it('clears the expired-window WATCH that ops-readiness reported', function () {
    // (12) The end-to-end point of the whole capability.
    legacyRmeMigrationWave(['TLK1'], 'EXT-READINESS');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'EXT-READINESS')->firstOrFail();
    $wave->forceFill([
        'planned_start_date' => '2026-08-19',
        'planned_end_date' => '2026-09-30',
        'status' => LegacyRmeWaveStatus::ACTIVE,
    ])->save();

    // Stand on a clinical day after the window closed, exactly as production was.
    $this->travelTo('2026-10-04 09:00:00');

    $before = app(LegacyRmeSteadyStateOpsService::class)->readiness(['include_monitoring' => false]);
    $windowBefore = collect($before['checks'])->firstWhere('id', 'batch_window');

    expect($windowBefore['status'])->toBe('WATCH')
        ->and($windowBefore['context']['expired'])->toBeTrue();

    windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), '2026-10-31', 'owner approval 2026-10-04');

    $after = app(LegacyRmeSteadyStateOpsService::class)->readiness(['include_monitoring' => false]);
    $windowAfter = collect($after['checks'])->firstWhere('id', 'batch_window');

    expect($windowAfter['status'])->toBe('GO')
        ->and($windowAfter['summary'])->toContain('inside the batch planned window')
        ->and($windowAfter['context']['planned_end_date'])->toBe('2026-10-31')
        // The start date still reads back as it was registered.
        ->and($windowAfter['context']['planned_start_date'])->toBe('2026-08-19');
});

it('is genuinely read-only without --apply', function () {
    $wave = windowActiveWave('EXT-DRY', '2026-08-19', '2026-08-20');

    [$exit, $output] = runWaveAdmin([
        'action' => 'extend',
        '--wave' => 'EXT-DRY',
        '--planned-end-date' => '2026-10-31',
        '--reason' => 'owner approval recorded',
        '--actor' => (string) windowApprover()->getKey(),
        '--json' => true,
    ]);

    $payload = json_decode($output, true);

    expect($exit)->toBe(0)
        ->and($payload['applied'])->toBeFalse()
        // The dry run shows current -> proposed, not just what was typed.
        ->and($payload['current_planned_end_date'])->toBe('2026-08-20')
        ->and($payload['planned_end_date'])->toBe('2026-10-31');

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-08-20');
    expect(DB::table('sys_audit_logs')->where('action', LegacyRmeAuditEvent::WAVE_WINDOW_EXTENDED)->count())->toBe(0);
});

it('extends through the CLI when the approver applies it', function () {
    $wave = windowActiveWave('EXT-CLI', '2026-08-19', '2026-08-20');

    [$exit, $output] = runWaveAdmin([
        'action' => 'extend',
        '--wave' => 'EXT-CLI',
        '--planned-end-date' => '2026-10-31',
        '--reason' => 'owner approval 2026-10-04',
        '--actor' => (string) windowApprover()->getKey(),
        '--apply' => true,
        '--json' => true,
    ]);

    $payload = json_decode($output, true);

    expect($exit)->toBe(0)
        ->and($payload['applied'])->toBeTrue()
        ->and($payload['planned_end_date'])->toBe('2026-10-31')
        ->and($payload['status'])->toBe(LegacyRmeWaveStatus::ACTIVE)
        // The CLI reports no "before": the only value available to it is the
        // pre-lock one. The authoritative pair lives in the audit row, read
        // under the lock.
        ->and($payload)->not->toHaveKey('planned_end_date_before');

    $payload = json_decode((string) DB::table('sys_audit_logs')
        ->where('action', LegacyRmeAuditEvent::WAVE_WINDOW_EXTENDED)
        ->value('new_values'), true);

    expect($payload['planned_end_date_before'])->toBe('2026-08-20')
        ->and($payload['planned_end_date_after'])->toBe('2026-10-31');

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-10-31')
        ->and($wave->refresh()->planned_start_date->toDateString())->toBe('2026-08-19');
});

it('applies the separate-approver rule, so a creator cannot extend their own batch', function () {
    // Production runs with LEGACY_RME_REQUIRE_SEPARATE_APPROVER=true, and
    // `approve()` has always enforced it. An `extend` that skipped it would be
    // the WEAK side of the maker/checker split rather than the strict side:
    // the wave's creator could grant their own batch more time precisely where
    // the deployment forbids them from approving it.
    config()->set('legacy_rme_operations.require_separate_approver', true);

    // One account that both creates AND holds the approver ability.
    $both = userWith([
        'manage_legacy_rme_migration_operations',
        'approve_legacy_rme_migration_wave',
        'view_legacy_rme_migration_operations',
    ]);

    $wave = windowGovernance()->createWave(
        actor: $both,
        code: 'EXT-SELF',
        name: 'Batch sendiri',
        branchCodes: ['TLK1'],
        dailyQuota: null,
        perBranchDailyQuota: null,
        plannedStartDate: '2026-08-19',
        plannedEndDate: '2026-08-20',
    );
    windowGovernance()->approve(windowApprover(), $wave);
    windowGovernance()->activate(windowOperator(), $wave->refresh());

    expect(fn () => windowGovernance()->extendBatchWindow($both, $wave->refresh(), '2026-10-31', 'extending my own batch'))
        ->toThrow(ValidationException::class);

    // A different approver may.
    $updated = windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), '2026-10-31', 'extending someone elses batch');

    expect($updated->planned_end_date->toDateString())->toBe('2026-10-31');
});

it('refuses to extend a wave whose recorded approval has drifted from the deployment', function () {
    // `approve`, `activate` and `resume` all refuse a drifted record. An
    // `extend` that did not would re-approve an unbound scope for longer —
    // exactly the drift bindingMatches() exists to catch.
    $wave = windowActiveWave('EXT-DRIFT', '2026-08-19', '2026-08-20');

    legacyRmeApproveWave('ROUTINE-APPROVAL-MOVED-ON', ['TLK1']);

    expect(fn () => windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), '2026-10-31', 'extending a drifted batch'))
        ->toThrow(ValidationException::class);

    expect($wave->refresh()->planned_end_date->toDateString())->toBe('2026-08-20');
});

it('extends a legacy wave that carries no start date instead of blaming that field', function () {
    // A wave registered before the window rule existed legitimately has a null
    // start. normalize(…, required: true) throws on that, which would have made
    // this action permanently impossible for that population while reporting a
    // field the caller cannot even supply.
    legacyRmeMigrationWave(['TLK1'], 'EXT-NOSTART');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'EXT-NOSTART')->firstOrFail();
    $wave->forceFill([
        'planned_start_date' => null,
        'planned_end_date' => '2026-09-30',
        'status' => LegacyRmeWaveStatus::ACTIVE,
    ])->save();

    $updated = windowGovernance()->extendBatchWindow(windowApprover(), $wave, '2026-10-31', 'extending a legacy batch');

    expect($updated->planned_end_date->toDateString())->toBe('2026-10-31')
        ->and($updated->planned_start_date)->toBeNull();
});

it('will not hand an expiry-less wave a window that is already over', function () {
    // With no current end date the monotonic guard cannot fire, so without this
    // a method called `extend` would happily write a lapsed window.
    legacyRmeMigrationWave(['TLK1'], 'EXT-NOEND');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'EXT-NOEND')->firstOrFail();
    $wave->forceFill([
        'planned_start_date' => '2019-01-01',
        'planned_end_date' => null,
        'status' => LegacyRmeWaveStatus::ACTIVE,
    ])->save();

    expect(fn () => windowGovernance()->extendBatchWindow(windowApprover(), $wave, '2020-01-01', 'setting a lapsed window'))
        ->toThrow(ValidationException::class);

    // A future date is accepted, which is what setting a first expiry means.
    $updated = windowGovernance()->extendBatchWindow(windowApprover(), $wave->refresh(), '2099-01-01', 'setting a real window');

    expect($updated->planned_end_date->toDateString())->toBe('2099-01-01');
});
