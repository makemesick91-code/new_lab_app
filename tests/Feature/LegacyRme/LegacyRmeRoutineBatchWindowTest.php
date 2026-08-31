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
use App\Modules\LegacyRme\Services\LegacyRmeWaveGovernanceService;
use App\Modules\LegacyRme\Support\LegacyRmeBatchWindowRule;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\LegacyRme\Support\SeparatePublisherGuard;
use App\Support\Clinical\ClinicalTimezone;
use App\Support\Clinical\InvalidClinicalTimezoneException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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
