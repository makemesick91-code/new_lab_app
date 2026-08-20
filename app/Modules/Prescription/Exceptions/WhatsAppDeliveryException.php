<?php

namespace App\Modules\Prescription\Exceptions;

use RuntimeException;

/**
 * FIX-02 — a WhatsApp delivery could not be completed. The message is written
 * for a clinic operator and never carries a token, a raw provider payload or
 * any patient identity beyond what the operator already sees on screen.
 */
class WhatsAppDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $providerErrorCode = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self('Pengiriman WhatsApp belum aktif. Hubungi administrator untuk mengatur kredensial WhatsApp Business.');
    }

    public static function misconfigured(string $what): self
    {
        return new self("Konfigurasi WhatsApp Business belum lengkap ({$what}). Hubungi administrator.");
    }
}
