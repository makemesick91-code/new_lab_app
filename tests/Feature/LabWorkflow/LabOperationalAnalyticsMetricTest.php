<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Models\LabExternalDispatch;
use App\Modules\LabOrder\Models\LabModelAnalysis;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabOperationalAnalyticsService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;

beforeEach(fn () => seedAccessControl());

/** Full-tier, all-branch scope for direct service assertions. */
function opScope(?int $branchId = null, ?int $technicianId = null): array
{
    return [
        'tier' => 'full',
        'sees_all' => $branchId === null,
        'branch_id' => $branchId,
        'technician_id' => $technicianId,
        'technician_name' => null,
    ];
}

function opV2Order(array $attrs = []): LabOrder
{
    return LabOrder::factory()->create(array_merge([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'branch_id' => 1,
        'order_date' => now()->toDateString(),
        'status' => LabWorkflowState::RECEIVED_AT_LAB,
    ], $attrs));
}

function opLog(LabOrder $order, string $new, $changedAt): void
{
    LabOrderStatusLog::create([
        'lab_order_id' => $order->id,
        'old_status' => LabWorkflowState::RECEIVED_AT_LAB,
        'new_status' => $new,
        'changed_by' => 1,
        'changed_at' => $changedAt,
    ]);
}

function opAnalytics(array $scope = [], array $filters = []): array
{
    return app(LabOperationalAnalyticsService::class)->analytics(
        $scope === [] ? opScope() : $scope,
        array_merge(['period' => 'month'], $filters),
    );
}

it('returns empty, non-fabricated KPIs when there is no data', function () {
    $data = opAnalytics();

    expect($data['kpi']['orders_received'])->toBe(0)
        ->and($data['kpi']['open_wip'])->toBe(0)
        ->and($data['kpi']['throughput'])->toBe(0)
        ->and($data['kpi']['sla']['eligible'])->toBe(0)
        ->and($data['kpi']['sla']['compliance_pct'])->toBeNull() // not a fake 0
        ->and($data['kpi']['qc']['first_pass_yield_pct'])->toBeNull()
        ->and($data['data_quality']['total'])->toBe(0);
});

it('counts orders received in the period and ignores legacy + out-of-period', function () {
    opV2Order(['order_date' => now()->toDateString()]);
    opV2Order(['order_date' => now()->toDateString()]);
    opV2Order(['order_date' => now()->subYears(2)->toDateString()]); // out of month period
    LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_LEGACY, 'branch_id' => 1, 'order_date' => now()->toDateString()]);

    expect(opAnalytics()['kpi']['orders_received'])->toBe(2);
});

it('open WIP excludes terminal orders', function () {
    opV2Order(['status' => LabWorkflowState::QC_PENDING]);
    opV2Order(['status' => LabWorkflowState::STEP_2_TEETH_SETUP]);
    opV2Order(['status' => LabWorkflowState::DELIVERED]);
    opV2Order(['status' => LabWorkflowState::CANCELLED]);

    expect(opAnalytics()['kpi']['open_wip'])->toBe(2);
});

it('rework_active counts QC_FAILED + REWORK_REQUIRED', function () {
    opV2Order(['status' => LabWorkflowState::QC_FAILED]);
    opV2Order(['status' => LabWorkflowState::REWORK_REQUIRED]);
    opV2Order(['status' => LabWorkflowState::QC_PENDING]);

    expect(opAnalytics()['kpi']['rework_active'])->toBe(2);
});

it('throughput counts DELIVERED transitions in period with previous-period delta', function () {
    $a = opV2Order(['status' => LabWorkflowState::DELIVERED]);
    opLog($a, LabWorkflowState::DELIVERED, now());
    $b = opV2Order(['status' => LabWorkflowState::DELIVERED]);
    opLog($b, LabWorkflowState::DELIVERED, now()->subDay());

    $data = opAnalytics(opScope(), ['period' => '7d']);

    expect($data['kpi']['throughput'])->toBe(2)
        ->and($data['kpi']['throughput_prev'])->toBe(0)
        ->and($data['kpi']['throughput_delta'])->toBe(2);
});

it('SLA compliance: on-time when delivered before due, late when after; boundary is on-time', function () {
    // On-time: delivered today, due today (end of due day).
    $onTime = opV2Order(['status' => LabWorkflowState::DELIVERED, 'due_date' => now()->toDateString()]);
    opLog($onTime, LabWorkflowState::DELIVERED, now());

    // Boundary: delivered exactly within the due day → on-time.
    $boundary = opV2Order(['status' => LabWorkflowState::DELIVERED, 'due_date' => now()->toDateString()]);
    opLog($boundary, LabWorkflowState::DELIVERED, now()->endOfDay());

    // Late: due 2 days ago, delivered now.
    $late = opV2Order(['status' => LabWorkflowState::DELIVERED, 'due_date' => now()->subDays(2)->toDateString()]);
    opLog($late, LabWorkflowState::DELIVERED, now());

    $sla = opAnalytics()['kpi']['sla'];

    expect($sla['eligible'])->toBe(3)
        ->and($sla['on_time'])->toBe(2)
        ->and($sla['late'])->toBe(1)
        ->and($sla['compliance_pct'])->toBe(66.7)
        ->and($sla['median_lateness_days'])->toBeGreaterThan(1.0);
});

it('SLA excludes completed orders that never had a due_date', function () {
    $noDue = opV2Order(['status' => LabWorkflowState::DELIVERED, 'due_date' => null]);
    opLog($noDue, LabWorkflowState::DELIVERED, now());

    $data = opAnalytics();

    expect($data['kpi']['sla']['eligible'])->toBe(0)
        ->and($data['kpi']['sla']['compliance_pct'])->toBeNull()
        ->and($data['data_quality']['without_due_date'])->toBe(1);
});

it('QC first-pass yield and rework rate use the first QC attempt', function () {
    // First attempt PASSED → first pass, no rework.
    $clean = opV2Order(['status' => LabWorkflowState::MODEL_DONE]);
    opLog($clean, LabWorkflowState::QC_PASSED, now());

    // First attempt FAILED then PASSED → not first pass, is rework.
    $reworked = opV2Order(['status' => LabWorkflowState::MODEL_DONE]);
    opLog($reworked, LabWorkflowState::QC_FAILED, now()->subMinutes(10));
    opLog($reworked, LabWorkflowState::QC_PASSED, now());

    $qc = opAnalytics()['kpi']['qc'];

    expect($qc['attempts'])->toBe(2)
        ->and($qc['first_pass'])->toBe(1)
        ->and($qc['first_pass_yield_pct'])->toBe(50.0)
        ->and($qc['rework_orders'])->toBe(1)
        ->and($qc['rework_rate_pct'])->toBe(50.0);
});

it('internal vs external counts analysis decisions in period', function () {
    $i = opV2Order();
    LabModelAnalysis::create(['lab_order_id' => $i->id, 'decision' => 'INTERNAL', 'reason' => 'test', 'analyzed_by' => 1, 'analyzed_at' => now()]);
    $e = opV2Order();
    LabModelAnalysis::create(['lab_order_id' => $e->id, 'decision' => 'EXTERNAL', 'reason' => 'test', 'analyzed_by' => 1, 'analyzed_at' => now()]);

    $ie = opAnalytics()['kpi']['internal_vs_external'];

    expect($ie['internal'])->toBe(1)->and($ie['external'])->toBe(1)->and($ie['total'])->toBe(2);
});

it('external turnaround median comes from sent_at/returned_at', function () {
    $o = opV2Order();
    $lab = ExternalLab::factory()->create();
    LabExternalDispatch::create([
        'lab_order_id' => $o->id,
        'external_lab_id' => $lab->id,
        'status' => 'RETURNED',
        'sent_at' => now()->subDays(4),
        'returned_at' => now(),
        'created_by' => 1,
    ]);

    expect(opAnalytics()['kpi']['external_turnaround']['median_days'])->toBeGreaterThanOrEqual(3.9);
});

it('technician KPI aggregates assignments with sample size', function () {
    $tech = Technician::factory()->create(['is_active' => true]);
    $order = opV2Order(['status' => LabWorkflowState::STEP_2_TEETH_SETUP]);
    LabOrderAssignment::factory()->create([
        'lab_order_id' => $order->id,
        'technician_id' => $tech->id,
        'assigned_at' => now()->subHours(3),
        'started_at' => now()->subHours(3),
        'completed_at' => now(),
        'status' => LabOrderAssignment::STATUS_DONE,
    ]);

    $rows = opAnalytics()['technicians'];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['completed'])->toBe(1)
        ->and($rows[0]['sample'])->toBe(1)
        ->and($rows[0]['median_minutes'])->toBeGreaterThan(150.0);
});

it('branch scope restricts every KPI to the given branch', function () {
    $a = Branch::factory()->create(['is_active' => true]);
    $b = Branch::factory()->create(['is_active' => true]);
    opV2Order(['branch_id' => $a->id, 'status' => LabWorkflowState::QC_PENDING]);
    opV2Order(['branch_id' => $b->id, 'status' => LabWorkflowState::QC_PENDING]);

    expect(opAnalytics(opScope($a->id))['kpi']['open_wip'])->toBe(1);
});
