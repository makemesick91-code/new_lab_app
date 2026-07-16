<?php

namespace App\Modules\Satusehat\Gateways;

use App\Modules\Satusehat\Exceptions\SatusehatIntegrationDisabledException;

/**
 * Default runtime gateway for SATUSEHAT-1. Opens NO network connection under any
 * circumstance. Every external operation fails closed: submission/lookup throw,
 * and the readiness guard throws. Selecting this gateway is what guarantees the
 * app never talks to the SATUSEHAT API while the integration is disabled.
 */
final class DisabledSatusehatGateway implements SatusehatGatewayInterface
{
    public function __construct(
        private readonly string $environment = 'sandbox',
    ) {}

    public function isEnabled(): bool
    {
        return false;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function assertReadyForSubmission(): void
    {
        throw SatusehatIntegrationDisabledException::forOperation('assertReadyForSubmission');
    }

    public function submitResource(string $resourceType, array $payload, string $idempotencyKey): SatusehatGatewayResult
    {
        // Never performs I/O. Callers must treat this as "not available".
        throw SatusehatIntegrationDisabledException::forOperation("submitResource:{$resourceType}");
    }

    public function lookupPatientIdentifier(string $localIdentifier): SatusehatGatewayResult
    {
        throw SatusehatIntegrationDisabledException::forOperation('lookupPatientIdentifier');
    }

    public function assertSendAllowed(): void
    {
        throw SatusehatIntegrationDisabledException::forOperation('assertSendAllowed');
    }

    public function createResource(string $resourceType, array $payload, string $correlationId): SatusehatApiResponse
    {
        throw SatusehatIntegrationDisabledException::forOperation("createResource:{$resourceType}");
    }

    public function updateResource(string $resourceType, string $remoteId, array $payload, string $correlationId): SatusehatApiResponse
    {
        throw SatusehatIntegrationDisabledException::forOperation("updateResource:{$resourceType}");
    }

    public function getResource(string $resourceType, string $remoteId, string $correlationId): SatusehatApiResponse
    {
        throw SatusehatIntegrationDisabledException::forOperation("getResource:{$resourceType}");
    }

    public function verifyIdentifier(string $entityType, array $searchParams, string $correlationId): SatusehatApiResponse
    {
        throw SatusehatIntegrationDisabledException::forOperation("verifyIdentifier:{$entityType}");
    }

    public function connectionStatus(): array
    {
        return [
            'gateway' => 'disabled',
            'environment' => $this->environment,
            'send_enabled' => 'false',
        ];
    }
}
