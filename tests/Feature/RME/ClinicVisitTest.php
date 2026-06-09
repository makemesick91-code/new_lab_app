<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_clinic_visits']);
    $this->viewer = userWith(['view_clinic_visits']);

    $this->clinic = Clinic::factory()->create();
    $this->patient = Patient::factory()->create();
    $this->doctor = Doctor::factory()->create();
});

it('denies users without clinic visit permission', function () {
    $this->actingAs(userWith([]))
        ->get(route('rme.visits.index'))
        ->assertForbidden();
});

it('lists visits for a viewer', function () {
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'visit_number' => 'VIS-20260609-001',
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertViewIs('rme.visits.index')
        ->assertSee('VIS-20260609-001');
});

it('only lists visits from active branch', function () {
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'visit_number' => 'VIS-ACTIVE-001',
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'queue_number' => 1,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTH1']);
    ClinicVisit::factory()->create([
        'branch_id' => $otherBranch->id,
        'visit_number' => 'VIS-OTHER-001',
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->viewer->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee('VIS-ACTIVE-001')
        ->assertDontSee('VIS-OTHER-001');
});

it('lets manager open create page', function () {
    $this->actingAs($this->manager)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertViewIs('rme.visits.create');
});

it('stores valid clinic visit in active branch', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'chief_complaint' => 'Sakit gigi',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('trx_clinic_visits', [
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'chief_complaint' => 'Sakit gigi',
        'status' => 'registered',
    ]);
});

it('auto-generates queue_number per branch per visit_date', function () {
    $patient2 = Patient::factory()->create();

    // Seed first visit directly (avoids SQLite savepoint visibility issue in tests).
    // Production uses lockForUpdate() inside DB::transaction() for concurrency safety.
    ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'visit_date' => today()->toDateString(),
        'queue_number' => 1,
        'visit_number' => 'VIS-'.today()->format('Ymd').'-001',
    ]);

    // Second visit via HTTP — must get queue_number = 2 since 1 is taken.
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient2->id,
            'doctor_id' => $this->doctor->id,
        ])
        ->assertRedirect();

    $second = ClinicVisit::where('branch_id', $this->branch->id)
        ->where('patient_id', $patient2->id)
        ->first();

    expect($second)->not->toBeNull()
        ->and($second->queue_number)->toBe(2);
});

it('auto-generates visit_number in VIS-YYYYMMDD-NNN format', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
        ]);

    $visit = ClinicVisit::where('branch_id', $this->branch->id)->first();

    expect($visit->visit_number)
        ->toMatch('/^VIS-\d{8}-\d{3}$/')
        ->toStartWith('VIS-'.now()->format('Ymd').'-');
});

it('rejects invalid patient, doctor, or room', function () {
    $this->actingAs($this->manager)
        ->from(route('rme.visits.create'))
        ->post(route('rme.visits.store'), [
            'clinic_id' => 99999,
            'patient_id' => 99999,
            'doctor_id' => 99999,
            'clinic_room_id' => 99999,
        ])
        ->assertSessionHasErrors(['clinic_id', 'patient_id', 'doctor_id', 'clinic_room_id']);
});

it('ignores branch_id supplied in request', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTH2']);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $otherBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
        ]);

    $this->assertDatabaseHas('trx_clinic_visits', [
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
    ]);
    $this->assertDatabaseMissing('trx_clinic_visits', [
        'branch_id' => $otherBranch->id,
    ]);
});

it('updates status and room', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);
    $room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->put(route('rme.visits.update', $visit), [
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
            'clinic_room_id' => $room->id,
        ])
        ->assertRedirect(route('rme.visits.show', $visit));

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and($visit->clinic_room_id)->toBe($room->id);
});

it('denies viewer from creating or updating', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('rme.visits.store'), [
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
        ])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->put(route('rme.visits.update', $visit), ['status' => 'waiting'])
        ->assertForbidden();
});

it('prevents updating visits from another branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTH3']);
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $otherBranch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->manager)
        ->put(route('rme.visits.update', $visit), [
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
        ])
        ->assertForbidden();

    expect($visit->refresh()->status)->toBe(ClinicVisit::STATUS_REGISTERED);
});
