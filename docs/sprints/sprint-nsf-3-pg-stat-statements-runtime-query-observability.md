# Sprint NSF-3 — pg_stat_statements & Runtime Query Observability

## Pre-flight

| Item | Value |
| --- | --- |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Sprint branch | `feature/sprint-nsf-3-pg-stat-statements-runtime-query-observability` |
| Base HEAD (start) | `620980ad8509c971e0ed1e41aa8d1576ab9af625` |
| Includes NSF-2.2 evidence | yes (`620980a`) |
| Laravel | 12.61.0 |
| PHP (local) | 8.5.4 |
| DB driver (local) | pgsql |
| Test DB driver | sqlite (in-memory via phpunit.xml) |

## Graphify map

| Area | Finding |
| --- | --- |
| `performance:slow-query-audit` | `SlowQueryAuditCommand` → `SlowQueryAuditService` (NSF-1 baseline) |
| `pilot:performance-snapshot` | `PilotPerformanceSnapshotCommand` → snapshot classifier pattern |
| pg_stat in NSF-1 | `SlowQueryAuditService::collectPgStatStatements()` — top 5 mean-time only |
| Evidence storage | `storage/app/performance/` (NSF-1/NSF-2 convention) |
| Hotspots | RME visits, cashier/receivable, inventory movements, owner KPI |

## Objective

First read-only runtime PostgreSQL query observability layer via `pg_stat_statements`, privacy-safe, no business logic changes.

## Command

```bash
php artisan performance:runtime-query-observability
php artisan performance:runtime-query-observability --limit=20 --min-calls=1 --sort=total_time
php artisan performance:runtime-query-observability --json
php artisan performance:runtime-query-observability --output=nsf3-local-runtime-query-observability.json
php artisan performance:runtime-query-observability --reset-baseline  # pgsql only, explicit
```

## Privacy rules

- No patient names, KTP, phone, address, diagnosis, odontogram/medical notes
- No raw bindings or unsafe literals
- Normalized query summary from pg_stat_statements + sanitizer
- Module guess from safe table/keyword matching only

## Files added

- `app/Services/Monitoring/RuntimeQueryObservabilityService.php`
- `app/Console/Commands/PerformanceRuntimeQueryObservabilityCommand.php`
- `tests/Unit/Console/RuntimeQueryObservabilityCommandTest.php`

## VPS enablement runbook

1. Backup PostgreSQL config: `sudo -u postgres psql -tAc "SHOW config_file;"`
2. Check preload: `sudo -u postgres psql -c "SHOW shared_preload_libraries;"`
3. If missing, append `pg_stat_statements` (preserve existing libraries)
4. Restart PostgreSQL during deploy window
5. `CREATE EXTENSION IF NOT EXISTS pg_stat_statements;` in app database
6. Verify with `performance:runtime-query-observability --json`

## Rollback plan

- Revert sprint commit / remove command files
- pg_stat extension can remain enabled (harmless read-only)
- To disable: remove from `shared_preload_libraries`, restart PostgreSQL

## Risk assessment

- Low: read-only queries, no schema changes, no business logic
- Medium: `--reset-baseline` clears stats (opt-in only)

## GO/NO-GO (pre-deploy)

| Check | Status |
| --- | --- |
| Command implemented | GO |
| Tests green | GO (12 RuntimeQuery, 3600 full suite, 7 skipped) |
| Full suite green | GO — 3600 passed, 0 failed |
| Local evidence captured | GO |
| Build/style | GO (pint, npm build) |

### Local evidence

| Item | Value |
| --- | --- |
| pg_stat_statements (local) | unavailable (not preloaded locally) |
| Runtime report | `storage/app/performance/nsf3-local-runtime-query-observability.json` (832 B) |
| Slow query audit | `storage/app/performance/nsf3-local-slow-query-audit.json` |

---

## Post-merge evidence

| Item | Value |
| --- | --- |
| PR | [#157](https://github.com/makemesick91-code/new_lab_app/pull/157) |
| Merge commit | `5865a5eaae4b0776c660e76eb5084378b4e9f82f` |
| Sprint commit | `0367aa1` |
| GO tag | `sprint-nsf-3-pg-stat-statements-runtime-query-observability-go` → `5865a5e` |
| Local HEAD (merged) | `5865a5eaae4b0776c660e76eb5084378b4e9f82f` |
| Full suite | 3600 passed, 0 failed, 7 skipped |
| RuntimeQuery tests | 10 passed, 2 skipped (pgsql-only) |

## VPS deployment

| Item | Value |
| --- | --- |
| Status | **BLOCKED — infrastructure unreachable** |
| VPS previous HEAD | unknown (SSH unreachable) |
| VPS deployed HEAD | not deployed |

### Blocker details

**Local SSH (failed):**
```bash
ssh -o ConnectTimeout=15 -o BatchMode=yes daengtisiams-vps 'hostname'
# ssh: connect to host 145.79.13.224 port 22: Connection timed out
```

**GitHub Actions deploy (failed):**
- Workflow: `Deploy VPS Pilot` run [28687670723](https://github.com/makemesick91-code/new_lab_app/actions/runs/28687670723)
- Failed step: `Setup SSH` (exit 1) — SSH secrets/connectivity issue from CI runner

### Manual VPS steps (when connectivity restored)

```bash
cd /var/www/asia-dental-lab-v2
# backup DB + postgresql.conf (see runbook above)
git fetch --all --tags --prune
git checkout feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
git pull --ff-only origin feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
# verify: git describe --tags --exact-match HEAD
# enable pg_stat_statements if needed, then:
bash scripts/deploy-vps.sh
php artisan performance:runtime-query-observability --json --output=nsf3-vps-runtime-query-observability.json
php artisan performance:slow-query-audit --json --skip-benchmarks --output=nsf3-vps-slow-query-audit.json
```

## Final GO/NO-GO

| Area | Decision |
| --- | --- |
| Code + tests + PR + merge + GO tag | **GO** |
| VPS deploy + pg_stat evidence + smoke | **WATCH** — blocked by SSH/network; manual deploy required |

## NSF-4 recommendations (deferred)

- Scheduled weekly `performance:runtime-query-observability` capture on pilot
- Dashboard panel for top sanitized query summaries (read-only)
- Alert when `risk_hint` = high_mean_latency on RME/inventory modules
- pg_stat_statements tuning (`pg_stat_statements.max`, track_io_timing)
