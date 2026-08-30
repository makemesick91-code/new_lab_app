<?php

/**
 * REVISION-LEGACY-ODONTOGRAM-NATIVE-OPTIONAL-1 — a legacy odontogram is
 * historical evidence, and evidence does not need permission from the present.
 *
 * THE REVISION. The archive used to refuse any patient who had never been
 * charted in this system (`PATIENT_HAS_NO_NATIVE_ODONTOGRAM`). That refusal
 * inverted the real situation: the patients whose paper charts most need
 * archiving are precisely the ones who have not yet been examined here. Having
 * no native odontogram is now a VALID clinical state, and the archive files
 * against it.
 *
 * WHAT DID NOT CHANGE, AND THIS DISTINCTION IS THE WHOLE SPRINT:
 *
 *     NATIVE OPTIONAL  !=  NATIVE CUTOFF REMOVED
 *
 * When a meaningful native odontogram DOES exist it still bounds the archive,
 * still at the EARLIEST one, and still STRICTLY. Only the "you must have one at
 * all" gate is gone. Every test below that pins a rejection is pinning the half
 * of the old rule that survives.
 *
 * THE OTHER DISTINCTION THIS SPRINT MAKES LOAD-BEARING:
 *
 *     NO NATIVE FOUND  !=  THE QUERY FAILED
 *
 * Before the revision both would have refused, so conflating them was merely
 * untidy. Now "no native" ALLOWS, so a swallowed database error would silently
 * become permission to file a chart with no chronological bound at all. The
 * resolver therefore lets exceptions propagate, and a test below holds it there.
 */

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramNativeReferenceRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramDateRuleService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Services\PatientEarliestNativeOdontogramDateResolver;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
});

function lonRules(): LegacyOdontogramDateRuleService
{
    return app(LegacyOdontogramDateRuleService::class);
}

function lonCutoff(): PatientEarliestNativeOdontogramDateResolver
{
    return app(PatientEarliestNativeOdontogramDateResolver::class);
}

/**
 * The clinical counts that a legacy archive must never move.
 *
 * @return array<string, int>
 */
function lonNativeCounts(): array
{
    return [
        'visits' => ClinicVisit::count(),
        'odontograms' => Odontogram::count(),
        'medical_records' => MedicalRecord::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'lab_orders' => LabOrder::count(),
        'satusehat' => SatusehatCandidate::count(),
        'patients' => Patient::count(),
    ];
}

/** A fully rendered import at READY_FOR_REVIEW for a patient with NO native odontogram. */
function lonReadyImportWithoutNative(string $legacyDate = '2019-06-01', int $pages = 2): LegacyOdontogramImport
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages($pages));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages($pages));

    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    $import = lodoStageImport($patient, $legacyDate, lodoOperator(), $pages);

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    return $import->refresh();
}

/*
|--------------------------------------------------------------------------
| THE REVISION — no native odontogram is a valid state to archive against.
|--------------------------------------------------------------------------
*/

it('accepts an archive for a patient who has NO native odontogram at all', function () {
    $patient = lodoPatient();

    $result = lonRules()->evaluate($patient, '2015-01-01');

    expect($result->passed)->toBeTrue()
        ->and($result->code)->toBeNull()
        // Absence is reported as absence. No epoch, no sentinel, no "now".
        ->and($result->context['earliest_native_odontogram_date'])->toBeNull();
});

it('accepts an archive for a patient whose ONLY odontogram is a contentless placeholder', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2024-05-01');

    // The placeholder charts nothing, so it is not evidence: it neither blocks
    // the archive nor bounds it at 2024-05-01.
    $result = lonRules()->evaluate($patient, '2019-01-01');

    expect($result->passed)->toBeTrue()
        ->and($result->context['earliest_native_odontogram_date'])->toBeNull()
        ->and(lonCutoff()->hasNativeOdontogram($patient->id))->toBeFalse();
});

it('never invents a cutoff when there is no native odontogram', function () {
    $patient = lodoPatient();

    // A fabricated bound would show up as a non-null cutoff, and a date at the
    // far end of either direction would silently accept or reject everything.
    expect(lonCutoff()->resolve($patient->id))->toBeNull()
        ->and(lonCutoff()->resolveAsDateString($patient->id))->toBeNull()
        ->and(lonRules()->snapshotCutoff($patient))->toBeNull();
});

it('retires the "patient has no native odontogram" refusal from the rule set entirely', function () {
    // Superseding a rule means the code can no longer produce it, not merely
    // that a default was flipped somewhere a future edit could flip back.
    expect(LegacyOdontogramDateRuleService::CODES)
        ->not->toContain('PATIENT_HAS_NO_NATIVE_ODONTOGRAM')
        ->and(defined(LegacyOdontogramDateRuleService::class.'::CODE_PATIENT_HAS_NO_NATIVE_ODONTOGRAM'))->toBeFalse()
        ->and(config()->has('legacy_odontogram.dates.require_native_odontogram_reference'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| THE HALF THAT SURVIVES — a meaningful native still bounds the archive.
|--------------------------------------------------------------------------
*/

it('still accepts a date strictly before the earliest meaningful native odontogram', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2025-06-10');

    $result = lonRules()->evaluate($patient, '2024-01-20');

    expect($result->passed)->toBeTrue()
        ->and($result->context['earliest_native_odontogram_date'])->toBe('2025-06-10');
});

it('still REFUSES a date after the earliest meaningful native odontogram', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2025-06-10');

    $result = lonRules()->evaluate($patient, '2025-08-20');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('still REFUSES a date EQUAL to the earliest native odontogram — the same-date policy is unchanged', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2025-06-10');

    // SAME_DATE_POLICY = REJECT. Equal is the overlap case: a chart dated the
    // day of a real examination is either that examination or contradicts it.
    $result = lonRules()->evaluate($patient, '2025-06-10');

    expect($result->failed())->toBeTrue()
        ->and($result->code)->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('bounds on the EARLIEST meaningful native, never the latest', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2026-08-01');
    lodoNativeOdontogram($patient, '2025-09-01');
    lodoNativeOdontogram($patient, '2026-01-01');

    expect(lonCutoff()->resolve($patient->id)?->toDateString())->toBe('2025-09-01');

    // Before the earliest — accepted.
    expect(lonRules()->evaluate($patient, '2025-08-31')->passed)->toBeTrue();

    // Between the earliest and the latest — refused. Bounding on the LATEST
    // would have accepted this and interleaved the archive with a real chart.
    expect(lonRules()->evaluate($patient, '2025-12-01')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);
});

it('skips a placeholder that predates the earliest MEANINGFUL native when drawing the bound', function () {
    $patient = lodoPatient();
    lodoEmptyNativeOdontogram($patient, '2024-01-01');
    lodoNativeOdontogram($patient, '2025-01-01');

    expect(lonCutoff()->resolve($patient->id)?->toDateString())->toBe('2025-01-01');

    // Valid against the meaningful native, and it would have been refused had
    // the contentless 2024-01-01 row been allowed to bound.
    expect(lonRules()->evaluate($patient, '2024-06-01')->passed)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| THE OTHER RULES STILL RUN WHEN THERE IS NO NATIVE.
|--------------------------------------------------------------------------
|
| Removing the gate must not turn "no native" into a bypass for everything
| else — that is the failure mode of deleting a rule that ran first.
*/

it('still refuses TODAY and the future for a patient with no native odontogram', function () {
    $patient = lodoPatient();
    $today = app(ClinicalClock::class)->today();

    expect(lonRules()->evaluate($patient, $today->toDateString())->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_IN_FUTURE)
        ->and(lonRules()->evaluate($patient, $today->addDay()->toDateString())->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_IN_FUTURE);
});

it('still refuses a date before the patient was born when there is no native odontogram', function () {
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);

    expect(lonRules()->evaluate($patient, '1989-12-31')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_BEFORE_PATIENT_BIRTH)
        // Equal to the birth date remains ACCEPTED.
        ->and(lonRules()->evaluate($patient, '1990-01-01')->passed)->toBeTrue();
});

it('still refuses an unparseable date when there is no native odontogram', function () {
    expect(lonRules()->evaluate(lodoPatient(), 'not-a-date')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_INVALID);
});

/*
|--------------------------------------------------------------------------
| NO NATIVE FOUND != THE QUERY FAILED.
|--------------------------------------------------------------------------
*/

it('lets a native-reference lookup FAILURE propagate instead of reading it as "no native"', function () {
    $patient = lodoPatient();

    app()->instance(LegacyOdontogramNativeReferenceRepositoryInterface::class, new class implements LegacyOdontogramNativeReferenceRepositoryInterface
    {
        public function earliestVisitWithOdontogramForPatient(int $patientId): ?ClinicVisit
        {
            throw new RuntimeException('native reference lookup exploded');
        }
    });

    // The dangerous outcome is a PASS: a broken lookup must never be mistaken
    // for a patient with a clean slate, because that files a chart with no
    // chronological bound at all.
    expect(fn () => lonRules()->evaluate($patient, '2015-01-01'))
        ->toThrow(RuntimeException::class);

    expect(fn () => lonCutoff()->resolve($patient->id))
        ->toThrow(RuntimeException::class);
});

/*
|--------------------------------------------------------------------------
| END TO END — the workflow really files it, and creates nothing native.
|--------------------------------------------------------------------------
*/

it('stages a real upload for a patient with no native odontogram, snapshotting a NULL cutoff', function () {
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);

    $import = lodoStageImport($patient, '2015-05-15', lodoOperator());

    expect($import->patient_id)->toBe($patient->id)
        ->and($import->selected_odontogram_date->toDateString())->toBe('2015-05-15')
        ->and($import->earliest_native_odontogram_date_snapshot)->toBeNull()
        ->and($import->status)->toBe(LegacyOdontogramImportStatus::QUEUED);
});

it('publishes an archive for a no-native patient and creates NOTHING native anywhere', function () {
    $import = lonReadyImportWithoutNative('2015-05-15');
    $actor = lodoOperator();

    $before = lonNativeCounts();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    expect($record->status)->toBe(LegacyOdontogramRecordStatus::PUBLISHED)
        ->and($record->odontogram_date->toDateString())->toBe('2015-05-15');

    // No ClinicVisit, no native Odontogram, no RME, no invoice, no payment, no
    // LabOrder, no SATUSEHAT candidate, and no duplicated patient.
    expect(lonNativeCounts())->toBe($before);

    // Emphatically: the archive did not fabricate a native odontogram to give
    // itself the reference it used to demand.
    expect(Odontogram::count())->toBe(0)
        ->and(ClinicVisit::count())->toBe(0)
        ->and(lonCutoff()->resolve((int) $import->patient_id))->toBeNull();
});

it('leaves the patient master untouched', function () {
    // Scalars only: `date_of_birth` casts to a Carbon INSTANCE, and comparing
    // instances would assert object identity rather than the stored value.
    $identity = fn (Patient $p): array => [
        'id' => $p->id,
        'medical_record_number' => $p->medical_record_number,
        'branch_id' => $p->branch_id,
        'name' => $p->name,
        'date_of_birth' => $p->date_of_birth?->toDateString(),
        'deleted_at' => $p->deleted_at?->toDateTimeString(),
    ];

    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    $before = $identity($patient);

    lodoStageImport($patient, '2015-05-15', lodoOperator());

    expect(Patient::count())->toBe(1)
        ->and($identity($patient->fresh()))->toBe($before)
        // The Nomor RM in particular is the archive's branch authority; the
        // import must read it, never rewrite it.
        ->and($before['medical_record_number'])->not->toBeNull();
});

it('still refuses a no-native patient once the branch has spent its daily import quota', function () {
    /*
     * Widening eligibility makes the quota the remaining volume control for
     * exactly the patients this revision newly admits, so "a no-native patient
     * counts the same as any other" has to be a fact rather than an intention.
     *
     * The hub's own suite exercises the quota SERVICE directly; nothing pinned
     * that the odontogram intake actually honours its refusal. Found by mutation
     * M16 — nulling the intake's quota preview passed every test in both the
     * LegacyOdontogram and LegacyImportHub directories.
     */
    config()->set('legacy_import_hub.daily_limit.legacy_odontogram', 1);

    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    $actor = lodoOperator();

    lodoStageImport($patient, '2015-05-15', $actor);

    expect(fn () => lodoStageImport($patient, '2016-06-16', $actor))
        ->toThrow(ValidationException::class);

    expect(LegacyOdontogramImport::where('patient_id', $patient->id)->count())->toBe(1);
});

it('files several historical charts for the same no-native patient', function () {
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    $actor = lodoOperator();

    // Distinct documents, distinct clinical dates — three real paper charts.
    lodoStageImport($patient, '2021-03-01', $actor);
    lodoStageImport($patient, '2022-04-01', $actor);
    lodoStageImport($patient, '2023-05-01', $actor);

    expect(LegacyOdontogramImport::where('patient_id', $patient->id)->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| A NATIVE ODONTOGRAM MAY ARRIVE LATER — and the archive survives it.
|--------------------------------------------------------------------------
*/

it('keeps a published archive intact, read-only and unconverted when a native odontogram is charted later', function () {
    $import = lonReadyImportWithoutNative('2015-05-15');
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    $snapshot = $record->only(['id', 'patient_id', 'branch_id', 'status', 'page_count', 'source_pdf_sha256']);
    $publishedDate = $record->odontogram_date->toDateString();

    // The patient is examined for real, two years later.
    $patient = Patient::findOrFail($import->patient_id);
    lodoNativeOdontogram($patient, '2026-08-31');

    $record->refresh();

    expect($record->only(['id', 'patient_id', 'branch_id', 'status', 'page_count', 'source_pdf_sha256']))->toBe($snapshot)
        ->and($record->odontogram_date->toDateString())->toBe($publishedDate)
        ->and($record->status)->toBe(LegacyOdontogramRecordStatus::PUBLISHED)
        // Not converted, not deleted, not absorbed — they coexist.
        ->and(LegacyOdontogramRecord::count())->toBe(1)
        ->and(Odontogram::count())->toBe(1)
        ->and(lonCutoff()->resolve($patient->id)?->toDateString())->toBe('2026-08-31');
});

it('applies the newly-arrived native as a bound to the NEXT archive, without disturbing the first', function () {
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    $actor = lodoOperator();

    // Filed while the patient had no native odontogram.
    lodoStageImport($patient, '2015-05-15', $actor);

    lodoNativeOdontogram($patient, '2026-01-01');

    // A chart discovered later, predating the native — still historical.
    expect(lonRules()->evaluate($patient, '2024-05-01')->passed)->toBeTrue();

    // A chart dated after the native — refused, exactly as for any other patient.
    expect(lonRules()->evaluate($patient, '2026-03-01')->code)
        ->toBe(LegacyOdontogramDateRuleService::CODE_LEGACY_DATE_NOT_BEFORE_NATIVE_ODONTOGRAM);

    // The already-staged 2015 chart is untouched by the new native.
    expect(LegacyOdontogramImport::where('patient_id', $patient->id)->count())->toBe(1);
});

it('re-refuses at PUBLISH when a native odontogram lands under a staged archive', function () {
    $import = lonReadyImportWithoutNative('2026-03-01');
    $actor = lodoOperator();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);

    // Charted for real BEFORE the staged archive's date — the staged document
    // is no longer historical relative to this patient's native history.
    lodoNativeOdontogram(Patient::findOrFail($import->patient_id), '2026-01-01');

    expect(fn () => app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor))
        ->toThrow(ValidationException::class);

    expect(LegacyOdontogramRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| THE HTTP BOUNDARY — and the branch authority it must not hand over.
|--------------------------------------------------------------------------
*/

it('lets an operator upload through the real form for a patient with no native odontogram', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));

    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);

    $this->actingAs(lodoOperator())
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patient->id,
            'selected_odontogram_date' => '2015-05-15',
            'document' => lodoPdfUpload('odontogram.pdf', 2),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(LegacyOdontogramImport::query()->firstOrFail()->patient_id)->toBe($patient->id)
        ->and(ClinicVisit::count())->toBe(0)
        ->and(Odontogram::count())->toBe(0);
});

it('shows the no-native patient as archivable on the upload screen', function () {
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);

    $this->actingAs(lodoOperator())
        ->get(route('settings.rme.legacy-odontograms.create', ['patient_id' => $patient->id]))
        ->assertOk()
        // The screen must no longer tell the operator the archive is blocked.
        ->assertDontSee('belum dapat diarsipkan');
});

it('resolves the patient at SUBMIT through the actor-scoped repository, not a bare model lookup', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(1));

    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);

    /*
     * The repository is the module's ONLY door to `mst_patients`, and it is
     * where the actor's patient scope is applied
     * (DoctorPatientScopeService, via baseQuery). Widening eligibility makes
     * this boundary matter more, not less: more patients are now archivable,
     * so "who may archive against whom" has to keep being decided here.
     *
     * This stub refuses every id. If `store()` reached past the interface to a
     * bare `Patient::find()`, the stub would simply be ignored and the upload
     * would succeed — which is exactly the regression this pins.
     */
    app()->instance(LegacyOdontogramPatientRepositoryInterface::class, new class implements LegacyOdontogramPatientRepositoryInterface
    {
        public function findSelectableById(?User $actor, int $patientId): ?Patient
        {
            return null;
        }

        public function searchByMedicalRecordNumber(?User $actor, string $medicalRecordNumber, int $limit): Collection
        {
            return collect();
        }
    });

    $this->actingAs(lodoOperator())
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patient->id,
            'selected_odontogram_date' => '2015-05-15',
            'document' => lodoPdfUpload('odontogram.pdf', 1),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
        ])
        ->assertNotFound();

    expect(LegacyOdontogramImport::count())->toBe(0);
});

it('ignores a submitted branch_id and keeps the branch derived from the Nomor RM', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(1));

    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    $otherBranch = lodoBranch('LDK2', 'Cabang Landak');

    $this->actingAs(lodoOperator())
        ->post(route('settings.rme.legacy-odontograms.store'), [
            'patient_id' => $patient->id,
            'selected_odontogram_date' => '2015-05-15',
            'document' => lodoPdfUpload('odontogram.pdf', 1),
            'patient_confirmation' => '1',
            'date_confirmation' => '1',
            // Not a field the request reads — asserted, not assumed.
            'branch_id' => $otherBranch->id,
            'origin_branch_id' => $otherBranch->id,
        ])
        ->assertRedirect();

    expect(LegacyOdontogramImport::query()->firstOrFail()->origin_branch_id)
        ->toBe($patient->branch_id)
        ->not->toBe($otherBranch->id);
});
