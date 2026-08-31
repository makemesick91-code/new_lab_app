<?php

/**
 * BUGFIX-LEGACY-ODONTOGRAM-QUEUE-CONSUMER-1 — what the WORKER may and may not do.
 *
 * Restoring the consumer means this code path starts executing in production
 * again after a period in which it never ran at all. The properties it must hold
 * were asserted around STAGING and around PUBLISH, but not around PROCESSING
 * itself: LegacyOdontogramPublishTest deliberately takes its native-record
 * baseline AFTER staging, so everything the rasterization step does sat inside
 * the unmeasured gap between the two.
 *
 * That gap is where a queue worker's blast radius actually lives, so it is
 * pinned here: the worker renders page images and moves the import forward, and
 * does nothing else to the patient, the ledger, the quota, or the state machine.
 */

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LegacyImport\Services\LegacyImportDailyQuotaService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmePdfException;
use App\Modules\LegacyRme\Support\LegacyRmePdfFailure;
use App\Modules\LegacyRme\Support\LegacyRmeRasterizationResult;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();

    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(2));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(2));
});

/**
 * A staged import sitting at QUEUED — the exact state the stalled production
 * document was in, before any worker touched it.
 */
function lodoQueuedImport(): array
{
    $patient = lodoPatient(['date_of_birth' => '1990-01-01']);
    lodoNativeOdontogram($patient, '2022-03-10');
    $actor = lodoOperator();

    $import = lodoStageImport($patient, '2019-06-01', $actor, 2);

    return [$import->refresh(), $patient, $actor];
}

function lodoNativeCensus(): array
{
    return [
        'visits' => ClinicVisit::count(),
        'odontograms' => Odontogram::count(),
        'medical_records' => MedicalRecord::count(),
        'invoices' => RmeInvoice::count(),
        'payments' => RmePayment::count(),
        'lab_orders' => LabOrder::count(),
        'satusehat' => SatusehatCandidate::count(),
    ];
}

it('advances a queued import to READY_FOR_REVIEW when the worker runs', function () {
    [$import] = lodoQueuedImport();

    expect($import->status)->toBe(LegacyOdontogramImportStatus::QUEUED);

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    expect($import->refresh()->status)->toBe(LegacyOdontogramImportStatus::READY_FOR_REVIEW)
        ->and($import->pages()->count())->toBe(2);
});

it('creates no native clinical, financial or downstream record while processing', function () {
    [$import] = lodoQueuedImport();

    // Baseline taken AFTER staging and BEFORE processing. The publish suite
    // measures the other side of this boundary; between the two there was no
    // assertion at all, which is precisely the worker's window.
    $before = lodoNativeCensus();

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    expect(lodoNativeCensus())->toBe($before);
});

it('does not consume daily import quota a second time while processing', function () {
    [$import, $patient] = lodoQueuedImport();

    $quota = app(LegacyImportDailyQuotaService::class);
    $branchId = (int) $import->origin_branch_id;

    // The upload already spent its slot transactionally. A worker that reserved
    // again would silently halve the branch's daily ceiling, and a retry would
    // halve it again.
    $before = $quota->consumedToday(LegacyImportType::LEGACY_ODONTOGRAM, $branchId);

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    expect($quota->consumedToday(LegacyImportType::LEGACY_ODONTOGRAM, $branchId))->toBe($before)
        ->and($before)->toBeGreaterThan(0);
});

it('keeps rendered pages on the private disk', function () {
    [$import] = lodoQueuedImport();

    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    $forbidden = (array) config('legacy_odontogram.storage.forbidden_disks');

    expect(config('legacy_odontogram.storage.disk'))->not->toBeIn($forbidden)
        ->and($forbidden)->toContain('public');

    foreach ($import->refresh()->pages as $page) {
        expect($page->image_disk)->not->toBeIn($forbidden);
    }
});

it('refuses to reach READY_FOR_REVIEW without passing through PROCESSING', function () {
    // The status machine is the authority for QUEUED → PROCESSING; a worker
    // that skipped the claim would be publishing an unrendered document.
    expect(LegacyOdontogramImportStatus::canTransition(
        LegacyOdontogramImportStatus::QUEUED,
        LegacyOdontogramImportStatus::READY_FOR_REVIEW,
    ))->toBeFalse()
        ->and(LegacyOdontogramImportStatus::canTransition(
            LegacyOdontogramImportStatus::QUEUED,
            LegacyOdontogramImportStatus::PROCESSING,
        ))->toBeTrue()
        ->and(LegacyOdontogramImportStatus::canTransition(
            LegacyOdontogramImportStatus::PROCESSING,
            LegacyOdontogramImportStatus::READY_FOR_REVIEW,
        ))->toBeTrue()
        ->and(LegacyOdontogramImportStatus::canTransition(
            LegacyOdontogramImportStatus::PROCESSING,
            LegacyOdontogramImportStatus::FAILED,
        ))->toBeTrue();
});

it('does not leave a processed import back at QUEUED when rendering fails', function () {
    [$import] = lodoQueuedImport();

    // A failure that returned the row to QUEUED would be indistinguishable from
    // a document that was never picked up — the very ambiguity this sprint exists
    // to remove.
    app()->instance(
        LegacyRmePdfRasterizerInterface::class,
        new class implements LegacyRmePdfRasterizerInterface
        {
            public function rasterize(
                string $sourceAbsolutePath,
                string $temporaryOutputDirectory,
                int $dpi,
            ): LegacyRmeRasterizationResult {
                throw LegacyRmePdfException::make(LegacyRmePdfFailure::PDF_RENDER_FAILED);
            }
        }
    );

    try {
        app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());
    } catch (Throwable) {
        // The job's retry/failure contract is asserted on the row, not here.
    }

    expect($import->refresh()->status)->not->toBe(LegacyOdontogramImportStatus::QUEUED);
});
