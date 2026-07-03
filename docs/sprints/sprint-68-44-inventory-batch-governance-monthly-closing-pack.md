# Sprint 68.44 — Inventory Batch Governance Monthly Closing Pack

## Status

Implementation complete — pending PR merge, GO tag, and VPS deploy.

## Baseline

Builds on Sprint 68.38–68.43:

- Auto batch number (68.38)
- Batch-aware transfer/opname UX (68.39)
- Batch expiry alert & FEFO visibility (68.40)
- Operational action log (68.41)
- Disposal/adjustment evidence workflow (68.42)
- Owner audit report/export/print (68.43)

Baseline commit/tag: `e284833` / `sprint-68-43-batch-disposal-owner-report-audit-dashboard-go`

## Goal

Package monthly batch governance evidence into one closing report for Admin Warehouse, Supervisor, and Owner.

## Scope

- Monthly closing pack (read-only summary)
- Governance checklist with print signature placeholders
- CSV export for governance archive
- Print evidence pack
- **No** digital review acknowledgement table (deferred — print signatures only)
- **No** stock mutation or movement creation

## Deliverables

| Area | Artifact |
|------|----------|
| Service | `InventoryBatchMonthlyClosingPackService` |
| Controller | `InventoryBatchMonthlyClosingPackController` |
| Request | `InventoryBatchMonthlyClosingPackFilterRequest` |
| Routes | `inventory.reports.batch-monthly-closing.{index,export,print}` |
| Views | `inventory/reports/batch-monthly-closing/{index,print}.blade.php` |
| Sidebar | Laporan & Analitik → Closing Bulanan Batch |
| Tests | `InventoryBatchMonthlyClosingPackTest.php` |

## Monthly Closing Checklist

1. Expired batch list reviewed
2. Near-expiry batch list reviewed
3. FEFO risky batches reviewed
4. Action logs reviewed
5. Disposal/return supplier requests reviewed
6. Pending approval requests reviewed
7. Adjustment-recorded requests matched to ADJUSTMENT_OUT movement
8. Stock card links verified for finalized adjustments
9. Cross-branch summary reviewed by Owner/Super Admin if authorized
10. CSV export archived
11. Print pack signed manually
12. No direct stock mutation outside ledger

## Safety Notes

- Report/pack is **read-only**
- No `trx_inventory_movements` creation from index/export/print
- No approve/reject/finalize actions in this sprint
- No mutable stock columns
- Ledger remains source of truth: `SUM(quantity_in) - SUM(quantity_out)`
- Branch scope via `BranchContext` + existing cross-branch permission (`view_inventory_cross_branch_analytics`)
- Authorization: `viewAny` on `InventoryMovement` (same as other inventory reports)

## Test Plan

```bash
DB_CONNECTION=pgsql php artisan test --filter=InventoryBatchMonthlyClosingPack
DB_CONNECTION=pgsql php artisan test --filter=InventoryBatchDisposalReport
```

## Evidence Placeholders

| Item | Status |
|------|--------|
| Route smoke | Pending post-deploy |
| Pest tests | Run locally before merge |
| CSV export | Covered in tests |
| Print pack | Covered in tests |
| VPS backup | Required before deploy |
| Deploy commit/tag | `sprint-68-44-inventory-batch-governance-monthly-closing-pack-go` |

## PR Summary

Sprint 68.44 adds a monthly governance closing pack that aggregates expiry risk (ledger-derived positive stock), action logs, disposal workflow, ADJUSTMENT_OUT ledger evidence, and follow-up exceptions — with CSV export, printable checklist/signatures, and branch-scoped access. Fully read-only; no migrations.
