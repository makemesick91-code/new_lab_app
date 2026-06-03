<?php

use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;

beforeEach(function () {
    seedAccessControl();
});

it('lists doctors for an authorized user', function () {
    Doctor::factory()->count(3)->create();

    $this->actingAs(userWith(['manage doctors']))
        ->get(route('settings.doctors.index'))
        ->assertOk()
        ->assertViewIs('settings.doctors.index');
});

it('filters doctors by clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    Doctor::factory()->create(['clinic_id' => $clinicA->id, 'name' => 'Dr. Alpha']);
    Doctor::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'Dr. Beta']);

    $this->actingAs(superAdmin())
        ->get(route('settings.doctors.index', ['clinic_id' => $clinicA->id]))
        ->assertOk()
        ->assertSee('Dr. Alpha')
        ->assertDontSee('Dr. Beta');
});

it('creates a doctor (happy path)', function () {
    $clinic = Clinic::factory()->create();

    $this->actingAs(userWith(['manage doctors']))
        ->post(route('settings.doctors.store'), [
            'clinic_id' => $clinic->id,
            'code' => 'DOC-001',
            'name' => 'Dr. New',
            'is_active' => 1,
        ])
        ->assertRedirect(route('settings.doctors.index'));

    expect(Doctor::where('code', 'DOC-001')->exists())->toBeTrue();
});

it('requires clinic_id and name', function () {
    $this->actingAs(superAdmin())
        ->post(route('settings.doctors.store'), ['code' => 'X', 'name' => ''])
        ->assertSessionHasErrors(['clinic_id', 'name']);
});

it('rejects a duplicate doctor code', function () {
    $clinic = Clinic::factory()->create();
    Doctor::factory()->create(['code' => 'DDUP']);

    $this->actingAs(superAdmin())
        ->post(route('settings.doctors.store'), ['clinic_id' => $clinic->id, 'code' => 'DDUP', 'name' => 'X'])
        ->assertSessionHasErrors('code');
});

it('updates a doctor', function () {
    $doctor = Doctor::factory()->create(['name' => 'Old']);

    $this->actingAs(superAdmin())
        ->put(route('settings.doctors.update', $doctor), [
            'clinic_id' => $doctor->clinic_id,
            'code' => $doctor->code,
            'name' => 'Updated Doctor',
        ])
        ->assertRedirect(route('settings.doctors.index'));

    expect($doctor->refresh()->name)->toBe('Updated Doctor');
});

it('soft deletes a doctor', function () {
    $doctor = Doctor::factory()->create();

    $this->actingAs(superAdmin())
        ->delete(route('settings.doctors.destroy', $doctor))
        ->assertRedirect();

    expect(Doctor::find($doctor->id))->toBeNull();
    expect(Doctor::withTrashed()->find($doctor->id))->not->toBeNull();
});

it('denies doctor access without permission', function () {
    $this->actingAs(userWith(['manage clinics']))
        ->get(route('settings.doctors.index'))
        ->assertForbidden();
});
