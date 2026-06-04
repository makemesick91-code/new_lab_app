# Sprint 13 Technical Design - Stock Opname & Controlled Adjustments

Date: 2026-06-05

## Project Context

Asia Dental Lab Management System is a Laravel modular monolith using Blade, Tailwind CSS, Alpine.js, and PostgreSQL.

Completed foundation:

- Sprint 0-12 completed.
- Sprint 12 Inventory Core completed.
- Inventory is branch-aware and location-aware.
- Branch isolation is enforced through `BranchContext`, repositories, services, policies, and UI flows.
- Stock is ledger-derived from `trx_inventory_movements`.
- No product-level mutable stock source-of-truth column exists.

Sprint 13 must preserve the existing architecture:

```text
Controller -> Request -> Service -> Repository -> Model
```

Sprint 13 must not replace existing Inventory workflows. It extends them with controlled stock opname and approved variance posting per Inventory Location.

## Business Goals

1. Let each branch count physical stock per Inventory Location.
2. Compare physical count against ledger-derived stock.
3. Make stock variance visible before any ledger correction is posted.
4. Require controlled review before variance adjustments affect stock.
5. Preserve a clear audit trail from stock opname session to inventory movement.
6. Prevent cross-branch and cross-location leakage.
7. Reduce operator mistakes during stock correction.

## Scope

In scope:

- Stock opname session per active branch and Inventory Location.
- Count sheet generation from active products and ledger-derived location stock.
- Manual counted quantity entry.
- Variance calculation: `counted_qty - expected_qty`.
- Submit stock opname for review.
- Approve stock opname and post variance as existing Inventory Movement ledger rows.
- Reject stock opname with reason.
- Cancel draft/counting sessions.
- Stock opname dashboard/list/detail UI.
- Controlled adjustment request design for non-opname corrections.
- Audit-ready references from `trx_inventory_movements.reference_type/reference_id`.

Out of scope:

- Purchase Order.
- Supplier payment.
- Production usage.
- Bill of Materials.
- Inter-location transfer.
- Inter-branch transfer.
- Inventory forecasting.
- FIFO/LIFO costing.
- Barcode scanning.
- Offline count mode.

## Reusable Inventory Workflow Patterns

Sprint 13 should introduce reusable workflow patterns for future inventory modules:

- Branch-scoped operation: every create/read/update uses `BranchContext::requireId()`.
- Location-bound operation: every stock-affecting workflow requires one active Inventory Location.
- Snapshot then post: capture expected stock first, review variance, then post ledger movement.
- Draft-submit-review lifecycle: mutable drafts become immutable once approved.
- Reference-linked ledger posting: generated movements must reference the source document.
- Idempotent approval: approving the same source twice must not post duplicate movements.
- Ledger remains truth: stock opname does not create mutable stock columns.

## Database Design

### inv_stock_opname_sessions

Purpose: one physical count session for one branch and one Inventory Location.

Columns:

```text
id
branch_id
inventory_location_id
opname_number
status
started_at nullable
submitted_at nullable
approved_at nullable
rejected_at nullable
cancelled_at nullable
created_by nullable
submitted_by nullable
approved_by nullable
rejected_by nullable
cancelled_by nullable
notes nullable
rejection_reason nullable
timestamps
```

Recommended statuses:

```text
DRAFT
COUNTING
SUBMITTED
APPROVED
REJECTED
CANCELLED
```

Indexes:

```text
branch_id
inventory_location_id
status
opname_number unique per branch
branch_id + inventory_location_id + status
```

Rules:

- Session belongs to one branch.
- Session belongs to one active Inventory Location.
- Only `DRAFT` and `COUNTING` sessions are editable.
- `APPROVED` sessions are immutable.
- Only one active `DRAFT`, `COUNTING`, or `SUBMITTED` session should exist per location unless explicitly allowed later.

### inv_stock_opname_lines

Purpose: product-level expected stock, counted stock, and variance inside one stock opname session.

Columns:

```text
id
stock_opname_session_id
branch_id
inventory_location_id
product_id
expected_qty decimal default 0
counted_qty decimal nullable
variance_qty decimal default 0
unit_cost_snapshot decimal default 0
notes nullable
counted_by nullable
counted_at nullable
timestamps
```

Indexes:

```text
stock_opname_session_id
branch_id
inventory_location_id
product_id
unique stock_opname_session_id + product_id
branch_id + inventory_location_id + product_id
```

Rules:

- `branch_id` and `inventory_location_id` must match the parent session.
- `product_id` must belong to the same active branch.
- `expected_qty` is captured from ledger-derived stock at line creation.
- `counted_qty` must be `>= 0` when entered.
- `variance_qty = counted_qty - expected_qty`.
- Lines cannot be edited after approval.

### Optional: inv_stock_adjustment_requests

Purpose: controlled non-opname adjustment workflow for manual stock correction requiring review.

This does not remove the existing Sprint 12 adjustment routes. It provides a future safer workflow for branches that need approval before manual corrections.

Columns:

```text
id
branch_id
inventory_location_id
product_id
adjustment_type
requested_qty decimal
unit_cost decimal default 0
status
reason
requested_by nullable
reviewed_by nullable
reviewed_at nullable
rejection_reason nullable
posted_movement_id nullable
timestamps
```

Recommended statuses:

```text
PENDING
APPROVED
REJECTED
CANCELLED
```

Recommended adjustment types:

```text
ADJUSTMENT_IN
ADJUSTMENT_OUT
```

Rules:

- Approval posts exactly one `trx_inventory_movements` row.
- `ADJUSTMENT_OUT` approval must fail if location stock is insufficient.
- `posted_movement_id` prevents duplicate ledger posting.

### Existing Ledger Integration

Approved stock opname variance posts to the existing table:

```text
trx_inventory_movements
```

Posting rules:

- Positive variance posts `ADJUSTMENT_IN`.
- Negative variance posts `ADJUSTMENT_OUT`.
- Zero variance posts no movement.
- `reference_type = STOCK_OPNAME`.
- `reference_id = inv_stock_opname_lines.id` or session id. Prefer line id for product-level traceability.
- `notes` should include opname number and reviewer note.
- Movement `branch_id`, `inventory_location_id`, and `product_id` must match the source line.

No new stock, current_stock, or qty_on_hand column is allowed.

## Service Design

### StockOpnameService

Responsibilities:

- `createSession(int $locationId, ?string $notes = null)`
- `startCounting(int $sessionId)`
- `generateLines(int $sessionId, array $productIds = [])`
- `updateCountLine(int $lineId, float $countedQty, ?string $notes = null)`
- `submit(int $sessionId)`
- `approve(int $sessionId, ?string $notes = null)`
- `reject(int $sessionId, string $reason)`
- `cancel(int $sessionId, ?string $reason = null)`
- `varianceSummary(int $sessionId)`

Business rules:

- Use `BranchContext::requireId()` for every branch-owned operation.
- Validate session, location, product, and lines belong to the active branch.
- Lock session and lines during approval.
- Recalculate current location stock before approval only for safety reporting; do not overwrite `expected_qty`.
- Prevent approval if any line has `counted_qty = null`.
- Prevent duplicate approval.
- Post variance through a ledger posting service or `InventoryStockService` extension.

### StockOpnamePostingService

Responsibilities:

- Convert approved variance lines into inventory movement rows.
- Ensure idempotency.
- Reuse existing movement constants:
  - `InventoryMovement::TYPE_ADJUSTMENT_IN`
  - `InventoryMovement::TYPE_ADJUSTMENT_OUT`
- Reject negative-stock result for outbound variance.

Implementation note:

If `InventoryStockService` remains the only service allowed to create movements, add internal methods such as:

```text
createReferencedAdjustmentIn(...)
createReferencedAdjustmentOut(...)
```

Do not bypass stock validation by writing directly from controllers.

### StockAdjustmentRequestService

Responsibilities:

- Create pending adjustment request.
- Approve and post one movement.
- Reject with reason.
- Cancel pending request.
- Prevent cross-branch product/location combinations.

This service may be deferred if Sprint 13 is implemented as stock opname only.

## Repository Design

### StockOpnameSessionRepositoryInterface

Recommended methods:

- `paginateForBranch(int $branchId, array $filters = [], int $perPage = 15)`
- `findInBranch(int $branchId, int $id)`
- `create(array $data)`
- `update(StockOpnameSession $session, array $data)`
- `activeSessionExists(int $branchId, int $locationId)`
- `lockForApproval(int $branchId, int $id)`

### StockOpnameLineRepositoryInterface

Recommended methods:

- `listForSession(int $branchId, int $sessionId)`
- `findInBranch(int $branchId, int $id)`
- `createMany(array $rows)`
- `update(StockOpnameLine $line, array $data)`
- `lockLinesForSession(int $branchId, int $sessionId)`
- `varianceRows(int $branchId, int $sessionId)`

### InventoryMovementRepositoryInterface Additions

Recommended additions:

- `currentStockSnapshotForLocation(int $branchId, int $locationId)`
- `movementExistsForReference(int $branchId, string $referenceType, int $referenceId)`
- `createReferencedMovement(array $data)`
- `movementsForReference(int $branchId, string $referenceType, int $referenceId)`

Repository rules:

- Always filter by `branch_id`.
- Location-specific queries must filter by `inventory_location_id`.
- Reference queries must also include branch filter.

## Controllers

### StockOpnameController

Actions:

- `index()`
- `create()`
- `store(StoreStockOpnameSessionRequest $request)`
- `show(StockOpnameSession $session)`
- `start(StockOpnameSession $session)`
- `submit(SubmitStockOpnameRequest $request, StockOpnameSession $session)`
- `approve(ApproveStockOpnameRequest $request, StockOpnameSession $session)`
- `reject(RejectStockOpnameRequest $request, StockOpnameSession $session)`
- `cancel(CancelStockOpnameRequest $request, StockOpnameSession $session)`

Controller rules:

- Controllers stay thin.
- Authorization through policies.
- Validation through Form Requests.
- Business logic in services.
- Data access in repositories.

### StockOpnameLineController

Actions:

- `update(UpdateStockOpnameLineRequest $request, StockOpnameLine $line)`
- Optional bulk count update endpoint for count sheet.

### StockAdjustmentRequestController

Optional if controlled manual adjustment is included.

Actions:

- `index()`
- `create()`
- `store(StoreStockAdjustmentRequest $request)`
- `show(StockAdjustmentRequest $request)`
- `approve(ApproveStockAdjustmentRequest $request, StockAdjustmentRequest $adjustment)`
- `reject(RejectStockAdjustmentRequest $request, StockAdjustmentRequest $adjustment)`

## Requests

Required stock opname requests:

- `StockOpnameFilterRequest`
- `StoreStockOpnameSessionRequest`
- `UpdateStockOpnameLineRequest`
- `SubmitStockOpnameRequest`
- `ApproveStockOpnameRequest`
- `RejectStockOpnameRequest`
- `CancelStockOpnameRequest`

Validation highlights:

```text
inventory_location_id required integer exists:inv_inventory_locations,id
notes nullable string max:2000
counted_qty nullable numeric min:0
rejection_reason required string max:2000
```

Important:

- Request `exists` rules are not enough.
- Branch/location/product ownership must be checked in services.

Optional adjustment request classes:

- `StoreStockAdjustmentRequest`
- `ApproveStockAdjustmentRequest`
- `RejectStockAdjustmentRequest`

## Policies

Required policies:

- `StockOpnameSessionPolicy`
- `StockOpnameLinePolicy`
- Optional `StockAdjustmentRequestPolicy`

Suggested abilities:

- `viewAny`
- `view`
- `create`
- `update`
- `submit`
- `approve`
- `reject`
- `cancel`

Permission suggestions using existing pattern:

```text
view_inventory
manage_inventory
count_inventory_stock
approve_inventory_stock_opname
```

Policy rules:

- Users may only access sessions in the active branch.
- Users may only access sessions for locations in the active branch.
- Lines inherit access from their parent session.
- Approved sessions and lines cannot be updated.
- Approval requires explicit permission or `manage_inventory`.
- Existing Super Admin gate bypass remains centralized.

## Routes

Suggested routes under existing `inventory.*` namespace:

```text
GET  inventory/stock-opnames
GET  inventory/stock-opnames/create
POST inventory/stock-opnames
GET  inventory/stock-opnames/{stockOpname}
POST inventory/stock-opnames/{stockOpname}/start
PATCH inventory/stock-opname-lines/{line}
POST inventory/stock-opnames/{stockOpname}/submit
POST inventory/stock-opnames/{stockOpname}/approve
POST inventory/stock-opnames/{stockOpname}/reject
POST inventory/stock-opnames/{stockOpname}/cancel
```

Route names:

```text
inventory.stock-opnames.index
inventory.stock-opnames.create
inventory.stock-opnames.store
inventory.stock-opnames.show
inventory.stock-opnames.start
inventory.stock-opname-lines.update
inventory.stock-opnames.submit
inventory.stock-opnames.approve
inventory.stock-opnames.reject
inventory.stock-opnames.cancel
```

Optional adjustment request routes:

```text
inventory.adjustment-requests.index
inventory.adjustment-requests.create
inventory.adjustment-requests.store
inventory.adjustment-requests.show
inventory.adjustment-requests.approve
inventory.adjustment-requests.reject
```

## UI Design

Use existing Blade and Tailwind conventions. Do not introduce React, Vue, or a new UI framework.

Views:

```text
resources/views/inventory/stock-opnames/index.blade.php
resources/views/inventory/stock-opnames/create.blade.php
resources/views/inventory/stock-opnames/show.blade.php
resources/views/inventory/stock-opnames/_line-table.blade.php
resources/views/inventory/stock-opnames/_summary.blade.php
resources/views/inventory/stock-opnames/_status-badge.blade.php
```

Index page:

- Filter by location, status, date range.
- Show opname number, location, status, variance count, created by, submitted/approved dates.
- Highlight submitted sessions needing approval.

Create page:

- Strong Inventory Location selector.
- Explain that expected stock is captured from ledger-derived stock.
- Empty state when no active Inventory Location exists.

Detail/count page:

- Product summary rows with:
  - code
  - product name
  - unit
  - expected quantity
  - counted quantity
  - variance
  - notes
- Variance badges:
  - zero variance: neutral/success
  - positive variance: emerald
  - negative variance: amber/rose
- Mobile layout should use product count cards rather than forcing wide tables.

Review page state:

- Show total lines.
- Show positive variance count/value.
- Show negative variance count/value.
- Show zero variance count.
- Show ledger posting preview.
- Approve and reject actions must be clearly separated.

Safety copy:

- "Approval will post inventory adjustment movements."
- "Stock remains ledger-derived."
- "Approved stock opname cannot be edited."

Navigation:

- Add "Stock Opname" under Inventory navigation when permission allows.

## Tests

Recommended test files:

```text
tests/Feature/Inventory/StockOpnameServiceTest.php
tests/Feature/Inventory/StockOpnameRouteAuthorizationTest.php
tests/Feature/Inventory/StockOpnameUiTest.php
tests/Feature/Inventory/StockOpnameBranchIsolationTest.php
```

Core scenarios:

- Guest cannot access stock opname routes.
- User without inventory permission is forbidden.
- Authorized user can create stock opname session for active branch location.
- User cannot create stock opname for another branch location.
- Session captures expected stock from ledger by location.
- Counted quantity updates variance.
- Counted quantity cannot be negative.
- Submit fails when any line has no counted quantity.
- Submit changes status to `SUBMITTED`.
- Approval posts adjustment movements for non-zero variance lines.
- Positive variance posts `ADJUSTMENT_IN`.
- Negative variance posts `ADJUSTMENT_OUT`.
- Zero variance posts no movement.
- Approval prevents duplicate movement posting.
- Approval fails if outbound variance would make location stock negative.
- Rejection requires reason.
- Approved sessions cannot be edited.
- Cancelled sessions do not post movements.
- Stock card shows movements referenced to stock opname.
- Stock opname list does not show another branch sessions.
- Stock opname line update cannot leak another branch product/location.
- UI shows empty state when no active locations exist.

## Risks

1. Expected stock snapshot drift.

Ledger stock may change after a session starts. Sprint 13 should keep `expected_qty` as a snapshot and display that it is a snapshot, not a live value.

2. Duplicate approval.

Approving a session twice can duplicate adjustment movements. Approval must lock the session and check reference-linked movements.

3. Negative stock on approval.

A negative variance may be valid during count but invalid at approval if stock changed. Approval must validate location stock before posting `ADJUSTMENT_OUT`.

4. Scope conflict with existing manual adjustment.

Sprint 12 already has direct adjustment in/out. Sprint 13 should not break it. If controlled adjustment requests are added, they should be additive and permission-gated.

5. Large count sheets.

Branches with many products may need pagination, bulk save, or CSV import later. Sprint 13 should keep count sheet efficient but avoid spreadsheet import unless explicitly scoped.

6. Cost valuation ambiguity.

Variance adjustment value can use `Product.average_cost`, but this is not accounting-grade costing. FIFO/LIFO is out of scope.

7. Inactive product/location edge cases.

Sessions should use active locations. Existing lines may reference products later deactivated; approval should still be safe if the line snapshot exists, but new sessions should prefer active products.

8. Audit expectations.

Inventory movement ledger is source of stock truth, but lifecycle audit for stock opname still matters. If `AuditLogService` is reused, define entity types before implementation.

## Acceptance Criteria

- Stock opname sessions are branch-owned and location-owned.
- Users can only access sessions in the active branch.
- Users can only count active Inventory Locations in the active branch.
- Count sheets capture expected stock from ledger-derived location stock.
- Counted quantity updates variance.
- Stock opname can be submitted only when required count lines are complete.
- Approved stock opname posts adjustment movements into `trx_inventory_movements`.
- Positive variance posts `ADJUSTMENT_IN`.
- Negative variance posts `ADJUSTMENT_OUT`.
- Zero variance posts no movement.
- Posted movements include `reference_type = STOCK_OPNAME`.
- Posted movements include `reference_id` linking back to the source line or session.
- Approval is idempotent and cannot duplicate postings.
- Approved sessions and lines are immutable.
- Rejected sessions require a rejection reason.
- Cancelled sessions do not affect stock.
- Stock remains calculated from ledger only.
- No mutable `stock`, `current_stock`, or `qty_on_hand` source-of-truth column is introduced.
- Controllers remain thin.
- Validation remains in Form Requests.
- Business logic remains in Services.
- Data access remains in Repositories.
- Policies prevent cross-branch and cross-location access.
- Feature tests cover branch isolation, location isolation, variance calculation, approval posting, duplicate approval prevention, and UI empty states.

## Likely Files To Create

```text
app/Modules/Inventory/Controllers/StockOpnameController.php
app/Modules/Inventory/Controllers/StockOpnameLineController.php
app/Modules/Inventory/Interfaces/StockOpnameSessionRepositoryInterface.php
app/Modules/Inventory/Interfaces/StockOpnameLineRepositoryInterface.php
app/Modules/Inventory/Models/StockOpnameSession.php
app/Modules/Inventory/Models/StockOpnameLine.php
app/Modules/Inventory/Policies/StockOpnameSessionPolicy.php
app/Modules/Inventory/Policies/StockOpnameLinePolicy.php
app/Modules/Inventory/Repositories/StockOpnameSessionRepository.php
app/Modules/Inventory/Repositories/StockOpnameLineRepository.php
app/Modules/Inventory/Requests/StockOpnameFilterRequest.php
app/Modules/Inventory/Requests/StoreStockOpnameSessionRequest.php
app/Modules/Inventory/Requests/UpdateStockOpnameLineRequest.php
app/Modules/Inventory/Requests/SubmitStockOpnameRequest.php
app/Modules/Inventory/Requests/ApproveStockOpnameRequest.php
app/Modules/Inventory/Requests/RejectStockOpnameRequest.php
app/Modules/Inventory/Requests/CancelStockOpnameRequest.php
app/Modules/Inventory/Services/StockOpnameService.php
app/Modules/Inventory/Services/StockOpnamePostingService.php
database/factories/StockOpnameSessionFactory.php
database/factories/StockOpnameLineFactory.php
resources/views/inventory/stock-opnames/*
tests/Feature/Inventory/StockOpname*.php
```

Likely files to modify during implementation:

```text
routes/web.php
app/Providers/RepositoryServiceProvider.php
database/seeders/PermissionSeeder.php
database/seeders/RoleSeeder.php
resources/views/layouts/sidebar.blade.php
app/Modules/Inventory/Interfaces/InventoryMovementRepositoryInterface.php
app/Modules/Inventory/Repositories/InventoryMovementRepository.php
app/Modules/Inventory/Services/InventoryStockService.php
```

No application code is modified by this technical design document.
