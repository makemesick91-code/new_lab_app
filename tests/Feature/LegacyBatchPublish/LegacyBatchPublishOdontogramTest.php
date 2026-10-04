<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — batch publish, odontogram.
 *
 * WHY THIS IS A SEPARATE SUITE
 * ----------------------------
 * §7 forbids inventing one shared RME/Odontogram separation rule, and forbids
 * making odontogram either weaker OR silently stricter than its single-item
 * publish workflow. The single most valuable assertion here is that batch
 * publish did not import RME's separation-of-duties rule: this module has no
 * such guard anywhere, so an uploader publishing their own chart is permitted
 * behaviour through the canonical page, and the batch surface must match it.
 *
 * The mirror-image risk is covered by the RME suite, which proves a separation
 * refusal still happens there. Together they pin both directions.
 */

use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishItem;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\BatchReview\Services\LegacyReviewTriageService;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/../LegacyBatchReview/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| The asymmetry, pinned — §7
|--------------------------------------------------------------------------
*/

it('lets the uploader publish their own odontogram chart in batch, as the single-item page does', function () {
    // This module has NO separation-of-duties guard — a grep for `separation`
    // across it returns nothing, and its publish policy checks only permission,
    // scope and transition. Refusing this would make the batch surface silently
    // stricter than the canonical page it mirrors, which §7 forbids.
    $uploader = superAdmin();
    $import = lbpOdontogramReviewed($uploader, $uploader);

    $run = lbpOpenRun(lbpOdontogramAdapter(), $uploader);
    $result = lbpSelectAndPublish($run, lbpOdontogramAdapter(), $uploader, [(int) $import->getKey()]);

    expect($result['select']['eligible'])->toBe(1)
        ->and($result['publish']['published'])->toBe(1)
        ->and($result['publish']['refused'])->toBe(0);

    expect($import->refresh()->status)->toBe(LegacyOdontogramImportStatus::PUBLISHED);

    $record = LegacyOdontogramRecord::sole();
    expect($record->status)->toBe(LegacyOdontogramRecordStatus::PUBLISHED)
        ->and($record->source_import_id)->toBe($import->getKey());
});

it('publishes several reviewed odontogram charts with partial-success accounting', function () {
    $publisher = superAdmin();
    $good = collect(range(1, 2))->map(fn (): object => lbpOdontogramReviewed());
    $unreviewed = lbrOdontogramReady(superAdmin());

    $run = lbpOpenRun(lbpOdontogramAdapter(), $publisher);

    $result = lbpSelectAndPublish(
        $run, lbpOdontogramAdapter(), $publisher,
        $good->map(fn ($i): int => (int) $i->getKey())->push((int) $unreviewed->getKey())->all(),
    );

    expect($result['select']['eligible'])->toBe(2)
        ->and($result['select']['refused'])->toBe(1)
        ->and($result['publish']['published'])->toBe(2);

    expect(LegacyOdontogramRecord::count())->toBe(2);
    expect($unreviewed->refresh()->status)->toBe(LegacyOdontogramImportStatus::READY_FOR_REVIEW);

    expect(
        LegacyBatchPublishItem::where('odontogram_legacy_import_id', $unreviewed->getKey())->sole()->reason_code
    )->toBe(LegacyBatchPublishReason::NOT_REVIEWED);
});

/*
|--------------------------------------------------------------------------
| Triage and type isolation
|--------------------------------------------------------------------------
*/

it('refuses a withheld odontogram chart and never clears the block', function () {
    $publisher = superAdmin();
    $blocker = superAdmin();
    $import = lbpOdontogramReviewed();

    // Raised through the canonical triage service, because PR1's review
    // workspace only accepts a READY_FOR_REVIEW document and this one is
    // already REVIEWED.
    app(LegacyReviewTriageService::class)->raise(
        lbrOdontogramAdapter(),
        $import,
        $blocker,
        LegacyReviewTriageStatus::BLOCKED,
        LegacyBatchReviewReason::TRIAGE_INCOMPLETE_PAGES,
    );

    $run = lbpOpenRun(lbpOdontogramAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpOdontogramAdapter(), $publisher, [(int) $import->getKey()]);

    expect(LegacyBatchPublishItem::sole()->reason_code)
        ->toBe(LegacyBatchPublishReason::TRIAGE_BLOCKED);
    expect(LegacyOdontogramRecord::count())->toBe(0);
    expect($import->refresh()->status)->toBe(LegacyOdontogramImportStatus::REVIEWED);

    // The block survives.
    expect(
        app(LegacyReviewTriageService::class)->blockingFor(
            LegacyImportType::LEGACY_ODONTOGRAM,
            'odontogram_legacy_import_id',
            (int) $import->getKey()
        )
    )->not->toBeNull();
});

it('writes an odontogram attempt into the odontogram columns, never the RME ones', function () {
    $publisher = superAdmin();
    $import = lbpOdontogramReviewed();

    $run = lbpOpenRun(lbpOdontogramAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpOdontogramAdapter(), $publisher, [(int) $import->getKey()]);

    $item = LegacyBatchPublishItem::sole();

    // A type confusion here would attach the attempt to the wrong archive and
    // drive the wrong module's canonical service.
    expect($item->import_type)->toBe(LegacyImportType::LEGACY_ODONTOGRAM)
        ->and($item->odontogram_legacy_import_id)->toBe($import->getKey())
        ->and($item->rme_legacy_import_id)->toBeNull()
        ->and($item->odontogram_legacy_record_id)->not->toBeNull()
        ->and($item->rme_legacy_record_id)->toBeNull();
});

it('publishes exactly once for an odontogram chart confirmed twice', function () {
    $publisher = superAdmin();
    $import = lbpOdontogramReviewed();

    $run = lbpOpenRun(lbpOdontogramAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($run, lbpOdontogramAdapter(), $publisher, [(int) $import->getKey()]);
    expect($service->publish($run->refresh(), lbpOdontogramAdapter(), $publisher)['published'])->toBe(1);
    expect($service->publish($run->refresh(), lbpOdontogramAdapter(), $publisher)['attempted'])->toBe(0);

    expect(LegacyOdontogramRecord::where('source_import_id', $import->getKey())->count())->toBe(1);
    expect(LegacyOdontogramRecord::count())->toBe(1);
});

it('keeps the two archives independent for the same patient', function () {
    // §8(D): RME and odontogram slots are per (patient, document type), so
    // publishing one must not affect the other.
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');

    $publisher = superAdmin();
    $odontogram = lbpOdontogramReviewed();

    $run = lbpOpenRun(lbpOdontogramAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpOdontogramAdapter(), $publisher, [(int) $odontogram->getKey()]);

    expect(LegacyOdontogramRecord::count())->toBe(1);
    // The RME archive is untouched.
    expect(LegacyRmeRecord::count())->toBe(0);

    // And no RME attempt row was created.
    expect(LegacyBatchPublishItem::where('import_type', LegacyImportType::LEGACY_RME)->count())->toBe(0);
});

it('never voids or deletes an odontogram archive while publishing', function () {
    $publisher = superAdmin();
    $imports = collect(range(1, 2))->map(fn (): object => lbpOdontogramReviewed());

    $run = lbpOpenRun(lbpOdontogramAdapter(), $publisher);
    lbpSelectAndPublish(
        $run, lbpOdontogramAdapter(), $publisher,
        $imports->map(fn ($i): int => (int) $i->getKey())->all(),
    );

    expect(LegacyOdontogramRecord::where('status', LegacyOdontogramRecordStatus::VOID)->count())->toBe(0);
    expect(LegacyOdontogramRecord::where('status', LegacyOdontogramRecordStatus::PUBLISHED)->count())->toBe(2);
    expect(LegacyOdontogramImport::count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| The fixture collision a full-suite CI run exposed
|--------------------------------------------------------------------------
*/

it('creates patients through both shared fixtures interleaved without colliding', function () {
    // THE BUG THIS PINS, and it reproduces the CI failure deterministically.
    //
    // `legacyRmeArchivablePatient()` (tests/Pest.php) and `lodoPatient()`
    // (tests/Feature/LegacyOdontogram/helpers.php) both minted
    // sprintf('DG-%s-2024-%04d', $branchCode, $sequence) from their OWN
    // independent `static $sequence`, both starting at the same place. In a
    // fresh process the first call to each produced the SAME number, so any
    // test creating patients through both collided on
    // mst_patients_medical_record_number_unique.
    //
    // It was masked because the counters are process-global: by the time a mixed
    // test ran, earlier tests had usually knocked them out of step. A full-suite
    // CI run hit the aligned case. This loop forces it every time.
    //
    // BEFORE the fix this test fails on the FIRST pair. It passes only because
    // the three generators now occupy disjoint blocks.
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');

    $numbers = [];

    foreach (range(1, 12) as $ignored) {
        $numbers[] = (string) legacyRmeArchivablePatient()->medical_record_number;
        $numbers[] = (string) lodoPatient()->medical_record_number;
    }

    $numbers[] = (string) lbpOdontogramReviewed()->patient->medical_record_number;

    // No duplicates at all — the property, not merely the absence of a crash.
    expect(count(array_unique($numbers)))->toBe(count($numbers));

    // And the blocks really are disjoint, so no amount of advancing can make
    // them meet.
    $rme = collect($numbers)->filter(fn (string $n): bool => (int) substr($n, -4) < 3000);
    $odo = collect($numbers)->filter(fn (string $n): bool => (int) substr($n, -4) >= 3000 && (int) substr($n, -4) < 7000);
    $batch = collect($numbers)->filter(fn (string $n): bool => (int) substr($n, -4) >= 7000);

    expect($rme)->not->toBeEmpty()
        ->and($odo)->not->toBeEmpty()
        ->and($batch)->not->toBeEmpty()
        ->and($rme->intersect($odo))->toBeEmpty()
        ->and($odo->intersect($batch))->toBeEmpty()
        ->and($rme->intersect($batch))->toBeEmpty();
});
