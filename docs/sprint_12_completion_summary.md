# Sprint 12 Inventory Core Completion Summary

## Sprint Scope Completed

Sprint 12 Inventory Core is complete for branch-aware inventory per Inventory Location.

Completed scope:
- Product categories
- Product units
- Products/materials
- Suppliers
- Inventory locations per branch
- Inventory movement ledger
- Opening stock
- Receive stock
- Adjustment in
- Adjustment out
- Current stock calculation
- Stock card with running balance
- Low stock detection
- Inventory dashboard summary
- Branch/location isolation in repository, service, policy, controller, and UI paths

## Files Created or Modified Summary

Created Inventory module files:
- `app/Modules/Inventory/Models/*`
- `app/Modules/Inventory/Interfaces/*`
- `app/Modules/Inventory/Repositories/*`
- `app/Modules/Inventory/Services/*`
- `app/Modules/Inventory/Requests/*`
- `app/Modules/Inventory/Policies/*`
- `app/Modules/Inventory/Controllers/*`

Created database/support files:
- `database/migrations/2026_06_04_120000_create_inventory_core_tables.php`
- Inventory factories under `database/factories`
- `database/seeders/InventorySeeder.php`

Created views:
- `resources/views/inventory/dashboard.blade.php`
- `resources/views/inventory/locations/*`
- `resources/views/inventory/products/*`
- `resources/views/inventory/suppliers/*`
- `resources/views/inventory/stock/*`

Modified shared wiring:
- `app/Providers/RepositoryServiceProvider.php`
- `routes/web.php`
- `resources/views/layouts/sidebar.blade.php`
- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`

Created/updated tests:
- `tests/Feature/Inventory/InventoryStockServiceTest.php`
- `tests/Feature/Inventory/InventoryRouteAuthorizationTest.php`
- `tests/Feature/Inventory/InventoryUiTest.php`

## Tables Created

- `inv_product_categories`
- `inv_product_units`
- `inv_products`
- `inv_suppliers`
- `inv_inventory_locations`
- `trx_inventory_movements`

No mutable stock source-of-truth column was added. Product stock is derived only from `trx_inventory_movements`.

## Routes Added

Inventory routes are registered under the `inventory.*` route namespace:
- `GET inventory/dashboard`
- `resource inventory/locations`
- `resource inventory/products`
- `resource inventory/suppliers`
- `GET inventory/stock`
- `GET inventory/products/{product}/stock-card`
- `GET|POST inventory/products/{product}/opening-stock`
- `GET|POST inventory/products/{product}/receive-stock`
- `GET|POST inventory/products/{product}/adjust-in`
- `GET|POST inventory/products/{product}/adjust-out`

## Services and Repositories Added

Repositories:
- `InventoryLocationRepository`
- `ProductCategoryRepository`
- `ProductUnitRepository`
- `ProductRepository`
- `SupplierRepository`
- `InventoryMovementRepository`

Services:
- `InventoryLocationService`
- `InventoryProductService`
- `InventorySupplierService`
- `InventoryStockService`

Key service behavior:
- Uses `BranchContext::requireId()` for branch-owned operations.
- Validates product, location, and supplier branch ownership.
- Rejects zero or negative stock quantities.
- Rejects adjustment out when location stock is insufficient.
- Rejects stock writes for inactive products and inactive locations.
- Calculates stock from ledger using `SUM(quantity_in) - SUM(quantity_out)`.

## Views Added

Inventory UI follows the existing `x-settings-shell` layout and table/form conventions:
- Dashboard cards for inventory value, low stock count, and out-of-stock count
- Product list with current branch stock and stock status badge
- Location list and CRUD screens
- Supplier list and CRUD screens
- Stock index by product/location
- Stock card with movement date, type, location, quantities, running balance, unit cost, and notes
- Stock operation forms with required Inventory Location selector

Navigation:
- Added Inventory sidebar group with Dashboard, Products, Locations, Suppliers, and Stock.
- Visibility follows existing Spatie permission style.

## Permissions

Added Sprint 12 permissions using the existing permission seeder pattern:
- `manage_inventory`
- `view_inventory`

Role assignment:
- `Super Admin`: all permissions through existing wildcard seeding
- `Admin Lab`: manage and view inventory
- `Technician`: view inventory
- `Quality Control`: view inventory

Policies also retain compatibility with the existing coarse `manage master data` permission used by earlier master-data workflows.

## Tests Added

Inventory tests cover:
- Auth requirements for inventory routes
- Product access inside active branch
- Cross-branch product denial
- Opening stock creation
- Cross-branch location rejection
- Cross-branch supplier rejection
- Current stock by location and branch
- Insufficient stock rejection for adjustment out
- Stock card ordering and running balance
- Low stock and inventory value from ledger
- Low stock respecting location filters
- Inactive location not selectable for stock operations
- Inactive product stock operation rejection
- Stock card location filter isolation
- Dashboard isolation from other branch movements

## Quality Gate Results

Final Step 7 quality gates:
- `php artisan route:list`: passed, 171 routes
- `php artisan migrate:fresh --seed`: passed
- `php artisan test`: passed, 395 tests / 941 assertions
- `npm.cmd run build`: passed
- `.\vendor\bin\pint`: passed
- `git status`: reviewed

## Known Limitations

- Branch switching is still limited by the current minimal `BranchContext`; it falls back to `MAIN` unless user branch assignment support is added later.
- Average cost is stored on product and used for inventory value display, but weighted average cost recalculation is not implemented.
- Stock rows are ledger-derived and show products/locations that have movement history; products with no movements are visible in product/low-stock views.
- Inventory permissions are coarse-grained for Sprint 12.
- No audit/status logging was added for inventory movement workflows yet.

## Out of Scope Confirmed

The following future-sprint features were not implemented:
- Purchase Order
- Stock Opname
- Production Usage
- Bill of Materials
- Inter-location transfer
- Inter-branch transfer
- Supplier payment
- Inventory forecasting

## Recommended Sprint 13 Scope

Recommended Sprint 13:
Stock Adjustment and Stock Opname per Inventory Location.

Suggested Sprint 13 items:
- Stock opname session per branch/location
- Count sheet entry and approval workflow
- Variance calculation from ledger-derived stock
- Approved variance posting as inventory adjustment movements
- Audit log for stock opname lifecycle
- Permissions and policies for stock opname roles
- Reports for stock variance and adjustment history
