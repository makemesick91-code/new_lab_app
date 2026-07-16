<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\DataQuality\SatusehatDataQualityIssueService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatRemediationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
    // Sprint 66 online-context selector is orthogonal to this workspace's own
    // authorization boundary (see the RME route-test convention).
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);
});

function ssOpenIssue(array $overrides = []): array
{
    $ctx = ssMakeVisit();
    $ctx['patient']->update(['gender' => null] + ($overrides['patient'] ?? []));
    $sync = ssSyncIssues($ctx);

    $issue = SatusehatDataQualityIssue::query()
        ->where('rule_code', $overrides['rule_code'] ?? 'patient_identity')
        ->firstOrFail();

    return ['ctx' => $ctx, 'candidate' => $sync['candidate'], 'issue' => $issue];
}

it('runs the full lifecycle: acknowledge → start → clinical review → resolve after real fix', function () {
    ['ctx' => $ctx, 'issue' => $issue] = ssOpenIssue();
    $actor = superAdmin();
    $service = app(SatusehatRemediationService::class);

    $service->acknowledge($issue, $actor);
    expect($issue->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_ACKNOWLEDGED);

    $service->startRemediation($issue->fresh(), $actor);
    expect($issue->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_IN_REMEDIATION);

    $service->requestClinicalReview($issue->fresh(), $actor);
    expect($issue->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_AWAITING_CLINICAL_REVIEW);

    // Resolve WITHOUT fixing → rejected by revalidation.
    expect(fn () => $service->resolve($issue->fresh(), $actor))->toThrow(ValidationException::class);
    expect($issue->fresh()->isOpen())->toBeTrue();

    // Fix the data → resolve passes and records the actor.
    $ctx['patient']->update(['gender' => 'Male']);
    $resolved = $service->resolve($issue->fresh(), $actor);
    expect($resolved->status)->toBe(SatusehatDataQualityIssue::STATUS_RESOLVED)
        ->and($resolved->resolution_type)->toBe('manual')
        ->and($resolved->resolved_by)->toBe($actor->id);
});

it('never allows a hard issue to be waived, and requires a reason for soft waivers', function () {
    ['issue' => $soft] = ssOpenIssue();
    $actor = superAdmin();
    $service = app(SatusehatRemediationService::class);

    // Reason required.
    expect(fn () => $service->waive($soft, $actor, '   '))->toThrow(ValidationException::class);

    // Soft waiver with reason works…
    $service->waive($soft->fresh(), $actor, 'Data legacy — pasien tidak aktif lagi.');
    expect($soft->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_WAIVED);

    // …but a HARD issue can never be waived.
    $hardCase = ssOpenIssue(['patient' => ['date_of_birth' => '1800-01-01'], 'rule_code' => 'patient_demographics']);
    expect(fn () => $service->waive($hardCase['issue'], $actor, 'coba paksa'))
        ->toThrow(ValidationException::class);
    expect($hardCase['issue']->fresh()->isOpen())->toBeTrue();
});

it('an expired waiver reopens automatically on the next scan while still detected', function () {
    ['candidate' => $candidate, 'issue' => $issue] = ssOpenIssue();
    $actor = superAdmin();

    app(SatusehatRemediationService::class)->waive($issue, $actor, 'sementara', now()->addMinute()->toDateTimeString());
    expect($issue->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_WAIVED);

    $this->travel(2)->minutes();
    app(SatusehatDataQualityIssueService::class)
        ->syncForCandidate($candidate->fresh(), $actor);

    expect($issue->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_REOPENED);
});

it('waiving never changes the canonical readiness verdict', function () {
    ['ctx' => $ctx, 'candidate' => $candidate, 'issue' => $issue] = ssOpenIssue();
    $actor = superAdmin();

    app(SatusehatRemediationService::class)->waive($issue, $actor, 'waiver triase saja');

    $candidate = ssService()->refresh($candidate->fresh(), $actor);
    expect($candidate->readiness_status)->toBe(SatusehatCandidate::READINESS_INCOMPLETE);
});

it('HTTP actions are permission-gated and waivers need the dedicated permission', function () {
    ['issue' => $issue] = ssOpenIssue();

    // Kasir: no access at all.
    $this->actingAs(userInRole('Kasir'))
        ->post(route('satusehat.readiness.issues.acknowledge', $issue->id))
        ->assertForbidden();

    // Admin Klinik: remediation yes, waiver no.
    $adminKlinik = userInRole('Admin Klinik');
    $this->actingAs($adminKlinik)
        ->post(route('satusehat.readiness.issues.acknowledge', $issue->id))
        ->assertRedirect();
    $this->actingAs($adminKlinik)
        ->post(route('satusehat.readiness.issues.waive', $issue->id), ['reason' => 'coba tanpa izin'])
        ->assertForbidden();

    // Supervisor RME: waiver allowed (soft issue).
    $this->actingAs(userInRole('Supervisor RME'))
        ->post(route('satusehat.readiness.issues.waive', $issue->id), ['reason' => 'alasan waiver sah'])
        ->assertRedirect();

    expect($issue->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_WAIVED);
});

it('issues outside the RME branch set are unreachable (IDOR boundary)', function () {
    ['issue' => $issue] = ssOpenIssue();

    // Take the branch out of the RME set — the issue must vanish (404), even
    // for a fully-permitted user, and a forged POST must also 404.
    Branch::query()->whereKey($issue->branch_id)->update(['is_rme_enabled' => false]);

    $supervisor = userInRole('Supervisor RME');
    $this->actingAs($supervisor)->get(route('satusehat.readiness.issues.show', $issue->id))->assertNotFound();
    $this->actingAs($supervisor)->post(route('satusehat.readiness.issues.acknowledge', $issue->id))->assertNotFound();
});

it('every remediation transition is recorded in the append-only audit log', function () {
    ['issue' => $issue] = ssOpenIssue();
    $actor = superAdmin();
    app(SatusehatRemediationService::class)->acknowledge($issue, $actor);

    $log = SatusehatAuditLog::query()
        ->where('entity_type', 'data_quality_issue')
        ->where('entity_id', $issue->id)
        ->where('event', SatusehatAuditLog::EVENT_ISSUE_ACKNOWLEDGED)
        ->first();

    expect($log)->not->toBeNull();
});
