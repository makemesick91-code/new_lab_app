# Sprint 13 Completion Summary - Stock Opname

Date: 2026-06-05

## Sprint Overview

| Field | Summary |
|---|---|
| Sprint | 13 |
| Module | Inventory |
| Objective | Implement branch- and location-aware Stock Opname with physical counts, variance review, finalization, cancellation, and ledger adjustment posting |

Sprint 13 extends Sprint 12 Inventory Core without changing the source of stock truth. Count records are snapshots; final stock remains derived from `trx_inventory_movements`.

## Deliverables Completed

### Database

Created tables:

- `trx_stock_opnames`
- `trx_stock_opname_items`

Key constraints:

- `trx_stock_opnames.branch_id` FK to `mst_branches`.
- `trx_stock_opnames.inventory_location_id` FK to `inv_inventory_locations`.
- Unique `branch_id + opname_number`.
- `trx_stock_opname_items.stock_opname_id` FK to `trx_stock_opnames`.
- `trx_stock_opname_items.product_id` FK to `inv_products`.
- Unique `stock_opname_id + product_id`.

### Models

Added:

- `App\Modules\Inventory\Models\StockOpname`
- `App\Modules\Inventory\Models\StockOpnameItem`

Model behavior:

- Status constants: `DRAFT`, `COUNTING`, `COMPLETED`, `CANCELLED`.
- Relationships to branch, inventory location, users, items, and products.
- Date and decimal casts.
- Factories for test data.

### Services

Added:

- `App\Modules\Inventory\Services\StockOpnameService`

Implemented methods:

- `createDraftOpname()`
- `updateCountedQuantity()`
- `reviewOpname()`
- `finalizeOpname()`
- `cancelOpname()`

Important service behavior:

- Uses `BranchContext::requireId()`.
- Uses transactions for every write workflow.
- Uses branch-scoped and active-record validation for locations/products.
- Captures system quantity from ledger-derived current stock.
- Calculates `variance_quantity = counted_quantity - system_quantity`.
- Generates adjustment movements on finalize only.
- Prevents duplicate finalization.
- Rolls back generated movements when finalization fails.

### Repositories

Added:

- `StockOpnameRepositoryInterface`
- `StockOpnameRepository`

Implemented methods:

- `paginate()`
- `create()`
- `update()`
- `findById()`
- `loadItems()`
- `finalizeLookup()`

Repository reads are branch-scoped.

### Requests

Added:

- `StoreStockOpnameRequest`
- `UpdateStockOpnameItemRequest`
- `ReviewStockOpnameRequest`
- `FinalizeStockOpnameRequest`
- `CancelStockOpnameRequest`

### Policies

Added:

- `StockOpnamePolicy`

Implemented abilities:

- `viewAny`
- `view`
- `create`
- `update`
- `delete`
- `review`
- `finalize`
- `cancel`

Current integration note: the policy class is registered in `RepositoryServiceProvider` using the existing provider policy-map convention.

### Controllers

Added:

- `StockOpnameController`

Implemented actions:

- `index`
- `create`
- `store`
- `show`
- `updateCountedQuantity`
- `review`
- `reviewScreen`
- `finalize`
- `cancel`

### Views

Added:

- `resources/views/inventory/stock-opnames/index.blade.php`
- `resources/views/inventory/stock-opnames/create.blade.php`
- `resources/views/inventory/stock-opnames/show.blade.php`
- `resources/views/inventory/stock-opnames/review.blade.php`

Screens added:

- Stock Opname directory with filters.
- Create Stock Opname form.
- Count entry/detail screen.
- Variance review screen with summary cards and Over/Short/Match badges.

### Routes

Added under `inventory.stock-opnames.*`:

| Method | URI | Name |
|---|---|---|
| GET | `inventory/stock-opnames` | `inventory.stock-opnames.index` |
| GET | `inventory/stock-opnames/create` | `inventory.stock-opnames.create` |
| POST | `inventory/stock-opnames` | `inventory.stock-opnames.store` |
| GET | `inventory/stock-opnames/{stock_opname}` | `inventory.stock-opnames.show` |
| GET | `inventory/stock-opnames/{stockOpname}/review` | `inventory.stock-opnames.review-screen` |
| POST | `inventory/stock-opnames/{stockOpname}/review` | `inventory.stock-opnames.review` |
| POST | `inventory/stock-opnames/{stockOpname}/finalize` | `inventory.stock-opnames.finalize` |
| POST | `inventory/stock-opnames/{stockOpname}/cancel` | `inventory.stock-opnames.cancel` |
| POST | `inventory/stock-opnames/{stockOpname}/products/{productId}/counted-quantity` | `inventory.stock-opnames.update-counted-quantity` |

### Tests

Added Sprint 13 test coverage:

- `tests/Feature/Inventory/StockOpnameModelTest.php`
- `tests/Feature/Inventory/StockOpnameRepositoryTest.php`
- `tests/Feature/Inventory/StockOpnameRequestTest.php`
- `tests/Feature/Inventory/StockOpnameServiceTest.php`

Sprint 13 focused tests currently pass: 31 tests / 99 assertions.

## Key Features

Draft stock opname:

- Create a branch-owned, location-owned count document.
- Optionally preselect products.
- Generate branch-unique opname number.
- Snapshot `system_quantity` from ledger current stock.

Counting workflow:

- Add products to an existing draft/counting opname.
- Update counted quantities and notes.
- Automatically calculate variance.

Review workflow:

- `reviewOpname()` moves `DRAFT` to `COUNTING`.
- `COUNTING` is the implemented ready-to-finalize state.
- Review records `counted_by`.

Variance review:

- Review screen shows total products, total variances, overages, and shortages.
- Table shows system quantity, counted quantity, variance, and Over/Short/Match classification.

Finalization workflow:

- Only `COUNTING` can be finalized.
- Positive variance creates `ADJUSTMENT_IN`.
- Negative variance creates `ADJUSTMENT_OUT`.
- Zero variance creates no movement.
- Generated movements reference `trx_stock_opnames`.
- Header is marked `COMPLETED` only after posting succeeds.

Cancellation workflow:

- `DRAFT` and `COUNTING` can be cancelled.
- Cancellation requires notes through `CancelStockOpnameRequest`.
- Cancelled opnames cannot be finalized.

Read-only completed state:

- Editable count controls are only rendered for `DRAFT` and `COUNTING`.
- `COMPLETED` and `CANCELLED` records render as read-only count history.

## Quality Gates

Current verification during documentation generation:

| Gate | Result |
|---|---|
| Stock Opname focused tests | PASS: 31 tests / 99 assertions |
| `php artisan route:list --name=inventory.stock-opnames` | PASS: 9 routes |
| `npm.cmd run build` | PASS |
| Full `php artisan test` | NOT CLEAN: timed out after 120 seconds and showed failures outside Sprint 13 Stock Opname |
| `.\vendor\bin\pint` | PASS: formatting applied |
| `php artisan test tests\Feature\Inventory` | NOT CLEAN: 55 passed / 4 failed, with failures in non-Stock-Opname route authorization POST tests returning 419 CSRF responses |

## Risks Addressed

Duplicate finalization:

- `finalizeOpname()` rejects `COMPLETED` records before creating new movements.
- Test coverage proves second finalization throws and does not duplicate movements.

Direct stock mutation:

- No product stock column is updated.
- Adjustments are generated as ledger rows in `trx_inventory_movements`.

Cross-branch access:

- Service methods use `BranchContext::requireId()`.
- Repository reads are branch-scoped.
- Submitted products and locations are revalidated in active branch.

Partial posting:

- Finalization is transactional.
- A test proves generated movements are rolled back if one item fails branch/product validation.

Invalid stock decrease:

- Negative variance checks current location stock before creating `ADJUSTMENT_OUT`.

## Known Limitations

- Full Inventory route authorization coverage is still not clean because four non-Stock-Opname POST route tests return 419 CSRF responses.
- There is no dedicated Stock Opname route authorization test file; service, repository, request, model, and review-screen tests exist.
- `COUNTING` is the implemented reviewed/ready state; there is no separate `REVIEW` or `FINALIZED` status name.
- No inventory audit-log entries are created for Stock Opname lifecycle events beyond the movement ledger reference.
- No item removal workflow exists; counts can be added or updated.
- Full test suite is not clean in the current workspace.

## Sprint Outcome

Sprint 13 Stock Opname is functionally implemented for the core inventory workflow: draft count, count entry, variance review, review-ready transition, finalization into ledger adjustments, cancellation, branch/service isolation, policy registration, and focused tests.

Release readiness is improved for Sprint 13 because the policy registration, review-screen action visibility, and Pint findings are fixed. The remaining test concern is outside the Stock Opname flow: non-Stock-Opname Inventory route authorization POST tests still return 419 CSRF responses.
