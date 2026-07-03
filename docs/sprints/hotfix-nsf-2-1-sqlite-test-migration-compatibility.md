# Hotfix NSF-2.1 — SQLite Test Migration Compatibility

## Objective

Fix SQLite in-memory test DB migration failure caused by PostgreSQL-only `CREATE INDEX CONCURRENTLY` in `2026_06_30_153729_add_rme_receivables_performance_indexes.php`, while preserving PostgreSQL pilot/production index behavior.

## Root Cause

- PHPUnit uses `DB_CONNECTION=sqlite` in-memory (`phpunit.xml`).
- Migration `2026_06_30_153729_add_rme_receivables_performance_indexes.php` executed `CREATE INDEX CONCURRENTLY` without driver guard.
- SQLite error: `SQLSTATE[HY000]: General error: 1 near "IF": syntax error`.
- NSF-2 migration (`2026_07_03_200001`) already no-ops on non-pgsql; this older RME receivables migration did not.

## Graphify Map

- Test bootstrap: `tests/Pest.php` → `RefreshDatabase` on Feature tests.
- PHPUnit env: `phpunit.xml` → `DB_CONNECTION=sqlite`.
- Affected migration: `database/migrations/2026_06_30_153729_add_rme_receivables_performance_indexes.php`.
- Reference pattern: `2026_07_01_680200_add_read_path_indexes_for_rme_payments.php`, `2026_07_03_200001_add_nsf2_safe_performance_indexes.php`.
- Tables: `trx_rme_payments`, `trx_rme_invoices` (receivable/cashier read paths).

## Fix

Add `if (DB::connection()->getDriverName() !== 'pgsql') { return; }` to `up()` and `down()`.

- **PostgreSQL**: unchanged — `CREATE INDEX CONCURRENTLY IF NOT EXISTS` with partial index + INCLUDE preserved; `withinTransaction = false` kept.
- **SQLite/tests**: no-op — tables exist from prior migrations; no duplicate or unsafe indexes.
- **Other drivers**: no-op.

## Changed Files

- `database/migrations/2026_06_30_153729_add_rme_receivables_performance_indexes.php`
- `tests/Feature/Performance/Nsf21SqliteMigrationCompatibilityTest.php`
- `docs/sprints/hotfix-nsf-2-1-sqlite-test-migration-compatibility.md`

## Rollback Plan

Revert hotfix commit or restore migration file from prior commit. No data rollback — PostgreSQL indexes already exist on pilot from prior deploy.

## Risk Assessment

**Low** — additive guard only; PostgreSQL path unchanged; matches established project pattern.

## Pre-Deploy Evidence

### Environment

- Starting HEAD: `daf96ed31ce4b0a957319e2e973e1c64918a96a2` (includes NSF-2 evidence commit daf96ed)
- Laravel: 12.61.0
- PHP: 8.5.4
- Local default DB: pgsql
- Test DB: sqlite in-memory (`phpunit.xml`)

### Targeted Tests

| Filter | Result |
|--------|--------|
| Nsf21 | 3 passed |
| SlowQuery | 6 passed, 1 skipped |
| Nsf2 | 3 passed, 4 skipped (pgsql-only) |
| Cashier | 126 passed |
| Receivable | 140 passed (was 138 failed before fix) |
| Performance | 58 passed, 4 skipped |

### Full Suite

```
php artisan test
Tests: 18 failed, 5 skipped, 3572 passed (15296 assertions)
Duration: 1414.15s
Exit code: 2
```

Migration syntax issue **fixed** — no `CREATE INDEX CONCURRENTLY` SQLite failures. Remaining 18 failures are unrelated (RME workspace 403 vs 404, profile delete redirect, etc.).

### Build / Style

- `./vendor/bin/pint --dirty` — passed (migration formatted)
- `npm ci && npm run build` — passed

### GO/NO-GO (Local)

**GO** for hotfix objective — SQLite migration compatibility restored; PostgreSQL behavior preserved.
