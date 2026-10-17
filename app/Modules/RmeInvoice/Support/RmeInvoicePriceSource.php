<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — how an invoice line's price was decided
 * (owner D21). Stored on trx_rme_invoice_items.price_source.
 *
 * Only TARIFF_MATCHED and TARIFF_EXCEPTION_AUTHORIZED are "tariff-verified".
 * Every TRANSITIONAL_* value is the legacy cashier price, recorded honestly so it
 * is never presented as master tariff.
 */
final class RmeInvoicePriceSource
{
    /** The tariff in force was the price and there is no discount. */
    public const TARIFF_MATCHED = 'TARIFF_MATCHED';

    /** Strict mode: a deviation or discount authorized by permission + reason. */
    public const TARIFF_EXCEPTION_AUTHORIZED = 'TARIFF_EXCEPTION_AUTHORIZED';

    /** Transitional: a tariff exists, but the cashier price or discount differs. */
    public const TRANSITIONAL_TARIFF_DEVIATION = 'TRANSITIONAL_TARIFF_DEVIATION';

    /** Transitional: the treatment has no usable tariff in this branch. */
    public const TRANSITIONAL_NO_TARIFF = 'TRANSITIONAL_NO_TARIFF';

    /** Transitional: a free-text line naming no treatment. */
    public const TRANSITIONAL_FREE_TEXT = 'TRANSITIONAL_FREE_TEXT';

    public const ALL = [
        self::TARIFF_MATCHED,
        self::TARIFF_EXCEPTION_AUTHORIZED,
        self::TRANSITIONAL_TARIFF_DEVIATION,
        self::TRANSITIONAL_NO_TARIFF,
        self::TRANSITIONAL_FREE_TEXT,
    ];

    public const TARIFF_VERIFIED = [self::TARIFF_MATCHED, self::TARIFF_EXCEPTION_AUTHORIZED];

    /** Short Indonesian label for the cashier UI. NULL = recorded before tariff authority. */
    public static function label(?string $source): string
    {
        return match ($source) {
            self::TARIFF_MATCHED => 'Tarif master',
            self::TARIFF_EXCEPTION_AUTHORIZED => 'Pengecualian tarif (diotorisasi)',
            self::TRANSITIONAL_TARIFF_DEVIATION => 'Transisi — berbeda dari tarif master',
            self::TRANSITIONAL_NO_TARIFF => 'Transisi — belum ada tarif master',
            self::TRANSITIONAL_FREE_TEXT => 'Transisi — tindakan manual',
            default => 'Belum tercatat (sebelum otoritas tarif)',
        };
    }

    public static function tone(?string $source): string
    {
        return match ($source) {
            self::TARIFF_MATCHED => 'success',
            self::TARIFF_EXCEPTION_AUTHORIZED => 'info',
            self::TRANSITIONAL_TARIFF_DEVIATION, self::TRANSITIONAL_NO_TARIFF, self::TRANSITIONAL_FREE_TEXT => 'warning',
            default => 'neutral',
        };
    }
}
