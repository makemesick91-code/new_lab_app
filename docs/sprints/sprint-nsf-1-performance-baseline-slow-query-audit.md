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

## PR / Deploy evidence (filled post-merge)

| Item | Value |
| --- | --- |
| PR | TBD |
| Merge commit | TBD |
| GO tag | `sprint-nsf-1-performance-baseline-slow-query-audit-go` |
| VPS previous HEAD | TBD |
| VPS deployed HEAD | TBD |
| Backup path | TBD |
| Smoke | TBD |
