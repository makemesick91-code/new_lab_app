<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabWorkflowPilotReadinessAuditor;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Technician\Models\Technician;
use Illuminate\Support\Collection;

beforeEach(fn () => seedAccessControl());

/** Flip the Lab Workflow V2 feature flag on (uniquely named to avoid a global collision). */
function readinessEnableV2(): void
{
    $flags = config('feature_flags.flags');
    $flags['lab.workflow_v2']['default'] = true;
    config()->set('feature_flags.flags', $flags);
}

/** An active, RME-enabled branch. */
function readinessRmeBranch(): Branch
{
    $branch = Branch::factory()->create(['is_active' => true]);
    $branch->forceFill(['is_rme_enabled' => true])->save();

    return $branch;
}

/** @return Collection<string,array<string,mixed>> */
function readinessChecks(array $report): Collection
{
    return collect($report['checks'])->keyBy('key');
}

it('is NO-GO when the pilot is unprovisioned', function () {
    $report = app(LabWorkflowPilotReadinessAuditor::class)->audit();

    expect($report['summary']['decision'])->toBe('NO-GO')
        ->and($report['summary']['critical_codes'])->toContain('v2_active')
        ->and($report['summary']['critical_codes'])->toContain('eligible_technician');
});

it('clears the critical blockers with V2 active, an RME branch and an eligible technician', function () {
    readinessEnableV2();
    readinessRmeBranch();
    Technician::factory()->assignable()->create();

    $report = app(LabWorkflowPilotReadinessAuditor::class)->audit();
    $checks = readinessChecks($report);

    expect($checks['v2_active']['status'])->toBe('GO')
        ->and($checks['eligible_technician']['status'])->toBe('GO')
        ->and($checks['rme_branches_available']['status'])->toBe('GO')
        ->and($report['summary']['critical_codes'])->toBe([]);   // no NO-GO
    // External lab + actors still missing → WATCH overall (not NO-GO).
    expect($report['summary']['decision'])->toBe('WATCH');
});

it('reaches GO when fully provisioned', function () {
    readinessEnableV2();
    readinessRmeBranch();
    ExternalLab::factory()->create(['is_active' => true]);
    Technician::factory()->assignable()->create();
    userWith(['pass_qc']);
    userWith(['manage_lab_pickups']);
    userWith(['manage_lab_orders']);

    $report = app(LabWorkflowPilotReadinessAuditor::class)->audit();

    expect($report['summary']['critical_codes'])->toBe([])
        ->and($report['summary']['decision'])->toBe('GO');
});

it('flags an order with an invalid workflow status as NO-GO', function () {
    readinessEnableV2();
    readinessRmeBranch();
    Technician::factory()->assignable()->create();
    LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => 'NOT_A_REAL_STATE',
    ]);

    $report = app(LabWorkflowPilotReadinessAuditor::class)->audit();

    expect($report['summary']['critical_codes'])->toContain('invalid_status')
        ->and($report['summary']['decision'])->toBe('NO-GO');
});

it('flags a stuck non-terminal order as WATCH', function () {
    readinessEnableV2();
    readinessRmeBranch();
    Technician::factory()->assignable()->create();

    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => LabWorkflowState::RECEIVED_AT_LAB,
    ]);
    LabOrderStatusLog::create([
        'lab_order_id' => $order->id,
        'old_status' => LabWorkflowState::IN_TRANSIT_TO_LAB,
        'new_status' => LabWorkflowState::RECEIVED_AT_LAB,
        'changed_by' => superAdmin()->id,
        'changed_at' => now()->subDays(5),
    ]);

    $report = app(LabWorkflowPilotReadinessAuditor::class)->audit();
    $checks = readinessChecks($report);

    expect($checks['stuck_orders']['status'])->toBe('WATCH')
        ->and($checks['stuck_orders']['value']['stuck'])->toHaveCount(1);
});

it('strict command exits 2 on anomalies', function () {
    $this->artisan('lab-workflow:pilot-readiness-audit', ['--strict' => true, '--json' => true])
        ->assertExitCode(2);
});

it('never mutates data (read-only)', function () {
    readinessEnableV2();
    readinessRmeBranch();
    Technician::factory()->assignable()->create();
    LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_V2, 'status' => LabWorkflowState::MODEL_DONE]);

    $before = LabOrder::count();
    app(LabWorkflowPilotReadinessAuditor::class)->audit();

    expect(LabOrder::count())->toBe($before);
});
