<?php

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\DataQuality\SatusehatDataQualityIssueService;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
});

it('detects missing patient identity fields as soft issues with per-field fingerprints', function () {
    $ctx = ssMakeVisit();
    $ctx['patient']->update(['date_of_birth' => null, 'gender' => null]);

    ['summary' => $summary] = ssSyncIssues($ctx);

    $issues = SatusehatDataQualityIssue::query()->where('rule_code', 'patient_identity')->get();

    expect($issues->pluck('field_path'))->toContain('date_of_birth', 'gender')
        ->and($issues->every(fn ($i) => $i->severity === 'soft'))->toBeTrue()
        ->and($issues->every(fn ($i) => $i->owner_role === 'Admin Klinik'))->toBeTrue()
        ->and($summary['created'])->toBeGreaterThanOrEqual(2);

    Http::assertNothingSent();
});

it('flags impossible birth dates and non-canonical gender as HARD issues', function () {
    $ctx = ssMakeVisit();
    $ctx['patient']->update(['date_of_birth' => now()->addYear()->toDateString(), 'gender' => 'unknown-x']);

    ssSyncIssues($ctx);

    $hard = SatusehatDataQualityIssue::query()->where('rule_code', 'patient_demographics')->get();

    expect($hard)->toHaveCount(2)
        ->and($hard->every(fn ($i) => $i->severity === SatusehatDataQualityIssue::SEVERITY_HARD))->toBeTrue();
});

it('classifies IHS identifier gaps as external info issues, never internal blockers', function () {
    $ctx = ssMakeVisit(); // no identifiers at all

    ssSyncIssues($ctx);

    $external = SatusehatDataQualityIssue::query()
        ->whereIn('rule_code', ['practitioner_readiness', 'organization_readiness', 'location_readiness'])
        ->where('severity', 'info')
        ->get();

    expect($external->count())->toBeGreaterThanOrEqual(3)
        ->and($external->every(fn ($i) => (bool) ($i->metadata['external'] ?? false)))->toBeTrue();
});

it('is idempotent: re-scanning never duplicates issues, only bumps last_detected_at', function () {
    $ctx = ssMakeVisit();
    $ctx['patient']->update(['gender' => null]);

    ['candidate' => $candidate] = ssSyncIssues($ctx);
    $countAfterFirst = SatusehatDataQualityIssue::count();
    $issue = SatusehatDataQualityIssue::query()->where('rule_code', 'patient_identity')->firstOrFail();
    $firstDetected = $issue->first_detected_at;

    $this->travel(1)->minutes();
    app(SatusehatDataQualityIssueService::class)
        ->syncForCandidate($candidate->fresh());

    expect(SatusehatDataQualityIssue::count())->toBe($countAfterFirst)
        ->and($issue->fresh()->first_detected_at->equalTo($firstDetected))->toBeTrue()
        ->and($issue->fresh()->last_detected_at->greaterThan($firstDetected))->toBeTrue();
});

it('auto-resolves an issue when the defect is fixed, and reopens when it regresses', function () {
    $ctx = ssMakeVisit();
    $ctx['patient']->update(['gender' => null]);

    ['candidate' => $candidate] = ssSyncIssues($ctx);
    $issue = SatusehatDataQualityIssue::query()
        ->where('rule_code', 'patient_identity')->where('field_path', 'gender')->firstOrFail();
    expect($issue->status)->toBe(SatusehatDataQualityIssue::STATUS_OPEN);

    // Fix → auto-resolve (revalidated).
    $ctx['patient']->update(['gender' => 'Female']);
    app(SatusehatDataQualityIssueService::class)
        ->syncForCandidate($candidate->fresh());
    $issue->refresh();
    expect($issue->status)->toBe(SatusehatDataQualityIssue::STATUS_RESOLVED)
        ->and($issue->resolution_type)->toBe('revalidated');

    // Regress → the SAME fingerprint reopens (no duplicate row).
    $ctx['patient']->update(['gender' => null]);
    app(SatusehatDataQualityIssueService::class)
        ->syncForCandidate($candidate->fresh());
    $issue->refresh();
    expect($issue->status)->toBe(SatusehatDataQualityIssue::STATUS_REOPENED)
        ->and(SatusehatDataQualityIssue::query()->where('field_path', 'gender')->count())->toBe(1);
});

it('emits structured diagnosis + treatment mapping + drift issues from the canonical readiness verdict', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $reviewer = superAdmin();

    // No diagnosis yet → structured_diagnosis soft issue for the Doctor.
    ['candidate' => $candidate] = ssSyncIssues($ctx, $reviewer);
    expect(SatusehatDataQualityIssue::query()->where('rule_code', 'structured_diagnosis')->exists())->toBeTrue();

    // Approve, then change clinical source → SourceDriftRule fires as HARD.
    $candidate = ssService()->approve($candidate->fresh(), $reviewer);
    ssDiagnosis($ctx, 'K02.9', 'primary', mapped: false);
    app(SatusehatDataQualityIssueService::class)
        ->syncForCandidate($candidate->fresh(), $reviewer);

    expect(SatusehatDataQualityIssue::query()->where('rule_code', 'source_drift')->where('severity', 'hard')->exists())->toBeTrue()
        // The unmapped diagnosis also surfaces for the Clinical Reviewer.
        ->and(SatusehatDataQualityIssue::query()->where('rule_code', 'diagnosis_mapping')->exists())->toBeTrue()
        // And the structured_diagnosis issue auto-resolved (a primary now exists).
        ->and(SatusehatDataQualityIssue::query()->where('rule_code', 'structured_diagnosis')->value('status'))
        ->toBe(SatusehatDataQualityIssue::STATUS_RESOLVED);
});
