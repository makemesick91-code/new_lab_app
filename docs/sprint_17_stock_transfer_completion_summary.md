# Sprint 17 - Stock Transfer Hardening Completion Summary

Date: 2026-06-07
Status: Completed
Module: `app/Modules/Inventory`

## 1. Sprint Overview

Sprint 17 finalized the ADLMS Stock Transfer hardening track. The work reviewed the existing stock transfer workflow, documented the hardening design, added runtime ledger integrity guards, added a read-only PDF checklist, and completed the full quality gate.

Sprint 17 artifacts:

- Sprint 17.1: Stock Transfer audit.
- Sprint 17.2: hardening design.
- Sprint 17.3: runtime hardening.
- Sprint 17.4: checklist PDF.
- Sprint 17 completion summary.

The active workflow remains:

```text
draft -> submitted -> in_transit -> received
```

No migration was added. No stock transfer workflow rebuild was performed.

## 2. Why Sprint 17 Became Hardening Instead Of Rebuild

The Sprint 17.1 audit confirmed that Stock Transfer already existed as a mature Inventory workflow with models, requests, controller, service, repository, policy, Blade UI, seeders, routes, and tests.

Because the existing implementation already preserved the Sprint 15.2 two-phase transfer contract, Sprint 17 was intentionally scoped as hardening:

- Preserve current workflow instead of replacing it.
- Protect receive from corrupted or mismatched ledger states.
- Move show-page ledger reads into repository/service read layer.
- Add a read-only operational checklist PDF.
- Avoid future-sprint features such as partial receive, reversal, return, or inter-branch transfer.

## 3. Files Created/Changed

Created documentation:

- `docs/sprint_17_1_stock_transfer_audit.md`
- `docs/sprint_17_2_stock_transfer_hardening_design.md`
- `docs/sprint_17_3_stock_transfer_runtime_hardening.md`
- `docs/sprint_17_4_stock_transfer_checklist_pdf.md`
- `docs/sprint_17_stock_transfer_completion_summary.md`

Created application/test files:

- `resources/views/inventory/stock-transfers/checklist-pdf.blade.php`
- `tests/Feature/Inventory/StockTransferChecklistPdfTest.php`

Changed application files:

- `app/Modules/Inventory/Controllers/StockTransferController.php`
- `app/Modules/Inventory/Interfaces/InventoryMovementRepositoryInterface.php`
- `app/Modules/Inventory/Policies/Concerns/ChecksInventoryAccess.php`
- `app/Modules/Inventory/Policies/StockTransferPolicy.php`
- `app/Modules/Inventory/Repositories/InventoryMovementRepository.php`
- `app/Modules/Inventory/Repositories/StockTransferRepository.php`
- `app/Modules/Inventory/Services/StockTransferService.php`
- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`
- `resources/views/inventory/stock-transfers/show.blade.php`
- `routes/web.php`
- `tests/Feature/Inventory/StockTransferHardeningTest.php`
- `composer.json`
- `composer.lock`

## 4. Current Workflow

### draft

Draft transfer is document-only. It can be edited, submitted, or cancelled when authorized. It creates no inventory movements.

### submitted

Submitted transfer is ready to ship. Shipping is the first stock-impacting workflow action. Submitted transfer can still be cancelled before shipping.

### in_transit

Shipping moves the transfer to `in_transit` and creates `TRANSFER_OUT` ledger rows at the source location only. Cancel is blocked after this point. Checklist PDF download is allowed.

### received

Receiving moves the transfer to `received` and creates `TRANSFER_IN` ledger rows at the destination location only. The transfer becomes terminal/read-only for normal workflow purposes. Checklist PDF download remains allowed.

Legacy `completed` status remains treated as received through the existing compatibility path.

## 5. Ledger Rules

- `TRANSFER_OUT` is created during `shipTransfer()`.
- `TRANSFER_IN` is created during `receiveTransfer()`.
- `submitTransfer()` does not mutate stock.
- Checklist PDF download does not mutate stock.
- Stock remains derived from `trx_inventory_movements`.
- Current stock formula remains `SUM(quantity_in) - SUM(quantity_out)`.
- No mutable `stock`, `current_stock`, `qty_on_hand`, or similar persisted stock column was added.

## 6. Runtime Hardening

Sprint 17.3 hardened `StockTransferService::receiveTransfer()`:

- Receive requires valid existing `TRANSFER_OUT` ledger rows.
- Receive rejects missing outbound transfer ledger.
- Receive rejects mismatched product, batch, location, branch, reference type, reference id, or quantity.
- Receive rejects corrupted outbound rows where `quantity_out <= 0` or `quantity_in != 0`.
- Duplicate `TRANSFER_IN` is blocked even if a transfer is still marked `in_transit`.
- Proposed full receive is checked against existing outbound totals.
- Failed integrity checks create no `TRANSFER_IN` rows and leave status unchanged.

## 7. Controller/Read-Layer Hardening

`StockTransferController::show()` no longer performs direct `InventoryMovement::query()` reads for transfer ledger references.

The read path now goes through:

- `InventoryMovementRepositoryInterface::transferMovements(...)`
- `InventoryMovementRepositoryInterface::lockTransferMovementsForUpdate(...)`
- `StockTransferService::getTransferMovementSummary(...)`

The show page still receives `ledgerMovements`, but the query ownership moved to repository/service read layer.

## 8. Checklist PDF

Package:

- `barryvdh/laravel-dompdf v3.1.2`

Route:

- `GET inventory/stock-transfers/{stockTransfer}/checklist`
- Route name: `inventory.stock-transfers.checklist`

Permission:

- `download_stock_transfer_checklist`

Policy:

- `StockTransferPolicy::downloadChecklist(...)`
- Requires the new permission and active-branch ownership.

Allowed status:

- `in_transit`
- `received`
- legacy `completed` via `StockTransfer::isReceived()`

Not allowed:

- `draft`
- `submitted`
- `cancelled`

No stock mutation:

- Does not create inventory movements.
- Does not call `shipTransfer()`.
- Does not call `receiveTransfer()`.
- Does not update transfer status.
- Does not update product, location, batch, or stock columns.

## 9. Permission Added

Added permission:

```text
download_stock_transfer_checklist
```

Seeder updates:

- Added to `PermissionSeeder::PERMISSIONS`.
- Assigned to `Admin Lab` in `RoleSeeder`.
- `Super Admin` continues to receive all permissions through the existing all-permissions seeder path.
- No new role was forced for Admin Cabang or Staff Inventory.

## 10. Tests Added/Updated

Added:

- `tests/Feature/Inventory/StockTransferChecklistPdfTest.php`

Updated:

- `tests/Feature/Inventory/StockTransferHardeningTest.php`

Coverage added or confirmed:

- Authorized PDF download succeeds and returns `application/pdf`.
- Guest users are redirected to login.
- Users without permission are forbidden.
- Cross-branch download is forbidden.
- Download is allowed only for `in_transit` and `received`.
- PDF button is permission/status gated on show page.
- Download creates no inventory movement.
- Download does not change transfer status.
- Admin Lab receives `download_stock_transfer_checklist`.
- Receive rejects missing `TRANSFER_OUT`.
- Receive rejects mismatched outbound ledger.
- Receive rejects existing `TRANSFER_IN` on an `in_transit` transfer.
- Movement summary returns outbound, inbound, and in-transit totals.
- Show page uses read-layer path instead of direct controller movement query.

## 11. Quality Gate Result

All requested gates passed.

```text
composer validate
Result: passed
Duration: 00:00:01.1697247

php artisan route:list
Result: passed
Routes shown: 231
Duration: 00:00:00.7886599

php artisan test --filter=StockTransfer
Result: passed
Tests: 115 passed
Assertions: 549
Duration: 00:00:36.0996793

php artisan test --filter=Inventory
Result: passed
Tests: 919 passed
Assertions: 3699
Duration: 00:03:50.1927229

php artisan test
Result: passed
Tests: 1307 passed
Assertions: 4634
Duration: 00:05:26.4766255

npm run build
Result: passed
Vite modules transformed: 56
Duration: 00:00:02.6025071

vendor/bin/pint --test
Result: passed
Duration: 00:00:02.1100284

git diff --check
Result: passed
Duration: 00:00:00.0778135
```

Final pre-commit checks also confirmed:

- `composer.json` and `composer.lock` are included in the changed file set.
- Sprint docs 17.1, 17.2, 17.3, 17.4, and this completion summary are included.
- Temporary/cache/log artifacts detected locally after test/build were removed before commit.

## 12. Manual Browser Test Checklist

Recommended browser checks before deployment sign-off:

- Login as Admin Lab.
- Open Inventory -> Transfer Stok.
- Open an `in_transit` transfer detail page.
- Confirm `Download Checklist PDF` button is visible.
- Download checklist PDF and confirm file opens.
- Confirm PDF content shows transfer number, branch, source location, destination location, status, users, item rows, batch/lot when present, checklist columns, and signature areas.
- Open a `received` transfer and confirm checklist download remains visible.
- Open a `draft` transfer and confirm checklist button is hidden.
- Open a `submitted` transfer and confirm checklist button is hidden.
- Login as a user without `download_stock_transfer_checklist` and confirm checklist button is hidden.
- Attempt direct checklist route as unauthorized user and confirm 403.
- Attempt another-branch transfer direct route and confirm 403.
- Confirm no stock movement is created by downloading the PDF.
- Confirm normal ship and receive workflow still works.

## 13. Deployment Notes

- Run `composer install` because `composer.lock` changed.
- `php artisan migrate` is not expected because Sprint 17 added no migration.
- Run `php artisan db:seed --class=PermissionSeeder --force`.
- Run `php artisan db:seed --class=RoleSeeder --force` if safe/needed for the target environment.
- Run `php artisan optimize:clear`.
- Run `php artisan config:cache`.
- Run `php artisan route:cache`.
- Run `php artisan view:cache`.
- Run `npm run build` if frontend assets changed or the deployment process rebuilds assets.

## 14. Remaining Out Of Scope

The following remain explicitly out of scope:

- Partial receive.
- Transfer reversal.
- Transfer return.
- Inter-branch transfer schema.
- Digital signature.
- Barcode/QR scan.
- Offline/PWA receive.
- Transfer discrepancy workflow.
- New stock transfer migrations.
- Mutable stock/current stock columns.
