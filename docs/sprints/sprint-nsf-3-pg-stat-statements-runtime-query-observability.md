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

*Post-deploy evidence (PR, merge, GO tag, VPS) updated after deployment.*
