<?php

/**
 * LEGACY-RME-PDF-ROLL-2 — the controlled rollout gate.
 *
 * These tests pin the properties that make the gate trustworthy: it fails
 * closed, it cannot be talked into GO by an unapproved pilot, it proves the
 * flag state in both directions, and it never mutates anything it inspects.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Services\LegacyRmeRolloutReadinessService;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Support\Facades\Storage;

/** Locate one check in a report by id. */
function roll2Check(array $report, string $id): array
{
    $match = collect($report['checks'])->firstWhere('id', $id);

    expect($match)->not->toBeNull("check [{$id}] is missing from the report");

    return $match;
}

/** Declare a fully approved pilot scope pointing at a real RME branch. */
function roll2ApprovePilot(string $code = 'LDK2'): Branch
{
    $branch = Branch::factory()->create([
        'code' => $code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    config()->set('legacy_rme_rollout.pilot_scope.approved', true);
    config()->set('legacy_rme_rollout.pilot_scope.approval_reference', 'ROLL-2-PILOT-001');
    config()->set('legacy_rme_rollout.pilot_scope.branch_code', $code);

    return $branch;
}

it('refuses the rollout when no pilot scope is approved', function () {
    config()->set('legacy_rme_rollout.pilot_scope.approved', false);

    $report = app(LegacyRmeRolloutReadinessService::class)->report();
    $check = roll2Check($report, 'pilot_scope_approved');

    expect($check['status'])->toBe('FAIL')
        ->and($report['decision'])->toBe(LegacyRmeRolloutReadinessService::DECISION_NO_GO);
});

it('refuses a pilot scope that is approved but incomplete', function () {
    roll2ApprovePilot();
    config()->set('legacy_rme_rollout.pilot_scope.approval_reference', '');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'pilot_scope_approved');

    expect($check['status'])->toBe('FAIL')
        ->and($check['context']['has_reference'])->toBeFalse();
});

it('refuses MAIN as a clinical pilot branch', function () {
    roll2ApprovePilot();
    config()->set('legacy_rme_rollout.pilot_scope.branch_code', 'MAIN');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'pilot_scope_approved');

    expect($check['status'])->toBe('FAIL');
});

it('refuses a pilot branch that is not an active RME branch', function () {
    roll2ApprovePilot();

    Branch::query()->where('code', 'LDK2')->update(['is_rme_enabled' => false]);
    config()->set('legacy_rme_rollout.pilot_scope.branch_code', 'LDK2');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'pilot_scope_approved');

    expect($check['status'])->toBe('FAIL');
});

it('accepts an approved pilot scope on an active RME branch', function () {
    roll2ApprovePilot();

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'pilot_scope_approved');

    expect($check['status'])->toBe('GO')
        ->and($check['context']['branch_code'])->toBe('LDK2');
});

it('asserts the expected OFF state and fails when the flag is unexpectedly ON', function () {
    $service = app(LegacyRmeRolloutReadinessService::class);

    config()->set('feature_flags.flags.rme\.legacy_pdf_archive', null);

    // Default deployment posture: the archive is OFF.
    expect(roll2Check($service->report('off'), 'effective_state')['status'])->toBe('GO');

    // Asking it to prove ON while it is OFF must fail, not shrug.
    $check = roll2Check($service->report('on'), 'effective_state');

    expect($check['status'])->toBe('FAIL')
        ->and($check['context']['enabled'])->toBeFalse();
});

it('treats an uncaptured runtime override as a rollout blocker', function () {
    // Simulates the ROLL-1 regression: a declared override key that was never
    // captured at config-build time is silently ignored under a cached config,
    // which breaks enablement AND rollback.
    $flags = config('feature_flags.flags');
    $flags['rme.legacy_pdf_archive']['env_captured'] = false;
    unset($flags['rme.legacy_pdf_archive']['env_value']);
    config()->set('feature_flags.flags', $flags);

    $report = app(LegacyRmeRolloutReadinessService::class)->report();
    $check = roll2Check($report, 'runtime_override_capture');

    expect($check['status'])->toBe('FAIL')
        ->and($report['decision'])->toBe(LegacyRmeRolloutReadinessService::DECISION_NO_GO);
});

it('flags stale governance metadata as WATCH rather than silently passing', function () {
    config()->set('legacy_rme_rollout.delivered_sprint', 'LEGACY-RME-PDF-9Z');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'flag_metadata_current');

    expect($check['status'])->toBe('WATCH');
});

it('keeps the shipped flag metadata aligned with the delivered runtime', function () {
    // The staleness that ROLL-2 corrected must not silently return.
    $metadata = app(FeatureFlagService::class)->metadata('rme.legacy_pdf_archive');

    expect($metadata['review_target'])->toBe(config('legacy_rme_rollout.delivered_sprint'))
        ->and($metadata['default'])->toBeFalse()
        ->and(trim((string) $metadata['rollback_action']))->not->toBe('');
});

it('refuses a rollout when the archive points at a publicly exposable disk', function () {
    config()->set('legacy_rme.storage.disk', 'public');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'private_disk_configured');

    expect($check['status'])->toBe('FAIL');
});

it('refuses a rollout when the configured archive disk is not defined', function () {
    config()->set('legacy_rme.storage.disk', 'not_a_real_disk');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'private_disk_configured');

    expect($check['status'])->toBe('FAIL');
});

it('probes disk writability without leaving anything behind', function () {
    Storage::fake('legacy_rme_private');
    config()->set('legacy_rme.storage.disk', 'legacy_rme_private');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'private_disk_writable');

    expect($check['status'])->toBe('GO')
        ->and(Storage::disk('legacy_rme_private')->allFiles())->toBe([]);
});

it('blocks a deployment that would render pages inline instead of on a worker', function () {
    // Rasterization is queued by design; `sync` would drag a multi-minute
    // render into the operator's upload request.
    config()->set('queue.default', 'sync');
    config()->set('legacy_rme_rollout.queue.worker_required_environments', [app()->environment()]);

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'queue_contract');

    expect($check['status'])->toBe('FAIL');
});

/*
|--------------------------------------------------------------------------
| LEGACY-RME-PDF-ROLL-2 pilot finding — a usable connection is not a usable
| pipeline.
|--------------------------------------------------------------------------
| The first real pilot upload sat at QUEUED forever: rasterization is
| dispatched to a DEDICATED queue and the deployed worker did not consume it.
| There was no failed job and no error — and this very check reported GO,
| because it only asked whether the CONNECTION was something other than `sync`.
*/

it('blocks a deployment whose worker does not consume the rendering queue', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.queue.worker_required_environments', [app()->environment()]);
    // A queue no worker unit lists — the shape of the real pilot defect.
    config()->set('legacy_rme.processing.queue', 'queue-nobody-consumes');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'queue_contract');

    expect($check['status'])->toBe('FAIL')
        ->and($check['context']['render_queue'])->toBe('queue-nobody-consumes');
});

it('accepts the real rendering queue because the tracked worker unit consumes it', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.queue.worker_required_environments', [app()->environment()]);

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'queue_contract');

    expect($check['status'])->toBe('GO')
        ->and($check['context']['render_queue'])->toBe('legacy-rme-documents')
        ->and($check['context']['worker_unit_readable'])->toBeTrue()
        ->and($check['context']['queue_name_approved'])->toBeTrue();
});

// A substring match would accept `legacy-rme-documents-archive` for
// `legacy-rme-documents`; the unit's comma-separated list is compared exactly.
it('does not accept a worker queue whose name merely contains the rendering queue', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.queue.worker_required_environments', [app()->environment()]);
    config()->set('legacy_rme.processing.queue', 'legacy-rme-doc');

    expect(roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'queue_contract')['status'])
        ->toBe('FAIL');
});

it('fails closed when the worker unit cannot be read', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.queue.worker_required_environments', [app()->environment()]);
    config()->set('legacy_rme_rollout.queue.worker_unit_file', 'deploy/systemd/does-not-exist.service');

    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'queue_contract');

    expect($check['status'])->toBe('FAIL')
        ->and($check['context']['worker_unit_readable'])->toBeFalse();
});

it('refuses a rendering queue that is not an approved ENT-5 queue name', function () {
    config()->set('queue.default', 'database');
    config()->set('legacy_rme_rollout.queue.worker_required_environments', [app()->environment()]);
    config()->set('queue_governance.ent5_retry_failed_job.allowed_queue_names', ['default']);

    expect(roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'queue_contract')['status'])
        ->toBe('FAIL');
});

it('keeps the rendering queue and the worker unit in step', function () {
    // The two sides that drifted apart in production.
    $unit = file_get_contents(base_path(config('legacy_rme_rollout.queue.worker_unit_file')));

    expect(config('legacy_rme.processing.queue'))->toBe('legacy-rme-documents')
        ->and($unit)->toContain('legacy-rme-documents')
        ->and(config('queue_governance.ent5_retry_failed_job.allowed_queue_names'))
        ->toContain('legacy-rme-documents');
});

it('requires a rehearsed rollback path to exist', function () {
    expect(roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'rollback_contract')['status'])
        ->toBe('GO');

    config()->set('legacy_rme_rollout.rollback.runbook', 'docs/runbooks/does-not-exist.md');

    expect(roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'rollback_contract')['status'])
        ->toBe('FAIL');
});

it('reports every legacy route and permission the archive depends on', function () {
    seedAccessControl();

    $report = app(LegacyRmeRolloutReadinessService::class)->report();

    expect(roll2Check($report, 'routes_registered')['status'])->toBe('GO')
        ->and(roll2Check($report, 'permissions_registered')['status'])->toBe('GO')
        ->and(roll2Check($report, 'schema_ready')['status'])->toBe('GO');
});

it('names the missing permissions instead of failing opaquely', function () {
    // No seeding: the archive's authorization boundary does not exist yet.
    $check = roll2Check(app(LegacyRmeRolloutReadinessService::class)->report(), 'permissions_registered');

    expect($check['status'])->toBe('FAIL')
        ->and($check['context']['missing'])->toContain('view_legacy_rme_archive');
});

it('treats an unevaluated check as blocking rather than as ready', function () {
    $service = app(LegacyRmeRolloutReadinessService::class);

    expect($service->decide([['id' => 'x', 'status' => 'UNKNOWN']]))
        ->toBe(LegacyRmeRolloutReadinessService::DECISION_NO_GO)
        ->and($service->decide([['id' => 'x', 'status' => 'FAIL']]))
        ->toBe(LegacyRmeRolloutReadinessService::DECISION_NO_GO)
        ->and($service->decide([['id' => 'x', 'status' => 'WATCH']]))
        ->toBe(LegacyRmeRolloutReadinessService::DECISION_WATCH)
        ->and($service->decide([['id' => 'x', 'status' => 'GO']]))
        ->toBe(LegacyRmeRolloutReadinessService::DECISION_GO);
});

it('never reports a patient identifier or document path in its evidence', function () {
    roll2ApprovePilot();

    $encoded = json_encode(app(LegacyRmeRolloutReadinessService::class)->report());

    expect($encoded)->not->toContain('patient_id')
        ->and($encoded)->not->toContain('ktp')
        ->and($encoded)->not->toContain('nik')
        ->and($encoded)->not->toMatch('/\d{16}/');
});

it('exits non-zero from the command while the rollout is blocked', function () {
    config()->set('legacy_rme_rollout.pilot_scope.approved', false);

    $this->artisan('legacy-rme:rollout-readiness')->assertExitCode(1);
});

it('rejects an unsupported expected state instead of guessing', function () {
    $this->artisan('legacy-rme:rollout-readiness', ['--expect' => 'maybe'])->assertExitCode(1);
});

it('exits zero when only a WATCH remains, and non-zero under strict', function () {
    seedAccessControl();
    roll2ApprovePilot();
    config()->set('legacy_rme_rollout.delivered_sprint', 'LEGACY-RME-PDF-9Z');

    $this->artisan('legacy-rme:rollout-readiness')->assertExitCode(0);
    $this->artisan('legacy-rme:rollout-readiness', ['--strict' => true])->assertExitCode(1);
});

it('clears to GO on a fully ready deployment with an approved pilot', function () {
    seedAccessControl();
    roll2ApprovePilot();

    $report = app(LegacyRmeRolloutReadinessService::class)->report('off');

    expect($report['decision'])->toBe(LegacyRmeRolloutReadinessService::DECISION_GO);

    $this->artisan('legacy-rme:rollout-readiness', ['--expect' => 'off', '--strict' => true])
        ->assertExitCode(0);
});
