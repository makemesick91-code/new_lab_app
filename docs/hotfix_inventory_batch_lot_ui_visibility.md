# Hotfix — Inventory Batch/Lot Stock-In UI Visibility

**Date:** 2026-06-24
**Branch:** `hotfix/inventory-batch-lot-stock-in-ui-visibility`
**Scope:** UI visibility + operator guidance + Opening Stock batch wiring + tests. No destructive migration. No ledger logic change. No RME change. No branch master change.

## Background

The Inventory **Batch & Lot** page exposes only `index` and `show` routes — by design, it is a monitoring/derivation page, not a master-data entry screen. Batch stock is derived from the inventory movement ledger (`inv_inventory_movements`); the batch table (`inv_inventory_batches`) carries no mutable stock column.

The backend already supports batch/lot/expiry capture across stock-in flows:

- `InventoryStockService` (`createOpeningStock`, `receiveStock`, `adjustIn`) accepts an optional `$batchData` array.
- `GoodsReceiptService` validates batch requirements for batch-tracked products (`requires_batch_tracking`).
- `GoodsReceiptRepository` / `GoodsReceiptItem` persist `batch_number`, `lot_number`, `expiry_date`.
- `InventoryBatch` carries `batch_number`, `lot_number`, `expiry_date`.
- `StoreReceiveStockRequest` and `StoreAdjustmentRequest` already accept new-batch input via the `ValidatesInventoryBatchInput` concern.

**Problem:** Operators did not know where Batch/Lot data is entered, because the Batch & Lot page has no create button. This caused confusion even though the correct architecture is: *stock-in transaction → batch data → Batch/Lot monitoring*.

## Why the Batch/Lot page is monitoring-only

Batch identity and quantities are a **consequence** of stock-in transactions, not an independent master record. Allowing a manual batch create here would:

- create batch rows with no backing ledger movement (phantom stock identity),
- duplicate the batch-creation logic that already lives inside the stock-in services,
- diverge from the ledger-as-source-of-truth invariant.

Therefore Batch & Lot stays read-only; it lists and inspects batches that the stock-in flows produce.

## Correct operator workflow (after hotfix)

1. Receive purchased stock → **Goods Receipt** (item lines capture batch/lot/expiry).
2. Initialize stock for a product → **Opening Stock** (per-product, captures batch/lot/expiry).
3. Correct stock upward → **Adjustment In** / **Receive Stock** (capture batch/lot/expiry when "Buat Batch Baru").
4. Monitor the resulting batches on **Batch & Lot** (`/inventory/batches`).

The Batch & Lot index now carries a guidance card stating exactly this, with shortcut buttons to the existing stock-in entry points.

### Goods Receipt batch input steps

1. Open **Penerimaan Barang → Buat Penerimaan Barang** (from a sent Purchase Order).
2. For each item line, set Diterima Baik > 0. For batch-tracked products the **Batch / Lot** panel appears.
3. Choose **Batch Baru** and fill `Nomor Batch` (required), `Nomor Lot`, `Tanggal Terima Batch` (required), `Tanggal Kedaluwarsa`, or pick **Batch Ada**.
4. Post the Goods Receipt → batch appears on **Batch & Lot**.

Helper text on the form: *"Isi nomor batch/lot dan tanggal kedaluwarsa untuk barang yang dilacak batch atau expired. Setelah Goods Receipt diposting, batch akan muncul di halaman Batch & Lot."*

### Opening Stock batch input steps

1. Open a product → **Stok Awal**.
2. In the **Batch / Lot** section choose **Buat Batch Baru** and fill `Nomor Batch`, `Nomor Lot`, `Tanggal Terima`, `Tanggal Kedaluwarsa` (or select an existing batch).
3. Save → batch appears on **Batch & Lot**.

Helper text on the form: *"Untuk stok awal barang yang memiliki batch/expired, isi nomor batch/lot dan tanggal kedaluwarsa di sini. Batch akan otomatis tampil di halaman Batch & Lot setelah stok awal disimpan."*

### Adjustment In / Receive Stock note

Both already exposed batch fields (`includeBatch => true`). This hotfix only adds small helper text reminding operators that new batches surface on the Batch & Lot page after submission.

## What changed

| File | Change |
| --- | --- |
| `resources/views/inventory/batches/index.blade.php` | Added operator guidance card + `Route::has`-guarded shortcut buttons (Goods Receipt create, Produk, Penerimaan Barang). No create button added. |
| `resources/views/inventory/goods-receipts/_form.blade.php` | Added batch helper text under the "Item Penerimaan" heading. Existing batch fields/JS untouched. |
| `resources/views/inventory/stock/opening.blade.php` | Enabled `includeBatch` + passed `batches` and Indonesian `batchHelp`. |
| `resources/views/inventory/stock/receive.blade.php` | Added small `batchHelp`. |
| `resources/views/inventory/stock/adjust-in.blade.php` | Added small `batchHelp`. |
| `resources/views/inventory/stock/_operation-form.blade.php` | Forwarded optional `batchHelp` into the batch fields partial. |
| `resources/views/inventory/stock/_batch-fields.blade.php` | Renders optional `batchHelp` helper text; relabeled section "Batch / Lot". |
| `app/Modules/Inventory/Requests/StoreOpeningStockRequest.php` | Adopted `ValidatesInventoryBatchInput` (batch rules + `withValidator` parity with Receive/Adjustment). |
| `app/Modules/Inventory/Controllers/InventoryStockController.php` | `openingStock` now passes `batches`; `storeOpeningStock` now passes `$request->batchData()` into the existing service param. |
| `tests/Feature/Inventory/InventoryBatchLotUiVisibilityHotfixTest.php` | New — 11 tests. |
| `docs/hotfix_inventory_batch_lot_ui_visibility.md` | This document. |
| `docs/sprint_history.md` | Hotfix entry. |

## What did NOT change

- No manual Batch/Lot create/store route or button.
- No new or duplicate batch table; no schema migration (the existing `InventoryStockService::createOpeningStock` already accepted `$batchData`).
- No change to ledger-derived stock (source of truth unchanged).
- No change to RME, branch master, or payment/conversion logic.
- Goods Receipt / Receive / Adjustment JS row add/remove behavior unchanged.

## Testing evidence

- `php artisan test --filter=InventoryBatchLotUiVisibilityHotfixTest` → **11 passed (33 assertions)**.
- `php artisan test --filter=Inventory` → **1200 passed (6129 assertions)**.
- `vendor/bin/pint --test` → **passed**.
- `git diff --check` → clean.

## Future rule

Do **not** add a manual batch master create flow (create/store route or button on the Batch & Lot page) unless explicitly approved. Batch identity must continue to originate from stock-in transactions (Goods Receipt, Opening Stock, Receive Stock, Adjustment In) so that every batch is backed by a ledger movement.
