<?php

use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Services\SatusehatIdentifierVerificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    Cache::flush();
    ssConfigureSandbox();
});

function ssIdentifier(array $overrides = []): SatusehatEntityIdentifier
{
    return SatusehatEntityIdentifier::create(array_merge([
        'environment' => 'sandbox',
        'entity_type' => SatusehatEntityIdentifier::ENTITY_PATIENT,
        'local_entity_type' => 'patient',
        'local_entity_id' => 1,
        'remote_identifier' => 'ihs-patient-1',
        'status' => SatusehatEntityIdentifier::STATUS_ACTIVE,
    ], $overrides));
}

it('verifies an identifier via GET-by-id and stamps verified_at', function () {
    Http::fake([
        '*/oauth2/*' => Http::response(['access_token' => 'T', 'expires_in' => '3600'], 200),
        '*/fhir-r4/*' => Http::response(['resourceType' => 'Patient', 'id' => 'ihs-patient-1', 'meta' => ['versionId' => '3']], 200),
    ]);

    $identifier = ssIdentifier();
    $result = app(SatusehatIdentifierVerificationService::class)->verify($identifier, userWith(['manage_satusehat_settings']));

    expect($result['verified'])->toBeTrue()
        ->and($identifier->fresh()->verified_at)->not->toBeNull();

    // NIK is never placed in the request URL.
    Http::assertSent(fn ($r) => ! str_contains($r->url(), 'ktp') && ! str_contains($r->url(), 'nik'));
});

it('refuses verification while the integration is disabled', function () {
    config()->set('satusehat.send_enabled', false);

    app(SatusehatIdentifierVerificationService::class)->verify(ssIdentifier(), userWith(['manage_satusehat_settings']));
})->throws(ValidationException::class);

it('refuses to verify a production identifier while sandbox-only', function () {
    Http::fake();

    app(SatusehatIdentifierVerificationService::class)->verify(ssIdentifier(['environment' => 'production']), userWith(['manage_satusehat_settings']));
})->throws(ValidationException::class);

it('rate-limits verification attempts', function () {
    Http::fake([
        '*/oauth2/*' => Http::response(['access_token' => 'T', 'expires_in' => '3600'], 200),
        '*/fhir-r4/*' => Http::response(['resourceType' => 'Patient', 'id' => 'ihs-patient-1'], 200),
    ]);

    $identifier = ssIdentifier();
    $service = app(SatusehatIdentifierVerificationService::class);

    for ($i = 0; $i < 5; $i++) {
        $service->verify($identifier, userWith(['manage_satusehat_settings']));
    }

    expect(fn () => $service->verify($identifier, userWith(['manage_satusehat_settings'])))
        ->toThrow(ValidationException::class);
});
