<?php

namespace App\Modules\Prescription\Gateways;

use App\Modules\Prescription\Exceptions\WhatsAppDeliveryException;

/**
 * FIX-02 — deterministic in-memory gateway for automated tests. Records what
 * it was asked to send so a test can assert the recipient, template and
 * parameters without any network access.
 */
final class FakeWhatsAppGateway implements WhatsAppGatewayInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    private ?WhatsAppApiResponse $nextResponse = null;

    private bool $enabled = true;

    public function enable(bool $enabled = true): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function willReturn(WhatsAppApiResponse $response): self
    {
        $this->nextResponse = $response;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function assertReadyToSend(): void
    {
        if (! $this->enabled) {
            throw WhatsAppDeliveryException::disabled();
        }
    }

    public function sendTemplate(
        string $recipientMsisdn,
        string $templateName,
        string $languageCode,
        array $bodyParameters = [],
    ): WhatsAppApiResponse {
        $this->assertReadyToSend();

        $this->sent[] = [
            'to' => $recipientMsisdn,
            'template' => $templateName,
            'language' => $languageCode,
            'parameters' => $bodyParameters,
        ];

        return $this->nextResponse ?? WhatsAppApiResponse::success('wamid.FAKE'.count($this->sent));
    }
}
