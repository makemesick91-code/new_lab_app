<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;

beforeEach(function () {
    seedAccessControl();
});

it('lists patients for an authorized user', function () {
    Patient::factory()->count(3)->create();

    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.index'))
        ->assertOk()
        ->assertViewIs('settings.patients.index');
});

it('filters patients by clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    Patient::factory()->create(['clinic_id' => $clinicA->id, 'doctor_id' => Doctor::factory()->create(['clinic_id' => $clinicA->id]), 'name' => 'Patient Alpha']);
    Patient::factory()->create(['clinic_id' => $clinicB->id, 'doctor_id' => Doctor::factory()->create(['clinic_id' => $clinicB->id]), 'name' => 'Patient Beta']);

    $this->actingAs(superAdmin())
        ->get(route('settings.patients.index', ['clinic_id' => $clinicA->id]))
        ->assertOk()
        ->assertSee('Patient Alpha')
        ->assertDontSee('Patient Beta');
});

it('creates a patient (happy path)', function () {
    $clinic = Clinic::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2) — a branch is
    // required and the Nomor RM is automatic; the retired free override is ignored.
    $branch = Branch::factory()->create(['code' => 'MDP1', 'is_active' => true, 'is_rme_enabled' => true]);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), array_merge(newPatientCoreFields(), [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'branch_id' => $branch->id,
            'medical_record_number' => 'MRN-001',
            'name' => 'John Patient',
            'gender' => 'Male',
            'is_active' => 1,
        ]))
        ->assertRedirect(route('settings.patients.index'));

    expect(Patient::where('medical_record_number', 'MRN-001')->exists())->toBeFalse()
        ->and(Patient::where('name', 'John Patient')->value('medical_record_number'))
        ->toStartWith('DG-MDP1-')->toEndWith('-0001');
});

it('requires clinic_id, doctor_id and name', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.patients.store'), array_merge(newPatientCoreFields(), ['name' => '']))
        ->assertSessionHasErrors(['clinic_id', 'doctor_id', 'name']);
});

it('updates a patient', function () {
    $patient = Patient::factory()->create(['name' => 'Old']);

    $this->actingAs(superAdmin())
        ->put(route('settings.patients.update', $patient), [
            'clinic_id' => $patient->clinic_id,
            'doctor_id' => $patient->doctor_id,
            'name' => 'Updated Patient',
        ])
        ->assertRedirect(route('settings.patients.index'));

    expect($patient->refresh()->name)->toBe('Updated Patient');
});

it('soft deletes a patient', function () {
    $patient = Patient::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.patients.destroy', $patient))
        ->assertRedirect();

    expect(Patient::find($patient->id))->toBeNull();
    expect(Patient::withTrashed()->find($patient->id))->not->toBeNull();
});

it('denies patient access without permission', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.patients.index'))
        ->assertForbidden();
});
