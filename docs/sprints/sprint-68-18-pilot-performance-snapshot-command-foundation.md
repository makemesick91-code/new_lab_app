# Sprint 68.18 — Pilot Performance Snapshot Command Foundation

## Executive Summary

- Implemented read-only Artisan command `php artisan pilot:performance-snapshot`.
- Supports console, JSON, and Markdown output modes.
- Classifies snapshot as OK / WATCH / INVESTIGATE / FIX with environment guard.
- No cron, alert, dashboard, migration, or DB writes.
- Deploy required after merge + GO tag so command is available on VPS/pilot.

## Deploy Decision

| Item | Decision |
|---|---|
| Deploy needed | Yes |
| Reason | New Artisan command must be available on VPS/pilot |
| Migration needed | No |
| DB write needed | No |
| Cron/alert installed | No |

## Implemented Scope

| Area | Result |
|---|---|
| Artisan command | `PilotPerformanceSnapshotCommand` |
| App health | APP_ENV, debug, maintenance, Laravel/PHP versions |
| DB health | Size, row counts, index, connections, long queries (PostgreSQL) |
| Q5/Q6 timing | EXPLAIN ANALYZE with statement_timeout |
| Resource health | Disk free, meminfo, load average |
| HTTP basic check | `/` and `/login` via HTTP client |
| Log summary | Error-like line count from log tail only |
| JSON output | `--json` |
| Markdown output | `--markdown` |
| Env guard | local/pilot/stress/testing; production requires `--force-production` |
| Tests | 16 Pest tests (command + classifier) |

## Command Usage

```bash
php artisan pilot:performance-snapshot
php artisan pilot:performance-snapshot --json
php artisan pilot:performance-snapshot --markdown
php artisan pilot:performance-snapshot --no-db
php artisan pilot:performance-snapshot --no-http
php artisan pilot:performance-snapshot --fail-on-watch
php artisan pilot:performance-snapshot --output=snapshot.json
```

## Safety Rules

- Read-only only.
- No raw logs.
- No PII.
- No secrets.
- No DB writes.
- No service restarts.
- Output files only under `storage/app/monitoring`.

## Local Verification

| Check | Result |
|---|---|
| artisan list | Pending final run |
| command console | Pending final run |
| JSON output | Pending final run |
| Markdown output | Pending final run |
| tests | `php artisan test --filter=PilotPerformanceSnapshot` — 16 passed |
| Pint | `./vendor/bin/pint --dirty` — passed |
| graphify | Pending final run |

## VPS Deploy Verification

| Check | Result |
|---|---|
| GO tag deployed | Pending |
| php artisan about | Pending |
| artisan list includes command | Pending |
| command console run | Pending |
| command JSON run | Pending |
| command markdown run | Pending |
| no migration required | Expected yes |
| services active | Pending |
| maintenance OFF | Pending |
| debug OFF | Pending |

## Final VPS Snapshot Summary

| Metric | Result | Status |
|---|---|---|
| APP_ENV | Pending | |
| DB size | Pending | |
| Patients | Pending | |
| Visits | Pending | |
| Payments | Pending | |
| Q5 | Pending | |
| Q6 | Pending | |
| Overall status | Pending | |

## What Was Not Implemented

- No cron/systemd.
- No alert.
- No dashboard UI.
- No monitoring DB table.
- No Netdata/Prometheus.
- No authenticated benchmark.

## Recommended Next Sprint

Primary: Sprint 68.19 — Pilot Performance Snapshot Weekly Evidence Using Command

Alternative: Sprint 68.19 — Pilot Snapshot Scheduling Plan

## Safety Confirmation

- No destructive DB command.
- No migration.
- No business logic changed.
- No PII exposed.
- No `.env`/backup/SSH key/DB dump/log committed.
- Deploy checklist to run after GO tag.

## Final Status

IN PROGRESS — pending commit, PR, merge, GO tag, VPS deploy
