<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
});

it('medical record factory can create record', function () {
    $record = MedicalRecord::factory()->create();

    expect($record)->toBeInstanceOf(MedicalRecord::class)
        ->and($record->id)->toBeInt()
        ->and($record->clinic_visit_id)->toBeInt()
        ->and($record->branch_id)->toBeInt()
        ->and($record->patient_id)->toBeInt()
        ->and($record->doctor_id)->toBeInt();
});

it('medical record default status is draft', function () {
    $record = MedicalRecord::factory()->create();

    expect($record->status)->toBe(MedicalRecord::STATUS_DRAFT);
});

it('clinic visit has one medical record relation works', function () {
    $visit = ClinicVisit::factory()->create();
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $loaded = $visit->medicalRecord;

    expect($loaded)->toBeInstanceOf(MedicalRecord::class)
        ->and($loaded->id)->toBe($record->id);
});

it('medical record belongs to clinic visit relation works', function () {
    $visit = ClinicVisit::factory()->create();
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $loaded = $record->clinicVisit;

    expect($loaded)->toBeInstanceOf(ClinicVisit::class)
        ->and($loaded->id)->toBe($visit->id);
});

it('medical record can be final using factory state', function () {
    $record = MedicalRecord::factory()->final()->create();

    expect($record->status)->toBe(MedicalRecord::STATUS_FINAL);
});
