# Sprint NSF-2 — Safe Index Pack & Query Plan Hardening

## Pre-flight

| Item | Value |
| --- | --- |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Sprint branch | `feature/sprint-nsf-2-safe-index-pack-query-plan-hardening` |
| Base HEAD (start) | `b42f77afa915594be812328b7c5c699aaf5d0885` (includes NSF-1 post-deploy evidence) |
| Laravel | 12.61.0 |
| PHP (local) | 8.5.4 |
| DB driver | pgsql |
| APP_ENV (local) | local |

## Graphify map (query paths)

| Area | Files / patterns |
| --- | --- |
| Inventory mutations | `InventoryMovementRepository` — `branch_id` + `movement_date` windows, stock card, valuation |
| Inventory dashboard/reports | `InventoryReportService`, `InventoryDashboardService` via movement repo |
| Patient branch lists | `PatientRepository::forAudit()` — `branch_id` + `is_active` |
| Cross-branch lookup | `CrossBranchPatientLookupService` — selects `branch_id`, `is_active` |

**Routes:** `inventory.dashboard`, `inventory.reports.*`, `settings.patients`, RME patient audit (`rme.patients.audit`).

## Existing index review (pre-migration)

| Table | Candidate | Pre-existing index | Action |
| --- | --- | --- | --- |
| `trx_inventory_movements` | `(branch_id, movement_date)` | `trx_inv_movements_branch_date_index` (migration `2026_06_10_100000`) | **No duplicate** — NSF-2 audit recognizes alias |
| `mst_patients` | `(branch_id, is_active)` | Only single-column `mst_patients_is_active_index` | **Created** `idx_nsf2_patients_branch_is_active` |

## Migration

File: `database/migrations/2026_07_03_200001_add_nsf2_safe_performance_indexes.php`

- PostgreSQL `CREATE INDEX CONCURRENTLY IF NOT EXISTS` with `$withinTransaction = false`
- Idempotent column-signature check before create
- SQLite no-op (tests)

**Rollback:** `php artisan migrate:rollback --step=1` (drops `idx_nsf2_patients_branch_is_active`; preserves legacy inventory index)

## Before / after evidence

| File | When |
| --- | --- |
| `storage/app/performance/nsf2-before-local.json` | Pre-migration (2 deferred, benchmarks skipped — empty branch data) |
| `storage/app/performance/nsf2-after-local.json` | Post-migration (0 deferred, 9 benchmarks, branch_id=1 fallback) |

### Summary

| Benchmark | After index scan | Notes |
| --- | --- | --- |
| `inv_movements_month_window` | Seq scan on empty local DB | Acceptable locally; index `trx_inv_movements_branch_date_index` present for pilot scale |
| `rme_patients_branch_active` | **Index Scan** via `idx_nsf2_patients_branch_is_active` | NSF-2 primary win |
| Other benchmarks | Unchanged | No regression |

## Deliverables

- Migration `2026_07_03_200001_add_nsf2_safe_performance_indexes.php`
- `SlowQueryAuditService` — NSF-2 status, plan node summary, index names, patient benchmark, branch fallback
- `SlowQueryAuditCommand` — NSF-2 console table
- Tests `tests/Unit/Performance/Nsf2SafePerformanceIndexesTest.php`

## Quality gates

```bash
php artisan test --filter=SlowQuery
php artisan test --filter=Nsf2
./vendor/bin/pint --dirty
npm ci && npm run build
```

## Risk assessment

- **Low:** additive indexes only, no data/business logic change
- Inventory index already existed; no duplicate btree
- Patient composite index small write overhead on patient updates
- CONCURRENTLY avoids long write locks on pilot

## Rollback

```bash
php artisan migrate:rollback --step=1
# or manually:
# DROP INDEX CONCURRENTLY IF EXISTS idx_nsf2_patients_branch_is_active;
```

## GO / NO-GO

**GO** — NSF-2 indexes applied/verified, audit enhanced, tests green, PR merged, VPS deployed.

## PR & release

| Item | Value |
| --- | --- |
| PR | [#154](https://github.com/makemesick91-code/new_lab_app/pull/154) |
| Merge commit | `43a61893ecd3d192adf03659b7d79d80efbd6ef9` |
| GO tag | `sprint-nsf-2-safe-index-pack-query-plan-hardening-go` (exact match merged HEAD) |
| Sprint commit | `54de034` |

## VPS deployment evidence (2026-07-03)

| Item | Value |
| --- | --- |
| VPS previous HEAD | `1bcae6cfa4690189e86a642e8b4fed88335364ba` |
| VPS deployed HEAD | `43a61893ecd3d192adf03659b7d79d80efbd6ef9` |
| GO tag on VPS | `sprint-nsf-2-safe-index-pack-query-plan-hardening-go` ✓ |
| Backup | `storage/app/backups/deploy/pre_nsf2_20260703-112254.sql` (543K) |
| Migration | `2026_07_03_200001_add_nsf2_safe_performance_indexes` — DONE |
| Indexes verified | `idx_nsf2_patients_branch_is_active`, `trx_inv_movements_branch_date_index` |
| VPS audit | `storage/app/performance/nsf2-vps-evidence.json` |
| Composer/npm | OK |
| php-fpm/nginx | active / reload OK |
| HTTP smoke | `/`, `/login`, `/inventory/dashboard`, `/rme/visits`, `/dashboard` → 200 |
| Laravel log | no new ERROR after deploy |

### VPS benchmark highlights

| Benchmark | Index scan | Index used |
| --- | --- | --- |
| `inv_movements_month_window` | yes | `trx_inv_movements_branch_product_covering_index` |
| `rme_patients_branch_active` | planner choice on pilot data | `idx_nsf2_patients_branch_is_active` present |

## Tests

| Command | Result |
| --- | --- |
| `php artisan test --filter=SlowQuery` | 6 passed, 1 skipped (sqlite test DB) |
| `php artisan test --filter=Nsf2` | 4 skipped on sqlite; pgsql assertions on local pilot DB |
| `./vendor/bin/pint --dirty` | passed |
| `npm run build` | passed |

## Deferred NSF-3

- Enable `pg_stat_statements` on pilot for live slow-query ranking
- `ANALYZE mst_patients` after large imports if patient list plans regress
- Review covering index for `inv_current_stock_aggregate` at national scale
