<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Support;

/**
 * The pricing mode of a branch, recorded on every new invoice line as
 * `trx_rme_invoice_items.pricing_mode`.
 *
 *   MANUAL           the mode of EVERY branch since REVISION-RME-MANUAL-PRICING-
 *                    AUTHORITY-1 (owner D21, revised 2026-10-20). The cashier
 *                    types the price; there is no mandatory tariff. The server
 *                    still owns the arithmetic (integer cents, discount within
 *                    its line, overflow, totals) and treatment validity.
 *   STRICT_ENFORCED  LEGACY_SUPERSEDED. The tariff was the price. Retained as
 *                    code only; unreachable without a reviewed source change of
 *                    `config/rme_tariff_authority.php` AND a new owner decision
 *                    superseding the revised D21.
 *   TRANSITIONAL     HISTORICAL. The mode lines carried between
 *                    FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 and the D21 revision.
 *                    Never written again; kept so historical rows read honestly.
 *
 * Readiness is a SEPARATE, informational axis measured from the optional tariff
 * master (TARIFF_READY / TARIFF_INCOMPLETE / NO_TARIFFS). It never changes a mode
 * and never blocks billing.
 */
final class TariffPricingMode
{
    public const MANUAL = 'MANUAL';

    public const STRICT_ENFORCED = 'STRICT_ENFORCED';

    /** HISTORICAL — written only before the D21 revision. */
    public const TRANSITIONAL = 'TRANSITIONAL';

    public const READY = 'TARIFF_READY';

    public const INCOMPLETE = 'TARIFF_INCOMPLETE';

    public const NO_TARIFFS = 'NO_TARIFFS';

    /** Modes a line can be written in today. */
    public const MODES = [self::MANUAL, self::STRICT_ENFORCED];

    /** Modes that only historical rows may carry. */
    public const HISTORICAL = [self::TRANSITIONAL];
}
