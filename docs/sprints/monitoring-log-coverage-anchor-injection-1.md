# MONITORING-LOG-COVERAGE-ANCHOR-INJECTION-1

**Log content must not be able to prove that log coverage exists.**

Base `01d2870d201d350ac9b5da6dfa2e4e1d7eeb2f13` (`monitoring-log-timestamp-rollover-1-go`).
Type `RUNTIME_FIX`. No migration, no permission, no route, no schema, no driver change.

---

## 1. The residual, and why it was not a metadata wobble

MONITORING-LOG-TIMESTAMP-ROLLOVER-1 recorded an honest backlog item: that
`oldest_scanned_event_at` "can still be anchored by an injected *valid* ancient benign
line … converting a truncation WATCH into OK". It was filed as "a log-injection/coverage
concern in a different subsystem needing its own reproducer".

It needed the reproducer because it was a **false green**, and this sprint's first job
was to prove that rather than assume it.

A log source larger than the 2 MiB scan budget is read tail-only, so its **prefix** is
skipped. Coverage was then decided here:

```php
// PilotPerformanceSnapshotService::scanLogSources — the defect
$requiredFrom  = $source->requiredCoverageFrom($cutoff);
$oldestScanned = $metrics['oldest_scanned_event_at'] ?? null;
$sourceCovered = ! $truncated
    || ($oldestScanned !== null && Carbon::parse($oldestScanned)->lessThanOrEqualTo($requiredFrom));
```

`oldest_scanned_event_at` is the minimum timestamp over **every event header of any
level** found in the scanned tail. So the coverage decision was made of log content, and
the reasoning behind it — "if the oldest line I saw is already older than the window,
everything before it must be older still" — is only valid if the file is strictly
chronological. Nothing enforces that.

## 2. Reproduction (executed, not reasoned)

Clock frozen at `2026-08-22 12:00:00`, 24h window, cutoff `2026-08-21 12:00:00`. A real
in-window `pilot.ERROR` is placed in the **unscanned prefix**; the scanned tail is varied.

| # | Scanned tail contents | `oldest_scanned_event_at` | `window_fully_covered` | fresh errors seen | logs | overall |
|---|---|---|---|---|---|---|
| A | all ancient benign `INFO` | `2026-08-20T09:00` | **true** | 0 | **OK** | **OK** |
| B | all fresh benign `INFO` (control) | `2026-08-22T11:30` | false | 0 | WATCH | WATCH |
| C | fresh `INFO` + **one** injected `[2019-01-01 00:00:00]` line | `2019-01-01T00:00` | **true** | 0 | **OK** | **OK** |

**B and C differ by one line.** That line flipped the verdict from WATCH to OK while a
genuine `SQLSTATE[08006] payment write failed` sat unread in the prefix — and the section
reported *"No fresh error events within lookback window."*

So: `FALSE_COVERAGE_BEFORE=true`, `FALSE_GREEN_BEFORE=true`,
`HIDDEN_FRESH_ERROR_REPRODUCED=true`, `CONTENT_CONTROLS_COVERAGE=true`.

The defect was **not** in the classifier — `PilotPerformanceSnapshotClassifier` already
returns WATCH for `!$windowFullyCovered` on the zero-count branch, and that is the only
branch where the flag is read. It was fed a `true` it should never have been given.

## 3. Reachability — verified mechanism, honestly bounded

A line is treated as an event header whenever it merely **starts** with
`[Y-m-d H:i:s…]` (`isTimestampedHeader`). Laravel builds its formatter as
`new LineFormatter(null, $this->dateFormat, true, true, true)`
(`Illuminate/Log/LogManager.php:488`) — the third argument is
`$allowInlineLineBreaks`. Verified empirically in this sprint by formatting a record
through that exact construction:

```
[2026-08-22 11:45:00] production.WARNING: Sync failed {"error":"connection failed
[2019-01-01 00:00:00] production.INFO: recovered"}
```

Two physical lines; both match the event-header shape; the analyzer returned
`oldest_scanned_event_at = 2019-01-01T00:00:00+00:00`. A newline inside a **context array
value** — not only inside a message — becomes a forged header. `QueryException` messages
embed SQL and bindings, and several services put an unbounded `$e->getMessage()` into a
log context array.

**Stated honestly:** the *primitive* is verified. A specific end-to-end
attacker-controlled path from an unauthenticated production request was **not** proven,
and this sprint does not claim one. It does not need one: Monitoring must stay correct in
the presence of syntactically valid content it did not expect, whatever its origin —
an operator, an import, a vendor error string, or a delayed/backdated write.

Non-injection routes to the same anchor are also real: multi-process writers (PHP-FPM
plus the queue worker), a restored or concatenated log, or a line written with an
explicit past timestamp.

## 4. The fix

Coverage is now a fact about the read.

```php
$sourceCovered = $coverage->isComplete();   // true only when the read began at byte 0
```

`MonitoringLogScanCoverage` is a value object built from `fileBytes`, `scanStartOffset`
and `bytesScanned`. **It takes no timestamp, no event and no line** — the type cannot
accept content, so the comparison cannot be reintroduced by accident. A test asserts
every public parameter is `int` and that the file never mentions `Carbon`.

Supporting changes, all consequences of that one decision:

- `readTail()` now returns the content **and** the coverage, produced at the only place
  that knows the real read offset. The caller's second `filesize()` is gone — two stats
  of a file that can change underneath them is exactly how coverage drifts from the read.
- `MonitoringLogSource::requiredCoverageFrom()` is **removed**. It had one caller: the
  defect. Leaving it would leave the wiring for the same mistake; a comment in its place
  records what it was and why it went. `coversFrom` stays — it answers a structural
  question (which day a rotating file belongs to) used by the absent-day rule.
- `oldest_scanned_event_at` **stays**, documented as an observation that decides nothing.
  The goal was never to delete it.
- The scan budget becomes `foundation_monitoring.log_scan.max_source_bytes`, clamped to
  `[64 KiB, 64 MiB]`, defaulting to the 2 MiB this monitor has always used. Fail-closed
  needs a lever, and the "raise the scan budget" advice the warning already gave had no
  knob behind it. It is clamped because buying coverage must not become an unbounded read.
- Warnings now name the skipped byte count and the config key.

## 5. Coverage matrix (after the fix)

Physical = did the read start at byte 0. Claim = what the monitor asserts.

| Scenario | Physical coverage | Claim | Verdict |
|---|---|---|---|
| Well under budget | complete | complete | OK (no fresh errors) |
| Exactly at budget | complete | complete | OK |
| One byte over budget | partial | **withdrawn** | WATCH |
| Far over budget | partial | withdrawn | WATCH |
| Partial + ancient anchor in tail | partial | withdrawn | WATCH |
| Partial + ancient anchor + hidden fresh ERROR | partial | withdrawn | WATCH |
| Partial, budget raised above file size | complete | complete | OK |
| Daily: today complete, yesterday truncated | partial | withdrawn | WATCH |
| Stack: one member complete, one truncated | partial | withdrawn | WATCH |
| Stack: all members complete | complete | complete | OK |
| Required source missing / unreadable / unsupported | none | withdrawn | WATCH |
| Complete + 1 fresh ERROR | complete | complete | WATCH |
| Complete + 150 fresh ERRORs | complete | complete | FIX |

Presented as a table rather than a chart deliberately: the data is ~13 discrete
categorical verdicts, which is a lookup job, and forcing it into a chart would encode
nothing the table does not already say.

## 6. Negative controls

- **Mutation control.** Reintroducing the content-anchored expression makes
  **18 of the 35** new tests fail. The suite detects the defect; it does not merely
  describe it. Restored afterwards, 35 pass.
- **A/B differential.** Two files identical except how old their tail events look must
  reach the same coverage verdict — pinned as a test, not just observed once.
- **Recoverability.** Fail-closed alone would be its own defect (rule 113 R3). Pinned:
  fully read + clean → OK; + 1 fresh error → WATCH; + 150 → FIX; budget raised → OK again.
- **Superseded test.** Exactly one existing test asserted that a truncated scan may
  report OK. It was the defect stated benignly, and it is rewritten in place with its
  original reasoning quoted and answered — not deleted.

## 7. What this sprint did NOT change

The 24h boundary (`event >= window_start`, inclusive), severity thresholds
(0→OK, 1–20→WATCH, 21–100→INVESTIGATE, >100→FIX), timestamp faithfulness (rule 115),
the absent-day tolerance, the resolver's stack/daily expansion and caps, Monitoring's UTC
clock, `MonitoringLogSourceResolver` path safety, and `FoundationMonitoringStatusService`.

**On that last one, explicitly:** MON-1's `applicationLogSignal()` is a *separate*
signal that counts errors in the last 200 **lines**. It computes no coverage, reads
neither `window_fully_covered` nor `oldest_scanned_event_at`, and shares only the source
resolver. It is therefore not affected by this defect, and no second content-controlled
coverage authority was left behind — there is exactly one coverage calculation in the
codebase, and this sprint fixed it.

## 8. Residuals — recorded, not fixed

- **Log injection remains possible.** `allowInlineLineBreaks: true` is Laravel's own
  formatter behaviour; changing it is a global logging change far outside a Monitoring
  fix. After this sprint a forged header can still perturb `oldest_scanned_event_at`,
  `timestamped_lines` and stack-grouping counts — but it can no longer buy coverage, and
  the direction it *can* still push a verdict (adding a fake fresh error) fails safe
  toward WATCH.
- `undated_error_like_count` still cannot escalate past WATCH (fail-closed; severity
  under-reports) — MONITORING-UNDATED-SEVERITY-ESCALATION-1.
- `RestoreDrillEvidenceService::ageHours()` still uses `strtotime()` —
  RESTORE-DRILL-TIMESTAMP-FAITHFULNESS-1.
