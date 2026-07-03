# Sprint 68.43 — Batch Disposal Owner Report & Audit Dashboard

## Baseline

Builds on Sprint 68.38–68.42:

- Auto batch number (68.38)
- Batch-aware transfer/opname (68.39)
- Expiry alert + FEFO (68.40)
- Operational action log (68.41)
- Disposal/adjustment workflow (68.42)

Baseline commit/tag: `4455232` / `sprint-68-42-batch-disposal-adjustment-evidence-workflow-go`

## Scope

Read-only owner/admin warehouse reporting and audit trail for batch disposal workflow:

- Dashboard summary cards and breakdowns
- Audit trail: Batch → Action Log → Disposal Request → ADJUSTMENT_OUT Movement
- Filters, CSV export, print evidence
- Branch-scoped visibility; cross-branch read-only for authorized users
- **No stock mutation, no movement creation, no migration**

## Routes

| Route name | Method | Path |
| --- | --- | --- |
| `inventory.reports.batch-disposals.index` | GET | `/inventory/reports/batch-disposals` |
| `inventory.reports.batch-disposals.export` | GET | `/inventory/reports/batch-disposals/export` |
| `inventory.reports.batch-disposals.print` | GET | `/inventory/reports/batch-disposals/print` |

## Authorization

- `InventoryMovementPolicy::viewAny` (`view_inventory` or equivalent)
- Cross-branch filter: `view_inventory_cross_branch_analytics`, `view_owner_dashboard`, or Super Admin (via `InventoryReportService::reportBranchOptions`)

## Dashboard Checklist

1. Total submitted disposal requests
2. Total approved requests
3. Total rejected requests
4. Total adjustment-recorded requests
5. Pending approval count
6. Quantity requested
7. Quantity adjustment-recorded
8. Request breakdown by type
9. Request breakdown by branch (cross-branch)
10. Request breakdown by status
11. Expired/near-expiry batch related requests
12. Movement-linked requests

## Audit Checklist

1. Batch detail visible (link to workflow show)
2. Action log visible
3. Disposal request visible
4. Linked ADJUSTMENT_OUT visible when finalized
5. No movement shown when not finalized
6. Branch filter obeys permission
7. Export CSV works
8. Print page works

## Safety Notes

- Report is read-only
- No `trx_inventory_movements` creation
- No adjustment finalization from report
- No stock mutation / no mutable stock columns
- Ledger remains source of truth
- `BranchContext` + policy enforced

## Tests

`tests/Feature/Inventory/InventoryBatchDisposalReportTest.php`

```bash
DB_CONNECTION=pgsql php artisan test --filter=InventoryBatchDisposalReport
```

## Evidence Placeholders

- PR: TBD
- Screenshots: optional
- Deploy backup: TBD
- Smoke result: TBD

## Deliverables

- `InventoryBatchDisposalReportService`
- `InventoryBatchDisposalReportController`
- `InventoryBatchDisposalReportFilterRequest`
- Views: `inventory/reports/batch-disposals/index`, `print`
- Sidebar: Inventory → Laporan & Analitik → Disposal & Adjustment Batch
- Inventory dashboard KPI cards (4 read-only links)
- UAT doc (this file)
