<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) — the immutable answer of
 * {@see RmeZeroValueEligibility}: either a category (eligible) or a stable
 * refusal code with an operator message. Callers branch on the CODE.
 */
final class RmeZeroValueEligibilityResult
{
    private function __construct(
        private readonly ?string $category,
        private readonly ?string $reason,
        private readonly ?string $message,
    ) {}

    public static function forCategory(string $category): self
    {
        return new self($category, null, null);
    }

    public static function refuse(string $reason, string $message): self
    {
        return new self(null, $reason, $message);
    }

    public function eligible(): bool
    {
        return $this->category !== null;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /** The operator message, carrying the code so a screenshot names the rule. */
    public function message(): ?string
    {
        return $this->message === null ? null : $this->message.' ['.$this->reason.']';
    }
}
