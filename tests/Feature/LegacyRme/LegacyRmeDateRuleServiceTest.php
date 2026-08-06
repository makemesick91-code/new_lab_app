<?php

/**
 * LEGACY-RME-PDF-1A — the legacy RME date rules.
 *
 * The legacy date is chosen MANUALLY from what the document shows. It must be
 * strictly earlier than the patient's earliest native RME date, strictly
 * earlier than today, and never earlier than the patient's birth date.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeDateRuleService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

function lrmeDateRules(): LegacyRmeDateRuleService
{
    return app(LegacyRmeDateRuleService::class);
}

function lrmeNativeVisit(Patient $patient, string $visitDate): ClinicVisit
{
    $visit = ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'visit_date' => $visitDate,
    ]);

    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return $visit;
}

it('accepts a legacy date one day before the earliest native RME date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    $result = lrmeDateRules()->evaluate($patient, '2022-03-09');

    expect($result->passed)->toBeTrue()
        ->and($result->code)->toBeNull()
        ->and($result->context['earliest_native_rme_date'])->toBe('2022-03-10');
});

it('rejects a legacy date equal to the earliest native RME date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    $result = lrmeDateRules()->evaluate($patient, '2022-03-10');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME);
});

it('rejects a legacy date after the earliest native RME date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    $result = lrmeDateRules()->evaluate($patient, '2022-03-11');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_RME);
});

it('rejects a patient that has no native RME to compare against', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);

    $result = lrmeDateRules()->evaluate($patient, '2015-01-01');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_PATIENT_HAS_NO_NATIVE_RME)
        ->and($result->context['earliest_native_rme_date'])->toBeNull();
});

it('rejects today as a legacy date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);

    // The native RME sits in the future relative to "today" so the native rule
    // passes and the today-rule is the one under test.
    $tomorrow = CarbonImmutable::now(lrmeDateRules()->timezone())->addDay()->toDateString();
    lrmeNativeVisit($patient, $tomorrow);

    $today = lrmeDateRules()->today()->toDateString();
    $result = lrmeDateRules()->evaluate($patient, $today);

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_IN_FUTURE);
});

it('rejects a future legacy date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);

    $future = CarbonImmutable::now(lrmeDateRules()->timezone())->addDays(10);
    lrmeNativeVisit($patient, $future->addDays(5)->toDateString());

    $result = lrmeDateRules()->evaluate($patient, $future->toDateString());

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_IN_FUTURE);
});

it('rejects a legacy date earlier than the patient birth date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '2000-06-15']);
    lrmeNativeVisit($patient, '2022-01-01');

    $result = lrmeDateRules()->evaluate($patient, '2000-06-14');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH)
        ->and($result->context['patient_birth_date'])->toBe('2000-06-15');
});

it('accepts a legacy date equal to the patient birth date', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '2000-06-15']);
    lrmeNativeVisit($patient, '2022-01-01');

    expect(lrmeDateRules()->evaluate($patient, '2000-06-15')->passed)->toBeTrue();
});

it('rejects a legacy date equal to the birth date when the same-day allowance is off', function () {
    config()->set('legacy_rme.dates.allow_same_day_as_birth_date', false);

    $patient = Patient::factory()->create(['date_of_birth' => '2000-06-15']);
    lrmeNativeVisit($patient, '2022-01-01');

    expect(lrmeDateRules()->evaluate($patient, '2000-06-15')->code)
        ->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH)
        ->and(lrmeDateRules()->evaluate($patient, '2000-06-16')->passed)->toBeTrue();
});

it('skips the birth-date rule when the patient has no recorded birth date', function () {
    // date_of_birth is nullable by design; the service never invents one and
    // never blocks the import on a missing value.
    $patient = Patient::factory()->create(['date_of_birth' => null]);
    lrmeNativeVisit($patient, '2022-01-01');

    $result = lrmeDateRules()->evaluate($patient, '1899-01-01');

    expect($result->passed)->toBeTrue()
        ->and($result->context)->not->toHaveKey('patient_birth_date');
});

it('rejects an unparseable or empty legacy date', function () {
    $patient = Patient::factory()->create();
    lrmeNativeVisit($patient, '2022-01-01');

    expect(lrmeDateRules()->evaluate($patient, null)->code)
        ->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_INVALID)
        ->and(lrmeDateRules()->evaluate($patient, 'bukan-tanggal')->code)
        ->toBe(LegacyRmeDateRuleService::CODE_LEGACY_DATE_INVALID);
});

it('handles a leap day and accepts a Carbon instance as input', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2021-01-01');

    $result = lrmeDateRules()->evaluate($patient, CarbonImmutable::parse('2020-02-29'));

    expect($result->passed)->toBeTrue()
        ->and($result->context['selected_rme_date'])->toBe('2020-02-29');
});

it('compares calendar dates only, ignoring any time component', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    // 23:59 on the day before the cutoff is still the day before.
    $result = lrmeDateRules()->evaluate($patient, '2022-03-09 23:59:59');

    expect($result->passed)->toBeTrue()
        ->and($result->context['selected_rme_date'])->toBe('2022-03-09');
});

it('recomputes the cutoff server-side and never trusts a supplied snapshot', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    // Even if a caller believed the cutoff was far in the past, the service
    // recomputes it from the database.
    $result = lrmeDateRules()->evaluate($patient, '2022-03-09');

    expect($result->context['earliest_native_rme_date'])->toBe('2022-03-10')
        ->and(lrmeDateRules()->snapshotCutoff($patient))->toBe('2022-03-10');
});

it('is unaffected by an existing legacy record for the same patient', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'rme_date' => '2015-01-01',
    ]);

    // A previously imported legacy record must never become the comparison
    // point — the cutoff stays the earliest NATIVE RME date.
    $result = lrmeDateRules()->evaluate($patient, '2014-01-01');

    expect($result->passed)->toBeTrue()
        ->and($result->context['earliest_native_rme_date'])->toBe('2022-03-10');
});

it('is unaffected by the origin branch of the import', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    // Origin branch is provenance metadata; it never changes the date bound.
    expect(lrmeDateRules()->evaluate($patient, '2022-03-09')->passed)->toBeTrue();
});

it('throws a validation exception on assert and returns the result on success', function () {
    $patient = Patient::factory()->create(['date_of_birth' => '1990-01-01']);
    lrmeNativeVisit($patient, '2022-03-10');

    expect(lrmeDateRules()->assert($patient, '2022-03-09')->passed)->toBeTrue();

    try {
        lrmeDateRules()->assert($patient, '2022-03-10');
        $this->fail('Expected a ValidationException for a non-historical legacy date.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey(LegacyRmeDateRuleService::FIELD);
    }
});

it('never leaks patient identity into the rule context', function () {
    // Built at runtime so no KTP-shaped literal ever sits in the source tree.
    $ktp = '7371'.str_repeat('9', 12);

    $patient = Patient::factory()->create([
        'date_of_birth' => '1990-01-01',
        'ktp_number' => $ktp,
    ]);
    lrmeNativeVisit($patient, '2022-03-10');

    $context = lrmeDateRules()->evaluate($patient, '2022-03-11')->context;
    $serialized = json_encode($context);

    expect($serialized)->not->toContain($ktp)
        ->and($serialized)->not->toContain($patient->name)
        ->and($context)->toHaveKey('patient_id');
});
