<?php

/**
 * LEGACY-RME-PDF-1A — the single source of truth for a patient's earliest
 * NATIVE RME date.
 *
 * The resolver is the only place this bound is computed. These tests pin the
 * canonical field (trx_clinic_visits.visit_date), the exclusions, and the
 * chronological (visit_date, id) ordering.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\PatientEarliestNativeRmeDateResolver;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;

function lrmeResolver(): PatientEarliestNativeRmeDateResolver
{
    return app(PatientEarliestNativeRmeDateResolver::class);
}

function lrmeRmeBranch(array $overrides = []): Branch
{
    return Branch::factory()->create($overrides + [
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

function lrmeVisitWithRecord(Patient $patient, string $visitDate, array $overrides = []): ClinicVisit
{
    $visit = ClinicVisit::factory()->create($overrides + [
        'patient_id' => $patient->id,
        'visit_date' => $visitDate,
    ]);

    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit->refresh();
}

it('returns the earliest native RME date, not the most recent one', function () {
    $patient = Patient::factory()->create();

    lrmeVisitWithRecord($patient, '2024-05-10');
    lrmeVisitWithRecord($patient, '2022-01-03');
    lrmeVisitWithRecord($patient, '2023-08-21');

    expect(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2022-01-03');
});

it('returns null when the patient has no native RME at all', function () {
    $patient = Patient::factory()->create();

    expect(lrmeResolver()->resolve($patient->id))->toBeNull()
        ->and(lrmeResolver()->hasNativeRme($patient->id))->toBeFalse();
});

it('ignores visits that have no medical record', function () {
    $patient = Patient::factory()->create();

    // Earliest visit exists but never produced a medical record.
    ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'visit_date' => '2019-02-02',
    ]);

    lrmeVisitWithRecord($patient, '2021-06-06');

    expect(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2021-06-06');
});

it('ignores cancelled visits even when they carry a medical record', function () {
    $patient = Patient::factory()->create();

    lrmeVisitWithRecord($patient, '2018-03-03', ['status' => ClinicVisit::STATUS_CANCELLED]);
    lrmeVisitWithRecord($patient, '2020-09-09');

    expect(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2020-09-09');
});

it('ignores soft-deleted visits and soft-deleted medical records', function () {
    $patient = Patient::factory()->create();

    $deletedVisit = lrmeVisitWithRecord($patient, '2017-01-01');
    $deletedVisit->delete();

    $visitWithDeletedRecord = lrmeVisitWithRecord($patient, '2017-06-01');
    $visitWithDeletedRecord->medicalRecord()->first()->delete();

    lrmeVisitWithRecord($patient, '2021-11-11');

    expect(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2021-11-11');
});

it('never treats a legacy staging row or a legacy record as native RME', function () {
    $patient = Patient::factory()->create();

    LegacyRmeImport::factory()->create([
        'patient_id' => $patient->id,
        'selected_rme_date' => '2010-01-01',
    ]);

    LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'rme_date' => '2011-01-01',
    ]);

    // Still no native RME: legacy rows are archive documents, not encounters.
    expect(lrmeResolver()->resolve($patient->id))->toBeNull();

    lrmeVisitWithRecord($patient, '2020-04-04');

    expect(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2020-04-04');
});

it('never treats a voided legacy record as native RME', function () {
    $patient = Patient::factory()->create();

    LegacyRmeRecord::factory()->voided()->create([
        'patient_id' => $patient->id,
        'rme_date' => '2009-09-09',
    ]);

    expect(lrmeResolver()->resolve($patient->id))->toBeNull();
});

it('breaks same-day ties on id so the result is stable', function () {
    $patient = Patient::factory()->create();

    $first = lrmeVisitWithRecord($patient, '2022-02-02');
    $second = lrmeVisitWithRecord($patient, '2022-02-02');

    expect($second->id)->toBeGreaterThan($first->id)
        ->and(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2022-02-02');
});

it('scans every branch so the cutoff is the earliest possible bound', function () {
    $patient = Patient::factory()->create();

    $disabledBranch = lrmeRmeBranch(['is_rme_enabled' => false, 'is_active' => false]);
    $activeBranch = lrmeRmeBranch();

    // The earliest encounter lives in a branch that is no longer RME-enabled.
    // A narrower scan would move the bound LATER and let a legacy document
    // overlap a real native record — so it must still count.
    lrmeVisitWithRecord($patient, '2016-05-05', ['branch_id' => $disabledBranch->id]);
    lrmeVisitWithRecord($patient, '2022-05-05', ['branch_id' => $activeBranch->id]);

    expect(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2016-05-05');
});

it('does not leak another patient native RME into the cutoff', function () {
    $patient = Patient::factory()->create();
    $other = Patient::factory()->create();

    lrmeVisitWithRecord($other, '2015-01-01');
    lrmeVisitWithRecord($patient, '2021-01-01');

    expect(lrmeResolver()->resolveAsDateString($patient->id))->toBe('2021-01-01');
});

it('resolves from a patient model as well as a patient id', function () {
    $patient = Patient::factory()->create();
    lrmeVisitWithRecord($patient, '2020-07-07');

    expect(lrmeResolver()->resolveForPatient($patient)?->toDateString())->toBe('2020-07-07');
});
