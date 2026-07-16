<?php

use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Services\Dental\SatusehatDentalReadinessService;
use App\Modules\Satusehat\Services\Dental\SatusehatDentalResourceBuilder;
use App\Modules\Satusehat\Support\SatusehatDentalConformanceValidator;
use App\Modules\Satusehat\Support\SatusehatSourceHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
});

it('reports dental_incomplete when there is no odontogram', function () {
    $ctx = ssMakeVisit();

    $result = app(SatusehatDentalReadinessService::class)->evaluate($ctx['visit']);

    expect($result->status)->toBe(SatusehatCandidate::DENTAL_INCOMPLETE)
        ->and(array_column($result->reasons, 'code'))->toContain('dental_odontogram_missing');
    Http::assertNothingSent();
});

it('reports dental_mapping_blocked when the odontogram has teeth but no active mapping', function () {
    $ctx = ssMakeVisit();
    ssOdontogram($ctx, ['48' => 'caries']);

    $result = app(SatusehatDentalReadinessService::class)->evaluate($ctx['visit']);

    expect($result->status)->toBe(SatusehatCandidate::DENTAL_MAPPING_BLOCKED);
    Http::assertNothingSent();
});

it('reports dental_unsupported for an unknown local tooth status', function () {
    $ctx = ssMakeVisit();
    ssOdontogram($ctx, ['48' => 'totally_unknown_status']);

    $result = app(SatusehatDentalReadinessService::class)->evaluate($ctx['visit']);

    expect($result->status)->toBe(SatusehatCandidate::DENTAL_UNSUPPORTED);
});

it('reaches dental_ready with active mappings + IHS identifiers (golden fixture)', function () {
    $ctx = ssMakeVisit();
    $teeth = ['48' => 'caries', '11' => 'filling', '46' => 'missing'];
    ssOdontogram($ctx, $teeth);
    ssActivateDentalMappings($teeth);
    ssAddIdentifiers($ctx);

    $result = app(SatusehatDentalReadinessService::class)->evaluate($ctx['visit']);

    expect($result->status)->toBe(SatusehatCandidate::DENTAL_READY)
        ->and($result->coverage['tooth_count'])->toBe(3);
    Http::assertNothingSent();
});

it('produces a locally-valid Observation for every supported dental resource', function () {
    $ctx = ssMakeVisit();
    $teeth = ['48' => 'caries'];
    ssOdontogram($ctx, $teeth);
    ssActivateDentalMappings($teeth);
    ssAddIdentifiers($ctx);

    $preview = app(SatusehatDentalResourceBuilder::class)->build($ctx['visit']);
    $validator = app(SatusehatDentalConformanceValidator::class);

    $supported = collect($preview['resources'])->filter(fn ($r) => $r['supported'] && $r['payload'] !== null);
    expect($supported)->not->toBeEmpty();
    foreach ($supported as $r) {
        expect($validator->validate($r['payload'])['result'])->toBe('valid');
    }
});

it('never emits a name, NIK, or raw note in the dental coverage snapshot', function () {
    $ctx = ssMakeVisit();
    ssOdontogram($ctx, ['48' => 'caries'], summary: 'Catatan rahasia pasien');

    $result = app(SatusehatDentalReadinessService::class)->evaluate($ctx['visit']);
    $json = json_encode($result->coverage);

    expect($json)->not->toContain('Catatan rahasia pasien')
        ->and($json)->not->toContain($ctx['patient']->name)
        ->and($json)->not->toContain((string) $ctx['patient']->ktp_number);
});

it('never places the patient or doctor name inside a built dental payload', function () {
    $ctx = ssMakeVisit();
    $teeth = ['48' => 'caries'];
    ssOdontogram($ctx, $teeth);
    ssActivateDentalMappings($teeth);
    ssAddIdentifiers($ctx);

    $preview = app(SatusehatDentalResourceBuilder::class)->build($ctx['visit']);
    $json = json_encode($preview['resources']);

    expect($json)->not->toContain($ctx['patient']->name)
        ->and($json)->not->toContain($ctx['doctor']->name)
        ->and($json)->not->toContain((string) $ctx['patient']->ktp_number);
});

it('produces a deterministic dental source hash for identical odontograms', function () {
    $a = ssMakeVisit();
    ssOdontogram($a, ['48' => 'caries', '11' => 'filling']);
    $b = ssMakeVisit();
    ssOdontogram($b, ['11' => 'filling', '48' => 'caries']); // different key order

    $hasher = app(SatusehatSourceHasher::class);
    $ra = app(SatusehatDentalReadinessService::class)->evaluate($a['visit']);
    $rb = app(SatusehatDentalReadinessService::class)->evaluate($b['visit']);

    expect($hasher->hash($ra->facts))->toBe($hasher->hash($rb->facts));
});
