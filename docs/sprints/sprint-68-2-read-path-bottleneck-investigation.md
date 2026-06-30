# Sprint 68.2 — Read Path Bottleneck Investigation

## Scope

* Investigation-first.
* Read paths only.
* No business rule changes.

## Environment

* Branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
* Commit (HEAD at start): `ab4fae1` (Sprint 68.1 write-bottleneck merge)
* Laravel: 12
* PHP: 8.x
* DB: PostgreSQL (app/pilot); SQLite `:memory:` for the test suite
* Date: 2026-07-01

## Candidate Routes Reviewed

| Area | Route/Page | Controller | Service/Repository | Risk | Finding |
| ---- | ---------- | ---------- | ------------------ | ---- | ------- |
| RME | `rme.reports.payments` (+ export/print) | `RmeReportController@payments` | `paymentReportQuery()` → `RmePayment` | Med | Eager-loaded; **no index on `paid_at`** for branch+range sort/sum → **F1 (fixed)** |
| RME | `rme.reports.patients` (+ export/print) | `RmeReportController@patients` | `patientReportQuery()` → `ClinicVisit` | Low | Already eager-loads `patient/branch/doctor`; covered by `(branch_id, visit_date, status)` index. OK |
| Dashboard | `dashboard` (Owner KPI) | `HomeDashboardController` | `OwnerDashboardKpiService` | Med | Per-branch revenue/trend scan `RmePayment` by `branch_id`+`paid_at`→ **F1 (fixed)**; per-branch loop counts → **F2 (deferred)** |
| RME | `rme.patient-queue.index` / worklist | `ClinicVisitController` | `ClinicVisitRepository@queueForBranches/worklistForBranches` | Low | Paginated + eager-loaded; covered by `(branch_id, visit_date, status)`. OK |
| RME | RM workspace / detail | `MedicalRecordController@show` | `PatientRmWorkspaceResolver` | Low | Bounded per-patient `whereHas('medicalRecord')`; small row sets. OK |
| Inventory | stock/mutation/valuation reports | `Inventory*Controller` | `InventoryMovementRepository` | Med | Heavy `->get()` analytics but paginated per report; **F3 (deferred — needs query-plan evidence)** |
| Lab | candidate queue / lab orders | `LabCaseCandidateController` | repos | Low | `(branch_id, status)` + FK indexes already present. OK |

## Findings

### Finding 1 — `trx_rme_payments` missing index for branch + period aggregation

* Area: RME payment report + Owner KPI dashboard
* Files:
  * `app/Modules/Reporting/Services/OwnerDashboardKpiService.php` (`paymentQuery()`, `dailyPaymentTrend()`, per-branch `revenue`)
  * `app/Modules/RmeInvoice/Controllers/RmeReportController.php` (`paymentReportQuery()`, `payments()`/export/print)
* Symptoms: Owner dashboard (loaded on Owner login) computes, per branch, `whereIn('branch_id') + whereBetween('paid_at') + sum('amount')` and a daily trend `get(['paid_at','amount'])`. The payment report orders `latest('paid_at')` under a branch filter.
* Root cause: `trx_rme_payments` had indexes on `(branch_id, rme_invoice_id)`, `clinic_visit_id`, `patient_id`, `payment_batch_uuid`, and `rme_invoice_id` — but **none leading on `paid_at`**. A branch-scoped date-range scan therefore filters rows without an ordered/range-capable index, and the `SUM(amount)` cannot be served index-only.
* Evidence (query shapes, verified in source):
  * `OwnerDashboardKpiService.php`: `$this->paymentQuery([$branch->id])->whereBetween('paid_at', [$from, $to])->sum('amount')`
  * `OwnerDashboardKpiService.php` `dailyPaymentTrend`: `paymentQuery($branchIds)->whereBetween('paid_at', [...])->get(['paid_at','amount'])`
  * `RmeReportController.php` `paymentReportQuery`: `RmePayment::query()->where('branch_id', $branchId)...` then `->latest('paid_at')->limit(100)`
* Risk: Very low. Pure additive btree index, `CONCURRENTLY`, guarded to `pgsql` (no-op on SQLite test DB). No column/data/behaviour change.
* Recommendation: Add `trx_rme_payments (branch_id, paid_at DESC) INCLUDE (amount)`.
* Implemented in this sprint: **yes** — `database/migrations/2026_07_01_680200_add_read_path_indexes_for_rme_payments.php`. Verified valid on local Postgres (`pg_index.indisvalid = true`).

### Finding 2 — Owner KPI per-branch metrics issue N queries per branch (loop aggregation)

* Area: Owner KPI dashboard
* Files: `app/Modules/Reporting/Services/OwnerDashboardKpiService.php` (`perBranchMetrics()`)
* Symptoms: For each active branch the service runs ~5 separate queries (visits count, new-patients count, revenue sum, receivable, and a `whereHas('latestFollowUp')` follow-up count). Total queries scale as `5 × branchCount`.
* Root cause: Per-branch loop instead of a single grouped aggregate (`GROUP BY branch_id`).
* Evidence: `perBranchMetrics()` `->map(fn ($branch) => [...])` with one query block per branch.
* Risk to fix: Medium — rewriting to grouped aggregates changes query construction and must preserve the exact per-branch shape, the `latestFollowUp` semantics, and branch-scope. Not a clear "behaviour-identical" one-liner.
* Recommendation: Defer. The pilot branch count is small (single-digit), so absolute cost is currently bounded; Finding 1's index already accelerates the per-branch `revenue` sum. Revisit only if branch count grows or query-log evidence shows it dominant.
* Implemented in this sprint: **no** (deferred to 68.3 with measurement).

### Finding 3 — Inventory analytics reports rely on broad `->get()` over movement ledger

* Area: Inventory current-stock / mutation / valuation / stock-card reports
* Files: `app/Modules/Inventory/Repositories/InventoryMovementRepository.php`, `InventoryAnalyticsRepository.php`, `InventorySummaryAnalyticsRepository.php`
* Symptoms: Many `->get()` / `->all()` calls computing stock from the movement ledger; the page-level lists are paginated, but the underlying aggregates can scan large movement sets.
* Root cause: Stock is derived from `trx_inventory_movements` rather than a mutable stock column (by design — must not add mutable stock columns). Aggregation cost grows with movement volume.
* Evidence: 20+ `->get()` in `InventoryMovementRepository.php`; analytics repos use `->get()`/`->all()` for valuation/supplier maps.
* Risk: Medium — safe optimization needs `EXPLAIN ANALYZE` on a representative Postgres dataset to identify the specific missing composite index (likely on `(product_id, location_id, created_at)` and/or `(reference_type, reference_id)`); inventing indexes without plan evidence is out of scope for this sprint.
* Recommendation: Defer to a measured 68.3 inventory pass with `EXPLAIN ANALYZE` on the pilot DB.
* Implemented in this sprint: **no**.

## Query / Index Notes

| Table | Existing relevant index | Query pattern | Recommendation | Implemented |
| ----- | ----------------------- | ------------- | -------------- | ----------- |
| `trx_rme_payments` | `(branch_id, rme_invoice_id)`, `clinic_visit_id`, `patient_id`, `payment_batch_uuid`, `rme_invoice_id` | `branch_id IN/=` + `paid_at BETWEEN` + `SUM(amount)` / `ORDER BY paid_at DESC` | Add `(branch_id, paid_at DESC) INCLUDE (amount)` | **Yes** |
| `trx_clinic_visits` | `(branch_id, visit_date, status)`, `patient_id`, `doctor_id`, `clinic_room_id`, `visit_number varchar_pattern_ops` (Sprint 68.1) | report/queue `branch_id + visit_date + status` | None — already covered | n/a |
| `trx_rme_invoices` | `(branch_id, status, created_at DESC, id DESC) INCLUDE (...)` partial UNPAID/PARTIAL (Sprint 68.1) | active receivables | None — already covered | n/a |
| `trx_inventory_movements` | (to be confirmed) | stock/valuation aggregation | Needs `EXPLAIN ANALYZE` before proposing | No (F3) |

## Changes Made

* `database/migrations/2026_07_01_680200_add_read_path_indexes_for_rme_payments.php` — additive Postgres index `trx_rme_payments_branch_paid_at_idx` on `(branch_id, paid_at DESC) INCLUDE (amount)`, `CONCURRENTLY`, `pgsql`-guarded (no-op on SQLite). No app/controller/service/view/business-logic change.

## Tests / Commands

```bash
git branch --show-current            # feature/sprint-26-phase-26-8-...
php artisan migrate:status           # new migration shows Ran on local pgsql
# index validity confirmed: pg_index.indisvalid = true, def = (branch_id, paid_at DESC) INCLUDE (amount)
./vendor/bin/pint --dirty            # passed
git diff --check                     # clean
php artisan test --filter=OwnerKpiDashboardTest   # 12 passed
php artisan test --filter="Report|RmePayment"     # 296 passed (985 assertions)
```

Skipped: full `php artisan test` and inventory/lab suites — the only change is a `pgsql`-guarded additive index that is a no-op on the SQLite test DB, so behaviour cannot change; the report/payment/dashboard suites that exercise the affected read paths were run and pass.

JS/Blade: no front-end change → `npm run build` not run.

## Risks / Deferred Items

* F1 index: minimal risk; on the pilot VPS apply with `php artisan migrate --force` (never `migrate:fresh`/`db:wipe`). `CONCURRENTLY` avoids write-locking the table.
* F2 (Owner per-branch loop) and F3 (inventory ledger aggregation) deferred — need query-log / `EXPLAIN ANALYZE` evidence before a safe change.

## Sprint 68.3 Recommendations

1. Capture `EXPLAIN (ANALYZE, BUFFERS)` on the pilot DB for: Owner KPI payment sum/trend (validate F1 index is chosen), payment report, and inventory current-stock/valuation queries.
2. Evaluate collapsing `OwnerDashboardKpiService::perBranchMetrics()` into single grouped aggregates (F2) if branch count or query volume grows.
3. Inventory ledger composite index pass (F3), driven by plan evidence — likely `(product_id, location_id, created_at)` / `(reference_type, reference_id)`.
4. Consider a standalone `trx_rme_payments (paid_at DESC)` index only if the cross-branch (no branch filter) payment report sort shows up as slow.

## Confirmation

No commit, push, tag, PR, or deploy was performed. Only the migration file and this report were added to the working tree.
