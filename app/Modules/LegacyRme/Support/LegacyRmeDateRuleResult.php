<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1A — immutable outcome of the legacy RME date rules.
 *
 * Carries a STABLE machine code so callers (form requests, services, commands,
 * future UI) branch on the code, never on the human message. The context is
 * PII-free by construction: only dates and the patient id, never a name, KTP,
 * NIK or clinical content.
 */
final class LegacyRmeDateRuleResult
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly bool $passed,
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function pass(array $context = []): self
    {
        return new self(true, null, null, $context);
    }

    /**
     * @param  array<string, mixed>  $context
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
     * LEGACY-RME-PDF-FIX-ROLL2-1 — which reference the decision was made
     * against: `BEFORE_NATIVE_RME` when the patient already has a native RME
     * that bounds the archive, `NO_NATIVE_REFERENCE` when they have none.
     *
     * The second is a normal, expected migration case — NOT an error. It is
     * surfaced so the evidence trail can distinguish the two, which is exactly
     * the distinction the old (removed) refusal used to destroy by refusing.
     */
    public function referenceMode(): ?string
    {
        $mode = $this->context['reference_mode'] ?? null;

        return is_string($mode) ? $mode : null;
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
