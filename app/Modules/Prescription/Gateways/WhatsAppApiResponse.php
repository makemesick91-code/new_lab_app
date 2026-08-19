<?php

namespace App\Modules\Prescription\Gateways;

/**
 * FIX-02 — a sanitized value object. Callers never see the raw provider
 * response, so a token echoed back by the provider can never reach a log,
 * a database column or the UI.
 */
final class WhatsAppApiResponse
{
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $messageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
    ) {}

    public static function success(?string $messageId): self
    {
        return new self(true, $messageId);
    }

    public static function failure(?string $errorCode, string $errorMessage, bool $retryable = false): self
    {
        return new self(false, null, $errorCode, $errorMessage, $retryable);
    }
}
