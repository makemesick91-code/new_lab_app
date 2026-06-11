# Owner Dashboard — RME/Lab Pilot KPI Notes

Sprint 22 Phase 22.5 developer reference for read-only Owner Dashboard monitoring.

## Purpose

Give the Owner role a lightweight, read-only view of clinic pilot progress across the RME → cashier → lab candidate funnel without mutating operational data.

## Service

`App\Modules\Reporting\Services\OwnerDashboardRmeLabKpiService`

Loaded only from `HomeDashboardController` when the authenticated user qualifies for the Owner dashboard shell (has `view_owner_dashboard` or legacy `manage_report`, and does **not** have branch-operational dashboard permissions).

## KPI definitions

| KPI | Source | Rule |
|-----|--------|------|
| Kunjungan RME Hari Ini | `trx_clinic_visits` | `visit_date = today`, status ≠ `cancelled` |
| Menunggu Kasir | `trx_clinic_visits` | `status = cashier_pending` (snapshot) |
| RM Draft | `trx_medical_records` | `status = draft` |
| RM Final Hari Ini | `trx_medical_records` | `status = final` and `finalized_at` today |
| Invoice RME Belum Dibayar | `trx_rme_invoices` | `status IN (DRAFT, UNPAID)` |
| Pembayaran RME Hari Ini | `trx_rme_invoices` + `trx_rme_payments` | invoice `PAID` with payment `paid_at` today |
| Pendapatan dibayar hari ini | `trx_rme_payments` | `SUM(amount)` where `paid_at` today |
| Kandidat Lab Pending | `trx_lab_case_candidates` | `status = pending_review` |
| Kandidat Dikonversi Hari Ini | `trx_lab_case_candidates` | `status = converted_to_lab_order` and `reviewed_at` today |
| Lab Order dari RME Hari Ini | `trx_lab_orders` | `created_at` today and linked via `converted_lab_order_id` |

Funnel stages reuse the same counts for a compact RME → kasir → bayar → kandidat → lab order visualization.

## Branch / Owner aggregation

- **Owner dashboard (global default):** no `branch_id` query param → `metrics(null)` scopes to **all active branches** (`mst_branches.is_active = true`). Filter UI shows **Semua Cabang**.
- **Owner dashboard (selected branch):** `?branch_id=<id>` when id is an **active** branch → `metrics($branchId)` scopes KPI cards, funnel, attention panel, and branch summary row set to that branch.
- **Invalid `branch_id`:** ignored silently; dashboard falls back to all active branches (no error, no inactive/deleted branch data).
- **Branch admin dashboard:** unchanged; RME/Lab pilot section, branch filter, and **Ringkasan Per Cabang** are **not** rendered for operational branch users.

## Branch summary (Ringkasan Per Cabang)

`OwnerDashboardRmeLabKpiService::branchSummary()` returns one row per active branch (or single row when branch filter applied) with:

| Column | Metric |
|--------|--------|
| Kunjungan Hari Ini | visits today (non-cancelled) |
| Menunggu Kasir | `cashier_pending` snapshot |
| Invoice Belum Dibayar | DRAFT/UNPAID invoices |
| Kandidat Lab Pending | `pending_review` candidates |
| Dikonversi Hari Ini | converted today (`reviewed_at`) |
| Status Perhatian | `Perlu cek kasir` → `Perlu cek kandidat lab` → `Banyak RM draft` → `Belum ada data hari ini` → `Aman` |

Pilot scale: all active branches render in one table (no pagination). Review if branch count grows beyond pilot.

## Drilldown links

`OwnerDashboardRmeLabDrilldownService::linksFor($user)` builds **read-only index** URLs only when the user has permission:

| KPI key | Route (when permitted) | Permission |
|---------|------------------------|------------|
| `visits_today` | `rme.visits.index?visit_date=today` | `view_clinic_visits` / `manage_clinic_visits` |
| `visits_cashier_pending` | `rme.visits.index?status=cashier_pending` | same |
| `medical_records_draft` | `rme.medical-records.index?status=draft` | same |
| `medical_records_final_today` | `rme.medical-records.index` + date/status filters | same |
| `rme_invoices_unpaid` | `rme.cashier.index` | `manage_rme_billing` |
| `rme_invoices_paid_today` | `rme.cashier.index` | `manage_rme_billing` |
| `lab_candidates_pending` | `lab-case-candidates.index?status=pending_review` | `view_lab_orders` / `manage_lab_orders` |
| `lab_candidates_converted_today` | `lab-case-candidates.index?status=converted_to_lab_order` | same |
| `lab_orders_from_rme_today` | `lab-orders.index` | same |

Rules:

- No create/edit/action links from dashboard KPI cards.
- If permission or route is unavailable, KPI renders **without** link (no error).
- Owner role (pilot) typically sees clinic-visit drilldowns only; lab/cashier links appear for roles with those permissions.
- Destination index pages remain branch-scoped via `BranchContext` on operational modules.

## UI copy (Owner section)

- Filter label: **Filter Cabang** / option **Semua Cabang**
- Scope: **Menampilkan semua cabang aktif** or **Menampilkan cabang: {name}**
- Disclaimer: **Dashboard ini hanya monitoring; tidak membuat atau mengubah data RME/Lab.**

## Known limitations

- Pilot metrics only; executive lab/inventory KPI cards remain placeholder until later phases.
- Revenue uses paid RME payments only (`trx_rme_payments`), not lab billing.
- Handwriting RM and RM finalization remain manual workflow steps.
- Candidate → lab order conversion still requires explicit Admin Lab action with `lab_service_id`.
- Attention items and branch summary iterate per active branch (small N); acceptable for pilot branch count.
- KPI drilldowns are read-only index links where available; some cards may not link if permission/route is unavailable.
- Drilldown destination lists use active `BranchContext`, not dashboard `branch_id` filter.

## Follow-up (Phase 22.7)

- Date range filters (`date_from` / `date_to`).
- Pass dashboard branch filter into drilldown query params where index pages support it.
- Deeper visit-status breakdown and candidate status mix.
- Optional query-count/performance regression guard if branch count grows.
- VPS pilot smoke check for Owner branch filter after deploy.
