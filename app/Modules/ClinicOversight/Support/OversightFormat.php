<?php

namespace App\Modules\ClinicOversight\Support;

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
    /** Integer cents as Rupiah for display: "Rp 1.250.000". */
    public static function rupiahCents(int $cents): string
    {
        return 'Rp '.number_format(intdiv($cents, 100), 0, ',', '.');
    }

    /** A decimal money value (string|float|int|null) as Rupiah for display. */
    public static function rupiah(mixed $value): string
    {
        return 'Rp '.number_format((float) ($value ?? 0), 0, ',', '.');
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
