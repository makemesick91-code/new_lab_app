<?php

use App\Modules\MedicalRecord\Services\ClinicalDiagnosisService;
use App\Modules\Satusehat\Services\SatusehatFhirPreviewBuilder;
use App\Modules\Satusehat\Services\SatusehatReadinessService;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
});

function s4bPreview(array $ctx): array
{
    $visit = $ctx['visit']->fresh(['medicalRecord']);
    $readiness = app(SatusehatReadinessService::class)->evaluate($visit);

    return app(SatusehatFhirPreviewBuilder::class)->build($visit, $readiness);
}

it('renders a single honest unsupported Condition placeholder when no structured diagnosis exists', function () {
    $ctx = ssMakeVisit();

    $preview = s4bPreview($ctx);
    $conditions = collect($preview['resources'])->where('resource_type', 'Condition')->values();

    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]['supported'])->toBeFalse()
        ->and($conditions[0]['payload'])->toBeNull()
        ->and($preview['note'])->toBe(SatusehatFhirPreviewBuilder::HONEST_NOTE);

    Http::assertNothingSent();
});

it('builds one supported Condition per mapped structured diagnosis with terminology from the reviewed mapping only', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    ssDiagnosis($ctx, 'K02.1', 'primary', mapped: true);
    ssDiagnosis($ctx, 'K04.0', 'secondary', mapped: true);

    $preview = s4bPreview($ctx);
    $conditions = collect($preview['resources'])->where('resource_type', 'Condition')->values();

    expect($conditions)->toHaveCount(2)
        ->and($conditions[0]['supported'])->toBeTrue()
        ->and($conditions[0]['payload']['resourceType'])->toBe('Condition')
        ->and($conditions[0]['payload']['code']['coding'][0]['code'])->toBe('K02.1')
        ->and($conditions[0]['payload']['code']['coding'][0]['system'])->toBe('http://hl7.org/fhir/sid/icd-10')
        ->and($conditions[0]['payload']['category'][0]['coding'][0]['code'])->toBe('encounter-diagnosis')
        ->and($conditions[0]['payload']['local_diagnosis_role'])->toBe('primary')
        ->and($conditions[1]['payload']['local_diagnosis_role'])->toBe('secondary')
        ->and($conditions[0]['payload_hash'])->not->toBeNull();

    // The full NIK never appears anywhere in the preview.
    $json = json_encode($preview);
    expect($json)->not->toContain($ctx['patient']->ktp_number);

    Http::assertNothingSent();
});

it('reports mapping_blocked honestly for an unmapped diagnosis (never guesses terminology)', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    ssDiagnosis($ctx, 'K05.0', 'primary', mapped: false);

    $preview = s4bPreview($ctx);
    $condition = collect($preview['resources'])->firstWhere('resource_type', 'Condition');

    expect($condition['supported'])->toBeFalse()
        ->and(implode(' ', $condition['issues']))->toContain('mapping_blocked')
        ->and($condition['payload'])->toBeNull();
});

it('marks a Condition unsupported when its terminology is no longer active', function () {
    $ctx = ssMakeVisit();
    ssAddIdentifiers($ctx);
    $reviewer = userWith(['review_clinical_terminology']);
    $dxRow = ssDiagnosis($ctx, 'K02.9', 'primary', mapped: true);

    app(ClinicalDiagnosisService::class)->deprecate($dxRow->clinicalDiagnosis, $reviewer);

    $preview = s4bPreview($ctx);
    $condition = collect($preview['resources'])->firstWhere('resource_type', 'Condition');

    expect($condition['supported'])->toBeFalse()
        ->and(implode(' ', $condition['issues']))->toContain('tidak lagi aktif');
});
