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

---

## Post-Deploy Evidence

| Item | Value |
|------|-------|
| PR | [#155](https://github.com/makemesick91-code/new_lab_app/pull/155) |
| Merge commit | `79755883dea63258a85cb0fdfa6e23320b12437d` |
| GO tag | `hotfix-nsf-2-1-sqlite-test-migration-compatibility-go` |
| Local HEAD | `79755883dea63258a85cb0fdfa6e23320b12437d` |
| VPS previous HEAD | `43a61893ecd3d192adf03659b7d79d80efbd6ef9` |
| VPS deployed HEAD | `79755883dea63258a85cb0fdfa6e23320b12437d` |
| GO tag exact match | Yes (`git describe --tags --exact-match HEAD` on VPS) |
| Backup | `storage/app/backups/deploy/pre_nsf2_1_20260703-115754.sql` (547K) |
| Migration result | Nothing to migrate (guard-only change; indexes already exist) |
| Composer/npm build | OK |
| php-fpm/nginx | active (restarted/reloaded) |
| Performance audit | `storage/app/performance/nsf2-1-vps-evidence.json` (12K) |
| PostgreSQL indexes | `idx_nsf2_patients_branch_is_active`, `trx_rme_payments_rme_invoice_id_index`, `trx_rme_invoices_active_receivable_order_idx` — all present |

### VPS Smoke (curl -L http://127.0.0.1)

| URL | Status |
|-----|--------|
| `/` | 200 |
| `/login` | 200 |
| `/inventory/dashboard` | 200 |
| `/rme/visits` | 200 |
| `/dashboard` | 200 |

### Final GO/NO-GO

**GO** — Hotfix deployed to VPS pilot; SQLite test migration fixed; PostgreSQL indexes unchanged; smoke green.

> Note: GO tag points to merge commit `7975588`. Post-deploy evidence doc updates may follow on stable base as a separate commit.
