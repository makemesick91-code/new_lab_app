# Sprint 13 Technical Design - Stock Opname

Date: 2026-06-05

Source audit: actual Sprint 13 code, routes, migrations, views, policies, requests, services, repositories, factories, and tests.

## Overview

Sprint 13 adds Stock Opname to the Inventory module. The business purpose is to let a branch perform a physical count for one Inventory Location, compare that count to ledger-derived system stock, review variance, and finalize the difference as inventory adjustment movements.

Stock Opname does not become a stock source of truth. It records count snapshots and posts corrections into the existing movement ledger only at finalization.

```text
Branch
  -> Inventory Location
      -> Stock Opname header
          -> Stock Opname item snapshots
              -> Finalize posts trx_inventory_movements adjustments
```

## Architecture

Sprint 13 stays inside the Laravel modular monolith:

```text
app/Modules/Inventory
  Controllers/StockOpnameController.php
  Requests/*StockOpname*Request.php
  Policies/StockOpnamePolicy.php
  Services/StockOpnameService.php
  Interfaces/StockOpnameRepositoryInterface.php
  Repositories/StockOpnameRepository.php
  Models/StockOpname.php
  Models/StockOpnameItem.php
```

The intended application flow is:

```text
Route
  -> StockOpnameController
      -> Form Request validation
      -> StockOpnamePolicy authorization
      -> StockOpnameService business rules
          -> BranchContext
          -> StockOpnameRepository
          -> InventoryMovementRepository
          -> ProductRepository
          -> InventoryLocationRepository
              -> Eloquent models / database
```

Implementation note: `StockOpnamePolicy` is registered in `RepositoryServiceProvider` following the existing provider policy-map convention. The repository binding is registered in the same provider.

## Controller Flow

`StockOpnameController` owns the HTTP workflow:

| Action | Route name | Responsibility |
|---|---|---|
| `index` | `inventory.stock-opnames.index` | Branch-scoped paginated directory with filters |
| `create` | `inventory.stock-opnames.create` | Active branch location and product selectors |
| `store` | `inventory.stock-opnames.store` | Create a draft opname through the service |
| `show` | `inventory.stock-opnames.show` | Count entry/detail screen |
| `updateCountedQuantity` | `inventory.stock-opnames.update-counted-quantity` | Add/update one product count |
| `review` | `inventory.stock-opnames.review` | Move draft to review-ready `COUNTING` state |
| `reviewScreen` | `inventory.stock-opnames.review-screen` | Read variance summary and Over/Short/Match badges |
| `finalize` | `inventory.stock-opnames.finalize` | Post variance movements |
| `cancel` | `inventory.stock-opnames.cancel` | Cancel an uncompleted opname |

Controllers stay thin: they authorize, receive validated input, call `StockOpnameService`, and return views or redirects.

## Request Flow

Sprint 13 Form Requests:

| Request | Key validation |
|---|---|
| `StoreStockOpnameRequest` | `inventory_location_id`, `opname_date`, optional `product_ids[]`, optional notes |
| `UpdateStockOpnameItemRequest` | required numeric `counted_quantity >= 0`, optional notes |
| `ReviewStockOpnameRequest` | optional notes |
| `FinalizeStockOpnameRequest` | optional notes |
| `CancelStockOpnameRequest` | required cancellation notes |

The Form Requests validate shape and basic existence. Branch ownership, active records, status transitions, and stock rules are enforced in `StockOpnameService`.

## Policy Flow

`StockOpnamePolicy` uses `ChecksInventoryAccess`:

- View actions allow `view_inventory`, `manage_inventory`, or legacy `manage master data`.
- Create/update/review/finalize/cancel require `manage_inventory` or `manage master data`.
- Model actions require the opname `branch_id` to match the active branch from `BranchContext`.

Provider registration: the policy class is wired through `RepositoryServiceProvider`.

## Service Flow

`StockOpnameService` owns all state changes and wraps each workflow in `DB::transaction()`.

```text
createDraftOpname(location, products)
  -> require active branch
  -> validate active location in branch
  -> create DRAFT header
  -> create product snapshot items from ledger current stock

updateCountedQuantity(opname, product, counted)
  -> require active branch
  -> lock opname in branch
  -> reject completed/cancelled
  -> validate active location/product in branch
  -> create missing item snapshot if needed
  -> counted_quantity = physical count
  -> variance_quantity = counted - system

reviewOpname(opname)
  -> lock branch-owned DRAFT opname
  -> require at least one item
  -> status = COUNTING
  -> counted_by = current user

finalizeOpname(opname)
  -> lock branch-owned opname
  -> require COUNTING
  -> reject COMPLETED/CANCELLED
  -> validate active location
  -> lock each product
  -> create adjustment movement for non-zero variance
  -> status = COMPLETED
  -> completed_at = now

cancelOpname(opname)
  -> lock branch-owned opname
  -> reject COMPLETED/CANCELLED
  -> status = CANCELLED
```

## Database Design

### `trx_stock_opnames`

Purpose: branch- and location-owned physical count header.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `branch_id` | Owning branch, FK to `mst_branches` |
| `inventory_location_id` | Counted location, FK to `inv_inventory_locations` |
| `opname_number` | Branch-unique document number |
| `opname_date` | Count/posting date used for generated movements |
| `status` | `DRAFT`, `COUNTING`, `COMPLETED`, `CANCELLED` |
| `notes` | Header notes or cancellation reason |
| `counted_by` | User who reviewed/marked ready |
| `created_by` | User who created the opname |
| `completed_at` | Finalization timestamp |
| `created_at`, `updated_at` | Laravel timestamps |

Indexes and constraints:

- `branch_id`
- `inventory_location_id`
- `status`
- `opname_date`
- composite `branch_id, status`
- composite `branch_id, inventory_location_id`
- unique `branch_id, opname_number`

### `trx_stock_opname_items`

Purpose: per-product count line and variance snapshot.

| Column | Purpose |
|---|---|
| `id` | Primary key |
| `stock_opname_id` | Parent header, FK to `trx_stock_opnames` |
| `product_id` | Counted product, FK to `inv_products` |
| `system_quantity` | Ledger-derived stock captured at snapshot time |
| `counted_quantity` | Physical count entered by user |
| `variance_quantity` | `counted_quantity - system_quantity` |
| `unit_cost` | Product average-cost snapshot for generated adjustments |
| `notes` | Line notes |
| `created_at`, `updated_at` | Laravel timestamps |

Indexes and constraints:

- `stock_opname_id`
- `product_id`
- unique `stock_opname_id, product_id`

## Status Workflow

Implemented statuses live on `StockOpname`:

```text
DRAFT
COUNTING
COMPLETED
CANCELLED
```

User-facing workflow:

```text
DRAFT
  -> COUNTING      (reviewed / ready to finalize)
  -> COMPLETED     (finalized)
```

Cancellation path:

```text
DRAFT -> CANCELLED
COUNTING -> CANCELLED
```

Transition rules:

- Only `DRAFT` can be reviewed.
- Review requires at least one item.
- Review sets status to `COUNTING` and records `counted_by`.
- Only `COUNTING` can be finalized.
- `COMPLETED` and `CANCELLED` opnames cannot be edited.
- `COMPLETED` cannot be cancelled.
- `CANCELLED` cannot be finalized.
- Duplicate finalization is rejected before new movement rows are created.

## Stock Variance Logic

System quantity source:

```text
InventoryMovementRepository::currentStock(branch_id, product_id, inventory_location_id)
```

Counted quantity source:

```text
User-entered counted_quantity on trx_stock_opname_items
```

Formula:

```text
Variance = Counted Quantity - System Quantity
```

Variance types:

| Type | Rule | Finalization behavior |
|---|---|---|
| Over | variance `> 0` | Create `ADJUSTMENT_IN` |
| Short | variance `< 0` | Create `ADJUSTMENT_OUT` for absolute variance |
| Match | variance `= 0` | No movement |

## Finalization Process

Finalization is transactional.

```text
BEGIN
  lock trx_stock_opnames row for active branch
  validate status = COUNTING
  validate active location in branch
  load item lines
  for each item:
    lock active product in branch
    if variance > 0:
      create ADJUSTMENT_IN movement
    if variance < 0:
      check current location stock
      create ADJUSTMENT_OUT movement
  update opname status to COMPLETED
  set completed_at
COMMIT
```

Generated movement fields:

- `branch_id` from the opname.
- `inventory_location_id` from the opname.
- `product_id` from the item product.
- `movement_type` = `ADJUSTMENT_IN` or `ADJUSTMENT_OUT`.
- `movement_date` = `opname_date`.
- `quantity_in` or `quantity_out` from variance.
- `unit_cost` from item cost snapshot.
- `reference_type = trx_stock_opnames`.
- `reference_id = stock_opnames.id`.
- `notes = Generated from stock opname <opname_number>`.

Duplicate prevention:

- A `COMPLETED` opname throws a validation error when finalized again.
- A failed finalization rolls back generated movements and keeps the opname in `COUNTING`.
- Zero-variance items create no movement rows.

## Security

Branch isolation:

- `StockOpnameService` resolves active branch through `BranchContext::requireId()`.
- Repository list and lookup methods accept `branchId` and apply `where('branch_id', $branchId)`.
- Submitted location and product IDs are reloaded through active-branch repository methods.
- Opname mutation uses a locked branch-scoped lookup.

Authorization:

- Controller actions call `$this->authorize()`.
- `StockOpnamePolicy` checks inventory permissions and active-branch ownership.
- `RepositoryServiceProvider` registers `StockOpname::class => StockOpnamePolicy::class`.

Validation:

- Form Requests validate required fields, dates, arrays, numeric counts, and cancellation notes.
- Service validation rejects inactive or cross-branch products/locations, invalid status transitions, negative counts, duplicate finalization, and insufficient stock for shortage posting.

## UX Decisions

Variance review workflow:

- Detail screen supports entry and update of counts while the opname is `DRAFT` or `COUNTING`.
- Review action turns a `DRAFT` count into `COUNTING`, the implemented ready-to-finalize state.
- Review screen gives a read-only variance summary before finalization.

Summary cards:

- Review screen shows Total Products, Total Variances, Overages, and Shortages.
- Cards focus the reviewer on exception count, not just the raw item table.

Badge system:

- Status badges distinguish `DRAFT`, `COUNTING`, `COMPLETED`, and `CANCELLED`.
- Variance badges classify each line as Over, Short, or Match.

Read-only state after finalization:

- Count fields and add-product controls are rendered only for `DRAFT` and `COUNTING`.
- `COMPLETED` and `CANCELLED` lines display values without editable controls.

User validation experience:

- Create and update forms show field-level validation messages.
- Cancellation requires an explicit reason.
- Service-level validation returns domain errors for invalid branch, status, or stock conditions.

## Testing

Sprint 13 focused tests:

| Test file | Coverage |
|---|---|
| `StockOpnameModelTest.php` | Relationships, casts, statuses, fillable behavior, branch-consistent factory lines |
| `StockOpnameRepositoryTest.php` | Interface binding, branch-scoped pagination/find/finalize lookup, eager loading |
| `StockOpnameRequestTest.php` | Store, update, review, finalize, cancel validation rules |
| `StockOpnameServiceTest.php` | Draft snapshots, count update, review, finalize, zero variance, duplicate finalize, read-only state, branch isolation, cancellation, rollback, review UI summary |

Current verification during this documentation pass:

- `php artisan test tests\Feature\Inventory\StockOpnameModelTest.php tests\Feature\Inventory\StockOpnameRepositoryTest.php tests\Feature\Inventory\StockOpnameRequestTest.php tests\Feature\Inventory\StockOpnameServiceTest.php`: passed, 31 tests / 99 assertions.
- `php artisan test --filter=StockOpname`: passed, 31 tests / 99 assertions.
- `npm.cmd run build`: passed.
- `php artisan route:list --name=inventory.stock-opnames`: passed, 9 routes.
- `.\vendor\bin\pint`: passed and fixed Sprint 13 formatting findings.
- `php artisan test tests\Feature\Inventory`: failed, 55 passed / 4 failed, with failures limited to existing non-Stock-Opname `InventoryRouteAuthorizationTest` POST assertions receiving 419 CSRF responses.
- Full `php artisan test`: previously timed out after 120 seconds and showed failures outside the Sprint 13 Stock Opname focus area.

## Reusable Patterns

Review -> Finalize workflow:

- Draft records collect operational input.
- Review step freezes intent by moving to a ready state.
- Finalize performs irreversible ledger side effects in a transaction.

Status transition workflow:

- Status constants live on the model.
- Service methods own transition rules.
- Controllers expose action routes; they do not contain status rules.

Inventory approval workflow:

- User-entered quantities are not stock.
- Approval/finalization posts stock-changing ledger entries.
- Finalization references the source transaction for auditability.

Variance review workflow:

- Snapshot expected state.
- Capture actual state.
- Calculate variance.
- Review exceptions with summary cards and badges.
- Post only non-zero variance.

Branch-scoped repository workflow:

- Repository reads take `branchId` first.
- Service resolves branch through `BranchContext`.
- Route-bound models are followed by authorization or service validation.
