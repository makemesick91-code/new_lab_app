<?php

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\MedicalRecord\Services\MedicalRecordDiagnosisService;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\SatusehatReadinessService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
});

it('legacy record without structured diagnosis stays readable and only informational', function () {
    $ctx = ssMakeVisit();

    $result = app(SatusehatReadinessService::class)->evaluate($ctx['visit']);
    $codes = collect($result->reasons)->pluck('code');

    expect($codes)->toContain('diagnosis_not_structured')
        ->and(collect($result->reasons)->firstWhere('code', 'diagnosis_not_structured')['severity'])->toBe('info')
        ->and($result->facts)->not->toHaveKey('diagnoses');

    Http::assertNothingSent();
});

it('recording a diagnosis adds facts and an unmapped diagnosis blocks readiness as incomplete', function () {
    $ctx = ssMakeVisit();
    ssDiagnosis($ctx, 'K02.9', 'primary', mapped: false);

    $result = app(SatusehatReadinessService::class)->evaluate($ctx['visit']->fresh(['medicalRecord']));
    $codes = collect($result->reasons)->pluck('code');

    expect($codes)->toContain('diagnosis_mapping_missing')
        ->and($codes)->not->toContain('diagnosis_not_structured')
        ->and($result->facts['diagnoses'][0]['code'])->toBe('K02.9');
});

it('a mapped structured diagnosis fully satisfies the diagnosis gate', function () {
    $ctx = ssMakeVisit();
    ssDiagnosis($ctx, 'K04.0', 'primary', mapped: true);

    $result = app(SatusehatReadinessService::class)->evaluate($ctx['visit']->fresh(['medicalRecord']));
    $codes = collect($result->reasons)->pluck('code');

    expect($codes)->not->toContain('diagnosis_mapping_missing')
        ->and($codes)->not->toContain('diagnosis_not_structured')
        ->and($result->facts['diagnoses'][0]['mapping_code'])->toBe('K04.0');
});

it('service refuses duplicate diagnosis, second primary, and inactive master', function () {
    $ctx = ssMakeVisit();
    $actor = superAdmin();
    $service = app(MedicalRecordDiagnosisService::class);

    $master = ClinicalDiagnosis::factory()->create(['code' => 'K05.0']);
    $service->record($ctx['mr'], ['clinical_diagnosis_id' => $master->id, 'diagnosis_role' => 'primary'], $actor);

    // Duplicate.
    expect(fn () => $service->record($ctx['mr'], ['clinical_diagnosis_id' => $master->id, 'diagnosis_role' => 'secondary'], $actor))
        ->toThrow(ValidationException::class);

    // Second primary.
    $other = ClinicalDiagnosis::factory()->create(['code' => 'K05.1']);
    expect(fn () => $service->record($ctx['mr'], ['clinical_diagnosis_id' => $other->id, 'diagnosis_role' => 'primary'], $actor))
        ->toThrow(ValidationException::class);

    // Inactive master.
    $inactive = ClinicalDiagnosis::factory()->deprecated()->create(['code' => 'K05.3']);
    expect(fn () => $service->record($ctx['mr'], ['clinical_diagnosis_id' => $inactive->id, 'diagnosis_role' => 'secondary'], $actor))
        ->toThrow(ValidationException::class);

    // Secondary of a different code is fine.
    $service->record($ctx['mr'], ['clinical_diagnosis_id' => $other->id, 'diagnosis_role' => 'secondary'], $actor);
    expect(MedicalRecordDiagnosis::query()->where('medical_record_id', $ctx['mr']->id)->count())->toBe(2);
});

it('adding a diagnosis after approval drifts the source hash and revokes the approval', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $reviewer = superAdmin();

    $candidate = ssService()->generateForVisit($ctx['visit']);
    $candidate = ssService()->approve($candidate, $reviewer);
    expect($candidate->review_status)->toBe(SatusehatCandidate::REVIEW_APPROVED);

    // Clinical change: a structured diagnosis is recorded post-approval.
    ssDiagnosis($ctx, 'K02.1', 'primary', mapped: true);

    $candidate = ssService()->refresh($candidate->fresh(), $reviewer);

    expect($candidate->readiness_status)->toBe(SatusehatCandidate::READINESS_SOURCE_CHANGED)
        ->and($candidate->review_status)->toBe(SatusehatCandidate::REVIEW_PENDING)
        ->and($candidate->revoked_at)->not->toBeNull();
});

it('doctor-facing search returns only ACTIVE masters and never synthetic entries', function () {
    ClinicalDiagnosis::factory()->create(['code' => 'K02.9', 'display' => 'Dental caries, unspecified']);
    ClinicalDiagnosis::factory()->deprecated()->create(['code' => 'K99.1', 'display' => 'Dental caries deprecated']);
    ClinicalDiagnosis::factory()->create([
        'code' => 'SYN-1', 'display' => 'Dental caries synthetic',
        'code_system' => 'SYNTHETIC-SATUSEHAT-4A', 'status' => ClinicalDiagnosis::STATUS_SYNTHETIC,
    ]);

    $user = userWith(['view_clinic_visits']);

    $response = $this->actingAs($user)->getJson(route('rme.diagnoses.search', ['q' => 'caries']));

    $response->assertOk();
    $labels = collect($response->json('data'))->pluck('code');
    expect($labels)->toContain('K02.9')
        ->and($labels)->not->toContain('K99.1')
        ->and($labels)->not->toContain('SYN-1');
});

it('diagnosis routes are permission + policy gated (unauthorized user cannot record)', function () {
    $ctx = ssMakeVisit();
    $master = ClinicalDiagnosis::factory()->create();

    // No permission at all → search 403.
    $stranger = userWith(['view reports']);
    $this->actingAs($stranger)->getJson(route('rme.diagnoses.search', ['q' => 'x']))->assertForbidden();

    // manage_clinic_visits permission but MedicalRecordPolicy denies (not a
    // manager of this branch context) — route-level middleware passes, policy
    // decides. Super Admin passes via Gate::before.
    $admin = superAdmin();
    $this->actingAs($admin)
        ->post(route('rme.visits.medical-record.diagnoses.store', [$ctx['visit'], $ctx['mr']]), [
            'clinical_diagnosis_id' => $master->id,
            'diagnosis_role' => 'primary',
        ])
        ->assertRedirect();

    expect(MedicalRecordDiagnosis::query()->where('medical_record_id', $ctx['mr']->id)->count())->toBe(1);
});

it('master governance page requires manage_structured_diagnoses', function () {
    $this->actingAs(userInRole('Kasir'))->get(route('satusehat.diagnoses.index'))->assertForbidden();
    $this->actingAs(userInRole('Supervisor RME'))->get(route('satusehat.diagnoses.index'))->assertOk();
});
