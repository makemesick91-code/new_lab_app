<?php

/**
 * FIX-04b — the legacy odontogram date rules.
 *
 * The date is the one fact a human types, so it is the one fact that can be
 * wrong. These tests pin every boundary the rules draw, including the ones that
 * are deliberately EXCLUSIVE (equal-to-native, today) and the one that is
 * deliberately INCLUSIVE (equal-to-birth-date).
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyOdontogram\Services\PatientEarliestNativeOdontogramDateResolver;
use App\Modules\LegacyRme\Services\PatientEarliestNativeRmeDateResolver;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
});

function lodoRules(): LegacyOdontogramDateRuleService
{
    return app(LegacyOdontogramDateRuleService::class);
}

it('refuses a patient with no native odontogram at all', function () {
    $patient = lodoPatient();

    $result = lodoRules()->evaluate($patient, '2015-01-01');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM);
});

it('accepts a date strictly before the earliest native odontogram', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $result = lodoRules()->evaluate($patient, '2019-06-01');

    expect($result->passed)->toBeTrue()
        ->and($result->context['earliest_native_odontogram_date'])->toBe('2022-03-10');
});

it('refuses a date EQUAL to the earliest native odontogram, because equal is the overlap case', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $result = lodoRules()->evaluate($patient, '2022-03-10');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('refuses a date after the earliest native odontogram', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $result = lodoRules()->evaluate($patient, '2023-01-05');

    expect($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('refuses TODAY itself — an archive is historical by definition', function () {
    $patient = lodoPatient();

    // A native odontogram far in the future so the native rule cannot be what
    // trips: the only thing wrong with the date is that it is today.
    lodoNativeOdontogram($patient, app(ClinicalClock::class)->today()->addYears(5)->toDateString());

    $result = lodoRules()->evaluate($patient, app(ClinicalClock::class)->today()->toDateString());

    expect($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_IN_FUTURE);
});

it('refuses a future date', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, app(ClinicalClock::class)->today()->addYears(5)->toDateString());

    $result = lodoRules()->evaluate($patient, app(ClinicalClock::class)->today()->addDay()->toDateString());

    expect($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_IN_FUTURE);
});

it('evaluates "today" on the CLINICAL calendar, not raw UTC', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, app(ClinicalClock::class)->today()->addYears(5)->toDateString());

    $result = lodoRules()->evaluate($patient, '2015-01-01');

    expect($result->passed)->toBeTrue()
        ->and($result->context['clinical_timezone'])->toBe(app(ClinicalClock::class)->timezone())
        ->and($result->context['today'])->toBe(app(ClinicalClock::class)->todayString());
});

it('refuses a date before the patient birth date', function () {
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    lodoNativeOdontogram($patient, '2022-03-10');

    $result = lodoRules()->evaluate($patient, '1989-12-31');

    expect($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH);
});

it('ACCEPTS a date exactly equal to the patient birth date', function () {
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoRules()->evaluate($patient, '1990-01-01')->passed)->toBeTrue();
});

it('SKIPS the birth-date rule when the patient has no recorded birth date', function () {
    $patient = lodoPatient(['date_of_birth' => null]);
    lodoNativeOdontogram($patient, '2022-03-10');

    $result = lodoRules()->evaluate($patient, '1900-01-01');

    expect($result->passed)->toBeTrue()
        ->and($result->context)->not->toHaveKey('patient_birth_date');
});

it('refuses an unparseable date', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoRules()->evaluate($patient, 'bukan-tanggal')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_INVALID);
});

it('throws a ValidationException from assert() and passes through on success', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(fn () => lodoRules()->assert($patient, '2023-01-01'))
        ->toThrow(ValidationException::class);

    expect(lodoRules()->assert($patient, '2019-01-01')->passed)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The resolver itself — the reason this module does NOT reuse the legacy RME
| cutoff.
|--------------------------------------------------------------------------
*/

it('is bounded by the earliest ODONTOGRAM, not by the earliest medical record', function () {
    $patient = lodoPatient();

    // The trap this resolver exists to avoid. The patient's earliest NATIVE
    // ODONTOGRAM is 2020-01-01, on a visit that carries no medical record. The
    // earliest visit owning a MEDICAL RECORD is 2022-03-10 and has no
    // odontogram at all.
    //
    // PatientEarliestNativeRmeDateResolver would answer 2022-03-10 and happily
    // accept a "legacy" chart dated 2021-01-01 — a legacy odontogram filed
    // AFTER a real native odontogram already exists, which is precisely the
    // chronological corruption the rule is for. This resolver answers
    // 2020-01-01 and refuses it.
    lodoNativeOdontogram($patient, '2020-01-01');

    $rmeOnlyVisit = ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'visit_date' => '2022-03-10',
    ]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $rmeOnlyVisit->id,
        'branch_id' => $rmeOnlyVisit->branch_id,
        'patient_id' => $rmeOnlyVisit->patient_id,
        'doctor_id' => $rmeOnlyVisit->doctor_id,
    ]);

    // The two resolvers genuinely disagree — that is the whole point.
    expect(app(PatientEarliestNativeOdontogramDateResolver::class)->resolve((int) $patient->id)?->toDateString())
        ->toBe('2020-01-01')
        ->and(app(PatientEarliestNativeRmeDateResolver::class)->resolve((int) $patient->id)?->toDateString())
        ->toBe('2022-03-10');

    expect(lodoRules()->evaluate($patient, '2021-01-01')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);

    // And a chart genuinely older than the first odontogram is still fine.
    expect(lodoRules()->evaluate($patient, '2019-01-01')->passed)->toBeTrue();
});

it('excludes cancelled visits and soft-deleted odontograms from the cutoff', function () {
    $patient = lodoPatient();

    lodoNativeOdontogram($patient, '2018-01-01', ClinicVisit::STATUS_CANCELLED);
    lodoNativeOdontogram($patient, '2019-01-01')->delete();
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(app(PatientEarliestNativeOdontogramDateResolver::class)->resolve((int) $patient->id)?->toDateString())
        ->toBe('2022-03-10');
});

it('snapshots the server-computed cutoff and never takes it from a caller', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoRules()->snapshotCutoff($patient))->toBe('2022-03-10');
});
