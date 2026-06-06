# ADLMS Sprint 13–20 Roadmap Memory

## Sprint 13 — COMPLETE

Stock Opname Workflow.

## Sprint 14 — COMPLETE

Inventory Stock Transfer between locations (same branch).

## Sprint 15 — Inventory Advanced (COMPLETE)

Milestone delivered as numbered slices 15.2–15.6 (not Purchase/Receiving — see Sprint 16).

### Sprint 15.2 — COMPLETE

Transfer Receiving Workflow (ship/receive two-phase transfer).

### Sprint 15.3 — COMPLETE

Batch & Lot Tracking.

### Sprint 15.4 — COMPLETE

Reorder Point & Inventory Alerts.

### Sprint 15.5 — COMPLETE

Inventory Analytics.

### Sprint 15.6 — COMPLETE

Inventory Advanced Hardening & Navigation Closure (sidebar, dashboard KPI dedup, quick actions).

## Sprint 16 — COMPLETE

Purchasing milestone delivered as slices 16.1–16.4. PR/PO express intent only; stock increases via Goods Receipt post → `PURCHASE` ledger movement.

**Final sign-off:** commit `0dd4729`, tag `sprint-16.4-revision-complete`, **1038 tests passed**, **3523 assertions**, **227 routes**.

### Sprint 16.1 — COMPLETE

Purchase Request Workflow (intent-only; no inventory movements; branch-scoped approval).

### Sprint 16.2 — COMPLETE

Purchase Order Workflow (document-only; no inventory movements; no stock updates):

- schema: `trx_purchase_orders`, `trx_purchase_order_items`
- statuses: draft, submitted, approved, sent, cancelled
- manual PO and PR-linked PO (approved PR only; duplicate active PO blocked)
- supplier snapshot, currency default IDR, computed total (not stored)
- UI: Pesanan Pembelian sidebar, Buat Pesanan Pembelian quick action, Buat PO from approved PR

### Sprint 16.3 — COMPLETE

Goods Receipt / receiving workflow (PURCHASE inventory movements; stock updates):

- schema: `trx_goods_receipts`, `trx_goods_receipt_items`; PO item `quantity_received` fulfillment cache
- statuses: draft, submitted, posted, cancelled
- `GoodsReceiptService` post → `PURCHASE` movements; PR/PO remain zero stock impact
- PO receiving statuses: partially_received, fully_received (header); line pending/partial/complete
- UI: Penerimaan Barang sidebar, Terima Barang from PO show

### Sprint 16.4 — COMPLETE

Procurement Hardening (no re-architecture; closes audit backlog on PR → PO → GR chain):

- 16.4.1: batch/lot on GR receive (`requires_batch_tracking`, batch fields, movement linkage)
- 16.4.2: PO receiving visibility (ordered/received/remaining, linked GR panel)
- 16.4.3: GR void workflow (`ADJUSTMENT_OUT` reversal, PO cache rollback)
- 16.4.4: PR/PO branch isolation tests, GR regression, permission/UI hardening

### Sprint 16.4 Revision UI Inventory — COMPLETE

Final inventory/procurement UI closure (commit `0dd4729`):

- sidebar navigation overhaul for inventory and procurement modules
- procurement form polish: PO, PR, stock transfers, goods receipts
- product CSV import UI; decimal(18,4) quantity column migration
- roles/permissions grouping UI (`PermissionGroupingService`)

## Sprint 17 — PLANNED

Production Material Usage integration.

## Sprint 18 — PLANNED

Inventory notification channels, owner cross-branch rollup, valuation/audit export enhancements (as scoped).

## Sprint 19 — PLANNED

HR Core: employee records, attendance foundation, roles.

## Sprint 20 — PLANNED

UX/UI Modernization and Dashboard Hardening.

## Rule

Each sprint must preserve completed sprint contracts and include tests, docs, and quality gates. Do not mark unbuilt features as complete.
