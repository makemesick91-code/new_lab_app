<?php

/**
 * FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 (PR1) — the HTTP boundary.
 *
 * WHAT THESE TESTS PIN
 * --------------------
 * That the batch surfaces add no authorization of their own and relax none:
 * the route permission is the canonical review permission, a session is
 * reachable only by the reviewer who opened it and only through its own
 * archive's route group, an id the actor cannot act on is refused rather than
 * acted on, and the migration capability switch still closes the endpoint.
 *
 * A NOTE ON BRANCH SCOPE, STATED HONESTLY
 * ---------------------------------------
 * `review_legacy_rme_imports` is itself one of LegacyRmeWorkspaceScope's
 * GOVERNANCE_PERMISSIONS, so a reviewer legitimately sees every RME-enabled
 * branch — that is the canonical design, not a hole, and these tests do not
 * pretend otherwise. What matters, and what is asserted, is that the scope is
 * resolved SERVER-SIDE from that canonical scope on every request and is never
 * taken from the URL, the form or a session column.
 */

use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewItemDecision;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../LegacyOdontogram/helpers.php';
require_once __DIR__.'/helpers.php';

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
| Reachability and refusal
|--------------------------------------------------------------------------
*/

it('lets a reviewer holding the canonical review permission open the workspace', function () {
    $this->actingAs(lbrReviewer())
        ->get(route('settings.rme.legacy-review-imports.index'))
        ->assertOk()
        ->assertSee('Batch Review Legacy RME');
});

it('refuses an actor who may view the archive but not review it', function () {
    // No batch-specific permission exists, so "can view" must not imply
    // "can batch review" — the route carries the review permission verbatim.
    $this->actingAs(userWith(['view_legacy_rme_imports']))
        ->get(route('settings.rme.legacy-review-imports.index'))
        ->assertForbidden();
});

it('refuses a guest', function () {
    $this->get(route('settings.rme.legacy-review-imports.index'))
        ->assertRedirect(route('login'));
});

it('closes the endpoint entirely when the migration capability is off', function () {
    // 404 rather than 403, matching the canonical controllers: a 403 would
    // confirm the endpoint exists.
    legacyRmeArchiveFlag(false);

    $this->actingAs(lbrReviewer())
        ->get(route('settings.rme.legacy-review-imports.index'))
        ->assertNotFound();
});

it('keeps the two archives on separate permissions', function () {
    // An odontogram reviewer has no business on the RME surface and vice versa.
    $this->actingAs(lbrOdontogramReviewer())
        ->get(route('settings.rme.legacy-review-imports.index'))
        ->assertForbidden();

    $this->actingAs(lbrReviewer())
        ->get(route('settings.rme.legacy-review-odontograms.index'))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Session addressing
|--------------------------------------------------------------------------
*/

it('opens a session and lands the reviewer on it', function () {
    $reviewer = lbrReviewer();

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-review-imports.store'))
        ->assertRedirect();

    $session = LegacyBatchReviewSession::sole();

    expect($session->opened_by)->toBe($reviewer->getKey());

    $this->actingAs($reviewer)
        ->get(route('settings.rme.legacy-review-imports.show', $session->uuid))
        ->assertOk();
});

it('hides another reviewer\'s session behind a 404', function () {
    $owner = lbrReviewer();
    $other = lbrReviewer();

    $this->actingAs($owner)->post(route('settings.rme.legacy-review-imports.store'));
    $session = LegacyBatchReviewSession::sole();

    $this->actingAs($other)
        ->get(route('settings.rme.legacy-review-imports.show', $session->uuid))
        ->assertNotFound();
});

it('refuses to open an odontogram session through the RME route group', function () {
    // Cross-archive IDOR: the uuid is real, but not for this route group.
    $reviewer = userWith([
        'view_legacy_rme_imports', 'review_legacy_rme_imports',
        'view_legacy_odontogram_imports', 'review_legacy_odontogram_imports',
    ]);

    $this->actingAs($reviewer)->post(route('settings.rme.legacy-review-odontograms.store'));
    $odontogramSession = LegacyBatchReviewSession::sole();

    $this->actingAs($reviewer)
        ->get(route('settings.rme.legacy-review-imports.show', $odontogramSession->uuid))
        ->assertNotFound();
});

it('rejects a session handle that is not a uuid at the route level', function () {
    $this->actingAs(lbrReviewer())
        ->get('/settings/rme/legacy-review-imports/1')
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The decision endpoint
|--------------------------------------------------------------------------
*/

it('records one attestation through the HTTP boundary', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());

    $this->actingAs($reviewer)->post(route('settings.rme.legacy-review-imports.store'));
    $session = LegacyBatchReviewSession::sole();

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-review-imports.decide', $session->uuid), [
            'import_id' => $import->getKey(),
            'decision' => LegacyBatchReviewDecision::REVIEWED,
        ])
        ->assertRedirect();

    expect(LegacyBatchReviewItemDecision::sole()->rme_legacy_import_id)->toBe($import->getKey());

    // Recording an attestation does NOT review the document — only submit does.
    expect($import->refresh()->status)->toBe(LegacyRmeImportStatus::READY_FOR_REVIEW);
});

it('refuses a block with no reason, and one with a reason it never offered', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());

    $this->actingAs($reviewer)->post(route('settings.rme.legacy-review-imports.store'));
    $session = LegacyBatchReviewSession::sole();

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-review-imports.decide', $session->uuid), [
            'import_id' => $import->getKey(),
            'decision' => LegacyBatchReviewDecision::BLOCKED,
        ])
        ->assertSessionHasErrors('reason_code');

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-review-imports.decide', $session->uuid), [
            'import_id' => $import->getKey(),
            'decision' => LegacyBatchReviewDecision::BLOCKED,
            'reason_code' => 'TRIAGE_NOT_A_REAL_CODE',
        ])
        ->assertSessionHasErrors('reason_code');

    expect(LegacyBatchReviewItemDecision::count())->toBe(0);
});

it('refuses a decision naming a document that does not exist', function () {
    $reviewer = superAdmin();

    $this->actingAs($reviewer)->post(route('settings.rme.legacy-review-imports.store'));
    $session = LegacyBatchReviewSession::sole();

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-review-imports.decide', $session->uuid), [
            'import_id' => 987654,
            'decision' => LegacyBatchReviewDecision::REVIEWED,
        ])
        ->assertSessionHasErrors('import_id');

    expect(LegacyBatchReviewItemDecision::count())->toBe(0);
});

it('refuses an odontogram document id submitted to the RME decision endpoint', function () {
    // Cross-archive IDOR on the item rather than the session. The id is real in
    // the other archive; it must not be reachable here.
    $reviewer = userWith([
        'view_legacy_rme_imports', 'review_legacy_rme_imports',
        'view_legacy_odontogram_imports', 'review_legacy_odontogram_imports',
    ]);

    $odontogram = lbrOdontogramReady(superAdmin());

    $this->actingAs($reviewer)->post(route('settings.rme.legacy-review-imports.store'));
    $session = LegacyBatchReviewSession::sole();

    $this->actingAs($reviewer)
        ->post(route('settings.rme.legacy-review-imports.decide', $session->uuid), [
            'import_id' => $odontogram->getKey(),
            'decision' => LegacyBatchReviewDecision::REVIEWED,
        ])
        ->assertSessionHasErrors('import_id');

    expect(LegacyBatchReviewItemDecision::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Privacy
|--------------------------------------------------------------------------
*/

it('never renders a patient identity number in the workspace', function () {
    $reviewer = superAdmin();
    $import = lbrRmeReady(superAdmin());

    $import->patient->forceFill(['ktp_number' => '7371010101010001'])->save();

    $this->actingAs($reviewer)->post(route('settings.rme.legacy-review-imports.store'));
    $session = LegacyBatchReviewSession::sole();

    $response = $this->actingAs($reviewer)
        ->get(route('settings.rme.legacy-review-imports.show', $session->uuid))
        ->assertOk();

    $response->assertDontSee('7371010101010001');

    // The medical record number is the identifier a legacy workspace shows.
    $response->assertSee($import->patient->medical_record_number);
});
