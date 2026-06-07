# Sprint 17.1 - Stock Transfer Audit

Date: 2026-06-07
Status: Audit documentation only
Module: `app/Modules/Inventory`

## Scope

This audit reviews the existing ADLMS Inventory module for stock transfer, inventory movement, branch isolation, permissions, tests, UI, and PDF package readiness. No business logic, route, migration, UI, or test code was changed in this step.

Primary rules applied:

- ADLMS is a Laravel modular monolith.
- Inventory stock remains ledger-derived only.
- No mutable stock/current stock columns may be added to products, locations, batches, or transfer lines.
- Current stock is calculated from `trx_inventory_movements`: `SUM(quantity_in) - SUM(quantity_out)`.
- Branch isolation is mandatory and must use `BranchContext` for branch-owned operations.

## Existing Inventory Structure

Inventory is implemented as a full module under `app/Modules/Inventory` with the expected ADLMS layers:

- Controllers: `InventoryStockController`, `StockTransferController`, `StockOpnameController`, `GoodsReceiptController`, analytics/dashboard/controllers, and master-data controllers.
- Requests: Form Requests exist for stock operations, transfer workflow actions, purchase/procurement, analytics filters, and inventory activity logs.
- Services: business logic is centralized in services such as `InventoryStockService`, `StockTransferService`, `StockOpnameService`, `GoodsReceiptService`, `InventoryAlertService`, and analytics services.
- Repositories and interfaces: Inventory uses repository interfaces and concrete repositories, including `InventoryMovementRepositoryInterface`, `StockTransferRepositoryInterface`, and branch-scoped repository methods.
- Models: `InventoryMovement`, `StockTransfer`, `StockTransferItem`, `InventoryLocation`, `Product`, `Supplier`, `InventoryBatch`, stock opname, procurement, and activity log models.
- Policies: Inventory has model policies and a shared `ChecksInventoryAccess` concern.
- Views: Blade views exist under `resources/views/inventory`, including stock transfers, stock cards, stock operation forms, dashboard, analytics, activity logs, procurement, batches, and alerts.

Stock transfer is already implemented. It is not a missing feature.

Existing stock transfer files:

- `app/Modules/Inventory/Models/StockTransfer.php`
- `app/Modules/Inventory/Models/StockTransferItem.php`
- `app/Modules/Inventory/Services/StockTransferService.php`
- `app/Modules/Inventory/Repositories/StockTransferRepository.php`
- `app/Modules/Inventory/Interfaces/StockTransferRepositoryInterface.php`
- `app/Modules/Inventory/Controllers/StockTransferController.php`
- `app/Modules/Inventory/Policies/StockTransferPolicy.php`
- `app/Modules/Inventory/Requests/StoreStockTransferRequest.php`
- `app/Modules/Inventory/Requests/UpdateStockTransferRequest.php`
- `app/Modules/Inventory/Requests/SubmitStockTransferRequest.php`
- `app/Modules/Inventory/Requests/ShipStockTransferRequest.php`
- `app/Modules/Inventory/Requests/ReceiveStockTransferRequest.php`
- `app/Modules/Inventory/Requests/CancelStockTransferRequest.php`
- `app/Modules/Inventory/Requests/Concerns/ValidatesStockTransferInput.php`
- `resources/views/inventory/stock-transfers/*`
- `tests/Feature/Inventory/StockTransfer*.php`

Existing transfer tables:

- `trx_stock_transfers`
- `trx_stock_transfer_items`

Stock transfer migrations:

- `database/migrations/2026_06_06_140200_create_trx_stock_transfers_table.php`
- `database/migrations/2026_06_06_140201_create_trx_stock_transfer_items_table.php`
- `database/migrations/2026_06_06_200001_add_ship_columns_to_trx_stock_transfers_table.php`
- `database/migrations/2026_06_06_220000_add_inventory_batch_id_to_trx_stock_transfer_items_table.php`
- `database/migrations/2026_06_07_160000_alter_inventory_quantity_columns_to_decimal_18_4.php`

## Existing Stock Movement Rules

The inventory movement source-of-truth table is:

```text
trx_inventory_movements
```

The movement model is `app/Modules/Inventory/Models/InventoryMovement.php`.

Current movement fields include:

- `branch_id`
- `inventory_location_id`
- `product_id`
- `supplier_id`
- `inventory_batch_id`
- `movement_type`
- `movement_date`
- `quantity_in`
- `quantity_out`
- `unit_cost`
- `reference_type`
- `reference_id`
- `notes`
- `created_by`

Current movement types:

- `OPENING`
- `PURCHASE`
- `ADJUSTMENT_IN`
- `ADJUSTMENT_OUT`
- `TRANSFER_IN`
- `TRANSFER_OUT`

Stock is calculated through repository methods such as:

- `InventoryMovementRepository::currentStock(int $branchId, int $productId, ?int $locationId = null)`
- `InventoryMovementRepository::currentStockByBatch(int $branchId, int $productId, int $locationId, int $batchId)`
- `InventoryMovementRepository::stockCard(...)`
- `InventoryMovementRepository::stockRows(...)`
- `InventoryMovementRepository::inventoryValue(...)`

These methods scope by `branch_id` and calculate derived stock using `SUM(quantity_in) - SUM(quantity_out)`.

Stock transfer workflow currently uses the Sprint 15.2 two-phase flow:

```text
draft -> submitted -> in_transit -> received
draft/submitted -> cancelled
```

Ledger behavior:

- `submitTransfer()` changes document status only. No inventory movement is created.
- `shipTransfer()` posts `TRANSFER_OUT` rows at the source location only and changes status to `in_transit`.
- `receiveTransfer()` posts `TRANSFER_IN` rows at the destination location only and changes status to `received`.
- `cancelTransfer()` changes document status only and is blocked after `in_transit`.

Transfer movement metadata:

- `branch_id` comes from the transfer header, originally created from `BranchContext::requireId()`.
- `inventory_location_id` is source for `TRANSFER_OUT`, destination for `TRANSFER_IN`.
- `reference_type` is `trx_stock_transfers`.
- `reference_id` is the transfer id.
- `unit_cost` uses product `average_cost`.
- `inventory_batch_id` is propagated from `trx_stock_transfer_items` when present.

Important compatibility note:

- `StockTransfer::STATUS_COMPLETED = completed` remains as a deprecated compatibility constant.
- `isReceived()` treats both `received` and legacy `completed` as received.
- New workflow uses `received`; the old `complete` route is absent.

## Existing Branch Access Pattern

The active branch resolver is:

```text
app/Modules/Branch/Services/BranchContext.php
```

Inventory services use `BranchContext::requireId()` for branch-owned operations. Existing examples:

- `InventoryStockService`
- `StockTransferService`
- `InventoryActivityLogService`
- Dashboard and analytics services/controllers

Stock transfer branch rules found in code:

- `StockTransferService::createTransfer()` resolves branch via `BranchContext::requireId()`.
- Transfer header `branch_id` is stamped server-side.
- Source and destination locations are validated as active and in the active branch.
- Products are validated as active and in the active branch.
- Batch references are validated as active, same branch, and matching product.
- `StockTransferRepository::paginate()` and `findById()` require `int $branchId` and apply `where('branch_id', $branchId)`.
- `StockTransferPolicy` checks `belongsToActiveBranch($stockTransfer->branch_id)` for model actions.
- HTTP route model binding is followed by `$this->authorize(...)` calls in `StockTransferController`.

Pattern caveat:

- `StockTransferController::show()` directly queries `InventoryMovement` for ledger references. It scopes by `branch_id`, `reference_type`, and `reference_id`, so branch isolation is present. However, as a layering hardening item, this could be moved to `InventoryMovementRepository` or `StockTransferRepository` in a future cleanup to keep controllers thinner.

## Existing Permission Pattern

Inventory permissions are seeded in:

- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`

Current inventory transfer permissions:

- `view_stock_transfer`
- `manage_stock_transfer`

Fallback permissions in `ChecksInventoryAccess`:

- View transfer: `view_stock_transfer`, `manage_stock_transfer`, `view_inventory`, `manage_inventory`, `manage master data`
- Manage transfer: `manage_stock_transfer`, `manage_inventory`, `manage master data`

Role seeder observation:

- `Super Admin` receives all permissions from `PermissionSeeder::PERMISSIONS`.
- `Admin Lab` currently has broad inventory permissions (`manage_inventory`, `view_inventory`) and inventory analytics/activity permissions, but not every granular inventory permission is explicitly listed. Because policies include `manage_inventory` fallback, Admin Lab can still manage stock transfers.
- Other roles such as Technician and Quality Control have `view_inventory`, which allows view-level inventory access through fallback policies.

Routes:

- Inventory routes are grouped under `auth` in `routes/web.php`.
- Stock transfer routes rely on controller policy authorization rather than route-level Spatie permission middleware.
- Current route names include:
  - `inventory.stock-transfers.index`
  - `inventory.stock-transfers.create`
  - `inventory.stock-transfers.store`
  - `inventory.stock-transfers.show`
  - `inventory.stock-transfers.edit`
  - `inventory.stock-transfers.update`
  - `inventory.stock-transfers.submit`
  - `inventory.stock-transfers.ship`
  - `inventory.stock-transfers.receive`
  - `inventory.stock-transfers.cancel`
- `inventory.stock-transfers.complete` is intentionally absent.

Policy registration:

- `StockTransfer::class => StockTransferPolicy::class` is registered in `RepositoryServiceProvider`.
- `StockTransferRepositoryInterface::class => StockTransferRepository::class` is bound in `RepositoryServiceProvider`.

## Existing Test Pattern

Inventory tests live under:

```text
tests/Feature/Inventory
```

Stock transfer test files:

- `StockTransferModelTest.php`
- `StockTransferRequestTest.php`
- `StockTransferPolicyTest.php`
- `StockTransferControllerTest.php`
- `StockTransferServiceTest.php`
- `StockTransferHardeningTest.php`
- `StockTransferUiTest.php`
- `StockTransferBatchTest.php`

Coverage patterns found:

- Model relationships, fillable fields, casts, status helpers, status constants.
- Form Request validation for create/update, including same-location rejection, item requirement, positive quantities, branch-safe locations, active product, and cross-branch product rejection.
- Empty-body request validation for submit, ship, and receive actions.
- Policy permissions for view, manage, legacy `manage master data`, and cross-branch denial.
- Controller route registration, HTTP authorization, cross-branch route denial, status transitions, redirects, and flash messages.
- Service happy path for draft creation, submit, ship, receive, and cancel.
- Ledger correctness for `TRANSFER_OUT` and `TRANSFER_IN`.
- Source stock sufficiency validation at ship.
- No movement on failed ship.
- No mutable stock columns on products, locations, batches, or transfer items.
- Batch propagation into transfer movements.
- UI button visibility and ledger panel visibility.
- Authentication and unauthorized user denial.

Test pattern is mature and should be preserved. Future Step 17.x work should add focused regression tests rather than replacing this suite.

## Existing Blade UI Pattern

Stock transfer views exist under:

```text
resources/views/inventory/stock-transfers
```

Files:

- `_form.blade.php`
- `_status-badge.blade.php`
- `index.blade.php`
- `create.blade.php`
- `edit.blade.php`
- `show.blade.php`

UI patterns found:

- Uses `<x-settings-shell>`.
- Uses teal primary actions.
- Uses permission-gated actions via `@can`.
- `index.blade.php` has filter card, desktop table, mobile card layout, pagination, and empty state.
- `_status-badge.blade.php` maps `draft`, `submitted`, `in_transit`, `received`, `completed`, and `cancelled` to Indonesian labels and semantic colors.
- `show.blade.php` shows workflow buttons:
  - Draft: submit, edit, cancel when authorized.
  - Submitted: ship and cancel when authorized.
  - In transit: receive when authorized.
  - Received/cancelled: read-only workflow surface.
- Ledger panel appears for `in_transit` and `received` when movements exist.
- `_form.blade.php` lists active branch locations and products provided by controller/service, and uses Alpine for item lines and batch options.

Sidebar pattern:

- `resources/views/layouts/sidebar.blade.php` includes a single `Transfer Stok` link.
- The link is gated with `@can('viewAny', StockTransfer::class)`.
- This matches Sprint 15.2 guidance: receiving remains an action on the transfer detail page, not a separate menu item.

UI caveat:

- Some labels still use direct text without icons, which is acceptable for current ADLMS Blade conventions.
- The form partial uses a nested repeated item card. This is currently a practical form pattern and is already covered by UI tests.

## PDF Capability Check

Composer package check:

- `composer.json` does not require `barryvdh/laravel-dompdf`.
- `composer.json` does not require `knplabs/knp-snappy` or Laravel Snappy wrapper.
- `composer.json` does not require `mpdf/mpdf`.
- Repository search found PDF mentioned in documentation and upload validation, but no installed PDF rendering package or PDF facade/provider usage.

`composer.lock` contains an author reference to `barryvdh` through `fruitcake/php-cors`, not a DomPDF package. This is not PDF capability.

Conclusion:

- No PDF generation package is currently installed.
- Do not install a PDF package in Step 17.1.

Recommended package if Step 17.x needs transfer print/PDF:

- `barryvdh/laravel-dompdf` is the safest first candidate for ADLMS because it is Laravel-oriented, common for Blade-to-PDF documents, and does not require external binaries.
- Snappy/wkhtmltopdf is more operationally fragile on Windows/server deployments because it depends on an external binary.
- mPDF can be considered if Indonesian typography or complex print layout needs outgrow DomPDF, but it is heavier.

## Gap Analysis For Stock Transfer

Because stock transfer already exists, the main gaps are hardening and documentation gaps, not foundational implementation gaps.

Confirmed implemented:

- Stock transfer table/model/controller/service/repository/request/policy/views/tests exist.
- Two-phase ship/receive workflow exists.
- `TRANSFER_OUT` and `TRANSFER_IN` movement types exist.
- Stock remains ledger-derived from `trx_inventory_movements`.
- Same-branch inter-location transfer is enforced.
- Batch-aware transfer lines and movement propagation exist.
- Granular stock transfer permissions exist, with broader inventory fallbacks.
- Transfer activity logging exists through `inv_inventory_activity_logs`.

Potential gaps or hardening candidates:

1. Controller ledger query layering
   - `StockTransferController::show()` directly queries `InventoryMovement`.
   - It is branch-scoped and safe, but future hardening can move this lookup into `InventoryMovementRepository` or `StockTransferRepository`.

2. Route-level permission middleware consistency
   - Inventory transfer routes rely on controller policies after `auth`.
   - This is currently covered by tests, but future hardening could add route-level permission middleware if ADLMS wants double protection consistently.

3. Receive idempotency integrity guard
   - `receiveTransfer()` blocks duplicate receive by status.
   - The Sprint 15.2 design mentioned optionally checking whether ship movements exist before receive. Current service does not explicitly assert a `TRANSFER_OUT` row exists before receive; status `in_transit` is treated as sufficient.
   - A future hardening step could add a repository-backed guard and regression test for corrupted `in_transit` transfers without OUT movements.

4. Transfer number generation
   - Current service uses `TRF-{YYYYMM}-{random}`.
   - This is acceptable with DB unique constraint but not sequential. If product needs operator-friendly sequence numbers, design a branch-scoped sequence generator carefully and test collisions.

5. Receive naming
   - `approved_by` and `completed_at` are retained as receive actor/time for compatibility.
   - UI labels show receive semantics. A future migration could rename to `received_by` and `received_at`, but this is not necessary unless approved because it touches schema and historical compatibility.

6. Transfer PDF or print document
   - No PDF package is installed.
   - If a future step requires transfer slip PDF, add a PDF package intentionally with tests and a Blade print template.

7. Partial receive and reversal
   - Not implemented and intentionally out of scope.
   - Current receive posts full line quantities.
   - Cancel after ship is blocked; no reversal workflow exists for in-transit transfers.

8. Inter-branch transfer
   - Not implemented and should remain out of scope unless explicitly designed.
   - Current transfer is same-branch only.

## Proposed Implementation Plan

### Step 17.2 - Stock transfer hardening audit fixes

Recommended scope:

- Move transfer ledger movement lookup out of `StockTransferController::show()` into a repository method.
- Add or update tests proving the show page still displays only branch-scoped movements.
- Consider an explicit receive guard requiring existing `TRANSFER_OUT` rows before posting `TRANSFER_IN`.
- Do not add tables or mutable stock columns.
- Do not reintroduce a single-step `complete` route.

Likely files:

- `app/Modules/Inventory/Interfaces/InventoryMovementRepositoryInterface.php`
- `app/Modules/Inventory/Repositories/InventoryMovementRepository.php`
- `app/Modules/Inventory/Controllers/StockTransferController.php`
- `app/Modules/Inventory/Services/StockTransferService.php` only if adding receive integrity guard.
- `tests/Feature/Inventory/StockTransferControllerTest.php`
- `tests/Feature/Inventory/StockTransferServiceTest.php`
- `tests/Feature/Inventory/StockTransferHardeningTest.php`

### Step 17.3 - Transfer document print/PDF design

Recommended scope:

- Create a design doc first for transfer slip content, permissions, route, storage policy, and package choice.
- If PDF is approved, install `barryvdh/laravel-dompdf`.
- Add a read-only transfer print/PDF route protected by `view_stock_transfer` or policy `view`.
- Render from a Blade template with branch-scoped transfer details and ledger references.
- Do not write to stock or create inventory movements.

Likely files:

- `composer.json` and `composer.lock` only after explicit install approval.
- `app/Modules/Inventory/Controllers/StockTransferPrintController.php` or a method on `StockTransferController`.
- `resources/views/inventory/stock-transfers/print.blade.php`
- `tests/Feature/Inventory/StockTransferPdfTest.php` or controller test.

### Step 17.4 - Transfer export and activity trace

Recommended scope:

- Add CSV/export only if requested.
- Use branch-scoped repository queries.
- Include transfer number, source, destination, status, item count, shipped/received actors, and ledger movement references.
- Keep activity log as audit/read model, not KPI source.

### Step 17.5 - Optional workflow extensions

Only if explicitly requested and approved:

- Partial receive.
- In-transit reversal/void workflow.
- Transfer discrepancy handling.
- Inter-branch transfer.
- Separate permissions for ship and receive.

Each of these requires a technical design, migrations if needed, service transactions, branch isolation tests, ledger correctness tests, and UI tests.

## Risks And Constraints

Must preserve:

- `trx_inventory_movements` as the stock source of truth.
- Current stock formula: `SUM(quantity_in) - SUM(quantity_out)`.
- Same-branch transfer behavior.
- Two-phase ship/receive workflow.
- No `complete` route or paired OUT+IN single action.
- No mutable stock/current stock columns on `inv_products`, `inv_inventory_locations`, `inv_inventory_batches`, or transfer tables.
- Branch-scoped repository queries.
- Policy checks after route model binding.
- Batch stock validation at source during ship.
- Cancel blocked after `in_transit`.
- UI permission gates and mobile responsiveness.
- Existing Sprint 15.2, 15.3, 16.x contracts.

Risks to watch:

- Adding PDF package without approval would alter dependencies and deployment assumptions.
- Renaming `approved_by`/`completed_at` without migration planning could break compatibility.
- Adding route-level middleware without matching policy tests could create inconsistent denials.
- Implementing partial receive or reversal casually could create duplicate or missing ledger movements.
- Querying ledger movements from controllers is safe today but should not expand as a pattern.
- Summary/reporting tables from Sprint 16.8 must remain read models only and never replace ledger stock.

## Files Reviewed

Documentation and project context:

- `AGENTS.md`
- `docs/project_rules.md`
- `docs/system_architecture.md`
- `docs/architecture_rules.md`
- `docs/ai_development_guide.md`
- `docs/inventory_rules.md`
- `docs/ui_design_system.md`
- `docs/sprint_history.md`
- `docs/ai_bootstrap_prompt.md`
- `docs/sprint_15_2_transfer_receiving_design.md`
- `docs/sprint_16_8_analytics_optimization_design.md`
- `.cursor/snippets/adlms_master_workflow.md`
- `.cursor/memory/project.md`
- `.cursor/memory/inventory.md`
- `.cursor/memory/branching.md`
- `.cursor/memory/sprint-roadmap-13-20.md`

Application files:

- `app/Modules/Inventory`
- `app/Modules/Branch/Services/BranchContext.php`
- `app/Providers/RepositoryServiceProvider.php`
- `routes/web.php`
- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`
- `resources/views/inventory`
- `resources/views/layouts/sidebar.blade.php`
- `database/migrations/*inventory*`
- `database/migrations/*stock_transfer*`
- `tests/Feature/Inventory`
- `composer.json`
- `composer.lock`

## Step 17.1 Quality Check Results

Requested commands:

```bash
php artisan test --filter=Inventory
vendor/bin/pint --test
```

Results:

- `php artisan test --filter=Inventory` passed: 901 tests, 3649 assertions, duration about 220.54 seconds.
- `vendor/bin/pint --test` passed.

Step result:

- Documentation-only change.
- No business logic changed.
- No migrations changed.
- No routes changed.
- No UI changed.
- No tests added or updated.
