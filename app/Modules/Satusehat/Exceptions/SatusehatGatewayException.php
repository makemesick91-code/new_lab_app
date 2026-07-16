<?php

namespace App\Modules\Satusehat\Exceptions;

use App\Modules\Satusehat\Support\SatusehatOutcome;
use RuntimeException;

/**
 * Thrown for a failed SATUSEHAT gateway operation, carrying the classified
 * {@see SatusehatOutcome} so the job layer can decide retry vs. reconcile vs.
 * permanent fail — without re-inspecting the raw response. The message is always
 * sanitized (no token/secret/NIK/raw payload).
 */
class SatusehatGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $outcome = SatusehatOutcome::UNKNOWN,
        public readonly ?int $httpStatus = null,
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message);
    }

    public function requiresReconciliation(): bool
    {
        return $this->outcome === SatusehatOutcome::UNKNOWN;
    }

    public function isRetryable(): bool
    {
        return $this->outcome === SatusehatOutcome::RETRYABLE;
    }

    public function isPermanent(): bool
    {
        return $this->outcome === SatusehatOutcome::PERMANENT;
    }
}
