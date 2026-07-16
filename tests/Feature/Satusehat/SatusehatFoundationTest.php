<?php

use App\Modules\Satusehat\Exceptions\SatusehatIntegrationDisabledException;
use App\Modules\Satusehat\Gateways\DisabledSatusehatGateway;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use App\Modules\Satusehat\Support\SatusehatSourceHasher;
use Illuminate\Support\Facades\Http;

it('binds the disabled gateway by default and integration stays off', function () {
    expect(config('satusehat.enabled'))->toBeFalse();

    $gateway = app(SatusehatGatewayInterface::class);

    expect($gateway)->toBeInstanceOf(DisabledSatusehatGateway::class)
        ->and($gateway->isEnabled())->toBeFalse();
});

it('disabled gateway throws and never performs a network call', function () {
    Http::fake();

    $gateway = app(SatusehatGatewayInterface::class);

    expect(fn () => $gateway->submitResource('Encounter', ['x' => 1], 'key'))
        ->toThrow(SatusehatIntegrationDisabledException::class);
    expect(fn () => $gateway->lookupPatientIdentifier('123'))
        ->toThrow(SatusehatIntegrationDisabledException::class);
    expect(fn () => $gateway->assertReadyForSubmission())
        ->toThrow(SatusehatIntegrationDisabledException::class);

    Http::assertNothingSent();
});

it('fails closed to the disabled gateway when config is incomplete even if enabled', function () {
    config()->set('satusehat.enabled', true); // but URLs/credentials are blank
    app()->forgetInstance(SatusehatGatewayInterface::class);

    expect(app(SatusehatGatewayInterface::class))->toBeInstanceOf(DisabledSatusehatGateway::class);
});

it('produces a deterministic, key-order-independent source hash', function () {
    $hasher = new SatusehatSourceHasher;

    expect($hasher->hash(['a' => 1, 'b' => ['x' => 2, 'y' => 3]]))
        ->toBe($hasher->hash(['b' => ['y' => 3, 'x' => 2], 'a' => 1]));

    expect($hasher->hash(['a' => 1]))->not->toBe($hasher->hash(['a' => 2]));
});

it('writes append-only audit logs that mask sensitive values', function () {
    $log = app(SatusehatAuditLogger::class)->log(
        'satusehat_candidate', 1, SatusehatAuditLog::EVENT_APPROVED,
        'Pasien NIK 3273010101010001 disetujui',
        ['note' => 'token abc 3273010101010001'],
    );

    expect($log->created_at)->not->toBeNull()
        ->and(str_contains((string) $log->summary, '3273010101010001'))->toBeFalse()
        ->and(str_contains(json_encode($log->context), '3273010101010001'))->toBeFalse();

    // Append-only: the model disables updated_at.
    expect(SatusehatAuditLog::UPDATED_AT)->toBeNull();
});
