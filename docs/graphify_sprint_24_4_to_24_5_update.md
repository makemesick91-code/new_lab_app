# Graphify Update — Sprint 24.4 to Sprint 24.5

## Context

This document records the Graphify refresh for sprint updates completed after `docs/graphify_sprint_22_to_24_update.md`.

## Previous Graphify Update

- Commit: `a167791`
- Tag: `sprint-24-graphify-sprint-22-to-24-update`
- Covered through: Sprint 24.3 / Sprint 24.3 VPS smoke / Sprint 22–24 companion update

## New Sprint Coverage

### Sprint 24 Phase 24.4 — Owner Dashboard RME Receivable KPI Integration

- Commit: `afbc3e3`
- Tag: `sprint-24-phase-24-4-owner-dashboard-rme-receivable-kpi`
- Added Owner Dashboard receivable KPIs in `OwnerDashboardRmeLabKpiService`:
  - `rme_receivable_total_remaining`
  - `rme_receivable_partial_count`
  - `rme_receivable_unpaid_count`
- Added permission-gated `rme_receivables` drilldown shortcut in `OwnerDashboardRmeLabDrilldownService` → `route('rme.cashier.receivables')`.
- Added dashboard labels:
  - Sisa Piutang RME
  - Invoice Cicilan
  - Invoice Belum Dibayar
  - Piutang RME
- Receivable KPI is branch-aware (scoped via `rmeInvoiceQuery($branchIds)`).
- Receivable total uses active `UNPAID` and `PARTIAL` RME invoices; total remaining sums per-invoice remaining amount.
- `PAID`, `VOID`, and `DRAFT` invoices are excluded.
- No migration.
- No payment logic change.

### Sprint 24 Phase 24.5 — VPS Deploy + Owner Dashboard Receivable KPI Smoke

- Commit: `7ceb0c0`
- Tag: `sprint-24-phase-24-5-vps-owner-dashboard-receivable-kpi-smoke`
- VPS deploy validated Sprint 24.4 commit `afbc3e3`.
- Smoke result:
  - Dashboard Owner opens: PASS
  - Monitoring Pilot RME & Lab section visible: PASS
  - Sisa Piutang RME visible and formatted as Rupiah: PASS
  - Invoice Cicilan visible: PASS
  - Invoice Belum Dibayar visible: PASS
  - Piutang RME shortcut visible for permitted user: PASS
  - Shortcut opens Piutang RME page: PASS
  - Branch filter affects receivable KPI: PASS
  - Laravel log after smoke: CLEAN
- Negative permission check was marked N/A in manual smoke, covered by Sprint 24.4 targeted tests.

## Important Files Added or Updated

- `app/Modules/Reporting/Services/OwnerDashboardRmeLabKpiService.php`
- `app/Modules/Reporting/Services/OwnerDashboardRmeLabDrilldownService.php`
- `resources/views/dashboard.blade.php`
- `tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php`
- `tests/Feature/Dashboard/OwnerDashboardBranchFilterDrilldownTest.php`
- `docs/sprint_24_phase_24_4_owner_dashboard_rme_receivable_kpi.md`
- `docs/sprint_24_phase_24_5_vps_owner_dashboard_receivable_kpi_smoke.md`

## Graphify Result

- Command: `graphify update .` (AST-only re-extraction, no LLM / no API cost)
- Result: PASS
- Nodes: `11733` (previously `11700`)
- Edges: `16715` (previously `16685`)
- Communities: `1702` (previously `1716`)
- Extraction: 1607/1607 files (100%)
- Manifest commit before/after: graph previously built at `9aff71c4` (Sprint 24.3); now refreshed at `7ceb0c0` (Sprint 24.5).

## Notes

- `graphify-out/` is generated and gitignored.
- Only this companion doc should be committed.
- No production logic was changed for this Graphify refresh.
- No migrations were added.
- No full test suite was run.

## Commit Recommendation

`Update Graphify report for Sprint 24.4 and 24.5`
