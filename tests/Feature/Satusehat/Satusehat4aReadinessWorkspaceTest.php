<?php

use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\DataQuality\SatusehatOperationalReadinessService;
use App\Modules\Satusehat\Support\SatusehatOperationalStatusResolver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
    // Sprint 66 online-context selector is orthogonal to this workspace's own
    // authorization boundary (see the RME route-test convention).
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
});

it('renders the readiness dashboard for Super Admin and denies every other role now that the module is Super Admin only', function () {
    ssSyncIssues(ssMakeVisit());

    // FIX-08: `can:satusehat.access` guards the whole satusehat.* group, so only
    // a Super Admin reaches the workspace at all.
    $this->actingAs(superAdmin())->get(route('satusehat.readiness.index'))
        ->assertOk()
        ->assertSee('Kesiapan Operasional SATUSEHAT');

    foreach ([
        'Supervisor RME', 'Owner', 'Admin Klinik', 'Kasir', 'Doctor', 'Admin Lab',
    ] as $role) {
        $this->actingAs(userInRole($role))->get(route('satusehat.readiness.index'))->assertForbidden();
    }

    // The per-feature readiness permission itself is unchanged — the outer gate
    // simply precedes it. Asserted here so the seeded RBAC split is not lost.
    expect(userInRole('Supervisor RME')->can('view_satusehat_readiness'))->toBeTrue()
        ->and(userInRole('Owner')->can('view_satusehat_readiness'))->toBeTrue()
        ->and(userInRole('Admin Klinik')->can('view_satusehat_readiness'))->toBeTrue()
        ->and(userInRole('Kasir')->canAny(['view_satusehat_readiness', 'manage_satusehat_remediation']))->toBeFalse()
        ->and(userInRole('Doctor')->canAny(['view_satusehat_readiness', 'manage_satusehat_remediation']))->toBeFalse()
        ->and(userInRole('Admin Lab')->canAny(['view_satusehat_readiness', 'manage_satusehat_remediation']))->toBeFalse();

    Http::assertNothingSent();
});

it('resolves BLOCKED_EXTERNAL_CREDENTIAL only when every remaining gap is external', function () {
    $ctx = ssMakeVisit();
    ssDiagnosis($ctx, 'K02.9', 'primary', mapped: true);
    ['candidate' => $candidate] = ssSyncIssues($ctx);

    // Everything internal is complete; only IHS identifiers are missing.
    $status = app(SatusehatOperationalReadinessService::class)->operationalStatusFor($candidate);
    expect($status)->toBe('BLOCKED_EXTERNAL_CREDENTIAL');
});

it('resolves READY_INTERNAL when identifiers exist and internal data is complete', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    ssDiagnosis($ctx, 'K02.9', 'primary', mapped: true);
    ['candidate' => $candidate] = ssSyncIssues($ctx);

    expect(app(SatusehatOperationalReadinessService::class)->operationalStatusFor($candidate))
        ->toBe('READY_INTERNAL');
});

it('prioritizes internal gaps over the external credential wall', function () {
    $ctx = ssMakeVisit(); // no diagnosis, no identifiers
    ['candidate' => $candidate] = ssSyncIssues($ctx);

    expect(app(SatusehatOperationalReadinessService::class)->operationalStatusFor($candidate))
        ->toBe('MISSING_STRUCTURED_DIAGNOSIS');

    // Invalid demographics outranks everything internal.
    $ctx['patient']->update(['date_of_birth' => '1799-01-01']);
    ['candidate' => $candidate] = ssSyncIssues($ctx);
    expect(app(SatusehatOperationalReadinessService::class)->operationalStatusFor($candidate->fresh()))
        ->toBe('INVALID_PATIENT_DEMOGRAPHICS');
});

it('reports SOURCE_CHANGED above everything else after post-approval drift', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $reviewer = superAdmin();
    $candidate = ssService()->generateForVisit($ctx['visit']);
    ssService()->approve($candidate, $reviewer);

    ssDiagnosis($ctx, 'K02.9', 'primary', mapped: true);
    $candidate = ssService()->refresh($candidate->fresh(), $reviewer);

    expect(app(SatusehatOperationalReadinessService::class)->operationalStatusFor($candidate))
        ->toBe('SOURCE_CHANGED');
});

it('the candidate board is branch-scoped and paginated with per-row operational status', function () {
    $inScope = ssMakeVisit();
    $outOfScope = ssMakeVisit();
    $outOfScope['branch']->update(['is_rme_enabled' => false]);
    ssSyncIssues($inScope);

    $service = app(SatusehatOperationalReadinessService::class);
    $board = $service->candidateBoard([], [$inScope['branch']->id]);

    expect($board->total())->toBe(1)
        ->and($board->items()[0]->operational_status)->not->toBeNull();

    // Empty branch set = fail-closed deny.
    expect($service->candidateBoard([], [])->total())->toBe(0);
});

it('exposes practitioner, organization, and location readiness without fabricating identifiers', function () {
    $ctx = ssMakeVisit();

    $service = app(SatusehatOperationalReadinessService::class);
    $practitioners = collect($service->practitionerReadiness());
    $orgLocation = $service->organizationLocationReadiness();

    expect($practitioners->firstWhere('doctor_id', $ctx['doctor']->id)['status'])->toBe('ready_for_lookup')
        ->and(collect($orgLocation['organizations'])->firstWhere('branch_id', $ctx['branch']->id)['status'])
        ->toBe('awaiting_external_identifier');

    // With a manually onboarded identifier the org becomes present-unverified.
    ssAddIdentifiers($ctx);
    $orgLocation = $service->organizationLocationReadiness();
    expect(collect($orgLocation['organizations'])->firstWhere('branch_id', $ctx['branch']->id)['status'])
        ->toBe('identifier_present_unverified');
});

it('never renders a full NIK anywhere in the workspace', function () {
    $ctx = ssMakeVisit();
    $nik = $ctx['patient']->ktp_number;
    ssSyncIssues($ctx);

    $issue = SatusehatDataQualityIssue::query()->firstOrFail();

    // FIX-08: Super Admin only. Every privacy assertion below is unchanged.
    $actor = superAdmin();

    $this->actingAs($actor)->get(route('satusehat.readiness.index'))
        ->assertOk()->assertDontSee($nik);
    $this->actingAs($actor)->get(route('satusehat.readiness.issues'))
        ->assertOk()->assertDontSee($nik);
    $this->actingAs($actor)->get(route('satusehat.readiness.issues.show', $issue->id))
        ->assertOk()->assertDontSee($nik);
});

it('the resolver treats an odontogram-less visit as non-blocking dental info', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    ssDiagnosis($ctx, 'K02.9', 'primary', mapped: true);
    ['candidate' => $candidate] = ssSyncIssues($ctx);

    // Dental status is dental_incomplete purely because no odontogram exists —
    // the operational status must still be READY_INTERNAL.
    expect($candidate->dental_readiness_status)
        ->toBe(SatusehatCandidate::DENTAL_INCOMPLETE)
        ->and(app(SatusehatOperationalReadinessService::class)->operationalStatusFor($candidate))
        ->toBe('READY_INTERNAL');
});

it('operational statuses registry matches the resolver outputs', function () {
    $registered = (array) config('satusehat_data_quality.operational_statuses');

    foreach (['READY_INTERNAL', 'BLOCKED_EXTERNAL_CREDENTIAL', 'MISSING_STRUCTURED_DIAGNOSIS', 'SOURCE_CHANGED', 'INVALID_PATIENT_DEMOGRAPHICS'] as $status) {
        expect($registered)->toContain($status);
    }

    expect(SatusehatOperationalStatusResolver::EXTERNAL_REASON_CODES)->toContain('patient_ihs_missing');
});
