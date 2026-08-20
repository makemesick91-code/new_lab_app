<?php

namespace App\Modules\Prescription\Gateways;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — the outbound WhatsApp Business
 * Platform boundary.
 *
 * Three implementations, mirroring the SATUSEHAT gateway pattern already used
 * in this codebase:
 *   - {@see DisabledWhatsAppGateway} — the default. Opens NO network connection.
 *   - {@see FakeWhatsAppGateway}     — deterministic, tests only.
 *   - {@see CloudApiWhatsAppGateway} — the real Meta Cloud API.
 *
 * Guard methods are part of the contract, and every call returns a sanitized
 * {@see WhatsAppApiResponse} rather than a raw HTTP response.
 */
interface WhatsAppGatewayInterface
{
    public function isEnabled(): bool;

    /**
     * Fail closed before any network call: throws when the integration is
     * disabled or its configuration is incomplete.
     */
    public function assertReadyToSend(): void;

    /**
     * Send an approved template message.
     *
     * Meta only delivers template messages outside an open 24-hour customer
     * service window, so a proactive prescription hand-off always uses one.
     *
     * @param  string  $recipientMsisdn  normalised E.164 digits, no '+'
     * @param  array<int, string>  $bodyParameters  positional body parameters
     */
    public function sendTemplate(
        string $recipientMsisdn,
        string $templateName,
        string $languageCode,
        array $bodyParameters = [],
    ): WhatsAppApiResponse;
}
