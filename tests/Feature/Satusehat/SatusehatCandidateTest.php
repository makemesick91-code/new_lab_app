<?php

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\SatusehatFhirPreviewBuilder;
use App\Modules\Satusehat\Services\SatusehatReadinessService;
use Carbon\Carbon;

require_once __DIR__.'/helpers.php';

// These tests exercise the explicit service path deterministically; the
// post-commit observer is covered separately in SatusehatObserverTest.
beforeEach(fn () => config()->set('satusehat.candidate.auto_generate', false));

it('generates one idempotent pending candidate for a completed visit with final MR', function () {
    $ctx = ssMakeVisit();

    $first = ssService()->generateForVisit($ctx['visit']);
    $second = ssService()->generateForVisit($ctx['visit']->fresh());

    expect($first)->not->toBeNull()
        ->and(SatusehatCandidate::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($first->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING);
});

it('never generates for an ineligible visit', function () {
    // Not completed.
    $waiting = ssMakeVisit(['status' => ClinicVisit::STATUS_WAITING]);
    expect(ssService()->generateForVisit($waiting['visit']))->toBeNull();

    // Completed but MR not final.
    $ctx = ssMakeVisit();
    $ctx['mr']->update(['status' => MedicalRecord::STATUS_DRAFT, 'finalized_at' => null]);
    expect(ssService()->generateForVisit($ctx['visit']->fresh()))->toBeNull();

    expect(SatusehatCandidate::count())->toBe(0);
});

it('never generates for a non-RME branch', function () {
    $ctx = ssMakeVisit();
    $ctx['branch']->update(['is_rme_enabled' => false]);

    expect(ssService()->generateForVisit($ctx['visit']->fresh()))->toBeNull()
        ->and(SatusehatCandidate::count())->toBe(0);
});

it('is incomplete when IHS identifiers are missing', function () {
    $ctx = ssMakeVisit();

    $candidate = ssService()->generateForVisit($ctx['visit']);

    expect($candidate->readiness_status)->toBe(SatusehatCandidate::READINESS_INCOMPLETE)
        ->and(collect($candidate->readiness_reasons)->pluck('code'))->toContain('patient_ihs_missing');
});

it('is ready when identifiers are present and there are no unmapped treatments', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);

    $candidate = ssService()->generateForVisit($ctx['visit']);

    expect($candidate->readiness_status)->toBe(SatusehatCandidate::READINESS_READY);
});

it('is blocked for a cancelled visit (readiness engine)', function () {
    $ctx = ssMakeVisit(['status' => ClinicVisit::STATUS_CANCELLED]);

    $result = app(SatusehatReadinessService::class)->evaluate($ctx['visit']);

    expect($result->status)->toBe(SatusehatCandidate::READINESS_BLOCKED)
        ->and(collect($result->reasons)->pluck('code'))->toContain('visit_cancelled');
});

it('keeps a stable source hash when nothing clinical changes', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);

    $candidate = ssService()->generateForVisit($ctx['visit']);
    $hash = $candidate->source_hash;
    $version = $candidate->source_version;

    ssService()->generateForVisit($ctx['visit']->fresh());

    // Re-generating with no clinical change must not drift the hash or bump the version.
    expect($candidate->fresh()->source_hash)->toBe($hash)
        ->and($candidate->fresh()->source_version)->toBe($version);
});

it('revokes approval and flags source_changed when the clinical source changes', function () {
    seedAccessControl();
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $reviewer = userWith(['review_satusehat_submissions']);

    $candidate = ssService()->generateForVisit($ctx['visit']);
    $approved = ssService()->approve($candidate, $reviewer);
    expect($approved->review_status)->toBe(SatusehatCandidate::REVIEW_APPROVED);

    // Change the clinical source: a different handling doctor.
    $newDoctor = Doctor::factory()->create();
    $ctx['visit']->update(['doctor_id' => $newDoctor->id]);

    $refreshed = ssService()->refresh($candidate->fresh(), $reviewer);

    expect($refreshed->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($refreshed->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING)
        ->and($refreshed->revoked_at)->not->toBeNull()
        ->and($refreshed->approved_by)->toBe($reviewer->id) // retained for audit
        ->and($refreshed->source_version)->toBeGreaterThan(1);
});

it('builds a local FHIR preview with UTC-normalized period and no odontogram', function () {
    $ctx = ssMakeVisit([
        'started_at' => Carbon::parse('2026-01-01 10:00:00', 'Asia/Makassar'),
        'completed_at' => Carbon::parse('2026-01-01 11:00:00', 'Asia/Makassar'),
    ]);
    ssAddIdentifiers($ctx);

    $result = app(SatusehatReadinessService::class)->evaluate($ctx['visit']);
    $preview = app(SatusehatFhirPreviewBuilder::class)->build($ctx['visit'], $result);

    $encounter = collect($preview['resources'])->firstWhere('resource_type', 'Encounter');

    // WITA 10:00 → UTC 02:00.
    expect($encounter['payload']['period']['start'])->toBe('2026-01-01T02:00:00+00:00')
        ->and($preview['note'])->toContain('belum dikirim')
        ->and(json_encode($preview))->not->toContain('odontogram');
});
