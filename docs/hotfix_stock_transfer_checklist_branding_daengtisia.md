# Hotfix — Stock Transfer Checklist Branding (Daengtisia)

**Date:** 2026-06-24
**Branch:** `hotfix/stock-transfer-checklist-branding-daengtisia`
**Type:** Branding / cosmetic — no business logic change.

## Problem

The downloaded/printed **"Checklist Pengiriman Barang Antar Lokasi"** still displayed the
legacy brand text **`ASIA DENTAL LAB`** in the document header. It must show the current
system brand **`DAENGTISIA MANAGEMENT SYSTEM`**.

## Root Cause

The checklist PDF template hardcoded the old brand string instead of deriving it from the
application configuration.

- File: `resources/views/inventory/stock-transfers/checklist-pdf.blade.php`
- Line (header `.brand`): `<p class="brand">Asia Dental Lab</p>`

The `.brand` CSS already applies `text-transform: uppercase`, so the rendered output read
`ASIA DENTAL LAB`.

## Fix

Replaced the hardcoded brand with a configuration-driven, safe Blade expression:

```blade
<p class="brand">{{ strtoupper(config('app.name', 'Daengtisia Management System')) }}</p>
```

`config('app.name')` resolves from `APP_NAME` (set to `"Daengtisia Management System"` in
`.env`), with an explicit safe default. Combined with the existing uppercase styling the
header now renders **`DAENGTISIA MANAGEMENT SYSTEM`**.

## Route / View Fixed

- **Route:** `inventory.stock-transfers.checklist`
  (`GET /inventory/stock-transfers/{stockTransfer}/checklist` →
  `StockTransferController@downloadChecklist`)
- **View:** `resources/views/inventory/stock-transfers/checklist-pdf.blade.php`

## Tests

Added a feature test to `tests/Feature/Inventory/StockTransferChecklistPdfTest.php`:

- Builds real stock transfer test data (branch, locations, product, transfer item).
- Renders the actual checklist view used by the download route.
- Asserts the output **contains** `DAENGTISIA MANAGEMENT SYSTEM`.
- Asserts the output **does not contain** `ASIA DENTAL LAB` / `Asia Dental Lab`.

> The download route returns a compressed binary PDF, so text assertions are performed
> against the rendered Blade view that the route loads via `Pdf::loadView(...)`. All
> existing route/permission/branch-isolation tests remain unchanged and passing.

## Validation

- `php artisan test --filter=StockTransfer` → **116 passed (552 assertions)**
- `php artisan test --filter=Inventory` → **1217 passed (6181 assertions)**
- `vendor/bin/pint --test` → **passed**
- `git diff --check` → **clean**

## No Migration

**No migration.** This change is template/text only. No schema change, no stock transfer
lifecycle change, no inventory ledger change, no branch master change, no route redesign,
no RME change.

## Browser / Download Verification

1. Log in as a user with `download_stock_transfer_checklist` (e.g. Admin Lab).
2. Open an `in_transit` or `received` stock transfer:
   `GET /inventory/stock-transfers/{id}`.
3. Click **"Download Checklist PDF"**.
4. Confirm the PDF header reads **`DAENGTISIA MANAGEMENT SYSTEM`** and no longer shows
   `ASIA DENTAL LAB`.
