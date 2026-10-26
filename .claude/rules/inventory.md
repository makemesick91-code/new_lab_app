---
paths:
  - "app/Modules/Inventory/**"
  - "app/Services/Inventory/**"
  - "resources/views/inventory/**"
  - "config/inventory*.php"
  - "tests/Feature/Inventory/**"
---

# Inventory & procurement — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and
are not repeated here. Authority: code > canonical `.cursor/rules/` > this file > archive. On disagreement follow the
code, then the canonical rule, and report the drift.

## Invariants

### Ledger and stock

- **AIR-INV-01** Derive stock only as `SUM(quantity_in) - SUM(quantity_out)` over `trx_inventory_movements`, always
  filtered by `branch_id` (and `inventory_location_id` when location-specific). Never add or write a mutable stock
  column (`stock`, `current_stock`, `qty_on_hand`, `available_stock`, …) and never `update(['current_stock' => …])`
  (CG-10). Source: `.cursor/rules/20-inventory-branch-ledger.mdc`.
- **AIR-INV-02** Every movement row carries `branch_id`, `inventory_location_id`, `product_id`, `movement_type`,
  `movement_date`, `quantity_in`, `quantity_out`. Use the types declared in `InventoryMovement::TYPES` (`OPENING`,
  `PURCHASE`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `TRANSFER_IN`, `TRANSFER_OUT`); rule 20 lists only the first four —
  the model constant is the authority. Source: `20-inventory-branch-ledger.mdc`.
- **AIR-INV-03** Stock increases only from a posted ledger receipt (Goods Receipt → `PURCHASE` movement), never from a
  PO approval. A stock opname count is a snapshot and reaches the ledger only when it is finalized. Source:
  `20-inventory-branch-ledger.mdc`.
- **AIR-INV-04** Before any outbound movement, check location-specific derived stock and reject insufficient stock;
  validate that the product and the inventory location are active. Source: `20-inventory-branch-ledger.mdc`,
  `inventory-expert.mdc`.
- **AIR-INV-05** Value stock as derived stock × `average_cost`; never read a stored stock column. Source:
  `20-inventory-branch-ledger.mdc`.
- **AIR-INV-06** Treat the summary tables `rpt_inventory_daily_summaries`, `rpt_inventory_branch_summaries` and
  `rpt_inventory_product_summaries` as read accelerators only — never a source of truth and never mutable stock.
  Source: `.cursor/rules/52-reporting-materialized-summary.mdc` §8 (archive `ENT-3`).

### Branch isolation and authorization

- **AIR-INV-07** In every branch-owned service call `BranchContext::requireId()`, load route-bound records with
  `findInBranch($branchId, $id)`, and validate that related records share the branch. Repositories take `int
  $branchId` as the first parameter, always filter on it, and never return an unbounded `all()`. A request
  `branch_id` is never the authority (CG-05). Source: `20-inventory-branch-ledger.mdc`.
- **AIR-INV-08** The `inventory.` route group carries only `auth` middleware, so every controller action must call
  `$this->authorize(...)` against its inventory policy; a hidden menu is never the boundary (CG-06). Source:
  `.cursor/rules/75-admin-lab-lab-only-access.mdc` §4 (archive `FIX-ADMIN-LAB-LAB-ONLY-ACCESS`).
- **AIR-INV-09** Cover every inventory workflow with tests for authorization, validation, branch isolation, location
  isolation, stock calculation, insufficient stock, inactive product/location, the happy path and edge cases. Source:
  `inventory-expert.mdc`.

### Purchase orders (multi-vendor)

- **AIR-INV-10** Keep the supplier canonical on the PO item (`trx_purchase_order_items.supplier_id`). The header
  `trx_purchase_orders.supplier_id` / `supplier_snapshot_name` is a deprecated, derived snapshot (the sole distinct
  item supplier, or `NULL` for multi-vendor); never reintroduce a header supplier select as the source of truth.
  Source: `.cursor/rules/71-po-multi-vendor-item-supplier-estimated-arrival-pdf.mdc`.
- **AIR-INV-11** Every PO item requires a supplier and `estimated_arrival_date >= order_date`
  (`ValidatesPurchaseOrderInput`). `PurchaseOrderService::applyItemDefaults()` is only a compatibility shim for
  programmatic callers; the HTTP FormRequest still requires both per item. Source:
  `71-po-multi-vendor-item-supplier-estimated-arrival-pdf.mdc`.
- **AIR-INV-12** Compute prices, per-supplier subtotals and the grand total server-side
  (`PurchaseOrder::supplierGroups()`, `subtotalForSupplier()`, `totalAmount()`); never trust request totals. Submit,
  approve and send assert that every item's supplier is active. Source: `71-po-…-pdf.mdc`, archive
  `FIX-PO-MULTI-VENDOR`.
- **AIR-INV-13** The supplier PDF (`inventory.purchase-orders.supplier-pdf`) is vendor-scoped: build it only through
  `PurchaseOrderService::buildSupplierPdfData()`, which checks supplier membership and branch ownership; never leak
  another supplier's items, prices or existence; reuse the `view` policy; keep the dompdf template table-based and
  sanitized. Source: `71-po-multi-vendor-item-supplier-estimated-arrival-pdf.mdc`.
- **AIR-INV-14** A Goods Receipt references the PO item explicitly; its `PURCHASE` movement records the PO item's
  supplier (`poItem.supplier_id ?? header.supplier_id`), a void reversal reuses the original movement's supplier, and
  PO completion is computed across all items. Source: `71-po-…-pdf.mdc`, archive `FIX-PO-MULTI-VENDOR`.

### Goods receipt batches

- **AIR-INV-15** Treat the GR header default batch/lot as a convenience:
  `ValidatesGoodsReceiptBatchInput::applyDefaultBatchToItems()` expands it only into batch-tracked items that have no
  explicit batch; an item-level batch always overrides; non-batch products keep an empty batch. A batch-tracked item
  with neither its own batch nor a usable default is refused. Source: archive `FIX-PRE-68-45` (Scope E),
  `SPRINT-68.45`.
- **AIR-INV-16** Batch identity is per product, never one global batch: find-or-create keys on UNIQUE
  `inv_inventory_batches_branch_product_batch_lot_unique` (branch, product, batch number, lot number), so one shared
  header batch number yields a distinct batch row per product. The `PURCHASE` movement keeps its per-product
  `inventory_batch_id`, the default expiry is kept for FEFO, and the GR post runs in one `DB::transaction` with no
  partial writes. Source: archive `SPRINT-68.45`.

### Purchase requests and roles

- **AIR-INV-17** Kepala Cabang is PR-only: its role holds `view dashboard`, `view_inventory`,
  `view_purchase_request`, `manage_purchase_request` and nothing that creates a PO, manages inventory or master data,
  or approves. `PurchaseOrderPolicy::create` → `canManagePurchaseOrder` is the server-side chokepoint; Admin Warehouse
  owns PO and vendor continuation. Never grant Kepala Cabang a PO permission. Source: archive `FIX-PRE-68-45`
  (Scope G), `SPRINT-68.45`.
- **AIR-INV-18** Keep the PR type (`pr_type`, `PurchaseRequest::PR_TYPE_REGULER` / `PR_TYPE_DARURAT`, validated
  `in:reguler,darurat`). The branch PR board `inventory.purchase-requests.workflow` is read-only, scoped through
  `BranchContext` (`PurchaseRequestService::branchWorkflowBoard`), registered before the PR resource route so it is
  not captured as `{purchaseRequest}`, and shows only real PR statuses — never an invented one. Source: archive
  `FIX-PRE-68-45` (Scope G), `SPRINT-68.45`.

### Reports and vendors

- **AIR-INV-19** The report supplier filter is purchase provenance on `trx_inventory_movements.supplier_id` and
  applies only to current stock, low stock, mutation and valuation. `InventoryReportService::sanitizeReportFilters()`
  drops a supplier outside the resolved branch (IDOR boundary). On Kartu Stok and Stok per Ruangan the filter is
  inapplicable and the page says so with a banner — never wrong data, never a 500. Source: archive `FIX-PRE-68-45`
  (Scope F), `SPRINT-68.45`.
- **AIR-INV-20** Vendor spend is procurement truth — POSTED GR `line_total` through
  `InventoryAnalyticsRepository::getSupplierPerformance()` (`InventoryReportService::supplierSpendSummary()`), never
  the stock ledger. Source: archive `FIX-PRE-68-45` (Scope F), `SPRINT-68.45`.
- **AIR-INV-21** Run `inventory:procurement-workflow-audit --strict` before a procurement GO or deploy. Only unsafe
  findings fail strict (exit 2): `kepala_cabang_role_po_permission_leak` and `kepala_cabang_user_po_permission_leak`.
  Data-quality findings (missing PR type, missing batch, missing provenance, …) are WARN and must never be promoted to
  a strict failure. Source: archive `SPRINT-68.45`.

### Views

- **AIR-INV-22** Inventory views use the `x-ui.*` components and semantic tokens (the product list is the reference
  list page, the stock card the reference ledger detail page). Never assign a stock attribute in a view
  (`->current_stock =`, `->derived_stock =`, `->stock_quantity =` are flagged by `ArchitectureUiGovernanceCheckCommand`),
  keep semantic stock-status colours, and remember status tokens only expose shades `50/100/700/DEFAULT`. A view
  change never alters movement order, quantity signs or the running balance. Source: archive `UIX-6`, `UIX-9`.

## Current posture (snapshot 2026-10-25 — verify before relying on it)

- Procurement audit `inventory:procurement-workflow-audit --strict` was GO on the production pilot at
  `SPRINT-68.45` closure; data-quality WARN findings are expected and non-blocking.
- The Kepala Cabang role and the additive `users.branch_id` column (FIX-PRE-68-45 Phase 3) are live. Note that
  `BranchContext` now resolves an active online context and the Front Office pin before `users.branch_id`; the
  resolution order written in rule 20 predates that — follow `App\Modules\Branch\Services\BranchContext`.
- Inventory suites run in CI through the Selective Module Gate (`--filter='Inventory'`) when the classifier sets
  `run_inventory_tests`; only the batch-governance suites (tokens `Dq2BatchGovernance`, `Dq3SourceDocumentBatch`,
  `Dq31`) are in the NSF-R011 critical filter.
- Batch data-quality tooling exists — registries `config/inventory_batch_governance.php`,
  `config/inventory_source_document_batch_governance.php`, `config/inventory_dq31_governance.php` and commands
  `inventory:batch-governance-audit`, `inventory:source-document-batch-audit`,
  `inventory:backfill-source-document-batches`, `inventory:backfill-missing-batches`,
  `inventory:ambiguous-batch-review-pack`, `inventory:repair-ambiguous-batch-links`. Their sprint records are not in
  this file's sources; read the registry before touching batch linkage and run any repair dry run first (CG-13).
- `inventory-expert.mdc` is partly historical: its "Expected Module Areas" list `Http/Controllers` / `Http/Requests`
  (the module uses `Controllers/` and `Requests/`), and its Sprint 14–20 roadmap is old planning.

## Canonical rules — read the relevant one before changing this area

| Rule | Covers |
|---|---|
| `.cursor/rules/20-inventory-branch-ledger.mdc` | Ledger-only stock, branch-owned service and repository rules |
| `.cursor/rules/71-po-multi-vendor-item-supplier-estimated-arrival-pdf.mdc` | Item-level supplier, estimated arrival, vendor-scoped PDF |
| `.cursor/rules/inventory-expert.mdc` | Inventory domain invariants and required test matrix |
| `.cursor/rules/52-reporting-materialized-summary.mdc` | Summary tables never become the source of truth |
| `.cursor/rules/75-admin-lab-lab-only-access.mdc` | Inventory controllers authorize; Admin Lab has no inventory access |

## History

Verbatim sprint records: `docs/sprints/claude-md-archive/09-inventory.md` (UIX-6, UIX-9, FIX-PRE-68-45,
SPRINT-68.45, FIX-PO-MULTI-VENDOR). Cross-domain records cited above: `ENT-3` in `12-foundation-enterprise.md`,
`FIX-ADMIN-LAB-LAB-ONLY-ACCESS` in `08-lab.md`. Grep by sprint id; never read a whole file.
