# ADLMS AI Development Guide

Version: 2.0
Last updated: June 2026

This document is the permanent operating manual for AI agents working on the Asia Dental Lab Management System. It is written for Claude Code, Trae Solo, Codex, Cursor, Copilot Agent, ChatGPT, and any future coding assistant.

Use it before proposing, generating, reviewing, or merging any change. Its purpose is to preserve the architecture completed through Sprint 12, prevent branch data leakage, protect the ledger-based inventory model, and keep future work consistent with the existing Laravel modular monolith.

## Project Overview

### Business Purpose

Asia Dental Lab Management System (ADLMS) is an operational ERP-style application for a dental laboratory group. It manages the lifecycle from lab order intake through production, quality control, delivery, proof of delivery, invoicing, payment tracking, reporting, branch operations, and inventory control.

The system is in pilot/live testing status. AI agents must treat it as a production-adjacent application: small changes, clear boundaries, tests, and no speculative features.

### Application Scope

Completed scope through Sprint 12 includes:

- User access management and role-based permissions.
- Master data for clinics, doctors, patients, lab services, and technicians.
- Lab order core workflow with status logs, attachments, audit logs, and medical record number support.
- Production assignment and workflow.
- Quality control, checklists, evidence, and remake requests.
- Delivery and proof of delivery.
- Invoice and payment workflows.
- Reporting dashboard and report exports.
- Multi-branch foundation, branch context, and branch enforcement foundation.
- Inventory Core with product categories, units, products/materials, suppliers, inventory locations, stock movements, stock cards, low stock detection, and inventory dashboard.

### Technology Stack

- Laravel modular monolith.
- Blade templates.
- Tailwind CSS.
- Alpine.js where interactivity is needed.
- PostgreSQL.
- Pest PHP for tests.
- Spatie Laravel Permission for roles and permissions.

Do not introduce React, Vue, a new CSS framework, a new permission system, or a new application architecture unless the user explicitly requests a separate architecture change.

### Module Structure

Application modules live under `app/Modules`. Current module families include:

- `AccessControl`
- `Branch`
- `Clinic`
- `Delivery`
- `Doctor`
- `Inventory`
- `Invoice`
- `LabOrder`
- `LabService`
- `Patient`
- `Production`
- `QualityControl`
- `Reporting`
- `Technician`
- `User`

Views live under `resources/views`, with module-specific folders such as `inventory`, `reports`, `lab-orders`, `production`, and reusable Blade components under `resources/views/components`.

Routes are centralized in `routes/web.php` and grouped by sprint/module using auth and permission middleware.

### Multi-Branch Architecture

ADLMS is multi-branch. Branch-owned data must be scoped to the active branch. The current active branch resolver is:

```text
app/Modules/Branch/Services/BranchContext.php
```

Branch ownership is expressed using `branch_id` on branch-owned master and transaction records. Inventory adds a second operational scope:

```text
Branch -> Inventory Location -> Product Stock Ledger
```

Every branch-owned feature must resolve the active branch centrally and must never trust a user-submitted `branch_id`.

## Core Architecture Rules

### Required Flow

All feature implementation must follow this flow:

```text
Controller -> Form Request -> Service -> Repository -> Model
```

The existing provider `app/Providers/RepositoryServiceProvider.php` binds repository interfaces to concrete repositories and registers policies/gates. New repositories and policies must be registered there unless the project introduces a documented alternative provider for the module.

### Controller Responsibilities

Controllers must remain thin.

Controllers may:

- Accept a typed Form Request or simple request object.
- Call authorization using policies or gates.
- Call services and repositories for prepared view data.
- Return Blade views, redirects, or responses.
- Flash success/error messages using existing conventions.

Controllers must not:

- Contain business rules.
- Build complex queries.
- Calculate inventory stock.
- Enforce branch rules manually when a service/repository is responsible.
- Write directly to models except for trivial non-domain cases already established by the module.

Valid pattern:

```php
public function store(StoreProductRequest $request): RedirectResponse
{
    $this->authorize('create', Product::class);

    $product = $this->products->create($request->validated());

    return redirect()
        ->route('inventory.products.show', $product)
        ->with('status', 'Product created.');
}
```

Invalid pattern:

```php
public function store(Request $request): RedirectResponse
{
    Product::create($request->all());
    return back();
}
```

### Form Request Responsibilities

Form Requests must handle input validation. They should define field types, required/nullable rules, enum-like allowed values, max lengths, dates, numeric constraints, and existence checks.

Form Requests usually return `true` from `authorize()` in this codebase, while authorization is performed through policies in controllers/services. If a module already uses request-level authorization, follow that module's local convention.

Do not place branch isolation rules only in Form Requests. Validation can check that an ID exists, but services/policies must verify that it belongs to the active branch.

### Service Responsibilities

Services contain business rules and workflow orchestration.

Services must:

- Resolve active branch using `BranchContext` for branch-owned operations.
- Use repository interfaces, not concrete repositories, unless an existing module has no interface.
- Use `DB::transaction()` for multi-write operations, status changes, ledger writes, invoice/payment workflows, stock adjustments, and any operation where partial writes would corrupt state.
- Validate domain invariants, such as status transitions, stock sufficiency, ownership, active/inactive constraints, and relationship compatibility.
- Throw clear validation exceptions for user-correctable failures.

Services must not:

- Render views.
- Read raw request data.
- Trust route model binding without branch verification.
- Duplicate repository query logic unless locking is required for a transaction.

### Repository Responsibilities

Repositories contain query and persistence logic.

Repositories may:

- Create, update, paginate, search, filter, and eager-load models.
- Apply branch filters when passed a branch ID.
- Provide aggregate query methods, such as reporting summaries or inventory stock calculations.
- Return Eloquent models, paginators, collections, or scalar aggregate values.

Repositories must not:

- Enforce business workflow decisions.
- Resolve active branch themselves unless their interface explicitly says it is a current-branch method and the module has established that pattern.
- Read HTTP request data.
- Authorize users.

Preferred branch-aware repository shape:

```php
public function findInBranch(int $branchId, int $id): ?Product
{
    return Product::query()
        ->where('branch_id', $branchId)
        ->whereKey($id)
        ->first();
}
```

Avoid:

```php
return Product::find($id);
```

for branch-owned records.

### Model Responsibilities

Models define:

- Table name.
- Fillable fields.
- Casts.
- Constants for status/type values where useful.
- Relationships.
- Factory method using `HasFactory`.

Models should not contain workflow orchestration. Keep model helpers small and deterministic.

### Policy and Permission Usage

Authorization is enforced through:

- Spatie permissions in route middleware and Blade navigation.
- Laravel policies for model-level access.
- Named gates for workflow actions where no direct model policy is appropriate.
- `Gate::before` Super Admin bypass in `RepositoryServiceProvider`.

Do not invent a parallel permission system.

## Module Structure Standards

Use this structure for new modules, adapting only when the existing target module has a more specific local convention:

```text
app/Modules/<Module>/
  Controllers/
    <Entity>Controller.php
  Interfaces/
    <Entity>RepositoryInterface.php
  Models/
    <Entity>.php
  Policies/
    <Entity>Policy.php
  Repositories/
    <Entity>Repository.php
  Requests/
    Store<Entity>Request.php
    Update<Entity>Request.php
    <Entity>FilterRequest.php
  Services/
    <Entity>Service.php
```

Optional folders are allowed when the module already uses them, such as:

- `Controllers/Concerns`
- `Policies/Concerns`
- `Exports`
- `ValueObjects`

Tests should live under:

```text
tests/Feature/<Module>/<Feature>Test.php
```

### Naming Conventions

Use singular entity names for models, policies, services, and repositories:

- `Product`
- `ProductPolicy`
- `ProductService`
- `ProductRepository`
- `ProductRepositoryInterface`

Use plural route resource names:

- `inventory.products.*`
- `inventory.locations.*`
- `settings.users.*`

Use action-specific request names:

- `StoreOpeningStockRequest`
- `StoreAdjustmentRequest`
- `StockCardFilterRequest`

Use explicit service names when one service spans several entities:

- `InventoryStockService`
- `ProductionWorkflowService`
- `QualityControlService`

### Service Provider Registration

When adding a repository interface, bind it in `RepositoryServiceProvider`:

```php
ProductRepositoryInterface::class => ProductRepository::class,
```

When adding a policy, register it in the same provider:

```php
Product::class => ProductPolicy::class,
```

When adding named gates, follow the existing `$gates` map convention:

```php
'production.start' => [ProductionPolicy::class, 'start'],
```

## Branch Context Rules

### BranchContext Location

The active branch resolver is:

```text
app/Modules/Branch/Services/BranchContext.php
```

It provides:

- `id(): ?int`
- `branch(): ?Branch`
- `requireId(): int`
- `forUser(User $user): ?int`

Current resolution strategy:

1. If authenticated user has a valid active `branch_id` column, use it.
2. If the user model has a `branches()` relation, use the first active assigned branch ordered by name.
3. Fall back to the seeded default `MAIN` branch through the branch repository.
4. `requireId()` throws a clear `RuntimeException` if no branch can be resolved.

### Required Branch Isolation Pattern

For every branch-owned operation:

1. Resolve branch ID using `BranchContext::requireId()` in the service.
2. Load branch-owned records with repository methods scoped by branch.
3. Validate related records belong to the same active branch.
4. Apply policy checks for route-bound models.
5. Do not expose records from other branches in views or selectors.

Valid service pattern:

```php
$branchId = $this->branchContext->requireId();
$product = $this->products->findInBranch($branchId, $productId);

if (! $product) {
    throw ValidationException::withMessages([
        'product_id' => 'Product tidak valid untuk branch aktif.',
    ]);
}
```

Valid repository pattern:

```php
return Product::query()
    ->where('branch_id', $branchId)
    ->where('is_active', true)
    ->orderBy('name')
    ->get();
```

Valid policy pattern:

```php
return $user->can('view_inventory')
    && $this->belongsToActiveBranch($product->branch_id);
```

### Forbidden Branch Patterns

Never do these in branch-owned features:

```php
$branchId = $request->input('branch_id');
```

```php
$product = Product::findOrFail($id);
```

```php
InventoryMovement::query()->where('product_id', $productId)->get();
```

```php
<option value="{{ $location->id }}">{{ $location->name }}</option>
```

when `$location` was not loaded from the active branch.

### Route Model Binding Warning

Laravel route model binding can load a model before branch authorization. Always follow it with a policy check or service-level branch validation:

```php
$this->authorize('view', $product);
```

Do not assume route-bound models are branch-safe.

## Inventory Rules

### Inventory Architecture

Sprint 12 Inventory Core uses this model:

```text
Branch
  -> Inventory Location
  -> Product
  -> Inventory Movement Ledger
```

Core tables:

- `inv_product_categories`
- `inv_product_units`
- `inv_products`
- `inv_suppliers`
- `inv_inventory_locations`
- `trx_inventory_movements`

Inventory locations represent storage or workflow places inside a branch, such as warehouse, production room, QC room, delivery area, clinic room, or other storage areas.

### Ledger-Only Stock Rule

Stock is derived only from inventory movements:

```text
current stock = SUM(quantity_in) - SUM(quantity_out)
```

This applies to:

- Product + location stock.
- Product stock across a branch.
- Location stock.
- Low stock detection.
- Inventory value.
- Stock card running balance.

Never create mutable stock source-of-truth columns.

Forbidden column names include:

- `stock`
- `current_stock`
- `qty_on_hand`
- `quantity_on_hand`
- `available_stock`
- `final_stock`

Temporary query aliases named `current_stock` are allowed only in aggregate queries and view models. They must not be stored as product or location columns.

### Inventory Movement Rules

Every movement must include:

- `branch_id`
- `inventory_location_id`
- `product_id`
- `movement_type`
- `movement_date`
- `quantity_in`
- `quantity_out`

Optional fields include:

- `supplier_id`
- `reference_type`
- `reference_id`
- `notes`
- `created_by`

Allowed Sprint 12 movement types:

- `OPENING`
- `PURCHASE`
- `ADJUSTMENT_IN`
- `ADJUSTMENT_OUT`

Future movement types must be introduced only by a sprint that explicitly owns them.

### Stock Operation Rules

Opening stock:

- Creates an inbound ledger entry.
- Requires active product and active inventory location in the active branch.
- May include unit cost.

Receive stock:

- Creates an inbound ledger entry.
- Requires active product and active inventory location in the active branch.
- Supplier is optional, but if provided must belong to the active branch and be active.
- May include unit cost.

Adjustment in:

- Creates an inbound correction ledger entry.
- Requires active product and active inventory location in the active branch.

Adjustment out:

- Creates an outbound correction ledger entry.
- Requires active product and active inventory location in the active branch.
- Must reject zero or negative quantity.
- Must reject when stock in that specific location is insufficient.
- Must not check only branch-level stock when location-level stock is required.

### Product, Supplier, and Location Rules

- Product branch must match active branch.
- Inventory location branch must match active branch.
- Supplier branch must match active branch when supplier is provided.
- Product and location branch must match.
- Selectors must show active records from the active branch only.
- Inactive products must not be selectable for stock operations.
- Inactive locations must not be selectable for stock operations.

### Low Stock Rules

Low stock uses derived stock and `minimum_stock`.

Branch low stock:

```text
SUM(all location stock for product in branch) <= product.minimum_stock
```

Location low stock:

```text
stock for product in selected location <= product.minimum_stock
```

Out of stock:

```text
derived stock <= 0
```

### Inventory Valuation Rules

Current Sprint 12 valuation uses:

```text
derived stock * product.average_cost
```

Do not introduce FIFO, LIFO, weighted average recalculation, purchase order valuation, supplier payment, or costing engines unless a future sprint explicitly asks for it.

### Stock Card Rules

Stock card rows must be ordered by:

```text
movement_date ASC, id ASC
```

Running balance is calculated from the ordered ledger:

```text
balance += quantity_in - quantity_out
```

Stock card must support optional inventory location filter and must never leak another branch's movements.

## Database Standards

### Table Naming

Existing table prefixes:

- `mst_` for master data, such as branches, clinics, doctors, technicians.
- `trx_` for transactional data, such as lab orders, deliveries, invoices, payments, inventory movements.
- `sys_` for system support tables, such as audit logs and attachments.
- `inv_` for inventory master data introduced in Sprint 12.

Use the module's existing prefix convention. Do not rename existing tables.

### Migration Naming

Use timestamped Laravel migration files with clear action names:

```text
YYYY_MM_DD_HHMMSS_create_<table>_table.php
YYYY_MM_DD_HHMMSS_add_<column>_to_<table>_table.php
YYYY_MM_DD_HHMMSS_backfill_<purpose>.php
```

Do not create schema changes unless the user explicitly asks for schema work.

### Columns and Keys

Use:

- `id` primary key.
- `branch_id` for branch-owned tables.
- `<entity>_id` for foreign keys.
- `created_by` for user actor references when relevant.
- `timestamps` unless the table is a pivot table with a documented exception.
- `is_active` for master records that are deactivated instead of deleted.

Use decimals for quantities and money-like values where fractional values matter.

### Foreign Keys and Indexes

Every foreign key should be indexed either by Laravel's `foreignId()` behavior or explicit indexes. Add composite indexes for common filters:

- `branch_id`
- `branch_id + status`
- `branch_id + inventory_location_id + product_id`
- `reference_type + reference_id`
- Date columns used in reports.

When a branch-owned table has a unique business code, prefer branch-scoped uniqueness:

```text
unique(branch_id, code)
```

For nullable codes, use the safest database-supported uniqueness strategy. PostgreSQL can support partial unique indexes; if Laravel schema builder cannot express the exact need cleanly, document and implement carefully.

### Soft Delete and Deactivation

The application commonly uses `is_active` for master data lifecycle. Follow the target module's existing convention:

- Use `is_active` for active/inactive master rows.
- Avoid hard deletes for records that are referenced by transactions.
- Do not add soft deletes unless the module already uses them or the sprint explicitly requires them.

### Audit and Status Logs

Lab order status history uses status logs. Important activities use audit logs and attachments through system tables and morph aliases configured in `RepositoryServiceProvider`.

When adding a workflow that changes business status, add a status/audit logging strategy consistent with LabOrder, Production, QC, Delivery, Invoice, and Payment patterns.

## Authorization Standards

### Roles

Existing roles include:

- `Super Admin`
- `Admin Lab`
- `Technician`
- `Quality Control`
- `Delivery Coordinator`
- `Courier`
- `Finance`
- `Doctor`

`Super Admin` receives a global Gate bypass in `RepositoryServiceProvider`.

### Permission Patterns

Permissions are seeded in `database/seeders/PermissionSeeder.php` and assigned in `database/seeders/RoleSeeder.php`.

Existing patterns include both older space-separated permissions and module-specific underscore permissions. Follow the target module convention:

- `manage users`
- `manage clinics`
- `manage_lab_orders`
- `view_lab_orders`
- `manage_inventory`
- `view_inventory`

Do not remove old permission names unless a migration plan explicitly requires it.

### Routes

Routes should use:

```php
Route::middleware('auth')->prefix('inventory')->name('inventory.')->group(function () {
    // ...
});
```

Use Spatie permission middleware where the existing route group requires it:

```php
->middleware('permission:view_inventory|manage_inventory');
```

Inventory routes currently rely heavily on controller policies as well. Preserve that pattern when changing inventory behavior.

### Policies

Policies must combine permission checks with branch ownership checks.

Example:

```php
public function view(User $user, Product $product): bool
{
    return $this->canViewInventory($user)
        && $this->belongsToActiveBranch($product->branch_id);
}
```

Policies should not run complex reporting queries. They should answer access questions.

### Blade Navigation

Navigation visibility is permission-aware through `@can`, `@canany`, and `@role` in `resources/views/layouts/sidebar.blade.php`.

When adding a navigation item:

- Gate it using the same permission used by the route.
- Mark active state with `request()->routeIs(...)`.
- Do not show links to users who cannot access the route.

## Testing Standards

### Test Framework

ADLMS uses Pest for feature tests. `tests/Pest.php` applies `RefreshDatabase` to Feature tests and defines helpers such as:

- `seedAccessControl()`
- `superAdmin()`
- `userWith(array $permissions)`
- `receivedOrder()`
- `assignOrder()`
- `orderInProduction()`
- `qcPendingOrder()`
- `startQcReview()`

Use these helpers instead of duplicating setup.

### Test Locations and Names

Feature tests should live under:

```text
tests/Feature/<Module>/<Behavior>Test.php
```

Examples:

- `tests/Feature/Inventory/InventoryStockServiceTest.php`
- `tests/Feature/Inventory/InventoryRouteAuthorizationTest.php`
- `tests/Feature/Inventory/InventoryUiTest.php`
- `tests/Feature/Hardening/AuthorizationCoverageTest.php`

Test names should describe business behavior:

```php
it('rejects adjustment out when location stock is insufficient', function () {
    // ...
});
```

### Required Test Categories

For new domain work, add focused tests for:

- Authentication requirement.
- Permission/authorization behavior.
- Branch isolation.
- Happy path.
- Validation failures.
- Service business rules.
- Repository branch filtering.
- UI visibility if Blade changes are made.

For inventory changes, always test:

- Stock is derived from ledger movements.
- Adjustment out cannot exceed location stock.
- Cross-branch product/location/supplier access is blocked.
- Location filter cannot leak other branch data.
- Inactive products/locations are not selectable for stock operations.

### Quality Gates

Use the gates requested by the user. Common gates are:

```text
php artisan route:list
php artisan migrate:fresh --seed
php artisan test
npm.cmd run build
.\vendor\bin\pint
git status
```

If a gate cannot be run, say why and report the residual risk. Do not pretend it passed.

## UI / UX Standards

### Existing UI Pattern

ADLMS uses Blade and Tailwind with a restrained operational dashboard style. The main shell is component-based, including:

- `resources/views/components/settings-shell.blade.php`
- `resources/views/layouts/sidebar.blade.php`
- Standard Breeze-style components such as buttons, inputs, labels, errors, dropdowns, and modals.

New UI should feel like a dense SaaS operations tool, not a marketing page.

### Page Layout

Use:

- A clear page header with title, short operational description, and primary actions.
- Full-width content sections.
- Responsive grids for dashboard cards.
- Tables inside `overflow-x-auto` wrappers.
- Compact, readable spacing.
- Safe empty states.

Avoid:

- Landing pages for internal tools.
- Decorative gradients, oversized hero blocks, or visual clutter.
- Nested cards inside cards unless it is a repeated item in a framed tool.
- Business logic in Blade.

### Dashboard Patterns

Dashboard sections should be decision-oriented:

- KPI cards answer the top operational questions.
- Alert panels show work needing attention.
- Pipeline cards show workflow status.
- Activity timelines show recent events.
- Branch/location cards clarify ownership and scope.

Owner dashboard components include:

- `owner-dashboard.owner-kpi-card`
- `owner-dashboard.dashboard-section`
- `owner-dashboard.alert-panel`
- `owner-dashboard.branch-performance-card`
- `owner-dashboard.pipeline-card`
- `owner-dashboard.activity-timeline`

Branch dashboard components include:

- `branch-dashboard.queue-card`
- `branch-dashboard.workload-widget`
- `branch-dashboard.quick-action-panel`
- `branch-dashboard.inventory-alert-widget`
- `branch-dashboard.finance-alert-widget`
- `branch-dashboard.daily-summary-card`

Inventory components include:

- `inventory.kpi-card`
- `inventory.dashboard-section`
- `inventory.location-card`
- `inventory.low-stock-widget`
- `inventory.movement-timeline`
- `inventory.stock-value-card`

Reuse these components before creating new ones.

### Tables

Tables should:

- Use compact headers.
- Show the most important identifiers first.
- Use `tabular-nums` for numeric columns.
- Provide active/inactive or status badges.
- Keep actions aligned and predictable.
- Include empty states when no rows exist.
- Preserve query strings on pagination where filters are used.

For mobile, either allow horizontal scrolling or switch to stacked cards if the current page already uses that pattern.

### Forms

Forms should:

- Use Form Requests for validation.
- Display field errors using existing input error components.
- Keep submit and cancel actions visible and predictable.
- Use strong selectors for branch/location-sensitive records.
- Disable or explain actions when no active selectable records exist.
- Keep warning banners close to destructive or irreversible operations.

Inventory stock operation forms must show:

- Product summary.
- Operation type.
- Required inventory location selector.
- Quantity guidance.
- Cost guidance for opening/receive stock.
- Warning banner for adjustment out.
- Ledger-derived stock explanation.

### Badges and Status

Use badges to make operational state scannable:

- Active/inactive.
- Low stock.
- Out of stock.
- QC failed.
- Overdue.
- Paid/unpaid.
- Delivered/completed.

Use semantic color, but keep palettes restrained:

- Green/teal for healthy or positive state.
- Amber/yellow for warning.
- Red for danger, overdue, failed, or irreversible operations.
- Gray/slate for neutral metadata.

### Empty States

Every list, dashboard widget, and timeline should have a safe empty state:

- Explain what is absent.
- Avoid implying fake data.
- Provide a relevant next action only if the user is authorized.

Example:

```text
No stock movements yet. Opening stock or receive stock movements will create location summaries.
```

### Accessibility and Responsiveness

Use:

- Clear headings.
- Descriptive link/button text.
- Focus rings on interactive elements.
- Labels for form controls.
- Sufficient color contrast.
- Responsive grids using Tailwind breakpoints.

Do not rely only on color to communicate risk.

## Code Quality Rules

### Always

- Read the existing module before editing.
- Follow Controller -> Request -> Service -> Repository -> Model.
- Use Form Requests for input validation.
- Use services for business rules.
- Use repositories for data access.
- Use `BranchContext::requireId()` for branch-owned operations.
- Use transactions for multi-write or workflow-changing operations.
- Register repository bindings and policies.
- Add focused tests for behavior and branch isolation.
- Run requested quality gates.
- Keep changes scoped to the user's request.

### Never

- Put business logic in controllers.
- Put business logic in Blade.
- Trust user-submitted `branch_id`.
- Query branch-owned records without branch filtering.
- Create mutable inventory stock columns.
- Update product stock directly.
- Leak cross-branch records in selectors, dashboards, reports, APIs, or route-bound pages.
- Add new roles or permissions without following seeders and route/sidebar patterns.
- Introduce new frameworks without explicit approval.
- Implement future-sprint features accidentally.
- Revert unrelated user changes.

## Sprint Reference Summary

### Sprint 0 - Foundation

Established Laravel foundation, authentication baseline, application shell, and early project structure.

### Sprint 1 - User Access Management

Introduced users, roles, permissions, access-control routes, role/permission seeders, and permission-aware navigation.

### Sprint 2 - Master Data

Added core master data modules for clinics, doctors, patients, lab services, and technicians using modular CRUD patterns.

### Sprint 3 - Lab Order Core

Added lab order workflow, items, attachments, audit logs, status logs, and order status lifecycle.

### Sprint 4 - Production Workflow

Added production assignment, technician work logs, production steps, and workflow gates for start/pause/resume/complete/send-to-QC.

### Sprint 5 - Quality Control

Added QC queue, QC records, checklist updates, evidence uploads, pass/reject decisions, and remake requests.

### Sprint 6 - Delivery & POD

Added delivery records, courier assignment, delivery status transitions, and proof-of-delivery requirements.

### Sprint 7 - Invoice & Payment

Added invoices, invoice items, payments, issue/void/payment flows, and finance permissions.

### Sprint 8 - Reporting

Added reporting dashboard and read-only reports for orders, production, QC, delivery, invoices, payments, outstanding invoices, and revenue.

### Sprint 8.1 - Reporting Enhancement

Improved report usability, exports, dashboards, and operational visibility.

### Sprint 9 - Release Hardening

Added production configuration checks, authorization coverage, hardening tests, and release safety work.

### Sprint 10 - Branch Context

Established branch context foundations and branch-aware design direction.

### Sprint 11 - Branch Enforcement

Added branch enforcement foundation, `branch_id` columns/backfill, branch policies, and branch-isolation tests.

### Sprint 12 - Inventory Core

Added inventory schema, models, factories, seeders, repositories, services, policies, requests, controllers, routes, Blade views, navigation, and UI tests. Inventory stock is ledger-derived and location-aware.

## AI Implementation Checklist

Before implementation:

- Confirm the exact user goal and scope.
- Check whether the task is documentation-only, UI-only, service-only, schema-only, or full implementation.
- Read the relevant design docs under `docs/`.
- Inspect the target module under `app/Modules`.
- Inspect relevant routes in `routes/web.php`.
- Inspect relevant views/components under `resources/views`.
- Inspect relevant tests under `tests/Feature`.
- Run `git status` when editing to understand existing changes.
- Identify branch-owned records and required branch isolation rules.
- Identify required permissions and policies.
- Identify whether the operation needs a transaction.
- Identify whether audit/status logs are required.
- Decide which tests are needed before writing code.

During implementation:

- Keep controllers thin.
- Put validation in Form Requests.
- Put business rules in Services.
- Put queries in Repositories.
- Use repository interfaces.
- Register bindings/policies/gates.
- Use `BranchContext::requireId()` for branch-owned operations.
- Do not expand scope into future features.
- Add focused tests close to the behavior being changed.

Before final response:

- Run requested quality gates.
- Report any gate that failed or was not run.
- Summarize files changed.
- Summarize behavior changed.
- Mention assumptions and remaining risks.

## AI Review Checklist

Use this checklist for code review, hardening, or pre-merge review.

Architecture:

- Does the change follow Controller -> Request -> Service -> Repository -> Model?
- Are controllers thin?
- Are business rules outside controllers and Blade?
- Are repositories free of workflow decisions?
- Are repository interfaces registered?
- Are policies registered?

Branch isolation:

- Does every branch-owned operation use `BranchContext`?
- Are all branch-owned queries scoped by `branch_id`?
- Are route-bound models authorized or branch-validated?
- Are selectors limited to active branch records?
- Can another branch's ID be submitted to mutate data?

Inventory:

- Is stock calculated only from ledger movements?
- Are product/location/supplier branches validated?
- Does adjustment out reject insufficient location stock?
- Are there any new mutable stock columns?
- Does stock card ordering preserve running balance?

Authorization:

- Are route permissions consistent with policies?
- Are sidebar links permission-aware?
- Does Super Admin still work through the existing Gate bypass?
- Were new permissions seeded and assigned only when required?

Database:

- Are migration names clear?
- Are foreign keys and indexes present?
- Are branch-owned tables using `branch_id`?
- Are unique constraints branch-scoped when needed?
- Are schema changes explicitly requested?

Tests:

- Is there a happy path test?
- Is there a validation failure test?
- Is there an authorization test?
- Is there a branch isolation test?
- Are inventory ledger and stock edge cases tested when relevant?
- Do tests use existing Pest helpers?

UI:

- Does the UI use existing Blade/Tailwind conventions?
- Is it responsive?
- Are tables readable and horizontally safe on mobile?
- Are empty states present?
- Are destructive or irreversible actions clearly warned?
- Does the UI avoid fake data and backend invention?

Quality gates:

- Did requested gates run?
- Were failures investigated?
- Is `git status` understood?

## Prompt Templates

### New Module Creation

```text
You are working on ADLMS, a Laravel modular monolith.

Read docs/ai_development_guide.md and inspect existing modules under app/Modules before coding.

Goal:
Create <Module Name> module.

Scope:
- <entities>
- <workflows>

Required architecture:
Controller -> Request -> Service -> Repository -> Model.

Branch rules:
- Use BranchContext for branch-owned records.
- Never trust user-submitted branch_id.

Create only:
- Models
- Migrations
- Factories
- Repositories/interfaces
- Services
- Requests
- Policies
- Controllers/routes/views/tests as explicitly requested

Do not implement:
- <out-of-scope features>

Quality gates:
- php artisan migrate:fresh --seed
- php artisan test
- npm.cmd run build
- .\vendor\bin\pint
- git status
```

### CRUD Generation

```text
Implement CRUD for <Entity> in ADLMS.

Read the target module and docs/ai_development_guide.md first.

Preserve:
- Existing database schema unless schema changes are explicitly requested.
- Existing routes, services, repositories, permissions, and policies outside the target area.

Requirements:
- Controller must be thin.
- Validation must be in Form Requests.
- Business logic must be in Service.
- Data access must be in Repository.
- Policies must enforce permissions and branch isolation.
- Views must follow existing Blade/Tailwind patterns.
- Tests must cover auth, authorization, validation, happy path, and branch isolation.
```

### Sprint Implementation

```text
Implement Sprint <number> for ADLMS.

Before coding:
- Read docs/ai_development_guide.md.
- Read the sprint technical design.
- Inspect existing related modules.
- Identify branch-owned records.
- Identify required permissions.
- Identify tests and quality gates.

Rules:
- Do not implement out-of-scope future sprint features.
- Follow Controller -> Request -> Service -> Repository -> Model.
- Use BranchContext for branch-owned operations.
- Use transactions for multi-write workflows.
- Add focused tests.

Output:
- Files created/modified.
- Behavior implemented.
- Test and quality gate results.
- Remaining work.
```

### Refactoring

```text
Refactor <target> in ADLMS.

Read docs/ai_development_guide.md and the current implementation first.

Constraints:
- Preserve behavior.
- Preserve schema.
- Preserve routes and permissions.
- Do not widen scope.
- Do not rewrite architecture.

Focus:
- Reduce duplication.
- Improve readability.
- Preserve branch isolation.
- Preserve tests or add characterization tests first if behavior is risky.

Output:
- What changed.
- What behavior was preserved.
- Test results.
- Remaining risks.
```

### UI Modernization

```text
Improve UI for <page/module> in ADLMS.

Read:
- docs/ai_development_guide.md
- relevant UI design docs
- current Blade views/components

Allowed:
- Blade improvements
- Tailwind improvements
- Reusable Blade components
- Accessibility and responsive improvements
- UI feature tests if needed

Not allowed:
- Database changes
- Route changes
- Service/repository rewrites
- New UI framework
- Fake backend data

Implement:
- Clear page header
- KPI/cards/tables/forms as appropriate
- Empty states
- Mobile behavior
- Permission-aware actions
```

### Test Generation

```text
Add tests for <feature> in ADLMS.

Read docs/ai_development_guide.md and existing tests in tests/Feature/<Module>.

Cover:
- Authentication
- Permission/authorization
- Branch isolation
- Validation failures
- Happy path
- Business rule edge cases

Use:
- Pest
- seedAccessControl()
- superAdmin()
- userWith([...])
- Existing factories

Do not modify production code unless tests reveal a real bug and the user asked for fixes.
```

### Code Review

```text
Review the current ADLMS changes.

Use docs/ai_development_guide.md as the review standard.

Prioritize findings:
- Bugs
- Branch data leakage
- Authorization gaps
- Inventory stock-source violations
- Missing transactions
- Missing tests
- UI regressions
- Scope creep

Output:
- Findings first, ordered by severity.
- File and line references.
- Open questions.
- Short summary only after findings.
```

### Inventory Feature Prompt

```text
Implement <inventory feature> in ADLMS.

Read docs/ai_development_guide.md and Sprint 12 inventory docs first.

Inventory invariants:
- Stock is ledger-derived.
- Never create mutable stock columns.
- Every movement belongs to branch, inventory location, and product.
- Product, supplier, and location must match active branch.
- Adjustment out must reject insufficient stock in the selected location.

Follow:
Controller -> Request -> Service -> Repository -> Model.

Tests must cover:
- Ledger-derived stock.
- Branch isolation.
- Location isolation.
- Invalid product/location/supplier access.
- Low stock or stock card behavior if relevant.
```

## Final Principle

AI agents are implementation partners, not product owners. The sprint request defines scope. The database schema is a contract. Branch isolation is mandatory. Inventory stock is ledger-derived. Consistency with Sprint 0-12 is more important than inventing a clever new pattern.
