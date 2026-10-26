---
paths:
  - "app/Services/Monitoring/**"
  - "app/Support/Health/**"
  - "app/Support/Observability/**"
  - "app/Support/DeveloperConsole/**"
  - "app/Modules/Observability/**"
  - "app/Services/Foundation/RestoreDrill*.php"
  - "app/Support/Foundation/RestoreDrill*.php"
  - "config/foundation_monitoring.php"
  - "config/health_check.php"
  - "config/developer_console.php"
  - "tests/Unit/Services/Monitoring/**"
  - "tests/Feature/Observability/**"
---

# Monitoring truthfulness, health & restore-drill evidence — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Log verdict truthfulness (`pilot:performance-snapshot`)

- **AIR-MON-01** Treat a logs WATCH caused by a real event as correct and never suppress it: no truncating, rotating,
  editing or deleting log lines, no rewriting an `ERROR` to a lower level, no message or exception-class allowlist, no
  shorter lookback, no higher threshold, no disabling the logs section or dropping it from the `worst()` aggregate.
  Recovery is ageing out of the window or fixing the runtime that emits the error. Source:
  `.cursor/rules/113-monitoring-logs-watch-truthfulness.mdc` (R1).
- **AIR-MON-02** Report logs OK only when every required source was actually read and the window was actually
  determined clear. An unreadable file is WATCH plus a warning with `tail_bytes_scanned = 0`; a missing `single` file,
  an unsupported stack member, or a configuration with no supported source is not OK. Source: `.cursor/rules/113-…`
  (R2), `114-monitoring-log-source-authority.mdc` (R5; supersedes 113's "absent file = OK").
- **AIR-MON-03** Prove every new escalation does not fire on a healthy, well-formed log, and keep a recoverable path:
  a fully read source with no fresh errors reports OK, one fresh error WATCH, 150 fresh errors still escalate. A false
  WATCH is a defect too. Source: `.cursor/rules/113-…` (R3), `116-…` (R9).
- **AIR-MON-04** Let undetermined freshness only raise a status through `worst()`; it may never short-circuit or discard
  an already-correct parsed count (150 fresh events plus orphan lines stays FIX). Source: `.cursor/rules/113-…` (R4).

### Log source and coverage authority

- **AIR-MON-05** Derive monitored log files only from `config('logging.*')` through
  `App\Services\Monitoring\MonitoringLogSourceResolver`, the single resolver shared by
  `PilotPerformanceSnapshotService` and `FoundationMonitoringStatusService`. Read `config()`, never `env()` (production
  runs `config:cache`). Never reintroduce a hardcoded `laravel.log` path or a second path knob such as
  `foundation_monitoring.paths.laravel_log`. Source: `.cursor/rules/114-…` (R1, R6).
- **AIR-MON-06** Resolve a `stack` recursively with cycle and depth guards; for `daily`, read every day-file whose day
  intersects the window (day boundaries in `config('app.timezone')`, capped at 31 files — hitting the cap makes
  coverage incomplete). Report an unsupported member, never drop it. Exempt a channel only by its
  `Monolog\Handler\NullHandler` class, never by its name. Source: `.cursor/rules/114-…` (R2, R3, R5); archive
  MONITORING-LOG-SOURCE-RESILIENCE-1.
- **AIR-MON-07** Tolerate exactly one absence: a missing `daily` day-file whose directory was listed and whose day is
  still inside that channel's `days` retention. Anything else that cannot be read fails closed. Source:
  `.cursor/rules/114-…` (R5).
- **AIR-MON-08** Decide coverage from bytes read, never from content: a source is covered only when its read began at
  byte 0. `MonitoringLogScanCoverage` takes integer offsets only — never add a `CarbonInterface`, event or line to it.
  `oldest_scanned_event_at` is an observation and must never certify skipped bytes. Multi-source coverage is
  conjunctive. Source: `.cursor/rules/116-monitoring-log-coverage-authority.mdc` (R1–R3, R6; supersedes 113 R5's
  "tail reaches past the cutoff" coverage).
- **AIR-MON-09** Treat a partially read required source as not OK and say which bytes were skipped. The operator lever is
  `foundation_monitoring.log_scan.max_source_bytes` (default 2 MiB, clamped to
  `LOG_SCAN_BUDGET_MIN_BYTES`…`LOG_SCAN_BUDGET_MAX_BYTES`, 64 KiB…64 MiB); never assume chronological order to skip a
  read. Source: `.cursor/rules/116-…` (R4, R5).
- **AIR-MON-10** Treat log content as untrusted: inline line breaks let a message forge a `[Y-m-d H:i:s]` header. Fix
  what is allowed to decide, never the sample payload (no "ignore INFO", no known-string filter). Source:
  `.cursor/rules/116-…` (R7, R8).
- **AIR-MON-11** Sum counts across sources (a duplicate record may overstate, never understate); never replace the sum
  with a max. Keep the logs metric schema stable across verdicts, with `null` for anything unmeasured. Source:
  `.cursor/rules/114-…` (R8, R9).

### Timestamps and severity

- **AIR-MON-12** Age an event only from the timestamp in its own header, never from file mtime, a rotated filename or
  line position. Accept a parsed value only if it reproduces the exact calendar digits of the line; otherwise it is
  undated. Never replace this faithfulness test with a format whitelist. Source:
  `.cursor/rules/115-monitoring-log-timestamp-authority.mdc` (R1, R2, R4).
- **AIR-MON-13** Remember that `Carbon` rollover moves dates in both directions (`2026-00-15` becomes `2025-12-15`,
  a false green; `2026-02-30` becomes `2026-03-02`, a false WATCH); never repeat the claim that it only moves later.
  Source: `.cursor/rules/115-…` (R3).
- **AIR-MON-14** Keep the window inclusive (`event >= now - lookback`) and pin any change with the
  `now-23:59:59` / `now-24:00:00` / `now-24:00:01` triple, under a frozen clock (`Carbon::setTestNow`). Source:
  `.cursor/rules/115-…` (R5, R10).
- **AIR-MON-15** Age infrastructure events against `now()` in the application timezone (UTC). Never wire
  `ClinicalClock` into monitoring or restore-drill freshness; a display timezone (WITA) never changes a verdict.
  Source: `.cursor/rules/113-…` (R7), `115-…` (R6, R8), `117-…` (8).
- **AIR-MON-16** Classify `errorEvidenceCount = fresh_error_like_count + undated_error_like_count` on one ladder:
  0 → OK (with complete coverage), 1–20 → WATCH, 21–100 → INVESTIGATE, > 100 → FIX. No separate undated thresholds, no
  independent per-bucket worst, each term floored at zero, and the critical count stays fresh-only. Source:
  `.cursor/rules/118-monitoring-undated-severity-escalation.mdc` (R2; supersedes 115 R9's WATCH ceiling).
- **AIR-MON-17** Keep severity monotonic in adverse evidence (pin it over a count grid), and never reach a calmer or
  harsher verdict by loosening the parser, relaxing coverage, shortening the window or widening `ERROR_PATTERN` / dropping
  the header requirement for the undated bucket. Source: `.cursor/rules/118-…` (R3–R5).
- **AIR-MON-18** Make the reason name its driver with static literals that distinguish fresh-only, all-undated and
  mixed (no log content, counts or paths), and keep `fresh_error_like_count`, `undated_error_like_count` and
  `timestamp_parse_status` in the payload. Source: `.cursor/rules/118-…` (R7).
- **AIR-MON-19** Do not merge `FoundationMonitoringStatusService::applicationLogSignal` (error lines in the recent tail,
  no ageing) into the snapshot's windowed ladder; one authority per question. Source: `.cursor/rules/118-…` (R8).

### Monitoring tests and production verification

- **AIR-MON-20** Hold `KNOWN_MONITORING_FAILURES = 0`. Root-cause a failing monitoring test; never allowlist, skip or
  quarantine it. Label a failure pre-existing only after reproducing the same signature on a pristine worktree at the
  exact base SHA. Keep every assertion a contract change does not invalidate, and prove it by mutation. Source:
  `.cursor/rules/112-monitoring-baseline-zero-known-failures.mdc` (R1, R2, R5).
- **AIR-MON-21** In a test asserting `overall_status` or an exit code, pin every section not under test (`skip_db`,
  `skip_http`, and `pilotSnapshotDiskProbe($freeGb)` as the `diskProbe` constructor argument); never recompute `worst()`
  from observed sections. Source: `.cursor/rules/112-…` (R3).
- **AIR-MON-22** Keep `PilotPerformanceSnapshotDiskProbe` a constructor seam only — never a `collect()` option, command
  flag, config key, environment variable or container binding. No path may emit OK for `resources` while the real disk
  is below the FIX threshold; an unreadable disk stays WATCH plus a warning. Source: `.cursor/rules/112-…` (R4).
- **AIR-MON-23** Verify production read-only with `php artisan pilot:performance-snapshot --json` and report what it
  says, including WATCH or FIX. Never write synthetic or malformed lines into a production log, never delete lines to
  clear a verdict, and never add a psysh/tinker suppression rule: tinker writes real `ERROR` records that the monitor is
  right to count (CG-14). Source: `.cursor/rules/113-…` (R6), `118-…` (R9).
- **AIR-MON-24** Expose no log path through HTTP or a CLI flag; the `log_path` seam on
  `PilotPerformanceSnapshotService::collect()` is internal and test-only. The command exits 0 unless `--fail-on-watch`,
  then by `PilotPerformanceSnapshotClassifier::exitCodeForStatus()` (FIX = 3). Source: `.cursor/rules/114-…` (R7);
  `app/Console/Commands/PilotPerformanceSnapshotCommand.php`.

### Restore-drill evidence

- **AIR-MON-25** Decide read state first with `App\Support\Foundation\RestoreDrillEvidenceReader::read()`
  (`absent | unreadable | read_failed | empty | ok`). Never coerce a failed read (`(string)` cast, `?: ''`, `?? ''`);
  `invalid_json` means read bytes the decoder rejected, wrong-shaped JSON is `evidence_not_an_object`, and a zero-byte
  file is `empty` (WATCH). Source: `.cursor/rules/92-restore-drill-evidence-read-state.mdc` (1–4).
- **AIR-MON-26** Keep candidate selection (`is_file && filesize > 0`) fixed: a read fault on the selected candidate
  blocks and never falls through to a later path. Changing a reason is allowed; moving a verdict needs explicit
  justification, and a read-state change must show `READINESS_DELTAS = 0`. Source: `.cursor/rules/92-restore-drill-…`
  (7).
- **AIR-MON-27** Parse `completed_at` only through `RestoreDrillTimestampParser` (`CANONICAL_FORMAT` =
  `Y-m-d\TH:i:s\Z`, byte-for-byte round-trip). Never use `strtotime()`, a bare `Carbon::parse()` or a `filemtime()`
  fallback; never clamp a negative age to zero. Keep this parser separate from the log analyzer's. Source:
  `.cursor/rules/117-restore-drill-timestamp-authority.mdc` (1–4, 6, 10).
- **AIR-MON-28** Give `missing`, `unparseable` and `future` timestamps a null age plus an explicit
  `evidence_timestamp_<state>` issue that forces WATCH, so no null age falls through the staleness check into GO.
  Fresh means `age_hours <= restore_drill_stale_hours` (default 720, inclusive); skew beyond
  `restore_drill_future_skew_minutes` (default 5) is `future`. Source: `.cursor/rules/117-…` (4–7).
- **AIR-MON-29** Map untrusted or missing evidence to WATCH, never a fake GO; reserve FAIL for production overwrite
  (`production_overwrite` must be exactly `false`), a production-like environment, leaked secret/PII, or a failed
  drill. `rollout:restore-drill-evidence` validates only and never restores; never edit or `chmod` canonical evidence
  to exercise a state. Source: `.cursor/rules/117-…` (9, 11), `70-roll-5-1a-restore-drill-evidence.mdc`,
  `92-restore-drill-…` (8).

### Health, consoles and telemetry

- **AIR-MON-30** Keep `/health/live`, `/health/ready` and `/health/lb` GET-only and minimal: overall status plus
  per-component `ok|degraded|down`, never APP_KEY, database host or credentials, environment values, route lists,
  user/branch/patient data or stack traces. `App\Support\Health\HealthCheckService` swallows probe exceptions; ready
  answers 503 when down; `health_check.alerting.external_channel_enabled` stays false. Source:
  `.cursor/rules/57-observability-health-check-pack.mdc`; archive ENT-8.
- **AIR-MON-31** Keep every `developer-console.*` route GET-only, behind `permission:view_developer_console`, audited
  through `DeveloperConsoleService::recordAccess` (`VIEW_DEVELOPER_CONSOLE`); `developer-console.index` keeps its name.
  Pass all free text through `SensitiveValueMasker` and never render audit payload columns. Source:
  `.cursor/rules/56-developer-assistance-console.mdc`, `175-observability-console.mdc`.
- **AIR-MON-32** Never put passwords, tokens, cookies, KTP/NIK, clinical notes, SOAP, RME or odontogram payloads or
  request bodies into telemetry; request bodies, query strings and SQL bindings are never read. `TelemetryRedactor` is
  the one sanitizer. Telemetry writes only to `sys_obs_*` in `terminate()`, never throws or `report()`s, and never calls
  `BranchContext::forUser()`. Source: `.cursor/rules/175-…` (1, 3–5).
- **AIR-MON-33** Keep thresholds, retention and route exclusions (with a reason) only in
  `config/observability_console.php`; retention stays bounded (per-request lottery plus `observability:prune`). State
  scope honestly: app cache hit rate is not Redis server hit rate, Redis is `NOT_IN_USE` unless it backs
  cache/session/queue, and the app never detects nginx 499/502/504. Source: `.cursor/rules/175-…` (2, 3, 7–9).
- **AIR-MON-34** Keep `OBSERVABILITY_TELEMETRY_ENABLED=false` in `phpunit.xml`; observability suites enable it
  explicitly. Never re-enable it suite-wide, or query-budget tests measure telemetry. Source: `.cursor/rules/175-…` (10).
- **AIR-MON-35** Keep MON-1 (`foundation:monitoring-observability-check`, `/foundation/monitoring`) a consolidation
  that re-implements no probe or audit: read-only, `view_developer_console` only, no expensive command on a web request
  (`--include-audits` is CLI-only), queue worker/scheduler UNKNOWN rather than faked green, writability probed with a
  temp file and never `chmod`. Source: `.cursor/rules/68-foundation-monitoring-observability.mdc`.
- **AIR-MON-36** Trust an inbound `X-Request-ID`/`X-Correlation-ID` only when its `trust_inbound` flag is on and the
  value passes strict validation; keep log context PII-free. Central logging and error tracking stay OFF; never print
  an endpoint, DSN or API key, and `send_pii = true` fails readiness regardless of `--strict`. Source: archive OBS-1,
  OBS-2.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- `daengtisiams-pilot-snapshot.timer` (host-side, not in the repository) runs `pilot:performance-snapshot` weekly,
  Saturday 19:30 UTC, as `daengtisiams`; no repository script or deploy step invokes it.
- Production logs resolve `stack → [single] → laravel.log`; scan budget 2 MiB; lookback 24h.
- Health external alert channel, central logging and error tracking are OFF; Redis is not in use.
- Production holds no restore-drill evidence: restore-drill readiness is honestly WATCH (`evidence_absent`).
- The Observability Console is live behind Super Admin only; authenticated page render was not exercised in
  production (covered in CI).

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/56-developer-assistance-console.mdc` | ENT-7 read-only audited developer console |
| `.cursor/rules/57-observability-health-check-pack.mdc` | ENT-8 minimal GET-only health endpoints |
| `.cursor/rules/68-foundation-monitoring-observability.mdc` | MON-1 signal consolidation, no re-implementation |
| `.cursor/rules/92-restore-drill-evidence-read-state.mdc` | Read state before parse; no coerced reads |
| `.cursor/rules/112-monitoring-baseline-zero-known-failures.mdc` | Zero known failures; host-independent assertions |
| `.cursor/rules/113-monitoring-logs-watch-truthfulness.mdc` | Real WATCH never suppressed; OK needs evidence |
| `.cursor/rules/114-monitoring-log-source-authority.mdc` | Log sources from logging config; fail closed |
| `.cursor/rules/115-monitoring-log-timestamp-authority.mdc` | Faithful timestamps; rollover both ways; UTC |
| `.cursor/rules/116-monitoring-log-coverage-authority.mdc` | Coverage from bytes read, never content |
| `.cursor/rules/117-restore-drill-timestamp-authority.mdc` | Canonical drill timestamp; untrusted age fails closed |
| `.cursor/rules/118-monitoring-undated-severity-escalation.mdc` | Undated and fresh errors share one ladder |
| `.cursor/rules/175-observability-console.mdc` | Telemetry redaction, retention, console access |
| `.cursor/rules/70-roll-5-1a-restore-drill-evidence.mdc` | Restore-drill evidence gating rollout Stage-1 |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/15-monitoring.md`; ENT-7, ENT-8, OBS-1, OBS-2, MON-1 and
FEATURE-DEV-CONSOLE-OBSERVABILITY-1 in `docs/sprints/claude-md-archive/12-foundation-enterprise.md`. Grep by sprint
id; never read a whole file.
