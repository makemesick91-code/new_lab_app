# Hotfix — Goods Receipt Create PO Batch Panel Rendering

## Problem

On the Goods Receipt **create-from-PO** page ("Buat Penerimaan Barang"), the Batch / Lot
panel did not appear for products that require batch tracking, even though the previous
hotfix (#67) had already gated the panel on `item.requires_batch_tracking` (not
`accepted_qty`).

Operators selecting a PO whose items map to batch-tracked products saw the item table but
**no** Batch / Lot inputs and **no** mandatory-batch notice, so they could not enter a batch
number before submitting / posting.

## VPS evidence

- PO `PO-20260624-4-000002` (`trx_purchase_orders.id = 2`, status `sent`) has 4 items.
- All four joined products require batch tracking:
  - COTTON ROLL (`product_id 35`) — `requires_batch_tracking = true`
  - ALKOHOL (`product_id 36`) — `requires_batch_tracking = true`
  - ASEPTIC GEL (`product_id 37`) — `requires_batch_tracking = true`
  - CAIRAN SPIRTUS (`product_id 42`) — `requires_batch_tracking = true`
- Browser console on the create page:

  ```js
  document.body.innerText.includes('Produk ini wajib batch') === false
  ```

  The notice text and the `name="items[index][batch_number]"` inputs were absent from the
  live DOM.

## Root cause

The desktop items table in `resources/views/inventory/goods-receipts/_form.blade.php` placed
**two sibling `<tr>` root elements** inside a single `<template x-for>`:

```blade
<tbody>
    <template x-for="(item, index) in items" :key="index">
        <tr> ...data row... </tr>
        <tr x-show="item.requires_batch_tracking"> ...batch panel... </tr>
    </template>
</tbody>
```

Alpine's `x-for` (like `x-if`) **requires a single root element per iteration**. With two
`<tr>` siblings, Alpine only cloned the first `<tr>` (the data row) into the DOM and silently
dropped the second `<tr>` — the one carrying the `_batch-item-fields` partial with the
mandatory notice and the `batch_number` / `lot_number` / `batch_received_date` / `expiry_date`
inputs.

This is why the **server HTML still contained** the markup (it lives inside the `<template>`
tag, so `assertSee` on the raw response found it) but the **live desktop DOM did not** — the
batch row was never rendered, so `document.body.innerText` did not include "Produk ini wajib
batch". The mobile block (`lg:hidden`) used a single `<article>` root and was unaffected, but
on a desktop viewport that block is hidden.

The data binding was **not** at fault: `GoodsReceiptService::buildPrefillItemsFromPurchaseOrder()`
already emits `requires_batch_tracking = (bool) product.requires_batch_tracking`, and the edit
flow eager-loads `items.product`.

## Fix

`resources/views/inventory/goods-receipts/_form.blade.php`

- Made the per-item **`<tbody>` the single `x-for` root**, wrapping both the data `<tr>` and
  the batch `<tr>`. A `<table>` may legally contain multiple `<tbody>` elements, so each item
  now renders its own `<tbody>` and Alpine clones the whole group:

  ```blade
  <template x-for="(item, index) in items" :key="index">
      <tbody class="divide-y divide-gray-100 bg-white">
          <tr> ...data row... </tr>
          <tr x-show="item.requires_batch_tracking"> ...batch panel... </tr>
      </tbody>
  </template>
  ```

- Added a hidden `items[index][requires_batch_tracking]` input (desktop + mobile) bound to
  `item.requires_batch_tracking ? 1 : 0`, so the flag round-trips through old-input
  re-render after a validation failure (the form previously did not resubmit it, which would
  collapse the panel on re-render). The value is ignored by the Store/Update request rules and
  never reaches the service `validated()` payload.

No change to `_batch-item-fields.blade.php`, the controller, the service, the request rules, or
the schema. Batch validation and posting behaviour are unchanged.

## Operator workflow (after fix)

1. Open **Penerimaan Barang → Buat Penerimaan Barang**.
2. Select the receivable PO (e.g. `PO-20260624-4-000002`).
3. Each batch-tracked item row now shows, directly **below** the item row, a teal
   **"Batch / Lot"** panel with the red notice:
   *"Produk ini wajib batch. Isi Nomor Batch sebelum Submit/Post Goods Receipt."*
4. Choose **Batch Ada** (pick an existing batch) or **Batch Baru**, then fill **Nomor Batch**
   (mandatory), optional **Nomor Lot**, **Tanggal Terima Batch**, and **Tanggal Kedaluwarsa**.
5. Save Draft → Submit/Post. Posting creates/links the `InventoryBatch` and the PURCHASE
   movement as before. Non-batch products show no batch panel and require no batch number.

### Browser-visible location of the Nomor Batch field

On the create-from-PO page, the **Nomor Batch** input renders inside the **Batch / Lot** panel
that sits in a full-width row **immediately beneath each batch-tracked product's data row** in
the "Item Penerimaan" table (and inside each item card on mobile widths). It only appears when
`item.requires_batch_tracking` is true and the **Batch Baru** mode is selected.

## Testing evidence

- `tests/Feature/Inventory/GoodsReceiptCreatePoBatchPanelRenderingHotfixTest.php` — **8 passed**.
  Covers: notice rendered, all four batch inputs present, `requires_batch_tracking":true`
  embedded in the Alpine payload, the single `<tbody>` x-for root regression guard, non-batch
  product not requiring a batch number, batch-tracked product blocked without a batch number,
  posting links an `InventoryBatch`, and absence of any `inventory.batches.create`/`store` route.
- `GoodsReceiptBatchFieldsVisibleHotfixTest` — 8 passed.
- `InventoryBatchLotUiVisibilityHotfixTest` — 11 passed.
- `--filter=Inventory` — **1216 passed (6178 assertions)**.
- `vendor/bin/pint --test` — passed.
- `git diff --check` — clean.

## No migration note

No migration was created or run. The `inv_products.requires_batch_tracking` column and all
batch tables already exist. This is a Blade-rendering hotfix only.

## No manual batch master note

No manual Inventory Batch create/store route was added. Batches are still created/linked only
as a side effect of posting a Goods Receipt. The `inventory.batches.create` /
`inventory.batches.store` routes remain absent (asserted by test).
