<?php

use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientCodeGenerator;
use App\Modules\Patient\Services\PatientService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function newPatientData(array $overrides = []): array
{
    return array_merge([
        'clinic_id' => Clinic::factory()->create()->id,
        'doctor_id' => Doctor::factory()->create()->id,
        'name' => 'Pasien Baru',
        'is_active' => true,
    ], $overrides);
}

it('generates a configurable patient code for a new patient with no code', function () {
    $patient = app(PatientService::class)->create(newPatientData());

    expect($patient->medical_record_number)->toBe('DG-202606-000001');
});

it('keeps an explicitly supplied code for an existing/returning patient', function () {
    $patient = app(PatientService::class)->create(newPatientData([
        'medical_record_number' => 'LEGACY-001',
    ]));

    expect($patient->medical_record_number)->toBe('LEGACY-001');
});

it('increments the sequence so generated codes stay unique', function () {
    $service = app(PatientService::class);

    $first = $service->create(newPatientData());
    $second = $service->create(newPatientData());

    expect($first->medical_record_number)->toBe('DG-202606-000001')
        ->and($second->medical_record_number)->toBe('DG-202606-000002')
        ->and(Patient::query()->distinct()->count('medical_record_number'))->toBe(2);
});

it('skips a code that already exists to avoid collisions', function () {
    Patient::factory()->create(['medical_record_number' => 'DG-202606-000001']);

    $generated = app(PatientCodeGenerator::class)->generate();

    expect($generated)->toBe('DG-202606-000002');
});

it('respects a configured prefix and sequence length', function () {
    config()->set('patient.code.prefix', 'MRN');
    config()->set('patient.code.seq_length', 4);

    $generated = app(PatientCodeGenerator::class)->generate();

    expect($generated)->toBe('MRN-202606-0001');
});

it('uses DG as the default auto-generated prefix', function () {
    expect(config('patient.code.prefix'))->toBe('DG');

    $generated = app(PatientCodeGenerator::class)->generate();

    expect($generated)->toStartWith('DG-');
});

it('does not auto-generate when disabled by config', function () {
    config()->set('patient.code.auto_generate', false);

    $patient = app(PatientService::class)->create(newPatientData());

    expect($patient->medical_record_number)->toBeNull();
});
