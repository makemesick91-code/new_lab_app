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

Sprint 16.1 completed Purchase Request Workflow:

- tables: `trx_purchase_requests`, `trx_purchase_request_items`
- statuses: draft, submitted, approved, rejected, cancelled
- service: `PurchaseRequestService` (no inventory movements)
- policy: `PurchaseRequestPolicy` + `approve_inventory_purchase_request`
- UI: index/create/edit/show, sidebar **Permintaan Pembelian**, alerts **Buat PR** shortcut (prefill only)
- PR number: `PR-{YYYYMMDD}-{branch_id}-{sequence}`

Sprint 16.2 completed Purchase Order Workflow (document-only; no stock impact):

- tables: `trx_purchase_orders`, `trx_purchase_order_items`
- statuses: draft, submitted, approved, sent, cancelled
- future statuses NOT implemented: partially_received, fully_received, closed (deferred to 16.3)
- service: `PurchaseOrderService` (no inventory movements)
- policy: `PurchaseOrderPolicy` + `approve_inventory_purchase_order` (with manage_inventory / legacy manage master data fallback)
- PO number: `PO-{YYYYMMDD}-{branch_id}-{sequence}`
- fields: supplier_snapshot_name, supplier_reference_number, currency default IDR
- total_amount: computed via model accessor (NOT stored on header)
- manual PO: purchase_request_id = null
- PR-linked PO: from approved PR only; duplicate active PO blocked; cancelled PO allows new PO
- supplier snapshot captured at creation; refreshed on draft edit if supplier changes
- UI: index/create/edit/show, sidebar **Pesanan Pembelian**, dashboard **Buat Pesanan Pembelian**, **Buat PO** on approved PR show
- NO Goods Receipt / Terima Barang / Update Stok UI
- NO trx_inventory_movements; NO stock updates; PURCHASE movement deferred to Goods Receipt (16.3)
- branch isolation and ledger-only rules preserved; HR module untouched

## Future Inventory Work

- Goods Receipt workflow (Sprint 16.3) — receiving, PURCHASE inventory movements, stock updates
- Production Material Usage integration
- Notification channels for inventory alerts
- Owner cross-branch inventory rollup
- Batch-aware stock opname / `requires_batch_tracking` product flag
