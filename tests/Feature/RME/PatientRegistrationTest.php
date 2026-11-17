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
    $this->branch = Branch::factory()->create(['code' => 'TLK1', 'is_active' => true, 'is_rme_enabled' => true]);
});

afterEach(fn () => Carbon::setTestNow());

function registrationPayload(array $overrides = []): array
{
    return array_merge(newPatientCoreFields(), [
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

// DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §2) — the Sprint 23.8
// manual number is retired for new registrations: the Nomor RM is automatic and
// read-only, a posted manual number or full override is ignored, and a branch is
// required. These cases replace the manual-number cases they used to assert.

it('creates a patient with a branch and issues the automatic RM in the clinical year', function () {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload())
        ->assertRedirect(route('settings.patients.index'));

    $patient = Patient::firstWhere('name', 'Nur Aisyah');

    expect($patient->medical_record_number)->toBe('DG-TLK1-2026-0001')
        ->and($patient->branch_id)->toBe($this->branch->id)
        ->and($patient->manual_rm_number)->toBeNull()
        ->and(optional($patient->registered_at)->format('Y-m-d'))->toBe('2026-06-13');
});

it('never issues a number that is already held: it takes the next one', function () {
    Patient::factory()->create(['medical_record_number' => 'DG-TLK1-2026-0001']);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'));

    expect(Patient::firstWhere('name', 'Nur Aisyah')->medical_record_number)->toBe('DG-TLK1-2026-0002');
});

it('numbers each branch separately (the final value differs by branch)', function () {
    $other = Branch::factory()->create(['code' => 'LDK2', 'is_active' => true, 'is_rme_enabled' => true]);
    Patient::factory()->create(['medical_record_number' => 'DG-TLK1-2026-0001']);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload(['branch_id' => $other->id, 'name' => 'Ahmad']))
        ->assertRedirect(route('settings.patients.index'));

    expect(Patient::firstWhere('name', 'Ahmad')->medical_record_number)->toBe('DG-LDK2-2026-0001');
});

it('ignores a posted manual RM number, even a malformed one', function () {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload(['manual_rm_number' => '00A1']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.patients.index'));

    expect(Patient::firstWhere('name', 'Nur Aisyah')->medical_record_number)->toBe('DG-TLK1-2026-0001');
});

it('requires the branch to be active and RME-enabled', function () {
    $disabled = Branch::factory()->create(['code' => 'INV9', 'is_active' => true, 'is_rme_enabled' => false]);

    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), registrationPayload(['branch_id' => $disabled->id]))
        ->assertSessionHasErrors('branch_id');
});

it('retires the free override: a full number without a branch is refused, not stored', function () {
    $this->actingAs(userWith(['manage patients']))
        ->post(route('settings.patients.store'), array_merge(newPatientCoreFields(), [
            'clinic_id' => $this->clinic->id,
            'doctor_id' => $this->doctor->id,
            'medical_record_number' => 'LEGACY-RM-001',
            'name' => 'Pasien Lama',
        ]))
        ->assertSessionHasErrors('branch_id');

    expect(Patient::firstWhere('name', 'Pasien Lama'))->toBeNull()
        ->and(Patient::query()->where('medical_record_number', 'LEGACY-RM-001')->exists())->toBeFalse();
});

it('displays a legacy patient without a medical record number safely', function () {
    Patient::factory()->create(['medical_record_number' => null, 'name' => 'Tanpa RM']);

    $this->actingAs(userWith(['manage patients']))
        ->get(route('settings.patients.index'))
        ->assertOk()
        ->assertSee('Tanpa RM');
});
