# Sprint 17.6 - Inventory Category And Unit CRUD

Date: 2026-06-07
Status: Implemented
Module: `app/Modules/Inventory`

## Background

Inventory products already require a product category and product unit. Before this step, users could create and edit products, but the sidebar did not expose CRUD screens for product categories or product units.

## Existing Gap

Route inspection showed:

- `inventory/products` existed.
- `inventory/product-categories` did not exist.
- `inventory/product-units` did not exist.

This made product setup incomplete from the application UI because users could not maintain the required category/unit masters directly.

## Existing Model/Repository Found

No new tables, models, or repositories were created.

Existing category assets:

- `ProductCategory` model.
- `ProductCategoryRepositoryInterface`.
- `ProductCategoryRepository`.
- `inv_product_categories` table.
- Branch-scoped lookup via `findInBranch(int $branchId, int $id)`.

Existing unit assets:

- `ProductUnit` model.
- `ProductUnitRepositoryInterface`.
- `ProductUnitRepository`.
- `inv_product_units` table.
- Global lookup via `find(int $id)`.

Existing product integration:

- `Product::category()` relation.
- `Product::unit()` relation.
- `StoreProductRequest` requires `product_category_id` and `product_unit_id`.
- `UpdateProductRequest` requires `product_category_id` and `product_unit_id`.
- `InventoryProductService::listActiveCategories()` and `listActiveUnits()` already feed the product forms.

## Routes Added

Product categories:

- `GET inventory/product-categories`
- `GET inventory/product-categories/create`
- `POST inventory/product-categories`
- `GET inventory/product-categories/{productCategory}/edit`
- `PUT/PATCH inventory/product-categories/{productCategory}`
- `DELETE inventory/product-categories/{productCategory}`

Product units:

- `GET inventory/product-units`
- `GET inventory/product-units/create`
- `POST inventory/product-units`
- `GET inventory/product-units/{productUnit}/edit`
- `PUT/PATCH inventory/product-units/{productUnit}`
- `DELETE inventory/product-units/{productUnit}`

## Controllers Added

- `app/Modules/Inventory/Controllers/ProductCategoryController.php`
- `app/Modules/Inventory/Controllers/ProductUnitController.php`

Controllers use existing repository interfaces and policy authorization. Category writes stamp `branch_id` from `BranchContext`, never from user input.

## Requests Added

- `StoreProductCategoryRequest`
- `UpdateProductCategoryRequest`
- `StoreProductUnitRequest`
- `UpdateProductUnitRequest`

Validation follows existing schema:

- Category `name` max 150.
- Category `code` nullable max 50 and unique per active branch scope.
- Unit `name` max 100.
- Unit `symbol` required max 20 and globally unique.
- Descriptions are nullable text.
- `is_active` is boolean when submitted.

## Views Added

Product category views:

- `resources/views/inventory/product-categories/index.blade.php`
- `resources/views/inventory/product-categories/create.blade.php`
- `resources/views/inventory/product-categories/edit.blade.php`
- `resources/views/inventory/product-categories/_form.blade.php`

Product unit views:

- `resources/views/inventory/product-units/index.blade.php`
- `resources/views/inventory/product-units/create.blade.php`
- `resources/views/inventory/product-units/edit.blade.php`
- `resources/views/inventory/product-units/_form.blade.php`

UI follows Inventory conventions:

- `<x-settings-shell>`.
- Teal primary action.
- Filter card.
- Desktop table plus mobile cards.
- Active/inactive badge.
- Empty state.
- Permission-gated create/edit/deactivate actions.

## Sidebar Changes

Updated canonical sidebar:

- `resources/views/layouts/sidebar.blade.php`

Inventory/Persediaan order now includes:

- Dasbor
- Produk
- Kategori Produk
- Satuan Produk
- Lokasi Persediaan
- Pemasok
- Stok

Menu visibility is policy/permission-gated through existing inventory access (`view_inventory` / `manage_inventory` via model policies).

## Product Form Integration

Product create/edit still uses:

- `InventoryProductService::listActiveCategories()`
- `InventoryProductService::listActiveUnits()`

Added lightweight warnings when no active category or unit exists:

- `Tambahkan Kategori Produk terlebih dahulu.`
- `Tambahkan Satuan Produk terlebih dahulu.`

No product workflow, stock workflow, inventory movement, stock transfer, or procurement behavior was changed.

## Authorization

New policies:

- `ProductCategoryPolicy`
- `ProductUnitPolicy`

Rules:

- View/index uses existing inventory view permission path.
- Create/update/deactivate uses existing inventory manage permission path.
- Category update/deactivate also requires active-branch ownership.
- Unit update/deactivate is global, matching the existing global unit repository/table design.
- No new granular permission was added.

## Deactivate Rules

Delete routes call repository `deactivate()` methods:

- Category `destroy()` sets `is_active = false`.
- Unit `destroy()` sets `is_active = false`.

No hard delete was added. Existing products can keep historical references to inactive categories/units.

## Tests

Added:

- `tests/Feature/Inventory/ProductCategoryCrudTest.php`
- `tests/Feature/Inventory/ProductUnitCrudTest.php`

Coverage:

- Authorized index/create access.
- Valid store.
- Required name validation.
- Edit/update.
- Deactivate instead of hard delete.
- Unauthorized denial.
- Category cross-branch update/deactivate denial.
- Sidebar menu visibility for authorized users.
- Sidebar menu hidden for unauthorized users.

## Manual Browser Checklist

- Login as a user with `manage_inventory`.
- Open Persediaan sidebar.
- Confirm menu order includes Produk, Kategori Produk, Satuan Produk, Lokasi Persediaan.
- Open Kategori Produk index.
- Create category with name and optional code.
- Edit category.
- Deactivate category and confirm inactive badge.
- Open Satuan Produk index.
- Create unit with name and symbol.
- Edit unit.
- Deactivate unit and confirm inactive badge.
- Open Tambah Produk.
- Confirm category and unit selectors include active records.
- Deactivate a category/unit and confirm inactive records are no longer selectable for new product forms.
- Login as a user without inventory permission and confirm sidebar links are hidden.
- Confirm no stock transfer, inventory movement, or procurement screen behavior changed.

## Quality Gate Result

Commands run:

```text
php artisan route:list | findstr /i "product-categories product-units"
php artisan test --filter=ProductCategoryCrudTest
php artisan test --filter=ProductUnitCrudTest
php artisan test --filter=Inventory
npm run build
vendor/bin/pint --test
git diff --check
```

Results:

- `php artisan route:list | findstr /i "product-categories product-units"` passed and listed 12 routes.
- `php artisan test --filter=ProductCategoryCrudTest` passed: 9 tests, 37 assertions.
- `php artisan test --filter=ProductUnitCrudTest` passed: 7 tests, 27 assertions.
- `php artisan test --filter=Inventory` passed: 935 tests, 3763 assertions, duration about 3m 44s.
- `npm run build` passed: 56 Vite modules transformed.
- Initial `vendor/bin/pint --test` reported ordered import fixes needed in `RepositoryServiceProvider.php` and `routes/web.php`.
- `vendor/bin/pint app/Providers/RepositoryServiceProvider.php routes/web.php` fixed ordered imports.
- Final `vendor/bin/pint --test` passed.
- `git diff --check` passed.

## Out Of Scope

Still out of scope:

- Stock workflow changes.
- Inventory movement changes.
- Stock transfer changes.
- Procurement changes.
- Mutable stock/current stock columns.
- New product category/unit tables.
- New product category/unit repositories.
- New granular permissions.
