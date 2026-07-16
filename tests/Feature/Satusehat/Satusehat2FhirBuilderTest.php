<?php

use App\Modules\Satusehat\Exceptions\SatusehatBuildException;
use App\Modules\Satusehat\Services\SatusehatFhirResourceBuilder;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.environment', 'sandbox');
    config()->set('satusehat.clinic_timezone', 'Asia/Makassar');
});

function ssFacts(array $ids = [], array $treatments = []): array
{
    return [
        'environment' => 'sandbox',
        'identifiers' => array_merge([
            'patient' => 'ihs-patient-1',
            'practitioner' => 'ihs-doctor-1',
            'organization' => 'ORG-1',
            'location' => 'LOC-1',
        ], $ids),
        'patient' => ['name' => 'Pasien Uji'],
        'treatments' => $treatments,
    ];
}

it('builds a valid Encounter with real IHS references and UTC period', function () {
    $ctx = ssMakeVisit();
    $encounter = app(SatusehatFhirResourceBuilder::class)->buildEncounter($ctx['visit'], ssFacts());

    expect($encounter['resourceType'])->toBe('Encounter')
        ->and($encounter['subject']['reference'])->toBe('Patient/ihs-patient-1')
        ->and($encounter['serviceProvider']['reference'])->toBe('Organization/ORG-1')
        ->and($encounter['participant'][0]['individual']['reference'])->toBe('Practitioner/ihs-doctor-1')
        ->and($encounter['period']['start'])->toMatch('/Z|\+00:00$/');

    // No out-of-scope / PII content.
    expect(json_encode($encounter))->not->toContain('odontogram')
        ->and(json_encode($encounter))->not->toContain('ktp');
});

it('throws (stays blocked) when the patient IHS is missing', function () {
    $ctx = ssMakeVisit();
    app(SatusehatFhirResourceBuilder::class)->buildEncounter($ctx['visit'], ssFacts(['patient' => null]));
})->throws(SatusehatBuildException::class);

it('builds a Procedure using terminology only from the active mapping', function () {
    $ctx = ssMakeVisit();
    ssTreatmentMapping(777);

    $procedure = app(SatusehatFhirResourceBuilder::class)->buildProcedure(
        $ctx['visit'],
        ['treatment_id' => 777],
        ssFacts(),
        'enc-remote-1',
    );

    expect($procedure['code']['coding'][0]['system'])->toBe('http://snomed.info/sct')
        ->and($procedure['code']['coding'][0]['code'])->toBe('2340003')
        ->and($procedure['encounter']['reference'])->toBe('Encounter/enc-remote-1')
        ->and($procedure['subject']['reference'])->toBe('Patient/ihs-patient-1');
});

it('refuses to build a Procedure for a treatment without an active mapping (never invents a code)', function () {
    $ctx = ssMakeVisit();
    app(SatusehatFhirResourceBuilder::class)->buildProcedure($ctx['visit'], ['treatment_id' => 999], ssFacts(), 'enc-1');
})->throws(SatusehatBuildException::class);

it('never fabricates a Condition when there is no structured diagnosis source', function () {
    $conditions = app(SatusehatFhirResourceBuilder::class)->buildConditions(ssFacts(), 'enc-1');

    expect($conditions)->toBe([]);
});
