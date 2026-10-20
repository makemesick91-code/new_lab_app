<?php

namespace App\Modules\ClinicOversight\Support;

use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — presentation helpers shared by
 * the oversight views, exports and prints, so a figure renders the same way
 * on screen, on paper and in a spreadsheet.
 */
final class OversightFormat
{
    /**
     * Integer cents as Rupiah for display: "Rp 1.250.000", or "Rp 175.000,50"
     * when the amount is not a whole rupiah.
     *
     * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195 §25): this used to
     * TRUNCATE the cents (intdiv), so a stored 175000.50 printed as
     * "Rp 175.000" on the frozen correction document and in oversight. Nothing
     * is rounded or dropped now: what is printed is what is stored.
     */
    public static function rupiahCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $text = number_format(intdiv($absolute, 100), 0, ',', '.');

        if ($absolute % 100 !== 0) {
            $text .= ','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        }

        return 'Rp '.($negative ? '-' : '').$text;
    }

    /**
     * A decimal money value (string|float|int|null) as Rupiah for display —
     * parsed exactly (decimal strings digit by digit), never via a float.
     */
    public static function rupiah(mixed $value): string
    {
        return self::rupiahCents(RmeInvoiceDocument::toCents($value ?? 0));
    }

    /**
     * A clinic calendar date (strict `Y-m-d`) as Indonesian `dd/mm/yyyy`.
     * Pure string reshaping: a calendar date is never timezone-converted.
     */
    public static function clinicalDate(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $ymd, $m) === 1
            ? $m[3].'/'.$m[2].'/'.$m[1]
            : $ymd;
    }

    /**
     * A stored UTC instant on the CLINIC wall clock (WITA). Null-safe.
     */
    public static function clinicalTime(DateTimeInterface|string|null $instant, string $format = 'H:i'): ?string
    {
        if ($instant === null || $instant === '') {
            return null;
        }

        $clock = app(ClinicalClock::class);

        $parsed = $instant instanceof DateTimeInterface
            ? CarbonImmutable::instance($instant)
            : CarbonImmutable::parse($instant, 'UTC');

        return $parsed->setTimezone($clock->timezone())->format($format);
    }
}
