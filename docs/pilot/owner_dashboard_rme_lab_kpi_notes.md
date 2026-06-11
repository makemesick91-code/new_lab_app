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

- **Owner dashboard (global):** `metrics(null)` scopes to **all active branches** (`mst_branches.is_active = true`).
- **Per-branch drill-down (service API):** `metrics($branchId)` scopes all queries to one branch — reserved for Phase 22.6 filters/comparisons; not exposed in UI yet.
- **Branch admin dashboard:** unchanged; RME/Lab pilot section is **not** rendered for operational branch users.

## Known limitations

- Pilot metrics only; executive lab/inventory KPI cards remain placeholder until later phases.
- Revenue uses paid RME payments only (`trx_rme_payments`), not lab billing.
- Handwriting RM and RM finalization remain manual workflow steps.
- Candidate → lab order conversion still requires explicit Admin Lab action with `lab_service_id`.
- Attention items iterate per active branch (small N); acceptable for pilot branch count.

## Follow-up (Phase 22.6)

- Date range filters (`date_from` / `date_to`).
- Branch comparison cards for authorized owner users.
- Deeper visit-status breakdown and candidate status mix.
- Optional query-count/performance regression guard if branch count grows.
