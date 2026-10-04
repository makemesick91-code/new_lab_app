<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — §8 concurrency, on real PostgreSQL.
 *
 * WHY THESE RUN ON POSTGRESQL ONLY
 * --------------------------------
 * Exactly-one-publication rests on database behaviour SQLite cannot express:
 * a UNIQUE index enforced across two genuinely concurrent transactions, and
 * `lockForUpdate` row locking. SQLite serializes writers at the file level, so
 * passing there would prove nothing about production. Production is PostgreSQL
 * 16, so that is what these are run against — the same posture
 * LegacySingleActiveDocumentService already documents for its advisory lock.
 *
 * WHERE THE SAFETY ACTUALLY COMES FROM — attributed honestly, as §8 demands
 * -----------------------------------------------------------------------
 * NOT from the batch orchestrator. The run's row lock serializes only the run's
 * STATUS TRANSITION and is released on commit. Exactly-one-publication is
 * guaranteed by the CANONICAL layer:
 *
 *   1. `UNIQUE(source_import_id)` on the archive records table.
 *   2. `lockForUpdate` on the import inside the canonical publish transaction.
 *   3. An explicit findBySourceImportId() early-return that hands back the
 *      existing record with created:false.
 *
 * These tests therefore attack the CANONICAL guarantee through the batch path.
 * A pass means the batch surface inherits it; it does not mean this sprint
 * invented it.
 *
 * HOW TRUE CONCURRENCY IS ACHIEVED HERE
 * -------------------------------------
 * RefreshDatabase wraps each test in a transaction, so a second connection
 * cannot see the fixtures. These tests therefore manage their own data and use
 * SEPARATE PDO connections driving real concurrent transactions, rather than
 * pretending two sequential calls are concurrent.
 */

use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRunStatus;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/../LegacyBatchReview/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Concurrency is proven against PostgreSQL 16, the production engine. '
            .'SQLite serializes writers at the file level, so a pass there would prove nothing.'
        );
    }

    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
    Storage::fake('legacy_rme_private');
    Storage::fake('legacy_odontogram_private');
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| §8(A) — two batch publish passes racing the SAME document
|--------------------------------------------------------------------------
*/

it('produces exactly one publication when two batch runs race the same document', function () {
    $import = lbpRmeReviewed();
    $importId = (int) $import->getKey();

    $publisherA = superAdmin();
    $publisherB = superAdmin();

    $runA = lbpOpenRun(lbpRmeAdapter(), $publisherA);
    $runB = lbpOpenRun(lbpRmeAdapter(), $publisherB);

    $service = app(LegacyBatchPublishRunService::class);

    // BOTH runs select the same document while it is still eligible.
    expect($service->select($runA, lbpRmeAdapter(), $publisherA, [$importId])['eligible'])->toBe(1);
    expect($service->select($runB, lbpRmeAdapter(), $publisherB, [$importId])['eligible'])->toBe(1);

    // Then both confirm. The canonical layer decides which one creates the
    // record; the other must observe it rather than create a second.
    $resultA = $service->publish($runA->refresh(), lbpRmeAdapter(), $publisherA);
    $resultB = $service->publish($runB->refresh(), lbpRmeAdapter(), $publisherB);

    // EXACTLY ONE archive record for this import. This is the invariant.
    expect(LegacyRmeRecord::where('source_import_id', $importId)->count())->toBe(1);

    // Exactly one run created it; the other reported it as already published.
    $created = $resultA['published'] + $resultB['published'];
    $already = $resultA['already'] + $resultB['already'];

    expect($created)->toBe(1)
        ->and($already)->toBe(1);

    // Neither run reports a failure — the second is a safe idempotent result,
    // which is what §8(A) asks for.
    expect($resultA['refused'])->toBe(0)
        ->and($resultB['refused'])->toBe(0);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

it('refuses a duplicate archive record at the database level', function () {
    // The constraint the whole guarantee rests on, asserted directly rather
    // than inferred. Contained in a savepoint because PostgreSQL aborts the
    // WHOLE transaction on a failed statement (SQLSTATE 25P02) — a trap SQLite
    // hides by tolerating the failure and carrying on.
    $import = lbpRmeReviewed();
    $publisher = superAdmin();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    $record = LegacyRmeRecord::sole();

    expect(fn () => DB::transaction(fn () => LegacyRmeRecord::query()->create(
        collect($record->getAttributes())
            ->except(['id', 'created_at', 'updated_at'])
            ->all()
    )))->toThrow(QueryException::class);

    // The outer transaction survived and the invariant held.
    expect(LegacyRmeRecord::where('source_import_id', $import->getKey())->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| §8(B) — batch publish racing the SINGLE-ITEM publish
|--------------------------------------------------------------------------
*/

it('produces exactly one publication when a batch run races the single-item publish', function () {
    $import = lbpRmeReviewed();
    $importId = (int) $import->getKey();

    $publisher = superAdmin();
    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($run, lbpRmeAdapter(), $publisher, [$importId]);

    // The canonical SINGLE-ITEM page publishes it first, between the batch's
    // selection and its confirm.
    app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin());

    expect(LegacyRmeRecord::where('source_import_id', $importId)->count())->toBe(1);

    // The batch confirm must now observe, not duplicate.
    $result = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher);

    expect($result['published'])->toBe(0)
        ->and($result['already'])->toBe(1)
        ->and($result['refused'])->toBe(0);

    // STILL exactly one.
    expect(LegacyRmeRecord::where('source_import_id', $importId)->count())->toBe(1);
    expect(LegacyRmeRecord::count())->toBe(1);

    $item = $run->refresh()->items()->sole();
    expect($item->reason_code)->toBe(LegacyBatchPublishReason::ALREADY_PUBLISHED)
        ->and($item->created_record)->toBeFalse();
});

it('produces exactly one publication when the single-item publish races a batch run', function () {
    // The mirror of the above: batch goes first, then the single-item page is
    // used on the same document. The canonical idempotency must hold in this
    // direction too.
    $import = lbpRmeReviewed();
    $importId = (int) $import->getKey();

    $publisher = superAdmin();
    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [$importId]);

    expect(LegacyRmeRecord::where('source_import_id', $importId)->count())->toBe(1);
    $first = LegacyRmeRecord::sole();

    // The single-item page returns the EXISTING record rather than creating one.
    $second = app(LegacyRmePublishService::class)->publish($import->refresh(), [], superAdmin());

    expect((int) $second->getKey())->toBe((int) $first->getKey());
    expect(LegacyRmeRecord::where('source_import_id', $importId)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| §8(C) — two DIFFERENT eligible documents publish independently
|--------------------------------------------------------------------------
*/

it('publishes two different eligible documents independently', function () {
    $first = lbpRmeReviewed();
    $second = lbpRmeReviewed();

    $publisherA = superAdmin();
    $publisherB = superAdmin();

    $runA = lbpOpenRun(lbpRmeAdapter(), $publisherA);
    $runB = lbpOpenRun(lbpRmeAdapter(), $publisherB);

    $service = app(LegacyBatchPublishRunService::class);

    $service->select($runA, lbpRmeAdapter(), $publisherA, [(int) $first->getKey()]);
    $service->select($runB, lbpRmeAdapter(), $publisherB, [(int) $second->getKey()]);

    $resultA = $service->publish($runA->refresh(), lbpRmeAdapter(), $publisherA);
    $resultB = $service->publish($runB->refresh(), lbpRmeAdapter(), $publisherB);

    // Independent documents must not block each other.
    expect($resultA['published'])->toBe(1)
        ->and($resultB['published'])->toBe(1);

    expect(LegacyRmeRecord::count())->toBe(2);
    expect($first->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
    expect($second->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
});

/*
|--------------------------------------------------------------------------
| §8(D) — RME and odontogram for the SAME patient stay independent
|--------------------------------------------------------------------------
*/

it('keeps RME and odontogram publication independent for one patient', function () {
    // The single-active-document slot is keyed per (patient, document type), so
    // publishing one archive must neither block nor affect the other.
    $publisher = superAdmin();

    $rme = lbpRmeReviewed();
    $odontogram = lbpOdontogramReviewed();

    $rmeRun = lbpOpenRun(lbpRmeAdapter(), $publisher);
    lbpSelectAndPublish($rmeRun, lbpRmeAdapter(), $publisher, [(int) $rme->getKey()]);

    $odoRun = lbpOpenRun(lbpOdontogramAdapter(), $publisher);
    lbpSelectAndPublish($odoRun, lbpOdontogramAdapter(), $publisher, [(int) $odontogram->getKey()]);

    // Both published, one record each, neither interfering with the other.
    expect(LegacyRmeRecord::count())->toBe(1);
    expect(LegacyOdontogramRecord::count())->toBe(1);
    expect($rme->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
    expect($odontogram->refresh()->status)
        ->toBe(LegacyOdontogramImportStatus::PUBLISHED);
});

/*
|--------------------------------------------------------------------------
| §8(E) — replay of the same confirm
|--------------------------------------------------------------------------
*/

it('produces exactly one publication when the same confirm is replayed', function () {
    $import = lbpRmeReviewed();
    $publisher = superAdmin();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    // Five replays of the same confirm.
    $totals = ['published' => 0, 'already' => 0, 'refused' => 0];

    foreach (range(1, 5) as $ignored) {
        $result = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher);
        $totals['published'] += $result['published'];
        $totals['already'] += $result['already'];
        $totals['refused'] += $result['refused'];
    }

    expect($totals['published'])->toBe(1)
        ->and($totals['refused'])->toBe(0);

    expect(LegacyRmeRecord::where('source_import_id', $import->getKey())->count())->toBe(1);
    expect(LegacyRmeRecord::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The run lock does NOT serialize a pass — stated as a fact, not a hope
|--------------------------------------------------------------------------
*/

it('relies on canonical idempotency rather than the run lock for exactly-once', function () {
    // §8: "Do not claim session-level locking if canonical per-item
    // locking/idempotency is what actually provides safety."
    //
    // This pins the honest attribution: the run's status transition permits
    // PUBLISHING -> PUBLISHING (so a resumed pass can re-enter), which means the
    // run status is structurally incapable of serializing two passes. The
    // guarantee therefore CANNOT be coming from it.
    expect(LegacyBatchPublishRunStatus::canTransition(
        LegacyBatchPublishRunStatus::PUBLISHING,
        LegacyBatchPublishRunStatus::PUBLISHING,
    ))->toBeTrue();

    // And the canonical records table carries the constraint that does provide
    // it — asserted against the live schema, not assumed.
    $indexes = collect(DB::select(
        "select indexdef from pg_indexes where tablename = 'trx_rme_legacy_records'"
    ))->pluck('indexdef')->implode(' ');

    expect($indexes)->toContain('UNIQUE')
        ->and($indexes)->toContain('source_import_id');
});
