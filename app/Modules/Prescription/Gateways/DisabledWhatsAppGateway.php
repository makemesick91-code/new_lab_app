<?php

namespace App\Modules\Prescription\Gateways;

use App\Modules\Prescription\Exceptions\WhatsAppDeliveryException;

/**
 * FIX-02 — the default binding. Opens NO network connection under any
 * circumstance, so a deployment without credentials can never leak a
 * prescription to a third party or hang on an outbound call.
 */
final class DisabledWhatsAppGateway implements WhatsAppGatewayInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function assertReadyToSend(): void
    {
        throw WhatsAppDeliveryException::disabled();
    }

    public function sendTemplate(
        string $recipientMsisdn,
        string $templateName,
        string $languageCode,
        array $bodyParameters = [],
    ): WhatsAppApiResponse {
        throw WhatsAppDeliveryException::disabled();
    }
}
