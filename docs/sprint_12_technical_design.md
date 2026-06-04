# Sprint 12 Technical Design - Inventory Core

Date: 2026-06-04

Source audit: `docs/sprint_12_inventory_audit.md`

## Goal

Build Inventory Core for the dental lab as a branch-aware module that follows the existing modular monolith architecture:

```text
Controller -> Request -> Service -> Repository -> Model
```

Sprint 12 must support:

- Product categories
- Product units
- Products/materials
- Suppliers
- Inventory transaction ledger
- Opening stock
- Receive stock
- Adjustment in
- Adjustment out
- Current stock calculation
- Stock card
- Low stock detection
- Inventory dashboard summary

Out of scope:

- Stock opname
- Production usage
- Purchase order
- Inter-branch transfer
- Supplier payment
- Inventory forecasting

## Design Decisions

1. Inventory is branch-aware from day one.

All branch-owned Inventory data must carry `branch_id`. Reads and writes must go through a branch resolver rather than trusting request input.

2. Sprint 12 must not assume `CurrentBranch` exists.

The audit found Branch foundation but no `CurrentBranch` class, helper, middleware, or service. Sprint 12 requires a minimal `BranchContext` service before Inventory implementation.

3. Stock is calculated from a ledger.

There must be no mutable `final_stock`, `current_stock`, or `qty_on_hand` column treated as source of truth. Current stock is derived from inventory ledger movements:

```text
SUM(quantity_in - quantity_out)
```

4. Repositories enforce branch filters.

Inventory repositories must always accept branch context from services and apply `branch_id` filters. They must not expose unscoped list/detail queries except deliberate Super Admin/all-branch reporting methods.

5. Policies block cross-branch access.

Inventory policies must compare model `branch_id` with the active branch. Super Admin may bypass via existing `Gate::before`; any extra all-branch permission must be explicit.

## Branch Strategy

### Existing Foundation

The codebase already has:

- `App\Modules\Branch\Models\Branch`
- `mst_branches`
- `Branch::MAIN_CODE`
- `BranchSeeder`
- `BranchRepositoryInterface`
- Branch relationships from LabOrder, Delivery, Invoice, and Payment
- Nullable `branch_id` on core transaction tables
- MAIN backfill migration

The audit also found:

- Runtime branch enforcement is still opt-in/TODO in existing repositories.
- No centralized `CurrentBranch` implementation exists.

### Required Prerequisite: Minimal BranchContext

Implemented as a minimal service:

```text
app/Modules/Branch/Services/BranchContext.php
```

Responsibilities:

- Resolve the active branch for the current request/user.
- Provide nullable lookup methods for optional contexts.
- Provide a required lookup method for branch-owned writes.
- Fallback to the MAIN branch when it exists.
- Fail clearly when a branch is required and MAIN is missing.

Actual Sprint 12 prerequisite implementation exposes:

```text
id(): ?int
branch(): ?Branch
requireId(): int
forUser(User $user): ?int
```

`requireId()` throws a clear runtime exception when no active branch can be resolved. The current user schema has no `branch_id` and no assigned-branches relation, so the implemented resolver uses the existing safe convention: resolve the seeded/backfilled MAIN branch via `BranchRepository::defaultBranch()`.

Resolution order:

1. If a future `users.branch_id` column exists and is populated with an active branch, use it.
2. If a future `User::branches()` relation exists, use the first active assigned branch.
3. Otherwise use `BranchRepository::defaultBranch()` resolving `Branch::MAIN_CODE`.

Sprint 12 should start with MAIN fallback because the current codebase has no user-branch assignment. This keeps Inventory branch-aware without inventing a wider branch administration sprint.

### Write Rules

Inventory services must stamp `branch_id` from `BranchContext::id()` on all branch-owned writes.

Do not accept `branch_id` from public forms for normal staff actions. If Super Admin branch switching is introduced, use session context or an explicit branch selector guarded by permission, then still stamp from `BranchContext`.

### Read Rules

Every Inventory repository list/detail query must receive or resolve `branch_id` and apply it:

```php
->where('branch_id', $branchId)
```

For detail pages, repositories should load by both primary key and branch:

```php
InventoryMovement::where('branch_id', $branchId)->find($id)
```

### Policy Rules

Policy checks:

- `viewAny`: user has `view_inventory` or `manage_inventory`.
- `view`: user can view inventory and model `branch_id` equals `BranchContext::id()`.
- `create`: user has create/manage permission.
- `update`: user has update/manage permission and model is in active branch.
- `delete`: only for safe master data and only in active branch when no ledger dependency exists.
- Movement records are immutable; policies should not allow update/delete for posted ledger rows.

Super Admin continues to bypass via existing `Gate::before`.

## Database Schema

This section is design only. No migration is created by this document.

### mst_product_categories

Purpose: categorize products/materials.

Branch ownership: branch-owned, because categories may differ per branch.

Columns:

```text
id BIGSERIAL PRIMARY KEY
branch_id BIGINT NOT NULL FK mst_branches(id) ON DELETE RESTRICT
code VARCHAR(50) NOT NULL
name VARCHAR(150) NOT NULL
description TEXT NULL
is_active BOOLEAN NOT NULL DEFAULT TRUE
created_at TIMESTAMP NULL
updated_at TIMESTAMP NULL
deleted_at TIMESTAMP NULL
```

Indexes:

```text
INDEX branch_id
UNIQUE(branch_id, code)
INDEX(branch_id, is_active)
```

### mst_product_units

Purpose: product unit master data, for example `pcs`, `gram`, `box`, `bottle`.

Branch ownership: branch-owned for consistent policy and branch filtering.

Columns:

```text
id BIGSERIAL PRIMARY KEY
branch_id BIGINT NOT NULL FK mst_branches(id) ON DELETE RESTRICT
code VARCHAR(50) NOT NULL
name VARCHAR(100) NOT NULL
symbol VARCHAR(20) NULL
description TEXT NULL
is_active BOOLEAN NOT NULL DEFAULT TRUE
created_at TIMESTAMP NULL
updated_at TIMESTAMP NULL
deleted_at TIMESTAMP NULL
```

Indexes:

```text
INDEX branch_id
UNIQUE(branch_id, code)
INDEX(branch_id, is_active)
```

### mst_inventory_products

Purpose: products/materials inventory master.

Columns:

```text
id BIGSERIAL PRIMARY KEY
branch_id BIGINT NOT NULL FK mst_branches(id) ON DELETE RESTRICT
product_category_id BIGINT NULL FK mst_product_categories(id) ON DELETE SET NULL
product_unit_id BIGINT NOT NULL FK mst_product_units(id) ON DELETE RESTRICT
code VARCHAR(50) NOT NULL
name VARCHAR(150) NOT NULL
type VARCHAR(30) NOT NULL DEFAULT 'MATERIAL'
description TEXT NULL
minimum_stock NUMERIC(12, 2) NOT NULL DEFAULT 0
is_active BOOLEAN NOT NULL DEFAULT TRUE
created_at TIMESTAMP NULL
updated_at TIMESTAMP NULL
deleted_at TIMESTAMP NULL
```

Allowed `type` values:

```text
MATERIAL
CONSUMABLE
TOOL
OTHER
```

Indexes:

```text
INDEX branch_id
UNIQUE(branch_id, code)
INDEX(branch_id, product_category_id)
INDEX(branch_id, product_unit_id)
INDEX(branch_id, is_active)
```

Validation rule: `product_category_id`, when present, must belong to the same branch.

### mst_inventory_suppliers

Purpose: supplier master data.

Columns:

```text
id BIGSERIAL PRIMARY KEY
branch_id BIGINT NOT NULL FK mst_branches(id) ON DELETE RESTRICT
code VARCHAR(50) NOT NULL
name VARCHAR(150) NOT NULL
phone VARCHAR(50) NULL
email VARCHAR(150) NULL
address TEXT NULL
contact_person VARCHAR(150) NULL
is_active BOOLEAN NOT NULL DEFAULT TRUE
created_at TIMESTAMP NULL
updated_at TIMESTAMP NULL
deleted_at TIMESTAMP NULL
```

Indexes:

```text
INDEX branch_id
UNIQUE(branch_id, code)
INDEX(branch_id, is_active)
```

### trx_inventory_movements

Purpose: immutable inventory transaction ledger. This is the source of truth for stock.

Columns:

```text
id BIGSERIAL PRIMARY KEY
branch_id BIGINT NOT NULL FK mst_branches(id) ON DELETE RESTRICT
product_id BIGINT NOT NULL FK mst_inventory_products(id) ON DELETE RESTRICT
supplier_id BIGINT NULL FK mst_inventory_suppliers(id) ON DELETE SET NULL
movement_number VARCHAR(50) NOT NULL
movement_date DATE NOT NULL
type VARCHAR(50) NOT NULL
quantity_in NUMERIC(12, 2) NOT NULL DEFAULT 0
quantity_out NUMERIC(12, 2) NOT NULL DEFAULT 0
unit_cost NUMERIC(14, 2) NULL
total_cost NUMERIC(14, 2) NULL
reference_type VARCHAR(100) NULL
reference_id BIGINT NULL
notes TEXT NULL
created_by BIGINT NULL FK users(id) ON DELETE SET NULL
created_at TIMESTAMP NULL
updated_at TIMESTAMP NULL
```

Allowed `type` values for Sprint 12:

```text
OPENING_STOCK
RECEIVE_STOCK
ADJUSTMENT_IN
ADJUSTMENT_OUT
```

Indexes:

```text
INDEX branch_id
UNIQUE(branch_id, movement_number)
INDEX(branch_id, product_id)
INDEX(branch_id, supplier_id)
INDEX(branch_id, movement_date)
INDEX(branch_id, type)
INDEX(reference_type, reference_id)
```

Ledger rules:

- Exactly one of `quantity_in` or `quantity_out` must be greater than zero.
- `OPENING_STOCK`, `RECEIVE_STOCK`, and `ADJUSTMENT_IN` use `quantity_in`.
- `ADJUSTMENT_OUT` uses `quantity_out`.
- `quantity_in` and `quantity_out` must never be negative.
- `total_cost = quantity_in * unit_cost` when `unit_cost` exists for inbound movement.
- Movement rows are append-only after creation.

### Current Stock Query

No mutable stock table is required for Sprint 12. Current stock is a query:

```sql
SELECT
    product_id,
    SUM(quantity_in - quantity_out) AS current_stock
FROM trx_inventory_movements
WHERE branch_id = :branch_id
GROUP BY product_id
```

For listing products with stock:

```sql
SELECT
    p.*,
    COALESCE(SUM(m.quantity_in - m.quantity_out), 0) AS current_stock
FROM mst_inventory_products p
LEFT JOIN trx_inventory_movements m
    ON m.product_id = p.id
    AND m.branch_id = p.branch_id
WHERE p.branch_id = :branch_id
GROUP BY p.id
```

Low stock:

```text
current_stock <= minimum_stock
```

## Module Structure

```text
app/Modules/Inventory/
├── Controllers/
│   ├── InventoryCategoryController.php
│   ├── InventoryUnitController.php
│   ├── InventoryProductController.php
│   ├── InventorySupplierController.php
│   ├── InventoryMovementController.php
│   ├── StockReceiptController.php
│   ├── StockAdjustmentController.php
│   └── InventoryDashboardController.php
├── Interfaces/
│   ├── InventoryCategoryRepositoryInterface.php
│   ├── InventoryUnitRepositoryInterface.php
│   ├── InventoryProductRepositoryInterface.php
│   ├── InventorySupplierRepositoryInterface.php
│   └── InventoryMovementRepositoryInterface.php
├── Models/
│   ├── InventoryCategory.php
│   ├── InventoryUnit.php
│   ├── InventoryProduct.php
│   ├── InventorySupplier.php
│   └── InventoryMovement.php
├── Policies/
│   ├── InventoryCategoryPolicy.php
│   ├── InventoryUnitPolicy.php
│   ├── InventoryProductPolicy.php
│   ├── InventorySupplierPolicy.php
│   └── InventoryMovementPolicy.php
├── Repositories/
│   ├── InventoryCategoryRepository.php
│   ├── InventoryUnitRepository.php
│   ├── InventoryProductRepository.php
│   ├── InventorySupplierRepository.php
│   └── InventoryMovementRepository.php
├── Requests/
│   ├── StoreInventoryCategoryRequest.php
│   ├── UpdateInventoryCategoryRequest.php
│   ├── StoreInventoryUnitRequest.php
│   ├── UpdateInventoryUnitRequest.php
│   ├── StoreInventoryProductRequest.php
│   ├── UpdateInventoryProductRequest.php
│   ├── StoreInventorySupplierRequest.php
│   ├── UpdateInventorySupplierRequest.php
│   ├── StoreOpeningStockRequest.php
│   ├── StoreStockReceiptRequest.php
│   ├── StoreAdjustmentInRequest.php
│   └── StoreAdjustmentOutRequest.php
└── Services/
    ├── InventoryCategoryService.php
    ├── InventoryUnitService.php
    ├── InventoryProductService.php
    ├── InventorySupplierService.php
    ├── InventoryMovementService.php
    ├── InventoryStockService.php
    ├── InventoryDashboardService.php
    └── InventoryNumberGeneratorService.php
```

## Models

All models should:

- Use `HasFactory`.
- Use `SoftDeletes` for master data.
- Set explicit `$table`.
- Define `$fillable`.
- Define casts for booleans, dates, decimals.
- Define `branch(): BelongsTo`.
- Define `protected static function newFactory()`.

`InventoryMovement` should:

- Define `ENTITY_TYPE = 'trx_inventory_movements'`.
- Not use `SoftDeletes`.
- Be append-only by policy/service convention.
- Define `product()`, `supplier()`, `branch()`, and `creator()` relationships.

## Services

### InventoryCategoryService

Responsibilities:

- List/create/update/delete categories in the active branch.
- Prevent delete when products reference the category.
- Stamp `branch_id` on create.

### InventoryUnitService

Responsibilities:

- List/create/update/delete units in the active branch.
- Prevent delete when products reference the unit.
- Stamp `branch_id` on create.

### InventoryProductService

Responsibilities:

- List products with category/unit and current stock.
- Create/update product master data.
- Validate category/unit belongs to active branch.
- Detect low stock through `InventoryStockService`.
- Prevent delete if ledger movements exist.

### InventorySupplierService

Responsibilities:

- List/create/update/delete suppliers in active branch.
- Prevent delete if ledger movements exist.

### InventoryMovementService

Responsibilities:

- Create ledger rows for opening stock, receive stock, adjustment in, and adjustment out.
- Validate product/supplier branch ownership.
- Generate movement numbers.
- Wrap all writes in `DB::transaction()`.
- Use `lockForUpdate()` on product/movement aggregate checks when creating outbound movements.
- Write audit logs through `AuditLogService`.
- Block negative stock for `ADJUSTMENT_OUT`.

### InventoryStockService

Responsibilities:

- Calculate current stock from `trx_inventory_movements`.
- Return stock card rows for one product in active branch.
- Return low stock products.
- Return dashboard aggregate counts.
- Never persist final stock.

### InventoryDashboardService

Responsibilities:

- Summarize:
  - total active products
  - low stock product count
  - total stock value from inbound cost basis where feasible
  - movement count for current month
  - recent movements

Cost valuation is basic for Sprint 12 and should not become forecasting or accounting.

### InventoryNumberGeneratorService

Responsibilities:

- Generate unique movement numbers.
- Suggested format:

```text
INV-YYYYMM-XXXXXX
```

Uniqueness should be per branch:

```text
UNIQUE(branch_id, movement_number)
```

## Repositories

Repositories must receive `branch_id` from services.

### InventoryCategoryRepository

Methods:

```text
paginate(int $branchId, array $filters = [], int $perPage = 10)
listActive(int $branchId)
findInBranch(int $branchId, int $id)
create(array $data)
update(InventoryCategory $category, array $data)
delete(InventoryCategory $category)
```

### InventoryUnitRepository

Same CRUD/list pattern as categories.

### InventoryProductRepository

Methods:

```text
paginate(int $branchId, array $filters = [], int $perPage = 10)
listActive(int $branchId)
findInBranch(int $branchId, int $id)
findDetailInBranch(int $branchId, int $id)
create(array $data)
update(InventoryProduct $product, array $data)
delete(InventoryProduct $product)
hasMovements(int $branchId, int $productId): bool
```

### InventorySupplierRepository

Same CRUD/list pattern plus `hasMovements(...)`.

### InventoryMovementRepository

Methods:

```text
paginate(int $branchId, array $filters = [], int $perPage = 15)
forProduct(int $branchId, int $productId, array $filters = [], int $perPage = 15)
create(array $data)
currentStockForProduct(int $branchId, int $productId): string
currentStockByProduct(int $branchId, array $productIds = []): Collection
lowStockProducts(int $branchId): Collection
latestMovementNumberForMonth(int $branchId, string $month): ?string
```

Repository guardrail:

- No `find($id)` without branch condition.
- No global `paginate()` for normal Inventory controllers.

## Requests

Requests authorize with `true`; controllers/policies enforce access, matching existing request style.

Common validation:

- Codes: required, string, max 50, unique per branch. Because Laravel validation needs active branch, use service-level duplicate checks or custom rule using `BranchContext`.
- Names: required, string, max 150.
- Active flags: sometimes boolean.
- Quantities: numeric, min 0.01.
- Dates: required date.
- Notes: nullable string max 2000.

Request examples:

### StoreInventoryProductRequest

```text
product_category_id nullable integer exists:mst_product_categories,id
product_unit_id required integer exists:mst_product_units,id
code required string max:50
name required string max:150
type required in:MATERIAL,CONSUMABLE,TOOL,OTHER
description nullable string max:2000
minimum_stock required numeric min:0
is_active sometimes boolean
```

Branch ownership for category/unit is checked in `InventoryProductService`.

### StoreOpeningStockRequest

```text
product_id required integer exists:mst_inventory_products,id
movement_date required date
quantity required numeric min:0.01
unit_cost nullable numeric min:0
notes nullable string max:2000
```

Service rule: only one opening stock movement per product per branch unless explicitly allowed.

### StoreStockReceiptRequest

```text
product_id required integer exists:mst_inventory_products,id
supplier_id nullable integer exists:mst_inventory_suppliers,id
movement_date required date
quantity required numeric min:0.01
unit_cost nullable numeric min:0
notes nullable string max:2000
```

### StoreAdjustmentOutRequest

```text
product_id required integer exists:mst_inventory_products,id
movement_date required date
quantity required numeric min:0.01
notes required string max:2000
```

Service rule: resulting stock must not go negative.

## Policies

Permissions:

```text
manage_inventory
view_inventory
create_inventory_master
update_inventory_master
delete_inventory_master
receive_inventory_stock
adjust_inventory_stock
view_inventory_movements
view_inventory_dashboard
```

Policy behavior:

- Master data policies check permission and active branch.
- Movement policy allows view within branch.
- Movement create abilities map to receive/adjust permissions.
- Movement update/delete return false for posted movement records.

Recommended policy methods:

```text
viewAny(User $user): bool
view(User $user, Model $model): bool
create(User $user): bool
update(User $user, Model $model): bool
delete(User $user, Model $model): bool
receiveStock(User $user): bool
adjustStock(User $user): bool
viewMovements(User $user): bool
viewDashboard(User $user): bool
```

Cross-branch rule:

```text
return $model->branch_id === app(BranchContext::class)->id()
```

Super Admin bypass remains centralized in `RepositoryServiceProvider`.

## Controllers

Controllers should stay thin.

### InventoryDashboardController

- `index()`: authorize dashboard, call `InventoryDashboardService`, render summary.

### InventoryCategoryController

- CRUD except show.
- Use settings-style form pattern.

### InventoryUnitController

- CRUD except show.

### InventoryProductController

- CRUD except show.
- `show()` optional for stock card shortcut if desired; otherwise stock card belongs to movement controller.

### InventorySupplierController

- CRUD except show.

### InventoryMovementController

- `index()`: ledger list.
- `stockCard(InventoryProduct $product)`: stock card for one product after policy/branch check.

### StockReceiptController

- `create()`: receive stock form.
- `store(StoreStockReceiptRequest $request)`: create `RECEIVE_STOCK` ledger row.

### StockAdjustmentController

- `createIn()`, `storeIn()`.
- `createOut()`, `storeOut()`.
- Or one form with type selector if UX is kept compact.

### OpeningStockController

- `create()`, `store()`.
- Can be folded into `InventoryMovementController` if the UI uses one movement form, but a dedicated controller keeps rules clearer.

## Routes

Suggested route group:

```php
Route::middleware('auth')->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('dashboard', [InventoryDashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:view_inventory_dashboard|manage_inventory');

    Route::resource('categories', InventoryCategoryController::class)
        ->except(['show'])
        ->middleware('permission:manage_inventory|create_inventory_master|update_inventory_master|delete_inventory_master');

    Route::resource('units', InventoryUnitController::class)
        ->except(['show'])
        ->middleware('permission:manage_inventory|create_inventory_master|update_inventory_master|delete_inventory_master');

    Route::resource('products', InventoryProductController::class)
        ->except(['show'])
        ->middleware('permission:view_inventory|manage_inventory');

    Route::resource('suppliers', InventorySupplierController::class)
        ->except(['show'])
        ->middleware('permission:view_inventory|manage_inventory');

    Route::get('movements', [InventoryMovementController::class, 'index'])
        ->name('movements.index')
        ->middleware('permission:view_inventory_movements|manage_inventory');

    Route::get('products/{product}/stock-card', [InventoryMovementController::class, 'stockCard'])
        ->name('products.stock-card')
        ->middleware('permission:view_inventory_movements|manage_inventory');

    Route::get('opening-stock/create', [OpeningStockController::class, 'create'])
        ->name('opening-stock.create')
        ->middleware('permission:adjust_inventory_stock|manage_inventory');
    Route::post('opening-stock', [OpeningStockController::class, 'store'])
        ->name('opening-stock.store')
        ->middleware('permission:adjust_inventory_stock|manage_inventory');

    Route::get('receipts/create', [StockReceiptController::class, 'create'])
        ->name('receipts.create')
        ->middleware('permission:receive_inventory_stock|manage_inventory');
    Route::post('receipts', [StockReceiptController::class, 'store'])
        ->name('receipts.store')
        ->middleware('permission:receive_inventory_stock|manage_inventory');

    Route::get('adjustments/create', [StockAdjustmentController::class, 'create'])
        ->name('adjustments.create')
        ->middleware('permission:adjust_inventory_stock|manage_inventory');
    Route::post('adjustments', [StockAdjustmentController::class, 'store'])
        ->name('adjustments.store')
        ->middleware('permission:adjust_inventory_stock|manage_inventory');
});
```

During implementation, import the controllers at the top of `routes/web.php`, matching existing style.

## Views

Recommended files:

```text
resources/views/inventory/dashboard.blade.php

resources/views/inventory/categories/index.blade.php
resources/views/inventory/categories/create.blade.php
resources/views/inventory/categories/edit.blade.php
resources/views/inventory/categories/_form.blade.php

resources/views/inventory/units/index.blade.php
resources/views/inventory/units/create.blade.php
resources/views/inventory/units/edit.blade.php
resources/views/inventory/units/_form.blade.php

resources/views/inventory/products/index.blade.php
resources/views/inventory/products/create.blade.php
resources/views/inventory/products/edit.blade.php
resources/views/inventory/products/_form.blade.php

resources/views/inventory/suppliers/index.blade.php
resources/views/inventory/suppliers/create.blade.php
resources/views/inventory/suppliers/edit.blade.php
resources/views/inventory/suppliers/_form.blade.php

resources/views/inventory/movements/index.blade.php
resources/views/inventory/movements/stock-card.blade.php

resources/views/inventory/opening-stock/create.blade.php
resources/views/inventory/receipts/create.blade.php
resources/views/inventory/adjustments/create.blade.php
```

UI conventions:

- Use `<x-settings-shell>`.
- Keep index screens dense and operational.
- Add filters for search, category, movement type, supplier, and date range where useful.
- Show current stock as calculated value.
- Show low stock indicator when `current_stock <= minimum_stock`.
- Do not show branch selector to normal users. If Super Admin branch switching is introduced, it should update BranchContext/session and be protected.

Sidebar:

- Add Inventory under Operations or its own Inventory section.
- Gate links with `@canany(['view_inventory', 'manage_inventory'])`.

## Tests

Recommended Pest feature tests:

```text
tests/Feature/Inventory/InventoryCategoryTest.php
tests/Feature/Inventory/InventoryUnitTest.php
tests/Feature/Inventory/InventoryProductTest.php
tests/Feature/Inventory/InventorySupplierTest.php
tests/Feature/Inventory/InventoryMovementTest.php
tests/Feature/Inventory/InventoryStockCardTest.php
tests/Feature/Inventory/InventoryDashboardTest.php
tests/Feature/Inventory/InventoryAuthorizationTest.php
tests/Feature/Inventory/InventoryBranchScopeTest.php
```

Factory files:

```text
database/factories/InventoryCategoryFactory.php
database/factories/InventoryUnitFactory.php
database/factories/InventoryProductFactory.php
database/factories/InventorySupplierFactory.php
database/factories/InventoryMovementFactory.php
```

Test scenarios:

- Authorized users can list Inventory dashboard.
- Guests are redirected to login.
- Users without inventory permissions are forbidden.
- Categories, units, products, and suppliers can be created/updated in active branch.
- Duplicate codes are rejected per branch.
- Same code is allowed in different branches if branch context supports switching.
- Product category/unit must belong to active branch.
- Opening stock creates an inbound ledger row.
- Receive stock creates an inbound ledger row.
- Adjustment in creates an inbound ledger row.
- Adjustment out creates an outbound ledger row.
- Adjustment out cannot make stock negative.
- Current stock is calculated from ledger rows.
- Stock card lists movement rows in date/id order for one product.
- Low stock detection uses calculated stock and `minimum_stock`.
- Dashboard summary is scoped to active branch.
- Repositories do not leak records from another branch.
- Policies block cross-branch view/update.
- Movement rows cannot be edited or deleted after creation.
- Audit log rows are created for master mutations and stock movements.

BranchContext tests:

- Returns MAIN branch when no session/user branch exists.
- Super Admin can use active branch from session.
- Non-super user cannot access a branch outside context.
- Missing MAIN branch fails loudly rather than creating unscoped Inventory data.

## Audit Logging

Inventory should reuse `AuditLogService`.

Add `ENTITY_TYPE` constants:

```text
mst_product_categories
mst_product_units
mst_inventory_products
mst_inventory_suppliers
trx_inventory_movements
```

Register morph map entries in `RepositoryServiceProvider` during implementation.

Recommended audit actions:

```text
inventory_category_created
inventory_category_updated
inventory_unit_created
inventory_unit_updated
inventory_product_created
inventory_product_updated
inventory_supplier_created
inventory_supplier_updated
inventory_opening_stock_created
inventory_stock_received
inventory_adjustment_in_created
inventory_adjustment_out_created
```

Inventory movement ledger remains the stock source of truth; audit logs are secondary trace metadata.

## Risks

1. BranchContext prerequisite may expand scope.

Inventory needs enforced branch scoping, but the current app only has branch foundation. Keep BranchContext minimal and defer full branch administration/switching.

2. User branch assignment does not exist.

Without `users.branch_id`, most non-super users will resolve to MAIN. This is acceptable for Sprint 12 only if documented and tested.

3. Ledger calculation can become slow.

Current stock from ledger is correct but may become expensive. Sprint 12 should use indexed aggregate queries. Materialized stock snapshots are out of scope unless performance requires a later sprint.

4. Negative stock edge cases.

Concurrent adjustment-out requests can overspend stock unless services use transactions and locks. Use `DB::transaction()` and lock the relevant product rows or ledger aggregate path.

5. Master data branch mismatch.

Products, categories, units, and suppliers must be validated against the same active branch. Request `exists` rules alone are insufficient.

6. Cost valuation ambiguity.

Sprint 12 can store `unit_cost` and `total_cost`, but it should not promise accounting-grade valuation, FIFO/LIFO, supplier payment, or forecasting.

7. Existing material references are free text.

Lab Order items have `material_text`. Sprint 12 should not retrofit production usage or Lab Order item material linkage in this sprint.

8. Permission naming consistency.

Use underscore-style permissions for Inventory and keep role assignments aligned.

9. Immutability expectations.

Ledger rows must be append-only. Corrections should be new adjustment rows, not updates to old rows.

10. Reporting expectations.

Inventory dashboard is in scope, but reporting exports and long-term analytics are not. Keep summary service boundaries clean for later Reporting integration.

## Acceptance Criteria

- Inventory technical implementation has a minimal `BranchContext` or equivalent resolver before any Inventory branch-owned write.
- All Inventory branch-owned tables include non-null `branch_id`.
- All Inventory repositories filter list/detail queries by active branch.
- All Inventory services stamp `branch_id` from branch context on create.
- Inventory policies prevent cross-branch access for non-bypass users.
- Stock is calculated from `trx_inventory_movements` ledger rows.
- No mutable final stock source-of-truth column exists.
- Opening stock, receive stock, adjustment in, and adjustment out create immutable ledger rows.
- Adjustment out cannot make calculated stock negative.
- Current stock, stock card, low stock detection, and dashboard summary are scoped to active branch.
- Inventory CRUD and movement actions use FormRequest validation, services, repositories, policies, and Blade views consistent with existing modules.
- Inventory permissions are seeded and assigned to appropriate roles.
- Feature tests cover authorization, branch scoping, CRUD, ledger movement creation, stock calculation, low stock, stock card, dashboard, and negative-stock prevention.
- Existing test suite remains green.

## Implementation Files

Likely created:

```text
app/Modules/Branch/Services/BranchContext.php

app/Modules/Inventory/Controllers/*
app/Modules/Inventory/Interfaces/*
app/Modules/Inventory/Models/*
app/Modules/Inventory/Policies/*
app/Modules/Inventory/Repositories/*
app/Modules/Inventory/Requests/*
app/Modules/Inventory/Services/*

resources/views/inventory/**/*
tests/Feature/Inventory/*
database/factories/InventoryCategoryFactory.php
database/factories/InventoryUnitFactory.php
database/factories/InventoryProductFactory.php
database/factories/InventorySupplierFactory.php
database/factories/InventoryMovementFactory.php
database/seeders/InventorySeeder.php
```

Likely modified during implementation:

```text
routes/web.php
app/Providers/RepositoryServiceProvider.php
database/seeders/PermissionSeeder.php
database/seeders/RoleSeeder.php
database/seeders/DatabaseSeeder.php
resources/views/layouts/sidebar.blade.php
docs/database_schema.md
docs/erd.md
docs/system_architecture.md
```

Likely migrations during implementation:

```text
database/migrations/*_create_mst_product_categories_table.php
database/migrations/*_create_mst_product_units_table.php
database/migrations/*_create_mst_inventory_products_table.php
database/migrations/*_create_mst_inventory_suppliers_table.php
database/migrations/*_create_trx_inventory_movements_table.php
```

No migrations or application code are created by this technical design document.
