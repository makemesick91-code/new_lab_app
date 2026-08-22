# MONITORING-LOG-TIMESTAMP-ROLLOVER-1

**Base:** `a7622c75345158b5c2b94742ec6fb7cd01cc4c97` (`monitoring-log-source-resilience-1-go`)
**Scope:** the log-event timestamp contract in `PilotPerformanceSnapshotLogAnalyzer`.
**Outcome:** `CODE_CHANGE_REQUIRED = true` — a real false-green *and* a real
false-WATCH path were reproduced, and both are closed.

---

## Why this sprint exists

MONITORING-LOGS-WATCH-ROOT-CAUSE-1 found the Carbon date-rollover behaviour, judged
it harmless, and deferred it with this justification:

> It only ever moves a timestamp *later*, so it cannot manufacture a false green,
> and Laravel's formatter never emits one.

**Both clauses are wrong, and the first one is wrong in the direction that matters.**
That is the whole reason the residual survived two monitoring sprints: the backlog
entry told every later reader it was safe.

---

## What `Carbon::parse()` actually does

Measured against this repository's own vendored Carbon. The parser is not
uniformly permissive or uniformly strict — it is *inconsistently* both:

| Header digits | `Carbon::parse()` | Direction |
|---|---|---|
| `2026-02-30 10:00:00` | `2026-03-02` | rolls **forward** 2 days |
| `2026-02-29 10:00:00` (non-leap) | `2026-03-01` | rolls **forward** 1 day |
| `2026-08-00 10:00:00` | `2026-07-31` | rolls **backward** into the previous month |
| `2026-00-15 10:00:00` | `2025-12-15` | rolls **backward into the previous year** |
| `2026-13-01`, `2026-08-32`, `2026-08-22 25:00:00`, `10:61:00` | throws | correctly unageable |

So `2026-13-01` fails closed and is reported as unageable, while the structurally
identical corruption `2026-00-15` is silently converted into a confident,
plausible, *fabricated* instant. Same class of damage, opposite handling.

## The two defects, reproduced end-to-end

Run against the real analyzer + the real classifier, at base.

**FALSE GREEN.** Monitoring runs on 5 Jan. An `ERROR` stamped `[2026-00-15 …]`
becomes `2025-12-15`, three weeks old, and is counted as ordinary history:

```
[2026-00-15] ERROR  →  fresh=0  historical=1  undated=0  parse=ok
[2025-12-15] ERROR  →  fresh=0  historical=1  undated=0  parse=ok   ← indistinguishable
[2026-13-01] ERROR  →  fresh=0  historical=0  undated=1  parse=failed  ← correct behaviour
```

Historical errors contribute nothing to the verdict, so the logs section reports
**OK** while carrying an error whose real date is unknowable. This is precisely the
"an unageable ERROR must never disappear into `logs=OK`" guarantee that
MONITORING-LOGS-WATCH-ROOT-CAUSE-1 was written to establish — reopened through a
side door.

**FALSE WATCH.** The forward rolls are not benign either. On 2 Mar an `ERROR`
stamped `[2026-02-30 …]` reports `fresh=1` and publishes
`latest_fresh_error_at = 2026-03-02T10:00:00+00:00` — a WATCH resting on an instant
that never occurred, which no operator can clear by fixing anything real.

**Coverage corruption.** The fabricated date also anchored
`oldest_scanned_event_at`, which decides whether "no fresh errors" describes the
whole window or only the scanned part — so a rolled date could widen a coverage
claim the scan had not earned.

**Unbounded age shift (found during this sprint's audit).** The header's trailing
`[^\]]*` segment accepted arbitrary PHP relative modifiers, which `Carbon` honours:
`[2026-08-22 10:00:00 -1 year]` parsed to 2025 and silently reclassified a fresh
error as historical while still reporting `timestamp_parse_status: ok`. Strictly
worse than the calendar rollover, and equally silent.

## The fix

`extractTimestamp()` now captures the literal calendar digits alongside the value
it parses, and requires the parsed instant to **reproduce those digits exactly**:

```php
if ($timestamp->format('Y-m-d H:i:s') !== $literal) {
    return null;
}
```

This is a *faithfulness* test, not a stricter grammar — which is what keeps it safe:

- Everything that round-trips is accepted **unchanged**: Laravel's `Y-m-d H:i:s`
  (`LogManager::$dateFormat`, what production actually writes), Monolog's
  ISO-8601-with-offset default, fractional seconds, and explicit offsets.
- Only a value the parser had to *change* to make legal is rejected, and it is
  rejected into the **existing** null/unageable path, which already fails closed
  (`undated_error_like_count > 0` ⇒ `freshnessUndetermined` ⇒ WATCH, and OK is
  provably unreachable — `PilotPerformanceSnapshotClassifier:192-207`).
- The comparison is done in the parsed value's **own** timezone, so an explicit
  offset stays honoured rather than being mistaken for a rollover.
- It closes the relative-modifier class as a by-product: a modifier changes the
  digits, so it fails the same round-trip.

An over-strict *format* whitelist was deliberately rejected. Production writes one
format today; a whitelist that guessed wrong would turn every real error into
`undated` and produce a permanent, un-clearable WATCH storm. Faithfulness cannot
reject a well-formed timestamp, whatever its format.

## Deliberately NOT changed

- **The window.** `event >= window_start` (inclusive) is now pinned by test at
  `now-23:59:59` / `now-24:00:00` / `now-24:00:01`; it was already correct.
- **Timezone handling.** `config('app.timezone')` is `UTC`; log lines carry no
  offset, so the writer and reader share PHP's default timezone by construction,
  and the daily-rotation timezone is the same value. Everything already agreed —
  nothing was "normalised to WITA", and no evidence supported doing so.
- **`ClinicalClock`.** Monitoring does not reference it and still must not. Clinical
  business dates and infrastructure event ageing are separate domains.
- **Explicit offsets / timezone names** remain honoured. They are faithful
  representations of a real instant, not corruption.
- **Carbon mutability.** Every `subSeconds`/`subDays`/`startOfDay`/`setTimezone` in
  the monitoring stack is already guarded by `->copy()`; no shared instance is
  mutated. No immutability rewrite was warranted.

## Recorded, NOT fixed (honest backlog)

- **`oldest_scanned_event_at` can still be anchored by an injected *valid* ancient
  timestamp.** A benign non-error line such as `[2020-01-01 00:00:00] production.DEBUG:`
  sets the coverage anchor before the error-like filter, which can convert an
  honest truncation WATCH into OK. This sprint removes the *rollover-fabricated*
  version of that primitive, but the log-injection version is a different
  subsystem (coverage/truncation, plus Laravel's `allowInlineLineBreaks`) needing
  its own reproducer and tests. **Not silently ignored — deliberately deferred.**
- **`undated_error_like_count` cannot escalate past WATCH.** A rolled-over burst
  large enough to be FIX-level reports WATCH, because severity is driven only by
  the *parsed* fresh count. Fail-closed, but severity under-reports.
- **`RestoreDrillEvidenceService::ageHours()` uses `strtotime()`** on `completed_at`,
  which normalises identically and could suppress an `evidence_stale` WATCH. Same
  defect family, different subsystem (ROLL-5-1A restore-drill evidence, not the log
  monitor). Out of scope here; recorded so it is not lost.

## Temporal boundary matrix (measured, not asserted)

Every row was produced by running the base implementation and the fixed analyzer
over the same header at the same frozen `now`. `BEFORE` is the base regex +
`Carbon::parse()` verbatim.

| Case | Header digits | Kind | BEFORE | AFTER | Changed |
|---|---|---|---|---|---|
| Valid, inside window | `2026-08-22 10:00:00` | valid | fresh | fresh | no |
| Valid, outside window | `2026-08-01 10:00:00` | valid | historical | historical | no |
| Boundary `now-23:59:59` | `2026-08-21 12:00:01` | valid | fresh | fresh | no |
| Boundary `now-24:00:00` | `2026-08-21 12:00:00` | valid | fresh | fresh | no |
| Boundary `now-24:00:01` | `2026-08-21 11:59:59` | valid | historical | historical | no |
| Monolog ISO + offset | `2026-08-22T10:00:00+00:00` | valid | fresh | fresh | no |
| Same instant as `+08:00` | `2026-08-22T18:00:00+08:00` | valid | fresh | fresh | no |
| Fractional seconds | `2026-08-22 10:00:00.123456` | valid | fresh | fresh | no |
| Real leap day | `2028-02-29 10:00:00` | valid | fresh | fresh | no |
| Year boundary, still fresh | `2025-12-31 23:30:00` | valid | fresh | fresh | no |
| **DAY roll** | `2026-08-00 10:00:00` | corrupt | historical | **unageable** | **YES** |
| **MONTH/YEAR roll** | `2026-00-15 10:00:00` | corrupt | historical | **unageable** | **YES** |
| **Feb 30 rolls forward** | `2026-02-30 10:00:00` | corrupt | fresh | **unageable** | **YES** |
| **Feb 29 non-leap** | `2026-02-29 10:00:00` | corrupt | fresh | **unageable** | **YES** |
| Impossible month 13 | `2026-13-01 10:00:00` | corrupt | unageable | unageable | no |
| Impossible day 32 | `2026-08-32 10:00:00` | corrupt | unageable | unageable | no |
| Impossible hour 25 | `2026-08-22 25:00:00` | corrupt | unageable | unageable | no |
| **Modifier `-1 year`** | `2026-08-22 10:00:00 -1 year` | corrupt | historical | **unageable** | **YES** |
| **Modifier `+5 days`** | `2026-08-22 10:00:00 +5 days` | corrupt | fresh | **unageable** | **YES** |
| Future (clock skew) | `2030-01-01 10:00:00` | suspect | fresh | fresh | no |

**20 rows, 6 changed, 14 unchanged.** Every change is `corrupt → unageable`. No row
whose header was a faithful timestamp changed bucket — including both sides of the
window boundary, both timezone spellings of the same instant, and the future-dated
event, which stays counted rather than vanishing.

The two `historical → unageable` rows are the **false green** closing. The two
`fresh → unageable` rows are the **false WATCH** closing.

## Verification

- **Negative control (the load-bearing evidence):** the new suite run against the
  pre-fix analyzer at base → **12 failed / 17 passed**. Post-fix → **29 passed**.
  The 17 that pass in both are the invariant controls (real formats, boundary,
  timezone), proving the fix changes nothing it should not.
- **Determinism:** every test freezes the clock with `Carbon::setTestNow`. None
  depends on the real date, midnight, a month end, or a year end.
- `KNOWN_MONITORING_FAILURES = 0` preserved (rule 112).
- **Full Suite:** `FULL_SUITE_EXECUTION_COUNT=0`,
  `DEFERRED_BY_GLOBAL_TEMPORARY_POLICY` (rule 107 still ACTIVE).

## CI mapping

The new suite is named `PilotPerformanceSnapshotTimestampRolloverTest` so it matches
the `PilotPerformanceSnapshot` token already present in **both** NSF-R011 critical
gate variants — it maps to a real authoritative path rather than repeating the
earlier "suite exists locally, matches no filter" defect.
