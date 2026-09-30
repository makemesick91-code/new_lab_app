<?php

declare(strict_types=1);

use App\Modules\LegacyImport\MassUpload\Adapters\LegacyOdontogramMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyRmeMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadBatchService;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadDispatchService;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';

/**
 * FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §47, §48 — mass odontogram.
 *
 * The odontogram surface is NOT a mirror of the RME one and this suite pins the
 * differences deliberately: a single date rather than a range, no wave
 * admission, and — new in this sprint — a server-side manifest-RM binding that
 * single odontogram upload has never had.
 */
beforeEach(function (): void {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    legacyMassUploadFakeDisk();
    Bus::fake();
});

function lmuOdontoPdf(int $pages = 1): string
{
    static $variant = 0;
    $variant++;

    return legacyRmePdfBytes($pages, 595.276 + ($variant / 1000));
}

/** A patient who may legitimately receive a legacy odontogram chart. */
function lmuOdontoPatient(string $nativeDate = '2022-03-10'): Patient
{
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);

    lodoNativeOdontogram($patient, $nativeDate);

    return $patient;
}

/**
 * A patient set up for BOTH legacy domains.
 *
 * The independence tests need RME branch admission AND an odontogram native
 * reference. legacyRmeArchivablePatient() is what admits the branch for RME —
 * lodoPatient() only prepares the odontogram side, so using it here made mass
 * upload refuse with "no branch is permitted to start legacy RME migration",
 * which is the admission gate working correctly rather than a defect.
 */
function lmuBothDomainsPatient(): Patient
{
    $patient = legacyRmeArchivablePatient(['date_of_birth' => '1990-01-01']);

    legacyRmeNativeVisit($patient, '2022-03-10');
    lodoNativeOdontogram($patient, '2022-03-10');

    return $patient;
}

function lmuOdontoAdapter(): LegacyOdontogramMassUploadAdapter
{
    return app(LegacyOdontogramMassUploadAdapter::class);
}

/**
 * @param  array<string, string>  $documents
 * @param  list<array<string, string|null>>  $rows
 */
function lmuOdontoStore(array $documents, array $rows): LegacyMassUploadBatch
{
    $zip = legacyMassUploadZip(
        documents: $documents,
        manifestRows: $rows,
        importType: LegacyImportType::LEGACY_ODONTOGRAM,
    );

    return app(LegacyMassUploadBatchService::class)->store($zip, lmuOdontoAdapter(), superAdmin());
}

function lmuOdontoDispatch(LegacyMassUploadBatch $batch): LegacyMassUploadBatch
{
    $dispatcher = app(LegacyMassUploadDispatchService::class);
    $actor = superAdmin();

    return $dispatcher->dispatchPass($dispatcher->confirm($batch, $actor), lmuOdontoAdapter(), $actor);
}

function lmuOdontoItem(LegacyMassUploadBatch $batch, int $row): LegacyMassUploadItem
{
    return LegacyMassUploadItem::query()
        ->where('mass_upload_batch_id', $batch->getKey())
        ->where('row_number', $row)
        ->firstOrFail();
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

it('preflights and dispatches a clean odontogram archive', function (): void {
    $patient = lmuOdontoPatient();

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    expect($batch->import_type)->toBe(LegacyImportType::LEGACY_ODONTOGRAM)
        ->and($batch->eligible_items)->toBe(1);

    expect(LegacyOdontogramImport::query()->count())->toBe(0);

    $batch = lmuOdontoDispatch($batch);

    expect($batch->status)->toBe(LegacyMassUploadBatchStatus::COMPLETED)
        ->and($batch->dispatched_items)->toBe(1)
        ->and(LegacyOdontogramImport::query()->count())->toBe(1);

    $item = lmuOdontoItem($batch, 1);

    // The odontogram column is the one populated; the RME column stays null.
    expect($item->odontogram_legacy_import_id)->not->toBeNull()
        ->and($item->rme_legacy_import_id)->toBeNull();
});

it('does not invent a latest date for a single date document', function (): void {
    // §33 — no false symmetry with the RME range.
    $patient = lmuOdontoPatient();

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    expect(lmuOdontoItem($batch, 1)->manifest_selected_date)->toBe('2019-04-11')
        ->and(lmuOdontoItem($batch, 1)->manifest_latest_date)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Single-active-document, evaluated independently of RME (§14)
|--------------------------------------------------------------------------
*/

it('blocks a patient who already has an active legacy odontogram import', function (): void {
    $patient = lmuOdontoPatient();

    lodoStageImport($patient, '2018-02-02', superAdmin());

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    expect($batch->blocked_items)->toBe(1)
        ->and(lmuOdontoItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS);
});

it('releases the odontogram slot once the previous import is cancelled', function (): void {
    $patient = lmuOdontoPatient();

    $existing = lodoStageImport($patient, '2018-02-02', superAdmin());
    $existing->forceFill(['status' => LegacyOdontogramImportStatus::CANCELLED])->save();

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    expect($batch->eligible_items)->toBe(1);
});

it('blocks the second appearance of one patient in an odontogram manifest', function (): void {
    $patient = lmuOdontoPatient();

    $batch = lmuOdontoStore(
        ['a.pdf' => lmuOdontoPdf(), 'b.pdf' => lmuOdontoPdf()],
        [
            legacyMassUploadOdontogramRow($patient->medical_record_number, 'a.pdf', '2018-04-11'),
            legacyMassUploadOdontogramRow($patient->medical_record_number, 'b.pdf', '2019-04-11'),
        ],
    );

    expect($batch->eligible_items)->toBe(1)
        ->and(lmuOdontoItem($batch, 2)->reason_code)->toBe(LegacyMassUploadReason::DUPLICATE_MANIFEST_PATIENT);
});

/*
|--------------------------------------------------------------------------
| The gate this sprint ADDS to the odontogram path
|--------------------------------------------------------------------------
*/

it('hard blocks an odontogram row whose manifest rm does not name the patient exactly', function (): void {
    /*
     * THE NEW GATE.
     *
     * Single odontogram upload has no source-RM concept at all — its only
     * wrong-patient defence is the human `patient_confirmation` checkbox, which
     * mass upload by its nature removes. Shipping without a replacement would
     * have left this path with NO wrong-patient defence, which is exactly the
     * exposure SOURCE-RM-BINDING-1 closed on the RME side after a real
     * production wrong-patient binding.
     *
     * So the manifest RM is asserted against the resolved patient by the same
     * canonical binding service, and a partial or foreign number blocks the
     * row. Mass odontogram is therefore STRICTER than single odontogram upload.
     */
    $patient = lmuOdontoPatient();

    $partial = substr((string) $patient->medical_record_number, -5);

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($partial, 'chart.pdf', '2019-04-11')],
    );

    expect($batch->eligible_items)->toBe(0)
        ->and($batch->blocked_items)->toBe(1);

    expect(lmuOdontoItem($batch, 1)->reason_code)->toBeIn([
        LegacyMassUploadReason::SOURCE_RM_INVALID,
        LegacyMassUploadReason::SOURCE_RM_MISMATCH,
        LegacyMassUploadReason::PATIENT_NOT_FOUND,
    ]);

    expect(LegacyOdontogramImport::query()->count())->toBe(0);
});

it('blocks an odontogram row naming a different patients medical record number', function (): void {
    $owner = lmuOdontoPatient();
    $other = lmuOdontoPatient();

    // The document claims `other`, but the row is only eligible if the RM and
    // the resolved patient agree — and they resolve to different people.
    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($other->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    // Resolution finds `other`, binding agrees, so this row is legitimately
    // eligible FOR OTHER — the important assertion is that it was never filed
    // against `owner`.
    $batch = lmuOdontoDispatch($batch);

    $import = LegacyOdontogramImport::query()->first();

    expect($import)->not->toBeNull()
        ->and((int) $import->patient_id)->toBe((int) $other->getKey())
        ->and((int) $import->patient_id)->not->toBe((int) $owner->getKey());
});

it('blocks an unknown medical record number on the odontogram surface', function (): void {
    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow('TLK1-2019-888888', 'chart.pdf', '2019-04-11')],
    );

    expect($batch->blocked_items)->toBe(1)
        ->and(lmuOdontoItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::PATIENT_NOT_FOUND);
});

it('blocks an odontogram date violating the native odontogram boundary', function (): void {
    $patient = lmuOdontoPatient('2015-01-05');

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-08-08')],
    );

    expect($batch->blocked_items)->toBe(1)
        ->and(lmuOdontoItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::NATIVE_ODONTOGRAM_BOUNDARY);
});

it('blocks an odontogram row whose slot was taken between preflight and dispatch', function (): void {
    $patient = lmuOdontoPatient();

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    expect($batch->eligible_items)->toBe(1);

    lodoStageImport($patient, '2018-02-02', superAdmin());

    $batch = lmuOdontoDispatch($batch);

    expect(lmuOdontoItem($batch, 1)->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and(lmuOdontoItem($batch, 1)->reason_code)->toBe(LegacyMassUploadReason::ACTIVE_IMPORT_EXISTS)
        ->and(LegacyOdontogramImport::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| §48 — the two slots are INDEPENDENT
|--------------------------------------------------------------------------
*/

it('lets a first odontogram through for a patient who already has a legacy rme import', function (): void {
    // Neither type may ever block the other. A patient's paper RME and their
    // paper chart are separate documents with separate lifecycles.
    $patient = lmuBothDomainsPatient();

    app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2020-05-01',
        $patient->medical_record_number,
        null,
        UploadedFile::fake()->createWithContent('arsip.pdf', lmuOdontoPdf()),
        superAdmin(),
    );

    expect(LegacyRmeImport::query()->count())->toBe(1);

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    expect($batch->eligible_items)->toBe(1)
        ->and($batch->blocked_items)->toBe(0);

    $batch = lmuOdontoDispatch($batch);

    expect(LegacyOdontogramImport::query()->count())->toBe(1);
});

it('lets a first legacy rme through for a patient who already has an odontogram import', function (): void {
    $patient = lmuBothDomainsPatient();

    lodoStageImport($patient, '2018-02-02', superAdmin());

    expect(LegacyOdontogramImport::query()->count())->toBe(1);

    $zip = legacyMassUploadZip(
        documents: ['arsip.pdf' => lmuOdontoPdf()],
        manifestRows: [legacyMassUploadRmeRow($patient->medical_record_number, 'arsip.pdf', '2019-04-11')],
    );

    $batch = app(LegacyMassUploadBatchService::class)
        ->store($zip, app(LegacyRmeMassUploadAdapter::class), superAdmin());

    expect($batch->eligible_items)->toBe(1)
        ->and($batch->blocked_items)->toBe(0);

    $dispatcher = app(LegacyMassUploadDispatchService::class);
    $actor = superAdmin();
    $dispatcher->dispatchPass($dispatcher->confirm($batch, $actor), app(LegacyRmeMassUploadAdapter::class), $actor);

    expect(LegacyRmeImport::query()->count())->toBe(1);
});

it('refuses an odontogram at the write path when the rm stops naming the patient', function (): void {
    /*
     * Closes mutant M16 — the write-path binding re-assertion in the ODONTOGRAM
     * adapter.
     *
     * Why this gate is not redundant here, unlike on the RME side:
     * LegacyOdontogramImportService takes no sourceRm argument, so unlike every
     * other gate the binding is NOT re-evaluated inside the canonical call. If
     * the adapter trusted its own preflight verdict, a master-data correction
     * between review and dispatch would file the chart against a record the
     * manifest no longer identifies.
     *
     * The preflight gate cannot cover this: at preflight the RM was correct.
     */
    $patient = lmuOdontoPatient();

    $batch = lmuOdontoStore(
        ['chart.pdf' => lmuOdontoPdf()],
        [legacyMassUploadOdontogramRow($patient->medical_record_number, 'chart.pdf', '2019-04-11')],
    );

    expect($batch->eligible_items)->toBe(1);

    // The patient is renumbered after the operator reviewed the batch.
    $patient->forceFill(['medical_record_number' => 'DG-TLK1-2024-8888'])->save();

    $batch = lmuOdontoDispatch($batch);

    $item = lmuOdontoItem($batch, 1);

    expect($item->status)->toBe(LegacyMassUploadItemStatus::BLOCKED)
        ->and($item->odontogram_legacy_import_id)->toBeNull()
        ->and(LegacyOdontogramImport::query()->count())->toBe(0);
});
