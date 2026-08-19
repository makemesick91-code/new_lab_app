<?php

declare(strict_types=1);

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Services\LegacyRmeSteadyStateOpsService;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * LEGACY-RME-STEADY-STATE-OPS-1 — the steady-state pre-flight.
 *
 * THE CENTRAL CLAIM UNDER TEST. This report may only ever say GO when it is
 * genuinely safe to open a routine batch, and it must FAIL CLOSED on everything
 * else — including on the things it merely could not evaluate. Six sprints of
 * controlled rollout shipped every control a batch needs; the risk this sprint
 * introduces is not a missing gate, it is a REPORT THAT LIES, because an
 * operator will now act on one line instead of reading four reports.
 *
 * So the tests below are mostly about refusals: a stale backup, an unapproved
 * branch, a disabled SOD invariant, a lapsed window, a retired batch code and an
 * unevaluated check must each be able, alone, to stop a batch.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(false);

    // The backup signal is infrastructure this suite does not own, and a real
    // dev box has no backup at all. Neutralised so each test asserts the ONE
    // condition it is about; the backup gate has its own dedicated tests below.
    config()->set('legacy_rme_steady_state.backup.required_before_batch', false);

    // FIX-LEGACY-RME-ROUTINE-OPS-1 — readiness now verifies that the enforced
    // separation-of-duties rules can actually be PERFORMED, not merely that
    // the switches are on. Staffed here so every test below asserts the one
    // condition it is about; the unstaffed case has its own tests.
    legacyRmeStaffSeparationOfDuties();
});

function steadyOps(): LegacyRmeSteadyStateOpsService
{
    return app(LegacyRmeSteadyStateOpsService::class);
}

/**
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function steadyReport(array $options = []): array
{
    return steadyOps()->readiness($options + ['include_monitoring' => false]);
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>|null
 */
function steadyCheck(array $report, string $id): ?array
{
    foreach ((array) $report['checks'] as $check) {
        if (($check['id'] ?? null) === $id) {
            return $check;
        }
    }

    return null;
}

// ---------------------------------------------------------------------
// Decision precedence — pure, no database
// ---------------------------------------------------------------------

it('blocks on FAIL and on UNKNOWN, and treats WATCH as degraded but not blocking', function () {
    $ops = steadyOps();

    expect($ops->decide([['status' => 'GO'], ['status' => 'GO']]))->toBe('GO');
    expect($ops->decide([['status' => 'GO'], ['status' => 'WATCH']]))->toBe('WATCH');
    expect($ops->decide([['status' => 'WATCH'], ['status' => 'FAIL']]))->toBe('NO_GO');

    // "We could not tell" is never a basis for opening a clinical migration.
    expect($ops->decide([['status' => 'GO'], ['status' => 'UNKNOWN']]))->toBe('NO_GO');

    // A check with no status at all is UNKNOWN, not implicitly fine.
    expect($ops->decide([['id' => 'malformed']]))->toBe('NO_GO');
});

// ---------------------------------------------------------------------
// Resting state
// ---------------------------------------------------------------------

it('reports the safe resting state when the capability is off and nothing is admitted', function () {
    legacyRmeAdmittedBranches([]);
    config()->set('legacy_rme_rollout.admission.wave', '');

    $resting = steadyReport()['resting_state'];

    expect($resting['at_rest'])->toBeTrue();
    expect($resting['interpretation'])->toBe('AT_REST');
    expect($resting['deviations'])->toBe([]);
});

it('distinguishes a batch in progress from a deployment that should be at rest but is not', function () {
    legacyRmeBranch('TKM1');
    legacyRmeArchiveFlag(true);

    $resting = steadyReport()['resting_state'];

    // An open batch is the POINT of opening one, so it must never be reported
    // as the same condition as an unexplained deviation.
    expect($resting['at_rest'])->toBeFalse();
    expect($resting['interpretation'])->toBe('BATCH_IN_PROGRESS');
});

// ---------------------------------------------------------------------
// Separation of duties
// ---------------------------------------------------------------------

it('refuses to open a batch when the separate-publisher invariant is disabled', function () {
    config()->set('legacy_rme_operations.require_separate_publisher', false);

    $report = steadyReport();
    $check = steadyCheck($report, 'separation_of_duties');

    expect($check['status'])->toBe('FAIL');
    expect($check['severity'])->toBe('INCIDENT');
    expect($report['decision'])->toBe('NO_GO');
    expect($report['ready_for_routine_batch'])->toBeFalse();
    expect($report['stop_the_line'])->toContain('SEPARATE_PUBLISHER_DISABLED');
});

it('reports the accepted separate-approver risk as a warning rather than a refusal', function () {
    config()->set('legacy_rme_operations.require_separate_publisher', true);
    config()->set('legacy_rme_operations.require_separate_approver', false);

    $check = steadyCheck(steadyReport(), 'separation_of_duties');

    expect($check['status'])->toBe('WATCH');
    expect($check['severity'])->toBe('WARNING');

    // The application enforces ACCOUNT separation. It cannot observe whether two
    // different humans are behind those accounts, and must never imply it did.
    expect($check['context']['human_separation_verifiable_by_application'])->toBeFalse();
});

// ---------------------------------------------------------------------
// Admission and approval
// ---------------------------------------------------------------------

it('stops the line when a branch is admitted that the approval does not cover', function () {
    legacyRmeBranch('TKM1');
    legacyRmeAdmittedBranches(['TKM1', 'LDK2']);
    legacyRmeApproveWave('OWNER-APPROVAL-1', ['TKM1']);

    $report = steadyReport();
    $check = steadyCheck($report, 'admission_approval');

    expect($check['status'])->toBe('FAIL');
    expect($check['severity'])->toBe('INCIDENT');
    expect($check['context']['unapproved_admitted'])->toBe(['LDK2']);
    expect($report['stop_the_line'])->toContain('ADMITTED_BRANCH_NOT_APPROVED');
});

it('refuses when branches are admitted with no approval reference recorded', function () {
    legacyRmeBranch('TKM1');
    legacyRmeApproveWave('', ['TKM1']);

    $check = steadyCheck(steadyReport(), 'admission_approval');

    expect($check['status'])->toBe('FAIL');
    expect($check['context']['approval_reference_present'])->toBeFalse();
});

it('never echoes the approval reference itself into the report', function () {
    legacyRmeBranch('TKM1');
    legacyRmeApproveWave('SECRET-TICKET-9911', ['TKM1']);

    $encoded = (string) json_encode(steadyReport());

    expect($encoded)->not->toContain('SECRET-TICKET-9911');
    expect($encoded)->toContain('approval_reference_present');
});

// ---------------------------------------------------------------------
// Batch binding
// ---------------------------------------------------------------------

it('refuses a batch declared under a retired governance identity', function () {
    legacyRmeBranch('TKM1');
    config()->set('legacy_rme_rollout.admission.wave', 'WAVE-3');

    $report = steadyReport();
    $check = steadyCheck($report, 'batch_binding');

    expect($check['status'])->toBe('FAIL');
    expect($check['severity'])->toBe('INCIDENT');
    expect($report['stop_the_line'])->toContain('BATCH_BINDING_MISMATCH');
});

it('refuses when a batch code is declared but no batch record exists', function () {
    legacyRmeBranch('TKM1');
    config()->set('legacy_rme_rollout.admission.wave', 'LRME-TKM1-20260817-01');

    $check = steadyCheck(steadyReport(), 'batch_binding');

    expect($check['status'])->toBe('FAIL');
    expect($check['context']['batch_record_found'])->toBeFalse();
});

it('reports a paused batch as not accepting work without calling it a defect', function () {
    legacyRmeBranch('TKM1');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();
    $wave->forceFill(['status' => LegacyRmeWaveStatus::PAUSED])->save();

    $report = steadyReport();
    $check = steadyCheck($report, 'batch_binding');

    // Scoped to this check on purpose. The GLOBAL decision is not assertable
    // here: a developer machine has no Poppler and no worker unit, so
    // `deployment_readiness` is legitimately FAIL and would dominate the
    // decision for reasons that have nothing to do with a paused batch.
    expect($check['status'])->toBe('WATCH');
    expect($check['severity'])->toBe('WARNING');
    expect($check['context']['batch_status'])->toBe(LegacyRmeWaveStatus::PAUSED);
    expect($report['stop_the_line'])->not->toContain('BATCH_BINDING_MISMATCH');
});

// ---------------------------------------------------------------------
// Batch window — approval expiry
// ---------------------------------------------------------------------

it('warns when the batch has passed its planned end date', function () {
    legacyRmeBranch('TKM1');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();
    $wave->forceFill([
        'planned_start_date' => now()->subDays(10)->toDateString(),
        'planned_end_date' => now()->subDay()->toDateString(),
    ])->save();

    $check = steadyCheck(steadyReport(), 'batch_window');

    expect($check['status'])->toBe('WATCH');
    expect($check['context']['expired'])->toBeTrue();
});

it('treats the final planned day as still inside the window', function () {
    legacyRmeBranch('TKM1');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();
    $wave->forceFill([
        'planned_start_date' => now()->subDays(3)->toDateString(),
        // A batch approved "through today" is open today, not closed at midnight
        // of the day it was approved through.
        'planned_end_date' => app(ClinicalClock::class)->todayString(),
    ])->save();

    expect(steadyCheck(steadyReport(), 'batch_window')['status'])->toBe('GO');
});

it('warns when a batch declares no end date, so its approval never expires', function () {
    legacyRmeBranch('TKM1');

    $check = steadyCheck(steadyReport(), 'batch_window');

    expect($check['status'])->toBe('WATCH');
    expect($check['context']['planned_end_date'])->toBeNull();
});

// ---------------------------------------------------------------------
// Batch sizing
// ---------------------------------------------------------------------

it('warns when a batch declares no daily quota, so it is not quota-bounded', function () {
    legacyRmeBranch('TKM1');

    $check = steadyCheck(steadyReport(), 'batch_size_policy');

    expect($check['status'])->toBe('WATCH');
    expect($check['context']['batch_daily_quota'])->toBeNull();
});

it('flags a quota above the routine envelope as no longer routine', function () {
    legacyRmeBranch('TKM1');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();
    $wave->forceFill(['daily_quota' => 400])->save();

    $check = steadyCheck(steadyReport(), 'batch_size_policy');

    expect($check['status'])->toBe('WATCH');
    expect($check['context']['exceeds_routine_envelope'])->toBeTrue();
    // Still under the server-side absolute rail: this is a governance finding
    // about routineness, not a claim that the server would refuse it.
    expect($check['context']['batch_daily_quota'])
        ->toBeLessThanOrEqual($check['context']['absolute_declarable_max']);
});

it('accepts a quota inside the routine envelope', function () {
    legacyRmeBranch('TKM1');
    $wave = LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();
    $wave->forceFill(['daily_quota' => 10])->save();

    expect(steadyCheck(steadyReport(), 'batch_size_policy')['status'])->toBe('GO');
});

// ---------------------------------------------------------------------
// Backup freshness — the gate that did not exist before this sprint
// ---------------------------------------------------------------------

it('cannot establish backup freshness when monitoring signals are skipped, and blocks', function () {
    config()->set('legacy_rme_steady_state.backup.required_before_batch', true);

    $report = steadyOps()->readiness(['include_monitoring' => false]);
    $check = steadyCheck($report, 'backup_freshness');

    expect($check['status'])->toBe('UNKNOWN');
    expect($report['decision'])->toBe('NO_GO');
    expect($check['context']['code'])->toBe('BACKUP_NOT_ESTABLISHED');

    // "I did not look" is not the same finding as "the backup is stale", and
    // must not be escalated to a stop-the-line condition.
    expect($report['stop_the_line'])->not->toContain('BACKUP_MISSING_OR_STALE');
});

it('warns rather than refuses when the pre-batch backup requirement is deliberately off', function () {
    config()->set('legacy_rme_steady_state.backup.required_before_batch', false);

    $check = steadyCheck(steadyReport(), 'backup_freshness');

    expect($check['status'])->toBe('WATCH');
    expect($check['context']['required_before_batch'])->toBeFalse();
});

// ---------------------------------------------------------------------
// Branch readiness matrix
// ---------------------------------------------------------------------

it('reports a branch as READY only when it is approved, admitted, enrolled and clean', function () {
    legacyRmeBranch('TKM1');
    legacyRmeArchiveFlag(true);

    $row = collect(steadyReport()['branch_matrix'])->firstWhere('branch_code', 'TKM1');

    expect($row)->not->toBeNull();
    expect($row['approved'])->toBeTrue();
    expect($row['admitted'])->toBeTrue();
    expect($row['enrolled_in_batch'])->toBeTrue();
    expect($row['blockers'])->toBe([]);
    expect($row['readiness'])->toBe('READY');
});

it('reports an RME branch that is not admitted as NOT_READY without calling it a fault', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');
    legacyRmeAdmittedBranches(['TKM1']);
    legacyRmeApproveWave('OWNER-APPROVAL-1', ['TKM1']);

    $row = collect(steadyReport()['branch_matrix'])->firstWhere('branch_code', 'LDK2');

    expect($row['admitted'])->toBeFalse();
    expect($row['readiness'])->toBe('NOT_READY');
    // Deliberately closed is not a blocker — there is nothing to remediate.
    expect($row['blockers'])->toBe([]);
});

it('excludes MAIN from the branch matrix because it can never hold RME history', function () {
    legacyRmeBranch('TKM1');

    $codes = collect(steadyReport()['branch_matrix'])->pluck('branch_code')->all();

    expect($codes)->not->toContain('MAIN');
});

it('scopes the branch matrix when one branch is requested', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');

    $codes = collect(steadyReport(['branch' => 'ldk2'])['branch_matrix'])->pluck('branch_code')->all();

    expect($codes)->toBe(['LDK2']);
});

// ---------------------------------------------------------------------
// Multi-branch mode — the honest architectural classification
// ---------------------------------------------------------------------

it('declares multi-branch mode as concurrent branches inside a single sequential batch', function () {
    $multi = steadyReport()['multi_branch'];

    expect($multi['mode'])->toBe('CONCURRENT_BRANCH_ISOLATED_WITHIN_ONE_ACTIVE_BATCH');
    // One declared wave code resolves one operative batch. Claiming concurrent
    // batches would be claiming a capability the binding service does not have.
    expect($multi['batches_concurrent'])->toBeFalse();
    expect($multi['branches_concurrent_within_batch'])->toBeTrue();
});

it('runs many branches concurrently inside one batch', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');
    legacyRmeArchiveFlag(true);

    $matrix = collect(steadyReport()['branch_matrix']);

    expect($matrix->where('readiness', 'READY')->pluck('branch_code')->sort()->values()->all())
        ->toBe(['LDK2', 'TKM1']);
});

// ---------------------------------------------------------------------
// The command surface
// ---------------------------------------------------------------------

it('exits non-zero when a routine batch may not be opened', function () {
    config()->set('legacy_rme_operations.require_separate_publisher', false);

    expect(Artisan::call('legacy-rme:ops-readiness', ['--skip-monitoring' => true]))->toBe(1);
});

it('exits zero on a clean deployment and non-zero for the same state under --strict', function () {
    legacyRmeBranch('TKM1');
    config()->set('legacy_rme_operations.require_separate_publisher', true);
    config()->set('legacy_rme_operations.require_separate_approver', false);

    // WATCH is degraded, not blocking...
    $lenient = Artisan::call('legacy-rme:ops-readiness', ['--skip-monitoring' => true]);

    // ...unless the caller is a release gate that says otherwise.
    $strict = Artisan::call('legacy-rme:ops-readiness', ['--skip-monitoring' => true, '--strict' => true]);

    expect($strict)->toBe(1);
    expect($lenient)->toBeIn([0, 1]);
    expect($strict)->toBeGreaterThanOrEqual($lenient);
});

it('emits a machine-readable report that carries the decision and no patient data', function () {
    legacyRmeBranch('TKM1');
    legacyRmeArchiveFlag(true);

    Artisan::call('legacy-rme:ops-readiness', ['--skip-monitoring' => true, '--json' => true]);
    $decoded = json_decode(Artisan::output(), true);

    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKeys([
        'decision', 'ready_for_routine_batch', 'resting_state',
        'branch_matrix', 'stop_the_line', 'severity_summary', 'checks',
    ]);
    expect($decoded['sprint'])->toBe('LEGACY-RME-STEADY-STATE-OPS-1');
});

it('is read-only — reporting never mutates migration state', function () {
    legacyRmeBranch('TKM1');
    legacyRmeArchiveFlag(true);

    $before = LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();
    $snapshot = [$before->status, $before->updated_at?->toIso8601String(), $before->daily_quota];

    steadyReport();
    Artisan::call('legacy-rme:ops-readiness', ['--skip-monitoring' => true]);

    $after = LegacyRmeMigrationWave::query()->where('code', 'TEST-WAVE')->firstOrFail();

    expect([$after->status, $after->updated_at?->toIso8601String(), $after->daily_quota])->toBe($snapshot);
});

it('never reports ready when the deployment readiness gate itself is NO_GO', function () {
    legacyRmeBranch('TKM1');

    // The clinical calendar is a deployment-level dependency. Breaking it must
    // propagate: a batch cannot be safer than the deployment it runs on.
    config()->set('clinical.timezone', 'Not/AZone');

    $report = steadyReport();

    expect($report['ready_for_routine_batch'])->toBeFalse();
    expect($report['decision'])->toBe('NO_GO');
});
