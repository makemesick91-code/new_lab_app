<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — batch review, legacy RME.
 *
 * WHAT THIS SUITE IS FOR
 * ----------------------
 * Batch review makes a slow operator task fast. These tests pin the properties
 * that make it SAFE to be fast: review stays per item, every attestation is its
 * own record, the canonical gates still run per document (separation of duties
 * included), one refusal never contaminates its neighbours, and "Blocked"
 * destroys nothing.
 *
 * The single most important assertion in this file is the one that proves there
 * is no way to mark a queue reviewed without attesting each document — §1, §13
 * and §24 all reduce to that.
 */

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewItemDecision;
use App\Modules\LegacyImport\BatchReview\Models\LegacyReviewTriage;
use App\Modules\LegacyImport\BatchReview\Requests\RecordLegacyBatchReviewDecisionRequest;
use App\Modules\LegacyImport\BatchReview\Requests\SubmitLegacyBatchReviewRequest;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewAuditService;
use App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewSessionService;
use App\Modules\LegacyImport\BatchReview\Services\LegacyReviewTriageService;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSessionStatus;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSubmitStatus;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    Storage::fake('legacy_rme_private');
    Bus::fake();
});

/*
|--------------------------------------------------------------------------
| The product rule: human review per item
|--------------------------------------------------------------------------
*/

it('exposes no vocabulary for marking a whole queue reviewed', function () {
    // §1/§13/§24 as a STRUCTURAL assertion rather than a UI convention: the
    // decision vocabulary has no "all" value, and the request that records an
    // attestation names exactly one import.
    expect(LegacyBatchReviewDecision::all())
        ->toBe(['REVIEWED', 'BLOCKED', 'NEEDS_ATTENTION']);

    $rules = (new RecordLegacyBatchReviewDecisionRequest)->rules();

    expect($rules)->toHaveKey('import_id')
        ->and($rules)->not->toHaveKey('import_ids')
        ->and($rules)->not->toHaveKey('all')
        ->and($rules)->not->toHaveKey('select_all');

    // And the submit request carries no item list at all — the server submits
    // what it already recorded, never what a client claims.
    $submitRules = (new SubmitLegacyBatchReviewRequest)->rules();

    expect(array_keys($submitRules))->toBe(['limit']);
});

it('submits nothing for a document the reviewer never attested', function () {
    $uploader = superAdmin();
    $reviewer = superAdmin();

    $attested = lbrRmeReady($uploader);
    $untouched = lbrRmeReady($uploader);

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer,
        (int) $attested->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );

    $summary = app(LegacyBatchReviewSessionService::class)
        ->submit($session, lbrRmeAdapter(), $reviewer);

    expect($summary['applied'])->toBe(1);

    // The attested one moved. The one nobody looked at did not.
    expect($attested->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
    expect($untouched->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
    expect($untouched->reviewed_by)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Item-level truth
|--------------------------------------------------------------------------
*/

it('records every attestation as its own row identifying the document it refers to', function () {
    $uploader = superAdmin();
    $reviewer = superAdmin();
    $import = lbrRmeReady($uploader);

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    $decision = app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );

    // §4: batch, item, patient, type, source SHA, actor, timestamp, decision.
    expect($decision->batch_review_session_id)->toBe($session->getKey())
        ->and($decision->import_type)->toBe(LegacyImportType::LEGACY_RME)
        ->and($decision->rme_legacy_import_id)->toBe($import->getKey())
        ->and($decision->odontogram_legacy_import_id)->toBeNull()
        ->and($decision->patient_id)->toBe($import->patient_id)
        ->and($decision->source_sha256)->toBe($import->source_pdf_sha256)
        ->and($decision->decided_by)->toBe($reviewer->getKey())
        ->and($decision->decided_at)->not->toBeNull()
        ->and($decision->decision)->toBe(LegacyBatchReviewDecision::REVIEWED);
});

it('keeps one decision per item per session when a reviewer changes their mind', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    $service = app(LegacyBatchReviewSessionService::class);

    $service->recordDecision(
        $session, lbrRmeAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_ILLEGIBLE,
    );

    $service->recordDecision(
        $session, lbrRmeAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::REVIEWED,
    );

    expect(LegacyBatchReviewItemDecision::where('batch_review_session_id', $session->getKey())->count())->toBe(1);
    expect(LegacyBatchReviewItemDecision::first()->decision)->toBe(LegacyBatchReviewDecision::REVIEWED);
});

/*
|--------------------------------------------------------------------------
| Partial success — one refusal must not contaminate its neighbours
|--------------------------------------------------------------------------
*/

it('applies the reviewable documents and refuses only the one the domain rejects', function () {
    $reviewer = superAdmin();

    $goodA = lbrRmeReady(superAdmin());
    // Separation of duties: this one was uploaded BY the reviewer, so the
    // canonical path must refuse it while its neighbours proceed.
    $sodBlocked = lbrRmeReady($reviewer);
    $goodB = lbrRmeReady(superAdmin());

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    $service = app(LegacyBatchReviewSessionService::class);

    foreach ([$goodA, $sodBlocked, $goodB] as $import) {
        $service->recordDecision(
            $session, lbrRmeAdapter(), $reviewer,
            (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
        );
    }

    $summary = $service->submit($session, lbrRmeAdapter(), $reviewer);

    expect($summary['attempted'])->toBe(3)
        ->and($summary['applied'])->toBe(2)
        ->and($summary['refused'])->toBe(1);

    // The two good ones really are reviewed — NOT rolled back by the refusal.
    expect($goodA->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
    expect($goodB->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);

    // The refused one did not move, and says why in a stable code.
    expect($sodBlocked->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);

    $refused = LegacyBatchReviewItemDecision::where('rme_legacy_import_id', $sodBlocked->getKey())->sole();

    expect($refused->submit_status)->toBe(LegacyBatchReviewSubmitStatus::REFUSED)
        ->and($refused->refusal_code)->toBe(LegacyBatchReviewReason::REFUSAL_SEPARATION_OF_DUTIES);

    // The session reports partial success as partial success.
    expect($session->refresh()->status)->toBe(LegacyBatchReviewSessionStatus::SUBMITTED_WITH_REFUSALS);
});

it('resumes after an interruption without re-reviewing what already applied', function () {
    $reviewer = superAdmin();
    $imports = collect(range(1, 3))->map(fn (): object => lbrRmeReady(superAdmin()));

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    $service = app(LegacyBatchReviewSessionService::class);

    foreach ($imports as $import) {
        $service->recordDecision(
            $session, lbrRmeAdapter(), $reviewer,
            (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
        );
    }

    // A bounded first pass, as if the browser died after one document.
    $first = $service->submit($session, lbrRmeAdapter(), $reviewer, 1);

    expect($first['applied'])->toBe(1);
    expect($session->refresh()->status)->toBe(LegacyBatchReviewSessionStatus::SUBMITTING);

    // Resuming picks up exactly what was never applied.
    $second = $service->submit($session->refresh(), lbrRmeAdapter(), $reviewer, 10);

    expect($second['attempted'])->toBe(2)
        ->and($second['applied'])->toBe(2);

    expect(LegacyBatchReviewItemDecision::where('submit_status', LegacyBatchReviewSubmitStatus::APPLIED)->count())->toBe(3);

    // And a third pass finds nothing left to do rather than redoing work.
    $third = $service->submit($session->refresh(), lbrRmeAdapter(), $reviewer, 10);

    expect($third['attempted'])->toBe(0)
        ->and($third['applied'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Blocked is sticky, non-destructive and never published
|--------------------------------------------------------------------------
*/

it('blocks a document without touching its canonical lifecycle or its slot', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());
    $patientId = (int) $import->patient_id;

    $slotBefore = app(LegacySingleActiveDocumentService::class)
        ->occupancyFor(LegacyImportType::LEGACY_RME, $patientId);

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED,
        LegacyBatchReviewReason::TRIAGE_WRONG_PATIENT,
        'Nomor RM pada dokumen berbeda.',
    );

    // THE CANONICAL LIFECYCLE IS UNTOUCHED — not CANCELLED, not REVIEWED.
    $import->refresh();
    expect($import->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW)
        ->and($import->reviewed_by)->toBeNull()
        ->and($import->cancelled_by)->toBeNull();

    // Triage is the SECOND axis, carrying reviewer, reason and timestamp.
    $triage = LegacyReviewTriage::sole();
    expect($triage->triage_status)->toBe(LegacyReviewTriageStatus::BLOCKED)
        ->and($triage->reason_code)->toBe(LegacyBatchReviewReason::TRIAGE_WRONG_PATIENT)
        ->and($triage->decided_by)->toBe($reviewer->getKey())
        ->and($triage->decided_at)->not->toBeNull()
        ->and($triage->isBlocking())->toBeTrue();

    // THE SLOT IS STILL HELD. Blocking must never free a patient's single
    // active document, or a second upload could slip in behind it.
    $slotAfter = app(LegacySingleActiveDocumentService::class)
        ->occupancyFor(LegacyImportType::LEGACY_RME, $patientId);

    expect($slotAfter->occupied)->toBeTrue()
        ->and($slotAfter->occupied)->toBe($slotBefore->occupied);

    // And nothing was published.
    expect(LegacyRmeRecord::count())->toBe(0);
});

it('refuses a block without a reason, and a free-text reason it never offered', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    $service = app(LegacyBatchReviewSessionService::class);

    expect(fn () => $service->recordDecision(
        $session, lbrRmeAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, null,
    ))->toThrow(ValidationException::class);

    // A crafted code the UI never offered is refused, so the closed list is a
    // real boundary rather than a dropdown convention.
    expect(fn () => $service->recordDecision(
        $session, lbrRmeAdapter(), $reviewer, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, 'TRIAGE_MADE_UP_CODE',
    ))->toThrow(ValidationException::class);

    expect(LegacyReviewTriage::count())->toBe(0);
    expect(LegacyBatchReviewItemDecision::count())->toBe(0);
});

it('never submits a blocked document even when it was attested reviewed earlier', function () {
    $reviewer = superAdmin();
    $blocker = superAdmin();
    $import = lbrRmeReady(superAdmin());
    $service = app(LegacyBatchReviewSessionService::class);

    // Reviewer A attests it reviewed.
    $sessionA = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    $service->recordDecision(
        $sessionA, lbrRmeAdapter(), $reviewer,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );

    // Reviewer B blocks it in a different session before A submits.
    $sessionB = lbrOpenSession(lbrRmeAdapter(), $blocker);
    $service->recordDecision(
        $sessionB, lbrRmeAdapter(), $blocker, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_SUSPECTED_DUPLICATE,
    );

    $summary = $service->submit($sessionA->refresh(), lbrRmeAdapter(), $reviewer);

    expect($summary['applied'])->toBe(0)
        ->and($summary['refused'])->toBe(1);

    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);

    $decision = LegacyBatchReviewItemDecision::where('batch_review_session_id', $sessionA->getKey())->sole();
    expect($decision->refusal_code)->toBe(LegacyBatchReviewReason::REFUSAL_TRIAGE_BLOCKING);
});

/*
|--------------------------------------------------------------------------
| Clearing triage is a review-authority act
|--------------------------------------------------------------------------
*/

it('lets an authorized reviewer clear a block, keeping the history', function () {
    $blocker = superAdmin();
    $clearer = superAdmin();
    $import = lbrRmeReady(superAdmin());

    $session = lbrOpenSession(lbrRmeAdapter(), $blocker);
    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $blocker, (int) $import->getKey(),
        LegacyBatchReviewDecision::NEEDS_ATTENTION, LegacyBatchReviewReason::TRIAGE_NEEDS_CLARIFICATION,
    );

    app(LegacyReviewTriageService::class)->clear(lbrRmeAdapter(), $import, $clearer);

    $triage = LegacyReviewTriage::sole();

    expect($triage->triage_status)->toBe(LegacyReviewTriageStatus::CLEARED)
        ->and($triage->isBlocking())->toBeFalse()
        ->and($triage->cleared_by)->toBe($clearer->getKey())
        ->and($triage->cleared_at)->not->toBeNull()
        // The original reviewer's attestation survives the release.
        ->and($triage->decided_by)->toBe($blocker->getKey())
        ->and($triage->reason_code)->toBe(LegacyBatchReviewReason::TRIAGE_NEEDS_CLARIFICATION);
});

it('refuses to let the uploader clear a block on their own RME document', function () {
    // The owner's rule: a block "cannot be cleared by the uploader unless
    // current review authorization explicitly permits that actor". On RME the
    // canonical review policy includes separation of duties, so it does not.
    $uploader = superAdmin();
    $import = lbrRmeReady($uploader);

    // ONE account for both calls: superAdmin() mints a fresh user each time, so
    // reusing it inline would trip the session-ownership guard instead of the
    // separation rule this test is about.
    $blocker = superAdmin();
    $session = lbrOpenSession(lbrRmeAdapter(), $blocker);
    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $blocker, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_ILLEGIBLE,
    );

    expect(fn () => app(LegacyReviewTriageService::class)->clear(lbrRmeAdapter(), $import, $uploader))
        ->toThrow(AuthorizationException::class);

    // Still withheld.
    expect(LegacyReviewTriage::sole()->isBlocking())->toBeTrue();
});

it('refuses to let an actor without review permission clear a block', function () {
    $import = lbrRmeReady(superAdmin());

    $blocker = superAdmin();
    $session = lbrOpenSession(lbrRmeAdapter(), $blocker);

    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $blocker, (int) $import->getKey(),
        LegacyBatchReviewDecision::BLOCKED, LegacyBatchReviewReason::TRIAGE_ILLEGIBLE,
    );

    $outsider = userWith(['view_legacy_rme_imports']);

    expect(fn () => app(LegacyReviewTriageService::class)->clear(lbrRmeAdapter(), $import, $outsider))
        ->toThrow(AuthorizationException::class);

    expect(LegacyReviewTriage::sole()->isBlocking())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Session ownership and type isolation
|--------------------------------------------------------------------------
*/

it('refuses to let another reviewer drive someone else\'s session', function () {
    $owner = superAdmin();
    $intruder = superAdmin();
    $import = lbrRmeReady(superAdmin());

    $session = lbrOpenSession(lbrRmeAdapter(), $owner);

    expect(fn () => app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $intruder,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    ))->toThrow(AuthorizationException::class);
});

it('refuses to drive an RME session through the odontogram adapter', function () {
    $reviewer = superAdmin();
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    // A type confusion here would write an id into the wrong foreign key and
    // call the wrong module's service.
    expect(fn () => app(LegacyBatchReviewSessionService::class)->submit(
        $session, lbrOdontogramAdapter(), $reviewer,
    ))->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

it('audits the batch submit with counts and references but no clinical payload', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );
    app(LegacyBatchReviewSessionService::class)->submit($session, lbrRmeAdapter(), $reviewer);

    $submitted = AuditLog::where('action', LegacyBatchReviewAuditService::SUBMITTED)->sole();
    $payload = (array) $submitted->new_values;

    expect($payload)->toHaveKey('applied_reviewed')
        ->and($payload['applied_reviewed'])->toBe(1)
        ->and($payload)->toHaveKey('session_uuid');

    // §15: counts and references, never clinical content or operator prose.
    expect($payload)->not->toHaveKey('reason_note')
        ->and($payload)->not->toHaveKey('patient_name');

    $encoded = json_encode($payload);
    expect($encoded)->not->toContain($import->patient->name);

    // The canonical per-item review event is still the authoritative clinical
    // record, and it now carries the BATCH channel.
    $reviewed = AuditLog::where('action', LegacyRmeAuditEvent::IMPORT_REVIEWED)->sole();
    expect((array) $reviewed->new_values)->toHaveKey('channel');
    expect(((array) $reviewed->new_values)['channel'])->toBe('BATCH');
});

it('records a refusal event, filling the gap the single-item review leaves', function () {
    $reviewer = superAdmin();
    // Uploaded by the reviewer, so separation of duties refuses it.
    $import = lbrRmeReady($reviewer);

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    app(LegacyBatchReviewSessionService::class)->recordDecision(
        $session, lbrRmeAdapter(), $reviewer,
        (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
    );
    app(LegacyBatchReviewSessionService::class)->submit($session, lbrRmeAdapter(), $reviewer);

    $refusal = AuditLog::where('action', LegacyBatchReviewAuditService::ITEM_REFUSED)->sole();

    expect(((array) $refusal->new_values)['refusal_code'])
        ->toBe(LegacyBatchReviewReason::REFUSAL_SEPARATION_OF_DUTIES);
});

/*
|--------------------------------------------------------------------------
| The publish path is untouched by PR1
|--------------------------------------------------------------------------
*/

it('publishes nothing and creates no archive record anywhere in a batch review', function () {
    $reviewer = superAdmin();
    $imports = collect(range(1, 3))->map(fn (): object => lbrRmeReady(superAdmin()));

    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);
    $service = app(LegacyBatchReviewSessionService::class);

    foreach ($imports as $import) {
        $service->recordDecision(
            $session, lbrRmeAdapter(), $reviewer,
            (int) $import->getKey(), LegacyBatchReviewDecision::REVIEWED,
        );
    }

    $service->submit($session, lbrRmeAdapter(), $reviewer);

    // Review is NOT publication. PR1 moves documents to REVIEWED and stops.
    expect(LegacyRmeRecord::count())->toBe(0);

    $imports->each(function ($import): void {
        expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::REVIEWED);
        expect($import->published_by)->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Query cost — the queue must not scale with page size
|--------------------------------------------------------------------------
*/

it('costs the same number of queries for one queued document as for six', function () {
    // A REGRESSION GUARD, not a micro-benchmark. The first version of the
    // workspace resolved `can_review` and `can_clear_triage` per row and
    // counted pages per row; since BranchService::rmeEnabledIds() is not
    // memoized, a 25-row page issued roughly fifty extra branch queries for
    // values the list never rendered. Constant-vs-page-size is the property
    // that catches that class of mistake coming back — an absolute ceiling
    // would not, because a duplicate read is still a constant.
    $reviewer = superAdmin();
    $workspace = app(App\Modules\LegacyImport\BatchReview\Services\LegacyBatchReviewWorkspaceService::class);

    lbrRmeReady(superAdmin());
    $session = lbrOpenSession(lbrRmeAdapter(), $reviewer);

    $count = function () use ($workspace, $reviewer, $session): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $workspace->queue(lbrRmeAdapter(), $reviewer, [], 25, $session);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Warm-up, discarded. Spatie resolves the actor's permissions once and
    // caches them in memory, so a COLD first sample carries one extra
    // `model_has_permissions` query that the second does not — measured, not
    // assumed. Comparing cold against warm would make this test fail by one for
    // a reason that has nothing to do with page size.
    $count();

    $withOne = $count();

    // Five more documents in the same queue page.
    foreach (range(1, 5) as $ignored) {
        lbrRmeReady(superAdmin());
    }

    $withSix = $count();

    expect($withSix)->toBe($withOne);
});
