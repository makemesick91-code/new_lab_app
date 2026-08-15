<?php

declare(strict_types=1);

namespace App\Support\Clinical;

use DateTimeZone;

/**
 * LEGACY-RME-DATE-TZ-1 — the canonical clinical calendar timezone.
 *
 * DaengtisiaMS clinical operations run on Indonesia Central Time. `DEFAULT` is
 * the ONE place that literal is written; `config/clinical.php` and every other
 * config that needs the clinic's wall clock reference this constant so two
 * config files can never drift into disagreeing about what day it is.
 *
 * IANA IDENTIFIER ONLY. `Asia/Makassar` carries the zone's real history and
 * future rules. `WITA`, `UTC+8`, `GMT+8` and `+08:00` are fixed offsets or
 * ambiguous abbreviations: PHP does not accept some of them at all, and the
 * ones it does accept silently lose the ability to ever represent a rule
 * change. A clinical calendar must be able to.
 *
 * THIS IS THE CLINICAL CALENDAR, NOT THE STORAGE CLOCK. Technical instants —
 * `created_at`, `updated_at`, queue timestamps, audit event times, deployment
 * logs — keep their existing architecture (normally UTC). The two are separate
 * semantic domains and conflating them is the defect this sprint closes.
 */
final class ClinicalTimezone
{
    /**
     * The canonical clinical calendar timezone for the current deployment.
     *
     * Single global value by design: every branch DaengtisiaMS currently serves
     * operates on this wall clock. A per-branch timezone is a separate product
     * decision and must not be invented by inference.
     */
    public const DEFAULT = 'Asia/Makassar';

    /**
     * The configuration key that carries the canonical value at runtime.
     */
    public const CONFIG_KEY = 'clinical.timezone';

    /**
     * The environment override key. It exists so an operator can correct a
     * misconfigured deployment without a code change — never so a bad value can
     * degrade quietly. An unusable value FAILS; it never falls back to UTC.
     */
    public const ENV_KEY = 'CLINICAL_TIMEZONE';

    /**
     * Whether a string is an identifier this platform can actually resolve.
     *
     * Deliberately strict: a blank string, a non-IANA abbreviation PHP happens
     * to tolerate, or a typo like `Asia/Makasar` must all be rejected so the
     * caller fails closed instead of computing a clinical date in the wrong
     * frame.
     */
    public static function isValid(mixed $timezone): bool
    {
        if (! is_string($timezone) || trim($timezone) === '') {
            return false;
        }

        $candidate = trim($timezone);

        // Memoised per process: the platform's timezone database cannot change
        // mid-request, and this runs on every clinical date decision.
        static $memo = [];

        if (isset($memo[$candidate])) {
            return $memo[$candidate];
        }

        // listIdentifiers() is the platform's own IANA database. Matching
        // against it rejects fixed offsets and legacy abbreviations that
        // DateTimeZone would otherwise construct without complaint.
        if (! in_array($candidate, self::identifiers(), true)) {
            return $memo[$candidate] = false;
        }

        try {
            new DateTimeZone($candidate);
        } catch (\Throwable) {
            return $memo[$candidate] = false;
        }

        return $memo[$candidate] = true;
    }

    /**
     * @return list<string>
     */
    public static function identifiers(): array
    {
        /** @var list<string> $identifiers */
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

        return $identifiers;
    }
}
