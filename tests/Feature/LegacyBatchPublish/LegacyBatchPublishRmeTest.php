<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR2) — batch publish, legacy RME.
 *
 * WHAT THIS SUITE IS FOR
 * ----------------------
 * Publishing is the irreversible half. These tests pin the properties that make
 * it safe to do in bulk: only REVIEWED documents are reachable, PR1's triage
 * annotations are absolute, every document is re-validated immediately before
 * its own publish, one refusal never rolls back its neighbours, a publication
 * happens exactly once, and an interrupted run resumes without duplicating.
 *
 * The most important assertions here are the ones that prove batch publish
 * cannot do something the single-item page cannot: no REVIEWED requirement
 * bypass, no triage bypass, no separation-of-duties bypass.
 */

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyImport\BatchPublish\Controllers\LegacyBatchPublishController;
use App\Modules\LegacyImport\BatchPublish\Models\LegacyBatchPublishItem;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishAuditService;
use App\Modules\LegacyImport\BatchPublish\Services\LegacyBatchPublishRunService;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishItemStatus;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishReason;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRunStatus;
use App\Modules\LegacyImport\BatchReview\Services\LegacyReviewTriageService;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmePublishService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/../LegacyBatchReview/helpers.php';
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| The happy path
|--------------------------------------------------------------------------
*/

it('publishes every eligible reviewed document in one operator action', function () {
    $publisher = superAdmin();
    $imports = collect(range(1, 3))->map(fn (): object => lbpRmeReviewed());

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    $result = lbpSelectAndPublish(
        $run, lbpRmeAdapter(), $publisher,
        $imports->map(fn ($i): int => (int) $i->getKey())->all(),
    );

    expect($result['select']['eligible'])->toBe(3)
        ->and($result['publish']['published'])->toBe(3)
        ->and($result['publish']['refused'])->toBe(0);

    // Each one produced its own immutable archive record.
    expect(LegacyRmeRecord::count())->toBe(3);

    $imports->each(function ($import): void {
        $import->refresh();
        expect($import->status)->toBe(LegacyRmeImportStatus::PUBLISHED)
            ->and($import->published_by)->not->toBeNull();

        $record = LegacyRmeRecord::where('source_import_id', $import->getKey())->sole();
        expect($record->status)->toBe(LegacyRmeRecordStatus::PUBLISHED);
    });

    expect($run->refresh()->status)->toBe(LegacyBatchPublishRunStatus::COMPLETED);
});

it('publishes only the subset the operator selected', function () {
    $publisher = superAdmin();
    $chosen = lbpRmeReviewed();
    $ignored = lbpRmeReviewed();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $chosen->getKey()]);

    expect($chosen->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
    // Untouched: a document nobody selected is never published.
    expect($ignored->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
    expect(LegacyRmeRecord::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Partial success — §4
|--------------------------------------------------------------------------
*/

it('publishes the eligible documents and refuses only the ones the domain rejects', function () {
    $publisher = superAdmin();

    $goodA = lbpRmeReviewed();
    // Uploaded BY the publisher, so separation of duties must refuse it at
    // publish while its neighbours proceed.
    $sodBlocked = lbpRmeReviewed($publisher);
    $goodB = lbpRmeReviewed();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    $result = lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [
        (int) $goodA->getKey(), (int) $sodBlocked->getKey(), (int) $goodB->getKey(),
    ]);

    // The SOD refusal is caught at selection time by the pre-flight, so it is
    // never attempted — which is the design working, not a miss.
    expect($result['select']['eligible'])->toBe(2)
        ->and($result['select']['refused'])->toBe(1);

    expect($result['publish']['published'])->toBe(2);

    // The two good ones really are published — NOT rolled back by the refusal.
    expect($goodA->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
    expect($goodB->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED);
    expect(LegacyRmeRecord::count())->toBe(2);

    // The refused one did not move, and says why in a stable code.
    expect($sodBlocked->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);

    $refused = LegacyBatchPublishItem::where('rme_legacy_import_id', $sodBlocked->getKey())->sole();
    expect($refused->status)->toBe(LegacyBatchPublishItemStatus::REFUSED)
        ->and($refused->reason_code)->toBe(LegacyBatchPublishReason::SOD_REFUSED);

    expect($run->refresh()->status)->toBe(LegacyBatchPublishRunStatus::COMPLETED_WITH_REFUSALS);
});

it('reports a refusal rather than silently skipping it', function () {
    // §4: "Do NOT silently skip the 3 without reporting them."
    $publisher = superAdmin();
    $sodBlocked = lbpRmeReviewed($publisher);

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $sodBlocked->getKey()]);

    // A row EXISTS for the refused document, with a reason an operator can read.
    $item = LegacyBatchPublishItem::sole();
    expect($item->status)->toBe(LegacyBatchPublishItemStatus::REFUSED)
        ->and($item->reason_code)->toBe(LegacyBatchPublishReason::SOD_REFUSED)
        ->and($item->reason_message)->not->toBeNull();

    expect($run->refresh()->refused_count)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Only REVIEWED is reachable
|--------------------------------------------------------------------------
*/

it('refuses a document that has not been reviewed', function () {
    $publisher = superAdmin();
    // READY_FOR_REVIEW, never reviewed.
    $unreviewed = lbrRmeReady(superAdmin());

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $result = lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $unreviewed->getKey()]);

    expect($result['select']['eligible'])->toBe(0)
        ->and($result['publish']['published'])->toBe(0);

    expect(LegacyBatchPublishItem::sole()->reason_code)
        ->toBe(LegacyBatchPublishReason::NOT_REVIEWED);

    expect($unreviewed->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses a document whose review was undone between selection and publish', function () {
    // The stale-selection case §6 exists for: eligible at selection, not at
    // publish. Proven by cancelling the import in between.
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $select = $service->select($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);
    expect($select['eligible'])->toBe(1);

    // The world moves on.
    $import->forceFill(['status' => LegacyRmeImportStatus::CANCELLED])->save();

    $publish = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher);

    expect($publish['published'])->toBe(0)
        ->and($publish['refused'])->toBe(1);

    // The item's STATUS is asserted, not only its reason code. The publish path
    // writes refusals through markItemRefused(), a different writer from the
    // selection path, and a mutation run showed that checking only the reason
    // code let a mutant mark a refused item PUBLISHED and survive.
    $item = LegacyBatchPublishItem::sole();
    expect($item->status)->toBe(LegacyBatchPublishItemStatus::REFUSED)
        ->and($item->reason_code)->toBe(LegacyBatchPublishReason::NOT_REVIEWED)
        ->and($item->recordId())->toBeNull()
        ->and($item->created_record)->toBeFalse();

    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| PR1's triage contract is absolute — §15
|--------------------------------------------------------------------------
*/

it('refuses a BLOCKED document and never clears the block', function () {
    $publisher = superAdmin();

    [$import, $triage] = lbpRmeReviewedButBlocked(
        LegacyBatchReviewDecision::BLOCKED,
        LegacyBatchReviewReason::TRIAGE_WRONG_PATIENT,
    );

    // The state that makes this test worth having: REVIEWED *and* withheld.
    expect($import->status)->toBe(LegacyRmeImportStatus::REVIEWED)
        ->and($triage->isBlocking())->toBeTrue();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $result = lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    expect($result['select']['eligible'])->toBe(0)
        ->and($result['publish']['published'])->toBe(0);

    expect(LegacyBatchPublishItem::sole()->reason_code)
        ->toBe(LegacyBatchPublishReason::TRIAGE_BLOCKED);

    // Nothing published, and the block SURVIVES — batch publish has no
    // clear-and-publish shortcut.
    expect(LegacyRmeRecord::count())->toBe(0);
    expect($triage->refresh()->isBlocking())->toBeTrue();
    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

it('refuses a NEEDS_ATTENTION document too', function () {
    $publisher = superAdmin();

    [$import, $triage] = lbpRmeReviewedButBlocked(
        LegacyBatchReviewDecision::NEEDS_ATTENTION,
        LegacyBatchReviewReason::TRIAGE_NEEDS_CLARIFICATION,
    );

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    expect(LegacyBatchPublishItem::sole()->reason_code)
        ->toBe(LegacyBatchPublishReason::TRIAGE_BLOCKED);
    expect(LegacyRmeRecord::count())->toBe(0);
    expect($triage->refresh()->isBlocking())->toBeTrue();
});

it('refuses a document blocked AFTER it was selected', function () {
    // §6 again: the triage re-check happens immediately before publish, not
    // only at selection. Here the document is eligible when selected and
    // withheld by the time confirm is pressed.
    $publisher = superAdmin();
    $blocker = superAdmin();
    $import = lbrRmeReady(superAdmin());

    // Reviewed first, so selection sees an eligible document.
    app(LegacyRmePublishService::class)
        ->review($import, superAdmin());

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    expect($service->select($run, lbpRmeAdapter(), $publisher, [(int) $import->refresh()->getKey()])['eligible'])
        ->toBe(1);

    // A reviewer withholds it between selection and confirm. Raised directly
    // through the canonical triage service, because PR1's review workspace only
    // accepts a READY_FOR_REVIEW document and this one is already REVIEWED.
    app(LegacyReviewTriageService::class)->raise(
        lbrRmeAdapter(),
        $import->refresh(),
        $blocker,
        LegacyReviewTriageStatus::BLOCKED,
        LegacyBatchReviewReason::TRIAGE_ILLEGIBLE,
    );

    $publish = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher);

    expect($publish['published'])->toBe(0)
        ->and($publish['refused'])->toBe(1);

    // Status asserted alongside the reason, for the same reason as above.
    $item = LegacyBatchPublishItem::sole();
    expect($item->status)->toBe(LegacyBatchPublishItemStatus::REFUSED)
        ->and($item->reason_code)->toBe(LegacyBatchPublishReason::TRIAGE_BLOCKED)
        ->and($item->recordId())->toBeNull();

    expect(LegacyRmeRecord::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Exactly one publication — §8
|--------------------------------------------------------------------------
*/

it('publishes exactly once when the same run is confirmed twice', function () {
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);
    $first = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher);

    expect($first['published'])->toBe(1);

    // A double-clicked confirm. Nothing attemptable remains, so this is a
    // harmless no-op returned before any status transition.
    $second = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher);

    expect($second['attempted'])->toBe(0)
        ->and($second['published'])->toBe(0);

    // EXACTLY ONE archive record.
    expect(LegacyRmeRecord::where('source_import_id', $import->getKey())->count())->toBe(1);
    expect(LegacyRmeRecord::count())->toBe(1);
});

it('reports an already-published document as already published, not as a new publication', function () {
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    // Published through the CANONICAL single-item path first.
    app(LegacyRmePublishService::class)
        ->publish($import, [], superAdmin());

    expect(LegacyRmeRecord::count())->toBe(1);

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $result = lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    // Benign: the document IS in the archive, so this is not a failure — but it
    // is NOT counted as a fresh publication either.
    expect($result['publish']['published'])->toBe(0);

    $item = LegacyBatchPublishItem::sole();
    expect($item->reason_code)->toBe(LegacyBatchPublishReason::ALREADY_PUBLISHED)
        ->and($item->created_record)->toBeFalse();

    // Still exactly one record.
    expect(LegacyRmeRecord::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Interruption and resume — §9
|--------------------------------------------------------------------------
*/

it('resumes after an interruption without re-publishing what already published', function () {
    $publisher = superAdmin();
    $imports = collect(range(1, 3))->map(fn (): object => lbpRmeReviewed());
    $ids = $imports->map(fn ($i): int => (int) $i->getKey())->all();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($run, lbpRmeAdapter(), $publisher, $ids);

    // A bounded first pass, as if the browser died after one document.
    $first = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher, [], 1);

    expect($first['published'])->toBe(1)
        ->and($first['remaining'])->toBe(2);
    expect(LegacyRmeRecord::count())->toBe(1);
    // Not terminal — the operator can resume.
    expect($run->refresh()->status)->toBe(LegacyBatchPublishRunStatus::PUBLISHING);

    // Resuming picks up exactly what was never attempted.
    $second = $service->publish($run->refresh(), lbpRmeAdapter(), $publisher, [], 10);

    expect($second['attempted'])->toBe(2)
        ->and($second['published'])->toBe(2);

    // Three documents, three records. No duplicate from the resume.
    expect(LegacyRmeRecord::count())->toBe(3);
    expect(LegacyBatchPublishItem::where('status', LegacyBatchPublishItemStatus::PUBLISHED)->count())->toBe(3);

    // A third pass finds nothing rather than redoing work.
    expect($service->publish($run->refresh(), lbpRmeAdapter(), $publisher)['attempted'])->toBe(0);
});

it('keeps published documents published when the run is abandoned', function () {
    // §9: "Do not require rollback of successful published items."
    $publisher = superAdmin();
    $published = lbpRmeReviewed();
    $untouched = lbpRmeReviewed();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($run, lbpRmeAdapter(), $publisher, [
        (int) $published->getKey(), (int) $untouched->getKey(),
    ]);
    $service->publish($run->refresh(), lbpRmeAdapter(), $publisher, [], 1);

    $service->abandon($run->refresh(), lbpRmeAdapter(), $publisher);

    expect($run->refresh()->status)->toBe(LegacyBatchPublishRunStatus::ABANDONED);

    // The published one stays published; the un-attempted one stays REVIEWED
    // and publishable in a future run.
    expect(LegacyRmeRecord::count())->toBe(1);
    expect($untouched->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
});

/*
|--------------------------------------------------------------------------
| Ownership and type isolation
|--------------------------------------------------------------------------
*/

it('refuses to let another publisher drive someone else\'s run', function () {
    $owner = superAdmin();
    $intruder = superAdmin();
    $import = lbpRmeReviewed();

    $run = lbpOpenRun(lbpRmeAdapter(), $owner);

    expect(fn () => app(LegacyBatchPublishRunService::class)
        ->select($run, lbpRmeAdapter(), $intruder, [(int) $import->getKey()]))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(LegacyBatchPublishRunService::class)
        ->publish($run, lbpRmeAdapter(), $intruder))
        ->toThrow(AuthorizationException::class);
});

it('refuses to drive an RME run through the odontogram adapter', function () {
    // Both capabilities ON so the import-type guard is the only thing that can
    // refuse — the lesson PR1's mutation run taught.
    lodoFlag(true);

    $publisher = superAdmin();
    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    expect(lbpRmeAdapter()->migrationEnabled())->toBeTrue()
        ->and(lbpOdontogramAdapter()->migrationEnabled())->toBeTrue();

    expect(fn () => app(LegacyBatchPublishRunService::class)
        ->publish($run, lbpOdontogramAdapter(), $publisher))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(LegacyBatchPublishRunService::class)
        ->select($run, lbpOdontogramAdapter(), $publisher, [1]))
        ->toThrow(AuthorizationException::class);

    expect(LegacyBatchPublishItem::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Audit — §14
|--------------------------------------------------------------------------
*/

it('audits the batch with counts and references while the canonical publish stays authoritative', function () {
    $publisher = superAdmin();
    $import = lbpRmeReviewed();

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    lbpSelectAndPublish($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    $requested = AuditLog::where('action', LegacyBatchPublishAuditService::PUBLISH_REQUESTED)->sole();
    $completed = AuditLog::where('action', LegacyBatchPublishAuditService::PUBLISH_COMPLETED)->sole();

    expect((array) $requested->new_values)->toHaveKey('run_uuid');

    $payload = (array) $completed->new_values;
    expect($payload['published_count'])->toBe(1)
        ->and($payload)->toHaveKey('refusal_counts');

    // §14: counts and references, never clinical content or operator prose.
    expect($payload)->not->toHaveKey('reason_message')
        ->and($payload)->not->toHaveKey('patient_name');
    expect(json_encode($payload))->not->toContain($import->patient->name);

    // The canonical publish event remains the authoritative clinical record,
    // and it carries the BATCH channel so an auditor can tell which surface
    // asked.
    $canonical = AuditLog::where('action', LegacyRmeAuditEvent::PUBLISHED)->sole();
    expect(((array) $canonical->new_values)['channel'])->toBe('BATCH');
});

/*
|--------------------------------------------------------------------------
| What batch publish must never do
|--------------------------------------------------------------------------
*/

it('never voids, deletes or rewrites anything while publishing', function () {
    $publisher = superAdmin();
    $imports = collect(range(1, 2))->map(fn (): object => lbpRmeReviewed());

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    lbpSelectAndPublish(
        $run, lbpRmeAdapter(), $publisher,
        $imports->map(fn ($i): int => (int) $i->getKey())->all(),
    );

    // No auto-VOID: every record created is PUBLISHED, none VOID.
    expect(LegacyRmeRecord::where('status', LegacyRmeRecordStatus::VOID)->count())->toBe(0);
    expect(LegacyRmeRecord::where('status', LegacyRmeRecordStatus::PUBLISHED)->count())->toBe(2);

    // No deletes: both staging rows survive, terminal and intact.
    expect(LegacyRmeImport::count())->toBe(2);
    $imports->each(fn ($i) => expect($i->refresh()->status)->toBe(LegacyRmeImportStatus::PUBLISHED));
});

it('bounds a selection so one request cannot be unbounded', function () {
    // §20: no unbounded orchestration, and §19's denial-of-service concern.
    $publisher = superAdmin();
    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    $tooMany = range(1, LegacyBatchPublishRunService::MAX_SELECTION + 1);

    expect(fn () => app(LegacyBatchPublishRunService::class)
        ->select($run, lbpRmeAdapter(), $publisher, $tooMany))
        ->toThrow(ValidationException::class);

    expect(LegacyBatchPublishItem::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Hardening found by the adversarial security review
|--------------------------------------------------------------------------
*/

it('lets only one attempt row claim authorship of a single publication', function () {
    // The canonical layer guarantees one RECORD, but not that two runs racing it
    // both stop believing they created it. Two interleaved passes can each
    // observe "no record yet" before either canonical transaction commits, and
    // would then both write created_record = true for ONE publication —
    // inflating the audit trail and the operator's published count while the
    // archive itself stayed correct.
    //
    // Authorship is therefore arbitrated against the attempt table: at most one
    // row across ALL runs may hold it.
    $import = lbpRmeReviewed();
    $importId = (int) $import->getKey();

    $publisherA = superAdmin();
    $publisherB = superAdmin();

    $runA = lbpOpenRun(lbpRmeAdapter(), $publisherA);
    $runB = lbpOpenRun(lbpRmeAdapter(), $publisherB);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($runA, lbpRmeAdapter(), $publisherA, [$importId]);
    $service->select($runB, lbpRmeAdapter(), $publisherB, [$importId]);

    $service->publish($runA->refresh(), lbpRmeAdapter(), $publisherA);
    $service->publish($runB->refresh(), lbpRmeAdapter(), $publisherB);

    // One record, and EXACTLY ONE attempt row claiming to have created it.
    expect(LegacyRmeRecord::where('source_import_id', $importId)->count())->toBe(1);
    expect(
        LegacyBatchPublishItem::where('rme_legacy_import_id', $importId)
            ->where('created_record', true)
            ->count()
    )->toBe(1);

    // Both rows agree the document IS published.
    expect(
        LegacyBatchPublishItem::where('rme_legacy_import_id', $importId)
            ->where('status', LegacyBatchPublishItemStatus::PUBLISHED)
            ->count()
    )->toBe(2);
});

it('records an already-published document as published, not as a refusal, at selection time', function () {
    // publish() treated a benign ALREADY_PUBLISHED verdict as "nothing to do",
    // but select() sent it down the generic refused path — so the operator was
    // told "N ditolak setelah pemeriksaan ulang" for a condition the reason
    // vocabulary itself declares benign. The two paths now agree.
    $import = lbpRmeReviewed();

    app(LegacyRmePublishService::class)
        ->publish($import, [], superAdmin());

    $publisher = superAdmin();
    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);

    $summary = app(LegacyBatchPublishRunService::class)
        ->select($run, lbpRmeAdapter(), $publisher, [(int) $import->getKey()]);

    expect($summary['already'])->toBe(1)
        ->and($summary['refused'])->toBe(0)
        ->and($summary['eligible'])->toBe(0);

    $item = LegacyBatchPublishItem::sole();
    expect($item->status)->toBe(LegacyBatchPublishItemStatus::PUBLISHED)
        ->and($item->created_record)->toBeFalse()
        ->and($item->reason_code)->toBe(LegacyBatchPublishReason::ALREADY_PUBLISHED);

    // Still exactly one archive record.
    expect(LegacyRmeRecord::count())->toBe(1);
});

it('refuses to resurrect a run the operator abandoned mid-pass', function () {
    // finalize() wrote the run status without consulting the transition map, so
    // an in-flight pass could finish after abandon() and overwrite a TERMINAL
    // status with COMPLETED — reporting a terminated run as completed.
    $publisher = superAdmin();
    $imports = collect(range(1, 2))->map(fn (): object => lbpRmeReviewed());

    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    $service->select($run, lbpRmeAdapter(), $publisher, $imports->map(fn ($i): int => (int) $i->getKey())->all());

    // Publish one, leaving the run mid-pass.
    $service->publish($run->refresh(), lbpRmeAdapter(), $publisher, [], 1);
    expect($run->refresh()->status)->toBe(LegacyBatchPublishRunStatus::PUBLISHING);

    // The operator abandons it.
    $service->abandon($run->refresh(), lbpRmeAdapter(), $publisher);
    expect($run->refresh()->status)->toBe(LegacyBatchPublishRunStatus::ABANDONED);

    // A further pass must not move it out of a terminal state.
    $service->publish($run->refresh(), lbpRmeAdapter(), $publisher);

    expect($run->refresh()->status)->toBe(LegacyBatchPublishRunStatus::ABANDONED);

    // And the already-published document stays published — abandoning never
    // un-publishes anything.
    expect(LegacyRmeRecord::count())->toBe(1);
});

it('bounds a run\'s cumulative outstanding set across several selections', function () {
    // MAX_SELECTION bounds ONE request. Without a cumulative bound, N requests
    // would accumulate N x MAX_SELECTION pending items in a single run.
    $publisher = superAdmin();
    $run = lbpOpenRun(lbpRmeAdapter(), $publisher);
    $service = app(LegacyBatchPublishRunService::class);

    // Per-request bound, matched to what one page can actually render.
    expect(LegacyBatchPublishRunService::MAX_SELECTION)
        ->toBe(LegacyBatchPublishController::MAX_PER_PAGE);

    expect(fn () => $service->select(
        $run, lbpRmeAdapter(), $publisher,
        range(1, LegacyBatchPublishRunService::MAX_SELECTION + 1)
    ))->toThrow(ValidationException::class);

    // And the cumulative bound exists and is larger than one request.
    expect(LegacyBatchPublishRunService::MAX_OUTSTANDING)
        ->toBeGreaterThan(LegacyBatchPublishRunService::MAX_SELECTION);

    expect(LegacyBatchPublishItem::count())->toBe(0);
});
