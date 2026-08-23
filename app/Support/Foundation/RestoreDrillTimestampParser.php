<?php

namespace App\Support\Foundation;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1 — canonical parser for restore-drill
 * evidence timestamps.
 *
 * Restore-drill evidence is operational safety evidence: an age derived from it
 * decides whether the drill counts as current (GO) or must be re-run (WATCH).
 * An age is only trustworthy if the instant it was computed from faithfully
 * identifies the timestamp literal that was actually written.
 *
 * The evidence grammar has exactly ONE canonical producer form, and every source
 * agrees on it:
 *   - `scripts/rollout-restore-drill.sh` writes `date -u +%Y-%m-%dT%H:%M:%SZ`
 *   - `docs/runbooks/roll-5-backup-restore-drill-runbook.md` documents
 *     `"completed_at": "YYYY-MM-DDTHH:MM:SSZ"`
 *   - `docs/evidence/rollout/restore-drill-template.md` shows `2026-07-10T00:03:00Z`
 *
 * So this parser is deliberately format-exact rather than a permissive
 * best-effort parse. It is NOT a general-purpose timestamp utility and must not
 * become one: the Monitoring log-line analyzer legitimately accepts several real
 * logging formats and therefore uses a different (parse-then-verify-digits)
 * contract. Two domains, two grammars, two parsers — merging them would force
 * one of the two to accept input its own producer never emits.
 *
 * Faithfulness rule: the literal is parsed with the canonical format in UTC and
 * then re-rendered. It is accepted only when the rendering reproduces the input
 * byte-for-byte. Anything the parser had to normalise, roll over, or ignore in
 * order to make legal (invalid calendar dates, day/month zero, out-of-range
 * fields, trailing relative modifiers, trailing junk, surrounding whitespace)
 * fails the round-trip and is rejected instead of silently becoming a plausible
 * instant. Because the canonical form is explicitly UTC (`Z`), the round-trip
 * compares like with like — it never re-displays the instant in another zone, so
 * a correct timestamp can never be mistaken for a corrupted one.
 */
class RestoreDrillTimestampParser
{
    /** Canonical evidence timestamp format: UTC, second precision, Zulu suffix. */
    public const CANONICAL_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Parse a canonical restore-drill evidence timestamp.
     *
     * Returns the UTC instant only when the literal is exactly canonical and
     * faithfully round-trips; null for every other input (missing, empty,
     * whitespace, malformed, normalised, relative, or padded with junk).
     */
    public function parse(?string $literal): ?DateTimeImmutable
    {
        if ($literal === null || $literal === '') {
            return null;
        }

        try {
            $utc = new DateTimeZone('UTC');
            // '!' resets every field the format does not set, so an unparsed
            // component can never inherit "now" and masquerade as fresh.
            $parsed = DateTimeImmutable::createFromFormat('!'.self::CANONICAL_FORMAT, $literal, $utc);
        } catch (Throwable) {
            return null;
        }

        if (! $parsed instanceof DateTimeImmutable) {
            return null;
        }

        // Faithfulness: only a literal the parser did not have to change is
        // evidence of the instant it claims to be.
        if ($parsed->format(self::CANONICAL_FORMAT) !== $literal) {
            return null;
        }

        return $parsed;
    }

    /**
     * True when the literal is a faithful canonical evidence timestamp.
     */
    public function isFaithful(?string $literal): bool
    {
        return $this->parse($literal) instanceof DateTimeImmutable;
    }
}
