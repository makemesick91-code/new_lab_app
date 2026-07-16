<?php

namespace App\Modules\Satusehat\Gateways;

use App\Modules\Satusehat\Exceptions\SatusehatGatewayException;
use App\Modules\Satusehat\Support\SatusehatOutcome;

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

    /** @var list<array<string, mixed>> */
    public array $createdResources = [];

    /** @var list<array<string, mixed>> */
    public array $updatedResources = [];

    /** @var list<array<string, mixed>> */
    public array $fetchedResources = [];

    /** @var list<array<string, mixed>> */
    public array $verifications = [];

    /**
     * Scripted responses, consumed FIFO per resource type. When empty a
     * deterministic success is returned. Set via {@see scriptResponse()}.
     *
     * @var array<string, list<SatusehatApiResponse>>
     */
    private array $scripted = [];

    public bool $sendAllowed = true;

    public function __construct(
        private readonly bool $enabled = true,
        private readonly string $environment = 'sandbox',
    ) {}

    /**
     * Queue a scripted response for the next create/update/get of $resourceType.
     */
    public function scriptResponse(string $resourceType, SatusehatApiResponse $response): void
    {
        $this->scripted[$resourceType][] = $response;
    }

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

    public function assertSendAllowed(): void
    {
        if (! $this->sendAllowed) {
            throw new SatusehatGatewayException('Pengiriman SATUSEHAT tidak diizinkan (fake).', SatusehatOutcome::PERMANENT);
        }
    }

    public function createResource(string $resourceType, array $payload, string $correlationId): SatusehatApiResponse
    {
        $this->createdResources[] = ['resource_type' => $resourceType, 'correlation_id' => $correlationId, 'payload' => $payload];

        if ($scripted = $this->nextScripted($resourceType)) {
            return $scripted;
        }

        return SatusehatApiResponse::success($resourceType, 'fake-'.strtolower($resourceType).'-'.count($this->createdResources), '1', 201, $correlationId);
    }

    public function updateResource(string $resourceType, string $remoteId, array $payload, string $correlationId): SatusehatApiResponse
    {
        $this->updatedResources[] = ['resource_type' => $resourceType, 'remote_id' => $remoteId, 'correlation_id' => $correlationId];

        if ($scripted = $this->nextScripted($resourceType)) {
            return $scripted;
        }

        return SatusehatApiResponse::success($resourceType, $remoteId, '2', 200, $correlationId);
    }

    public function getResource(string $resourceType, string $remoteId, string $correlationId): SatusehatApiResponse
    {
        $this->fetchedResources[] = ['resource_type' => $resourceType, 'remote_id' => $remoteId, 'correlation_id' => $correlationId];

        if ($scripted = $this->nextScripted($resourceType)) {
            return $scripted;
        }

        return SatusehatApiResponse::success($resourceType, $remoteId, '1', 200, $correlationId);
    }

    public function verifyIdentifier(string $entityType, array $searchParams, string $correlationId): SatusehatApiResponse
    {
        $this->verifications[] = ['entity_type' => $entityType, 'correlation_id' => $correlationId];

        if ($scripted = $this->nextScripted($entityType)) {
            return $scripted;
        }

        return SatusehatApiResponse::success($entityType, 'fake-'.strtolower($entityType).'-'.count($this->verifications), '1', 200, $correlationId);
    }

    public function connectionStatus(): array
    {
        return [
            'gateway' => 'fake',
            'environment' => $this->environment,
            'send_enabled' => $this->sendAllowed ? 'true' : 'false',
        ];
    }

    private function nextScripted(string $key): ?SatusehatApiResponse
    {
        if (! empty($this->scripted[$key])) {
            return array_shift($this->scripted[$key]);
        }

        return null;
    }
}
