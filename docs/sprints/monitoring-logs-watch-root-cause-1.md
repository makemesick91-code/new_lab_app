# MONITORING-LOGS-WATCH-ROOT-CAUSE-1

Root-cause the production `logs = WATCH`, decide whether the monitor is behaving
correctly, and fix whatever is actually broken.

**Base authority:** `84ac6953dddb67cff1bf23ac3abdd67e72386b7e`
(`test-baseline-monitoring-failures-1-go`)

## Verdict

The WATCH was **correct**. Five real `ERROR` events sat inside the 24 hour lookback
window, and the contract maps 1–20 fresh events to WATCH. Nothing was suppressed, no
threshold was moved, no window was shortened, and no log line was deleted.

The audit that proved the WATCH correct also found that the same section could report
**OK having observed nothing at all**. That is the defect this sprint fixes.

```
ROOT_CAUSE_CLASSIFICATION = (A) genuine application error
                          + (D) infrastructure error
                          + (F) operator tooling  — all correctly counted
MONITORING_BEHAVIOUR       = CORRECT for the WATCH
CODE_CHANGE_REQUIRED       = YES — but for a separate, latent false-green defect
```

## The monitoring contract, as measured from the code

| Property | Value |
|---|---|
| Command | `php artisan pilot:performance-snapshot` |
| Default window | `--since=24h` (86 400 s) |
| Scanned file | `storage_path('logs/laravel.log')`, single file |
| Scan budget | last 2 MiB |
| Error pattern | `/ERROR\|CRITICAL\|SQLSTATE\|timeout\|exception\|emergency\|fatal/i` |
| Event grouping | a `[YYYY-MM-DD HH:MM:SS]` header opens an event; later lines are continuations |
| Freshness | `timestamp >= now - lookback` (boundary inclusive → fresh) |
| `logs` status | 0 fresh → OK · 1–20 → WATCH · 21–100 → INVESTIGATE · >100 → FIX |
| Aggregate | `worst(app, database, resources, http, logs)` |
| Clock | `now()` in the app timezone (UTC), matching Monolog's header — **not** `ClinicalClock` |

Production runs this weekly: `daengtisiams-pilot-snapshot.timer`,
`OnCalendar=Sat *-*-* 19:30:00` UTC, `Persistent=true`, as the `daengtisiams` runtime
identity.

## The five events behind the WATCH

Observed read-only at `2026-08-22T06:07:35Z`. Messages are sanitised; no patient data,
KTP/NIK, token or request body appears here or in any monitor output.

| # | UTC | WITA | Event (sanitised) | Class |
|---|---|---|---|---|
| 1 | 2026-08-21 06:16:25 | 2026-08-21 14:16:25 | `SQLSTATE[08006]` — Postgres connection refused | **D** infrastructure |
| 2 | 2026-08-21 13:07:16 | 2026-08-21 21:07:16 | flysystem `Unable to create a directory` under the private RME consent path | **A** genuine application error |
| 3 | 2026-08-21 17:01:30 | 2026-08-22 01:01:30 | psysh config-directory write refused (`tinker`, no `HOME`) | **F** operator tooling |
| 4 | 2026-08-21 17:02:51 | 2026-08-22 01:02:51 | psysh parse error from a mistyped `--execute` | **F** operator tooling |
| 5 | 2026-08-22 06:06:51 | 2026-08-22 14:06:51 | psysh config-directory write refused | **F** — **created by this investigation** |

Event 4 is precisely the "latest known error at 2026-08-22 01:02 WITA" carried into
this sprint. Identity confirmed.

### Event 2 — the genuine application error, already recovered

A consent signature write failed because the per-consent directory could not be
created. It is **not live**:

- `2026-08-21 10:39–10:48Z` — deploy
- `2026-08-21 10:52Z` — the `rme-consents` parent directory appears
- `2026-08-21 13:07:16Z` — **write fails**
- deploys at `16:53–17:00Z` and `23:49–23:56Z` normalise storage ownership
- `2026-08-22 01:35Z` — the **same** consent directory is created successfully and
  holds both signature PNGs (consenter + doctor)

The window matches the INFRA-SEC-RUNTIME-1 runtime-identity migration
(`www-data` → `daengtisiams`): a directory created mid-migration was left owned by a
principal the new runtime could not write into, and the deploy's ownership
normalisation corrected it. Verified now: the runtime can write both the parent and
the consent directory, and a real consent was written after the failure.

The monitor surfacing this is the system working. A signed consent gates RME payment,
so a silent consent-storage failure is exactly what must not go unnoticed.

### Events 3–5 — operator tooling, and an honest disclosure

`php artisan tinker` writes genuine `ERROR` records into the application log. Event 5
was **caused by this investigation** — a `tinker` probe run at `06:06:51Z` before I
switched to the side-effect-free snapshot command.

It was not deleted. Deleting it to reach green is precisely what this sprint forbids.
Its cost is stated plainly: natural recovery moves from `2026-08-23 01:02:51 WITA` to
`2026-08-23 14:06:51 WITA`, a delay of about 13 hours.

The remedy is operational (rule 113 R6): do not run `tinker` against production; the
canonical read is `pilot:performance-snapshot --json`, which is side-effect free. A
psysh suppression rule is forbidden — it would blind the monitor to the next real
error that happens to share a phrase.

## Natural recovery, observed live

No code was needed to prove the window works. Between two read-only reads:

```
06:07:35Z   fresh = 5   oldest_fresh = 2026-08-21T06:16:25Z
06:18:11Z   fresh = 4   oldest_fresh = 2026-08-21T13:07:16Z
```

Event 1 crossed its 24 h boundary at `06:16:25Z` and left the window unaided.

## What was actually broken

The audit ran six parallel dimensions with adversarial verification. Every defect
below was then reproduced **by executing the code**, not by reading it.

### D1 — an error the monitor cannot age was invisible (false green)

A line matching the header shape whose timestamp fails `Carbon::parse` was counted in
**no** bucket — fresh, historical, orphan and attached all zero — while
`timestamp_parse_status` still reported `ok`. The classifier saw a clean empty window
and returned **OK**. A real `ERROR` produced a green monitor.

```
[2026-13-45 99:99:99] pilot.ERROR: boom
  before → fresh=0 hist=0 orphan=0 attached=0 tsLines=0 parse=ok  → OK
  after  → undated=1                                parse=failed  → WATCH
```

It also leaked its continuation lines onto the **next** event, because the early
return never cleared the grouping state.

A related, unreachable-in-practice variant is recorded rather than fixed: `Carbon`
rolls `2026-02-30` forward to `2026-03-02` instead of throwing, so a malformed date
can silently land in the wrong bucket. It only ever moves a timestamp *later*, so it
cannot manufacture a false green, and Laravel's formatter never emits one.

### D2 — an unreadable log read as OK

`readTail()` returned `''` when `fopen` failed, which the analyzer could not
distinguish from "scanned it, found nothing" → **OK**, alongside a *fabricated*
non-zero `tail_bytes_scanned`. It now returns `null` → **WATCH** + warning +
`tail_bytes_scanned = 0`.

Writing the test exposed a second, worse behaviour: the `fopen` warning is promoted to
an `ErrorException`, so the snapshot **aborted** rather than degrading. Both calls are
now diagnostics-suppressed, with the failure handled explicitly.

### D3 — an absent log read as OK, silently

Status stays OK on purpose — Laravel creates the file on first write, and escalating
would fire on every fresh checkout, a permanent false WATCH. It now always carries a
warning and a "not verified" reason, so it can never be mistaken for verified health.

### D4 — unparseable noise masked a real severity

150 fresh events — contractually **FIX** — returned a flat **WATCH** when 21 orphan
lines were present, because the guard returned early and discarded an already-correct
count. It understated the alarm exactly when the log was noisiest. Fixed by moving the
guard inside the `freshCount === 0` branch; undetermined freshness now escalates via
`worst()` and can never suppress.

### D5 — the scan budget was applied silently

The 2 MiB cap is a byte budget, not a time window. `tail_truncated` and a warning now
disclose when the counts are a lower bound. The residual — a truncated scan finding
zero fresh errors still reports OK — is recorded in rule 113 R5 with the correct fix
(a window-coverage check), deliberately not attempted here because escalating on
truncation alone would be a permanent false WATCH on any busy host.

## Not changed, on purpose

- **Thresholds, window, error pattern** — untouched.
- **`worst()` aggregation** — verified to include all five sections already.
- **The monitoring clock** — stays UTC. Moving it to `ClinicalClock`/WITA would be
  wrong (rule 113 R7).
- **`log_path`** — confirmed unreachable from operator or attacker input; the command
  exposes no such flag, so there is no path-traversal surface.
- **`worst()` degrading an unknown status to OK** — reachable only by passing a
  non-constant, which no caller does. Recorded, not fixed.

## Negative controls

| Control | Result |
|---|---|
| Production headers parse cleanly | 118 / 118, one uniform format, zero invalid month/day/hour/minute/second — and 118 exactly matches the snapshot's own `timestamped_lines` |
| Production verdict unchanged by the fix | file present, readable, 775 KB ≪ 2 MiB → no new escalation can fire |
| Healthy log still OK | pinned in `PilotPerformanceSnapshotLogSourceTest` |
| Mutation: analyzer stops counting undated events | **4 tests fail** |
| Mutation: classifier masking restored | **1 test fails** |
| Mutation: `readTail` swallows the failed open | **1 test fails** |
| All mutations reverted | 52 passed |

## Tests

`tests/Unit/Services/Monitoring/PilotPerformanceSnapshotLogSourceTest.php` — 16 tests
covering: no-false-green for unageable errors, continuation-leak containment,
unreadable/absent/truncated log sources, masking, the inclusive window boundary
(inside / exactly on / outside), unaided recovery by clock movement alone, and the
observed production shape.

```
Monitoring suite            52 passed  (206 assertions)   0 failures
Dependency regression      129 passed  (647 assertions)   0 failures, 1 pre-existing pgsql skip
Pint / git diff --check    clean
```

`KNOWN_MONITORING_FAILURES = 0` is preserved.

## Residuals recorded, not fixed

1. Window-coverage check for a truncated tail (rule 113 R5) — inert at 775 KB.
2. `Carbon` date rollover mis-bucketing — cannot produce a false green.
3. `worst()` degrading an unknown status to OK — unreachable from current callers.
4. The scanned log path is hardcoded rather than resolved from the configured channel.
   Production is `LOG_CHANNEL=stack` / `LOG_STACK=single` → `laravel.log`, so it is
   correct today, but a switch to `daily` would write `laravel-YYYY-MM-DD.log` and the
   monitor would go permanently green while errors accumulated elsewhere. The absent-file
   warning added here makes that visible; resolving the path from config is the real fix.
