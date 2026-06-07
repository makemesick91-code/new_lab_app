# Sprint 17.4 - Stock Transfer Checklist PDF

Date: 2026-06-07
Status: Implemented
Module: `app/Modules/Inventory`

## 1. Overview

Sprint 17.4 adds a downloadable PDF checklist for the existing Stock Transfer workflow.

The checklist is a physical inspection document for same-branch, inter-location transfers. It is available only for transfers that are already:

- `in_transit`
- `received`

The existing workflow is preserved:

```text
draft -> submitted -> in_transit -> received
```

## 2. Package PDF Yang Digunakan

Installed package:

```text
barryvdh/laravel-dompdf v3.1.2
```

Implementation uses:

```php
Barryvdh\DomPDF\Facade\Pdf
```

The PDF is rendered from a Blade view. No external API, browser screenshot, or external asset is used.

## 3. Route

New route:

```text
GET inventory/stock-transfers/{stockTransfer}/checklist
```

Route name:

```text
inventory.stock-transfers.checklist
```

The route is inside the existing authenticated inventory route group in `routes/web.php`.

## 4. Controller Method

Controller:

```text
app/Modules/Inventory/Controllers/StockTransferController.php
```

Method:

```php
downloadChecklist(StockTransfer $stockTransfer)
```

Behavior:

- Calls `authorize('downloadChecklist', $stockTransfer)`.
- Allows PDF generation only for `in_transit` or `received` transfers.
- Loads branch, source/destination locations, items, products, units, batch, requested/created/shipped/received users.
- Renders `resources/views/inventory/stock-transfers/checklist-pdf.blade.php`.
- Downloads `stock-transfer-checklist-{transfer_number}.pdf`.
- Does not call `shipTransfer()`.
- Does not call `receiveTransfer()`.
- Does not update the database.

## 5. Permission

New permission:

```text
download_stock_transfer_checklist
```

Seeder changes:

- Added to `PermissionSeeder::PERMISSIONS`.
- Assigned to `Admin Lab` in `RoleSeeder`.
- `Super Admin` receives it through the existing all-permissions role assignment.

Branch staff can be granted this permission through the existing role/permission management flow. The policy still requires active-branch ownership.

## 6. Policy Rule

Policy:

```text
app/Modules/Inventory/Policies/StockTransferPolicy.php
```

New method:

```php
downloadChecklist(User $user, StockTransfer $stockTransfer)
```

Rules:

- User must have `download_stock_transfer_checklist`.
- Transfer must belong to the active branch resolved by `BranchContext`.
- Cross-branch transfer downloads are denied.
- Existing view policy is not loosened.

## 7. PDF Content

PDF view:

```text
resources/views/inventory/stock-transfers/checklist-pdf.blade.php
```

Content includes:

- Asia Dental Lab header.
- Checklist Pengiriman Barang Antar Lokasi title.
- Transfer number, status, transfer date, created/shipped/received dates.
- Branch, source location, destination location.
- Created/requested/shipped/received user names when available.
- Item table with product code/SKU, product name, unit, shipped quantity, batch/lot, expiry, received checkbox, manual received quantity, condition, and notes.
- Physical inspection footer warning.
- Signature areas for sender, receiver, inspector, and inspection date.

## 8. Status Yang Boleh Download

Allowed:

- `in_transit`
- `received`
- legacy `completed` through `StockTransfer::isReceived()`

Not allowed:

- `draft`
- `submitted`
- `cancelled`

The show-page button follows the same status scope.

## 9. No Stock Mutation Guarantee

The checklist route is read-only.

It does not:

- Create `trx_inventory_movements`.
- Run `shipTransfer()`.
- Run `receiveTransfer()`.
- Change transfer status.
- Add mutable stock columns.
- Calculate or mutate product/location stock.

Inventory stock remains derived only from:

```text
SUM(quantity_in) - SUM(quantity_out)
```

## 10. Tests Added

Added:

```text
tests/Feature/Inventory/StockTransferChecklistPdfTest.php
```

Coverage:

- Authorized download succeeds.
- Response is PDF.
- Download filename contains transfer number.
- Guest user is redirected to login.
- User without permission is denied.
- Cross-branch user is denied.
- Download creates no inventory movement.
- Download does not change transfer status.
- Button appears for authorized users on `in_transit`.
- Button appears for authorized users on `received`.
- Button is hidden for unauthorized users.
- Button is hidden and route blocked for `draft` / `submitted`.
- Admin Lab receives the new permission through the role seeder.

## 11. Quality Gate Result

Commands run:

```bash
composer require barryvdh/laravel-dompdf
php artisan test --filter=StockTransferChecklistPdfTest
php artisan test --filter=StockTransfer
php artisan test --filter=Inventory
php artisan route:list
npm run build
vendor/bin/pint --test
git diff --check
```

Results:

- `composer require barryvdh/laravel-dompdf` passed and updated `composer.json` / `composer.lock`.
- `php artisan test --filter=StockTransferChecklistPdfTest` passed: 13 tests, 26 assertions.
- `php artisan test --filter=StockTransfer` passed: 115 tests, 549 assertions.
- `php artisan test --filter=Inventory` passed: 919 tests, 3699 assertions.
- `php artisan route:list` passed and showed 231 routes including `inventory.stock-transfers.checklist`.
- `npm run build` passed.
- `vendor/bin/pint --test` passed.
- `git diff --check` passed.

## 12. Remaining Out Of Scope

Still out of scope:

- Partial receive.
- Reversal after shipped.
- Inter-branch transfer.
- Digital signature.
- Barcode/QR scan.
- Transfer discrepancy workflow.
- New migrations or schema changes.
