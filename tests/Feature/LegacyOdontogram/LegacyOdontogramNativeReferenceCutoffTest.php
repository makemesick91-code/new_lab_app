<?php

/**
 * LEGACY-ODONTOGRAM-NATIVE-REFERENCE-CUTOFF-1 — what counts as a NATIVE
 * odontogram for the archive cutoff.
 *
 * The cutoff acts in TWO directions at once, which is why getting it wrong is
 * not a cosmetic problem:
 *
 *   1. as a GATE — a patient with no native odontogram cannot file an archive
 *      at all (CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM);
 *   2. as a BOUND — the archive date must be STRICTLY before the earliest
 *      native odontogram date.
 *
 * Counting a contentless placeholder row therefore does two wrong things: it
 * opens the gate on evidence that does not exist, and it draws the bound at a
 * date on which nothing was actually charted.
 *
 * The predicate is deliberately IDENTICAL to the one the doctor's Patient
 * Odontogram History already applies (`Odontogram::hasRecordedTeeth()`), so a
 * row the doctor is not shown as history can never bound an archive either.
 * One definition, pinned here.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramNativeReferenceRepositoryInterface;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyOdontogram\Services\PatientEarliestNativeOdontogramDateResolver;
use App\Modules\Odontogram\Models\Odontogram;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
});

function lodoCutoff(): PatientEarliestNativeOdontogramDateResolver
{
    return app(PatientEarliestNativeOdontogramDateResolver::class);
}

function lodoDateRules(): LegacyOdontogramDateRuleService
{
    return app(LegacyOdontogramDateRuleService::class);
}

/*
|--------------------------------------------------------------------------
| The canonical predicate — ONE definition, shared with Patient History.
|--------------------------------------------------------------------------
*/

it('treats a charted tooth as clinical content and everything else as empty', function () {
    $charted = new Odontogram(['tooth_map_payload' => lodoChartedTeeth()]);
    $nullPayload = new Odontogram(['tooth_map_payload' => null]);
    $emptyTeethMap = new Odontogram(['tooth_map_payload' => ['teeth' => []]]);
    $noTeethKey = new Odontogram(['tooth_map_payload' => ['something_else' => 'x']]);

    expect($charted->hasRecordedTeeth())->toBeTrue()
        ->and($nullPayload->hasRecordedTeeth())->toBeFalse()
        ->and($emptyTeethMap->hasRecordedTeeth())->toBeFalse()
        ->and($noTeethKey->hasRecordedTeeth())->toBeFalse();
});

it('counts a charted tooth regardless of the workflow status of the row', function () {
    // Content, not workflow state, is the test. A finalized-but-blank row is
    // still blank; a draft that charts a tooth is still clinical evidence.
    $draftWithContent = new Odontogram([
        'status' => Odontogram::STATUS_DRAFT,
        'tooth_map_payload' => lodoChartedTeeth(),
    ]);
    $finalizedButBlank = new Odontogram([
        'status' => Odontogram::STATUS_FINALIZED,
        'tooth_map_payload' => null,
    ]);

    expect($draftWithContent->hasRecordedTeeth())->toBeTrue()
        ->and($finalizedButBlank->hasRecordedTeeth())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| EMPTY-ONLY — the gate closes, it does not silently open.
|--------------------------------------------------------------------------
*/

it('reports NO native reference for a patient whose only odontogram is an empty placeholder', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2024-05-01');

    expect(lodoCutoff()->resolve($patient->id))->toBeNull()
        ->and(lodoCutoff()->hasNativeOdontogram($patient->id))->toBeFalse();
});

it('refuses the archive for an empty-only patient rather than bounding it on a fake date', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2024-05-01');

    // Before this sprint the placeholder opened the gate and bounded the
    // archive at 2024-05-01 — a date on which nothing was charted.
    $result = lodoDateRules()->evaluate($patient, '2019-01-01');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM);
});

it('ignores an empty payload whose teeth map is present but empty — the shape SQL cannot portably exclude', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2024-05-01', ['teeth' => []]);

    expect(lodoCutoff()->resolve($patient->id))->toBeNull();
});

it('ignores MANY empty placeholders, not just one', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2024-05-01');
    lodoEmptyNativeOdontogram($patient, '2024-06-01', ['teeth' => []]);
    lodoEmptyNativeOdontogram($patient, '2024-07-01');

    expect(lodoCutoff()->resolve($patient->id))->toBeNull();
});

it('reports NO native reference for a patient with no odontogram row at all — unchanged', function () {
    expect(lodoCutoff()->resolve(lodoPatient()->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| MEANINGFUL NATIVE DATA REMAINS AUTHORITATIVE — the protection is preserved.
|--------------------------------------------------------------------------
*/

it('still bounds the archive on a real charted odontogram', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2022-03-10');

    expect(lodoDateRules()->evaluate($patient, '2019-06-01')->passed)->toBeTrue();
    expect(lodoDateRules()->evaluate($patient, '2023-01-05')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('still refuses a date EQUAL to a real charted odontogram', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoDateRules()->evaluate($patient, '2022-03-10')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('picks the EARLIEST charted odontogram when several exist', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2023-01-05');
    lodoNativeOdontogram($patient, '2020-01-01');
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2020-01-01');
});

/*
|--------------------------------------------------------------------------
| MIXED — an empty row must not move the bound in EITHER direction.
|--------------------------------------------------------------------------
*/

it('does not let an EARLIER empty placeholder drag the bound backwards', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2020-01-01');
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2022-03-10');

    // The window between the placeholder and the real chart is legitimately
    // archivable: nothing was charted on 2020-01-01.
    expect(lodoDateRules()->evaluate($patient, '2021-06-01')->passed)->toBeTrue();
});

it('does not let a LATER empty placeholder push the bound forwards', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');
    lodoEmptyNativeOdontogram($patient, '2024-05-01');

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2022-03-10');

    // Still refused: the real chart, not the later placeholder, is the bound.
    expect(lodoDateRules()->evaluate($patient, '2023-01-05')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('resolves the earliest CHARTED row even when empty rows surround it', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2019-01-01');
    lodoNativeOdontogram($patient, '2021-02-02');
    lodoEmptyNativeOdontogram($patient, '2020-01-01', ['teeth' => []]);
    lodoNativeOdontogram($patient, '2023-03-03');

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2021-02-02');
});

/*
|--------------------------------------------------------------------------
| SCOPE — the exclusions the cutoff already made must survive the change.
|--------------------------------------------------------------------------
*/

it('still ignores a charted odontogram hanging off a CANCELLED visit', function () {
    $patient = lodoPatient();
    lodoOdontogramRow($patient, '2020-01-01', lodoChartedTeeth(), ClinicVisit::STATUS_CANCELLED);
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2022-03-10');
});

it('still ignores a SOFT-DELETED charted odontogram', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2020-01-01')->delete();
    lodoNativeOdontogram($patient, '2022-03-10');

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2022-03-10');
});

it('breaks a same-day tie deterministically on the surrogate key', function () {
    $patient = lodoPatient();
    $first = lodoNativeOdontogram($patient, '2022-03-10');
    $second = lodoNativeOdontogram($patient, '2022-03-10');

    // Asserting only the resolved DATE would be vacuous here — both rows carry
    // the same one, so it cannot observe which won. Go through the repository
    // and assert the VISIT, which is what `orderBy('id')` actually decides and
    // what makes the chunked walk deterministic.
    $visit = app(LegacyOdontogramNativeReferenceRepositoryInterface::class)
        ->earliestVisitWithOdontogramForPatient($patient->id);

    expect($visit?->id)->toBe($first->clinic_visit_id)
        ->and($visit?->id)->toBeLessThan($second->clinic_visit_id)
        ->and(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2022-03-10');
});

/*
|--------------------------------------------------------------------------
| PATIENT ISOLATION — no cutoff may leak across patients.
|--------------------------------------------------------------------------
*/

it('never lets one patient\'s empty placeholder affect another patient', function () {
    $a = lodoPatient();
    $b = lodoPatient();

    lodoEmptyNativeOdontogram($a, '2020-01-01');
    lodoNativeOdontogram($b, '2022-03-10');

    expect(lodoCutoff()->resolve($a->id))->toBeNull()
        ->and(lodoCutoff()->resolve($b->id)?->toDateString())->toBe('2022-03-10');
});

it('never lets one patient\'s charted odontogram bound another patient', function () {
    $a = lodoPatient();
    $b = lodoPatient();

    lodoNativeOdontogram($a, '2018-01-01');

    expect(lodoCutoff()->resolve($b->id))->toBeNull()
        ->and(lodoDateRules()->evaluate($b, '2017-01-01')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM);
});

/*
|--------------------------------------------------------------------------
| BRANCH — the resolver stays deliberately un-branch-scoped.
|--------------------------------------------------------------------------
*/

it('still bounds on a charted odontogram from ANOTHER branch — narrowing the scan could only admit an overlapping document', function () {
    $patient = lodoPatient();
    $other = lodoBranch('LDK2', 'Cabang Landak');

    $visit = ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'visit_date' => '2022-03-10',
        'branch_id' => $other->id,
    ]);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $other->id,
        'tooth_map_payload' => lodoChartedTeeth(),
    ]);

    expect(lodoCutoff()->resolve($patient->id)?->toDateString())->toBe('2022-03-10');
});
