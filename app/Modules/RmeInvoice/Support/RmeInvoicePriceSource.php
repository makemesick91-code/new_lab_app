<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

/**
 * How an invoice line's price was decided. Stored on
 * trx_rme_invoice_items.price_source.
 *
 * CURRENT (REVISION-RME-MANUAL-PRICING-AUTHORITY-1, owner D21 revised
 * 2026-10-20 — manual cashier pricing):
 *
 *   MANUAL_CASHIER_ENTRY     the authorized cashier typed the price of a line
 *                            naming a treatment on the ORIGINAL invoice.
 *   MANUAL_FREE_TEXT         the same, for a free-text line naming no treatment.
 *   CORRECTION_MANUAL_ENTRY  the price was set by an APPLIED, maker-checker
 *                            approved invoice correction.
 *
 * Who typed it and when is already recorded elsewhere (the invoice's cashier
 * and creation time; the correction's maker, checker and applier) — it is not
 * duplicated here.
 *
 * HISTORICAL (written only before the revision; kept so old rows read honestly):
 *   TARIFF_MATCHED, TARIFF_EXCEPTION_AUTHORIZED   strict / tariff-era lines;
 *   TRANSITIONAL_*                                tariff-era transitional lines;
 *   NULL                                          before any provenance existed.
 *
 * A manual price is NEVER labelled as a tariff, even when it happens to equal
 * one: coincidence is not tariff authority.
 */
final class RmeInvoicePriceSource
{
    public const MANUAL_CASHIER_ENTRY = 'MANUAL_CASHIER_ENTRY';

    public const MANUAL_FREE_TEXT = 'MANUAL_FREE_TEXT';

    public const CORRECTION_MANUAL_ENTRY = 'CORRECTION_MANUAL_ENTRY';

    /** HISTORICAL — the tariff in force was the price and there was no discount. */
    public const TARIFF_MATCHED = 'TARIFF_MATCHED';

    /** HISTORICAL / LEGACY strict mode: a deviation or discount authorized by permission + reason. */
    public const TARIFF_EXCEPTION_AUTHORIZED = 'TARIFF_EXCEPTION_AUTHORIZED';

    /** HISTORICAL transitional: a tariff existed, but the cashier price or discount differed. */
    public const TRANSITIONAL_TARIFF_DEVIATION = 'TRANSITIONAL_TARIFF_DEVIATION';

    /** HISTORICAL transitional: the treatment had no usable tariff in the branch. */
    public const TRANSITIONAL_NO_TARIFF = 'TRANSITIONAL_NO_TARIFF';

    /** HISTORICAL transitional: a free-text line naming no treatment. */
    public const TRANSITIONAL_FREE_TEXT = 'TRANSITIONAL_FREE_TEXT';

    /** Sources a line can be written with today. */
    public const MANUAL = [
        self::MANUAL_CASHIER_ENTRY,
        self::MANUAL_FREE_TEXT,
        self::CORRECTION_MANUAL_ENTRY,
    ];

    public const ALL = [
        self::MANUAL_CASHIER_ENTRY,
        self::MANUAL_FREE_TEXT,
        self::CORRECTION_MANUAL_ENTRY,
        self::TARIFF_MATCHED,
        self::TARIFF_EXCEPTION_AUTHORIZED,
        self::TRANSITIONAL_TARIFF_DEVIATION,
        self::TRANSITIONAL_NO_TARIFF,
        self::TRANSITIONAL_FREE_TEXT,
    ];

    /** The only values that ever claimed tariff authority (historical / legacy strict). */
    public const TARIFF_VERIFIED = [self::TARIFF_MATCHED, self::TARIFF_EXCEPTION_AUTHORIZED];

    /** Short Indonesian label for the cashier UI. NULL = recorded before price provenance existed. */
    public static function label(?string $source): string
    {
        return match ($source) {
            self::MANUAL_CASHIER_ENTRY => 'Harga manual kasir',
            self::MANUAL_FREE_TEXT => 'Harga manual kasir — tindakan manual',
            self::CORRECTION_MANUAL_ENTRY => 'Harga hasil koreksi (disetujui)',
            self::TARIFF_MATCHED => 'Tarif master (periode otoritas tarif)',
            self::TARIFF_EXCEPTION_AUTHORIZED => 'Pengecualian tarif (periode otoritas tarif)',
            self::TRANSITIONAL_TARIFF_DEVIATION => 'Transisi tarif — berbeda dari tarif (historis)',
            self::TRANSITIONAL_NO_TARIFF => 'Transisi tarif — tanpa tarif (historis)',
            self::TRANSITIONAL_FREE_TEXT => 'Transisi tarif — tindakan manual (historis)',
            default => 'Belum tercatat (sebelum pencatatan asal harga)',
        };
    }

    public static function tone(?string $source): string
    {
        return match ($source) {
            self::MANUAL_CASHIER_ENTRY, self::MANUAL_FREE_TEXT => 'info',
            self::CORRECTION_MANUAL_ENTRY => 'warning',
            self::TARIFF_MATCHED, self::TARIFF_EXCEPTION_AUTHORIZED,
            self::TRANSITIONAL_TARIFF_DEVIATION, self::TRANSITIONAL_NO_TARIFF, self::TRANSITIONAL_FREE_TEXT => 'neutral',
            default => 'neutral',
        };
    }
}
