# Sprint NSF-1 — Performance Baseline & Slow Query Audit

## Pre-flight

| Item | Value |
| --- | --- |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Sprint branch | `feature/sprint-nsf-1-performance-baseline-slow-query-audit` |
| Base HEAD (start) | `cd103709eba2e4ca0c7ce0c706e3bb7eb399a2ab` |
| Laravel | 12.61.0 |
| PHP (local) | 8.5.4 |
| DB driver | pgsql |
| APP_ENV (local) | local |

## System map (Graphify + route verification)

### RME / Cashier

| Route area | Controller / Service |
| --- | --- |
| `rme.dashboard`, `rme.patient-queue`, `rme.visits.*` | `ClinicVisitController`, `ClinicVisitService`, `ClinicVisitRepository` |
| `rme.visits.medical-record.*` | `MedicalRecordController`, `MedicalRecordService` |
| `rme.odontograms.*` | `OdontogramController`, `OdontogramService` |
| Cashier billing / payments | `RmePaymentController`, `RmePaymentService`, `RmeInvoiceService` |
| Receivables | `RmeReceivableController`, receivable queries on `trx_rme_invoices` |

**Query patterns:** `branch_id` filters on visits/invoices/payments; visit lists by `visit_date` + `status`; RM lookup via `visit_number` / `medical_record_number`; receivable aging on `status IN (UNPAID, PARTIAL)` + `created_at`.

### Inventory

| Route area | Controller / Service |
| --- | --- |
| `inventory.dashboard` | `InventoryDashboardController`, `InventoryDashboardService` |
| `inventory.reports.*` | `InventoryReportController`, `InventoryReportService` |
| Stock transfer / opname / GR | `StockTransferController`, `StockOpnameController`, `GoodsReceiptService` |
| Batch / expiry | `InventoryBatchController`, `BatchExpiryStatusService` |

**Query patterns:** ledger `SUM(quantity_in)-SUM(quantity_out)` on `trx_inventory_movements`; branch + product/location/batch filters; mutation date windows on `movement_date`.

### Owner / Reporting

| Route area | Controller / Service |
| --- | --- |
| `dashboard` (Owner KPI) | `HomeDashboardController`, `OwnerDashboardKpiService` |
| `reporting.dashboard` | `ReportingDashboardController` |

**Query patterns:** period-based visit/payment aggregates; branch-scoped `whereIn(branch_id)`; receivable snapshots.

## Deliverables

### Artisan command

```bash
php artisan performance:slow-query-audit
php artisan performance:slow-query-audit --json
php artisan performance:slow-query-audit --module=inventory
php artisan performance:slow-query-audit --skip-benchmarks
php artisan performance:slow-query-audit --output=nsf1-audit.json
php artisan performance:slow-query-audit --fail-on-watch
```

- Read-only, privacy-safe (no patient names, KTP, diagnosis, handwriting).
- Reports under `storage/app/performance/` when `--output` is set.
- EXPLAIN ANALYZE benchmarks for RME, cashier, inventory, owner query shapes.
- Index inventory for known performance indexes.
- Deferred index recommendations documented (no migration in NSF-1).

### Files added

- `app/Services/Monitoring/SlowQueryAuditService.php`
- `app/Console/Commands/SlowQueryAuditCommand.php`
- `tests/Unit/Console/SlowQueryAuditCommandTest.php`

## Index decision (NSF-1)

**No new migration added.**

Existing indexes verified in audit:

- `trx_clinic_visits_branch_date_status_index`
- `trx_clinic_visits_visit_number_pattern_idx`
- `trx_rme_invoices_active_receivable_order_idx`
- `trx_rme_payments_branch_paid_at_idx`
- `trx_inventory_movements_branch_location_product_index`

**Deferred to NSF-2+:**

- `(branch_id, movement_date)` on `trx_inventory_movements` for mutation/stock-card scans at scale
- `(branch_id, is_active)` on `mst_patients` if branch patient lists become hot

## Local evidence

```bash
php artisan performance:slow-query-audit --json --output=nsf1-local-evidence.json
```

| Item | Result |
| --- | --- |
| Timestamp | 2026-07-03 |
| Command | `performance:slow-query-audit` |
| Local DB | Unavailable during agent run (connection refused); command degrades gracefully with warnings |
| Tests | `php artisan test --filter=SlowQuery` — 6 passed, 1 skipped |
| Pint | passed (`--dirty`) |

## VPS runbook

```bash
cd /var/www/asia-dental-lab-v2
# after deploy + migrate
php artisan performance:slow-query-audit --json --output=nsf1-vps-evidence.json
```

Safe on pilot; production requires `--force-production`.

## Quality gates

```bash
php artisan test --filter=SlowQuery
php artisan test --filter=PilotPerformanceSnapshot
./vendor/bin/pint --dirty
npm ci && npm run build
php artisan route:list | grep performance
```

## Risk assessment

| Risk | Mitigation |
| --- | --- |
| EXPLAIN ANALYZE load | 5s statement_timeout per benchmark; read-only |
| PII leakage | Aggregates only; privacy flags in report; tests |
| Speculative indexes | Document-only deferrals |

## GO decision

**GO** — tooling is additive, read-only, tested, and complements existing `pilot:performance-snapshot`.

---

## PR / Deploy evidence

| Item | Value |
| --- | --- |
| PR | [#153](https://github.com/makemesick91-code/new_lab_app/pull/153) |
| Merge commit | `1bcae6cfa4690189e86a642e8b4fed88335364ba` |
| GO tag | `sprint-nsf-1-performance-baseline-slow-query-audit-go` |
| Local HEAD | `1bcae6cfa4690189e86a642e8b4fed88335364ba` |
| VPS previous HEAD | `cd103709eba2e4ca0c7ce0c706e3bb7eb399a2ab` |
| VPS deployed HEAD | `1bcae6cfa4690189e86a642e8b4fed88335364ba` |
| GO tag match on VPS | yes (`git describe --tags --exact-match HEAD`) |
| Backup path | `storage/app/backups/deploy/pre_nsf1_20260703-104106.sql` |
| Backup size | 543K |
| Composer | OK (`--no-dev --optimize-autoloader`) |
| npm build | OK (`app-DdSm4puC.css`, `app-JStlj-rZ.js`) |
| Migration | Nothing to migrate |
| php-fpm/nginx | active / reload OK |
| HTTP login smoke | 200 |
| VPS audit command | OK — 8 benchmarks, all OK status, 0 warnings |
| VPS evidence file | `storage/app/performance/nsf1-vps-evidence.json` (9.8K) |

### VPS benchmark hotspots (pilot dataset, small)

| Benchmark | ms | Seq scan |
| --- | --- | --- |
| rme_active_visits | 0.016 | yes (small table) |
| rme_medical_records_join | 0.049 | yes |
| cashier_active_receivables | 0.02 | yes |
| cashier_payment_sum_ytd | 0.024 | yes |
| inv_current_stock_aggregate | 0.023 | no |
| inv_movements_month_window | 0.015 | no |
| owner_visits_month | 0.018 | yes |
| owner_unpaid_invoices | 0.017 | yes |

Seq scans acceptable at pilot volume; monitor at national scale.

### Deferred NSF-2+

1. `trx_inventory_movements_branch_movement_date_idx` — mutation/stock-card date scans
2. `mst_patients_branch_active_idx` — branch patient list scaling

## Final GO

**GO** — merged, tagged, deployed, smoke green.
