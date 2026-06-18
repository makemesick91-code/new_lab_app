# Sprint 48 — Inventory Workflow Audit, Empty-Database Setup & Smoke Test Readiness

## 1. Title

Sprint 48 — Inventory Workflow Audit, Empty-Database Setup & Smoke Test Readiness

- Branch: `feature/sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness`
- Feature tag: `sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness`
- Future GO tag (after PR merge only): `sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness-go`
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: Sprint 47 GO / merge commit `cd16bf5`

## 2. Status

Local Inventory audit implementation / pending PR. Local controlled audit + documentation +
checklist regression test only. No deployment, no PR, no GO tag created in this sprint.

## 3. Baseline

Baseline is the Sprint 47 GO at merge commit `cd16bf5`
(`sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist-go`). This sprint
inspects the existing Inventory module at that baseline and produces documentation, an empty-database
setup workflow, a smoke-test readiness checklist, and a defect/risk register. No Inventory runtime logic
is rewritten.

## 4. Purpose

Audit and document the Inventory module workflow from an empty database / fresh setup state through to
operational readiness: what master data must exist first, what items must be input first, how stock
movements drive current stock, and what smoke tests are required before Inventory can be used in daily
operations.

## 5. Scope

- Inspect current Inventory module architecture (models, repositories, services, controllers, requests,
  policies, routes, views, tests, permissions).
- Document the empty-database Inventory setup order.
- Document required initial master data.
- Document opening stock workflow.
- Document daily operational workflows (receive, usage/out, adjustment in/out).
- Document stock card, current stock, low-stock, and stock opname workflows.
- Document a smoke-test readiness checklist.
- Document branch isolation, permission, and safety checks.
- Document defect/risk candidates for a future Inventory bugfix sprint.
- Update sprint history.
- Add a checklist regression test.

## 6. Non-goals / forbidden actions

This sprint explicitly forbids the following:

- No deployment.
- No VPS/production/server access.
- No production database/log/file access.
- No production command execution.
- No production backup.
- No production restore.
- No rollback execution.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.
- No runtime behavior change.
- No real production evidence collected.
- No direct stock mutation.
- No financial logic rewrite.
- No RME/health-check governance continuation.

## 7. RME closure and Inventory focus statement

Sprint 48 is Inventory-focused.
RME is considered complete/closed for current planning.
Sprint 48 stops the Pilot Health Check governance loop.
This sprint is local controlled audit + documentation + checklist regression test only.

Preserved cross-cutting rules (unchanged by this sprint):

- KTP / `ktp_number` remains hidden and is not part of Inventory workflow.
- WhatsApp remains manual-only and is not part of Inventory automation.
- Zero-remaining receivable rule remains preserved.
- Overpayment guard remains preserved.
- Financial rules are not rewritten.
- Inventory stock must be changed through stock movements / ledger entries.
- No direct stock mutation.

## 8. Inventory module map

The Inventory module lives under `app/Modules/Inventory/`. Key files confirmed by inspection at the
baseline:

**Models** (`app/Modules/Inventory/Models/`):
`Product`, `ProductCategory`, `ProductUnit`, `Supplier`, `InventoryLocation`, `InventoryMovement`,
`InventoryBatch`, `LocationProductMinimum`, `GoodsReceipt`, `GoodsReceiptItem`, `PurchaseOrder`,
`PurchaseOrderItem`, `PurchaseRequest`, `PurchaseRequestItem`, `StockOpname`, `StockOpnameItem`,
`StockTransfer`, `StockTransferItem`, `InventoryActivityLog`.

**Repositories** (`app/Modules/Inventory/Repositories/`):
`InventoryMovementRepository`, `ProductRepository`, `ProductCategoryRepository`, `ProductUnitRepository`,
`SupplierRepository`, `InventoryLocationRepository`, `LocationProductMinimumRepository`,
`StockOpnameRepository`, `GoodsReceiptRepository`, `PurchaseOrderRepository`, `PurchaseRequestRepository`,
`StockTransferRepository`, `InventoryAnalyticsRepository`, `InventorySummaryAnalyticsRepository`,
`InventoryBatchRepository`, `InventoryActivityLogRepository` (each behind an `Interfaces/` contract).

**Services** (`app/Modules/Inventory/Services/`):
`InventoryStockService` (ledger movement core), `InventoryProductService`, `InventoryLocationService`,
`InventorySupplierService`, `LocationProductMinimumService`, `StockOpnameService`, `GoodsReceiptService`,
`PurchaseOrderService`, `PurchaseRequestService`, `StockTransferService`, `InventoryBatchService`,
`InventoryAlertService`, `InventoryReportService`, `ProductImportService`, and the analytics services.

**Controllers** (`app/Modules/Inventory/Controllers/`):
`InventoryStockController`, `InventoryDashboardController`, `ProductController`, `ProductCategoryController`,
`ProductUnitController`, `SupplierController`, `InventoryLocationController`, `StockCardController`,
`StockOpnameController`, `GoodsReceiptController`, `PurchaseOrderController`, `PurchaseRequestController`,
`StockTransferController`, `InventoryAlertController`, `InventoryReportController`,
`LocationProductMinimumController`, `ProductImportController`, plus analytics/executive dashboard.

**Requests** (`app/Modules/Inventory/Requests/`):
`StoreOpeningStockRequest`, `StoreReceiveStockRequest`, `StoreAdjustmentRequest`, `StoreProductRequest`,
`StoreProductCategoryRequest`, `StoreProductUnitRequest`, `StoreSupplierRequest`,
`StoreInventoryLocationRequest`, `StoreStockOpnameRequest`, `StockCardFilterRequest`, and update/cancel
variants.

**Policies** (`app/Modules/Inventory/Policies/`):
`ProductPolicy`, `ProductCategoryPolicy`, `ProductUnitPolicy`, `SupplierPolicy`,
`InventoryLocationPolicy`, `InventoryMovementPolicy`, `StockOpnamePolicy`, `GoodsReceiptPolicy`,
`PurchaseOrderPolicy`, `PurchaseRequestPolicy`, `StockTransferPolicy`, `LocationProductMinimumPolicy`,
`InventoryBatchPolicy`, `InventoryActivityLogPolicy` (branch-aware via
`Policies/Concerns/ChecksInventoryAccess`).

**Routes**: `routes/web.php`, `Route::middleware('auth')->prefix('inventory')->name('inventory.')`
group (e.g. `inventory.stock.index`, `inventory.stock.*`, products, suppliers, locations, etc.).

**Views**: `resources/views/inventory/` (stock, products, product-units, product-categories, suppliers,
locations, stock-opnames, goods-receipts, purchase-orders, purchase-requests, stock-transfers, alerts,
reports, dashboard) plus `resources/views/components/inventory/`.

**Migrations / Seeders / Factories**: under `database/` (inventory products, suppliers, locations,
movements, batches, opname, goods receipts, purchase orders/requests, transfers). No migration or schema
change is performed in Sprint 48.

**Tests**: `tests/Feature/Inventory/` (≈80 feature tests covering goods receipt, stock service, opname,
transfer, alerts, analytics, permission hardening, branch isolation, decimal quantity, etc.).

**Permissions** (confirmed): `view_inventory`, `manage_inventory`, plus granular
`view_inventory_activity_log`, `view_inventory_analytics`, `manage_inventory_analytics`,
`view_inventory_batch_lot`, `manage_inventory_batch_lot`, `view_inventory_cross_branch`,
`view_inventory_cross_branch_analytics`, `view_inventory_executive_dashboard`.

**Movement types** (`InventoryMovement` constants): `OPENING`, `PURCHASE`, `ADJUSTMENT_IN`,
`ADJUSTMENT_OUT`, `TRANSFER_IN`, `TRANSFER_OUT`.

## 9. Empty-database setup principle

Inventory cannot become operational by directly editing stock totals. From an empty database, the
system must first have branches, users, inventory permissions, locations, units, categories, suppliers,
and products. Only after master data is ready may opening stock and stock movements be entered.

Current stock must be derived from ledger movements:

```
current stock = stock in - stock out
```

Confirmed in code: `InventoryStockService::getCurrentStock()` delegates to
`InventoryMovementRepository::currentStock()` — there is no mutable `current_stock` column being written
directly. Every change to stock is a new row in the movement ledger (`InventoryMovement`).

## 10. Required initial data

At minimum the following must exist before Inventory is operational:

- Branches
- Users
- Roles
- Permissions: `view_inventory` and `manage_inventory`
- Inventory locations
- Product units
- Product categories
- Suppliers
- Products/items
- Opening stock per branch/location/product

## 11. Initial setup order

```
1. Run migrations and seeders in safe local/test environment only.
2. Confirm branches exist.
3. Confirm users, roles, and Inventory permissions exist.
4. Create inventory locations per branch.
5. Create product units.
6. Create product categories.
7. Create suppliers.
8. Create products/items.
9. Input opening stock through stock movement.
10. Start daily stock receive and stock usage/out.
11. Monitor stock card/current stock/low stock.
12. Perform stock opname and adjustment when needed.
```

Do not run destructive database commands against production or unsupervised real data. `migrate:fresh`
and `db:wipe` are restricted to a safe local/test database only.

## 12. Branch / user / role / permission prerequisites

- Owner/Admin may manage Inventory (`manage_inventory`).
- Technician/QC/staff may view Inventory if the current permission model allows (`view_inventory`).
- A user without `view_inventory`/`manage_inventory` must be denied.
- Inventory data must be branch-aware (every query is scoped by `BranchContext::requireId()`).
- Cross-branch stock leakage must be rejected.

Confirmed in code: `InventoryStockService` resolves the active branch via
`BranchContext::requireId()` and asserts product/location/supplier/batch belong to that branch before
any movement is created.

## 13. Inventory location setup

Fields:

```
branch
code/name
active status
description
```

Examples:

```
Gudang Utama
Ruang Produksi
Ruang QC
Rak Bahan Acrylic
Rak Zirconia
Rak Packaging
```

Inactive locations are rejected for new movements (`assertLocationInBranch` /
`lockAndAssertLocationInBranch` require `is_active`).

## 14. Product unit setup

Examples:

```
pcs
box
pack
gram
kg
ml
liter
set
roll
tube
bottle
sheet
```

## 15. Product category setup

Examples:

```
Bahan Acrylic
Bahan Zirconia
Bahan Metal
Bahan Impression
Bahan Polishing
Disposable
Packaging
ATK
Alat Produksi
Alat QC
```

## 16. Supplier setup

Fields:

```
name
contact
phone
address
email
active status
notes
```

Inactive suppliers are rejected for inbound movements that reference a supplier
(`assertSupplierInBranch` requires `is_active`).

## 17. Product / item setup

Fields:

```
SKU/code
name
category
unit
default supplier
minimum stock
active status
branch/location behavior if applicable
notes
```

Examples:

```
Acrylic Resin Pink
Zirconia Disc A1
Zirconia Disc A2
Dental Stone
Alginate
Polishing Bur
Glove Latex
Masker Medis
Box Packaging
Label Stiker
```

Inactive products are rejected for new movements (`lockAndAssertProductInBranch` requires `is_active`).

## 18. Opening stock workflow

- Opening stock is the first ledger movement after physical stock count.
- Opening stock must be entered per branch, location, and product.
- Opening stock must not directly update a `current_stock` column.
- Opening stock must appear in the stock card.

Confirmed: `InventoryStockService::createOpeningStock()` creates an `OPENING` inbound movement
(`quantity_in > 0`, `quantity_out = 0`) inside a DB transaction and logs the activity.

Example:

```
Cabang: Makassar
Lokasi: Gudang Utama
Produk: Acrylic Resin Pink
Qty: 3000 gram
Type: Opening Stock / Stock In
Notes: Saldo awal hasil hitung fisik
```

## 19. Stock receive workflow

```
Supplier sends goods
Physical check
Input receive stock
Select branch/location/product
Input quantity
Input supplier/reference/notes
Save
Stock increases through ledger movement
Stock card records IN
```

Confirmed: `InventoryStockService::receiveStock()` creates a `PURCHASE` inbound movement; goods receipt
posting (`GoodsReceiptService`) also drives `PURCHASE` movements with reference linkage.

## 20. Stock usage / stock out workflow

```
Production requests materials
Warehouse checks stock
Input stock out/usage/adjustment out according to current implementation
Select branch/location/product
Input quantity and reason
System validates sufficient stock
Save
Stock decreases through ledger movement
Stock card records OUT
```

Confirmed: outbound movement (`adjustOut`) locks product/location, checks
`currentStock >= qty`, and creates an `ADJUSTMENT_OUT` movement (`quantity_out > 0`).

## 21. Adjustment in workflow

Use for:

```
Found stock
Internal return
Correction increase
Supplier bonus
Opname positive difference
```

Confirmed: `InventoryStockService::adjustIn()` creates an `ADJUSTMENT_IN` inbound movement.

## 22. Adjustment out workflow

Use for:

```
Damaged stock
Expired stock
Lost stock
Opname negative difference
Correction decrease
```

Rules:

```
Adjustment out must not make stock negative.
Reason/notes should be required operationally.
Zero/negative quantity must be rejected.
```

Confirmed: `adjustOut()` calls `assertPositiveQuantity()` (rejects `qty <= 0`) and rejects when
`currentStock < qty` ("Stok pada lokasi ini tidak mencukupi.").

## 23. Stock card workflow

The stock card must show the full movement trail:

```
Opening stock
Receive
Usage/out
Adjustment in
Adjustment out
Running balance if supported
User/date/notes/reference if supported
```

Confirmed: `InventoryStockService::getStockCard()` delegates to
`InventoryMovementRepository::stockCard()`, branch- and (optionally) location-scoped, with filters.

## 24. Current stock calculation

```
Current Stock = Total Stock In - Total Stock Out
```

Also:

```
Stock must be calculated per branch.
Stock must be calculated per location.
Stock must be calculated per product.
Current stock must align with stock card.
```

Confirmed ledger-based: stock is derived from summed `quantity_in - quantity_out`, never from a
mutable total column.

## 25. Low-stock monitoring

```
Product minimum stock must be configured.
Low stock appears when current stock <= minimum stock.
Low stock should be branch/location aware if current implementation supports it.
Low stock should help trigger purchase/restock planning.
```

Confirmed: `getLowStockProducts()` → `InventoryMovementRepository::lowStockProducts()`, branch- and
location-aware; per-location minimums via `LocationProductMinimum`.

## 26. Stock opname workflow

```
Open system stock list.
Count physical stock.
Compare system vs physical.
If equal: no movement.
If physical greater: adjustment in.
If physical lower: adjustment out.
Record reason and reviewer.
Review stock card after adjustment.
```

Confirmed: `StockOpnameService` / `StockOpname` + `StockOpnameItem` drive review and finalization,
producing adjustment movements through the ledger (no direct stock mutation).

## 27. Daily operational workflow

```
Morning: review low-stock alerts and dashboard summary.
Receive incoming goods (PURCHASE) with supplier/reference/notes.
Issue materials to production/QC (ADJUSTMENT_OUT / usage).
Record corrections (ADJUSTMENT_IN / ADJUSTMENT_OUT) with reason.
Periodic stock opname; reconcile via adjustment in/out.
Review stock card and current stock per product/location.
```

## 28. Branch-aware and location-aware rules

- Every movement requires an active branch (`BranchContext::requireId()`).
- Product, location, supplier, and batch must belong to the active branch.
- Current stock, stock card, low stock, and inventory value are computed per branch and per location.
- Cross-branch stock leakage is rejected ("... tidak valid untuk cabang aktif.").
- Batch outbound rejects expired batches and insufficient batch stock.

## 29. Permission and access control checks

- `view_inventory` gates read access (menu, index, stock card, dashboard).
- `manage_inventory` gates write access (create master data, post movements, opname, adjustments).
- Users without the relevant permission are denied by policy and route middleware.
- Granular permissions gate analytics, batch/lot, executive dashboard, activity log, and cross-branch
  visibility.

## 30. Inactive product/location rules

- Inactive product cannot be used for a new movement.
- Inactive location cannot be used for a new movement.
- Inactive supplier cannot be referenced on inbound movement.
- Inactive/expired batch cannot be used for outbound movement.

## 31. Smoke-test readiness checklist

```
1. Admin can access Inventory menu.
2. User without Inventory permission cannot access Inventory.
3. Product Unit can be created.
4. Product Category can be created.
5. Supplier can be created.
6. Inventory Location can be created.
7. Product can be created.
8. Opening stock can be entered through stock movement.
9. Stock receive increases stock.
10. Adjustment in increases stock.
11. Stock usage/out decreases stock.
12. Adjustment out decreases stock.
13. Adjustment out is rejected if stock is insufficient.
14. Zero quantity is rejected.
15. Negative quantity is rejected.
16. Current stock calculation is correct.
17. Stock card records all movements.
18. Low stock appears when stock <= minimum stock.
19. Branch A stock does not leak to Branch B.
20. Inactive product cannot be used for new movement.
21. Inactive location cannot be used for new movement.
22. Inventory dashboard summary matches movement data.
23. Stock opname process can be represented by adjustment in/out.
24. No direct stock mutation is required.
```

## 32. Empty-database seed/input templates

### Product Unit Template

```
code,name
PCS,Pieces
BOX,Box
PACK,Pack
GRAM,Gram
KG,Kilogram
ML,Mililiter
LITER,Liter
SET,Set
ROLL,Roll
```

### Product Category Template

```
code,name
ACR,Bahan Acrylic
ZIR,Bahan Zirconia
MTL,Bahan Metal
DSP,Disposable
PKG,Packaging
ATK,Administrasi
```

### Inventory Location Template

```
code,name,branch
GUD-MKS-01,Gudang Utama Makassar,Makassar
PROD-MKS-01,Ruang Produksi Makassar,Makassar
QC-MKS-01,Ruang QC Makassar,Makassar
```

### Supplier Template

```
name,contact,phone,address
PT Dental Supply Indonesia,Sales,08xxx,Jakarta
Supplier Lokal Makassar,Admin,08xxx,Makassar
```

### Product Template

```
sku,name,category,unit,supplier,min_stock
ACR-PINK-001,Acrylic Resin Pink,Bahan Acrylic,gram,PT Dental Supply Indonesia,500
ZIR-A1-001,Zirconia Disc A1,Bahan Zirconia,pcs,PT Dental Supply Indonesia,3
ZIR-A2-001,Zirconia Disc A2,Bahan Zirconia,pcs,PT Dental Supply Indonesia,3
DSP-GLV-001,Glove Latex,Disposable,box,Supplier Lokal Makassar,5
PKG-BOX-001,Box Packaging,Packaging,pcs,Supplier Lokal Makassar,20
```

### Opening Stock Template

```
date,branch,location,sku,qty,notes
2026-06-18,Makassar,Gudang Utama Makassar,ACR-PINK-001,3000,Opening stock awal
2026-06-18,Makassar,Gudang Utama Makassar,ZIR-A1-001,8,Opening stock awal
2026-06-18,Makassar,Gudang Utama Makassar,ZIR-A2-001,5,Opening stock awal
2026-06-18,Makassar,Gudang Utama Makassar,DSP-GLV-001,12,Opening stock awal
2026-06-18,Makassar,Gudang Utama Makassar,PKG-BOX-001,100,Opening stock awal
```

These templates are illustrative inputs for a safe local/test environment only. They contain no real
production data, no secrets, no KTP, no WhatsApp numbers, and no patient identifiers.

## 33. Defect/risk register candidates

Items to watch for during the next implementation sprint (document, do not fix here unless a follow-up
sprint is explicitly approved):

```
Stock can become negative.
Zero quantity can be saved.
Negative quantity can be saved.
Inactive product can still be used in new movement.
Inactive location can still be used in new movement.
Cross-branch stock leakage.
Current stock differs from stock card.
Low stock calculation is wrong.
Stock receive does not increase stock.
Adjustment out does not decrease stock.
Branch filter does not work.
User without permission can access Inventory.
Stock card order is incorrect.
Dashboard summary does not match movements.
Opening stock can be duplicated without warning.
Product minimum stock is missing or ignored.
Supplier inactive status is ignored.
```

Audit note: at the baseline, the core `InventoryStockService` already enforces positive-quantity,
insufficient-stock, inactive product/location/supplier, branch isolation, and ledger-only guards. The
register above is a watch-list for regression and for areas (e.g. opening-stock duplication warnings,
dashboard-vs-ledger reconciliation) that warrant explicit smoke-test confirmation in the next sprint.

## 34. Follow-up sprint recommendation

Recommend (only if confirmed bugs are found during smoke testing):

```
Sprint 49 — Inventory Bugfix Batch 1 & Workflow Stabilization
```

Possible Sprint 49 scope:

```
Fix negative stock guard.
Fix zero/negative quantity validation.
Fix inactive product/location transaction guard.
Fix branch isolation gaps.
Fix stock card/current stock mismatch.
Fix dashboard low-stock summary.
Add missing Inventory feature tests.
```

## 35. Validation commands

```bash
php artisan test --filter=Sprint48InventoryWorkflowAuditEmptyDatabaseSetupSmokeTestReadiness
vendor/bin/pint --test
git diff --check
git status --short
```

Optionally, if safe and not too costly:

```bash
php artisan test tests/Feature/Inventory
```

Do not run the full suite unless necessary. Do not run destructive database commands against a
non-test database.

## 36. AI agent memory summary

- Sprint 48 is an Inventory-focused local controlled audit + documentation + checklist regression test
  sprint at baseline `cd16bf5` (Sprint 47 GO).
- RME is considered complete/closed for current planning; the Pilot Health Check governance loop is
  stopped.
- Inventory stock is ledger-based: `current stock = stock in - stock out`. There is no direct stock
  mutation; all changes are `InventoryMovement` rows.
- Movement types: `OPENING`, `PURCHASE`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `TRANSFER_IN`,
  `TRANSFER_OUT`.
- Core guards (confirmed in `InventoryStockService`): positive-quantity, insufficient-stock, inactive
  product/location/supplier, expired/insufficient batch, and branch isolation.
- Permissions: `view_inventory`, `manage_inventory`, plus granular analytics/batch/cross-branch/executive
  permissions.
- KTP hidden; WhatsApp manual-only; zero-remaining receivables excluded; overpayment guard preserved;
  financial rules unchanged.
- No deployment, VPS/production/server/database/log/file access, `.env`/dependency/migration/schema/
  runtime change, or real evidence collection in this sprint.
