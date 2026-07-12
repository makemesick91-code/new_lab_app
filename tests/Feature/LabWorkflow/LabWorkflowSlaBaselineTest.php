<?php

use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabWorkflowSlaBaselineService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;

beforeEach(fn () => seedAccessControl());

function slaLog(LabOrder $order, string $new, $at, ?string $old = null): void
{
    LabOrderStatusLog::create([
        'lab_order_id' => $order->id,
        'old_status' => $old,
        'new_status' => $new,
        'changed_by' => superAdmin()->id,
        'changed_at' => $at,
    ]);
}

function slaV2Order(string $status): LabOrder
{
    return LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'status' => $status,
    ]);
}

it('computes per-stage durations from the status-log timeline (changed_at only)', function () {
    $order = slaV2Order(LabWorkflowState::PICKUP_ACCEPTED);
    $t0 = now()->subHours(4);
    slaLog($order, LabWorkflowState::WAITING_PICKUP, $t0->copy());
    slaLog($order, LabWorkflowState::PICKUP_ACCEPTED, $t0->copy()->addMinutes(30));

    $baseline = app(LabWorkflowSlaBaselineService::class)->baseline();
    $stages = collect($baseline['stages'])->keyBy('key');

    expect($stages['request_to_pickup']['count'])->toBe(1)
        ->and($stages['request_to_pickup']['avg_minutes'])->toBe(30.0)
        ->and($stages['request_to_pickup']['median_minutes'])->toBe(30.0);
});

it('counts QC_FAILED transitions as rework', function () {
    $order = slaV2Order(LabWorkflowState::STEP_2_TEETH_SETUP);
    slaLog($order, LabWorkflowState::QC_PENDING, now()->subHours(3));
    slaLog($order, LabWorkflowState::QC_FAILED, now()->subHours(2));

    $baseline = app(LabWorkflowSlaBaselineService::class)->baseline();

    expect($baseline['rework_count'])->toBe(1)
        ->and($baseline['rework_orders'])->toBe(1);
});

it('computes total lead time from WAITING_PICKUP to DELIVERED', function () {
    $order = slaV2Order(LabWorkflowState::DELIVERED);
    $t0 = now()->subDays(2);
    slaLog($order, LabWorkflowState::WAITING_PICKUP, $t0->copy());
    slaLog($order, LabWorkflowState::DELIVERED, $t0->copy()->addDay());

    $baseline = app(LabWorkflowSlaBaselineService::class)->baseline();
    $total = collect($baseline['stages'])->firstWhere('key', 'total_lead_time');

    expect($total['count'])->toBe(1)
        ->and($total['avg_minutes'])->toBe(1440.0); // 24h
});

it('returns an empty baseline without data and carries the pilot note', function () {
    $baseline = app(LabWorkflowSlaBaselineService::class)->baseline();

    expect($baseline['orders_analyzed'])->toBe(0)
        ->and($baseline['rework_count'])->toBe(0)
        ->and($baseline['note'])->toBe(LabWorkflowSlaBaselineService::NOTE);

    $total = collect($baseline['stages'])->firstWhere('key', 'total_lead_time');
    expect($total['count'])->toBe(0)
        ->and($total['avg_minutes'])->toBeNull();
});
