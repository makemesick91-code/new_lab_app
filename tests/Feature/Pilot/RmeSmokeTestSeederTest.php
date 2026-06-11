<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RmeSmokeTestSeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    test()->seed([
        BranchSeeder::class,
        PermissionSeeder::class,
        RoleSeeder::class,
    ]);
});

function seedRmeSmokeTest(): void
{
    test()->seed(RmeSmokeTestSeeder::class);
}

it('runs RmeSmokeTestSeeder successfully', function () {
    seedRmeSmokeTest();

    expect(Patient::where('name', RmeSmokeTestSeeder::PATIENT_NAME)->exists())->toBeTrue();
});

it('is idempotent when run multiple times', function () {
    seedRmeSmokeTest();
    seedRmeSmokeTest();

    expect(Patient::where('name', RmeSmokeTestSeeder::PATIENT_NAME)->count())->toBe(1)
        ->and(ClinicVisit::where('visit_number', RmeSmokeTestSeeder::VISIT_NUMBER)->count())->toBe(1)
        ->and(ClinicVisit::where('visit_number', RmeSmokeTestSeeder::VISIT_NUMBER_CASHIER)->count())->toBe(1)
        ->and(User::where('email', RmeSmokeTestSeeder::DOCTOR_USER_EMAIL)->count())->toBe(1);
});

it('creates smoke-test patient linked to main branch clinic', function () {
    seedRmeSmokeTest();

    $branch = Branch::where('code', RmeSmokeTestSeeder::BRANCH_CODE)->firstOrFail();
    $patient = Patient::where('medical_record_number', RmeSmokeTestSeeder::PATIENT_MRN)->firstOrFail();

    expect($patient->name)->toBe(RmeSmokeTestSeeder::PATIENT_NAME)
        ->and($patient->clinic)->not->toBeNull();
});

it('creates smoke-test users with expected roles', function () {
    seedRmeSmokeTest();

    $doctor = User::where('email', RmeSmokeTestSeeder::DOCTOR_USER_EMAIL)->firstOrFail();
    $perawat = User::where('email', RmeSmokeTestSeeder::PERAWAT_USER_EMAIL)->firstOrFail();
    $kasir = User::where('email', RmeSmokeTestSeeder::KASIR_USER_EMAIL)->firstOrFail();
    $owner = User::where('email', RmeSmokeTestSeeder::OWNER_USER_EMAIL)->firstOrFail();

    expect($doctor->hasRole('Doctor'))->toBeTrue()
        ->and($perawat->hasRole('Perawat'))->toBeTrue()
        ->and($kasir->hasRole('Kasir'))->toBeTrue()
        ->and($owner->hasRole('Owner'))->toBeTrue();
});

it('creates clinical visit with medical record and odontogram relationships', function () {
    seedRmeSmokeTest();

    $branch = Branch::where('code', RmeSmokeTestSeeder::BRANCH_CODE)->firstOrFail();
    $visit = ClinicVisit::where('visit_number', RmeSmokeTestSeeder::VISIT_NUMBER)->firstOrFail();

    expect($visit->branch_id)->toBe($branch->id)
        ->and($visit->patient->name)->toBe(RmeSmokeTestSeeder::PATIENT_NAME)
        ->and($visit->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS)
        ->and($visit->medicalRecord)->not->toBeNull()
        ->and($visit->medicalRecord->status)->toBe(MedicalRecord::STATUS_DRAFT)
        ->and($visit->odontogram)->not->toBeNull()
        ->and($visit->odontogram->status)->toBe(Odontogram::STATUS_DRAFT);
});

it('creates cashier-pending visit with finalized medical record for billing handoff', function () {
    seedRmeSmokeTest();

    $visit = ClinicVisit::where('visit_number', RmeSmokeTestSeeder::VISIT_NUMBER_CASHIER)->firstOrFail();

    expect($visit->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and($visit->medicalRecord)->not->toBeNull()
        ->and($visit->medicalRecord->status)->toBe(MedicalRecord::STATUS_FINAL);
});
