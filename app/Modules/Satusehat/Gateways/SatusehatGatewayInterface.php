<?php

namespace App\Modules\Satusehat\Gateways;

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
}
