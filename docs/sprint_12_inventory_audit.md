# Sprint 12 Inventory Core - Pre-Implementation Audit

Date: 2026-06-04

Scope: read-only audit of the current Laravel modular monolith before Sprint 12 Inventory Core. No migrations, model changes, or business logic changes were made for this audit.

## Existing Architecture Patterns

The application follows a modular monolith under `app/Modules`. Existing modules include:

- `AccessControl`
- `Branch`
- `Clinic`
- `Delivery`
- `Doctor`
- `Invoice`
- `Lab`
- `LabOrder`
- `LabService`
- `Patient`
- `Production`
- `QualityControl`
- `Reporting`
- `Shared`
- `Technician`
- `User`

`Lab` and `Shared` currently contain no files. Inventory should therefore be introduced as its own module rather than placed in either empty directory unless a later architecture decision explicitly repurposes them.

The dominant flow is:

```text
Controller -> Request -> Service -> Repository -> Model
```

Layer conventions:

- Controllers live in `app/Modules/{Module}/Controllers` and are named `{Domain}Controller`, for example `LabOrderController`, `ClinicController`, `DeliveryController`.
- Requests live in `app/Modules/{Module}/Requests` and are named by action, for example `StoreClinicRequest`, `UpdateLabOrderRequest`, `CreateDeliveryRequest`, `PassQcRequest`.
- Services live in `app/Modules/{Module}/Services` and own business rules and transactions. Examples: `LabOrderService`, `DeliveryWorkflowService`, `PaymentService`.
- Repositories live in `app/Modules/{Module}/Repositories` and compose persistence queries only. Examples: `ClinicRepository`, `LabOrderRepository`, `InvoiceRepository`.
- Interfaces live in `app/Modules/{Module}/Interfaces` and are named `{Domain}RepositoryInterface`.
- Models live in `app/Modules/{Module}/Models`, set explicit table names, define `$fillable`, casts, relationships, and `newFactory()`.
- Policies live in `app/Modules/{Module}/Policies`. CRUD modules use model policies; workflow modules also use named gates.
- Repository bindings, model policies, named gates, and morph maps are registered in `app/Providers/RepositoryServiceProvider.php`.

Repository conventions:

- `paginate(array $filters = [], int $perPage = 10|15)` for list pages.
- `listAll()` or `listActive()` for select options.
- `findById()` or `find()` for retrieval.
- `create(array $data)`, `update(Model $model, array $data)`, `delete(Model $model)` for persistence.
- Search filters use `mb_strtolower()` with `LOWER(column) LIKE ?`.
- Repositories return paginators with `withQueryString()`.
- Services wrap mutations in `DB::transaction(...)`.

View conventions:

- Master data screens are under `resources/views/settings/{plural}` with `index.blade.php`, `create.blade.php`, `edit.blade.php`, and `_form.blade.php`.
- Operational modules use root-level folders, for example `resources/views/lab-orders`, `resources/views/deliveries`, `resources/views/invoices`, `resources/views/production`, and `resources/views/quality-control`.
- Pages use `<x-settings-shell title="...">`, which renders the app layout, sidebar, status messages, and validation errors.
- Forms are plain Blade/Tailwind with method spoofing where needed.
- Sidebar links are permission-aware and live in `resources/views/layouts/sidebar.blade.php`.

Route conventions:

- All feature routes use `auth` middleware.
- Settings/master data routes live under `prefix('settings')->name('settings.')`.
- Master data commonly uses `Route::resource(...)->except(['show'])`.
- Operational modules use top-level route names such as `lab-orders.*`, `production.*`, `quality-control.*`, `deliveries.*`, `invoices.*`, and `reports.*`.
- Route middleware uses Spatie permission strings, for example `permission:view_lab_orders|manage_lab_orders`.
- Controllers still call `$this->authorize(...)`; routes provide coarse access and policies/gates provide model/action enforcement.

Permission conventions:

- Permissions are seeded in `database/seeders/PermissionSeeder.php`.
- Role mappings are seeded in `database/seeders/RoleSeeder.php`.
- Older permissions use spaces, for example `manage lab services`; newer workflow permissions use underscores, for example `view_lab_orders`, `manage_delivery`, `create_payment`.
- `Gate::before` grants Super Admin a global bypass.

## Existing Branch Enforcement Pattern

Branch module status in the inspected code:

- `app/Modules/Branch` exists with `BranchController`, requests, service, repository, interface, policy, model, factory, and seeder.
- `mst_branches` exists with `code`, `name`, `address`, `phone`, `is_active`, timestamps, and soft deletes.
- `Branch::MAIN_CODE` is `MAIN`.
- `BranchSeeder` creates the default MAIN branch and runs first in `DatabaseSeeder`.
- `BranchRepositoryInterface` is bound to `BranchRepository`.
- `BranchPolicy` is registered but documented as skeleton/foundation behavior.

Branch ownership currently exists on these transaction models:

- `trx_lab_orders.branch_id`
- `trx_lab_deliveries.branch_id`
- `trx_invoices.branch_id`
- `trx_payments.branch_id`

The `branch_id` columns are nullable foreign keys to `mst_branches` with `nullOnDelete()`. A backfill migration updates existing NULL branch values to the MAIN branch when MAIN exists.

Runtime branch scoping is not fully enforced in the inspected code:

- `LabOrderRepository`, `DeliveryRepository`, and `InvoiceRepository` include an opt-in filter:

```php
->when($filters['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
```

- Comments explicitly say this is not enforced unless a caller passes `branch_id`.
- `PaymentRepository` documents that payments inherit invoice branch scope, but no global payment branch listing is implemented.
- No class, service, helper, middleware, or binding named `CurrentBranch` was found in `app`, `routes`, `tests`, `database`, `resources`, or `docs`.
- No inspected controller currently derives a branch from the authenticated user and injects it into repository filters.
- No inspected write path consistently stamps `branch_id` from a current branch service.

Important conclusion for Sprint 12:

Inventory should not assume an active `CurrentBranch` service exists. If Sprint 12 requires branch-scoped inventory, implement it deliberately or integrate with the branch foundation by passing/stamping `branch_id` explicitly until a real CurrentBranch abstraction is present.

## Audit Logs And Status Logs

Audit logging is centralized in `App\Modules\LabOrder\Services\AuditLogService` and persists to `sys_audit_logs`.

Audit pattern:

- Models that should have polymorphic audit logs define an `ENTITY_TYPE` constant with the table/entity name.
- `RepositoryServiceProvider` registers a morph map for audited entities:
  - `LabOrder::ENTITY_TYPE`
  - `QualityControl::ENTITY_TYPE`
  - `Delivery::ENTITY_TYPE`
  - `Invoice::ENTITY_TYPE`
  - `Payment::ENTITY_TYPE`
- `AuditLogService::log(...)` writes:
  - `entity_type`
  - `entity_id`
  - `action`
  - `old_values`
  - `new_values`
  - `performed_by`
  - `performed_at`
  - `ip_address`
  - `user_agent`
- `AuditLog` defines action constants across Lab Order, Production, QC, Delivery, Invoice, and Payment.
- Services call audit logging inside transaction blocks after important mutations.

Status logging is Lab Order specific:

- `StatusLogService` writes append-only rows to `trx_lab_order_status_logs`.
- `LabOrderStatusLog` stores `lab_order_id`, `old_status`, `new_status`, `notes`, `changed_by`, and `changed_at`.
- Production, QC, and Delivery transitions update the Lab Order status and write status logs.
- Invoice and Payment status changes use audit logs, not a separate status log table.

Inventory implication:

- If inventory only needs mutation auditability, follow the `AuditLogService` pattern and add inventory model `ENTITY_TYPE` values to the morph map.
- If inventory needs an operational timeline, create an inventory-specific status/movement log instead of reusing `trx_lab_order_status_logs`.
- Stock movement history should likely be modeled as domain data, not only as audit logs.

## Existing Tests And Factories

Test conventions:

- Feature tests use Pest in most modules.
- `tests/Pest.php` applies `RefreshDatabase` to all Feature tests.
- Common helpers include:
  - `seedAccessControl()`
  - `superAdmin()`
  - `userWith([...])`
  - workflow helpers such as `receivedOrder()`, `assignOrder()`, `orderInProduction()`, and `qcPendingOrder()`.
- Most feature tests seed permissions and roles with `beforeEach(fn () => seedAccessControl())`.
- Tests assert redirects, session errors, database state, view names, and policy/permission denials.
- A small hardening suite uses classic PHPUnit classes under `tests/Feature/Hardening`.

Factory conventions:

- Factories live in `database/factories`.
- Models use `HasFactory` and define `protected static function newFactory()`.
- Factories set `protected $model = Model::class`.
- Common states include `inactive()`, `cancelled()`, and `main()`.
- Transaction factories often create related models with nested factories.

Inventory test implications:

- Add tests under `tests/Feature/Inventory`.
- Seed access control in inventory feature tests.
- Use `superAdmin()` for bypass-heavy workflow tests and `userWith([...])` for permission-specific authorization tests.
- Add factories for inventory master records and transaction/movement records.
- Include branch relationship/scope tests if inventory tables carry `branch_id`.

## Recommended Inventory Module Structure

Recommended module root:

```text
app/Modules/Inventory/
```

Recommended files:

```text
app/Modules/Inventory/Controllers/InventoryItemController.php
app/Modules/Inventory/Controllers/InventoryStockController.php
app/Modules/Inventory/Controllers/InventoryMovementController.php
app/Modules/Inventory/Controllers/StockAdjustmentController.php

app/Modules/Inventory/Requests/StoreInventoryItemRequest.php
app/Modules/Inventory/Requests/UpdateInventoryItemRequest.php
app/Modules/Inventory/Requests/StoreStockAdjustmentRequest.php
app/Modules/Inventory/Requests/StoreStockReceiptRequest.php
app/Modules/Inventory/Requests/StoreStockIssueRequest.php

app/Modules/Inventory/Services/InventoryItemService.php
app/Modules/Inventory/Services/InventoryStockService.php
app/Modules/Inventory/Services/InventoryMovementService.php
app/Modules/Inventory/Services/StockAdjustmentService.php

app/Modules/Inventory/Repositories/InventoryItemRepository.php
app/Modules/Inventory/Repositories/InventoryStockRepository.php
app/Modules/Inventory/Repositories/InventoryMovementRepository.php

app/Modules/Inventory/Interfaces/InventoryItemRepositoryInterface.php
app/Modules/Inventory/Interfaces/InventoryStockRepositoryInterface.php
app/Modules/Inventory/Interfaces/InventoryMovementRepositoryInterface.php

app/Modules/Inventory/Models/InventoryItem.php
app/Modules/Inventory/Models/InventoryStock.php
app/Modules/Inventory/Models/InventoryMovement.php

app/Modules/Inventory/Policies/InventoryItemPolicy.php
app/Modules/Inventory/Policies/InventoryStockPolicy.php
app/Modules/Inventory/Policies/InventoryMovementPolicy.php
```

Suggested table naming, matching existing `mst_` and `trx_` conventions:

```text
mst_inventory_items
trx_inventory_stocks
trx_inventory_movements
```

Alternative if Sprint 12 treats materials as master data:

```text
mst_materials
inventory_material_lab
```

Note: `docs/database_schema.md` and `docs/erd.md` already mention `mst_materials` and `inventory_material_lab`, but no implementation exists in `app/Modules` or migrations inspected for this sprint.

Recommended views:

```text
resources/views/inventory/items/index.blade.php
resources/views/inventory/items/create.blade.php
resources/views/inventory/items/edit.blade.php
resources/views/inventory/items/_form.blade.php
resources/views/inventory/stocks/index.blade.php
resources/views/inventory/movements/index.blade.php
resources/views/inventory/adjustments/create.blade.php
```

Recommended tests:

```text
tests/Feature/Inventory/InventoryItemTest.php
tests/Feature/Inventory/InventoryStockTest.php
tests/Feature/Inventory/InventoryMovementTest.php
tests/Feature/Inventory/InventoryAuthorizationTest.php
tests/Feature/Inventory/InventoryBranchScopeTest.php
```

Recommended factories:

```text
database/factories/InventoryItemFactory.php
database/factories/InventoryStockFactory.php
database/factories/InventoryMovementFactory.php
```

Recommended permissions:

```text
manage_inventory
view_inventory
create_inventory_items
update_inventory_items
adjust_inventory_stock
receive_inventory_stock
issue_inventory_stock
view_inventory_movements
```

Recommended routing style:

```php
Route::middleware('auth')->prefix('inventory')->name('inventory.')->group(function () {
    Route::resource('items', InventoryItemController::class)->except(['show']);
    Route::get('stocks', [InventoryStockController::class, 'index'])->name('stocks.index');
    Route::get('movements', [InventoryMovementController::class, 'index'])->name('movements.index');
    Route::get('adjustments/create', [StockAdjustmentController::class, 'create'])->name('adjustments.create');
    Route::post('adjustments', [StockAdjustmentController::class, 'store'])->name('adjustments.store');
});
```

For implementation, mirror existing route middleware style with inventory permissions, then still authorize in controllers.

## Sprint 12 Risks

1. Branch enforcement ambiguity

The codebase has branch foundation, but runtime branch isolation is not active in inspected code. Inventory has a high chance of being branch-sensitive, so Sprint 12 must decide whether inventory is global, branch-owned, or branch-transfer capable.

2. Missing `CurrentBranch`

The prompt references Branch Context and Branch Enforcement, but no `CurrentBranch` implementation was found. Building Inventory around an assumed service would create hidden coupling and likely test failures.

3. Stock movement must not be audit-only

Inventory quantity changes need durable domain history. Audit logs are useful, but they should not replace inventory movement rows.

4. Concurrency

Stock adjustments, receipts, and issues need row locking or another concurrency strategy. Existing workflow services use `DB::transaction()` and sometimes `lockForUpdate()`. Inventory should do the same for stock rows.

5. Negative stock rules

The current code has no inventory precedent for allowing or blocking negative stock. Sprint 12 should define this explicitly before implementing issue/consume behavior.

6. Unit of measure and material identity

Lab order items currently store free-text `material_text`; no implemented material catalog exists. Sprint 12 must avoid assuming existing normalized material master data unless it creates it.

7. Permission naming drift

The app has both space-style and underscore-style permissions. Inventory should prefer the newer underscore-style permissions for workflows, but role mappings must be updated consistently.

8. Branch-specific uniqueness

Inventory item codes may be global or branch-specific. Existing branch data is nullable and not enforced, so uniqueness rules must be chosen carefully.

9. Reporting impact

Inventory movements may later need reporting/export. Sprint 12 should keep query boundaries clean so Reporting can read through repositories/services without duplicating business rules.

10. UI navigation

The sidebar is permission-aware. Missing sidebar updates will make Inventory routable but hard to discover.

## Files Likely To Be Created Or Modified

Likely created:

```text
app/Modules/Inventory/Controllers/*
app/Modules/Inventory/Requests/*
app/Modules/Inventory/Services/*
app/Modules/Inventory/Repositories/*
app/Modules/Inventory/Interfaces/*
app/Modules/Inventory/Models/*
app/Modules/Inventory/Policies/*
resources/views/inventory/**/*
tests/Feature/Inventory/*
database/factories/InventoryItemFactory.php
database/factories/InventoryStockFactory.php
database/factories/InventoryMovementFactory.php
database/seeders/InventorySeeder.php
```

Likely modified:

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

Likely migrations for implementation phase only:

```text
database/migrations/*_create_mst_inventory_items_table.php
database/migrations/*_create_trx_inventory_stocks_table.php
database/migrations/*_create_trx_inventory_movements_table.php
```

No migration was created during this audit.

## Sprint 12 Implementation Guardrails

- Keep controllers thin: authorize, validate via FormRequest, call service, return view/redirect.
- Put stock rules and transactions in services.
- Keep repositories focused on query composition and persistence.
- If branch-scoped, include `branch_id` relationships and tests from day one.
- Use `AuditLogService` for important stock/item mutations.
- Use dedicated movement rows for all quantity changes.
- Register repository bindings and policies in `RepositoryServiceProvider`.
- Add inventory permissions and assign them to relevant roles.
- Add Feature tests for authorization, CRUD, stock movement, branch scope, validation, and concurrency-relevant invariants.
