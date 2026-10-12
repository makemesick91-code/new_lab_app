<?php

namespace App\Modules\ClinicOversight\Support;

use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;

/**
 * REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 (rule 187) — an INCLUSIVE range of
 * clinic (Asia/Makassar, WITA) calendar days and the UTC instants that bound it.
 *
 * Built from two strict ClinicalDayWindow values, so the WITA→UTC conversion
 * and the strict `Y-m-d` round-trip parse live in exactly one place:
 *
 *   startUtc        = start_date 00:00:00 WITA, in UTC   (inclusive)
 *   endExclusiveUtc = the day AFTER end_date 00:00:00 WITA, in UTC (exclusive)
 *
 * so a TIMESTAMP column is compared as `col >= startUtc AND col < endExclusiveUtc`
 * — never `23:59:59`, never a function on the column. A DATE column
 * (`visit_date`) is compared as `[start_date, endExclusiveDate)`.
 *
 * The range is refused (tryFrom() returns null) — never repaired — when:
 *   - either date is missing or not a real calendar date;
 *   - start_date is after end_date (the dates are NEVER exchanged);
 *   - a date falls outside [MIN_DATE, MAX_DATE] (keeps every bound literal a
 *     valid four-digit-year PostgreSQL timestamp);
 *   - the span exceeds MAX_DAYS inclusive days (resource bound).
 */
final class ClinicalDateRange
{
    /** A full calendar year including a leap day. */
    public const MAX_DAYS = 366;

    public const MIN_DATE = '2000-01-01';

    public const MAX_DATE = '2099-12-31';

    private function __construct(
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly CarbonImmutable $startUtc,
        public readonly CarbonImmutable $endExclusiveUtc,
        public readonly int $dayCount,
        private readonly string $endExclusiveDate,
    ) {}

    /** The clinical today as a one-day range. */
    public static function today(ClinicalClock $clock): self
    {
        $day = ClinicalDayWindow::today($clock);

        return self::fromWindows($day, $day);
    }

    /**
     * The range for two explicit dates, or null when the pair is not a valid,
     * ordered, bounded range (see the class docblock).
     */
    public static function tryFrom(?string $start, ?string $end, ClinicalClock $clock): ?self
    {
        $startDay = ClinicalDayWindow::tryFromDate($start, $clock);
        $endDay = ClinicalDayWindow::tryFromDate($end, $clock);

        if ($startDay === null || $endDay === null) {
            return null;
        }

        if ($startDay->date < self::MIN_DATE || $endDay->date > self::MAX_DATE) {
            return null;
        }

        if ($startDay->date > $endDay->date) {
            return null;
        }

        if (self::inclusiveDayCount($startDay->date, $endDay->date) > self::MAX_DAYS) {
            return null;
        }

        return self::fromWindows($startDay, $endDay);
    }

    /** A single clinic day as a range (the legacy `date` parameter), or null. */
    public static function singleDay(?string $date, ClinicalClock $clock): ?self
    {
        return self::tryFrom($date, $date, $clock);
    }

    /**
     * Inclusive number of calendar days between two strict `Y-m-d` dates
     * (start == end is 1). Counted on calendar dates, not instants, so it is
     * independent of any timezone offset.
     */
    public static function inclusiveDayCount(string $start, string $end): int
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $end, 'UTC');

        return (int) round(abs($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1;
    }

    /**
     * Quick-pick ranges, all ending on the clinical today.
     *
     * @return list<array{key:string, label:string, start_date:string, end_date:string}>
     */
    public static function presets(ClinicalClock $clock): array
    {
        $today = $clock->today();
        $end = $today->format('Y-m-d');

        return [
            ['key' => 'today', 'label' => 'Hari ini', 'start_date' => $end, 'end_date' => $end],
            ['key' => 'last_7_days', 'label' => '7 hari terakhir', 'start_date' => $today->subDays(6)->format('Y-m-d'), 'end_date' => $end],
            ['key' => 'last_30_days', 'label' => '30 hari terakhir', 'start_date' => $today->subDays(29)->format('Y-m-d'), 'end_date' => $end],
            ['key' => 'this_month', 'label' => 'Bulan ini', 'start_date' => $today->startOfMonth()->format('Y-m-d'), 'end_date' => $end],
        ];
    }

    private static function fromWindows(ClinicalDayWindow $start, ClinicalDayWindow $end): self
    {
        return new self(
            $start->date,
            $end->date,
            $start->startUtc,
            $end->endUtc,
            self::inclusiveDayCount($start->date, $end->date),
            $end->nextDate(),
        );
    }

    public function isSingleDay(): bool
    {
        return $this->startDate === $this->endDate;
    }

    public function contains(string $date): bool
    {
        return $date >= $this->startDate && $date <= $this->endDate;
    }

    public function startUtcString(): string
    {
        return $this->startUtc->format('Y-m-d H:i:s');
    }

    public function endExclusiveUtcString(): string
    {
        return $this->endExclusiveUtc->format('Y-m-d H:i:s');
    }

    /** The calendar date after end_date: the exclusive bound for DATE columns. */
    public function endExclusiveDate(): string
    {
        return $this->endExclusiveDate;
    }
}
