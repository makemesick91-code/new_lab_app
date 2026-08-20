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

it('denies the filter page to every non-Super-Admin, SATUSEHAT permission or not', function () {
    // FIX-08: SATUSEHAT is Super Admin only — `can:satusehat.access` guards the
    // whole route group, so even a `view_satusehat_submissions` holder is denied
    // before the per-feature permission middleware is reached.
    foreach ([
        userWith(['manage patients']),
        userWith(['view_satusehat_submissions']),
        userWith(['review_satusehat_submissions']),
        userWith(['send_satusehat_submissions']),
    ] as $user) {
        $this->actingAs($user)
            ->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get(route('satusehat.submissions.index'))
            ->assertForbidden();
    }
});

it('shows candidates to a Super Admin and never renders a full NIK', function () {
    $candidate = ssReadyCandidate();
    $fullNik = $candidate->patient->ktp_number;
    // FIX-08: Super Admin only. Every rendering/privacy assertion is unchanged.
    $viewer = superAdmin();

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

    // FIX-08: a permission holder is now stopped by the Super Admin gate first,
    // so its HTTP 403 alone no longer proves the branch boundary — and
    // SatusehatSubmissionController::show authorizes ONLY through
    // SatusehatCandidatePolicy::view, which Gate::before bypasses for a Super
    // Admin. This branch boundary is therefore no longer observable over HTTP
    // for ANY actor, and is asserted directly on the policy instead. (The bulk
    // action's branch boundary is scope-based, not policy-based, so it IS still
    // asserted end-to-end over HTTP — see the bulk IDOR test below.)
    $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.submissions.show', $candidate))
        ->assertForbidden();

    // Policy layer: an out-of-RME-scope candidate is unreadable even with the
    // permission (Gate::before never applies to a non-Super-Admin user).
    expect($viewer->can('view', $candidate))->toBeFalse()
        ->and($viewer->can('preview', $candidate))->toBeFalse()
        ->and($viewer->can('approve', $candidate))->toBeFalse()
        ->and($viewer->can('queue', $candidate))->toBeFalse();

    // Control: the same permission holder CAN read an in-scope candidate, so the
    // denial above is the branch boundary, not a blanket denial.
    expect($viewer->can('view', ssReadyCandidate()))->toBeTrue();
});

it('approves a ready candidate for a Super Admin reviewer', function () {
    $candidate = ssReadyCandidate();
    // FIX-08: Super Admin only over HTTP. The review-tier permission boundary
    // itself is still asserted on the policy (see the two "cannot" tests below).
    $reviewer = superAdmin();

    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('satusehat.submissions.approve', $candidate))
        ->assertRedirect();

    expect($candidate->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_APPROVED);
});

it('refuses to approve an incomplete candidate', function () {
    $ctx = ssMakeVisit(); // no identifiers → incomplete
    $candidate = ssService()->generateForVisit($ctx['visit']);
    $reviewer = superAdmin(); // FIX-08: Super Admin only.

    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('satusehat.submissions.show', $candidate))
        ->post(route('satusehat.submissions.approve', $candidate))
        ->assertRedirect(route('satusehat.submissions.show', $candidate));

    expect($candidate->fresh()->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING);
});

it('requires a reason to exclude', function () {
    $candidate = ssReadyCandidate();
    $reviewer = superAdmin(); // FIX-08: Super Admin only.

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

    // FIX-08: the HTTP 403 above is now produced by the Super Admin gate, so the
    // view-vs-review tier split is asserted directly on the policy, where the
    // Super Admin bypass does not apply.
    expect($viewer->can('approve', $candidate))->toBeFalse()
        ->and($viewer->can('exclude', $candidate))->toBeFalse()
        ->and(userWith(['review_satusehat_submissions'])->can('approve', $candidate))->toBeTrue();
});

it('cannot refresh (recompute/revoke) with only the view permission', function () {
    $candidate = ssReadyCandidate();
    $viewer = userWith(['view_satusehat_submissions']);

    // refresh can revoke an approval on source drift → review-tier only.
    $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('satusehat.submissions.refresh', $candidate))
        ->assertForbidden();

    // FIX-08: a review-tier holder is now denied at the Super Admin gate too, so
    // the view-vs-review split moved to the policy (no Super Admin bypass there)
    // while the successful refresh is exercised as a Super Admin.
    $reviewer = userWith(['review_satusehat_submissions']);
    $this->actingAs($reviewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('satusehat.submissions.refresh', $candidate))
        ->assertForbidden();

    expect($viewer->can('refresh', $candidate))->toBeFalse()
        ->and($reviewer->can('refresh', $candidate))->toBeTrue();

    $this->actingAs(superAdmin())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('satusehat.submissions.refresh', $candidate))
        ->assertRedirect();
});

it('queue/prepare is fail-closed and makes no external HTTP call', function () {
    Http::fake();
    $candidate = ssReadyCandidate();
    ssService()->approve($candidate, userWith(['review_satusehat_submissions']));
    // FIX-08: Super Admin only over HTTP. The send-tier permission boundary is
    // still asserted on the policy so it is not lost.
    expect(userWith(['view_satusehat_submissions'])->can('queue', $candidate))->toBeFalse()
        ->and(userWith(['send_satusehat_submissions'])->can('queue', $candidate))->toBeTrue();
    $sender = superAdmin();

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
    // FIX-08: Super Admin only over HTTP. The server-side branch scope still
    // bites for a Super Admin (the RME-enabled branch set is not a permission
    // check), so this remains a real IDOR assertion, not a bypass.
    $reviewer = superAdmin();

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
