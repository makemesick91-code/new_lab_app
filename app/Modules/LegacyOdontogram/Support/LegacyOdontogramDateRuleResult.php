<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Support;

/**
 * FIX-04b — the outcome of evaluating the legacy odontogram date rules.
 *
 * Immutable and always explicit: either every rule passed, or it carries the
 * STABLE CODE of the first one that did not. Callers branch on `code`, never on
 * `message` — the wording is for the operator, the code is the contract.
 *
 * The context is PII-free by construction: dates, a patient surrogate id and
 * the clinical timezone. No name, no Nomor RM, no KTP/NIK, no clinical detail.
 */
final class LegacyOdontogramDateRuleResult
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    private function __construct(
        public readonly bool $passed,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly array $context = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function pass(array $context = []): self
    {
        return new self(true, null, null, $context);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function fail(string $code, string $message, array $context = []): self
    {
        return new self(false, $code, $message, $context);
    }

    public function failed(): bool
    {
        return ! $this->passed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
