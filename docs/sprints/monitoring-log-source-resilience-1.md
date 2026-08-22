# MONITORING-LOG-SOURCE-RESILIENCE-1

**Type:** RUNTIME_FIX · **Module:** Monitoring
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Baseline:** `monitoring-logs-watch-root-cause-1-go` @ `b88be64f58a6692a7c0d60655802d3d702510359`
**Rule mirror:** [`.cursor/rules/114-monitoring-log-source-authority.mdc`](../../.cursor/rules/114-monitoring-log-source-authority.mdc)

## The residual this closes

MONITORING-LOGS-WATCH-ROOT-CAUSE-1 proved the production `logs = WATCH` was correct and made
it impossible for the logs section to report OK having observed nothing. It left one
assumption standing, and recorded it as a known residual: the monitor read a hardcoded
`storage/logs/laravel.log`.

That path was correct only by coincidence. Production resolves:

```
logging.default = stack
logging.channels.stack.channels = [single]
logging.channels.single.path = <app>/storage/logs/laravel.log
```

so the hardcoded path happened to hit the file the application writes. The failure mode:

```
LOG_CHANNEL or LOG_STACK changes to daily (or the stack gains a member)
  → the application writes errors to laravel-YYYY-MM-DD.log
  → the monitor keeps reading laravel.log, which has stopped moving
  → no fresh errors found
  → logs = OK
  → FALSE GREEN on a system that is actively failing
```

## Reproduced before it was fixed

Three negative controls stage a live in-window error where the *configured* channel actually
writes, and leave a clean `laravel.log` next to it. Against the hardcoded implementation all
three reported `OK`:

| Reproducer | Configured channel | Before | After |
|---|---|---|---|
| error in today's daily file | `daily` | `OK` ❌ | `WATCH` ✅ |
| error in a daily file behind a stack | `stack → [daily]` | `OK` ❌ | `WATCH` ✅ |
| error in yesterday's file, 30s after midnight | `daily` | `OK` ❌ | `WATCH` ✅ |

`LATENT_FALSE_GREEN_REPRODUCED = true` → `FALSE_GREEN_AFTER = false`.

## What changed

### New — `App\Services\Monitoring\MonitoringLogSourceResolver`

Its single responsibility is *effective logging config → the set of files that must be read*.
It does not parse log lines and does not classify health.

- Reads `config('logging.*')`, never `env()`. Production runs with `config:cache`, where
  `env()` is not the runtime authority.
- Expands `stack` to its real members, recursively, with a cycle guard and a depth cap.
- `single` → one file responsible for all of history.
- `daily` → one file per calendar day intersecting the window, named by Monolog's
  `{filename}-{date}` convention applied to the **configured** path, with day boundaries in
  `config('app.timezone')` — the clock Monolog stamps filenames with.
- Unsupported drivers (syslog, stderr, Slack, custom monolog) are **reported**, not dropped.
- A `null` channel is exempt, detected by its `NullHandler` class rather than by being
  *named* `null` — the shipped `null` channel's driver is `monolog`.

Each source carries the slice of time it is responsible for, which is what makes coverage
decidable rather than assumed.

### Changed — both monitors, one authority

`PilotPerformanceSnapshotService::collectLogSummary()` now resolves, reads and folds a source
*set*: counts sum, statuses take the worse, timestamp extremes widen, and coverage is the
conjunction. `FoundationMonitoringStatusService::applicationLogSignal()` (MON-1) consumes the
same resolver — it previously answered **GO** for a missing file, so a relocated log read as a
clean bill of health.

The duplicate `config('foundation_monitoring.paths.laravel_log')` knob was **retired**. A
second declared path could disagree with the running logger, and being the one the monitor
actually read, it would have won silently — making this whole fix inert in production.

### Coverage and fail-closed contract

| Condition | Verdict |
|---|---|
| no supported source configured at all | not OK |
| nothing readable actually read | not OK |
| unsupported member in a monitored stack | not OK (coverage incomplete) |
| `single` file missing | not OK |
| present file that cannot be opened | not OK, no fabricated byte count |
| `daily` day-file missing, directory listable, within `days` retention | **OK** — observed empty day |
| `daily` day-file missing, directory unlistable or past retention | not OK |
| any source truncated short of its required reach | not OK |

## Deliberate contract change

The previous sprint scored a **missing** log file as `OK` plus a warning, on the reasoning
that Laravel creates the file on first write. That reasoning cannot separate a quiet system
from a relocated log, and the relocated case is the dangerous one. Absence is now scored as
unverified.

The cost is real and accepted: a fresh checkout that has never logged reports WATCH. That is
honest — Monitoring GO has never meant Monitoring green (rule 113). The superseded test is
updated in place with the rationale, not deleted.

## Not changed

- Lookback window (`24h`), severity thresholds, and the `1–20 fresh → WATCH` band.
- The analyzer's parsing, grouping and ageing logic.
- The deterministic disk probe seam (rule 112 R4).
- The Carbon date-rollover residual, which remains open backlog and is **not** in scope here.

## Verification

- Negative controls: 3, failing before / passing after.
- New tests: 22 in `tests/Unit/Services/Monitoring/MonitoringLogSourceResilienceTest.php`
  covering single / daily / stack / mixed stack / midnight rollover / unsupported member /
  retention horizon / unlistable directory / 150-event burst split across sources /
  partial-scan coverage / resolver unit behaviour including nested and cyclic stacks.
- Monitoring baseline stays at **zero** known failures (rule 112).
- Full Suite: `FULL_SUITE_EXECUTION_COUNT=0`, deferred by the global temporary policy.

## Production posture

Production resolves to exactly one file today, so the deployed behaviour is unchanged: the
monitor reads the same `laravel.log` it always did — now because configuration says so, not
because the path was compiled in. The value delivered is that the next `LOG_CHANNEL` or
`LOG_STACK` change cannot silently blind it.
