<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabCapacity\Models\LabServiceWorkloadProfile;
use App\Modules\LabCapacity\Models\TechnicianAvailabilityOverride;
use App\Modules\LabCapacity\Models\TechnicianCapability;
use App\Modules\LabCapacity\Models\TechnicianCapacityProfile;
use App\Modules\LabCapacity\Services\LabTechnicianCapacityPlanningService;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderItem;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\LabService\Models\LabService;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->creator = User::factory()->create();
    $this->branch = Branch::factory()->create(['is_rme_enabled' => true]);
    $this->service = app(LabTechnicianCapacityPlanningService::class);
});

function lpcScope(?int $branchId = null): array
{
    return [
        'tier' => 'full', 'sees_all' => true, 'branch_id' => $branchId,
        'technician_id' => null, 'technician_name' => null,
        'can_manage' => true, 'can_export' => true,
    ];
}

function lpcCapProfile(Technician $t, float $daily, User $creator, array $days = [1, 2, 3, 4, 5, 6, 7], string $unit = 'minutes'): TechnicianCapacityProfile
{
    return TechnicianCapacityProfile::create([
        'technician_id' => $t->id, 'planning_unit' => $unit, 'daily_capacity' => $daily,
        'working_days' => $days, 'effective_from' => today()->subDay()->toDateString(),
        'is_active' => true, 'created_by' => $creator->id, 'updated_by' => $creator->id,
    ]);
}

function lpcWorkload(LabService $s, float $workload, User $creator, string $unit = 'minutes'): LabServiceWorkloadProfile
{
    return LabServiceWorkloadProfile::create([
        'lab_service_id' => $s->id, 'planning_unit' => $unit, 'planned_workload' => $workload,
        'effective_from' => today()->subDay()->toDateString(), 'is_active' => true,
        'created_by' => $creator->id, 'updated_by' => $creator->id,
    ]);
}

function lpcOrder(Branch $b, string $status, ?string $due, array $svcQty): LabOrder
{
    $o = LabOrder::factory()->create([
        'workflow_version' => 2, 'status' => $status, 'branch_id' => $b->id,
        'order_date' => today()->toDateString(), 'due_date' => $due,
    ]);
    foreach ($svcQty as $sid => $qty) {
        LabOrderItem::factory()->create(['lab_order_id' => $o->id, 'lab_service_id' => $sid, 'quantity' => $qty]);
    }

    return $o->refresh();
}

function lpcAssign(LabOrder $o, Technician $t): LabOrderAssignment
{
    return LabOrderAssignment::factory()->create([
        'lab_order_id' => $o->id, 'technician_id' => $t->id,
        'status' => LabOrderAssignment::STATUS_ASSIGNED, 'assigned_at' => now(),
    ]);
}

it('computes available capacity over the horizon (all working days)', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 100, $this->creator);

    $plan = $this->service->plan(lpcScope(), ['horizon' => '7', 'planning_unit' => 'minutes']);

    // 7 days * 100 = 700
    expect($plan['summary']['available_capacity'])->toBe(700.0);
    expect($plan['technicians'][$t->id]['coverage'])->toBe('configured');
});

it('honours working days subset', function () {
    Carbon::setTestNow(now()->startOfWeek(Carbon::MONDAY));
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 100, $this->creator, [1, 2, 3, 4, 5]); // Mon-Fri

    $plan = $this->service->plan(lpcScope(), ['horizon' => '7']);

    // Mon..Sun horizon, only 5 working days => 500
    expect($plan['summary']['available_capacity'])->toBe(500.0);
    Carbon::setTestNow();
});

it('applies availability override reduction on a day', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 100, $this->creator);
    TechnicianAvailabilityOverride::create([
        'technician_id' => $t->id, 'override_date' => today()->toDateString(),
        'capacity_reduction' => 100, 'reason_category' => 'leave', 'created_by' => $this->creator->id,
    ]);

    $plan = $this->service->plan(lpcScope(), ['horizon' => '7']);
    // one day reduced to 0 => 600
    expect($plan['summary']['available_capacity'])->toBe(600.0);
});

it('marks a technician with no profile as UNCONFIGURED with null capacity', function () {
    $t = Technician::factory()->assignable()->create();

    $plan = $this->service->plan(lpcScope(), ['horizon' => '7']);
    expect($plan['technicians'][$t->id]['available'])->toBeNull();
    expect($plan['technicians'][$t->id]['coverage'])->toBe('unconfigured');
    expect($plan['technicians'][$t->id]['band'])->toBe('UNCONFIGURED');
});

it('band is UNAVAILABLE when capacity is zero', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 0, $this->creator);

    $plan = $this->service->plan(lpcScope(), ['horizon' => '7']);
    expect($plan['technicians'][$t->id]['band'])->toBe('UNAVAILABLE');
    expect($plan['technicians'][$t->id]['utilization'])->toBeNull();
});

it('classifies assigned vs unassigned demand and computes remaining workload', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 1000, $this->creator);
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);

    // assigned order (active assignment) — pre-production fraction 1.0, qty 2 => 200
    $assigned = lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNED, today()->addDays(5)->toDateString(), [$svc->id => 2]);
    lpcAssign($assigned, $t);
    // unassigned order — qty 1 => 100
    lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, today()->addDays(5)->toDateString(), [$svc->id => 1]);

    $plan = $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);

    expect($plan['summary']['assigned_load'])->toBe(200.0);
    expect($plan['summary']['unassigned_demand'])->toBe(100.0);
    expect($plan['technicians'][$t->id]['assigned_load'])->toBe(200.0);
});

it('excludes terminal orders and treats post-production as zero remaining', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 1000, $this->creator);
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);

    // terminal (delivered) — excluded by repository
    lpcOrder($this->branch, LabWorkflowState::DELIVERED, today()->toDateString(), [$svc->id => 1]);
    // post-production (model done) — counted open but zero technician remaining
    lpcOrder($this->branch, LabWorkflowState::MODEL_DONE, today()->toDateString(), [$svc->id => 1]);

    $plan = $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);
    expect($plan['summary']['assigned_load'])->toBe(0.0);
    expect($plan['summary']['unassigned_demand'])->toBe(0.0);
});

it('marks an order with no workload profile as unplannable (not a fake zero)', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 1000, $this->creator);
    $svc = LabService::factory()->create(['is_active' => true]); // no workload profile
    lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, today()->addDays(3)->toDateString(), [$svc->id => 1]);

    $plan = $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);
    expect($plan['summary']['unplannable_count'])->toBe(1);
    expect($plan['summary']['unassigned_demand'])->toBe(0.0);
});

it('computes utilization bands and capacity gap', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 100, $this->creator); // 10-day horizon => 1000 available
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);

    // 8 assigned orders => 800 assigned => 80% => WATCH
    for ($i = 0; $i < 8; $i++) {
        $o = lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNED, today()->addDays(9)->toDateString(), [$svc->id => 1]);
        lpcAssign($o, $t);
    }
    $plan = $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);
    expect($plan['technicians'][$t->id]['utilization'])->toBe(80.0);
    expect($plan['technicians'][$t->id]['band'])->toBe('WATCH');
    expect($plan['technicians'][$t->id]['capacity_gap'])->toBe(200.0);
});

it('flags overload when assigned exceeds capacity', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 100, $this->creator); // 1000 available over 10 days
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);
    for ($i = 0; $i < 11; $i++) {
        $o = lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNED, today()->addDays(9)->toDateString(), [$svc->id => 1]);
        lpcAssign($o, $t);
    }
    $plan = $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);
    expect($plan['technicians'][$t->id]['band'])->toBe('OVER_CAPACITY');
    expect($plan['summary']['overload_count'])->toBe(1);
    expect($plan['technicians'][$t->id]['capacity_gap'])->toBeLessThan(0);
});

it('flags an overdue order', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01'));
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 1000, $this->creator);
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);
    lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, '2026-07-20', [$svc->id => 1]); // past due

    $plan = $this->service->plan(lpcScope(), ['horizon' => '14']);
    expect($plan['summary']['overdue_count'])->toBe(1);
    Carbon::setTestNow();
});

it('recommends an eligible technician with capability and capacity', function () {
    $t = Technician::factory()->assignable()->create(['name' => 'Kandidat A']);
    lpcCapProfile($t, 100, $this->creator); // 10-day custom range => 1000 available
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);
    TechnicianCapability::create([
        'technician_id' => $t->id, 'lab_service_id' => $svc->id, 'is_eligible' => true,
        'effective_from' => today()->subDay()->toDateString(), 'created_by' => $this->creator->id,
    ]);
    lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, today()->addDays(5)->toDateString(), [$svc->id => 1]);

    $plan = $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);
    $row = $plan['unassigned_orders'][0];
    expect($row['candidates'])->toHaveCount(1);
    expect($row['candidates'][0]['technician_id'])->toBe($t->id);
    expect($row['candidates'][0]['projected_utilization'])->toBe(10.0);
});

it('emits SERVICE_UNSUPPORTED when no technician has the capability', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 1000, $this->creator); // capacity but NO capability for the service
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);
    lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, today()->addDays(5)->toDateString(), [$svc->id => 1]);

    $plan = $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);
    $row = $plan['unassigned_orders'][0];
    expect($row['candidates'])->toBeEmpty();
    expect($row['reason_codes'])->toContain('SERVICE_UNSUPPORTED');
});

it('never mutates orders or assignments (read-only)', function () {
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 1000, $this->creator);
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);
    $o = lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, today()->addDays(5)->toDateString(), [$svc->id => 1]);

    $this->service->plan(lpcScope(), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);

    expect($o->fresh()->status)->toBe(LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING);
    expect(LabOrderAssignment::count())->toBe(0);
});

it('branch filter scopes demand', function () {
    $other = Branch::factory()->create(['is_rme_enabled' => true]);
    $t = Technician::factory()->assignable()->create();
    lpcCapProfile($t, 1000, $this->creator);
    $svc = LabService::factory()->create(['is_active' => true]);
    lpcWorkload($svc, 100, $this->creator);
    lpcOrder($this->branch, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, today()->addDays(5)->toDateString(), [$svc->id => 1]);
    lpcOrder($other, LabWorkflowState::TECHNICIAN_ASSIGNMENT_PENDING, today()->addDays(5)->toDateString(), [$svc->id => 1]);

    $plan = $this->service->plan(lpcScope($this->branch->id), ['horizon' => 'custom', 'from' => today()->toDateString(), 'to' => today()->addDays(9)->toDateString()]);
    expect($plan['data_quality']['total_open_orders'])->toBe(1);
});
