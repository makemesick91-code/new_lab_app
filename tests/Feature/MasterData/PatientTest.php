<?php

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

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), [
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'medical_record_number' => 'MRN-001',
            'name' => 'John Patient',
            'gender' => 'Male',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.patients.index'));

    expect(Patient::where('medical_record_number', 'MRN-001')->exists())->toBeTrue();
});

it('requires clinic_id, doctor_id and name', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.patients.store'), ['name' => ''])
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
