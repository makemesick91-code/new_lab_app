<?php

namespace App\Modules\Satusehat\Gateways;

/**
 * In-memory gateway for TESTS ONLY. Deterministic, records calls, and opens no
 * network connection. It exists so tests can assert the readiness/candidate/
 * filter flow without any real integration — never bound in production.
 */
final class FakeSatusehatGateway implements SatusehatGatewayInterface
{
    /** @var list<array<string, mixed>> */
    public array $submissions = [];

    /** @var list<string> */
    public array $lookups = [];

    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $environment = 'sandbox',
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function assertReadyForSubmission(): void
    {
        // No-op in the fake: tests opt in explicitly.
    }

    public function submitResource(string $resourceType, array $payload, string $idempotencyKey): SatusehatGatewayResult
    {
        $this->submissions[] = [
            'resource_type' => $resourceType,
            'idempotency_key' => $idempotencyKey,
        ];

        return SatusehatGatewayResult::ok('fake-submitted', [
            'remote_resource_id' => 'fake-'.$resourceType.'-'.count($this->submissions),
        ]);
    }

    public function lookupPatientIdentifier(string $localIdentifier): SatusehatGatewayResult
    {
        $this->lookups[] = $localIdentifier;

        return SatusehatGatewayResult::ok('fake-lookup', [
            'remote_identifier' => 'fake-ihs-'.count($this->lookups),
        ]);
    }
}
