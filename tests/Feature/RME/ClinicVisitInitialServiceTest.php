<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->rmeBranch = Branch::factory()->create(['code' => 'RME1', 'is_rme_enabled' => true]);
    $this->manager = userWith(['manage_clinic_visits']);
    $this->viewer = userWith(['view_clinic_visits']);

    $this->clinic = Clinic::factory()->create();
    $this->patient = Patient::factory()->create();
    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true]);
    rmeMakeDoctorOnline($this->doctor, $this->rmeBranch);
});

// --- Part A: Initial Service on Create ---

it('admin can create clinic visit with initial_treatment_id', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::latest()->first();

    expect($visit->initial_treatment_id)->toBe($this->treatment->id);
});

it('initial_treatment_id is required on create', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
        ])
        ->assertSessionHasErrors(['initial_treatment_id']);
});

it('initial service note can be saved', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
            'initial_service_note' => 'Pasien perlu tindakan scaling',
        ])
        ->assertRedirect();

    $visit = ClinicVisit::latest()->first();

    expect($visit->initial_service_note)->toBe('Pasien perlu tindakan scaling');
});

it('initial service appears on visit detail page', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $this->treatment->id,
        'initial_service_note' => 'Scaling dan polishing',
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.show', $visit))
        ->assertOk()
        ->assertSee($this->treatment->name)
        ->assertSee('Scaling dan polishing');
});

it('doctor can see initial service on RME page', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'clinic_id' => $this->clinic->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $this->treatment->id,
        'created_by' => $this->manager->id,
        'queue_number' => 1,
    ]);

    $medicalRecord = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.medical-record.show', $visit))
        ->assertOk()
        ->assertSee($this->treatment->name);
});

it('initial service does not create invoice or payment record', function () {
    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertRedirect();

    expect(Invoice::count())->toBe(0);
});

it('inactive treatment is rejected as initial treatment', function () {
    $inactive = Treatment::factory()->create(['is_active' => false]);

    $this->actingAs($this->manager)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $inactive->id,
        ])
        ->assertSessionHasErrors(['initial_treatment_id']);
});

it('unauthorized user cannot create visit with initial treatment', function () {
    $this->actingAs($this->viewer)
        ->post(route('rme.visits.store'), [
            'branch_id' => $this->rmeBranch->id,
            'clinic_id' => $this->clinic->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'initial_treatment_id' => $this->treatment->id,
        ])
        ->assertForbidden();
});

it('initialTreatment relationship returns correct treatment', function () {
    $visit = ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'initial_treatment_id' => $this->treatment->id,
    ]);

    expect($visit->initialTreatment)->not->toBeNull()
        ->and($visit->initialTreatment->id)->toBe($this->treatment->id);
});
