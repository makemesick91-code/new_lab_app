# ADLMS Inventory Rules

Version: 1.0
Last updated: June 2026

This document is the official inventory architecture authority for the Asia Dental Lab Management System (ADLMS). It converts the implemented Sprint 12 Inventory Core behavior into strict rules for future development.

Use this document before changing any inventory schema, model, service, repository, controller, policy, request, Blade view, factory, seeder, or test.

Mandatory rule:

```text
STOCK IS DERIVED FROM LEDGER ONLY.
NEVER CREATE MUTABLE STOCK COLUMNS.
NEVER UPDATE PRODUCT STOCK DIRECTLY.
NEVER ALLOW CROSS-BRANCH INVENTORY ACCESS.
```

## Inventory Architecture Overview

ADLMS Inventory is a Laravel modular-monolith module located at:

```text
app/Modules/Inventory
```

The module follows the project-wide architecture:

```text
Controller -> Request -> Service -> Repository -> Model
```

Inventory Core is branch-aware and location-aware:

```text
Branch
  -> Inventory Location
      -> Product Movement Ledger
```

Implemented Sprint 12 inventory entities:

- Product categories.
- Product units.
- Products/materials.
- Suppliers.
- Inventory locations.
- Inventory movements.
- Opening stock.
- Receive stock.
- Adjustment in.
- Adjustment out.
- Current stock calculation.
- Stock card.
- Low stock detection.
- Inventory dashboard summary.

Implemented or staged Sprint 13 inventory entities visible in the codebase:

- Stock opname header.
- Stock opname item lines.

Stock opname must remain consistent with ledger-derived stock rules.

## Inventory Domain Principles

### Ledger First

Inventory movement rows are the operational source of truth. Stock is never stored as a mutable final quantity on products, categories, units, suppliers, or locations.

### Branch Ownership

Every branch-owned inventory record must carry `branch_id` and must be filtered by the active branch.

Branch-owned inventory tables include:

- `inv_product_categories`
- `inv_products`
- `inv_suppliers`
- `inv_inventory_locations`
- `trx_inventory_movements`
- `trx_stock_opnames`

`inv_product_units` is currently global and does not have `branch_id`.

### Location Ownership

Inventory stock belongs to a branch and a location. Stock operation forms must require `inventory_location_id`.

### Append-Only Quantity History

Corrections must create new movement rows. Do not edit old movement rows to change stock unless a future audit-safe correction design explicitly permits it.

### Safe Operations

Inventory operations must reject invalid branch, inactive product, inactive location, invalid supplier, zero/negative quantity, and insufficient location stock.

### Testable Rules

Every inventory rule must have tests proving the happy path and failure path, especially branch isolation and ledger behavior.

## Source of Truth Rules

### Inventory Movement Ledger

The source-of-truth table for stock is:

```text
trx_inventory_movements
```

Movement rows store:

- `branch_id`
- `inventory_location_id`
- `product_id`
- `supplier_id` nullable
- `movement_type`
- `movement_date`
- `quantity_in`
- `quantity_out`
- `unit_cost`
- `reference_type` nullable
- `reference_id` nullable
- `notes` nullable
- `created_by` nullable
- timestamps

### Mandatory Rule

```text
STOCK IS DERIVED FROM LEDGER ONLY.
```

Valid stock calculation:

```php
InventoryMovement::query()
    ->where('branch_id', $branchId)
    ->where('product_id', $productId)
    ->when($locationId, fn ($query, $value) => $query->where('inventory_location_id', $value))
    ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
    ->value('current_stock');
```

Invalid stock calculation:

```php
$product->stock;
```

Invalid stock mutation:

```php
$product->increment('stock', $qty);
```

Why invalid:

- Product stock fields are not the source of truth.
- Location stock would be lost.
- Stock card running balances would diverge.
- Cross-branch safety becomes fragile.

### Forbidden Mutable Stock Columns

Do not add these as persisted source-of-truth columns:

- `stock`
- `current_stock`
- `qty_on_hand`
- `quantity_on_hand`
- `available_stock`
- `final_stock`
- `stock_balance`
- `physical_stock`

Allowed usage:

```php
->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
```

as a query alias only.

## Stock Calculation Rules

### Current Stock

Current stock is calculated from ledger rows.

Per product and location:

```text
current_stock(product, location) =
SUM(quantity_in WHERE branch_id = active_branch AND product_id = product AND inventory_location_id = location)
-
SUM(quantity_out WHERE branch_id = active_branch AND product_id = product AND inventory_location_id = location)
```

Per product across active branch:

```text
current_stock(product, branch) =
SUM(quantity_in WHERE branch_id = active_branch AND product_id = product)
-
SUM(quantity_out WHERE branch_id = active_branch AND product_id = product)
```

Per location:

```text
current_stock(location) =
GROUP BY product_id within branch_id + inventory_location_id
```

### Available Stock

No reservation, allocation, purchase order, production usage, or committed stock subsystem exists in Sprint 12.

Therefore:

```text
available_stock = current_stock
```

until a future sprint explicitly introduces reservations or allocations.

Future reservation systems must not mutate product stock. They must use separate reservation/allocation records and calculate:

```text
available_stock = current_stock - reserved_stock
```

### Low Stock

Low stock compares derived stock against `inv_products.minimum_stock`.

Per branch:

```text
low_stock = current_stock(product, branch) <= product.minimum_stock
```

Per location:

```text
low_stock = current_stock(product, location) <= product.minimum_stock
```

Out of stock:

```text
out_of_stock = derived_stock <= 0
```

### Stock Card Running Balance

Stock card rows must be ordered by:

```text
movement_date ASC, id ASC
```

Running balance:

```text
running_balance = previous_balance + quantity_in - quantity_out
```

The controller may calculate display running balance from service-provided movement rows. It must not query or mutate stock in Blade.

## Inventory Location Rules

### Location Ownership

Inventory locations are stored in:

```text
inv_inventory_locations
```

Fields:

- `branch_id`
- `name`
- `code` nullable
- `type`
- `description` nullable
- `is_active`
- timestamps

Allowed location types:

- `WAREHOUSE`
- `PRODUCTION_ROOM`
- `QC_ROOM`
- `DELIVERY_AREA`
- `CLINIC_ROOM`
- `OTHER`

Every inventory location belongs to exactly one branch.

### Location Isolation

Users may only list, view, update, deactivate, and use locations inside the active branch.

Valid repository pattern:

```php
InventoryLocation::query()
    ->where('branch_id', $branchId)
    ->where('is_active', true)
    ->orderBy('name')
    ->get();
```

Invalid pattern:

```php
InventoryLocation::all();
```

### Location Transfers

Inter-location transfer is not part of Sprint 12.

Do not simulate a transfer in UI by encouraging users to manually create adjustment out plus adjustment in. A future transfer feature must create paired ledger rows in one transaction:

- Outbound movement from source location.
- Inbound movement to destination location.
- Same branch unless inter-branch transfer is explicitly designed.
- Source stock sufficiency checked per location.

### Location Visibility

Stock operation forms must show only active locations in the active branch.

If no active location exists:

- Disable the submit action.
- Show a clear empty/disabled state.
- Do not allow hidden location IDs to be submitted successfully.

Stock card location filters must validate that the requested location belongs to the active branch.

## Branch Rules

### Branch Ownership

Inventory services must use:

```text
app/Modules/Branch/Services/BranchContext.php
```

For branch-owned writes, use:

```php
$branchId = $this->branchContext->requireId();
```

Never trust:

```php
$request->input('branch_id');
```

### Branch Filtering

Repositories must receive `int $branchId` and apply it to every branch-owned query.

Required pattern:

```php
->where('branch_id', $branchId)
```

Required lookup pattern:

```php
public function findInBranch(int $branchId, int $id): ?Product
{
    return Product::query()
        ->where('branch_id', $branchId)
        ->whereKey($id)
        ->first();
}
```

### Cross-Branch Protection

Inventory services must reject:

- Product from another branch.
- Location from another branch.
- Supplier from another branch.
- Movement using mismatched product/location branch.
- Stock card filter using another branch's location.
- Dashboard/report rows from another branch.

Tests already verify these behaviors and future changes must preserve them.

## Product Rules

### Product Table

Products are stored in:

```text
inv_products
```

Core fields:

- `branch_id`
- `product_category_id`
- `product_unit_id`
- `name`
- `code`
- `description`
- `minimum_stock`
- `average_cost`
- `is_active`

Product code is unique per branch:

```text
UNIQUE(branch_id, code)
```

### Product Lifecycle

Products are branch-owned master data.

Allowed lifecycle:

- Create in active branch.
- Update within active branch.
- Deactivate instead of deleting.
- Show inactive products on detail when authorized, but do not allow stock operations for inactive products.

### Active/Inactive Behavior

Inactive products:

- Must not be selectable for stock operations.
- Must not allow opening stock, receive stock, adjustment in, or adjustment out.
- May remain visible in historical movements and stock cards if route authorization allows viewing.

### Units

Units are stored in:

```text
inv_product_units
```

Units are currently global, not branch-owned.

Rules:

- Use active units for product forms.
- Do not duplicate unit definitions per branch unless a future design explicitly changes unit ownership.
- `symbol` is unique.

### Categories

Categories are stored in:

```text
inv_product_categories
```

Rules:

- Categories are branch-owned.
- Category `code` is unique per branch.
- Use active categories for product forms.
- Deactivate categories instead of deleting when referenced.

## Supplier Rules

Suppliers are stored in:

```text
inv_suppliers
```

Core fields:

- `branch_id`
- `name`
- `phone` nullable
- `email` nullable
- `address` nullable
- `is_active`
- timestamps

Rules:

- Suppliers belong to exactly one branch.
- Receive stock may optionally include `supplier_id`.
- If supplier is provided, supplier must belong to active branch.
- If supplier is provided, supplier must be active.
- Supplier from another branch must be rejected.
- Supplier deletion should be deactivation when referenced by movements.

## Inventory Movement Rules

Inventory movement model constants currently include:

- `OPENING`
- `PURCHASE`
- `ADJUSTMENT_IN`
- `ADJUSTMENT_OUT`

All movement writes must:

- Use active branch from BranchContext.
- Require valid active product in branch.
- Require valid active inventory location in branch.
- Validate supplier branch and active status when supplier is provided.
- Reject zero or negative quantity.
- Create a ledger row in `trx_inventory_movements`.
- Set `created_by` from the authenticated user when available.
- Run inside a transaction.

### Opening Stock

Movement type:

```text
OPENING
```

Rules:

- Creates an inbound movement.
- Uses `quantity_in = qty`.
- Uses `quantity_out = 0`.
- Requires active product.
- Requires active location.
- Unit cost may be provided and must be non-negative.
- Intended for initial balance entry.

Invalid:

- Opening stock into inactive product.
- Opening stock into inactive location.
- Opening stock into another branch's location.
- Opening stock with zero or negative quantity.

### Stock In

Current implemented stock-in workflow is receive stock.

Movement type:

```text
PURCHASE
```

Rules:

- Creates an inbound movement.
- Uses `quantity_in = qty`.
- Uses `quantity_out = 0`.
- Supplier is optional.
- Supplier must be active and in active branch when provided.
- Unit cost may be provided and must be non-negative.

Do not interpret purchase movement as a full purchase order. Purchase orders and supplier payments are separate future modules.

### Stock Out

Sprint 12 does not implement production usage, sales issue, delivery issue, inter-location transfer, or general stock-out workflow.

Current implemented outbound operation is adjustment out only.

Movement type:

```text
ADJUSTMENT_OUT
```

Rules:

- Creates an outbound correction movement.
- Uses `quantity_in = 0`.
- Uses `quantity_out = qty`.
- Requires sufficient derived stock in the selected location.
- Must not allow negative location stock.

### Adjustment In

Movement type:

```text
ADJUSTMENT_IN
```

Rules:

- Creates an inbound correction movement.
- Uses `quantity_in = qty`.
- Uses `quantity_out = 0`.
- Does not require supplier.
- Unit cost is currently stored as `0` by the service.
- Requires active product and active location.

### Adjustment Out

Movement type:

```text
ADJUSTMENT_OUT
```

Rules:

- Creates an outbound correction movement.
- Uses `quantity_in = 0`.
- Uses `quantity_out = qty`.
- Does not require supplier.
- Unit cost is currently stored as `0` by the service.
- Requires active product and active location.
- Rejects insufficient stock in that exact location.

## Stock Adjustment Rules

### Valid Scenarios

Valid adjustment in:

- Active product.
- Active location.
- Same active branch.
- Quantity greater than zero.
- Notes optional.

Valid adjustment out:

- Active product.
- Active location.
- Same active branch.
- Quantity greater than zero.
- Location current stock is greater than or equal to requested quantity.
- Notes optional.

### Invalid Scenarios

Invalid adjustment cases:

- Quantity is zero.
- Quantity is negative.
- Product belongs to another branch.
- Location belongs to another branch.
- Product is inactive.
- Location is inactive.
- Adjustment out quantity exceeds stock in selected location.
- Supplier is provided from another branch for receive stock.

### Concurrency Rule

Adjustment out must run inside a transaction and lock relevant product/location rows before checking stock. The implemented service uses `DB::transaction()` and `lockForUpdate()` on product and location rows.

Future high-concurrency inventory work may need stronger ledger-level locking or database constraints, but must preserve the service transaction boundary.

## Stock Opname Rules

Stock opname is the planned Sprint 13 physical count workflow. The codebase currently contains stock opname schema/model/repository/test foundations.

Tables:

- `trx_stock_opnames`
- `trx_stock_opname_items`

Statuses:

- `DRAFT`
- `COUNTING`
- `COMPLETED`
- `CANCELLED`

### Stock Opname Header Rules

Stock opname header stores:

- `branch_id`
- `inventory_location_id`
- `opname_number`
- `opname_date`
- `status`
- `notes`
- `counted_by`
- `created_by`
- `completed_at`
- timestamps

Rules:

- Stock opname belongs to one branch.
- Stock opname belongs to one inventory location.
- Stock opname number is unique per branch.
- Repository lookup must be branch-scoped.
- Finalization lookup must eager-load location and items.

### Stock Opname Item Rules

Stock opname items store physical count snapshots:

- `stock_opname_id`
- `product_id`
- `system_quantity`
- `counted_quantity`
- `variance_quantity`
- `unit_cost`
- `notes`
- timestamps

Rules:

- One product appears once per opname.
- Product must belong to the same branch as the opname.
- `system_quantity` is a snapshot of ledger-derived stock at count time.
- `counted_quantity` is the physical count.
- `variance_quantity = counted_quantity - system_quantity`.
- Item quantities are count records, not stock source of truth.

### Future Finalization Rules

Sprint 13 finalization must:

- Use a service.
- Use a transaction.
- Validate active branch.
- Validate active location.
- Validate opname status.
- Prevent double finalization.
- Compare counted quantity to ledger-derived system quantity.
- Create adjustment movements for non-zero variance.
- Mark opname completed only after all movement rows are created.

Positive variance:

```text
counted_quantity > system_quantity
create ADJUSTMENT_IN for variance
```

Negative variance:

```text
counted_quantity < system_quantity
create ADJUSTMENT_OUT for absolute variance
```

Zero variance:

```text
no inventory movement required
```

Stock opname must never update a product stock column.

## Inventory Valuation Rules

Current implemented valuation:

```text
inventory_value = derived_stock * product.average_cost
```

Per location:

```text
SUM(current_stock(product, location) * product.average_cost)
```

Per branch:

```text
SUM(current_stock(product, branch) * product.average_cost)
```

Rules:

- Valuation uses derived stock.
- Valuation uses `inv_products.average_cost`.
- Unit cost on movements is captured for movement history, not currently used as FIFO/LIFO costing.
- Do not promise accounting-grade valuation from Sprint 12 inventory value.

Forbidden without future design:

- FIFO costing.
- LIFO costing.
- Weighted average recalculation.
- Supplier payment integration.
- Purchase order accruals.
- Inventory forecasting.

## Low Stock Rules

Low stock is calculated from derived stock and `minimum_stock`.

Branch low stock:

```text
SUM(quantity_in - quantity_out across all active branch locations for product)
<= product.minimum_stock
```

Location low stock:

```text
SUM(quantity_in - quantity_out for selected location and product)
<= product.minimum_stock
```

Out of stock:

```text
derived stock <= 0
```

Rules:

- Only active products are considered for low stock reports.
- Location filter must be branch-validated.
- Low stock widgets must not show another branch's product or movement.
- Dashboard low stock count must come from active branch.

## Authorization Rules

Inventory permissions:

- `view_inventory`
- `manage_inventory`

Navigation may also expose inventory to users with broader existing permissions where already implemented, but protected actions must still be authorized.

Inventory policies:

- `InventoryLocationPolicy`
- `ProductPolicy`
- `SupplierPolicy`
- `InventoryMovementPolicy`

Policy concern:

```text
app/Modules/Inventory/Policies/Concerns/ChecksInventoryAccess.php
```

Rules:

- View actions require inventory view/manage permission.
- Create/update/delete actions require inventory manage permission.
- Model view/update/delete actions must verify model branch matches active branch.
- Inventory movement view must verify both movement branch and movement location branch match active branch.
- Super Admin bypass remains centralized in `RepositoryServiceProvider`.
- Do not invent inventory-specific role bypasses.

Controller rules:

- Controllers must call `$this->authorize()` for protected inventory actions.
- Route model binding must be followed by policy or service branch validation.
- Stock operations must authorize product view and movement creation.

## Testing Rules

Inventory tests live under:

```text
tests/Feature/Inventory
```

Required inventory test categories:

- Route authentication.
- Route authorization.
- Product branch access.
- Location branch access.
- Supplier branch validation.
- Stock movement creation.
- Ledger-derived stock by location.
- Ledger-derived stock by branch.
- Zero and negative quantity rejection.
- Insufficient stock rejection.
- Inactive product rejection.
- Inactive location selector exclusion.
- Low stock by branch.
- Low stock by location.
- Inventory valuation from ledger.
- Stock card ordering and running balance.
- Stock card location filter branch isolation.
- Dashboard branch isolation.
- UI empty states.
- Stock operation form location selector.
- Stock opname model/repository branch scoping when stock opname is touched.

Minimum tests for future inventory features:

```text
happy path
validation failure
authorization failure
branch isolation failure
location isolation failure
ledger correctness
UI visibility if Blade changes
```

## Performance Rules

### Query Requirements

Inventory queries must:

- Filter by `branch_id`.
- Filter by `inventory_location_id` when location-specific.
- Use aggregate SQL for stock calculations.
- Eager-load relationships rendered in lists.
- Paginate list pages.
- Preserve query strings on filtered pagination.

Required eager loads for movement views commonly include:

```php
->with(['inventoryLocation', 'product.unit', 'supplier', 'createdBy'])
```

### Required Index Awareness

Inventory schema includes indexes for:

- `branch_id`
- `inventory_location_id`
- `product_id`
- `supplier_id`
- `movement_type`
- `movement_date`
- `reference_type + reference_id`
- `branch_id + inventory_location_id + product_id`
- `branch_id + is_active` on key master tables
- `branch_id + status` for stock opname
- `branch_id + inventory_location_id` for stock opname

Future queries must use these indexed shapes or add explicit indexes when introducing new filters.

### Forbidden Performance Patterns

Forbidden:

```php
InventoryMovement::all();
```

Forbidden:

```php
Product::all();
```

for branch-owned views.

Forbidden in Blade:

```blade
{{ $product->movements()->sum('quantity_in') }}
```

Stock must be precomputed by service/repository and passed to the view.

## Anti-Patterns

### Direct Stock Updates

Forbidden:

```php
$product->update(['stock' => 10]);
```

Reason:

- Breaks ledger.
- Breaks location visibility.
- Breaks stock card.
- Creates hidden branch leakage risk.

### Mutable Stock Columns

Forbidden migration:

```php
$table->decimal('current_stock', 12, 2)->default(0);
```

Reason:

- Creates a second source of truth.
- Conflicts with Sprint 12 design.
- Makes adjustment and opname behavior unsafe.

### Product Stock Field Updates

Forbidden:

```php
$product->decrement('qty_on_hand', $qty);
```

Reason:

- Product does not own final stock.
- Location stock cannot be represented.
- Concurrent adjustments become unsafe.

### Cross-Branch Inventory Access

Forbidden:

```php
InventoryLocation::find($locationId);
```

for submitted location IDs.

Use:

```php
$this->locations->findInBranch($branchId, $locationId);
```

### Request Branch Trust

Forbidden:

```php
$movement['branch_id'] = $request->branch_id;
```

Use:

```php
$movement['branch_id'] = $this->branchContext->requireId();
```

### Business Logic in Blade

Forbidden:

```blade
@php($currentStock = app(InventoryStockService::class)->getCurrentStock($product->id))
```

The controller/service must prepare stock data before rendering.

### Unscoped Stock Card

Forbidden:

```php
InventoryMovement::where('product_id', $productId)
    ->orderBy('movement_date')
    ->get();
```

Required:

```php
InventoryMovement::query()
    ->where('branch_id', $branchId)
    ->where('product_id', $productId)
    ->when($locationId, fn ($query, $value) => $query->where('inventory_location_id', $value))
    ->orderBy('movement_date')
    ->orderBy('id')
    ->get();
```

### Transfer by Manual Adjustment Pair

Forbidden UI guidance:

```text
To move stock between locations, create adjustment out in one location and adjustment in another.
```

Reason:

- Not atomic.
- Does not preserve transfer identity.
- Can create inconsistent stock.
- Inter-location transfer is a future feature.

## AI Agent Inventory Checklist

Before coding any inventory change:

- [ ] Read `docs/inventory_rules.md`.
- [ ] Read `docs/architecture_rules.md`.
- [ ] Read `docs/ai_development_guide.md`.
- [ ] Inspect `app/Modules/Inventory`.
- [ ] Inspect relevant inventory migrations.
- [ ] Inspect relevant tests under `tests/Feature/Inventory`.
- [ ] Identify whether the change is schema, service, repository, policy, controller, request, view, or test only.
- [ ] Identify whether data is branch-owned.
- [ ] Identify whether data is location-owned.
- [ ] Identify active/inactive behavior.
- [ ] Identify required permissions and policies.
- [ ] Identify ledger movement type.
- [ ] Identify whether a transaction is required.
- [ ] Identify branch isolation tests.
- [ ] Confirm no future-sprint features are being implemented accidentally.

During coding:

- [ ] Use `BranchContext::requireId()` for branch-owned writes.
- [ ] Use repository interfaces in services.
- [ ] Keep controllers thin.
- [ ] Keep validation in Form Requests.
- [ ] Keep business rules in services.
- [ ] Keep queries in repositories.
- [ ] Use transactions for movement writes.
- [ ] Derive stock from ledger.
- [ ] Reject invalid branch/location/product/supplier combinations.
- [ ] Add or update focused tests.

## AI Agent Inventory Review Checklist

Before commit or merge:

- [ ] No mutable stock columns were added.
- [ ] No product stock field was updated directly.
- [ ] Every stock value is derived from `trx_inventory_movements`.
- [ ] Every branch-owned query has `branch_id` scope.
- [ ] Every location-specific query has `inventory_location_id` scope.
- [ ] Stock card is ordered by `movement_date ASC, id ASC`.
- [ ] Adjustment out rejects insufficient stock in selected location.
- [ ] Inactive products cannot receive stock operations.
- [ ] Inactive locations are not selectable for stock operations.
- [ ] Supplier branch is validated for receive stock.
- [ ] Policies block cross-branch model access.
- [ ] Controllers call authorization methods.
- [ ] Views do not call services or repositories.
- [ ] Dashboard widgets do not leak other branch data.
- [ ] Low stock respects branch and location filters.
- [ ] Stock opname remains snapshot/ledger-posting, not stock source of truth.
- [ ] Tests cover success and failure paths.
- [ ] Quality gates requested by the user were run or explicitly reported as not run.

## Final Inventory Rule

Inventory must remain boring, auditable, and ledger-driven. Every quantity change is a movement row. Every movement belongs to a branch, location, and product. Every query is branch-safe. Every stock number can be recalculated from history. Anything else is a regression.
