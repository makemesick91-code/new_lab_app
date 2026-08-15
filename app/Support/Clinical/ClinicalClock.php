<?php

declare(strict_types=1);

namespace App\Support\Clinical;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * LEGACY-RME-DATE-TZ-1 — the SINGLE source of truth for "what is today's
 * clinical date?".
 *
 * ── The defect this closes ────────────────────────────────────────────────
 *
 * `config/app.php` hard-codes `'timezone' => 'UTC'` and never reads
 * `APP_TIMEZONE`. The legacy archive's clinical timezone was declared as
 *
 *     env('LEGACY_RME_CLINICAL_TIMEZONE', env('APP_TIMEZONE', 'UTC'))
 *
 * and neither variable is set in production, so the clinical calendar silently
 * resolved to UTC. Two services then repeated the same fallback inline. The
 * consequence is a real clinical off-by-one: between 16:00 UTC and 24:00 UTC
 * it is already tomorrow in the clinic, so a document dated "today in the
 * clinic" was still "in the future" to a UTC-anchored rule — and the identical
 * document produced a different eligibility answer depending only on the hour
 * it was submitted.
 *
 * ── The two semantic domains ──────────────────────────────────────────────
 *
 *   INSTANT / TIMESTAMP     an absolute moment, e.g. 2026-08-13T16:00:00Z.
 *                           Stored and compared in the application's existing
 *                           architecture (normally UTC): created_at,
 *                           updated_at, queue timestamps, audit event times,
 *                           deployment logs. THIS CLASS DOES NOT CHANGE THEM.
 *
 *   CLINICAL CALENDAR DATE  the day the clinic was living through at that
 *                           instant, e.g. 2026-08-14. Every clinical
 *                           eligibility decision uses this.
 *
 * ── What this class does NOT do ───────────────────────────────────────────
 *
 * It never shifts a stored DATE. `selected_rme_date`, `latest_rme_date`,
 * `earliest_native_rme_date` and `trx_clinic_visits.visit_date` are calendar
 * dates a human read off a document or the workflow stamped. Pushing them
 * through a timezone conversion would corrupt history to fix a clock. The
 * timezone is used ONLY to derive a clinical date from a current instant.
 *
 * ── Fail closed ───────────────────────────────────────────────────────────
 *
 * An unusable configured timezone throws. It never degrades to UTC — that
 * degradation is precisely the bug. `inspect()` exists for readiness surfaces
 * that must REPORT the posture without blowing up.
 *
 * ── Test clock ────────────────────────────────────────────────────────────
 *
 * Every reading goes through Carbon, so `Carbon::setTestNow()` controls this
 * class in tests without any bespoke freezing mechanism. Nothing here consults
 * `date_default_timezone_get()`, `php.ini`, the OS zone or a request header, so
 * the clinical answer is identical on a UTC CI runner and a WITA workstation.
 */
class ClinicalClock
{
    /**
     * The configured clinical calendar timezone.
     *
     * @throws InvalidClinicalTimezoneException when it is missing or not a
     *                                          resolvable IANA identifier.
     */
    public function timezone(): string
    {
        $configured = config(ClinicalTimezone::CONFIG_KEY);

        if (! ClinicalTimezone::isValid($configured)) {
            throw InvalidClinicalTimezoneException::forValue($configured);
        }

        /** @var string $configured */
        return trim($configured);
    }

    public function timezoneObject(): DateTimeZone
    {
        return new DateTimeZone($this->timezone());
    }

    /**
     * The current instant, expressed on the clinical wall clock.
     *
     * Still an instant — use `today()` when you need the calendar date.
     */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /**
     * TODAY'S CLINICAL CALENDAR DATE, at 00:00:00 in the clinical timezone.
     *
     * This is the only value a clinical "is it historical yet?" rule may
     * compare against.
     */
    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    public function todayString(): string
    {
        return $this->today()->toDateString();
    }

    /**
     * The clinical calendar date an absolute instant falls on.
     *
     * A string without an explicit offset is read in UTC — the application's
     * technical instant frame — because that is what an unqualified stored
     * timestamp means here. Pass an offset (`...Z`, `+08:00`) to be explicit.
     *
     * @throws InvalidClinicalTimezoneException
     * @throws \Throwable when the instant is unparseable — an
     *                    unreadable instant is an error, never "today".
     */
    public function toClinicalDate(DateTimeInterface|string $instant): CarbonImmutable
    {
        $parsed = $instant instanceof DateTimeInterface
            ? CarbonImmutable::instance($instant)
            : CarbonImmutable::parse($instant, 'UTC');

        return $parsed->setTimezone($this->timezone())->startOfDay();
    }

    public function toClinicalDateString(DateTimeInterface|string $instant): string
    {
        return $this->toClinicalDate($instant)->toDateString();
    }

    /**
     * A non-throwing posture report for readiness gates and diagnostics.
     *
     * Deliberately separate from `timezone()`: a gate has to be able to say
     * "this deployment is misconfigured" instead of crashing, while a clinical
     * decision has to refuse outright.
     *
     * Contains no secret and no clinical content — a timezone identifier is
     * safe to print in evidence.
     *
     * @return array{valid: bool, configured: mixed, effective: ?string, expected: string, canonical: bool, process_default: string, message: ?string}
     */
    public function inspect(): array
    {
        $configured = config(ClinicalTimezone::CONFIG_KEY);
        $valid = ClinicalTimezone::isValid($configured);
        $effective = $valid ? trim((string) $configured) : null;

        return [
            'valid' => $valid,
            'configured' => is_string($configured) ? $configured : null,
            'effective' => $effective,
            'expected' => ClinicalTimezone::DEFAULT,
            'canonical' => $effective === ClinicalTimezone::DEFAULT,
            // Reported for contrast only. The clinical answer never depends on
            // it; seeing "UTC" here next to a WITA clinical zone is correct.
            'process_default' => date_default_timezone_get(),
            'message' => $valid
                ? null
                : InvalidClinicalTimezoneException::forValue($configured)->getMessage(),
        ];
    }
}
