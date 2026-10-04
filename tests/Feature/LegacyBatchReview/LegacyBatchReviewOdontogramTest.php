<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — batch review, legacy odontogram.
 *
 * WHY THIS IS A SEPARATE SUITE
 * ----------------------------
 * The two archives share an identical nine-state machine but NOT their
 * enforcement. The single most valuable assertion in this file is that batch
 * review did not quietly import RME's separation-of-duties rule: on odontogram
 * there is no such guard anywhere in the module, so an uploader reviewing their
 * own chart is permitted behaviour through the canonical single-item page — and
 * §2 requires batch mode to enforce the CURRENT policy truth "exactly as in
 * single-item mode", not a stricter invention.
 *
 * The mirror-image risk is just as real: if RME's guard had been dropped
 * instead, a clinical control would have disappeared silently. The RME suite
 * pins that direction; this one pins this.
 */

use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewItemDecision;
use App\Modules\LegacyImport\BatchReview\Models\LegacyReviewTriage;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewSessionService;
use App\Modules\LegacyImport\BatchReview\Services\LegacyReviewTriageService;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| The asymmetry, pinned
|--------------------------------------------------------------------------
*/

it('lets the uploader review their own odontogram chart in batch, as the single-item page does', function () {
    // There is NO separation-of-duties guard in the odontogram module: a grep
    // for `separation` across it returns nothing, and its policy checks only
    // permission, scope and transition. Batch review must therefore NOT refuse
    // this — enforcing RME's stricter rule here would mean the batch surface
    // silently diverged from the canonical page it mirrors.
    $uploader = superAdmin();
    $import = lbrOdontogramReady($uploader);

    $session = lbrOpenSession(lbrOdontogramAdapter(), $uploader);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrOdontogramAdapter(), $uploader,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );

    $summary = app(LegacyBatchReviewSessionService::class)
        ->submit($session, lbrOdontogramAdapter(), $uploader);

    expect($summary['applied'])->toBe(1)
        ->and($summary['refused'])->toBe(0);

    expect($import->refresh()->status)->toBe(LegacyOdontogramImportStatus::REVIEWED);
});

it('lets the uploader clear their own odontogram block, matching that policy', function () {
    // Consistent with the above: clearing follows the same policy truth as
    // reviewing for this document type, so it is permitted here and refused on
    // RME. The rule is reused per type, never reinvented per surface.
    $uploader = superAdmin();
    $import = lbrOdontogramReady($uploader);

    $session = lbrOpenSession(lbrOdontogramAdapter(), $uploader);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrOdontogramAdapter(), $uploader, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_ILLEGIBLE,
    );

    expect(LegacyReviewTriage::sole()->isBlocking())->toBeTrue();

    app(LegacyReviewTriageService::class)->clear(lbrOdontogramAdapter(), $import, $uploader);

    expect(LegacyReviewTriage::sole()->triage_status)->toBe(LegacyReviewTriageStatus::CLEARED);
});

/*
|--------------------------------------------------------------------------
| Item-level truth on the odontogram side
|--------------------------------------------------------------------------
*/

it('writes an odontogram decision into the odontogram column, never the RME one', function () {
    $reviewer = superAdmin();
    $import = lbrOdontogramReady(superAdmin());

    $session = lbrOpenSession(lbrOdontogramAdapter(), $reviewer);

    $decision = app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrOdontogramAdapter(), $reviewer,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );

    // A type confusion here would attach a decision to the wrong archive and
    // drive the wrong module's canonical service.
    expect($decision->import_type)->toBe(LegacyImportType::LEGACY_ODONTOGRAM)
        ->and($decision->odontogram_legacy_import_id)->toBe($import->getKey())
        ->and($decision->rme_legacy_import_id)->toBeNull()
        ->and($decision->source_sha256)->toBe($import->source_pdf_sha256);
});

it('blocks an odontogram chart without touching its lifecycle or its slot', function () {
    $reviewer = superAdmin();
    $import = lbrOdontogramReady(superAdmin());
    $patientId = (int) $import->patient_id;

    $session = lbrOpenSession(lbrOdontogramAdapter(), $reviewer);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrOdontogramAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_INCOMPLETE_PAGES,
    );

    $import->refresh();
    expect($import->status)->toBe(LegacyOdontogramImportStatus::READY_FOR_REVIEW)
        ->and($import->reviewed_by)->toBeNull();

    $slot = app(LegacySingleActiveDocumentService::class)
        ->occupancyFor(LegacyImportType::LEGACY_ODONTOGRAM, $patientId);

    expect($slot->occupied)->toBeTrue();
    expect(LegacyOdontogramRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The two archives stay independent
|--------------------------------------------------------------------------
*/

it('keeps triage on one archive from leaking into the other for the same patient', function () {
    // The slots are per (patient, document type) and so is triage. A blocked
    // RME document must not withhold that patient's odontogram chart, which is
    // the same independence the single-active-document service guarantees.
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');

    $reviewer = superAdmin();
    $odontogram = lbrOdontogramReady(superAdmin());

    $odoSession = lbrOpenSession(lbrOdontogramAdapter(), $reviewer);
    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $odoSession, lbrOdontogramAdapter(), $reviewer, (int) $odontogram->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_ILLEGIBLE,
    );

    $triageService = app(LegacyReviewTriageService::class);

    // Blocking exists for the odontogram id under the odontogram type...
    expect($triageService->blockingFor(
        LegacyImportType::LEGACY_ODONTOGRAM,
        'odontogram_legacy_import_id',
        (int) $odontogram->getKey()
    ))->not->toBeNull();

    // ...and the same numeric id under the RME type and column is unaffected.
    expect($triageService->blockingFor(
        LegacyImportType::LEGACY_RME,
        'rme_legacy_import_id',
        (int) $odontogram->getKey()
    ))->toBeNull();

    expect(LegacyBatchReviewItemDecision::where('import_type', LegacyImportType::LEGACY_RME)->count())->toBe(0);
});

it('publishes nothing on the odontogram side either', function () {
    $reviewer = superAdmin();
    $import = lbrOdontogramReady(superAdmin());

    $session = lbrOpenSession(lbrOdontogramAdapter(), $reviewer);
    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrOdontogramAdapter(), $reviewer,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );
    app(LegacyBatchReviewSessionService::class)->submit($session, lbrOdontogramAdapter(), $reviewer);

    expect($import->refresh()->status)->toBe(LegacyOdontogramImportStatus::REVIEWED);
    expect($import->published_by)->toBeNull();
    expect(LegacyOdontogramRecord::count())->toBe(0);
});
