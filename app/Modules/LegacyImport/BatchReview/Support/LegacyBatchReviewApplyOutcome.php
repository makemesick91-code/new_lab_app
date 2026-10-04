<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

/**
 * What happened when ONE attested decision was carried to the canonical path.
 *
 * An outcome, never a decision. The adapter calls the canonical review service
 * and reports what that service did; this object re-decides nothing. `applied`
 * is true only when the canonical layer actually transitioned (or had already
 * transitioned — the canonical review is idempotent, and an idempotent no-op is
 * a success, not a refusal).
 */
final class LegacyBatchReviewApplyOutcome
{
    private function __construct(
        public readonly bool $applied,
        public readonly ?string $refusalCode,
        public readonly ?string $refusalMessage,
        public readonly bool $changed,
    ) {}

    public static function applied(bool $changed = true): self
    {
        return new self(true, null, null, $changed);
    }

    public static function refused(string $code, ?string $message): self
    {
        return new self(false, $code, $message, false);
    }

    public function isRefused(): bool
    {
        return ! $this->applied;
    }
}
