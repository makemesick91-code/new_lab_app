<?php

declare(strict_types=1);

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyRmeMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadAuditService;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadBatchService;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadDispatchService;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';

/**
 * FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §46 — mass RME behaviour.
 *
 * Every assertion here runs through the REAL canonical services. Nothing is
 * mocked, because the entire point of the design is that mass upload decides
 * nothing itself — so a test that mocked the domain would be testing an empty
 * shell.
 */
beforeEach(function (): void {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    legacyMassUploadFakeDisk();

    // The render job is dispatched by the canonical service. Faking the bus
    // keeps these tests about creation and lets a separate assertion count
    // dispatches, rather than rasterizing PDFs 250 times.
    Bus::fake();
});

/**
 * Distinct PDF bytes on every call.
 *
 * legacyRmePdfBytes() is deterministic for a given page count, and the archive
 * refuses a checksum it has already seen. Reusing bytes would measure the
 * duplicate guard instead of whatever the test is actually about, so the
 * MediaBox width is nudged a fraction of a point per call: structurally valid,
 * visually identical, different checksum.
 */
function lmuRmePdf(int $pages = 1): string
{
    static $variant = 0;
    $variant++;

    return legacyRmePdfBytes($pages, 595.276 + ($variant / 1000));
}

/** A patient who may legitimately receive a legacy RME archive. */
function lmuRmePatient(string $nativeVisit = '2022-03-10'): Patient
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    legacyRmeNativeVisit($patient, $nativeVisit);

    return $patient;
}

function lmuRmeAdapter(): LegacyRmeMassUploadAdapter
{
    return app(LegacyRmeMassUploadAdapter::class);
}

/**
 * Upload + validate + preflight, returning the batch.
 *
 * @param  list<array<string, string|null>>  $rows
 * @param  array<string, string>  $documents
 */
function lmuRmeStore(array $documents, array $rows): LegacyMassUploadBatch
{
    $zip = legacyMassUploadZip(documents: $documents, manifestRows: $rows);

    return app(LegacyMassUploadBatchService::class)->store($zip, lmuRmeAdapter(), superAdmin());
}

/** Confirm then run one bounded dispatch pass. */
function lmuRmeDispatch(LegacyMassUploadBatch $batch): LegacyMassUploadBatch
{
    $dispatcher = app(LegacyMassUploadDispatchService::class);
    $actor = superAdmin();

    $batch = $dispatcher->confirm($batch, $actor);

    return $dispatcher->dispatchPass($batch, lmuRmeAdapter(), $actor);
}

/** Stage a legacy RME import through the canonical intake service. */
function lmuRmeStageExisting(Patient $patient, string $date = '2020-05-01'): LegacyRmeImport
{
    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        $date,
        $patient->medical_record_number,
        null,
        UploadedFile::fake()->createWithContent('arsip.pdf', lmuRmePdf()),
        superAdmin(),
    );
}

function lmuItem(LegacyMassUploadBatch $batch, int $row): LegacyMassUploadItem
{
    return LegacyMassUploadItem::query()
        ->where('mass_upload_batch_id', $batch->getKey())
        ->where('row_number', $row)
        ->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('preflights a clean archive as eligible and creates one canonical import per row', function (): void {
    $one = lmuRmePatient();
    $two = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf(), 'b.pdf' => lmuRmePdf()],
        [
            legacyMassUploadRmeRow($one->medical_record_number, 'a.pdf', '2018-01-02', '2019-05-06'),
            legacyMassUploadRmeRow($two->medical_record_number, 'b.pdf', '2017-03-04'),
        ],
    );

    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::PREFLIGHT_READY)
        ->and($batch->total_items)->toBe(2)
        ->and($batch->eligible_items)->toBe(2)
        ->and($batch->blocked_items)->toBe(0);

    // Preflight must not have created anything clinical.
    expect(LegacyRmeImport::query()->count())->toBe(0);

    $batch = lmuRmeDispatch($batch);

    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::COMPLETED)
        ->and($batch->dispatched_items)->toBe(2)
        ->and(LegacyRmeImport::query()->count())->toBe(2);

    // Each item records WHICH canonical import it produced — the idempotency
    // marker a resumed pass relies on.
    $item = lmuItem($batch, 1);
    expect($item->status)->toBe(LegacyMassUploadItemStatus::DISPATCHED)
        ->and($item->rme_legacy_import_id)->not->toBeNull()
        ->and($item->odontogram_legacy_import_id)->toBeNull();

    $import = LegacyRmeImport::query()->findOrFail($item->rme_legacy_import_id);
    expect((int) $import->patient_id)->toBe((int) $one->getKey());
});

it('carries the declared date range onto the created import', function (): void {
    // §12/§30 — the earliest date is the representative one and the latest is a
    // real additional bound, not decoration.
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2016-02-07', '2021-11-30')],
    );

    $batch = lmuRmeDispatch($batch);

    $import = LegacyRmeImport::query()->firstOrFail();

    expect($import->selected_rme_date->toDateString())->toBe('2016-02-07')
        ->and($import->latest_rme_date->toDateString())->toBe('2021-11-30');
});

/*
|--------------------------------------------------------------------------
| Single-active-document integration (§13)
|--------------------------------------------------------------------------
*/

it('blocks a patient who already has an active legacy rme import', function (): void {
    $patient = lmuRmePatient();

    lmuRmeStageExisting($patient);

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    expect($batch->eligible_items)->toBe(0)
        ->and($batch->blocked_items)->toBe(1);

    expect(lmuItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS);
});

it('keeps the slot occupied for a failed but retryable import', function (): void {
    // FAILED is retryable, so it still occupies the patient's slot. A mass row
    // must not open a second lifecycle alongside a retry.
    $patient = lmuRmePatient();

    $existing = lmuRmeStageExisting($patient);
    $existing->forceFill(['status' => LegacyRmeImportStatus::FAILED])->save();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    expect($batch->blocked_items)->toBe(1)
        ->and(lmuItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS);
});

it('releases the slot once the previous import is cancelled', function (): void {
    $patient = lmuRmePatient();

    $existing = lmuRmeStageExisting($patient);
    $existing->forceFill(['status' => LegacyRmeImportStatus::CANCELLED])->save();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    expect($batch->eligible_items)->toBe(1)
        ->and($batch->blocked_items)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Per-item refusals (§17, §21)
|--------------------------------------------------------------------------
*/

it('blocks only the offending row and lets its siblings through', function (): void {
    // THE CORE OF §17. One bad patient must not cost the other 249 theirs.
    $good = lmuRmePatient();
    $blocked = lmuRmePatient();

    lmuRmeStageExisting($blocked);

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf(), 'b.pdf' => lmuRmePdf()],
        [
            legacyMassUploadRmeRow($blocked->medical_record_number, 'a.pdf', '2018-01-02'),
            legacyMassUploadRmeRow($good->medical_record_number, 'b.pdf', '2018-01-02'),
        ],
    );

    expect($batch->eligible_items)->toBe(1)
        ->and($batch->blocked_items)->toBe(1);

    $batch = lmuRmeDispatch($batch);

    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::COMPLETED_WITH_BLOCKED_ITEMS)
        ->and($batch->dispatched_items)->toBe(1);

    expect(lmuItem($batch, 1)->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and(lmuItem($batch, 2)->status)->toBe(LegacyMassUploadItemStatus::DISPATCHED);
});

it('never creates a lifecycle or a job for a blocked row', function (): void {
    // §18 — the blocked rows must cost nothing at all.
    $patient = lmuRmePatient();

    lmuRmeStageExisting($patient);

    $before = LegacyRmeImport::query()->count();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $batch = lmuRmeDispatch($batch);

    expect(LegacyRmeImport::query()->count())->toBe($before)
        ->and($batch->dispatched_items)->toBe(0)
        ->and(lmuItem($batch, 1)->rme_legacy_import_id)->toBeNull();
});

it('blocks an unknown medical record number', function (): void {
    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow('TLK1-2019-999999', 'a.pdf', '2018-01-02')],
    );

    expect($batch->blocked_items)->toBe(1)
        ->and(lmuItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::PATIENT_NOT_FOUND)
        ->and(lmuItem($batch, 1)->resolved_patient_id)->toBeNull();
});

it('blocks a partial medical record number instead of accepting a suffix match', function (): void {
    /*
     * THE BULK WRONG-PATIENT GUARD.
     *
     * The patient lookup falls back to a suffix match across every branch, so a
     * partial RM finds a patient. The canonical binding service then resolves
     * the SAME value exactly, finds it is not a whole number, and refuses — and
     * the operator is told to write the RM in full rather than sent hunting a
     * mix-up that does not exist.
     */
    $patient = lmuRmePatient();

    $full = (string) $patient->medical_record_number;
    $partial = substr($full, -5);

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($partial, 'a.pdf', '2018-01-02')],
    );

    expect($batch->eligible_items)->toBe(0)
        ->and($batch->blocked_items)->toBe(1);

    $item = lmuItem($batch, 1);

    expect($item->reason_code)->toBeIn([
        LegacyMassUploadReason::SOURCE_RM_INVALID,
        LegacyMassUploadReason::SOURCE_RM_MISMATCH,
        LegacyMassUploadReason::PATIENT_NOT_FOUND,
    ]);

    expect(LegacyRmeImport::query()->count())->toBe(0);
});

it('blocks a date that violates the native rme boundary', function (): void {
    // The legacy archive must predate the patient's earliest native encounter.
    $patient = lmuRmePatient('2015-01-05');

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2019-08-08')],
    );

    expect($batch->blocked_items)->toBe(1)
        ->and(lmuItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::NATIVE_RME_BOUNDARY);
});

it('blocks a row whose date is missing', function (): void {
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '')],
    );

    expect($batch->blocked_items)->toBe(1)
        ->and(lmuItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::DATE_MISSING);
});

it('blocks the second appearance of one patient in the same manifest', function (): void {
    /*
     * §12 — the multi-year case.
     *
     * Three yearly PDFs for one patient must become ONE archive document, not
     * three lifecycles. At preflight neither occurrence has created anything,
     * so the slot is genuinely free for both and only the batch itself can see
     * the duplication. The FIRST row stays eligible; order is deterministic so
     * a re-run imports the same one.
     */
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['y2019.pdf' => lmuRmePdf(), 'y2020.pdf' => lmuRmePdf(), 'y2021.pdf' => lmuRmePdf()],
        [
            legacyMassUploadRmeRow($patient->medical_record_number, 'y2019.pdf', '2018-01-02'),
            legacyMassUploadRmeRow($patient->medical_record_number, 'y2020.pdf', '2019-01-02'),
            legacyMassUploadRmeRow($patient->medical_record_number, 'y2021.pdf', '2020-01-02'),
        ],
    );

    expect($batch->eligible_items)->toBe(1)
        ->and($batch->blocked_items)->toBe(2);

    expect(lmuItem($batch, 1)->status)->toBe(LegacyMassUploadItemStatus::ELIGIBLE)
        ->and(lmuItem($batch, 2)->reason_code)->toBe(LegacyMassUploadReason::DUPLICATE_MANIFEST_PATIENT)
        ->and(lmuItem($batch, 3)->reason_code)->toBe(LegacyMassUploadReason::DUPLICATE_MANIFEST_PATIENT);

    // And only one lifecycle is ever created.
    $batch = lmuRmeDispatch($batch);

    expect(LegacyRmeImport::query()->count())->toBe(1);
});

it('blocks a byte identical duplicate document inside one manifest', function (): void {
    $one = lmuRmePatient();
    $two = lmuRmePatient();

    $shared = lmuRmePdf();

    $batch = lmuRmeStore(
        ['a.pdf' => $shared, 'b.pdf' => $shared],
        [
            legacyMassUploadRmeRow($one->medical_record_number, 'a.pdf', '2018-01-02'),
            legacyMassUploadRmeRow($two->medical_record_number, 'b.pdf', '2018-01-02'),
        ],
    );

    expect($batch->eligible_items)->toBe(1)
        ->and(lmuItem($batch, 2)->reason_code)->toBe(LegacyMassUploadReason::DUPLICATE_SOURCE);
});

/*
|--------------------------------------------------------------------------
| Stale preflight (§15) and idempotency (§27)
|--------------------------------------------------------------------------
*/

it('blocks an eligible row whose slot was taken between preflight and dispatch', function (): void {
    /*
     * THE STALE-PREFLIGHT CASE, end to end.
     *
     * Preflight says eligible. A single upload then publishes for the same
     * patient. createFromUpload() re-decides under its advisory lock and
     * refuses, and the row becomes BLOCKED with the precise occupancy reason —
     * not a generic domain refusal, and above all not a second lifecycle.
     */
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    expect($batch->eligible_items)->toBe(1);

    // The race: someone else claims the slot after preflight.
    lmuRmeStageExisting($patient, '2019-06-06');

    $batch = lmuRmeDispatch($batch);

    $item = lmuItem($batch, 1);

    expect($item->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and($item->reason_code)->toBe(LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS)
        ->and($item->rme_legacy_import_id)->toBeNull();

    // Exactly one lifecycle exists: the one the other path created.
    expect(LegacyRmeImport::query()->count())->toBe(1);
});

it('treats a repeated confirm as a no op rather than a second dispatch', function (): void {
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $dispatcher = app(LegacyMassUploadDispatchService::class);
    $actor = superAdmin();

    $first = $dispatcher->confirm($batch, $actor);
    $second = $dispatcher->confirm($batch->refresh(), $actor);

    expect($first->status)->toBe(LegacyMassUploadBatchStatus::CONFIRMED)
        ->and($second->status)->toBe(LegacyMassUploadBatchStatus::CONFIRMED)
        ->and($second->confirmed_at->equalTo($first->confirmed_at))->toBeTrue();
});

it('does not create a second lifecycle when dispatch runs twice', function (): void {
    // §26/§27 — a resumed or double-clicked dispatch must be a no-op for rows
    // that already produced an import.
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $batch = lmuRmeDispatch($batch);

    expect(LegacyRmeImport::query()->count())->toBe(1);

    app(LegacyMassUploadDispatchService::class)
        ->dispatchPass($batch->refresh(), lmuRmeAdapter(), superAdmin());

    expect(LegacyRmeImport::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Batch shape (§49)
|--------------------------------------------------------------------------
*/

it('reports a realistic mixed batch accurately and enqueues only the eligible rows', function (): void {
    /*
     * §49 in miniature. The brief's shape is 250 rows = 242 eligible +
     * 5 already published + 3 active; the ratio is what matters, not the
     * volume, and 250 real PDFs would make this suite minutes slower for no
     * extra proof.
     */
    $documents = [];
    $rows = [];
    $expectedEligible = 6;
    $expectedActive = 3;

    for ($i = 1; $i <= $expectedEligible; $i++) {
        $patient = lmuRmePatient();
        $name = "ok{$i}.pdf";
        $documents[$name] = lmuRmePdf();
        $rows[] = legacyMassUploadRmeRow($patient->medical_record_number, $name, '2018-01-02');
    }

    for ($i = 1; $i <= $expectedActive; $i++) {
        $patient = lmuRmePatient();
        lmuRmeStageExisting($patient);
        $name = "active{$i}.pdf";
        $documents[$name] = lmuRmePdf();
        $rows[] = legacyMassUploadRmeRow($patient->medical_record_number, $name, '2018-01-02');
    }

    $existingImports = LegacyRmeImport::query()->count();

    $batch = lmuRmeStore($documents, $rows);

    expect($batch->total_items)->toBe($expectedEligible + $expectedActive)
        ->and($batch->eligible_items)->toBe($expectedEligible)
        ->and($batch->blocked_items)->toBe($expectedActive);

    $batch = lmuRmeDispatch($batch);

    expect($batch->dispatched_items)->toBe($expectedEligible)
        ->and($batch->blocked_items)->toBe($expectedActive)
        ->and(LegacyRmeImport::query()->count())->toBe($existingImports + $expectedEligible)
        ->and($batch->status)->toBe(LegacyMassUploadBatchStatus::COMPLETED_WITH_BLOCKED_ITEMS);

    expect(
        LegacyMassUploadItem::query()
            ->where('mass_upload_batch_id', $batch->getKey())
            ->where('status', LegacyMassUploadItemStatus::BLOCKED)
            ->whereNotNull('rme_legacy_import_id')
            ->count()
    )->toBe(0);
});

it('cleans the workspace once the batch is finished', function (): void {
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $batch = lmuRmeDispatch($batch);

    expect($batch->workspace_cleaned_at)->not->toBeNull()
        ->and(Storage::disk('legacy_mass_upload_private')->directoryExists('legacy-mass-upload/'.$batch->uuid))
        ->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Gaps found by the §51 mutation campaign
|--------------------------------------------------------------------------
|
| Each of the following was added because a mutant SURVIVED. The behaviour was
| already correct; it simply was not pinned, so a future edit could have removed
| a guard without any test noticing.
*/

it('never even attempts a blocked row', function (): void {
    /*
     * Closes mutant M02 (dispatchable() widened to include BLOCKED).
     *
     * The earlier assertion only proved no lifecycle RESULTED, which stayed true
     * with the mutant applied because the canonical service refused the row a
     * second time. §18 asks for more than that: a blocked row must never be
     * attempted at all, or 8 refused rows in a 250-row batch become 8 pointless
     * creation attempts, 8 stored files to clean up and 8 misleading audit
     * entries.
     *
     * "Never attempted" is observable as: the row keeps the reason preflight
     * gave it, rather than being overwritten by the refusal the write path
     * would have produced.
     */
    $patient = lmuRmePatient();

    lmuRmeStageExisting($patient);

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    $before = lmuItem($batch, 1);

    expect($before->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and($before->reason_code)->toBe(LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS);

    $batch = lmuRmeDispatch($batch);

    $after = lmuItem($batch, 1);

    expect($after->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and($after->reason_code)->toBe(LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS)
        ->and($after->dispatched_at)->toBeNull()
        ->and($after->rme_legacy_import_id)->toBeNull();

    /*
     * THE OBSERVABLE THAT ACTUALLY DETECTS AN ATTEMPT.
     *
     * An earlier version of this test compared updated_at and was useless:
     * Eloquent's save() is a no-op when nothing is dirty, and a re-attempted
     * row is re-written with the SAME reason, so the timestamp never moved and
     * the mutation that widened dispatchable() to include BLOCKED survived.
     *
     * The audit trail cannot be fooled that way — markItem() writes an event
     * unconditionally. No item event means the row was never picked up.
     */
    expect(
        AuditLog::query()
            ->where('entity_type', LegacyMassUploadAuditService::ENTITY_ITEM)
            ->where('entity_id', $after->getKey())
            ->count()
    )->toBe(0);
});

it('resumes a bounded batch and skips a row that already produced an import', function (): void {
    /*
     * Closes mutants M04 / M04c / M04d, and covers §23 + §26 at the same time.
     *
     * The first version of this test let the batch COMPLETE before resuming,
     * which cleans the workspace — so the second pass died at re-extraction and
     * never reached the item. It passed for a reason that had nothing to do
     * with idempotency, and every idempotency mutant survived it.
     *
     * The real shape is a bounded pass: one item per request, so the batch
     * rests in DISPATCHING with its workspace intact, exactly as a 250-row
     * archive does between passes.
     *
     * The stale row simulates the interrupted write that the marker exists for:
     * createFromUpload() committed, the status update did not. Resuming must
     * not attempt it again.
     *
     * NOTE ON WHAT THIS PROVES. The campaign showed that removing BOTH
     * mass-layer guards still produces no duplicate — the canonical
     * advisory-locked slot guard refuses it. So duplicate prevention does not
     * rest on this layer. What this layer prevents is the pointless ATTEMPT: no
     * stored file to compensate, no refusal audit, no wasted render capacity.
     * The audit assertion is therefore the one that matters.
     */
    config()->set('legacy_mass_upload.dispatch.max_items_per_request', 1);

    $one = lmuRmePatient();
    $two = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf(), 'b.pdf' => lmuRmePdf()],
        [
            legacyMassUploadRmeRow($one->medical_record_number, 'a.pdf', '2018-01-02'),
            legacyMassUploadRmeRow($two->medical_record_number, 'b.pdf', '2017-03-04'),
        ],
    );

    expect($batch->eligible_items)->toBe(2);

    // Pass one: bounded to a single item, so the batch stays open.
    $batch = lmuRmeDispatch($batch);

    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::DISPATCHING)
        ->and($batch->dispatched_items)->toBe(1)
        ->and(LegacyRmeImport::query()->count())->toBe(1)
        // The workspace survives between passes — that is what makes resume
        // possible at all.
        ->and($batch->workspace_cleaned_at)->toBeNull();

    $firstItem = lmuItem($batch, 1);
    $firstImportId = (int) $firstItem->rme_legacy_import_id;

    // The interrupted write: lifecycle committed, status not yet updated.
    $firstItem->forceFill([
        'status' => LegacyMassUploadItemStatus::ELIGIBLE,
        'dispatched_at' => null,
    ])->save();

    // Pass two.
    $batch = app(LegacyMassUploadDispatchService::class)
        ->dispatchPass($batch->refresh(), lmuRmeAdapter(), superAdmin());

    // Row two got its lifecycle; row one did NOT get a second one.
    expect(LegacyRmeImport::query()->count())->toBe(2)
        ->and((int) lmuItem($batch, 1)->rme_legacy_import_id)->toBe($firstImportId)
        ->and(lmuItem($batch, 2)->rme_legacy_import_id)->not->toBeNull();

    expect(LegacyRmeImport::query()->where('patient_id', $one->getKey())->count())->toBe(1);

    // And row one was never re-attempted: no refusal audit was written for it.
    expect(
        AuditLog::query()
            ->where('entity_type', LegacyMassUploadAuditService::ENTITY_ITEM)
            ->where('entity_id', lmuItem($batch, 1)->getKey())
            ->where('action', 'MASS_UPLOAD_ITEM_BLOCKED')
            ->count()
    )->toBe(0);
});

it('fails a row whose document changed between preflight and creation', function (): void {
    /*
     * Closes mutant M14 (digest re-verification removed).
     *
     * The source-substitution guard (§43). The workspace is private and
     * server-owned, so this is defence in depth rather than a live threat — but
     * a re-extraction between bounded passes, a partial write or any tampering
     * would otherwise file DIFFERENT bytes against the patient that preflight
     * approved, and nothing else in the pipeline would notice.
     */
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    expect($batch->eligible_items)->toBe(1);

    // Swap the recorded digest so the bytes on disk no longer match what
    // preflight evaluated — the same observable state as a substituted file.
    lmuItem($batch, 1)->forceFill(['document_sha256' => str_repeat('b', 64)])->save();

    $batch = app(LegacyMassUploadDispatchService::class)
        ->dispatchPass(
            app(LegacyMassUploadDispatchService::class)->confirm($batch, superAdmin()),
            lmuRmeAdapter(),
            superAdmin(),
        );

    $item = lmuItem($batch, 1);

    expect($item->status)->toBe(LegacyMassUploadItemStatus::FAILED)
        ->and($item->reason_code)->toBe(LegacyMassUploadReason::FILE_INVALID)
        ->and($item->rme_legacy_import_id)->toBeNull()
        ->and(LegacyRmeImport::query()->count())->toBe(0);
});

it('blocks a patient whose branch is not admitted for legacy migration', function (): void {
    /*
     * Closes the M11 gap (branch admission bypass).
     *
     * §37 — mass upload must never reach a branch that single upload cannot.
     * The original campaign pointed this mutant at an unrelated test, so the
     * bypass went unobserved; this is the assertion that actually watches it.
     */
    $patient = lmuRmePatient();

    // Withdraw admission for every branch, exactly as a deployment that has not
    // opened this branch yet would look.
    config()->set('legacy_rme_rollout.admission.admitted_branch_codes', []);

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    expect($batch->eligible_items)->toBe(0)
        ->and($batch->blocked_items)->toBe(1);

    expect(lmuItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::BRANCH_NOT_ADMITTED);

    $batch = lmuRmeDispatch($batch);

    expect(LegacyRmeImport::query()->count())->toBe(0);
});

it('refuses at the write path when the patients rm changes after preflight', function (): void {
    /*
     * Closes mutant M16 (the odontogram/RME write-path binding gate removed).
     *
     * The preflight gate blocks an RM that never matched, so it alone never
     * exercises the gate on the WRITE path. That second assertion exists for
     * the case here: the row passed preflight legitimately, and the patient's
     * master data was corrected before the batch finished dispatching. The
     * manifest now names a number that no longer identifies this patient, and
     * filing the document anyway would bind it to the wrong record.
     *
     * For RME the canonical createFromUpload() re-asserts the binding itself.
     * This pins that the mass path really does route through it rather than
     * trusting its own earlier verdict.
     */
    $patient = lmuRmePatient();

    $batch = lmuRmeStore(
        ['a.pdf' => lmuRmePdf()],
        [legacyMassUploadRmeRow($patient->medical_record_number, 'a.pdf', '2018-01-02')],
    );

    expect($batch->eligible_items)->toBe(1);

    // The master-data correction: this patient is renumbered after review.
    $patient->forceFill([
        'medical_record_number' => 'DG-TLK1-2024-9999',
    ])->save();

    $batch = lmuRmeDispatch($batch);

    $item = lmuItem($batch, 1);

    expect($item->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and($item->rme_legacy_import_id)->toBeNull()
        ->and(LegacyRmeImport::query()->count())->toBe(0);
});
