<?php

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Services\ClinicalDiagnosisService;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
});

it('emits deprecated_diagnosis_selected after terminology deprecation and auto-resolves on re-coding', function () {
    $ctx = ssMakeVisit();
    $reviewer = userWith(['review_clinical_terminology']);
    $dxRow = ssDiagnosis($ctx, 'K02.9', 'primary');

    // Initially clean for this rule.
    $sync = ssSyncIssues($ctx);
    expect(SatusehatDataQualityIssue::query()->where('rule_code', 'deprecated_diagnosis_selected')->count())->toBe(0);

    app(ClinicalDiagnosisService::class)->deprecate($dxRow->clinicalDiagnosis, $reviewer);
    ssSyncIssues($ctx);

    $issue = SatusehatDataQualityIssue::query()->where('rule_code', 'deprecated_diagnosis_selected')->first();
    expect($issue)->not->toBeNull()
        ->and($issue->severity)->toBe(SatusehatDataQualityIssue::SEVERITY_SOFT)
        ->and($issue->status)->toBe(SatusehatDataQualityIssue::STATUS_OPEN);

    // Re-code: remove the stale row, add an active one → issue auto-resolves.
    $dxRow->delete();
    ssDiagnosis($ctx, 'K04.0', 'primary');
    ssSyncIssues($ctx);

    expect($issue->fresh()->status)->toBe(SatusehatDataQualityIssue::STATUS_RESOLVED);
});

it('flags duplicate primaries as a HARD issue (historical integrity guard)', function () {
    $ctx = ssMakeVisit();
    ssDiagnosis($ctx, 'K02.9', 'primary');
    // Bypass the service guard to simulate an imported/legacy violation.
    ssDiagnosis($ctx, 'K04.0', 'primary');

    ssSyncIssues($ctx);

    $issue = SatusehatDataQualityIssue::query()->where('rule_code', 'duplicate_primary_diagnosis')->first();
    expect($issue)->not->toBeNull()
        ->and($issue->severity)->toBe(SatusehatDataQualityIssue::SEVERITY_HARD);
});

it('flags a malformed ICD-10 master code as a HARD issue', function () {
    $ctx = ssMakeVisit();
    $bad = ClinicalDiagnosis::factory()->create(['code' => 'BAD!!', 'display' => 'Broken import']);
    MedicalRecordDiagnosis::create([
        'medical_record_id' => $ctx['mr']->id,
        'clinic_visit_id' => $ctx['visit']->id,
        'branch_id' => $ctx['branch']->id,
        'clinical_diagnosis_id' => $bad->id,
        'diagnosis_role' => 'primary',
        'diagnosed_at' => now(),
    ]);

    ssSyncIssues($ctx);

    $issue = SatusehatDataQualityIssue::query()->where('rule_code', 'diagnosis_code_invalid')->first();
    expect($issue)->not->toBeNull()
        ->and($issue->severity)->toBe(SatusehatDataQualityIssue::SEVERITY_HARD);
});

it('deprecating terminology after approval drifts the source hash and revokes the approval', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $reviewer = superAdmin();
    $terminologyReviewer = userWith(['review_clinical_terminology']);

    $dxRow = ssDiagnosis($ctx, 'K02.1', 'primary', mapped: true);

    $candidate = ssService()->generateForVisit($ctx['visit']->fresh(['medicalRecord']));
    $candidate = ssService()->approve($candidate, $reviewer);
    expect($candidate->review_status)->toBe(SatusehatCandidate::REVIEW_APPROVED);

    // Clinical governance change: the selected terminology is deprecated.
    app(ClinicalDiagnosisService::class)->deprecate($dxRow->clinicalDiagnosis, $terminologyReviewer);

    $candidate = ssService()->refresh($candidate->fresh(), $reviewer);

    expect($candidate->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($candidate->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING)
        ->and($candidate->revoked_at)->not->toBeNull();
});

it('changing the primary via makePrimary swaps roles atomically, audits, and drifts an approved candidate', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $actor = superAdmin();

    $primary = ssDiagnosis($ctx, 'K02.1', 'primary', mapped: true);
    $secondary = ssDiagnosis($ctx, 'K04.0', 'secondary', mapped: true);

    $candidate = ssService()->generateForVisit($ctx['visit']->fresh(['medicalRecord']));
    $candidate = ssService()->approve($candidate, $actor);

    /*
     * CORRECTIVE-03 — the ROLE SWAP is a clinical write, so it needs a live
     * consented encounter. The candidate above was generated from the finished
     * visit, which is the real order of events; the correction happens while the
     * patient is back in the chair, and that is exactly what must drift the
     * already-approved candidate.
     */
    ssOpenEncounter($ctx, $actor);

    app(MedicalRecordDiagnosisService::class)->makePrimary($ctx['mr'], $secondary, $actor);

    expect($primary->fresh()->diagnosis_role)->toBe(MedicalRecordDiagnosis::ROLE_SECONDARY)
        ->and($secondary->fresh()->diagnosis_role)->toBe(MedicalRecordDiagnosis::ROLE_PRIMARY)
        ->and(SatusehatAuditLog::query()->where('event', SatusehatAuditLog::EVENT_DIAGNOSIS_ROLE_CHANGED)->count())->toBe(1);

    // The post-commit refresh revoked the stale approval (role is hashed).
    expect($candidate->fresh()->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($candidate->fresh()->revoked_at)->not->toBeNull();
});

it('an unchanged record never fabricates drift (hash is byte-stable across refreshes)', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $actor = superAdmin();
    ssDiagnosis($ctx, 'K02.1', 'primary', mapped: true);

    $candidate = ssService()->generateForVisit($ctx['visit']->fresh(['medicalRecord']));
    $candidate = ssService()->approve($candidate, $actor);
    $hash = $candidate->source_hash;

    $candidate = ssService()->refresh($candidate->fresh(), $actor);
    $candidate = ssService()->refresh($candidate->fresh(), $actor);

    expect($candidate->source_hash)->toBe($hash)
        ->and($candidate->readiness_status)->not->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($candidate->review_status)->toBe(SatusehatCandidate::REVIEW_APPROVED);

    Http::assertNothingSent();
});
