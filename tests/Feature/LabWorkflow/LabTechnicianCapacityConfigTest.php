<?php

use App\Models\User;
use App\Modules\LabCapacity\Models\TechnicianAvailabilityOverride;
use App\Modules\LabCapacity\Models\TechnicianCapability;
use App\Modules\LabCapacity\Models\TechnicianCapacityProfile;
use App\Modules\LabCapacity\Services\LabCapacityConfigService;
use App\Modules\LabService\Models\LabService;
use App\Modules\Technician\Models\Technician;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    $this->config = app(LabCapacityConfigService::class);
    $this->actor = User::factory()->create();
    $this->technician = Technician::factory()->assignable()->create();
    $this->service = LabService::factory()->create(['is_active' => true]);
});

it('creates, updates and deactivates a capacity profile transactionally', function () {
    $profile = $this->config->createCapacityProfile([
        'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
        'daily_capacity' => 400, 'effective_from' => today()->toDateString(),
    ], $this->actor);

    expect($profile->created_by)->toBe($this->actor->id);

    $this->config->updateCapacityProfile($profile, ['daily_capacity' => 500], $this->actor);
    expect($profile->fresh()->daily_capacity)->toBe('500.00');

    $this->config->deactivateCapacityProfile($profile, $this->actor);
    expect($profile->fresh()->is_active)->toBeFalse();
});

it('rejects an overlapping active capacity profile', function () {
    $this->config->createCapacityProfile([
        'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
        'daily_capacity' => 400, 'effective_from' => '2026-07-01', 'effective_until' => '2026-07-31',
    ], $this->actor);

    expect(fn () => $this->config->createCapacityProfile([
        'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
        'daily_capacity' => 300, 'effective_from' => '2026-07-15', 'effective_until' => '2026-08-15',
    ], $this->actor))->toThrow(ValidationException::class);

    // rollback safety: only the first profile persisted.
    expect(TechnicianCapacityProfile::count())->toBe(1);
});

it('allows a non-overlapping successor profile', function () {
    $this->config->createCapacityProfile([
        'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
        'daily_capacity' => 400, 'effective_from' => '2026-07-01', 'effective_until' => '2026-07-31',
    ], $this->actor);
    $this->config->createCapacityProfile([
        'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
        'daily_capacity' => 500, 'effective_from' => '2026-08-01',
    ], $this->actor);

    expect(TechnicianCapacityProfile::count())->toBe(2);
});

it('rejects an overlapping workload profile', function () {
    $this->config->createWorkloadProfile([
        'lab_service_id' => $this->service->id, 'planning_unit' => 'minutes',
        'planned_workload' => 100, 'effective_from' => '2026-07-01',
    ], $this->actor);

    expect(fn () => $this->config->createWorkloadProfile([
        'lab_service_id' => $this->service->id, 'planning_unit' => 'minutes',
        'planned_workload' => 120, 'effective_from' => '2026-07-10',
    ], $this->actor))->toThrow(ValidationException::class);
});

it('upserts capability and availability without duplication', function () {
    $this->config->setCapability([
        'technician_id' => $this->technician->id, 'lab_service_id' => $this->service->id,
        'effective_from' => '2026-07-01',
    ], $this->actor);
    $this->config->setCapability([
        'technician_id' => $this->technician->id, 'lab_service_id' => $this->service->id,
        'effective_from' => '2026-07-01', 'is_eligible' => false,
    ], $this->actor);

    expect(TechnicianCapability::count())->toBe(1);

    $this->config->upsertAvailabilityOverride([
        'technician_id' => $this->technician->id, 'override_date' => '2026-07-20',
        'capacity_reduction' => 100, 'reason_category' => 'leave',
    ], $this->actor);
    $this->config->upsertAvailabilityOverride([
        'technician_id' => $this->technician->id, 'override_date' => '2026-07-20',
        'capacity_override' => 50, 'reason_category' => 'half_day',
    ], $this->actor);

    expect(TechnicianAvailabilityOverride::count())->toBe(1);
});

// ---- HTTP validation + authorization -----------------------------------

it('rejects a negative daily capacity via HTTP', function () {
    $this->actingAs(userWith(['manage_lab_technician_capacity']))
        ->post('/lab/capacity-planning/capacity-profiles', [
            'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
            'daily_capacity' => -5, 'effective_from' => today()->toDateString(),
        ])
        ->assertSessionHasErrors('daily_capacity');
});

it('rejects effective_until before effective_from via HTTP', function () {
    $this->actingAs(userWith(['manage_lab_technician_capacity']))
        ->post('/lab/capacity-planning/capacity-profiles', [
            'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
            'daily_capacity' => 100, 'effective_from' => '2026-07-31', 'effective_until' => '2026-07-01',
        ])
        ->assertSessionHasErrors('effective_until');
});

it('rejects an availability override with neither value via HTTP', function () {
    $this->actingAs(userWith(['manage_lab_technician_capacity']))
        ->post('/lab/capacity-planning/availability-overrides', [
            'technician_id' => $this->technician->id, 'override_date' => '2026-07-20',
            'reason_category' => 'leave',
        ])
        ->assertSessionHasErrors('capacity_reduction');
});

it('forbids config writes for a non-manage user', function () {
    $this->actingAs(userWith(['view_lab_technician_capacity']))
        ->post('/lab/capacity-planning/capacity-profiles', [
            'technician_id' => $this->technician->id, 'planning_unit' => 'minutes',
            'daily_capacity' => 100, 'effective_from' => today()->toDateString(),
        ])
        ->assertForbidden();
});
