<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Carbon\Carbon;

beforeEach(function () {
    seedAccessControl();
    Carbon::setTestNow(Carbon::parse('2026-06-13 09:00:00'));

    $this->clinic = Clinic::factory()->create();
    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->branch = Branch::factory()->create(['code' => 'TKM1', 'is_active' => true, 'is_rme_enabled' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => test()->clinic->id,
        'doctor_id' => test()->doctor->id,
        'branch_id' => test()->branch->id,
        'registered_at' => '2026-06-13',
        'manual_rm_number' => '0001',
        'name' => 'Nur Aisyah',
        'gender' => 'Female',
        'is_active' => 1,
    ], $overrides);
}

it('creates a patient with branch + manual RM number and composes the final RM', function () {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload())
        ->assertRedirect(route('settings.patients.index'));

    $patient = Patient::firstWhere('name', 'Nur Aisyah');

    expect($patient->medical_record_number)->toBe('RM DG-TKM1-2026-0001')
        ->and($patient->branch_id)->toBe($this->branch->id)
        ->and($patient->manual_rm_number)->toBe('0001')
        ->and(optional($patient->registered_at)->format('Y-m-d'))->toBe('2026-06-13');
});

it('rejects a duplicate final medical record number', function () {
    Patient::factory()->create(['medical_record_number' => 'RM DG-TKM1-2026-0001']);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload())
        ->assertSessionHasErrors('manual_rm_number');
});

it('allows the same manual number on a different branch (final value differs)', function () {
    $other = Branch::factory()->create(['code' => 'LDK2', 'is_active' => true, 'is_rme_enabled' => true]);
    Patient::factory()->create(['medical_record_number' => 'RM DG-TKM1-2026-0001']);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload(['branch_id' => $other->id, 'name' => 'Ahmad']))
        ->assertRedirect(route('settings.patients.index'));

    expect(Patient::firstWhere('name', 'Ahmad')->medical_record_number)->toBe('RM DG-LDK2-2026-0001');
});

it('rejects a non-numeric manual RM number', function () {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload(['manual_rm_number' => '00A1']))
        ->assertSessionHasErrors('manual_rm_number');
});

it('requires the branch to be active and RME-enabled', function () {
    $disabled = Branch::factory()->create(['code' => 'INV9', 'is_active' => true, 'is_rme_enabled' => false]);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload(['branch_id' => $disabled->id]))
        ->assertSessionHasErrors('branch_id');
});

it('keeps the legacy explicit code path working without the new fields', function () {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), [
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'medical_record_number' => 'LEGACY-RM-001',
            'name' => 'Pasien Lama',
        ])
        ->assertRedirect(route('settings.patients.index'));

    $patient = Patient::firstWhere('name', 'Pasien Lama');

    expect($patient->medical_record_number)->toBe('LEGACY-RM-001')
        ->and($patient->branch_id)->toBeNull();
});

it('displays a legacy patient without a medical record number safely', function () {
    Patient::factory()->create(['medical_record_number' => null, 'name' => 'Tanpa RM']);

    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.index'))
        ->assertOk()
        ->assertSee('Tanpa RM');
});
