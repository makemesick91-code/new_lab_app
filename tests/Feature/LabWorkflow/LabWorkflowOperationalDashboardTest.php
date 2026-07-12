<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Services\LabWorkflowOperationalDashboardService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;

beforeEach(fn () => seedAccessControl());

function dashV2Order(string $status, ?int $branchId = null): LabOrder
{
    $attrs = ['workflow_version' => LabOrder::WORKFLOW_V2, 'status' => $status];
    if ($branchId !== null) {
        $attrs['branch_id'] = $branchId;
    }

    return LabOrder::factory()->create($attrs);
}

it('groups V2 orders into operational status buckets (legacy ignored)', function () {
    $staff = userWith(['manage_lab_orders']);
    dashV2Order(LabWorkflowState::WAITING_PICKUP);
    dashV2Order(LabWorkflowState::QC_PENDING);
    dashV2Order(LabWorkflowState::QC_PENDING);
    dashV2Order(LabWorkflowState::MODEL_DONE);
    LabOrder::factory()->create(['workflow_version' => LabOrder::WORKFLOW_LEGACY, 'status' => LabWorkflowState::WAITING_PICKUP]);

    $overview = app(LabWorkflowOperationalDashboardService::class)->overview($staff);
    $buckets = collect($overview['buckets'])->keyBy('key');

    expect($buckets['waiting_pickup']['count'])->toBe(1)
        ->and($buckets['qc_pending']['count'])->toBe(2)
        ->and($buckets['model_done']['count'])->toBe(1)
        ->and($overview['sees_all'])->toBeTrue()
        ->and($overview['active_total'])->toBe(4);
});

it('isolates a branch operator to their own branch (no cross-branch leak)', function () {
    $branchA = Branch::factory()->create(['is_active' => true]);
    $branchB = Branch::factory()->create(['is_active' => true]);

    $operator = userWith(['create_lab_branch_requests']);
    $operator->forceFill(['branch_id' => $branchA->id])->save();

    dashV2Order(LabWorkflowState::QC_PENDING, $branchA->id);
    dashV2Order(LabWorkflowState::QC_PENDING, $branchB->id);

    $overview = app(LabWorkflowOperationalDashboardService::class)->overview($operator->fresh());
    $buckets = collect($overview['buckets'])->keyBy('key');

    expect($overview['sees_all'])->toBeFalse()
        ->and($buckets['qc_pending']['count'])->toBe(1); // only branch A
});

it('lab staff can see all branches at once', function () {
    $branchA = Branch::factory()->create(['is_active' => true]);
    $branchB = Branch::factory()->create(['is_active' => true]);
    dashV2Order(LabWorkflowState::QC_PENDING, $branchA->id);
    dashV2Order(LabWorkflowState::QC_PENDING, $branchB->id);

    $overview = app(LabWorkflowOperationalDashboardService::class)->overview(userWith(['manage_lab_orders']));

    expect(collect($overview['buckets'])->firstWhere('key', 'qc_pending')['count'])->toBe(2);
});

it('recent activity exposes only order number, patient name, status and time (no PII)', function () {
    $staff = userWith(['manage_lab_orders']);
    $order = dashV2Order(LabWorkflowState::QC_PENDING);
    LabOrderStatusLog::create([
        'lab_order_id' => $order->id,
        'old_status' => LabWorkflowState::STEP_4_COMPLETED,
        'new_status' => LabWorkflowState::QC_PENDING,
        'changed_by' => superAdmin()->id,
        'changed_at' => now(),
    ]);

    $overview = app(LabWorkflowOperationalDashboardService::class)->overview($staff);

    expect($overview['recent_activity'])->not->toBeEmpty();
    expect(array_keys($overview['recent_activity'][0]))
        ->toEqualCanonicalizing(['order_number', 'patient_name', 'new_status', 'changed_at']);
});

it('returns zero counts without data (empty state, no crash)', function () {
    $overview = app(LabWorkflowOperationalDashboardService::class)->overview(userWith(['manage_lab_orders']));

    expect($overview['active_total'])->toBe(0)
        ->and($overview['overdue'])->toBe(0)
        ->and($overview['recent_activity'])->toBe([]);
});

it('route denies without a lab permission and allows with one', function () {
    $this->actingAs(userWith([]))->get('/lab/operational-dashboard')->assertForbidden();
    $this->actingAs(userWith(['view_lab_orders']))->get('/lab/operational-dashboard')->assertOk();
});
