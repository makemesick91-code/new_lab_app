<?php

namespace App\Modules\Satusehat\Gateways;

use RuntimeException;

/**
 * SATUSEHAT-2 placeholder for the real HTTP adapter. It is intentionally inert
 * in SATUSEHAT-1: it has NO production submission implementation and it never
 * runs. The container binding never selects it while the integration is disabled.
 *
 * The constructor performs NO network I/O. Every operation throws until a future
 * sprint (SATUSEHAT-2) implements OAuth + FHIR submission behind
 * SATUSEHAT_ENABLED=true, valid credentials, and the external-submission flag.
 */
final class HttpSatusehatGateway implements SatusehatGatewayInterface
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
        throw $this->notImplemented('assertReadyForSubmission');
    }

    public function submitResource(string $resourceType, array $payload, string $idempotencyKey): SatusehatGatewayResult
    {
        throw $this->notImplemented("submitResource:{$resourceType}");
    }

    public function lookupPatientIdentifier(string $localIdentifier): SatusehatGatewayResult
    {
        throw $this->notImplemented('lookupPatientIdentifier');
    }

    private function notImplemented(string $operation): RuntimeException
    {
        return new RuntimeException(
            "HttpSatusehatGateway::{$operation} is not implemented in SATUSEHAT-1. ".
            'Real external submission is introduced in SATUSEHAT-2.'
        );
    }
}
