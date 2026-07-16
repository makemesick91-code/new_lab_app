<?php

use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatSubmissionBatch;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    config()->set('satusehat.candidate.auto_generate', false);
});

function ssReadyCandidate(): SatusehatCandidate
{
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);

    return ssService()->generateForVisit($ctx['visit']);
}

it('denies the filter page without a SATUSEHAT permission', function () {
    $user = userWith(['manage patients']);

    $this->actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.submissions.index'))
        ->assertForbidden();
});

it('shows candidates to a viewer and never renders a full NIK', function () {
    $candidate = ssReadyCandidate();
    $fullNik = $candidate->patient->ktp_number;
    $viewer = userWith(['view_satusehat_submissions']);

    $response = $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.submissions.index'))
        ->assertOk()
        ->assertSee($candidate->patient->name);

    expect(str_contains($response->getContent(), (string) $fullNik))->toBeFalse();
});

it('enforces branch isolation on the detail page (IDOR)', function () {
    $candidate = ssReadyCandidate();
    $candidate->branch->update(['is_rme_enabled' => false]); // now out of the RME scope
    $viewer = userWith(['view_satusehat_submissions']);

    $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.submissions.show', $candidate))
        ->assertForbidden();
});

it('approves a ready candidate for a reviewer', function () {
    $candidate = ssReadyCandidate();
    $reviewer = userWith(['review_satusehat_submissions']);

    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('satusehat.submissions.approve', $candidate))
        ->assertRedirect();

    expect($candidate->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_APPROVED);
});

it('refuses to approve an incomplete candidate', function () {
    $ctx = ssMakeVisit(); // no identifiers → incomplete
    $candidate = ssService()->generateForVisit($ctx['visit']);
    $reviewer = userWith(['review_satusehat_submissions']);

    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('satusehat.submissions.show', $candidate))
        ->post(route('satusehat.submissions.approve', $candidate))
        ->assertRedirect(route('satusehat.submissions.show', $candidate));

    expect($candidate->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING);
});

it('requires a reason to exclude', function () {
    $candidate = ssReadyCandidate();
    $reviewer = userWith(['review_satusehat_submissions']);

    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('satusehat.submissions.show', $candidate))
        ->post(route('satusehat.submissions.exclude', $candidate), ['exclusion_reason' => ''])
        ->assertSessionHasErrors('exclusion_reason');

    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('satusehat.submissions.exclude', $candidate), ['exclusion_reason' => 'Data duplikat'])
        ->assertRedirect();

    expect($candidate->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_EXCLUDED);
});

it('cannot approve without the review permission', function () {
    $candidate = ssReadyCandidate();
    $viewer = userWith(['view_satusehat_submissions']);

    $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('satusehat.submissions.approve', $candidate))
        ->assertForbidden();
});

it('queue/prepare is fail-closed and makes no external HTTP call', function () {
    Http::fake();
    $candidate = ssReadyCandidate();
    ssService()->approve($candidate, userWith(['review_satusehat_submissions']));
    $sender = userWith(['send_satusehat_submissions']);

    $this->actingAs($sender)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('satusehat.submissions.index'))
        ->post(route('satusehat.submissions.bulk'), [
            'action' => 'prepare',
            'candidate_ids' => [$candidate->id],
        ])
        ->assertRedirect();

    Http::assertNothingSent();

    $batch = SatusehatSubmissionBatch::first();
    expect($batch)->not->toBeNull()
        ->and($batch->status)->toBe(SatusehatSubmissionBatch::STATUS_PREPARED)
        ->and($candidate->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_QUEUED)
        ->and(config('satusehat.enabled'))->toBeFalse();
});

it('drops out-of-scope ids from a bulk action (server-side IDOR)', function () {
    $inScope = ssReadyCandidate();
    $outOfScope = ssReadyCandidate();
    $outOfScope->branch->update(['is_rme_enabled' => false]);
    $reviewer = userWith(['review_satusehat_submissions']);

    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('satusehat.submissions.index'))
        ->post(route('satusehat.submissions.bulk'), [
            'action' => 'approve',
            'candidate_ids' => [$inScope->id, $outOfScope->id],
        ])
        ->assertRedirect();

    expect($inScope->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_APPROVED)
        ->and($outOfScope->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING);
});
