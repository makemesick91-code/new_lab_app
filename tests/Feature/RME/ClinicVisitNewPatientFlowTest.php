<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Treatment\Models\Treatment;
use Carbon\Carbon;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-06-13 09:00:00'));

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->rmeBranch = Branch::factory()->create(['code' => 'RME1', 'is_rme_enabled' => true]);
    // Give the actor both visit and patient management rights.
    $this->actor = userWith(['manage_clinic_visits', 'view_clinic_visits', 'manage patients']);
});

afterEach(fn () => Carbon::setTestNow());

it('registers a visit for an existing patient (default mode)', function () {
    $patient = Patient::factory()->create(['medical_record_number' => 'RM DG-MAIN-2026-0001']);

    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    expect(ClinicVisit::where('patient_id', $patient->id)->exists())->toBeTrue()
        ->and(Patient::count())->toBe(1);
});

it('creates a new patient then the visit in new-patient mode', function () {
    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Pasien Baru RME',
                'branch_id' => $this->branch->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '0007',
            ],
        ])
        ->assertRedirect();

    $patient = Patient::firstWhere('name', 'Pasien Baru RME');

    expect($patient)->not->toBeNull()
        ->and($patient->medical_record_number)->toBe('RM DG-MAIN-2026-0007')
        ->and(ClinicVisit::where('patient_id', $patient->id)->exists())->toBeTrue();
});

it('requires new patient fields in new mode', function () {
    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => ['name' => ''],
        ])
        ->assertSessionHasErrors(['new_patient.name', 'new_patient.branch_id', 'new_patient.manual_rm_number']);

    expect(Patient::count())->toBe(0);
});

it('requires patient_id in existing mode', function () {
    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertSessionHasErrors('patient_id');
});

it('rejects a duplicate final RM number in new mode', function () {
    Patient::factory()->create(['medical_record_number' => 'RM DG-MAIN-2026-0007']);

    $this->actingAs($this->actor)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'new',
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'new_patient' => [
                'name' => 'Duplikat',
                'branch_id' => $this->branch->id,
                'registered_at' => '2026-06-13',
                'manual_rm_number' => '0007',
            ],
        ])
        ->assertSessionHasErrors('new_patient.manual_rm_number');
});

it('shows registered patients as medical_record_number — name in the create form', function () {
    Patient::factory()->create(['medical_record_number' => 'RM DG-MAIN-2026-0001', 'name' => 'Nur Aisyah']);

    $this->actingAs($this->actor)
        ->get(route('rme.visits.create'))
        ->assertOk()
        ->assertSee('RM DG-MAIN-2026-0001 — Nur Aisyah');
});
