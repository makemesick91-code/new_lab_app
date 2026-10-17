<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Support;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the pricing mode of a branch (D21).
 *
 *   TRANSITIONAL     default. Legacy cashier pricing is accepted; every line
 *                    records its provenance. Never reported as tariff-verified.
 *   STRICT_ENFORCED  explicit opt-in per branch (config). The tariff is the
 *                    price; deviations, discounts, free-text lines, missing or
 *                    zero tariffs and Rp0 are refused.
 *
 * Readiness is a SEPARATE axis, measured from the tariff master:
 *
 *   TARIFF_READY       every active treatment has a positive tariff in force
 *   TARIFF_INCOMPLETE  some do, some do not
 *   NO_TARIFFS         none do
 *
 * A mode is never derived from readiness. A strict branch that is not ready is
 * reported (STRICT_NOT_READY) so the operator sees billing refusals coming.
 */
final class TariffPricingMode
{
    public const TRANSITIONAL = 'TRANSITIONAL';

    public const STRICT_ENFORCED = 'STRICT_ENFORCED';

    public const READY = 'TARIFF_READY';

    public const INCOMPLETE = 'TARIFF_INCOMPLETE';

    public const NO_TARIFFS = 'NO_TARIFFS';

    public const MODES = [self::TRANSITIONAL, self::STRICT_ENFORCED];
}
