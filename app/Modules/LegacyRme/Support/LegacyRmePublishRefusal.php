<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * LEGACY-RME-PDF-1C — a refusal raised from inside the publish transaction.
 *
 * WHY THIS EXISTS. Every publish check runs under the staging row's lock, so a
 * refusal necessarily happens inside an open transaction — and anything written
 * there (including an audit row) is rolled back with it. Throwing this instead
 * lets the service unwind the transaction first and record WHY the publish was
 * rejected afterwards, so the refusal trail actually survives.
 *
 * It carries the input field to attach the message to plus the PII-free audit
 * metadata for the rejection. The message is always safe to render: never a
 * filesystem path, never patient content.
 */
final class LegacyRmePublishRefusal extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $auditMetadata
     */
    private function __construct(
        public readonly string $field,
        string $message,
        public readonly array $auditMetadata,
    ) {
        parent::__construct($message);
    }

    /**
     * A structural refusal: wrong status, missing pages, missing source file.
     */
    public static function failure(string $failureCode, string $field = 'status'): self
    {
        return new self(
            $field,
            LegacyRmePdfFailure::message($failureCode),
            ['failure_code' => $failureCode],
        );
    }

    /**
     * A 1A date-rule refusal re-detected at publish time.
     */
    public static function dateRule(string $ruleCode, string $message, string $field): self
    {
        return new self($field, $message, ['rule_code' => $ruleCode]);
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            $this->field => $this->getMessage(),
        ]);
    }
}
