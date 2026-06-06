# ADLMS Inventory Memory

## Core Rules

- Inventory is branch-aware.
- Inventory is location-aware.
- Stock is ledger-based.
- Do not add mutable stock balance columns.
- Current stock must be calculated from stock movements.
- All stock movement operations must be auditable.

## Completed

Sprint 12 completed Inventory Core:

- product categories
- product units
- products
- suppliers
- inventory locations
- inventory movements
- stock service/repository patterns
- inventory dashboard and views
- permissions: manage_inventory, view_inventory

Sprint 13 completed Stock Opname Workflow.

Sprint 14 completed Stock Transfer Workflow (same-branch inter-location transfers).

Sprint 15.2 completed Transfer Receiving Workflow (ship/receive two-phase transfer).

Sprint 15.3 completed Batch & Lot Tracking (ledger-derived batch stock, read-only batch UI).

Sprint 15.4 completed Reorder Point & Inventory Alerts (`InventoryAlertService` canonical for alert KPIs).

Sprint 15.5 completed Inventory Analytics (read-only ledger-derived analytics).

Sprint 15.6 completed Inventory Advanced Hardening & Navigation Closure:

- Stok Opname sidebar discovery
- inventory dashboard KPI deduplication (`InventoryAlertService` canonical)
- dashboard quick actions (permission-gated)
- sidebar dead placeholder links removed

## Future Inventory Work

- Purchase Request / Purchase Order / Receiving workflow (Sprint 16 candidate)
- Production Material Usage integration
- Notification channels for inventory alerts
- Owner cross-branch inventory rollup
- Batch-aware stock opname / `requires_batch_tracking` product flag
