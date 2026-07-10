# FIX-PO-MULTI-VENDOR — Item-Level Vendor Selection, Estimated Arrival Date & Supplier PDF

Branch `feature/fix-po-multi-vendor-item-estimated-arrival-supplier-pdf`
Base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target `main`).

## Business goal

A Purchase Order (PO) previously assumed **one supplier per PO** (header-level
`trx_purchase_orders.supplier_id`). This sprint makes the **supplier canonical
at the item level** so a single PO can carry items from several suppliers, gives
each item its own **estimated arrival date**, and produces a **vendor-scoped PDF**
that can be sent to each supplier without leaking any other supplier's items.

Procurement flow `PR → PO → GR → inventory movement PURCHASE` is preserved.
Inventory stays **ledger-only**; branch isolation, authorization, batch tracking,
approval workflow, and GR posting are unchanged.

## Key rules (durable)

1. **One PO can have many suppliers.** Supplier is canonical on the **PO item**
   (`trx_purchase_order_items.supplier_id`).
2. The legacy header `trx_purchase_orders.supplier_id` / `supplier_snapshot_name`
   is **deprecated / compatibility-only**. It is **derived** from the item
   suppliers: the sole distinct supplier for a single-vendor PO, or `NULL` for a
   multi-vendor PO. Never treat it as canonical input.
3. **Every PO item must have a supplier** (required in the FormRequest; enforced
   server-side in the service before submit/approve/send).
4. **Every PO item must have an estimated arrival date** (`estimated_arrival_date`,
   required at the HTTP form boundary; must be `>= order_date`).
5. **Supplier PDF is vendor-scoped.** `GET inventory/purchase-orders/{purchaseOrder}/supplier/{supplier}/pdf`
   (`inventory.purchase-orders.supplier-pdf`) returns a PDF containing **only that
   supplier's items** — never another supplier's items, prices, or existence.
6. Server validates supplier **membership** (the supplier must actually have a line
   on that PO) and **branch ownership** before rendering the PDF.
7. **GR references the PO item explicitly and respects the item supplier.** The
   `PURCHASE` movement records the **PO item's** supplier (header used only as a
   fallback for legacy roomless lines). Void reversal reuses the original
   movement's supplier automatically.
8. PO completion (`partially_received` / `fully_received`) is computed across
   **all** items, not one supplier.
9. Prices/subtotals/grand total are computed **server-side**; request totals are
   never trusted.
10. Branch isolation, authorization (`view`/`create`/`manage` purchase order),
    audit logging, and additive-migration-only rules are preserved.

## Schema

Migration `2026_07_10_100001_add_supplier_and_estimated_arrival_to_trx_purchase_order_items_table.php`
(additive, `migrate` only — never `migrate:fresh`/`db:wipe`):

- `trx_purchase_order_items.supplier_id` — nullable FK → `inv_suppliers`, `nullOnDelete`, indexed.
- `trx_purchase_order_items.estimated_arrival_date` — nullable `date`.
- Indexes `trx_po_items_supplier_id_index`, `trx_po_items_order_supplier_index (purchase_order_id, supplier_id)`.
- **Deterministic backfill (chunked, PG + SQLite safe):** legacy `item.supplier_id`
  ← parent PO header `supplier_id`; legacy `item.estimated_arrival_date` ← parent
  PO `expected_delivery_date`. No column dropped; no data lost.

## Code

- `PurchaseOrderItem` — `supplier_id` + `estimated_arrival_date` fillable/cast,
  `supplier()` relation, `displaySupplierName()`.
- `PurchaseOrder` — `supplierGroups()`, `suppliersInvolved()`,
  `subtotalForSupplier()`, `hasItemsForSupplier()`; header `supplier_id` retained.
- `ValidatesPurchaseOrderInput` — `items.*.supplier_id required|exists`,
  `items.*.estimated_arrival_date required|date|after_or_equal:order_date`.
- `PurchaseOrderService` — per-item supplier + arrival validation server-side,
  `deriveHeaderSupplier()` (compat snapshot), `applyItemDefaults()` (compatibility
  layer: a header `supplier_id` defaults blank item suppliers and the order date
  defaults a blank arrival — the form always sends per-item values that take
  precedence), submit/approve/send assert **every item** supplier is active,
  `buildSupplierPdfData()` (vendor-scoped dataset with server-side membership).
- `PurchaseOrderRepository` — persists `supplier_id` + `estimated_arrival_date`,
  eager-loads `items.supplier`.
- `PurchaseOrderController::supplierPdf()` + route + dompdf blade
  `resources/views/inventory/purchase-orders/supplier-pdf.blade.php` (table-based,
  no flexbox; Klinik Gigi Daengtisia header, supplier block, item table with
  estimated arrival, supplier total, signature area). Reuses `view` policy — **no
  new permission**.
- `GoodsReceiptService::createFromPurchaseOrder()` — `PURCHASE` movement supplier =
  `poItem.supplier_id ?? header.supplier_id`.
- UI `_form.blade.php` — item-driven: per-item supplier select + estimated arrival
  + Alpine dynamic vendor summary (per-supplier subtotal + grand total). Header
  supplier select removed. `show.blade.php` — items grouped by supplier with
  per-supplier subtotal, grand total, and per-supplier PDF download buttons.

## Tests

- `PurchaseOrderMultiVendorTest` — single/multi-vendor create, per-item
  supplier/arrival persistence, server-side subtotals + grand total, HTTP-boundary
  rejection of missing supplier / missing arrival, arrival-before-order-date,
  cross-branch supplier, inactive supplier, request-total not trusted, legacy read,
  per-item movement supplier on GR post.
- `PurchaseOrderSupplierPdfTest` — authorized download (mime/filename), vendor
  isolation (no leak of other supplier/item), arrival + supplier subtotal on PDF,
  dataset scoping, 404 for non-member supplier, forbidden/404 cross-branch,
  forbidden for unauthorized user.
- Updated existing PO/GR test fixtures to supply per-item supplier + arrival.
