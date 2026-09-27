<?php

/**
 * FIX-04b — the PUBLISHED integration point.
 *
 * LegacyOdontogramPatientHistoryService::publishedRecordsFor() is what the
 * odontogram history screen calls, so its signature, its return shape and its
 * refusal behaviour are a CONTRACT, not an implementation detail. These tests
 * pin all three.
 */

use App\Models\User;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramVoidService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\Patient\Models\Patient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

function lodoPublishFor(Patient $patient, string $date): LegacyOdontogramRecord
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));

    $actor = lodoOperator();
    $import = lodoStageImport($patient, $date, $actor, 2);

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());
    $import->refresh();

    app(LegacyOdontogramPublishService::class)->review($import, $actor);

    return app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);
}

/**
 * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — an ADDITIONAL published
 * record for a patient who already has one, built directly rather than through
 * intake.
 *
 * Deliberate, and the reason is the point of this file. These tests pin the
 * READER contract of `publishedRecordsFor()`: its ordering, its eager loading and
 * what it excludes. The reader is NOT where the one-document-per-patient
 * invariant is enforced — the intake service is — and the reader must keep
 * returning correct, correctly-ordered, eager-loaded results for whatever rows
 * the table actually holds.
 *
 * Driving a SECOND concurrently-published record for one patient through the
 * guarded intake is now impossible by design, so these rows are created directly.
 * Testing a reader in isolation from the writer's admission rules is the honest
 * way to keep that contract pinned; asserting the reader only against
 * intake-reachable states would silently stop testing the ordering it exists for.
 */
function lodoPublishedRecordRow(Patient $patient, string $date, int $pages = 2): LegacyOdontogramRecord
{
    return LegacyOdontogramRecord::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => lodoBranch()->id,
        'source_branch_code' => 'TLK1',
        'odontogram_date' => $date,
        'page_count' => $pages,
        'source_pdf_sha256' => hash('sha256', $patient->id.'|'.$date),
    ]);
}

it('exposes exactly the signature the caller depends on', function () {
    $method = new ReflectionMethod(LegacyOdontogramPatientHistoryService::class, 'publishedRecordsFor');
    $parameters = $method->getParameters();

    expect($method->isPublic())->toBeTrue()
        ->and($parameters)->toHaveCount(2)
        ->and($parameters[0]->getName())->toBe('user')
        ->and((string) $parameters[0]->getType())->toBe(User::class)
        ->and($parameters[1]->getName())->toBe('patientId')
        ->and((string) $parameters[1]->getType())->toBe('int')
        ->and((string) $method->getReturnType())->toBe(Collection::class);
});

it('returns published records oldest clinical date first with the fields the caller needs', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    // The first goes through the real pipeline; the second is a direct row (see
    // lodoPublishedRecordRow) because a patient can no longer hold two concurrent
    // published archives via intake. Inserted out of date order on purpose — the
    // ordering under test must come from the query, not from insertion order.
    lodoPublishFor($patient, '2020-05-01');
    lodoPublishedRecordRow($patient, '2018-02-03');

    $records = app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor(lodoOperator(), (int) $patient->id);

    expect($records)->toHaveCount(2)
        ->and($records->first()->odontogram_date->toDateString())->toBe('2018-02-03')
        ->and($records->last()->odontogram_date->toDateString())->toBe('2020-05-01');

    $first = $records->first();

    expect($first->getKey())->toBeInt()
        ->and($first->odontogram_date)->toBeInstanceOf(Carbon::class)
        ->and($first->page_count)->toBe(2)
        ->and($first->branch)->not->toBeNull()
        ->and($first->branch->code)->toBe('TLK1')
        ->and(route('rme.legacy-odontograms.show', $first->getKey()))->toBeString();
});

it('eager-loads the branch so rendering a list is not N+1', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    // Three published records for one patient: one real, two direct rows. N+1 is
    // only observable across several rows, so the reader's eager loading cannot be
    // pinned from a single-record fixture.
    lodoPublishFor($patient, '2018-02-03');
    lodoPublishedRecordRow($patient, '2019-02-03');
    lodoPublishedRecordRow($patient, '2020-02-03');

    $records = app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor(lodoOperator(), (int) $patient->id);

    expect($records)->toHaveCount(3);

    DB::enableQueryLog();
    DB::flushQueryLog();

    foreach ($records as $record) {
        expect($record->branch?->name)->not->toBeNull();
    }

    expect(DB::getQueryLog())->toHaveCount(0);

    DB::disableQueryLog();
});

it('excludes staged work in progress and VOIDed records', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $voided = lodoPublishFor($patient, '2018-02-03');
    app(LegacyOdontogramVoidService::class)->void(
        $voided,
        'Dokumen ternyata milik pasien lain, ditarik dan akan diunggah ulang.',
        lodoOperator(),
    );

    // A staged, un-published import for the same patient.
    //
    // Reachable ONLY because the void above released the patient's slot — which is
    // itself the correction path working. Under
    // REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 this staging row could
    // not coexist with a live published archive, so the "kept" record below is a
    // direct row; the reader must still exclude both the VOID record and every
    // staging row regardless of how they came to be.
    $staged = lodoStageImport($patient, '2019-01-01', lodoOperator());

    expect($staged->status)->toBe(LegacyOdontogramImportStatus::QUEUED);

    $kept = lodoPublishedRecordRow($patient, '2020-05-01');

    $records = app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor(lodoOperator(), (int) $patient->id);

    expect($records)->toHaveCount(1)
        ->and($records->first()->getKey())->toBe($kept->getKey());
});

it('returns an EMPTY collection and never throws for a user with no read permission', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');
    lodoPublishFor($patient, '2018-02-03');

    $stranger = userWith(['view_clinic_visits']);

    $records = app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor($stranger, (int) $patient->id);

    expect($records)->toBeInstanceOf(Collection::class)
        ->and($records)->toHaveCount(0);
});

it('returns an EMPTY collection for a patient that does not exist', function () {
    expect(app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor(lodoOperator(), 999_999))
        ->toHaveCount(0);
});

it('returns an EMPTY collection for a patient in another branch', function () {
    $theirPatient = lodoPatient([], 'LDK2');
    lodoNativeOdontogram($theirPatient, '2022-03-10');
    lodoPublishFor($theirPatient, '2018-02-03');

    $reader = userWith(['view_legacy_odontogram_archive']);
    $reader->forceFill(['branch_id' => lodoBranch('TLK1')->id])->save();

    expect(app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor($reader->refresh(), (int) $theirPatient->id))
        ->toHaveCount(0);
});

it('is NOT gated by the migration feature flag', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');
    lodoPublishFor($patient, '2018-02-03');

    lodoFlag(false);

    expect(app(LegacyOdontogramPatientHistoryService::class)
        ->publishedRecordsFor(lodoOperator(), (int) $patient->id))
        ->toHaveCount(1);
});

it('answers hasLegacyOdontograms consistently with the listing', function () {
    $patient = lodoPatient();
    lodoNativeOdontogram($patient, '2022-03-10');

    $service = app(LegacyOdontogramPatientHistoryService::class);
    $operator = lodoOperator();

    expect($service->hasLegacyOdontograms($operator, (int) $patient->id))->toBeFalse();

    lodoPublishFor($patient, '2018-02-03');

    expect($service->hasLegacyOdontograms($operator, (int) $patient->id))->toBeTrue();
});
