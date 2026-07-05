# DaengtisiaMS — Database Performance Hotspot Inventory (ENT-2)

## Status

- Sprint: **ENT-2 — Database Performance Contract**
- Status: governance baseline — **no business logic changed by this document**
- Date: 2026-07-06
- Contract: `docs/architecture/database-performance-contract.md` (DBPERF-R001..R014)
- Purpose: living inventory of the known query hotspot domains, their expected
  filter/sort/branch/pagination patterns, and the index verification status.
  Entries marked **TODO** mean the exact index/query match has not been verified
  against migrations + code yet — they are audit backlog, **not** an instruction
  to add indexes speculatively (DBPERF-R008).

Verification legend:

- **verified** — index existence confirmed by reading the migration source in this repo.
- **TODO** — pattern known, exact index/query match not yet audited; capture EXPLAIN
  evidence before optimizing (DBPERF-R012).

---

## 1. RME visit list / Antrian Pasien

- Table/domain: `trx_clinic_visits` (RME visits, patient queue `rme.patient-queue.index`)
- Likely filters: `branch_id` (RME branch set), `status` (non-terminal set), `visit_date`, `clinic_room_id` (room assigned / not), search on patient
- Likely sort: `visit_date`, `id` (chronological)
- Branch scope: **required** (RME-enabled branch set, MAIN excluded)
- Pagination: **required** (queue and visit list)
- Summary/cache candidate: no (operational live queue)
- Index verification: **verified** — `trx_clinic_visits_branch_date_status_index` (`branch_id, visit_date, status`) plus `patient_id`, `doctor_id`, `clinic_room_id` indexes in `2026_06_09_000001_create_trx_clinic_visits_table`

## 2. RME patient search / RM lookup

- Table/domain: `mst_patients` (search by name/RM/phone, completeness audit, legacy import dedup)
- Likely filters: `name` (prefix/like), medical-record number, `is_active`, KTP collision check (masked output only)
- Likely sort: `name`, recent registration
- Branch scope: **required** for branch-scoped listings; cross-branch audit is read-only + permission-gated (DBPERF-R003)
- Pagination: **required**
- Summary/cache candidate: no
- Index verification: partially **verified** — `name`, `is_active`, `clinic_id`, `doctor_id` indexes in `2026_06_03_030003_create_mst_patients_table`; RM-number and KTP unique/lookup index audit: **TODO**

## 3. RME cashier invoices / receivables (piutang)

- Table/domain: `trx_rme_invoices`, `trx_rme_payments` (cashier billing, receivable list, carry-over)
- Likely filters: `branch_id`, `status` (`UNPAID`/`PARTIAL`), `patient_id`, `clinic_visit_id`, remaining > 0
- Likely sort: `visit_date, id` (oldest-first FIFO for carry-over), latest payments
- Branch scope: **required** (RME branch set)
- Pagination: **required** for lists; payment allocation runs bounded per patient
- Summary/cache candidate: receivable aging summary is an ENT-3 `rpt_*` candidate
- Index verification: **verified** — `trx_rme_invoices_branch_status_index` (`branch_id, status`) plus `clinic_visit_id`, `patient_id` in `2026_06_14_200001_create_trx_rme_invoices_table`; `trx_rme_payments_rme_invoice_id_index` added `CONCURRENTLY` in `2026_06_30_153729_add_rme_receivables_performance_indexes`

## 4. Owner dashboard KPI

- Table/domain: aggregates over `trx_clinic_visits`, `trx_rme_invoices`, `trx_rme_payments`, inventory analytics (`OwnerDashboardKpiService`)
- Likely filters: period (`today|7d|month|30d|custom`), optional `branch_id`
- Likely sort: daily trend by date; per-branch grouping
- Branch scope: cross-branch by design — **read-only + `view_owner_dashboard` gated** (DBPERF-R003)
- Pagination: n/a (bounded aggregates); latest-receivables list is limited
- Summary/cache candidate: **yes — primary ENT-3/ENT-4 target** (materialized daily KPI summaries + cached aggregates per DBPERF-R004)
- Index verification: **TODO** — payment/visit date-range aggregate plans not yet EXPLAIN-audited under volume

## 5. Inventory current stock / stock card / valuation / alerts

- Table/domain: inventory ledger (`trx_inventory_movements`, `inv_inventory_batches`, `inv_products` masters)
- Likely filters: `branch_id`, product, location, movement date range, `is_active`
- Likely sort: movement date (stock card), product name
- Branch scope: **required** (branch ledger isolation)
- Pagination: **required** (stock card, product lists)
- Summary/cache candidate: **yes** — current-stock/valuation snapshots are summary candidates; stock stays ledger-derived (DBPERF-R011)
- Index verification: partially **verified** — master `inv_*` branch/active composite indexes in `2026_06_04_120000_create_inventory_core_tables` (e.g. `inv_products_branch_active_index`); movement-ledger aggregation index audit (`trx_inventory_movements` by branch/product/date): **TODO**

## 6. Lab orders / lab case candidates

- Table/domain: `trx_lab_orders`, `trx_lab_case_candidates`
- Likely filters: `branch_id`/`clinic_id`, `status`, `priority`, `order_date`/`due_date`, patient/doctor
- Likely sort: `order_date`, `due_date`, newest-first queue
- Branch scope: **required** (candidate queue is branch-isolated via policy)
- Pagination: **required**
- Summary/cache candidate: no (operational queues)
- Index verification: **verified** — `trx_lab_orders` has `clinic_id`, `doctor_id`, `patient_id`, `status`, `priority`, `order_date`, `due_date` indexes (`2026_06_03_040001_create_trx_lab_orders_table`); `trx_lab_case_candidates` has (`branch_id, status`) + unique `rme_invoice_item_id` (`2026_06_14_210001_create_trx_lab_case_candidates_table`)

## 7. Reports / export

- Table/domain: report pages and CSV/PDF exports over `trx_*` transaction tables and `rpt_*` summaries
- Likely filters: branch, date range, status/category
- Likely sort: date, grouping keys
- Branch scope: **required** for branch reports; cross-branch consolidated reports read-only + permission-gated
- Pagination: **required** on screen; exports must be bounded/chunked (no unbounded `->get()` per DBPERF-R005) and PII-masked (DBPERF-R010)
- Summary/cache candidate: **yes — ENT-3 scope** (route heavy reports to `rpt_*`/materialized summaries per DBPERF-R004)
- Index verification: **TODO** — per-report query/index audit deferred to ENT-3 with EXPLAIN evidence

---

## Audit backlog (do not fix speculatively)

1. `mst_patients` RM-number / KTP lookup index audit (masked evidence only).
2. `trx_inventory_movements` branch/product/date aggregation EXPLAIN audit.
3. Owner dashboard KPI date-range aggregate EXPLAIN audit under pilot volume.
4. Per-report/export query audit — executes inside ENT-3 with the summary expansion.

No new index migration ships from this inventory until the matching query pattern is
verified from code + EXPLAIN evidence (DBPERF-R008/R009/R012).
