<?php

use App\Models\User;
use App\Modules\LabCapacity\Models\TechnicianCapacityProfile;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Technician\Models\Technician;

beforeEach(fn () => seedAccessControl());

it('reports GO when configuration is clean', function () {
    $this->artisan('lab-workflow:technician-capacity-go-no-go --strict')->assertExitCode(0);
    $this->artisan('lab-workflow:technician-capacity-audit')->assertExitCode(0);
});

it('reports NO_GO on a negative capacity profile', function () {
    $creator = User::factory()->create();
    $t = Technician::factory()->assignable()->create();
    TechnicianCapacityProfile::create([
        'technician_id' => $t->id, 'planning_unit' => 'minutes', 'daily_capacity' => -5,
        'effective_from' => today()->toDateString(), 'is_active' => true,
        'created_by' => $creator->id, 'updated_by' => $creator->id,
    ]);

    $this->artisan('lab-workflow:technician-capacity-audit')->assertExitCode(2);
    $this->artisan('lab-workflow:technician-capacity-go-no-go --strict')->assertExitCode(1);
});

it('reports NO_GO on overlapping active capacity profiles', function () {
    $creator = User::factory()->create();
    $t = Technician::factory()->assignable()->create();
    foreach ([['2026-07-01', '2026-07-31'], ['2026-07-15', '2026-08-15']] as [$from, $until]) {
        TechnicianCapacityProfile::create([
            'technician_id' => $t->id, 'planning_unit' => 'minutes', 'daily_capacity' => 100,
            'effective_from' => $from, 'effective_until' => $until, 'is_active' => true,
            'created_by' => $creator->id, 'updated_by' => $creator->id,
        ]);
    }

    $this->artisan('lab-workflow:technician-capacity-audit')->assertExitCode(2);
});

it('reports NO_GO on an unknown workflow status in V2 data', function () {
    LabOrder::factory()->create(['workflow_version' => 2, 'status' => 'NOT_A_REAL_STATUS']);

    $this->artisan('lab-workflow:technician-capacity-audit')->assertExitCode(2);
});

it('reports WATCH (strict fails) when an active technician has no profile', function () {
    Technician::factory()->assignable()->create(['is_active' => true]);

    // WATCH: coverage warning, no FAIL. go-no-go --strict returns 1; audit non-strict returns 0.
    $this->artisan('lab-workflow:technician-capacity-go-no-go --strict')->assertExitCode(1);
    $this->artisan('lab-workflow:technician-capacity-audit')->assertExitCode(0);
});

it('emits JSON with a decision key', function () {
    $this->artisan('lab-workflow:technician-capacity-audit --json')
        ->expectsOutputToContain('"decision"');
});
