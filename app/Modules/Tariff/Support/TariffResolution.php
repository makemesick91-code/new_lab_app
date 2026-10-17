<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Support;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the answer to "what is the authoritative
 * price of this treatment in this branch on this clinical date?".
 *
 * Only RESOLVED carries a price. Every other outcome is a reason there is no
 * authority — never a zero price and never another branch's tariff.
 */
final class TariffResolution
{
    public const RESOLVED = 'RESOLVED';

    /** The line names no treatment (free-text). */
    public const NO_TREATMENT = 'NO_TREATMENT';

    /** The treatment does not exist, is soft-deleted or inactive. */
    public const TREATMENT_INACTIVE = 'TREATMENT_INACTIVE';

    /** No active tariff of THIS branch is in force on the date. */
    public const NO_TARIFF = 'NO_TARIFF';

    /** The tariff in force is Rp0 — never treated as price authority (D21g). */
    public const ZERO_PRICE = 'TARIFF_ZERO_PRICE';

    private function __construct(
        public readonly string $outcome,
        public readonly ?int $treatmentId,
        public readonly ?int $tariffId = null,
        public readonly ?int $priceCents = null,
        public readonly ?string $effectiveDate = null,
    ) {}

    public static function resolved(int $treatmentId, int $tariffId, int $priceCents, string $effectiveDate): self
    {
        return new self(self::RESOLVED, $treatmentId, $tariffId, $priceCents, $effectiveDate);
    }

    public static function unresolved(string $outcome, ?int $treatmentId, ?int $tariffId = null): self
    {
        return new self($outcome, $treatmentId, $tariffId);
    }

    public function isResolved(): bool
    {
        return $this->outcome === self::RESOLVED;
    }
}
