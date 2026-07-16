<?php

use App\Modules\Satusehat\Exceptions\SatusehatIntegrationDisabledException;
use App\Modules\Satusehat\Gateways\DisabledSatusehatGateway;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

it('binds the disabled gateway by default (SATUSEHAT_ENABLED=false)', function () {
    config()->set('satusehat.enabled', false);

    expect(app(SatusehatGatewayInterface::class))->toBeInstanceOf(DisabledSatusehatGateway::class);
});

it('makes no network call and throws when disabled', function () {
    config()->set('satusehat.enabled', false);
    Http::fake();

    $gateway = app(SatusehatGatewayInterface::class);

    expect(fn () => $gateway->createResource('Encounter', [], 'c'))
        ->toThrow(SatusehatIntegrationDisabledException::class);

    Http::assertNothingSent();
});

it('fails closed to the disabled gateway when the environment is production', function () {
    config()->set('satusehat.enabled', true);
    config()->set('satusehat.sandbox_only', true);
    config()->set('satusehat.environment', 'production');
    config()->set('satusehat.oauth_base_url', 'https://api-satusehat.kemkes.go.id/oauth2/v1');
    config()->set('satusehat.fhir_base_url', 'https://api-satusehat.kemkes.go.id/fhir-r4/v1');
    config()->set('satusehat.client_id', 'x');
    config()->set('satusehat.client_secret', 'y');
    config()->set('satusehat.organization_id', 'o');
    config()->set('satusehat.location_id', 'l');

    expect(app(SatusehatGatewayInterface::class))->toBeInstanceOf(DisabledSatusehatGateway::class);
});
