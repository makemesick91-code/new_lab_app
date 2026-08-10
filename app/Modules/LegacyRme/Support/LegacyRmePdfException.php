<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * LEGACY-RME-PDF-1B — a PDF pipeline failure carrying a stable machine code.
 *
 * The message is always the safe, operator-facing text for the code. Anything
 * diagnostic (process output, filesystem path, driver exception) stays in the
 * application log and is NEVER put on this exception, because its message is
 * both rendered in the UI and persisted to `failure_message`.
 */
class LegacyRmePdfException extends RuntimeException
{
    private function __construct(
        public readonly string $failureCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function make(string $failureCode, ?string $message = null): self
    {
        return new self(
            LegacyRmePdfFailure::isValid($failureCode) ? $failureCode : LegacyRmePdfFailure::INVALID_PDF,
            $message ?? LegacyRmePdfFailure::message($failureCode),
        );
    }

    /**
     * Surface the failure at an input boundary the way the rest of the
     * repository does.
     */
    public function toValidationException(string $field = 'document'): ValidationException
    {
        return ValidationException::withMessages([
            $field => $this->getMessage(),
        ]);
    }
}
