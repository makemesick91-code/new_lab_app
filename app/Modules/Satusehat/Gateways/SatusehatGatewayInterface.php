<?php

namespace App\Modules\Satusehat\Gateways;

use App\Modules\Satusehat\Exceptions\SatusehatGatewayException;

/**
 * Contract for every SATUSEHAT gateway. The runtime binding defaults to
 * DisabledSatusehatGateway (SATUSEHAT-1). No implementation may perform a real
 * network request while the integration is disabled.
 *
 * Implementations:
 *   - DisabledSatusehatGateway — default; every external op throws, no network.
 *   - FakeSatusehatGateway     — tests only; in-memory, deterministic, no network.
 *   - HttpSatusehatGateway     — SATUSEHAT-2 placeholder; not active in SATUSEHAT-1.
 */
interface SatusehatGatewayInterface
{
    /** Whether external submission/lookup is enabled at runtime. */
    public function isEnabled(): bool;

    /** The selected environment (sandbox|production) — never mixes the two. */
    public function environment(): string;

    /**
     * Guard called before any submission is attempted. Throws
     * SatusehatIntegrationDisabledException when integration is disabled.
     * This is the single chokepoint that keeps SATUSEHAT-1 network-silent.
     */
    public function assertReadyForSubmission(): void;

    /**
     * Future (SATUSEHAT-2) resource submission entrypoint. In SATUSEHAT-1 no
     * caller reaches a real implementation; the disabled gateway throws first.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submitResource(string $resourceType, array $payload, string $idempotencyKey): SatusehatGatewayResult;

    /**
     * Future (SATUSEHAT-2) IHS identifier lookup. Disabled in SATUSEHAT-1.
     */
    public function lookupPatientIdentifier(string $localIdentifier): SatusehatGatewayResult;

    // ------------------------------------------------------------------
    // SATUSEHAT-2 — typed FHIR operations. Every implementation must route
    // through the host allowlist + sandbox lock and return a fully-sanitized
    // {@see SatusehatApiResponse} (never a raw body/token/NIK). The DISABLED
    // gateway throws; the FAKE gateway is deterministic; the HTTP gateway calls
    // the sandbox.
    // ------------------------------------------------------------------

    /**
     * Assert the outbound path is fully allowed right now: send kill switch on,
     * environment sandbox, host allowlisted, circuit breaker closed. Throws a
     * {@see SatusehatGatewayException} otherwise.
     */
    public function assertSendAllowed(): void;

    /**
     * Create a FHIR resource (POST). $payload is a validated, immutable snapshot.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createResource(string $resourceType, array $payload, string $correlationId): SatusehatApiResponse;

    /**
     * Update/replace a FHIR resource by its remote id (PUT) — used for the final
     * Encounter reconciliation. Never creates a second resource.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateResource(string $resourceType, string $remoteId, array $payload, string $correlationId): SatusehatApiResponse;

    /**
     * GET a resource by remote id — used for reconciliation after an ambiguous
     * (unknown-outcome) submission.
     */
    public function getResource(string $resourceType, string $remoteId, string $correlationId): SatusehatApiResponse;

    /**
     * Verify an IHS identifier for an entity (Patient/Practitioner/Organization/
     * Location) via the official search the API permits. Sandbox-only.
     *
     * @param  array<string, string>  $searchParams
     */
    public function verifyIdentifier(string $entityType, array $searchParams, string $correlationId): SatusehatApiResponse;

    /**
     * Value-free token/connection diagnostics for the health surface.
     *
     * @return array<string, string>
     */
    public function connectionStatus(): array;
}
