<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Support;

/**
 * Preflight verdict for one manifest row.
 *
 * A FORECAST, NOT A PROMISE. "eligible" means the server would accept this row
 * at the moment preflight ran. Between then and creation, a single upload may
 * publish for the same patient, a branch may leave its wave, or a quota may be
 * declared — so the canonical single-item service re-decides under a lock at
 * creation time and an eligible row can still end up blocked (§15).
 *
 * That is the design working. Preflight exists so an operator is not surprised
 * by 40 refusals after confirming, not to bypass the domain's own decision.
 */
final class LegacyMassUploadItemVerdict
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $reasonCode,
        public readonly ?string $reasonMessage,
        public readonly ?int $branchId = null,
        public readonly ?string $normalizedSourceRm = null,
    ) {}

    public static function eligible(?int $branchId = null, ?string $normalizedSourceRm = null): self
    {
        return new self(
            LegacyMassUploadItemStatus::ELIGIBLE,
            null,
            null,
            $branchId,
            $normalizedSourceRm,
        );
    }

    /**
     * Dispatchable, but the operator should look first.
     *
     * A warning must never be used for something the domain would refuse —
     * that would promise an operator a row will import when it will not. It is
     * for things that are genuinely fine but worth seeing, such as an RM that
     * only matched after normalisation.
     */
    public static function warning(string $reasonCode, ?string $message = null, ?int $branchId = null, ?string $normalizedSourceRm = null): self
    {
        return new self(
            LegacyMassUploadItemStatus::WARNING,
            $reasonCode,
            $message ?? LegacyMassUploadReason::message($reasonCode),
            $branchId,
            $normalizedSourceRm,
        );
    }

    public static function blocked(string $reasonCode, ?string $message = null, ?int $branchId = null, ?string $normalizedSourceRm = null): self
    {
        return new self(
            LegacyMassUploadItemStatus::BLOCKED,
            $reasonCode,
            $message ?? LegacyMassUploadReason::message($reasonCode),
            $branchId,
            $normalizedSourceRm,
        );
    }

    public function isBlocked(): bool
    {
        return $this->status === LegacyMassUploadItemStatus::BLOCKED;
    }

    public function isDispatchable(): bool
    {
        return LegacyMassUploadItemStatus::isDispatchable($this->status);
    }
}
