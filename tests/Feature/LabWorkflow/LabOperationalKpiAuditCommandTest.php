<?php

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabOperationalKpiAuditService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;

beforeEach(fn () => seedAccessControl());

/** A clean, fully-scoped V2 order (branch + due_date + timestamped log). */
function auditCleanOrder(): LabOrder
{
    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        // A real parent branch, not the hardcoded id 1 PostgreSQL rejects.
        'branch_id' => labOpsBranch()->id,
        'due_date' => now()->addDays(3)->toDateString(),
        'status' => LabWorkflowState::QC_PENDING,
        'order_date' => now()->toDateString(),
    ]);
    LabOrderStatusLog::create([
        'lab_order_id' => $order->id,
        'old_status' => LabWorkflowState::STEP_4_COMPLETED,
        'new_status' => LabWorkflowState::QC_PENDING,
        // changed_by is a NOT NULL FK to users; id 1 is not guaranteed to exist.
        'changed_by' => labOpsActor()->id,
        'changed_at' => now(),
    ]);

    return $order;
}

it('reports GO with a clean, fully-scoped V2 order and seeded permissions', function () {
    auditCleanOrder();

    $report = app(LabOperationalKpiAuditService::class)->audit();

    expect($report['decision'])->toBe('GO');
});

it('audit command exits 0 on GO', function () {
    auditCleanOrder();

    $this->artisan('lab-workflow:operational-kpi-audit')->assertExitCode(0);
});

it('go-no-go command exits 0 on GO and prints GO', function () {
    auditCleanOrder();

    $this->artisan('lab-workflow:operational-kpi-go-no-go')
        ->expectsOutputToContain('GO')
        ->assertExitCode(0);
});

it('is NO_GO when a V2 order carries an unknown workflow status', function () {
    LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'branch_id' => labOpsBranch()->id,
        'status' => 'NOT_A_REAL_STATUS',
        'order_date' => now()->toDateString(),
    ]);

    $report = app(LabOperationalKpiAuditService::class)->audit();
    $unknown = collect($report['checks'])->firstWhere('key', 'unknown_status');

    expect($report['decision'])->toBe('NO_GO')
        ->and($unknown['status'])->toBe('FAIL');

    $this->artisan('lab-workflow:operational-kpi-audit')->assertExitCode(2);
    $this->artisan('lab-workflow:operational-kpi-go-no-go')->assertExitCode(1);
});

it('is NO_GO when an assignment completes before it was assigned (impossible duration)', function () {
    auditCleanOrder();
    $tech = Technician::factory()->create(['is_active' => true]);
    LabOrderAssignment::factory()->create([
        'lab_order_id' => auditCleanOrder()->id,
        'technician_id' => $tech->id,
        'assigned_at' => now(),
        'completed_at' => now()->subDays(2),
    ]);

    $report = app(LabOperationalKpiAuditService::class)->audit();
    $neg = collect($report['checks'])->firstWhere('key', 'negative_assignment_duration');

    expect($report['decision'])->toBe('NO_GO')->and($neg['status'])->toBe('FAIL');
});

it('is WATCH (not NO_GO) when a V2 order is missing a branch, and go-no-go --strict then fails', function () {
    // Order without branch_id → branch_scope WARN (coverage), not FAIL.
    LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'branch_id' => null,
        'status' => LabWorkflowState::QC_PENDING,
        'order_date' => now()->toDateString(),
    ]);

    $report = app(LabOperationalKpiAuditService::class)->audit();

    expect($report['decision'])->toBe('WATCH');

    $this->artisan('lab-workflow:operational-kpi-go-no-go')->assertExitCode(0); // WATCH is non-blocking
    $this->artisan('lab-workflow:operational-kpi-go-no-go --strict')->assertExitCode(1); // strict blocks WATCH
});

it('emits JSON with a decision', function () {
    auditCleanOrder();

    $this->artisan('lab-workflow:operational-kpi-audit --json')
        ->expectsOutputToContain('"decision"')
        ->assertExitCode(0);
});
