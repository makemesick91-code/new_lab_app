<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1
|--------------------------------------------------------------------------
|
| ONE PATIENT, ONE DOCUMENT TYPE, ONE ACTIVE LIFECYCLE.
|
| The invariant this suite pins:
|
|   - a patient may hold at most ONE active / non-VOID legacy RME lifecycle;
|   - a patient may hold at most ONE active / non-VOID legacy ODONTOGRAM
|     lifecycle;
|   - the two slots are INDEPENDENT — neither ever blocks the other.
|
| What occupies a slot, and what releases it:
|
|   PUBLISHED archive record .................. OCCUPIED
|   VOID archive record ....................... RELEASED  (the correction path)
|   staging row in a SLOT_OCCUPYING state ..... OCCUPIED  (FAILED included —
|                                                          it is retryable)
|   CANCELLED staging row ..................... RELEASED  (terminal, no archive)
|   PUBLISHED staging row + VOID record ....... RELEASED  (the subtle one)
|
| That last line is the case that would permanently break void-then-reimport if
| it were wrong: a staging row keeps its historical PUBLISHED status forever, so
| if the staging row alone held the slot, a voided archive could never be
| replaced.
|
| RETRY IS NOT A NEW UPLOAD, and this suite proves that separately. Retrying an
| existing import goes through `queue($import, …, isRetry: true)`; only
| `createFromUpload()` creates a new lifecycle, and only it is guarded.
|
| CONCURRENCY IS NOT PROVEN HERE. The race is closed by a PostgreSQL advisory
| lock, which SQLite cannot take; the two-session proof lives in
| LegacySingleActiveDocumentConcurrencyTest and SKIPS off PostgreSQL rather than
| pretending to pass.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyImport\Exceptions\LegacyDocumentSlotLockUnavailable;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyDocumentSlotLock;
use App\Modules\LegacyImport\Support\LegacyDocumentSlotOccupancy;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramVoidService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmeImportProcessingService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Services\LegacyRmeVoidService;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\Patient\Models\Patient;
use App\Support\Legacy\LegacyVisitBindingService;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

/**
 * A legacy RME PDF whose bytes are DISTINCT on every call.
 *
 * `legacyRmePdfUpload()` returns identical bytes for identical page counts, and
 * the archive refuses a checksum it has already seen. A slot test that reused
 * bytes would be measuring the duplicate guard instead of the slot guard, so the
 * MediaBox width is nudged by a fraction of a point per call: structurally
 * valid, visually identical, different checksum.
 */
function lsadPdf(int $pages = 1): File
{
    static $variant = 0;
    $variant++;

    return UploadedFile::fake()->createWithContent(
        'arsip.pdf',
        legacyRmePdfBytes($pages, 595.276 + ($variant / 1000)),
    );
}

/** A patient who can legitimately receive BOTH a legacy RME and a legacy chart. */
function lsadPatient(): Patient
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    // Native references for each date domain. Both archives compare the
    // operator-declared date against the patient's earliest NATIVE encounter of
    // their own kind, so each needs its own.
    legacyRmeNativeVisit($patient, '2022-03-10');
    lodoNativeOdontogram($patient, '2022-03-10');

    return $patient;
}

/** Stage a legacy RME import through the real intake service. */
function lsadStageRme(Patient $patient, string $date = '2020-05-01'): LegacyRmeImport
{
    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $date,
        $patient->medical_record_number,
        null,
        lsadPdf(),
        superAdmin(),
    );
}

function lsadSlots(): LegacySingleActiveDocumentService
{
    return app(LegacySingleActiveDocumentService::class);
}

/** Force a staged RME import into a given lifecycle state. */
function lsadRmeStatus(LegacyRmeImport $import, string $status): LegacyRmeImport
{
    $import->forceFill(['status' => $status])->save();

    return $import->refresh();
}

/** A fully rendered, reviewed and PUBLISHED legacy RME archive. */
function lsadPublishedRmeRecord(Patient $patient): LegacyRmeRecord
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));

    $import = lsadStageRme($patient);

    app(LegacyRmeImportProcessingService::class)->process((int) $import->getKey());

    $actor = superAdmin();
    app(LegacyRmePublishService::class)->review($import->refresh(), $actor);

    return app(LegacyRmePublishService::class)->publish($import->refresh(), [], $actor);
}

/*
|--------------------------------------------------------------------------
| 1. The vocabulary: what occupies a slot is DERIVED, never hand-listed
|--------------------------------------------------------------------------
*/

it('defines the RME slot-occupying states as exactly the non-terminal ones', function () {
    // If these ever drift apart, a newly added staging state would silently
    // stop occupying the slot and a patient could hold two lifecycles.
    expect(LegacyRmeImportStatus::SLOT_OCCUPYING)
        ->toEqualCanonicalizing(array_values(array_diff(
            LegacyRmeImportStatus::ALL,
            LegacyRmeImportStatus::TERMINAL,
        )));
});

it('defines the odontogram slot-occupying states as exactly the non-terminal ones', function () {
    expect(LegacyOdontogramImportStatus::SLOT_OCCUPYING)
        ->toEqualCanonicalizing(array_values(array_diff(
            LegacyOdontogramImportStatus::ALL,
            LegacyOdontogramImportStatus::TERMINAL,
        )));
});

it('treats FAILED as occupying, because FAILED can still be retried', function () {
    // The reason this matters: FAILED -> QUEUED is a legal transition, so a
    // failed import is not abandoned. Allowing a second upload beside it would
    // create two competing lifecycles for one patient.
    expect(LegacyRmeImportStatus::TRANSITIONS[LegacyRmeImportStatus::FAILED])
        ->toContain(LegacyRmeImportStatus::QUEUED)
        ->and(LegacyRmeImportStatus::SLOT_OCCUPYING)->toContain(LegacyRmeImportStatus::FAILED)
        ->and(LegacyOdontogramImportStatus::TRANSITIONS[LegacyOdontogramImportStatus::FAILED])
        ->toContain(LegacyOdontogramImportStatus::QUEUED)
        ->and(LegacyOdontogramImportStatus::SLOT_OCCUPYING)->toContain(LegacyOdontogramImportStatus::FAILED);
});

/*
|--------------------------------------------------------------------------
| 2. The lock key
|--------------------------------------------------------------------------
*/

it('gives each document type its own advisory lock namespace', function () {
    [$rmeClass, $rmeObject] = LegacyDocumentSlotLock::keyFor(LegacyImportType::LEGACY_RME, 77);
    [$odoClass, $odoObject] = LegacyDocumentSlotLock::keyFor(LegacyImportType::LEGACY_ODONTOGRAM, 77);

    // Same patient, different namespace: this is what makes the two slots
    // independent at the lock level rather than only in the query.
    expect($rmeClass)->not->toBe($odoClass)
        ->and($rmeObject)->toBe(77)
        ->and($odoObject)->toBe(77);
});

it('accepts the largest addressable patient id rather than rejecting the boundary', function () {
    // The companion of the refusal test below, and the reason it exists: mutation
    // testing showed `$patientId > MAX_OBJID` could be weakened to `>=` without a
    // single test noticing, because nothing asserted that the boundary value
    // ITSELF is usable. Rejecting a valid id fails closed rather than dangerously,
    // but it would still lock a real patient out of the archive.
    [$classId, $objectId] = LegacyDocumentSlotLock::keyFor(
        LegacyImportType::LEGACY_RME,
        LegacyDocumentSlotLock::MAX_OBJID,
    );

    expect($objectId)->toBe(LegacyDocumentSlotLock::MAX_OBJID)
        ->and($classId)->toBe(LegacyDocumentSlotLock::NAMESPACES[LegacyImportType::LEGACY_RME]);
});

it('refuses a lock key it cannot address rather than wrapping silently', function () {
    expect(fn () => LegacyDocumentSlotLock::keyFor('legacy_patient', 1))
        ->toThrow(LegacyDocumentSlotLockUnavailable::class)
        ->and(fn () => LegacyDocumentSlotLock::keyFor(LegacyImportType::LEGACY_RME, 0))
        ->toThrow(LegacyDocumentSlotLockUnavailable::class)
        ->and(fn () => LegacyDocumentSlotLock::keyFor(
            LegacyImportType::LEGACY_RME,
            LegacyDocumentSlotLock::MAX_OBJID + 1,
        ))->toThrow(LegacyDocumentSlotLockUnavailable::class);
});

it('refuses to assert the slot outside a transaction', function () {
    /*
     * A transaction-scoped advisory lock taken OUTSIDE a transaction is released
     * the instant it is acquired, so an unguarded call would look like it worked
     * while guarding nothing. The guard must therefore fail loudly.
     *
     * Proving it needs a connection with no open transaction, and the default one
     * never qualifies here: RefreshDatabase wraps every Feature test in a
     * transaction it never commits, so `DB::transactionLevel()` is already >= 1
     * before the test body runs. A second connection — which nobody has begun a
     * transaction on — is where the production condition actually exists.
     *
     * Nothing is queried, because the guard clause runs before any statement; the
     * connection only has to exist.
     */
    $original = config('database.default');

    config()->set('database.connections.lsad_no_transaction', config('database.connections.'.$original));
    DB::purge('lsad_no_transaction');

    try {
        config()->set('database.default', 'lsad_no_transaction');

        expect(DB::transactionLevel())->toBe(0);

        expect(fn () => lsadSlots()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, 1))
            ->toThrow(LegacyDocumentSlotLockUnavailable::class);
    } finally {
        // Restored before teardown, or RefreshDatabase would try to roll back a
        // connection that never opened a transaction.
        config()->set('database.default', $original);
        DB::purge('lsad_no_transaction');
    }
});

/*
|--------------------------------------------------------------------------
| 3. Legacy RME — the slot
|--------------------------------------------------------------------------
*/

it('lets a patient with no legacy RME begin one', function () {
    $patient = lsadPatient();

    $occupancy = lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey());

    expect($occupancy->occupied)->toBeFalse()
        ->and($occupancy->reason)->toBeNull()
        ->and($occupancy->code())->toBeNull();

    $import = lsadStageRme($patient);

    expect($import->status)->toBe(LegacyRmeImportStatus::QUEUED)
        ->and(LegacyRmeImport::count())->toBe(1);
});

it('refuses a second NEW legacy RME while one is still in flight', function (string $status) {
    $patient = lsadPatient();
    lsadRmeStatus(lsadStageRme($patient), $status);

    expect(fn () => lsadStageRme($patient, '2020-07-01'))
        ->toThrow(ValidationException::class);

    // The refused upload created nothing: no second staging row and no orphan
    // bytes on the private disk.
    expect(LegacyRmeImport::count())->toBe(1);
})->with(LegacyRmeImportStatus::SLOT_OCCUPYING);

it('reports WHY the RME slot is held, with a code a caller can branch on', function () {
    $patient = lsadPatient();
    $first = lsadStageRme($patient);

    $occupancy = lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey());

    expect($occupancy->occupied)->toBeTrue()
        ->and($occupancy->reason)->toBe(LegacyDocumentSlotOccupancy::REASON_ACTIVE_IMPORT_EXISTS)
        ->and($occupancy->code())->toBe('LEGACY_RME_ACTIVE_IMPORT_EXISTS')
        ->and($occupancy->blockingImportId)->toBe((int) $first->getKey())
        // The in-flight message must NOT tell the operator to VOID: VOID is not
        // a legal transition from any staging state, and advising an action the
        // server will refuse is how a refusal becomes a support ticket.
        ->and($occupancy->message())->not->toContain('VOID')
        ->and($occupancy->message())->toContain('Selesaikan atau batalkan');
});

it('carries the full structure-only payload for each kind of refusal', function () {
    /*
     * The audit payload's SHAPE is the contract, not an implementation detail: an
     * operator who is refused is helped from this trail, and a missing key means a
     * support request that cannot be answered.
     *
     * Mutation testing found every one of these guards could be negated — and
     * `patient_id` removed outright — without a test noticing, because the earlier
     * assertions only looked for the reason code and the blocking id. These pin the
     * whole shape, and pin that it stays PII-free.
     */
    $patient = lsadPatient();
    $import = lsadStageRme($patient);

    $active = lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey());
    $activeContext = $active->auditContext();

    expect($activeContext)->toHaveKeys(['patient_id', 'slot_reason', 'blocking_import_id', 'blocking_status'])
        ->and($activeContext['patient_id'])->toBe((int) $patient->getKey())
        ->and($activeContext['slot_reason'])->toBe(LegacyDocumentSlotOccupancy::REASON_ACTIVE_IMPORT_EXISTS)
        ->and($activeContext['blocking_import_id'])->toBe((int) $import->getKey())
        ->and($activeContext['blocking_status'])->toBe(LegacyRmeImportStatus::QUEUED)
        // An in-flight lifecycle has produced no archive, so there is no record id.
        ->and($activeContext)->not->toHaveKey('blocking_record_id');

    $record = lsadPublishedRmeRecord(lsadPatient());
    $published = lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $record->patient_id);
    $publishedContext = $published->auditContext();

    expect($publishedContext)->toHaveKeys(['patient_id', 'slot_reason', 'blocking_record_id', 'blocking_status'])
        ->and($publishedContext['slot_reason'])->toBe(LegacyDocumentSlotOccupancy::REASON_ALREADY_PUBLISHED)
        ->and($publishedContext['blocking_record_id'])->toBe((int) $record->getKey())
        ->and($publishedContext['blocking_status'])->toBe('PUBLISHED')
        // Populated from the record's own source import, so the trail can reach the
        // staging row that produced the archive.
        ->and($publishedContext['blocking_import_id'])->toBe((int) $record->source_import_id);

    // Structure only, in both directions.
    $payload = json_encode($activeContext + $publishedContext);

    expect($payload)->not->toContain($patient->name)
        ->and($payload)->not->toContain($patient->medical_record_number);
});

it('reports an available slot as null from the advisory preview and the occupancy as itself', function () {
    // The advisory layer is not the gate, but it is what spares an operator a
    // pointless upload — and mutation testing showed it could be made to always
    // return null (never warning anyone) while every behavioural test still passed,
    // because the authoritative assertion inside the transaction still refused.
    // Layered defences hide each other's removal unless each layer is pinned.
    $free = lsadPatient();

    expect(lsadSlots()->previewForNewLifecycle(LegacyImportType::LEGACY_RME, (int) $free->getKey()))
        ->toBeNull();

    $taken = lsadPatient();
    lsadStageRme($taken);

    $preview = lsadSlots()->previewForNewLifecycle(LegacyImportType::LEGACY_RME, (int) $taken->getKey());

    expect($preview)->not->toBeNull()
        ->and($preview->occupied)->toBeTrue()
        ->and($preview->reason)->toBe(LegacyDocumentSlotOccupancy::REASON_ACTIVE_IMPORT_EXISTS);
});

it('releases the RME slot when the staging row is cancelled', function () {
    $patient = lsadPatient();
    lsadRmeStatus(lsadStageRme($patient), LegacyRmeImportStatus::CANCELLED);

    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey())->occupied)
        ->toBeFalse();

    $second = lsadStageRme($patient, '2020-07-01');

    expect($second->exists)->toBeTrue()
        ->and(LegacyRmeImport::count())->toBe(2);
});

it('refuses a NEW legacy RME once the patient has a PUBLISHED archive', function () {
    $patient = lsadPatient();
    $record = lsadPublishedRmeRecord($patient);

    $occupancy = lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey());

    expect($occupancy->occupied)->toBeTrue()
        ->and($occupancy->reason)->toBe(LegacyDocumentSlotOccupancy::REASON_ALREADY_PUBLISHED)
        ->and($occupancy->code())->toBe('LEGACY_RME_ALREADY_PUBLISHED')
        ->and($occupancy->blockingRecordId)->toBe((int) $record->getKey())
        // Published IS correctable — the message must name the route out.
        ->and($occupancy->message())->toContain('VOID');

    expect(fn () => lsadStageRme($patient, '2020-07-01'))->toThrow(ValidationException::class);

    expect(LegacyRmeRecord::count())->toBe(1);
});

it('releases the RME slot when the published archive is voided, even though the staging row stays PUBLISHED', function () {
    $patient = lsadPatient();
    $record = lsadPublishedRmeRecord($patient);

    app(LegacyRmeVoidService::class)->void(
        $record,
        'Dokumen yang dipublikasikan milik pasien lain, ditarik untuk diperbaiki.',
        superAdmin(),
    );

    // THE SUBTLE CASE. The staging row is still PUBLISHED forever — that is
    // history and must not be rewritten — so occupancy has to be decided by the
    // RECORD. If it were decided by the staging row, void-then-reimport would be
    // permanently dead.
    $stagingRow = LegacyRmeImport::query()->findOrFail($record->source_import_id);
    expect($stagingRow->status)->toBe(LegacyRmeImportStatus::PUBLISHED);

    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey())->occupied)
        ->toBeFalse();

    // And the correction import really goes through.
    $fresh = lsadStageRme($patient, '2020-07-01');

    expect($fresh->exists)->toBeTrue()
        // Nothing was deleted: the voided archive is still there as evidence.
        ->and(LegacyRmeRecord::count())->toBe(1);
});

it('does not treat a retry of the SAME import as a new upload', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));

    $patient = lsadPatient();
    $import = lsadRmeStatus(lsadStageRme($patient), LegacyRmeImportStatus::FAILED);

    // The slot is held — by this very import. Retrying it must still work, or
    // the guard would have broken queue retries and worker recovery.
    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey())->occupied)
        ->toBeTrue();

    $retried = app(LegacyRmeImportProcessingService::class)->retry(
        $import,
        app(LegacyRmeImportService::class),
        superAdmin(),
    );

    expect($retried->status)->toBe(LegacyRmeImportStatus::QUEUED)
        // Still ONE lifecycle. A retry that created a second row would be the
        // bug this whole revision exists to prevent.
        ->and(LegacyRmeImport::count())->toBe(1);
});

it('keeps the RME slot occupied when a live import is soft-deleted', function () {
    $patient = lsadPatient();
    $import = lsadStageRme($patient);

    // Nothing in the application soft-deletes an import; the canonical ways to
    // release a slot are CANCEL and VOID, both audited. Counting trashed rows
    // anyway means a manual or future delete() can never become a silent,
    // unaudited bypass of a clinical invariant.
    $import->delete();

    // Invisible to every ordinary read — `fresh()` deliberately bypasses the
    // soft-delete scope, so the default-scoped query is what proves the row is
    // gone as far as the application is concerned...
    expect(LegacyRmeImport::query()->find($import->getKey()))->toBeNull()
        ->and($import->fresh()->trashed())->toBeTrue()
        // ...and yet the slot is still held.
        ->and(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey())->occupied)
        ->toBeTrue();

    expect(fn () => lsadStageRme($patient, '2020-07-01'))->toThrow(ValidationException::class);
});

it('audits a refused RME upload without leaking patient identity', function () {
    $patient = lsadPatient();
    $first = lsadStageRme($patient);

    try {
        lsadStageRme($patient, '2020-07-01');
    } catch (ValidationException) {
        // expected
    }

    $log = AuditLog::query()
        ->where('action', LegacyRmeAuditEvent::NEW_UPLOAD_BLOCKED_ACTIVE_IMPORT)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();

    $payload = json_encode($log->new_values ?? []);

    expect($payload)->toContain('ACTIVE_IMPORT_EXISTS')
        ->and($payload)->toContain((string) $first->getKey())
        ->and($payload)->not->toContain($patient->name)
        ->and($payload)->not->toContain($patient->medical_record_number);
});

it('blocks the visit-bound preverified path too', function () {
    // A real visit and a valid date attestation are authority over the DATE.
    // They are not authority over how many archives a patient may have, and the
    // preverified path must not become a way around the cardinality rule.
    $patient = lsadPatient();
    lsadStageRme($patient);

    $uploader = superAdmin();
    $uploader->forceFill(['branch_id' => legacyRmeBranch('TLK1')->id])->save();

    $visit = ClinicVisit::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => legacyRmeBranch('TLK1')->id,
        'visit_date' => '2024-06-10',
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    $attestation = app(LegacyVisitBindingService::class)->resolve(
        (int) $visit->getKey(),
        $uploader,
        dateAttested: true,
    );

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-07-01',
        $patient->medical_record_number,
        null,
        lsadPdf(),
        $uploader,
        '2020-09-01',
        $attestation,
    ))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 4. Legacy Odontogram — the same invariant, its own slot
|--------------------------------------------------------------------------
*/

it('lets a patient with no legacy odontogram begin one', function () {
    $patient = lsadPatient();

    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->getKey())->occupied)
        ->toBeFalse();

    $import = lodoStageImport($patient, '2019-06-01', lodoOperator());

    expect($import->status)->toBe(LegacyOdontogramImportStatus::QUEUED)
        ->and(LegacyOdontogramImport::count())->toBe(1);
});

it('refuses a second NEW legacy odontogram while one is still in flight', function (string $status) {
    $patient = lsadPatient();
    $import = lodoStageImport($patient, '2019-06-01', lodoOperator());
    $import->forceFill(['status' => $status])->save();

    expect(fn () => lodoStageImport($patient, '2019-07-01', lodoOperator()))
        ->toThrow(ValidationException::class);

    expect(LegacyOdontogramImport::count())->toBe(1);
})->with(LegacyOdontogramImportStatus::SLOT_OCCUPYING);

it('releases the odontogram slot when the staging row is cancelled', function () {
    $patient = lsadPatient();
    $import = lodoStageImport($patient, '2019-06-01', lodoOperator());
    $import->forceFill(['status' => LegacyOdontogramImportStatus::CANCELLED])->save();

    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->getKey())->occupied)
        ->toBeFalse();

    expect(lodoStageImport($patient, '2019-07-01', lodoOperator())->exists)->toBeTrue()
        ->and(LegacyOdontogramImport::count())->toBe(2);
});

it('refuses a NEW legacy odontogram once the patient has a PUBLISHED chart, and releases it on VOID', function () {
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));

    $patient = lsadPatient();
    $actor = lodoOperator();

    $import = lodoStageImport($patient, '2019-06-01', $actor, 2);
    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());
    app(LegacyOdontogramPublishService::class)->review($import->refresh(), $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    $occupancy = lsadSlots()->occupancyFor(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->getKey());

    expect($occupancy->occupied)->toBeTrue()
        ->and($occupancy->code())->toBe('LEGACY_ODONTOGRAM_ALREADY_PUBLISHED');

    expect(fn () => lodoStageImport($patient, '2019-07-01', $actor))
        ->toThrow(ValidationException::class);

    // VOID is the correction path here too.
    app(LegacyOdontogramVoidService::class)->void(
        $record,
        'Kartu odontogram yang dipublikasikan salah pasien, ditarik untuk diperbaiki.',
        $actor,
    );

    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->getKey())->occupied)
        ->toBeFalse()
        ->and(lodoStageImport($patient, '2019-07-01', $actor)->exists)->toBeTrue()
        // The voided chart is retained as evidence, never deleted.
        ->and(LegacyOdontogramRecord::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 5. INDEPENDENCE — the property most easily lost to a convenience flag
|--------------------------------------------------------------------------
*/

it('does not let a published legacy RME block the patient first legacy odontogram', function () {
    $patient = lsadPatient();
    lsadPublishedRmeRecord($patient);

    // RME slot taken...
    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey())->occupied)
        ->toBeTrue()
        // ...odontogram slot untouched.
        ->and(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->getKey())->occupied)
        ->toBeFalse();

    expect(lodoStageImport($patient, '2019-06-01', lodoOperator())->exists)->toBeTrue();
});

it('does not let an in-flight legacy odontogram block the patient first legacy RME', function () {
    $patient = lsadPatient();
    lodoStageImport($patient, '2019-06-01', lodoOperator());

    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_ODONTOGRAM, (int) $patient->getKey())->occupied)
        ->toBeTrue()
        ->and(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $patient->getKey())->occupied)
        ->toBeFalse();

    expect(lsadStageRme($patient)->exists)->toBeTrue();
});

it('keeps the two slots independent for the same patient at the same time', function () {
    $patient = lsadPatient();

    $rme = lsadStageRme($patient);
    $odontogram = lodoStageImport($patient, '2019-06-01', lodoOperator());

    // One of each is legal. A second of EITHER is not.
    expect(LegacyRmeImport::count())->toBe(1)
        ->and(LegacyOdontogramImport::count())->toBe(1)
        ->and($rme->exists)->toBeTrue()
        ->and($odontogram->exists)->toBeTrue();

    expect(fn () => lsadStageRme($patient, '2020-07-01'))->toThrow(ValidationException::class);
    expect(fn () => lodoStageImport($patient, '2019-07-01', lodoOperator()))->toThrow(ValidationException::class);

    expect(LegacyRmeImport::count())->toBe(1)
        ->and(LegacyOdontogramImport::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 6. NO THIRD DOOR — the guard is only worth as much as its coverage
|--------------------------------------------------------------------------
*/

it('creates a legacy lifecycle from exactly one place per document type', function () {
    /*
     * The slot guard lives inside `createFromUpload()`. That is sound only while
     * `createFromUpload()` is the ONLY thing that inserts a staging row: a future
     * path that called the repository directly would bypass the invariant
     * silently, and every behavioural test above would still pass.
     *
     * So the creator set is pinned. If this fails, the new call site must either
     * route through the guarded intake service or take the slot lock itself —
     * updating this list without doing one of those re-opens the duplicate-archive
     * hole this revision closed.
     */
    $creators = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'imports->create(')) {
            $creators[] = str_replace(app_path().'/', '', $file->getPathname());
        }
    }

    expect($creators)->toEqualCanonicalizing([
        'Modules/LegacyRme/Services/LegacyRmeImportService.php',
        'Modules/LegacyOdontogram/Services/LegacyOdontogramImportService.php',
    ]);
});

it('asserts the slot in every place that creates a lifecycle', function () {
    // Each creator must also contain the authoritative assertion. A creator that
    // only ran the advisory preview would be raceable.
    foreach ([
        'Modules/LegacyRme/Services/LegacyRmeImportService.php',
        'Modules/LegacyOdontogram/Services/LegacyOdontogramImportService.php',
    ] as $relative) {
        $source = (string) file_get_contents(app_path($relative));

        expect($source)->toContain('assertAvailableForNewLifecycle');
    }
});

it('scopes the slot to ONE patient and never to the branch or the estate', function () {
    $first = lsadPatient();
    $second = lsadPatient();

    lsadStageRme($first);

    // Same branch, same operator, different patient: entirely unaffected.
    expect(lsadSlots()->occupancyFor(LegacyImportType::LEGACY_RME, (int) $second->getKey())->occupied)
        ->toBeFalse()
        ->and(lsadStageRme($second)->exists)->toBeTrue()
        ->and(LegacyRmeImport::count())->toBe(2);
});
