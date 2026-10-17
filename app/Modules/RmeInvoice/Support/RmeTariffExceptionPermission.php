<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the two permissions that may authorize
 * a STRICT-mode price exception (D21 "specific authorization").
 *
 * Seeded and granted to NO role: the exception approver (D21b) is undecided.
 * Holding one is necessary but not sufficient — exceptions are also disabled by
 * `rme_tariff_authority.price_exceptions_enabled` until D21b–D21f are decided,
 * which keeps them closed for Super Admin too (its wildcard grants the
 * permission, never the switch).
 */
final class RmeTariffExceptionPermission
{
    /** Bill a strict-mode line at a price other than the tariff in force. */
    public const OVERRIDE_PRICE = 'override_rme_tariff_price';

    /** Give a discount on a strict-mode line. */
    public const AUTHORIZE_DISCOUNT = 'authorize_rme_tariff_discount';

    public const ALL = [self::OVERRIDE_PRICE, self::AUTHORIZE_DISCOUNT];
}
