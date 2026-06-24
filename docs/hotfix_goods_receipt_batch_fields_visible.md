# Hotfix — Goods Receipt Batch Fields Visible for Batch-Tracked Products

**Date:** 2026-06-24
**Branch:** `hotfix/goods-receipt-batch-fields-visible`
**Scope:** Inventory · Goods Receipt receiving UI only. No schema, no procurement/PO lifecycle, no ledger source-of-truth, no RME, no branch master changes.

## Problem

A prior hotfix added operator guidance for the Inventory Batch/Lot stock-in flow. The Batch & Lot page is correctly monitoring-only.

On the Goods Receipt receiving screen the helper text was shown:

> "Isi nomor batch/lot dan tanggal kedaluwarsa untuk barang yang dilacak batch atau expired…"

…but the actual **Batch Number / Lot Number / Expiry Date** inputs were not reliably visible in the item table for batch-tracked products (e.g. ALKOHOL, ASEPTIC GEL, CAIRAN SPIRTUS, COTTON ROLL — all `requires_batch_tracking = true`). On VPS a previously posted GR had `accepted_qty > 0` but null batch fields and no `inv_inventory_batches` rows were created for it.

## Root cause

The **backend was already correct**. Validation (`ValidatesGoodsReceiptBatchInput` + `GoodsReceiptService::assertBatchInputForItem`) already rejects a batch-tracked item with `accepted_qty > 0` and an empty `batch_number`, and posting (`GoodsReceiptService::buildBatchDataForPost` → `InventoryStockService::receiveStock`) already creates/finds the `InventoryBatch` and stamps `inventory_batch_id` onto both the goods receipt item and the inventory movement.

The defect was purely in the **Blade/Alpine rendering**. The batch section was gated by:

```
x-show="item.requires_batch_tracking && Number(item.accepted_qty || 0) > 0"
```

in two places:

- `resources/views/inventory/goods-receipts/_form.blade.php` — the desktop second `<tr>` wrapper.
- `resources/views/inventory/goods-receipts/_batch-item-fields.blade.php` — the partial's outer container (used by both desktop and mobile layouts).

Because visibility was tied to `accepted_qty > 0` (a value evaluated against live user input), the whole batch section disappeared whenever the operator cleared or zeroed the accepted quantity, so the Batch/Lot inputs were not dependably visible before submit/post. The fields exist in the DOM but were hidden by `x-show`.

## Fix

The batch section is now shown for **every** batch-tracked line, gated only on the product attribute:

```
x-show="item.requires_batch_tracking"
```

Changes (UI only):

- `_batch-item-fields.blade.php`: outer `x-show` reduced to `item.requires_batch_tracking`; added the mandatory operator notice:
  > "Produk ini wajib batch. Isi Nomor Batch sebelum Submit/Post Goods Receipt."
- `_form.blade.php`: desktop batch `<tr>` `x-show` reduced to `item.requires_batch_tracking`.

The existing "Batch Ada / Batch Baru" (existing vs new) toggle, `old()` repopulation, edit-mode value hydration, validation-error display, and dynamic `items[index][...]` naming are all preserved by the partial (`:name="'items[' + index + '][batch_number]'"`, etc.).

## Operator workflow (after hotfix)

1. Admin/Inventory opens **Buat Penerimaan Barang** from a receivable PO.
2. For each batch-tracked line the Batch/Lot panel is visible immediately with the red mandatory notice.
3. Operator either picks an existing batch ("Batch Ada") or fills **Nomor Batch** (required), optional **Nomor Lot**, **Tanggal Terima Batch**, and optional **Tanggal Kedaluwarsa** ("Batch Baru").
4. Submit/Post is blocked by backend validation if a batch-tracked line with `accepted_qty > 0` has no batch identity.
5. On Post, the existing ledger flow creates/finds the `InventoryBatch`, sets `inventory_batch_id` on the GR item and the `PURCHASE` movement, and the batch then appears on the read-only Batch & Lot page.

## Validation rules (unchanged backend, now surfaced in UI)

- `requires_batch_tracking = true` + `accepted_qty > 0` ⇒ `batch_number` required (unless an existing `inventory_batch_id` is chosen).
- `lot_number` optional.
- `expiry_date` optional; if present must not predate the batch received date.
- Non-batch-tracked products are not required to provide batch fields.

## Testing evidence

- `tests/Feature/Inventory/GoodsReceiptBatchFieldsVisibleHotfixTest.php` — 8 passed:
  - Create form renders `batch_number`, `lot_number`, `expiry_date` inputs with `items[index][...]` naming.
  - Batch section gated only on `requires_batch_tracking` (no `accepted_qty` gate) + mandatory notice present.
  - Batch-tracked GR with `accepted_qty > 0` and empty `batch_number` is rejected (`items.0.batch_number`).
  - Batch-tracked GR with `batch_number` posts, creates an `InventoryBatch`, and links `inventory_batch_id` on item and movement.
  - Non-batch product needs no batch fields.
  - No `inventory.batches.create` / `inventory.batches.store` route exists.
- `php artisan test --filter=InventoryBatchLotUiVisibilityHotfixTest` — 11 passed.
- `vendor/bin/pint --test` and `git diff --check` — clean.

## No migration note

No migration was added. The required columns already exist on VPS and locally: `trx_goods_receipt_items.{batch_number,lot_number,expiry_date,inventory_batch_id}`, `trx_inventory_movements.inventory_batch_id`, and the `inv_inventory_batches` table. This hotfix is rendering-only.

## No manual batch master note

No manual Inventory Batch create/store route was added and no duplicate batch table was introduced. Batches are still created exclusively through the existing Goods Receipt post (and Opening Stock) ledger flow.
