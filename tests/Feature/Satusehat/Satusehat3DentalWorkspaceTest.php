<?php

use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
});

it('stores the dental readiness axis on a generated candidate without any network call', function () {
    $ctx = ssMakeVisit();
    ssOdontogram($ctx, ['48' => 'caries']);
    ssAddIdentifiers($ctx);

    $candidate = ssService()->generateForVisit($ctx['visit']);

    expect($candidate->dental_readiness_status)->not->toBeNull()
        ->and($candidate->dental_source_hash)->not->toBeNull()
        ->and($candidate->dental_coverage_snapshot)->toHaveKey('tooth_count');
    Http::assertNothingSent();
});

it('revokes approval when the dental source drifts after approval', function () {
    $ctx = ssMakeVisit();
    $teeth = ['48' => 'caries'];
    $odontogram = ssOdontogram($ctx, $teeth);
    ssActivateDentalMappings($teeth);
    ssAddIdentifiers($ctx);
    ssTreatmentMapping(9999); // keep core readiness happy is not required here

    $service = ssService();
    $candidate = $service->generateForVisit($ctx['visit']);
    // Force the CORE readiness to ready by approving directly is guarded; instead
    // assert dental drift path: approve requires core-ready, so approve via a
    // candidate we manually mark approved with a pinned dental hash.
    $candidate->update([
        'review_status' => SatusehatCandidate::REVIEW_APPROVED,
        'approved_dental_source_hash' => $candidate->dental_source_hash,
        'approved_source_hash' => $candidate->source_hash,
        'readiness_status' => SatusehatCandidate::READINESS_READY,
        'approved_at' => now(),
    ]);

    // Mutate the odontogram (drift).
    $payload = $odontogram->tooth_map_payload;
    $payload['teeth']['11'] = ['status' => 'missing'];
    $odontogram->update(['tooth_map_payload' => $payload]);

    $service->refresh($candidate->fresh());

    $candidate->refresh();
    expect($candidate->dental_readiness_status)->toBe(SatusehatCandidate::DENTAL_SOURCE_CHANGED)
        ->and($candidate->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING)
        ->and($candidate->revoked_at)->not->toBeNull();
});

it('renders the dental preview locally and never sends anything', function () {
    $ctx = ssMakeVisit();
    $teeth = ['48' => 'caries'];
    ssOdontogram($ctx, $teeth);
    ssActivateDentalMappings($teeth);
    ssAddIdentifiers($ctx);
    $candidate = ssService()->generateForVisit($ctx['visit']);

    // FIX-08: SATUSEHAT is Super Admin only (`can:satusehat.access` on the whole
    // route group), so the preview is rendered for a Super Admin. Every
    // preview/privacy assertion below is unchanged.
    $viewer = superAdmin();
    $response = $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.submissions.preview', $candidate))
        ->assertOk();

    $response->assertSee('PREVIEW LOKAL', false);
    $response->assertSee('Preview Gigi', false);
    expect($response->getContent())->not->toContain((string) $candidate->patient->ktp_number);
    Http::assertNothingSent();
});

it('shows the dental coverage page to a Super Admin', function () {
    // FIX-08: the module is Super Admin only; a `view_satusehat_submissions`
    // holder is now denied at the route group (see the denial test below).
    $viewer = superAdmin();

    $this->actingAs($viewer)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.dental.coverage'))
        ->assertOk()
        ->assertSee('Cakupan Use-Case Gigi', false);
});

it('shows the production readiness page and states production is blocked', function () {
    // FIX-08: Super Admin only — the `manage_satusehat_settings` route
    // permission is untouched, the outer gate simply precedes it.
    $settings = superAdmin();

    $this->actingAs($settings)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.production-readiness'))
        ->assertOk()
        ->assertSee('TERBLOKIR', false);
});

it('denies the coverage page to every non-Super-Admin, SATUSEHAT permission or not', function () {
    // FIX-08: the module is Super Admin only. Holding the per-feature SATUSEHAT
    // permission is no longer sufficient — the outer gate denies first.
    foreach ([
        userWith(['manage patients']),
        userWith(['view_satusehat_submissions']),
        userWith(['manage_satusehat_settings']),
    ] as $user) {
        $this->actingAs($user)
            ->withoutMiddleware(EnsureRmeOnlineContext::class)
            ->get(route('satusehat.dental.coverage'))
            ->assertForbidden();
    }
});

it('keeps SATUSEHAT-2 in WATCH: external submission remains disabled', function () {
    expect(config('satusehat.enabled'))->toBeFalse()
        ->and(config('satusehat.send_enabled'))->toBeFalse()
        ->and(config('satusehat.sandbox_verified'))->toBeFalse();
});
