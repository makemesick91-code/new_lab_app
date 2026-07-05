# DaengtisiaMS — Reporting Summary Candidate Inventory (ENT-3)

## Status

- Sprint: **ENT-3 — Reporting Materialized Summary Expansion**
- Status: governance baseline — **no physical summary object added by this document**
- Date: 2026-07-06
- Contract: `docs/architecture/reporting-materialized-summary-contract.md` (RPTSUM-R001..R016)
- Database baseline: `docs/architecture/database-performance-hotspot-inventory.md` (ENT-2)
- Shipped RPT-1 physical summaries (verified from migration
  `2026_06_10_100000_create_rpt_inventory_analytics_summary_tables.php`):
  `rpt_inventory_daily_summaries`, `rpt_inventory_branch_summaries`,
  `rpt_inventory_product_summaries`, `rpt_procurement_daily_summaries`.

Legend — status: **verified** = source tables/services confirmed from repo source;
**partial** = domain confirmed, some sources not yet audited; **TODO** = candidate
named, sources not yet verified — never invent table names for TODO entries.
Strategy values: raw query acceptable / rpt summary / materialized summary / cache /
queued export.

**Decision (ENT-3):** every entry below is a *candidate*. No new `rpt_*` table or
materialized view ships in ENT-3 — physical implementation is deferred to dedicated
report-summary sprints per RPTSUM-R016.

---

## 1. Owner dashboard KPI

- Domain/module: Reporting (`App\Modules\Reporting\Services\OwnerDashboardKpiService`)
- Source tables (verified): `trx_clinic_visits`, `trx_rme_invoices`, `trx_rme_payments`; inventory figures via `InventoryAnalyticsRepositoryInterface`
- Branch scope: per-branch filter + permission-gated cross-branch (`view_owner_dashboard`)
- Date filter: period `today|7d|month|30d|custom`
- Grouping: branch, day (visit/payment trends), KPI card aggregates
- Freshness target: near-real-time (page-load acceptable at pilot volume)
- Expected strategy: raw query acceptable now → **rpt summary + cache** as volume grows
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-4 CACHE, ENT-7/8 OBSERVABILITY

## 2. RME patient report (completeness audit / RM gap review)

- Domain/module: RME / Patient (Sprint 61.0 audit page `GET /rme/patients/audit`)
- Source tables (verified): `mst_patients`, `trx_clinic_visits`, `trx_medical_records`
- Branch scope: active RME-enabled branches only, MAIN excluded
- Date filter: registration/visit date range
- Grouping: branch, completeness classification, duplicate-risk flag
- Freshness target: daily acceptable
- Expected strategy: raw query acceptable now → **queued export** for large CSV; KTP always masked (RPTSUM-R010)
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-5 QUEUE (export)

## 3. RME payment report

- Domain/module: RME Invoice / Cashier
- Source tables (verified): `trx_rme_payments`, `trx_rme_invoices`, `trx_clinic_visits`
- Branch scope: branch-scoped cashier view; cross-branch read-only for owner analytics
- Date filter: payment date range
- Grouping: branch, day, payment method, payment_batch_uuid (carry-over batches)
- Freshness target: near-real-time
- Expected strategy: raw query acceptable now → **rpt summary** (daily payment rollup) as volume grows
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-4 CACHE

## 4. RME receivable aging / export (piutang)

- Domain/module: RME Invoice (`RmeControlReceivableService`, per-invoice remaining)
- Source tables (verified): `trx_rme_invoices`, `trx_rme_payments`
- Branch scope: RME branch set; invoice remaining is per-invoice, counted once, branch-attributed
- Date filter: visit date / aging buckets
- Grouping: patient, branch, aging bucket (invoice remaining — never visit status)
- Freshness target: near-real-time (cashier decisions read the transactional path, not the summary — RPTSUM-R006)
- Expected strategy: raw query acceptable now → **rpt summary + queued export** for aging report
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-5 QUEUE (export), ENT-7/8 OBSERVABILITY

## 5. Inventory current stock

- Domain/module: Inventory (ledger-derived)
- Source tables (verified): `trx_inventory_movements` (ledger; RPTSUM-R008 — summaries may accelerate, never mutate)
- Branch scope: branch-scoped; cross-branch comparison permission-gated
- Date filter: as-of-date (ledger cutoff)
- Grouping: product, branch, batch
- Freshness target: near-real-time
- Expected strategy: **rpt summary** (RPT-1 `rpt_inventory_*` tables already exist for analytics; live stock stays ledger-derived)
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-4 CACHE

## 6. Inventory stock card

- Domain/module: Inventory
- Source tables (verified): `trx_inventory_movements`
- Branch scope: branch-scoped
- Date filter: movement date range
- Grouping: product, movement type, reference
- Freshness target: real-time (audit trail — read the ledger directly)
- Expected strategy: **raw query acceptable** (paginated ledger read; summary not appropriate for an audit trail)
- Status: verified
- ENT dependency: ENT-2 DBPERF

## 7. Inventory valuation

- Domain/module: Inventory analytics (`InventoryAnalyticsSummaryRefreshService`, `InventorySummaryAnalyticsRepository`)
- Source tables (verified): `trx_inventory_movements` → `rpt_inventory_daily_summaries` / `rpt_inventory_branch_summaries` / `rpt_inventory_product_summaries`
- Branch scope: branch-scoped + permission-gated comparison
- Date filter: daily buckets
- Grouping: branch, product, day
- Freshness target: daily (RPT-1: manual/dry-run refresh only; no auto schedule)
- Expected strategy: **rpt summary** (shipped in RPT-1; runtime source switch still flag-gated)
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-5 QUEUE (future scheduled refresh), ENT-7/8 OBSERVABILITY

## 8. Inventory low-stock / expiry alerts

- Domain/module: Inventory
- Source tables (partial): `trx_inventory_movements` + batch/expiry data (`trx_stock_*`, batch tables — exact alert query sources not yet audited)
- Branch scope: branch-scoped
- Date filter: expiry window / threshold evaluation date
- Grouping: product, branch, batch expiry bucket
- Freshness target: hourly acceptable
- Expected strategy: **cache** over ledger-derived thresholds → queued alert candidate generation later (no auto-send)
- Status: partial
- ENT dependency: ENT-2 DBPERF, ENT-4 CACHE, ENT-5 QUEUE

## 9. Lab order / candidate reporting

- Domain/module: LabOrder (incl. RME → Lab candidates)
- Source tables (verified): `trx_lab_orders`, `trx_lab_order_items`, `trx_lab_case_candidates`
- Branch scope: branch-scoped (`LabCaseCandidatePolicy` branch isolation)
- Date filter: order/candidate created date range
- Grouping: branch, status, lab service, candidate conversion state
- Freshness target: near-real-time
- Expected strategy: raw query acceptable now → **rpt summary** (daily order/conversion rollup) as volume grows
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-7/8 OBSERVABILITY

## 10. Branch-level analytics

- Domain/module: Reporting / Inventory branch comparison (`InventoryBranchComparisonService`)
- Source tables (verified): `rpt_inventory_branch_summaries` + transactional sources above
- Branch scope: **read-only, permission-gated cross-branch** (RPTSUM-R009)
- Date filter: period buckets
- Grouping: branch, domain KPI
- Freshness target: daily
- Expected strategy: **rpt summary + cache**
- Status: verified
- ENT dependency: ENT-2 DBPERF, ENT-4 CACHE

## 11. Future national executive analytics

- Domain/module: future national/executive tier (multi-clinic rollup)
- Source tables: TODO — depends on national data architecture (NDA-1); do not invent
- Branch scope: cross-branch/cross-clinic, permission-gated, read-only, PII-free
- Date filter: period buckets
- Grouping: clinic, region, branch
- Freshness target: daily / manual rebuild
- Expected strategy: **materialized summary + queued export** (candidate only)
- Status: TODO
- ENT dependency: ENT-2 DBPERF, ENT-4 CACHE, ENT-5 QUEUE, ENT-7/8 OBSERVABILITY

---

## Audit backlog

- Audit exact alert query sources for candidate 8 (low-stock/expiry) before any summary work.
- National executive analytics (candidate 11) stays TODO until the national data architecture sprint defines its sources.
- Any promotion from candidate to physical `rpt_*` object requires a dedicated sprint satisfying RPTSUM-R016.
