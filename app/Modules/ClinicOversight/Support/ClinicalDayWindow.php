<?php

namespace App\Modules\ClinicOversight\Support;

use App\Support\Clinical\ClinicalClock;
use Carbon\CarbonImmutable;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — one CLINIC calendar day and the
 * UTC instants that bound it.
 *
 * The clinic day is the Asia/Makassar (WITA) calendar day read through
 * ClinicalClock — never `now()`/`today()`, which are UTC on this deployment and
 * name a different day for eight hours of every clinic day.
 *
 * Two shapes, used for two kinds of column:
 *
 *   - DATE columns (`trx_clinic_visits.visit_date`) are compared to the date
 *     string itself. visit_date is already stamped from the clinical clock.
 *   - TIMESTAMP columns (`paid_at`, `cancelled_at`) hold UTC instants, so the
 *     day is the HALF-OPEN UTC range [startUtc, endUtc). Comparing the raw
 *     column against two bound literals keeps the indexes usable — no
 *     timezone conversion is ever applied to the column.
 *
 * A malformed date never reaches SQL: parse() is strict (`Y-m-d` round trip),
 * so `2026-02-30` is refused instead of silently rolling into March.
 */
final class ClinicalDayWindow
{
    private function __construct(
        public readonly string $date,
        public readonly CarbonImmutable $startUtc,
        public readonly CarbonImmutable $endUtc,
    ) {}

    public static function today(ClinicalClock $clock): self
    {
        return self::fromDate($clock->todayString(), $clock);
    }

    /**
     * The window for an explicit date, or null when the string is not a real
     * calendar date in strict `Y-m-d` form.
     */
    public static function tryFromDate(?string $date, ClinicalClock $clock): ?self
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $clock->timezoneObject());

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return self::fromDate($date, $clock);
    }

    /** Requested date when valid, otherwise the clinical today. */
    public static function resolve(?string $requested, ClinicalClock $clock): self
    {
        return self::tryFromDate($requested, $clock) ?? self::today($clock);
    }

    private static function fromDate(string $date, ClinicalClock $clock): self
    {
        $startLocal = CarbonImmutable::createFromFormat('!Y-m-d', $date, $clock->timezoneObject());

        return new self(
            $date,
            $startLocal->utc(),
            $startLocal->addDay()->utc(),
        );
    }

    public function startUtcString(): string
    {
        return $this->startUtc->format('Y-m-d H:i:s');
    }

    public function endUtcString(): string
    {
        return $this->endUtc->format('Y-m-d H:i:s');
    }

    /**
     * The calendar date after this one. DATE columns are compared as the
     * half-open range [date, nextDate): portable to SQLite (where a `date`
     * cast stores `Y-m-d 00:00:00`) and still an index range on PostgreSQL,
     * where the literal is coerced to the column's DATE type.
     */
    public function nextDate(): string
    {
        return self::nextDateOf($this->date);
    }

    public static function nextDateOf(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->addDay()->format('Y-m-d');
    }
}
