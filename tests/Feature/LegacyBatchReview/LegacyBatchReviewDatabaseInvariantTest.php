<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — database-level invariants.
 *
 * WHY THESE RUN ON POSTGRESQL ONLY
 * --------------------------------
 * The stickiness invariant ("at most ONE triage row per canonical import") is
 * enforced by a unique index over a NULLABLE column, which relies on NULLs
 * being treated as DISTINCT. Production is PostgreSQL; asserting the behaviour
 * on SQLite would prove nothing about production, exactly as
 * LegacySingleActiveDocumentService already documents for its advisory lock.
 *
 * So these skip on the default SQLite suite and are run deliberately against a
 * real PostgreSQL 16 server, which is the production major version.
 */

use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewItemDecision;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Models\LegacyReviewTriage;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewSessionService;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Database invariants are proven against PostgreSQL, the production engine.');
    }

    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

it('refuses a second triage row for the same canonical import', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_ILLEGIBLE,
    );

    expect(LegacyReviewTriage::count())->toBe(1);

    // A second row for the same import must be impossible at the DATABASE
    // level, not merely avoided by the service. Two reviewers racing to block
    // the same document converge on one row; they do not create two.
    //
    // THE VIOLATION IS CONTAINED IN A SAVEPOINT, deliberately. PostgreSQL
    // aborts the WHOLE transaction on any failed statement (SQLSTATE 25P02),
    // so without a nested transaction the assertion below would fail with
    // "current transaction is aborted" rather than reporting the row count —
    // a trap SQLite hides completely, because it tolerates the failed
    // statement and carries on. RefreshDatabase already holds an outer
    // transaction, so DB::transaction() here issues a SAVEPOINT and the
    // rollback is scoped to it.
    expect(fn () => DB::transaction(fn () => LegacyReviewTriage::query()->create([
        'import_type' => LegacyImportType::LEGACY_RME,
        'rme_legacy_import_id' => $import->getKey(),
        'triage_status' => LegacyReviewTriageStatus::NEEDS_ATTENTION,
        'decided_by' => $reviewer->getKey(),
        'decided_at' => now(),
    ])))->toThrow(QueryException::class);

    // The outer transaction survived, and the invariant held.
    expect(LegacyReviewTriage::count())->toBe(1);
});

it('allows one triage row per archive for the same numeric id', function () {
    // The RME index must ignore odontogram rows and vice versa: both carry NULL
    // in the other archive's column, and NULLs are distinct. If this failed, a
    // blocked RME document would make the odontogram id unusable.
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());
    $id = (int) $import->getKey();

    LegacyReviewTriage::query()->create([
        'import_type' => LegacyImportType::LEGACY_RME,
        'rme_legacy_import_id' => $id,
        'triage_status' => LegacyReviewTriageStatus::BLOCKED,
        'decided_by' => $reviewer->getKey(),
        'decided_at' => now(),
    ]);

    // An odontogram-typed row carrying no odontogram id is still permitted,
    // which is what proves the two indexes are independent.
    LegacyReviewTriage::query()->create([
        'import_type' => LegacyImportType::LEGACY_ODONTOGRAM,
        'odontogram_legacy_import_id' => null,
        'triage_status' => LegacyReviewTriageStatus::BLOCKED,
        'decided_by' => $reviewer->getKey(),
        'decided_at' => now(),
    ]);

    expect(LegacyReviewTriage::count())->toBe(2);
});

it('refuses two decisions for the same item in the same session', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );

    expect(fn () => LegacyBatchReviewItemDecision::query()->create([
        'batch_review_session_id' => $session->getKey(),
        'import_type' => LegacyImportType::LEGACY_RME,
        'rme_legacy_import_id' => $import->getKey(),
        'decision' => LegacyBatchReviewDecision::BLOCKED,
        'decided_by' => $reviewer->getKey(),
        'decided_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps a triage row when the session that raised it is deleted', function () {
    // Triage OUTLIVES its session: a block must not evaporate because an
    // operator's work-queue row was cleaned up. The FK is nullOnDelete, not
    // cascade, and this is what proves it.
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_WRONG_PATIENT,
    );

    LegacyBatchReviewSession::query()->whereKey($session->getKey())->forceDelete();

    $triage = LegacyReviewTriage::sole();

    expect($triage->isBlocking())->toBeTrue()
        ->and($triage->raised_in_session_id)->toBeNull();
});
