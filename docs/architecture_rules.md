# ADLMS Architecture Rules

Version: 1.0
Last updated: June 2026

## Architecture Constitution

This document is the highest-priority architectural authority for the Asia Dental Lab Management System (ADLMS). It overrides individual implementation preferences, AI agent habits, and convenience shortcuts.

All future developers and AI agents must follow this document before creating, modifying, reviewing, or merging code. When this document conflicts with a suggested implementation, the implementation must change unless the user explicitly approves an architecture change and documents it.

This constitution exists to prevent architectural drift after Sprint 0 through Sprint 12. ADLMS is already a live-testing modular monolith with multi-branch rules, permissions, reporting, and ledger-based inventory. The system must evolve by extending established patterns, not by replacing them.

Mandatory rule:

```text
No future sprint may bypass Controller -> Request -> Service -> Repository -> Model.
No future sprint may bypass BranchContext for branch-owned data.
No future sprint may create mutable inventory stock columns.
```

## Architectural Principles

### Modular Monolith

ADLMS is a Laravel modular monolith. Modules live under `app/Modules`, and each domain owns its controllers, requests, services, repositories, interfaces, models, and policies.

Allowed:

- Add code inside the owning module.
- Register repository bindings and policies in `RepositoryServiceProvider`.
- Reuse shared Laravel infrastructure.
- Reuse cross-module services only through public service/repository interfaces.

Forbidden:

- Create unrelated global service classes for module-specific behavior.
- Put domain queries in controllers or views.
- Split into microservices without an approved architecture decision.
- Introduce a new module pattern that conflicts with existing modules.

### Single Database

ADLMS uses one PostgreSQL database. Domain separation is enforced by module boundaries, table naming, foreign keys, policies, services, and branch filters, not by multiple databases.

### Domain Separation

Each module must own its business vocabulary:

- LabOrder owns lab orders, items, status logs, attachments, and audit logs.
- Production owns assignments, steps, work logs, and workflow gates.
- QualityControl owns QC records, checklists, evidence, and remakes.
- Delivery owns delivery lifecycle and POD.
- Invoice owns invoices and payments.
- Reporting owns read-only report aggregation.
- Branch owns branch models and active branch resolution.
- Inventory owns product categories, units, products, suppliers, inventory locations, movements, stock cards, low stock, and valuation.

Cross-module coordination must happen through services or repository interfaces, not ad hoc database access.

### Branch Isolation

Branch isolation is mandatory for all branch-owned data. A user must not see, mutate, select, or infer records from another branch.

Branch-owned code must use:

```text
app/Modules/Branch/Services/BranchContext.php
```

The active branch must be resolved centrally. User input must never decide `branch_id`.

### Testability

Every business rule must be testable through service, feature, authorization, and branch isolation tests. Tests must prove that the system blocks invalid state and cross-branch leakage.

### Security First

Authentication, authorization, validation, branch isolation, and safe file handling are architecture concerns, not optional hardening tasks.

Every protected action must be authorized. Every input must be validated. Every branch-owned query must be scoped.

### Service-Oriented Business Logic

Services are the only proper home for business rules. Controllers coordinate HTTP flow. Repositories coordinate persistence. Views render prepared data.

If a rule affects domain state, workflow status, stock, invoices, deliveries, QC, branch ownership, or permissions, it belongs in a service.

## Module Boundaries

### Required Flow

```text
Controller -> Request -> Service -> Repository -> Model
```

Allowed:

```text
Controller -> Service
Controller -> Policy/Gate
Controller -> Form Request
Service -> Repository Interface
Service -> BranchContext
Service -> DB transaction
Repository -> Model
Policy -> User/Model/BranchContext helper
View -> Render data passed by controller
Test -> Controller/Service/Repository/Model
```

Forbidden:

```text
Controller -> Model
Controller -> Query Builder for domain data
View -> Repository
View -> Service
View -> Model query
Repository -> Request
Repository -> Auth authorization decision
Model -> Controller
Model -> View
Cross-module direct database access
Business logic in controllers
Business logic in Blade
```

### Valid Example

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

### Invalid Example

```php
public function store(Request $request): RedirectResponse
{
    Product::create($request->all());

    return redirect()->back();
}
```

Why prohibited:

- No Form Request validation.
- No policy check.
- No service.
- No branch resolution.
- Direct model persistence in controller.

## Layer Responsibilities

| Layer | Responsibilities | Allowed Actions | Forbidden Actions |
| --- | --- | --- | --- |
| Controllers | HTTP orchestration, authorization calls, service calls, redirects, view responses | Accept Form Requests, call `$this->authorize()`, call services, pass prepared data to views | Business logic, raw model queries, stock calculations, branch rules, direct persistence |
| Requests | Input validation | Define rules, normalize filters, validate required fields, validate numeric/date/string constraints | Domain ownership checks as the only protection, database mutation, business workflows |
| Services | Business rules and workflow orchestration | Resolve BranchContext, validate domain state, coordinate repositories, run transactions, throw validation exceptions | Render views, read raw request objects, handle UI concerns, bypass repositories |
| Repositories | Database access and persistence | Query, filter, paginate, aggregate, eager-load, create/update records | Authorization, workflow rules, reading request data, deciding branch from user input |
| Models | Eloquent mapping | Table names, fillable, casts, constants, relationships, factories | Workflow orchestration, UI decisions, authorization decisions |
| Policies | Authorization | Check permissions, roles, ownership, branch match | Heavy queries, persistence, UI rendering, business workflow state changes |
| Views | Presentation | Render data, show forms/tables/cards, use Blade components, show empty states | Query repositories, call services, mutate data, enforce business rules |
| Tests | Verification | Prove behavior, auth, branch isolation, validation, UI visibility, ledger rules | Depend on test order, skip branch cases, assert implementation details only |

## Dependency Rules

### Dependency Graph

```text
Routes
  -> Controllers
      -> Form Requests
      -> Policies/Gates
      -> Services
          -> Repository Interfaces
          -> BranchContext
          -> DB transactions
              -> Repositories
                  -> Models
                      -> Database

Views
  <- Controllers pass prepared data

Tests
  -> All public behavior layers as needed
```

### Allowed Dependencies

- Controllers may depend on services and Form Requests.
- Services may depend on repository interfaces, BranchContext, and other services when coordination is necessary.
- Repositories may depend on Eloquent models and query builders.
- Policies may depend on User, target model, permissions, and lightweight branch checks.
- Views may depend on variables, components, routes, and Blade authorization directives.

### Forbidden Dependency Examples

Controller querying a model:

```php
$orders = LabOrder::where('status', 'RECEIVED')->get();
```

View calling a service:

```blade
@php($stock = app(InventoryStockService::class)->getCurrentStock($product->id))
```

Repository authorizing a user:

```php
abort_unless(auth()->user()->can('manage_inventory'), 403);
```

Service using concrete repository when an interface exists:

```php
public function __construct(ProductRepository $products) {}
```

Use:

```php
public function __construct(ProductRepositoryInterface $products) {}
```

## Branch Architecture Rules

### BranchContext Rules

The active branch resolver is:

```text
app/Modules/Branch/Services/BranchContext.php
```

It provides:

- `id(): ?int`
- `branch(): ?Branch`
- `requireId(): int`
- `forUser(User $user): ?int`

BranchContext resolution order:

1. Authenticated user's active `branch_id` column if present.
2. First active branch from user's `branches()` relation if present.
3. Default seeded `MAIN` branch fallback.
4. `requireId()` throws a clear runtime exception if no branch can be resolved.

### Branch Isolation Rules

Every branch-owned service method must:

1. Call `BranchContext::requireId()`.
2. Load records through branch-scoped repository methods.
3. Validate related records belong to the same branch.
4. Reject inactive branch-owned records when the operation requires active data.
5. Never accept `branch_id` from request input as the source of truth.

Every branch-owned repository method must:

- Accept `int $branchId` when querying branch-owned records.
- Apply `where('branch_id', $branchId)`.
- Provide `findInBranch()` or equivalent for route-bound or submitted IDs.
- Avoid unbounded `all()` calls.

Every branch-owned policy must:

- Check permission/role.
- Check the model belongs to the active branch.
- Fail closed when no active branch exists.

### Allowed Branch Patterns

Service:

```php
$branchId = $this->branchContext->requireId();
$product = $this->products->findInBranch($branchId, $productId);
```

Repository:

```php
return Product::query()
    ->where('branch_id', $branchId)
    ->where('is_active', true)
    ->orderBy('name')
    ->get();
```

Policy:

```php
return $this->canViewInventory($user)
    && $this->belongsToActiveBranch($product->branch_id);
```

### Forbidden Branch Patterns

Trusting request branch:

```php
$branchId = $request->input('branch_id');
```

Unscoped route-bound lookup:

```php
$product = Product::findOrFail($id);
```

Unscoped aggregate:

```php
InventoryMovement::query()
    ->where('product_id', $productId)
    ->sum('quantity_in');
```

Cross-branch selector:

```blade
@foreach (InventoryLocation::all() as $location)
    <option value="{{ $location->id }}">{{ $location->name }}</option>
@endforeach
```

## Inventory Architecture Rules

### Ledger-Only Inventory Architecture

Inventory stock is ledger-derived. The inventory movement ledger is the source of truth.

Mandatory formula:

```text
current stock = SUM(quantity_in) - SUM(quantity_out)
```

This applies to:

- Stock by product and location.
- Stock by product across branch.
- Stock by location.
- Stock card running balance.
- Low stock detection.
- Inventory valuation.
- Dashboard summaries.

### Mandatory Inventory Tables

Sprint 12 Inventory Core established:

- `inv_product_categories`
- `inv_product_units`
- `inv_products`
- `inv_suppliers`
- `inv_inventory_locations`
- `trx_inventory_movements`

Every movement must include:

- `branch_id`
- `inventory_location_id`
- `product_id`
- `movement_type`
- `movement_date`
- `quantity_in`
- `quantity_out`

### Inventory Location Rules

Inventory is location-aware inside a branch:

```text
Branch -> Inventory Location -> Product Movement Ledger
```

Inventory locations may represent warehouse, production room, QC room, delivery area, clinic room, or other storage areas.

Rules:

- Every location belongs to exactly one branch.
- Every stock operation requires `inventory_location_id`.
- Users may only access locations in the active branch.
- Product branch and location branch must match.
- Location filter must never leak another branch's stock card or stock rows.

### No Mutable Stock Columns

Never add or use product/location columns as stock source of truth.

Forbidden persisted columns:

- `stock`
- `current_stock`
- `qty_on_hand`
- `quantity_on_hand`
- `available_stock`
- `final_stock`
- `stock_balance`

Allowed only as query alias:

```php
->selectRaw('SUM(quantity_in) - SUM(quantity_out) as current_stock')
```

### Valid Inventory Implementation

```php
public function currentStock(int $branchId, int $productId, ?int $locationId = null): float
{
    return (float) InventoryMovement::query()
        ->where('branch_id', $branchId)
        ->where('product_id', $productId)
        ->when($locationId, fn ($query, $value) => $query->where('inventory_location_id', $value))
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');
}
```

### Invalid Inventory Implementation

```php
$product->update([
    'current_stock' => $product->current_stock - $qty,
]);
```

Why prohibited:

- It creates mutable stock state.
- It can diverge from the ledger.
- It breaks stock cards.
- It is unsafe across branches and locations.

### Stock Adjustment Rules

Adjustment out must:

- Use active branch.
- Validate active product in branch.
- Validate active location in branch.
- Check stock in that specific location.
- Reject zero or negative quantity.
- Use a transaction.
- Create an outbound ledger row only if sufficient stock exists.

Adjustment out must not:

- Use branch-level stock to validate location-level stock.
- Write directly to product stock.
- Accept another branch's location.
- Create negative stock unless a future sprint explicitly approves negative stock behavior.

### Inventory Valuation Rules

Current valuation is:

```text
derived stock * product.average_cost
```

Do not introduce FIFO, LIFO, weighted average recalculation, purchase order costing, or supplier payment behavior without an explicit future sprint design.

## Authorization Architecture

### Policy-First Authorization

Every protected model action must be authorized by policy or named gate. Controllers must call `$this->authorize()` for model-level actions that are not fully protected by route middleware.

Register policies in:

```text
app/Providers/RepositoryServiceProvider.php
```

Super Admin bypass is already centralized through `Gate::before`. Do not duplicate it.

### Permission-First UI Visibility

Sidebar and action visibility must use Spatie permission Blade directives:

- `@can`
- `@canany`
- `@role`

UI visibility is not enough. Routes and controllers must still authorize protected actions.

### Role Assignment Conventions

Roles and permissions are seeded in:

- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`

Existing roles:

- `Super Admin`
- `Admin Lab`
- `Technician`
- `Quality Control`
- `Delivery Coordinator`
- `Courier`
- `Finance`
- `Doctor`

Do not create new roles or permissions casually. If a new permission is required, update seeders and tests using existing naming conventions.

### Authorization Example

Route:

```php
Route::get('reports/orders', [ReportController::class, 'orders'])
    ->name('reports.orders')
    ->middleware('permission:view_order_report|manage_report');
```

Controller:

```php
$this->authorize('view', $product);
```

Policy:

```php
return $this->canManageInventory($user)
    && $this->belongsToActiveBranch($location->branch_id);
```

## Database Architecture Rules

### Migration Standards

Migration filenames must be timestamped and explicit:

```text
YYYY_MM_DD_HHMMSS_create_<table>_table.php
YYYY_MM_DD_HHMMSS_add_<column>_to_<table>_table.php
YYYY_MM_DD_HHMMSS_backfill_<purpose>.php
```

Do not create migrations unless schema change is explicitly in scope.

### Table Naming Standards

Use established prefixes:

- `mst_` for master data.
- `trx_` for transaction data.
- `sys_` for system support tables.
- `inv_` for inventory master tables.

Examples:

- `mst_branches`
- `trx_lab_orders`
- `sys_audit_logs`
- `inv_products`
- `trx_inventory_movements`

### Foreign Key Standards

Use `<entity>_id` names:

- `branch_id`
- `product_id`
- `inventory_location_id`
- `created_by`

Foreign keys should be constrained when safe. Use nullable foreign keys only when the relationship is genuinely optional.

### Index Standards

Add indexes for:

- Foreign keys.
- `branch_id`.
- Status fields used in queues.
- Date fields used in reports.
- Composite branch filters.
- Reference polymorphic columns.

Common indexes:

```text
branch_id
branch_id + status
branch_id + inventory_location_id + product_id
reference_type + reference_id
movement_date
```

### Audit Fields

Use `created_by` when actor tracking matters. Use status logs and audit logs for workflow-critical transitions.

Status-changing workflows should record status history when the module has a status log pattern.

### Timestamp Rules

Use Laravel timestamps for normal models. Avoid disabling timestamps unless the table is a documented pivot or system exception.

### Activation Instead of Deletion

Master data commonly uses `is_active`. Prefer deactivation for records referenced by transactions.

Do not hard delete transaction-owned records unless the workflow explicitly supports deletion and tests prove downstream safety.

## Service Layer Rules

Services are the only location for business rules.

Services must:

- Validate workflow state.
- Enforce branch and location ownership.
- Coordinate repositories.
- Manage transactions.
- Enforce domain rules.
- Throw clear validation exceptions for user-correctable failures.
- Use repository interfaces.

Services must never:

- Render views.
- Perform UI logic.
- Read Blade state.
- Trust request `branch_id`.
- Query large datasets without repository support.

Transaction-required examples:

- Lab order create/update with items.
- Production assignment and workflow transitions.
- QC pass/reject/remake.
- Delivery lifecycle/POD completion.
- Invoice issue/void/payment.
- Inventory stock movement creation.
- Stock opname finalization.
- Transfer posting.

## Repository Layer Rules

Repositories own database access.

Repositories must:

- Query data.
- Persist data.
- Filter, search, sort, paginate, and eager-load.
- Provide branch-scoped lookup methods.
- Provide aggregate queries when they are data access, not workflow decisions.

Repositories must never:

- Contain business workflow rules.
- Handle authorization.
- Read HTTP request objects.
- Resolve branch from request input.
- Render views.

Valid repository:

```php
public function paginateForBranch(int $branchId, array $filters = []): LengthAwarePaginator
{
    return Product::query()
        ->where('branch_id', $branchId)
        ->when($filters['search'] ?? null, fn ($query, $value) => $query->where('name', 'like', "%{$value}%"))
        ->orderBy('name')
        ->paginate(15)
        ->withQueryString();
}
```

Invalid repository:

```php
public function createAdjustment(array $data): InventoryMovement
{
    if (! auth()->user()->can('manage_inventory')) {
        abort(403);
    }

    if ($data['quantity'] > $this->currentStock(...)) {
        throw new Exception('Insufficient stock');
    }

    return InventoryMovement::create($data);
}
```

Why prohibited:

- Authorization belongs in policy/controller.
- Stock sufficiency belongs in service.
- Repository should persist/query only.

## UI Architecture Rules

### Layout Hierarchy

The application uses Blade, Tailwind CSS, and reusable components. Layout hierarchy:

```text
layouts/app.blade.php
  -> layouts/sidebar.blade.php
  -> components/settings-shell.blade.php
      -> module views
          -> module partials/components
```

Follow existing internal SaaS dashboard style. Build the actual operational page, not a landing page.

### Navigation Structure

Sidebar visibility must be permission-aware. Use:

- `@can`
- `@canany`
- `@role`
- `request()->routeIs(...)` for active states.

Do not display navigation links that route to unauthorized features.

### Card Usage

Use cards for:

- KPI summaries.
- Repeated dashboard widgets.
- Item summaries.
- Alerts.
- Empty states.

Avoid nested cards inside decorative cards. Keep operational pages dense and scannable.

### Table Usage

Tables must:

- Use clear headers.
- Show primary identifiers first.
- Use `tabular-nums` for numeric data.
- Use status badges for state.
- Keep actions consistent.
- Use horizontal overflow wrappers on smaller screens.
- Provide empty states.
- Preserve filter query strings in pagination.

### Form Patterns

Forms must:

- Use Form Requests.
- Use existing input, label, error, button conventions.
- Keep submit/cancel actions predictable.
- Show warnings for irreversible or risky actions.
- Never expose cross-branch records in selects.
- Disable or explain the form when required active records are absent.

### Status Badges

Use badges for:

- Active/inactive.
- Order status.
- Production status.
- QC status.
- Delivery status.
- Invoice/payment status.
- Low stock/out of stock.

Color rules:

- Green/teal for healthy/completed/positive.
- Amber/yellow for warning/pending/low stock.
- Red for failed/overdue/danger/irreversible.
- Gray/slate for neutral metadata.

### Empty States

Every dashboard widget, table, and timeline must have an empty state.

Empty states must:

- State what is missing.
- Avoid fake data.
- Offer an authorized next action only when appropriate.
- Avoid blaming the user.

### Mobile Responsiveness

All UI must work on mobile:

- Use responsive grid breakpoints.
- Prevent table text overlap.
- Provide horizontal scrolling for dense tables.
- Keep action buttons reachable.
- Avoid viewport-based font scaling.
- Keep form controls full-width where appropriate.

## Testing Architecture Rules

### Required Tests for Every New Feature

Minimum test categories:

- Feature tests for routes and flows.
- Service tests for business rules.
- Authorization tests for permissions and policies.
- Branch isolation tests for branch-owned data.
- Validation tests for Form Requests.
- UI tests when Blade output changes.

### Feature Tests

Feature tests must prove user-visible workflows:

- Authenticated user can access allowed route.
- Unauthorized user is denied.
- Valid payload succeeds.
- Invalid payload fails.
- Redirects and flash messages are correct when important.

### Service Tests

Service tests must prove domain rules:

- Valid workflow transition succeeds.
- Invalid transition fails.
- Transactional side effects are complete.
- Branch and ownership checks are enforced.

### Authorization Tests

Authorization tests must prove:

- Required permissions are enforced.
- Super Admin bypass works through existing Gate behavior.
- Users without permission are denied.
- Policies check branch ownership where required.

### Branch Isolation Tests

Branch tests must prove:

- Active branch data is visible.
- Other branch data is hidden.
- Other branch IDs cannot be submitted to mutate data.
- Route-bound models from other branches are blocked.
- Dashboard/report aggregates do not include other branch data.

### Inventory Tests

Inventory tests must prove:

- Stock is derived from `trx_inventory_movements`.
- No operation depends on mutable stock columns.
- Adjustment out fails when location stock is insufficient.
- Stock card ordering produces correct running balance.
- Low stock respects branch and optional location filters.
- Another branch's product, supplier, location, or movement cannot leak.

## Performance Rules

### Query Optimization

Use eager loading for relationships rendered in loops:

```php
->with(['product.unit', 'inventoryLocation', 'supplier'])
```

Do not trigger N+1 queries from Blade.

### Pagination

Use pagination for list pages that may grow:

```php
->paginate(15)->withQueryString()
```

Do not use unbounded `all()` for operational tables, transaction tables, reports, or large master data.

Allowed exceptions:

- Small active select lists loaded through branch-scoped repository methods.
- Static enumerations.

### Index Usage

When adding filters or report queries, verify supporting indexes exist. Common filter fields include:

- `branch_id`
- `status`
- `movement_type`
- `movement_date`
- `created_at`
- `inventory_location_id`
- `product_id`
- `supplier_id`

### Forbidden Performance Patterns

Forbidden:

```php
Product::all();
```

for branch-owned operational views.

Forbidden:

```blade
{{ $product->movements()->sum('quantity_in') }}
```

inside table rows.

Forbidden:

```php
InventoryMovement::query()->get();
```

for stock/report pages without branch filters, pagination, or aggregation scope.

## Security Rules

### Mandatory Security Checklist

Before implementation:

- Identify authentication requirement.
- Identify permissions and policies.
- Identify branch-owned data.
- Identify validation rules.
- Identify file upload restrictions if files are involved.
- Identify transaction boundaries.

Before merge:

- Protected routes require auth.
- Protected actions require permission or policy.
- Branch-owned queries are scoped.
- Form Requests validate all user input.
- Views do not expose unauthorized actions.
- Tests prove denial paths.

### Input Validation

Every write request must use a Form Request. Every filter request should use a filter Form Request when the feature has several filters.

Validate:

- Required fields.
- Numeric limits.
- Date ranges.
- Exists rules.
- String lengths.
- Enum values.
- File types and sizes.

### Branch Isolation Security

Branch isolation is security-critical. Cross-branch leakage is treated as a security bug.

Do not rely only on UI hiding. Services and policies must reject invalid branch access.

### File Upload Security

Do not store base64 files in the database. Store files in configured storage and store paths/metadata in the database.

POD and evidence uploads must validate required fields, file types, and file presence according to the module workflow.

## Anti-Patterns

### Business Logic in Controller

Violation:

```php
if ($order->status !== 'RECEIVED') {
    abort(422);
}
```

Why prohibited:

- Workflow rules belong in services.
- Hard to test consistently.
- Encourages duplicate rules.

### Direct Model Query in Controller

Violation:

```php
$products = Product::where('branch_id', $branchId)->get();
```

Why prohibited:

- Data access belongs in repositories.
- Scoping becomes inconsistent.

### View Calling Service or Repository

Violation:

```blade
@php($value = app(InventoryStockService::class)->getInventoryValue())
```

Why prohibited:

- Views become business logic containers.
- Causes hidden queries and N+1 risk.

### Product::all() Without Branch Scope

Violation:

```php
Product::all();
```

Why prohibited:

- Leaks other branches.
- Unbounded query.
- Ignores active filters.

### Direct Stock Updates

Violation:

```php
$product->increment('stock', $qty);
```

Why prohibited:

- Breaks ledger source of truth.
- Breaks stock card.
- Ignores locations.

### Missing Policies

Violation:

```php
Route::resource('products', ProductController::class);
```

without policy/controller authorization for protected actions.

Why prohibited:

- Route auth alone may not protect branch ownership.
- Route model binding can load cross-branch models.

### Missing Tests

Violation:

```text
Feature added with no authorization or branch isolation tests.
```

Why prohibited:

- Branch and permission bugs are high-risk in live testing.
- Future agents cannot safely refactor without coverage.

### Cross-Module Leakage

Violation:

```php
DB::table('trx_lab_orders')->where(...)->update(...);
```

inside an unrelated module service.

Why prohibited:

- Bypasses owning module rules.
- Bypasses status/audit logging.
- Creates hidden coupling.

## AI Agent Rules

These instructions apply to Claude Code, Trae Solo, Codex, Cursor, Copilot Agent, ChatGPT, and any similar AI assistant.

### Before Implementation

Every AI agent must read or inspect:

- `docs/architecture_rules.md`
- `docs/ai_development_guide.md`
- Relevant sprint design docs under `docs/`
- Target module under `app/Modules/<Module>`
- Relevant routes in `routes/web.php`
- Relevant policies and permissions
- Relevant views/components if UI is in scope
- Relevant tests under `tests/Feature`
- `app/Modules/Branch/Services/BranchContext.php` for branch-owned work
- Inventory service/repository patterns for inventory work

### During Implementation

Every AI agent must:

- Keep scope limited to the user request.
- Preserve architecture flow.
- Use Form Requests for validation.
- Use services for business logic.
- Use repositories for data access.
- Use BranchContext for branch-owned records.
- Use policies and permissions.
- Add focused tests.
- Avoid future-sprint features unless explicitly requested.

### Before Commit

Every AI agent must:

- Review changed files.
- Confirm no unrelated files were modified.
- Confirm no forbidden architecture dependency was introduced.
- Confirm branch-owned queries are scoped.
- Confirm inventory stock remains ledger-derived.
- Run requested quality gates or report why they were not run.
- Check `git status`.

### Before Merge

Every AI agent must:

- Run architecture review checklist.
- Run branch compliance checklist.
- Run authorization/security checklist.
- Run inventory compliance checklist if inventory is touched.
- Confirm tests cover both allowed and denied paths.
- Confirm UI remains permission-aware and responsive if views changed.

## Architecture Review Checklist

### Architecture Compliance

- [ ] Controllers are thin.
- [ ] Form Requests handle validation.
- [ ] Services contain business rules.
- [ ] Repositories contain data access.
- [ ] Models are not workflow coordinators.
- [ ] Views contain no business logic.
- [ ] Repository interfaces are registered.
- [ ] Policies/gates are registered.

### Branch Compliance

- [ ] Branch-owned operations use `BranchContext::requireId()`.
- [ ] Branch-owned queries use `where('branch_id', $branchId)`.
- [ ] Route-bound branch-owned models are authorized.
- [ ] Selectors show active records from the active branch only.
- [ ] Submitted IDs are validated against the active branch.
- [ ] Other branch data cannot appear in reports, dashboards, or exports.

### Inventory Compliance

- [ ] Stock is derived from movement ledger.
- [ ] No mutable stock columns were added.
- [ ] Every movement has branch, location, and product.
- [ ] Product/location/supplier branch matches active branch.
- [ ] Adjustment out checks location-specific stock.
- [ ] Stock card order is `movement_date ASC, id ASC`.
- [ ] Low stock uses derived stock.

### Security Compliance

- [ ] Routes require auth where needed.
- [ ] Permissions protect routes/actions.
- [ ] Policies protect model actions.
- [ ] Validation covers all user input.
- [ ] File uploads validate type/size when applicable.
- [ ] Denial paths are tested.

### Testing Compliance

- [ ] Feature tests exist.
- [ ] Service tests exist for business rules.
- [ ] Authorization tests exist.
- [ ] Branch isolation tests exist.
- [ ] UI tests exist if Blade changed.
- [ ] Tests use existing Pest helpers and factories.

### Performance Compliance

- [ ] Large lists are paginated.
- [ ] Relationships are eager loaded.
- [ ] No N+1 query pattern was introduced.
- [ ] Common filters have indexes or documented follow-up.
- [ ] Reports and dashboards avoid unbounded queries.

## Future Sprint Protection Rules

### Sprint 13 - Inventory Advanced / Stock Opname

Must preserve:

- Ledger-derived stock.
- Branch and location isolation.
- No mutable product stock columns.
- Service-managed finalization.
- Transactional posting.

Stock opname must compare counted quantity against derived stock and post adjustment ledger movements when finalized. Draft counts must not become stock source of truth.

### Sprint 14 - Stock Transfer

Transfers must be ledger-based:

- Outbound movement from source location.
- Inbound movement to destination location.
- Same branch for inter-location transfer unless inter-branch transfer is explicitly designed.
- Transaction wraps both ledger entries.
- Source stock sufficiency checked per location.

Do not implement transfer by updating a stock column.

### Sprint 15 - Purchasing

Purchasing must remain separate from inventory receipt until goods are received.

Purchase orders may reserve intent, but must not increase stock. Stock increases only through a receipt/ledger movement.

Purchasing must validate supplier branch ownership and permission scope.

### Sprint 16 - Goods Receipt

Goods receipt may create inventory movements only after validation.

Receipt rules:

- Active branch.
- Active inventory location.
- Product belongs to branch.
- Supplier belongs to branch.
- Quantity greater than zero.
- Unit cost rules documented.
- Ledger movement created in a transaction.

### Sprint 17 - HR Core

HR must be a separate module. It must not be coupled directly to production, payroll, or attendance tables except through explicit services or relationships.

Employee records that are branch-owned must use `branch_id` and BranchContext.

### Sprint 18 - Attendance

Attendance must be branch-aware and employee-aware. Attendance events must be transactional records, not mutable daily summary fields as source of truth.

Reports may aggregate attendance, but raw attendance logs remain source of truth.

### Sprint 19 - Payroll

Payroll must be isolated from attendance and HR through services/repositories. Payroll calculations must be auditable and reproducible.

Payroll must not mutate attendance records. Payroll should reference locked periods or snapshots if future design requires immutability.

### Universal Future Sprint Rule

Every future sprint must answer these questions before implementation:

1. Which module owns this behavior?
2. Which records are branch-owned?
3. Which policies and permissions protect it?
4. Which service owns the business rule?
5. Which repository owns the query?
6. Which tests prove branch isolation?
7. Which UI components and layout conventions apply?
8. Which future features are explicitly out of scope?

If these questions are not answered, implementation must not start.

## Final Architecture Rule

ADLMS must stay boring in the best engineering sense: predictable layers, explicit permissions, branch-safe queries, ledger-derived inventory, small scoped changes, and tests that prove the important failures. Clever shortcuts are architectural debt. Do not take them.
