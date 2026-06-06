# ADLMS Sprint History

Version: 1.0
Last updated: June 2026
Status: **Permanent project memory.** Captures the major decisions from Sprint 0 through
Sprint 15.2.

This document is a durable record for humans and future AI agents. It is **descriptive**
(what was decided and why) and subordinate to the authoritative rule docs
[architecture_rules.md](architecture_rules.md) and [ai_development_guide.md](ai_development_guide.md).
Where this history and those rules disagree, the rule docs win.

It was reconstructed from primary sources: `git log` (commit-by-commit), `database/migrations`
(31 migrations), `app/Modules` (17 module folders), `tests/Feature`, and the design docs under
`docs/`.

> **Numbering note (read this first).** The repository contains two overlapping sprint-naming
> conventions. Git commits and `docs/sprint_9_multi_branch_foundation.md` label the multi-branch
> work as "Sprint 9". The canonical `ai_development_guide.md` instead numbers the late sprints as
> **9 = Release Hardening, 10 = Branch Context, 11 = Branch Enforcement, 12 = Inventory Core**.
> This document follows the **ai_development_guide numbering** (the project's authoritative scheme)
> and notes the artifact overlaps where they occur. Treat sprint numbers 9–11 as a phase boundary,
> not three cleanly separated release tags.

---

# Project Timeline

| Sprint | Theme | Primary commit(s) | Key schema added |
|---|---|---|---|
| 0 | Foundation | `d6d51f3` | `users`, `cache`, `jobs` |
| 1 | User Access Management | `cb6ef79` | `permission_tables`, profile fields on `users` |
| 2 | Master Data | `b96f152` | `mst_clinics/doctors/patients/lab_services/technicians` |
| 3 | Lab Order Core | `764886b` | `trx_lab_orders`, `_items`, `_status_logs`, `sys_attachments`, `sys_audit_logs` |
| 4 | Production Workflow | `eabb924` | `trx_lab_order_assignments`, `trx_lab_work_logs`, `trx_lab_production_steps` |
| 5 | Quality Control | `70c764f` | `trx_lab_quality_controls`, `_qc_checklists`, `_remake_requests` |
| 6 | Delivery & POD | `2ab766b` | `trx_lab_deliveries` |
| 7 | Invoice & Payment | `6e575b6` | `trx_invoices`, `_invoice_items`, `trx_payments` |
| 8 | Reporting | `c8c6a28` | (none — read-only aggregation) |
| 8.1 | Reporting Enhancements / MRN | `1e55f85` | `add_medical_record_number_to_trx_lab_orders` |
| 9 | Release Hardening | `f3d0f6f`, `2055d58`, `ad56f96` | (none — tests, scripts, redirects) |
| 10 | Branch Context | `b53bbe3`, `0a576cb` | `mst_branches`, `add_branch_id_to_core_transaction_tables` |
| 11 | Branch Enforcement | `b53bbe3`, `675cd5f` | `backfill_default_branch` |
| 12 | Inventory Core | `cafbb73` … `bbc9843` | `inv_*` (6 tables incl. `trx_inventory_movements`) |
| (post-12) | UI Design System & Dashboards | `e8c0141` … `52a7a1d` | (none — Blade/components) |
| 13 | Stock Opname Workflow | `f4718c1` / `sprint-13.1-complete` | `trx_stock_opnames`, `trx_stock_opname_items` |
| 14 | Stock Transfer Workflow | `72b618a` … (Sprint 14.7) / `sprint-14-ui-context-complete` | `trx_stock_transfers`, `trx_stock_transfer_items`; `TRANSFER_IN`/`TRANSFER_OUT` movement types |
| 15.2 | Transfer Receiving Workflow | (Sprint 15.2 ship/receive refactor) | `shipped_at`, `shipped_by` on `trx_stock_transfers`; workflow `in_transit` / `received` statuses |

Architecture has been **additive and consistent** throughout: every sprint extended the modular
monolith rather than replacing patterns. Stack held constant: Laravel modular monolith, Blade +
Tailwind + Alpine, PostgreSQL, Pest, Spatie Permission.

---

# Sprint 0 — Foundation

**Goals:** Stand up the Laravel application shell, authentication baseline, and project structure
that every later sprint builds on.

**Architecture decisions:**
- Chose a **modular monolith**: domains live under `app/Modules/<Module>`, each owning
  Controllers, Requests, Services, Repositories + Interfaces, Models, Policies.
- Established the mandatory flow **Controller → Request → Service → Repository → Model**.
- Centralized wiring in `app/Providers/RepositoryServiceProvider.php` (interface→implementation
  bindings, policy/gate registration, `Gate::before` Super Admin bypass, morph map).

**Database decisions:**
- **Single PostgreSQL database**; domain separation enforced by module boundaries, table-name
  prefixes, foreign keys, policies, and branch filters — not multiple databases.
- Table prefix convention: `mst_` (master), `trx_` (transaction), `sys_` (system), later `inv_`
  (inventory master). Migrations: `users`, `cache`, `jobs`.

**Testing decisions:**
- **Pest** for feature tests; `tests/Pest.php` applies `RefreshDatabase` to the Feature suite and
  hosts shared helpers. Tests run against SQLite in-memory; migrate/seed against PostgreSQL.
- Principle set early: prove both allowed and denied paths.

---

# Sprint 1 — User Access Management

**Features:** Users, roles, permissions, access-control routes, permission-aware navigation, and
profile fields on `users`. Modules: `AccessControl`, `User`. Tests under
`tests/Feature/AccessControl`, `tests/Feature/User`.

**Permissions:** Adopted **Spatie Laravel Permission** (`permission_tables` migration). Seeded in
`database/seeders/PermissionSeeder.php`; assigned in `RoleSeeder.php`. A convention split was
born here and tolerated since: older **space-separated** permissions (`manage users`,
`manage clinics`) coexist with later **underscore** permissions (`view_lab_orders`,
`manage_inventory`). Follow the target module's local convention; don't rename old names casually.

**Roles (canonical set, stable since here):** `Super Admin`, `Admin Lab`, `Technician`,
`Quality Control`, `Delivery Coordinator`, `Courier`, `Finance`, `Doctor`.

**Policies:** Authorization is policy/gate-first. `Super Admin` receives a **single centralized
`Gate::before` bypass** in `RepositoryServiceProvider` — never duplicated. UI visibility uses
`@can`/`@canany`/`@role` but is never the only protection; routes/controllers still authorize.

---

# Sprint 2 — Master Data

**Decisions:** Added the core master-data modules via a repeatable modular-CRUD pattern:
`Clinic`, `Doctor`, `Patient`, `LabService`, `Technician` (tables `mst_clinics`, `mst_doctors`,
`mst_patients`, `mst_lab_services`, `mst_technicians`). Tests under `tests/Feature/MasterData`.

Conventions established here and reused everywhere after:
- **Lifecycle by `is_active`**, not hard delete — master records referenced by transactions are
  deactivated, never destroyed.
- Repository pattern with branch-friendly list/find methods; `Store*Request` / `Update*Request`
  form requests; per-entity policies registered centrally.
- Multi-word resource routes map camelCase parameters (e.g. `lab-services` → `labService`).

---

# Sprint 3 — Lab Order Core

**Order lifecycle:** The Lab Order became the system's spine. Tables: `trx_lab_orders`,
`trx_lab_order_items`, `trx_lab_order_status_logs`, plus polymorphic `sys_attachments` and
`sys_audit_logs`. Module: `LabOrder`.

**Status flow (single source of truth on the model):** `DRAFT → RECEIVED → ASSIGNED →
IN_PRODUCTION → (ON_HOLD) → QC_PENDING → QC_PASSED → READY_FOR_DELIVERY → IN_DELIVERY →
DELIVERED → COMPLETED`, with `REMAKE` and `CANCELLED` as off-ramps. Status constants live on the
`LabOrder` model.

**Architecture decisions:**
- **Status history + audit logging** as first-class concerns: status changes recorded in status
  logs; important activity in `sys_audit_logs`.
- **Polymorphic morph map** registered in `RepositoryServiceProvider` so `entity_type` stores the
  table name (e.g. `trx_lab_orders`) — and deliberately **non-enforcing** so Spatie role morphs
  keep their defaults.
- Multi-step writes (order + items) wrapped in DB transactions; PostgreSQL serial-PK quirk handled
  by stripping `id` from item arrays before insert.

---

# Sprint 4 — Production Workflow

**Workflow decisions:** Module `Production` with tables `trx_lab_order_assignments`,
`trx_lab_work_logs`, `trx_lab_production_steps`. Introduced the **named-gate** pattern for
workflow actions (LabOrder already owns a model policy, so production checks are registered as
gates): `production.start|pause|resume|complete|sendToQc`, `production.assign|reassign|...`.

Key rules: assignment and each transition validated in `ProductionWorkflowService` /
`AssignmentService` (not controllers); transitions are transactional. Ownership tests use
permission-holding non-admin users (Super Admin bypasses policies via `Gate::before`, so a
super-admin invalid transition surfaces a service `ValidationException`, not a 403).

---

# Sprint 5 — Quality Control

**QC decisions:** Module `QualityControl`; tables `trx_lab_quality_controls`,
`trx_lab_qc_checklists`, `trx_lab_remake_requests`. Gates `qc.start|pass|reject|uploadEvidence`,
`qc.checklists.update`, `qc.requestRemake`. QC review starts by generating a default checklist;
pass/reject drives the order's status; a rejection can spawn a **remake request** that loops the
order back. Evidence uploads validated; (the GD-less environment forced
`UploadedFile::fake()->create()` over `->image()` in tests). Carbon 3 `diffInMinutes` returns
float → cast to int for durations.

---

# Sprint 6 — Delivery & POD

**Delivery architecture:** Module `Delivery`; table `trx_lab_deliveries`. Lifecycle
`READY_FOR_DELIVERY → IN_DELIVERY → DELIVERED → COMPLETED` (+ `CANCELLED`), with courier
assignment and **proof of delivery** (receiver name, signature path, photo path, received_at).
Gates `delivery.*`. Files stored on disk with paths/metadata in the DB — **never base64 in the
database**. Clinic is derived through the related lab order (Delivery has no direct `clinic_id`);
dates derive from `created_at`/`completed_at`.

---

# Sprint 7 — Invoice & Payment

**Finance architecture:** Module `Invoice`; tables `trx_invoices`, `trx_invoice_items`,
`trx_payments`. Invoice statuses `DRAFT → ISSUED → PARTIALLY_PAID → PAID`, plus `OVERDUE` and
`VOID`. Payment methods `CASH/BANK_TRANSFER/QRIS/CARD/OTHER`. Policies `InvoicePolicy`,
`PaymentPolicy`. Money modeled with `total_amount`/`paid_amount`/`outstanding_amount` (not
`grand_total`); issue/void/payment flows are transactional and recompute outstanding. Finance
permissions gate access.

---

# Sprint 8 — Reporting

**Reporting architecture:** Module `Reporting` — **read-only aggregation, no new tables**. A
single `ReportingRepository` exposes query/aggregate methods consumed by per-report services
(orders, production, QC, delivery, invoices, payments, outstanding, revenue) and a dashboard.
Gates `reporting.dashboard|orders|production|qc|delivery|invoices|payments|export`. VOID invoices
are excluded from revenue/outstanding. Query Builder `->tap()` is unavailable here — use
`->when(true, ...)`.

---

# Sprint 8.1 — Reporting Enhancements

**Improvements:** Export usability and operational visibility for reports, plus a domain addition:
**Medical Record Number** on lab orders (`add_medical_record_number_to_trx_lab_orders` migration;
MRN added to fillable, search, and the order form/detail). Also: root route now redirects to login
(`ad56f96`), and PostgreSQL backup/restore scripts were added (`2055d58`).

---

# Sprint 9 — Release Hardening

**Security & stability decisions:** Hardened the system for pilot/live testing rather than adding
features. Evidence: `tests/Feature/Hardening/` (`ProductionConfigTest`, `AuthorizationCoverageTest`,
`DebugLeakTest`, `ReportExportSecurityTest`, `BackupScriptTest`).

- **Authorization coverage** audited across modules (no unprotected protected action).
- **Production config & debug-leak** checks (no debug exposure in production posture).
- **Report export security** verified.
- **Backup/restore** scripts validated by test.
- Pre-modular-consolidation baseline taken (`f3d0f6f`); unused module placeholders removed
  (`d16940c`).

---

# Sprint 10 — Branch Context

**BranchContext:** Introduced multi-branch foundations. `mst_branches` table; `branch_id` added to
the four core transaction tables (`trx_lab_orders`, `trx_lab_deliveries`, `trx_invoices`,
`trx_payments`). The central resolver is `app/Modules/Branch/Services/BranchContext.php`
(commit `0a576cb`), exposing `id()`, `branch()`, `requireId()`, `forUser(User)`.

**Resolution rules (in order):**
1. Authenticated user's active `branch_id` column, if present.
2. First active branch from the user's `branches()` relation, if present.
3. Seeded default **MAIN** branch via the branch repository.

**Fallback rules:** `requireId()` throws a clear `RuntimeException` if no branch can be resolved.
**User input must never decide `branch_id`** — the active branch is resolved centrally, server-side.
`Branch::MAIN_CODE` is the single source of truth for the default branch; resolve by code, never a
hard-coded id.

> Artifact note: this work is captured in `docs/sprint_9_multi_branch_foundation.md` and the
> commit "Complete multi branch foundation" — the multi-branch foundation, branch context, and
> enforcement foundation were delivered as one phase but are split across Sprints 10–11 in the
> canonical numbering.

---

# Sprint 11 — Branch Enforcement

**Isolation rules:** Branch isolation is **mandatory and security-critical**; cross-branch leakage
is treated as a security bug. The `backfill_default_branch` migration anchored legacy rows to the
MAIN branch (idempotent, no data loss). Branch policies and **branch-isolation tests**
(`tests/Feature/Branch`) were added; `branch_id` became fillable with `branch()` relations on the
core models.

**Protection rules:**
- Every branch-owned **service** method calls `BranchContext::requireId()`, loads via
  branch-scoped repository methods, validates related records share the branch, and rejects
  another branch's IDs.
- Every branch-owned **repository** method takes `int $branchId` first and applies
  `where('branch_id', $branchId)`; provides `findInBranch()`-style lookups; avoids unbounded
  `all()`.
- Every branch-owned **policy** checks permission **and** active-branch ownership, failing closed
  when no active branch exists.
- The enforcement was delivered as a **foundation** (opt-in `branch_id` filters with
  `TODO(branch-scope)` markers) so existing behavior was preserved while the mechanism was put in
  place.

---

# Sprint 12 — Inventory Core

**Inventory architecture:** Module `Inventory`, full stack (models, factories, seeders,
repositories + interfaces, services, requests, policies, controllers, routes, Blade views, nav,
UI tests). The operational scope nests inside a branch:

```text
Branch -> Inventory Location -> Product -> Inventory Movement Ledger
```

Tables (`create_inventory_core_tables`): `inv_product_categories`, `inv_product_units`,
`inv_products`, `inv_suppliers`, `inv_inventory_locations`, `trx_inventory_movements`.

**Ledger rules (the defining decision):** Stock is **ledger-derived only**:
```text
current stock = SUM(quantity_in) - SUM(quantity_out)
```
applied to product+location stock, branch-wide stock, location stock, stock card running balance,
low-stock detection, and valuation. **No mutable stock columns ever** (`stock`, `current_stock`,
`qty_on_hand`, etc. are forbidden). `current_stock` is allowed **only** as a query alias.
Movement types: `OPENING`, `PURCHASE`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`. Every movement carries
`branch_id`, `inventory_location_id`, `product_id`, `movement_type`, `movement_date`,
`quantity_in`, `quantity_out`. Stock card ordered `movement_date ASC, id ASC`. Valuation =
`derived stock × product.average_cost` (no FIFO/LIFO/costing engine). Adjustment-out checks
**location-specific** stock and rejects insufficient/zero/negative quantities, inside a
transaction.

**Location rules:** Every location belongs to exactly one branch; every stock operation requires
`inventory_location_id`; product branch and location branch must match; users only access
active-branch locations; location filters must never leak another branch's stock.

**Permissions:** `view_inventory` / `manage_inventory` (underscore convention) gate routes and
controller policies; policies combine permission with active-branch ownership. UI uses the
`inventory.*` component family; the Sprint 12 `inventory/products/index` view became the reference
implementation of current UI conventions (teal primary, bordered cards, dual desktop-table /
mobile-card responsive lists, ledger-derived labeling).

---

# Sprint 13 — Stock Opname Workflow

## Sprint 13 Overview

**Status:** COMPLETED. Release tag `sprint-13.1-complete`, commit `f4718c1`, completion date
2026-06-05.

**Business objective:** Extend Inventory with a branch- and location-aware physical stock counting
workflow. The workflow lets a branch count actual quantities, compare them to ledger-derived
system stock, review variances, and generate adjustment movements during finalization.

**Inventory stock counting workflow:** Users create a draft opname for one inventory location,
optionally preselect products, snapshot each product's system quantity from the movement ledger,
and enter counted quantities on the count/detail screen.

**Variance review workflow:** `reviewOpname()` moves a `DRAFT` count into the implemented
review-ready `COUNTING` state. The Variance Review screen shows read-only system quantity, counted
quantity, variance, and Over/Short/Match classification. It does not show invalid workflow actions;
Finalize is available only while the opname is `COUNTING`.

**Adjustment generation workflow:** `finalizeOpname()` posts non-zero variances into
`trx_inventory_movements`: positive variance creates `ADJUSTMENT_IN`, negative variance creates
`ADJUSTMENT_OUT`, and zero variance creates no movement. Generated movements reference
`trx_stock_opnames`.

## Deliverables

**Database:**
- `trx_stock_opnames`
- `trx_stock_opname_items`

**Models:**
- `StockOpname`
- `StockOpnameItem`

**Services:**
- `createDraftOpname()`
- `updateCountedQuantity()`
- `reviewOpname()`
- `finalizeOpname()`
- `cancelOpname()`

**Requests:**
- `StoreStockOpnameRequest`
- `UpdateStockOpnameItemRequest`
- `ReviewStockOpnameRequest`
- `FinalizeStockOpnameRequest`
- `CancelStockOpnameRequest`

**Policy:** `StockOpnamePolicy`.

**Controller:** `StockOpnameController`.

**Routes:**

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

**Views:**
- Index
- Create
- Show
- Variance Review

## Key Features

- Draft workflow
- Counting workflow
- Variance review
- Finalization
- Cancellation
- Ledger-derived stock adjustments

## Quality Gates

| Gate | Result |
|---|---|
| Inventory Tests | PASS |
| StockOpname Tests | PASS |
| Pint | PASS |
| Build | PASS |
| Routes | PASS |

## Documentation Added

- `sprint_13_technical_design.md`
- `sprint_13_completion_summary.md`
- `sprint_13_inventory_audit.md`

## Architecture Notes

- **Ledger-derived stock:** Final stock remains derived from `trx_inventory_movements`; Stock
  Opname records snapshots and variances only.
- **Branch isolation:** Opnames are branch- and location-owned. Services resolve branch through
  `BranchContext`, repositories scope reads by branch, and policy checks preserve active-branch
  ownership.
- **Transaction safety:** Stock Opname write workflows are service-owned and transactional.
  Finalization locks the branch-owned opname and validates products/location before posting
  adjustment movements.
- **No direct stock updates:** Sprint 13 introduced no mutable stock columns and does not update
  product stock directly.

## Known Decisions

- `COUNTING` status is used as the review-ready state; no separate `REVIEW` or `FINALIZED` status
  was introduced.
- Adjustment movements are generated only during finalization.
- The Variance Review screen is read-only after finalization.

## Release Information

| Field | Value |
|---|---|
| Release Tag | `sprint-13.1-complete` |
| Commit | `f4718c1` |
| Completion Date | 2026-06-05 |
| Status | COMPLETED |

---

# Sprint 14 — Stock Transfer Workflow

## Sprint 14 Overview

**Status:** COMPLETED. Release tag `sprint-14-ui-context-complete`, schema commit `72b618a`,
completion date 2026-06-06 (Sprint 14.7 documentation and release completion).

**Business objective:** Extend Inventory with a branch-scoped inter-location stock transfer
workflow. Users create a transfer document from a source location to a destination location within
the active branch, submit it for processing, and complete it to post paired ledger movements. Stock
remains ledger-derived; transfer tables store workflow identity and requested quantities only.

**Workflow states:** `draft → submitted → completed`, with `cancelled` as a terminal off-ramp from
`draft` or `submitted`. Draft transfers are editable; submitted transfers await completion;
completed transfers are immutable and show linked ledger movements on the detail screen.

## Deliverables

**Database:**
- `trx_stock_transfers` (transfer document header)
- `trx_stock_transfer_items` (per-product transfer lines)

**Models:**
- `StockTransfer`
- `StockTransferItem`

**Movement types (ledger extension):**
- `TRANSFER_OUT` — outbound from source location on completion
- `TRANSFER_IN` — inbound to destination location on completion

**Services:**
- `createTransfer()`
- `updateTransfer()`
- `submitTransfer()`
- `completeTransfer()`
- `cancelTransfer()`
- `getTransferDetails()`

**Repository:**
- `StockTransferRepository` / `StockTransferRepositoryInterface`

**Requests:**
- `StoreStockTransferRequest`
- `UpdateStockTransferRequest`
- `SubmitStockTransferRequest`
- `CompleteStockTransferRequest`
- `CancelStockTransferRequest`
- `ValidatesStockTransferInput` (shared concern)

**Policy:** `StockTransferPolicy`.

**Controller:** `StockTransferController`.

**Routes:**

| Method | URI | Name |
|---|---|---|
| GET | `inventory/stock-transfers` | `inventory.stock-transfers.index` |
| GET | `inventory/stock-transfers/create` | `inventory.stock-transfers.create` |
| POST | `inventory/stock-transfers` | `inventory.stock-transfers.store` |
| GET | `inventory/stock-transfers/{stock_transfer}` | `inventory.stock-transfers.show` |
| GET | `inventory/stock-transfers/{stock_transfer}/edit` | `inventory.stock-transfers.edit` |
| PUT/PATCH | `inventory/stock-transfers/{stock_transfer}` | `inventory.stock-transfers.update` |
| POST | `inventory/stock-transfers/{stockTransfer}/submit` | `inventory.stock-transfers.submit` |
| POST | `inventory/stock-transfers/{stockTransfer}/complete` | `inventory.stock-transfers.complete` |
| POST | `inventory/stock-transfers/{stockTransfer}/cancel` | `inventory.stock-transfers.cancel` |

**Views:**
- Index (`inventory/stock-transfers/index`)
- Create (`inventory/stock-transfers/create`)
- Edit (`inventory/stock-transfers/edit`)
- Show (`inventory/stock-transfers/show`)
- Partials: `_form`, `_status-badge`
- Sidebar link: Transfer Stok (permission-gated via `@can('viewAny', StockTransfer::class)`)

**Factories:**
- `StockTransferFactory`
- `StockTransferItemFactory`

## Database Changes

**`trx_stock_transfers`**
- `branch_id` → `mst_branches`
- `transfer_number` (unique per branch: `UNIQUE(branch_id, transfer_number)`)
- `source_inventory_location_id` → `inv_inventory_locations`
- `destination_inventory_location_id` → `inv_inventory_locations`
- `transfer_date`, `status` (default `draft`), `notes`
- `requested_by`, `approved_by` (nullable), `completed_at` (nullable), `created_by` → `users`
- Indexes: `branch_id`, source/destination location, `status`, `transfer_date`,
  `(branch_id, status)`, `(branch_id, transfer_date)`

**`trx_stock_transfer_items`**
- `stock_transfer_id` → `trx_stock_transfers` (cascade delete)
- `product_id` → `inv_products`
- `quantity` (`decimal(12,2)`), `notes`
- Index: `(stock_transfer_id, product_id)`

**No mutable stock columns** were added to products, locations, or any other table.

## Models and Relationships

**`StockTransfer`** (`trx_stock_transfers`)
- Status constants: `draft`, `submitted`, `completed`, `cancelled` (lowercase strings)
- Relations: `branch`, `sourceInventoryLocation`, `destinationInventoryLocation`, `requestedBy`,
  `approvedBy`, `createdBy`, `items`
- Casts: `transfer_date` (date), `completed_at` (datetime)

**`StockTransferItem`** (`trx_stock_transfer_items`)
- Relations: `stockTransfer`, `product`
- Casts: `quantity` (decimal:2)
- Line quantities are requested transfer amounts, not stock balances

**`InventoryMovement`** (extended)
- Added `TYPE_TRANSFER_IN` and `TYPE_TRANSFER_OUT` to movement type constants
- Completed transfers reference `reference_type = trx_stock_transfers`,
  `reference_id = transfer.id`

## Services and Business Rules

`StockTransferService` owns all workflow rules inside `DB::transaction()` boundaries:

1. **Branch resolution:** `BranchContext::requireId()` — never trust request `branch_id`.
2. **Location validation:** Source and destination must be active locations in the active branch;
   source and destination must differ.
3. **Product validation:** Items must reference active products in the active branch; quantities
   must be > 0; duplicate product lines are merged on create/update.
4. **Draft-only edits:** `updateTransfer()` allowed only when status is `draft`.
5. **Submit:** `submitTransfer()` moves `draft → submitted` after validating locations, items, and
   product/quantity rules.
6. **Complete:** `completeTransfer()` allowed only from `submitted`; locks transfer, locations,
   products, and items with `lockForUpdate()`; checks per-product source location derived stock
   sufficiency; posts paired `TRANSFER_OUT` (source) and `TRANSFER_IN` (destination) movements per
   item; sets `approved_by`, `completed_at`, status `completed`.
7. **Cancel:** Allowed from `draft` or `submitted`; blocked for `completed` or already `cancelled`.
8. **Transfer number:** Generated as `TRF-{Ym}-{RANDOM6}` (e.g. `TRF-202606-ABCDEF`).
9. **Movement metadata:** `unit_cost` from `product.average_cost`; `movement_date` from transfer
   date; notes reference the transfer number.

## Requests

All write requests use `ValidatesStockTransferInput` for shared rules:
- Required distinct source/destination location IDs (active, in active branch)
- Required `items` array (min 1) with `product_id`, `quantity > 0`
- Optional `transfer_date`, `notes`
- Branch-safe `withValidator` checks for active locations and products

`SubmitStockTransferRequest` and `CompleteStockTransferRequest` carry no body fields.
`CancelStockTransferRequest` accepts optional `notes`.

## Policies

`StockTransferPolicy` uses `ChecksInventoryAccess`:
- `viewAny` / `view`: `view_inventory` + active-branch ownership
- `create` / `update` / `delete` / `submit` / `complete` / `cancel`: `manage_inventory` +
  active-branch ownership
- Super Admin bypass remains centralized in `RepositoryServiceProvider` (not duplicated)

## Controllers and Routes

`StockTransferController` is thin: authorize, delegate to service/repository, return view/redirect.
Index paginates via repository with filters (search, source/destination location, status, date
range). Show loads ledger movements when status is `completed`. Registered under
`inventory.*` prefix with `auth` middleware in `routes/web.php`. Wired in
`RepositoryServiceProvider` (interface binding + policy registration).

## Blade UI

Follows `docs/ui_design_system.md` conventions:
- `<x-settings-shell>` layout, teal primary (`teal-700`), bordered cards, dual desktop-table /
  mobile-card responsive lists
- Indonesian operator-facing labels (Transfer Stok, Lokasi Sumber/Tujuan, status badges)
- Permission-aware action buttons (`@can` for create, edit, submit, complete, cancel)
- Show page displays linked ledger movements for completed transfers
- Empty states and filter card on index

## Tests

65 focused tests across 7 files under `tests/Feature/Inventory/`:

| File | Focus |
|---|---|
| `StockTransferModelTest` | Relations, casts, statuses, fillable |
| `StockTransferServiceTest` | Workflow, ledger posting, stock sufficiency, branch isolation |
| `StockTransferRequestTest` | Validation, branch-safe location/product checks |
| `StockTransferPolicyTest` | Authorization matrix, cross-branch denial |
| `StockTransferControllerTest` | Routes, HTTP happy paths, auth denial |
| `StockTransferHardeningTest` | Branch isolation, ledger correctness, status guards, no mutable stock |
| `StockTransferUiTest` | Blade visibility, Indonesian labels, permission-gated buttons |

Coverage includes: happy path, validation, authorization, branch isolation, location isolation,
ledger correctness (paired movements, derived balances), insufficient stock, inactive
product/location rejection, status transition guards, and UI permission visibility.

## Quality Gates

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 501 tests, 1460 assertions |
| Stock Transfer tests (`--filter=StockTransfer`) | PASS — 65 tests, 319 assertions |
| Pint (`vendor/bin/pint --test`) | PASS |
| Routes (`php artisan route:list --name=stock-transfer`) | PASS — 9 routes registered |
| Build (`npm run build`) | PASS |

## Architecture Notes

- **Layering preserved:** `Controller → Request → Service → Repository → Model`.
- **Ledger-derived stock:** Transfer documents do not store or mutate stock; completion posts
  append-only movements to `trx_inventory_movements`.
- **Atomic transfer:** Paired `TRANSFER_OUT` + `TRANSFER_IN` movements are created in a single
  transaction per completion — never simulate transfers via manual adjustment pairs.
- **Reference linkage:** Movements reference the transfer header via polymorphic
  `reference_type`/`reference_id` for auditability on the show screen.
- **Concurrency:** `lockForUpdate()` on transfer, locations, products, and items during
  completion; source stock checked against ledger-derived balance before posting.

## Branch Isolation Notes

- Every transfer carries `branch_id` resolved from `BranchContext`.
- Repository methods accept `int $branchId` first and apply `where('branch_id', $branchId)`.
- Source and destination locations must belong to the active branch.
- Products on transfer lines must belong to the active branch.
- Policies verify `belongsToActiveBranch($stockTransfer->branch_id)`.
- Cross-branch transfer access is denied at policy, service, controller, and HTTP layers.
- Inter-branch transfer is **out of scope** — same-branch locations only.

## Inventory Ledger Notes

- New movement types: `TRANSFER_IN`, `TRANSFER_OUT` (added to `InventoryMovement::TYPES`).
- On completion, each item creates:
  - `TRANSFER_OUT` at source: `quantity_out = item.quantity`, `quantity_in = 0`
  - `TRANSFER_IN` at destination: `quantity_in = item.quantity`, `quantity_out = 0`
- Stock before/after remains `SUM(quantity_in) - SUM(quantity_out)` per product+location.
- No mutable `current_stock`, `stock`, or `qty_on_hand` columns introduced or used.
- Insufficient source location stock blocks completion and rolls back the transaction.

## Known Decisions

- Transfer statuses use lowercase strings (`draft`, `submitted`, `completed`, `cancelled`) stored
  in the database — distinct from Stock Opname uppercase status convention.
- Two-step approval workflow: `submit` then `complete` (no separate `approved` status; completion
  sets `approved_by`).
- Draft and submitted transfers can be cancelled; completed transfers are terminal.
- Transfer line quantities are document quantities only; ledger posting happens exclusively on
  `completeTransfer()`.
- `manage master data` legacy permission remains accepted for inventory transfer actions via
  `ChecksInventoryAccess` (backward compatibility with Sprint 12 permission patterns).

## Assumptions

- Transfers operate within a single branch (no inter-branch transfer design in Sprint 14).
- `unit_cost` on transfer movements uses `product.average_cost` (no FIFO/LIFO costing).
- Transfer numbers are generated randomly per month prefix; no sequential per-branch counter.
- View-only users (`view_inventory`) can list and view transfers but cannot create, edit, submit,
  complete, or cancel.
- Route model binding parameter naming follows Laravel resource convention (`stock_transfer` for
  show/edit/update; `stockTransfer` for workflow POST routes).

## Release Information

| Field | Value |
|---|---|
| Release Tag | `sprint-14-ui-context-complete` |
| Schema Commit | `72b618a` (Add inventory stock transfer models and schema) |
| Completion Date | 2026-06-06 |
| Sprint Slice | 14.7 — Documentation and Release Completion |
| Status | COMPLETED |

---

# Sprint 15.2 — Transfer Receiving Workflow

## Sprint 15.2 Overview

**Status:** COMPLETED. Completion date 2026-06-06.

**Business objective:** Evolve the Sprint 14 stock transfer workflow from a single-step
completion into a **two-phase ship/receive handoff**. Source staff ship goods (stock leaves the
source location and the document enters transit); destination staff receive goods later (stock
arrives at the destination). This models in-transit inventory and separates ship vs receive audit
actors without introducing mutable stock columns.

**Planning note:** The permanent history records the original transfer implementation as **Sprint
14**. Active milestone planning labeled the ship/receive evolution **Sprint 15.2** (design:
`docs/sprint_15_2_transfer_receiving_design.md`).

**Workflow states (current):** `draft → submitted → in_transit → received`, with `cancelled` as a
terminal off-ramp from `draft` or `submitted` only. Legacy database rows with status `completed`
remain readable; the UI labels them **Diterima** alongside `received` rows.

## Changes from Sprint 14

**Removed (legacy complete workflow):**
- `StockTransferService::completeTransfer()`
- `StockTransferController::complete()`
- `StockTransferPolicy::complete()`
- `CompleteStockTransferRequest`
- Route `inventory.stock-transfers.complete` (`POST .../complete` now returns **404**)

**Added (two-phase workflow):**
- `StockTransferService::shipTransfer()` — `submitted → in_transit`; posts **TRANSFER_OUT only**
- `StockTransferService::receiveTransfer()` — `in_transit → received`; posts **TRANSFER_IN only**
- `StockTransferController::ship()` / `receive()`
- `StockTransferPolicy::ship()` / `receive()`
- `ShipStockTransferRequest` / `ReceiveStockTransferRequest`
- Routes `inventory.stock-transfers.ship` and `inventory.stock-transfers.receive`

**Status model extensions:**
- New constants: `in_transit`, `received`
- `STATUS_COMPLETED` retained **only** for legacy DB rows (not used by new workflow transitions)
- `isInTransit()` and `isReceived()` helpers; `isReceived()` treats both `received` and
  `completed` as terminal
- Ledger panel on show view uses `isInTransit()` and `isReceived()` to display movements

**Guards:**
- Duplicate ship and duplicate receive are rejected
- Cancel blocked after ship or receive (and for legacy `completed` / `received` / `in_transit`)
- Ship requires source location derived stock sufficiency before posting OUT movements

**Column names unchanged:** `completed_at` and `approved_by` still record receive completion;
`shipped_at` and `shipped_by` added for ship audit (migration
`2026_06_06_200001_add_ship_columns_to_trx_stock_transfers_table`).

## Deliverables

**Database (additive):**
- `shipped_at` (nullable timestamp)
- `shipped_by` (nullable FK → `users`)

**Services:**
- `shipTransfer()` — OUT movements at source; sets `in_transit`, `shipped_at`, `shipped_by`
- `receiveTransfer()` — IN movements at destination; sets `received`, `approved_by`,
  `completed_at`
- Unchanged: `createTransfer()`, `updateTransfer()`, `submitTransfer()`, `cancelTransfer()`,
  `getTransferDetails()`

**Requests:**
- `ShipStockTransferRequest`
- `ReceiveStockTransferRequest`
- Removed: `CompleteStockTransferRequest`

**Policy abilities:**
- `ship`, `receive` (require `manage_inventory` + active-branch ownership)
- Removed: `complete`

**Routes (current):**

| Method | URI | Name |
|---|---|---|
| GET | `inventory/stock-transfers` | `inventory.stock-transfers.index` |
| GET | `inventory/stock-transfers/create` | `inventory.stock-transfers.create` |
| POST | `inventory/stock-transfers` | `inventory.stock-transfers.store` |
| GET | `inventory/stock-transfers/{stock_transfer}` | `inventory.stock-transfers.show` |
| GET | `inventory/stock-transfers/{stock_transfer}/edit` | `inventory.stock-transfers.edit` |
| PUT/PATCH | `inventory/stock-transfers/{stock_transfer}` | `inventory.stock-transfers.update` |
| POST | `inventory/stock-transfers/{stockTransfer}/submit` | `inventory.stock-transfers.submit` |
| POST | `inventory/stock-transfers/{stockTransfer}/ship` | `inventory.stock-transfers.ship` |
| POST | `inventory/stock-transfers/{stockTransfer}/receive` | `inventory.stock-transfers.receive` |
| POST | `inventory/stock-transfers/{stockTransfer}/cancel` | `inventory.stock-transfers.cancel` |

**UI (Indonesian operator labels):**
- **Kirim Transfer** (ship) — replaces **Selesaikan Transfer**
- **Terima Transfer** (receive) — new action for `in_transit` transfers
- Status badges include **Dalam Perjalanan** (`in_transit`) and **Diterima** (`received` and
  legacy `completed`)

## Services and Business Rules

`StockTransferService` preserves Sprint 14 layering inside `DB::transaction()` boundaries:

1. **Branch resolution:** `BranchContext::requireId()` — never trust request `branch_id`.
2. **Two-phase ledger flow:** Ship creates **OUT only** at source; receive creates **IN only** at
   destination. Never post both movement types in a single action.
3. **Ledger-derived stock:** Transfer documents store requested quantities only; stock remains
   `SUM(quantity_in) - SUM(quantity_out)` per product+location. **No mutable stock columns.**
4. **Ship:** Allowed only from `submitted`; locks transfer, locations, products, and items;
   validates source sufficiency; posts `TRANSFER_OUT` per item; status → `in_transit`.
5. **Receive:** Allowed only from `in_transit`; posts `TRANSFER_IN` per item at destination;
   status → `received`; sets `approved_by` and `completed_at`.
6. **Cancel:** Allowed from `draft` or `submitted` only; blocked after ship, receive, or for
   terminal statuses.
7. **Legacy compatibility:** Rows with status `completed` display as Diterima; `isReceived()`
   includes `completed`; external callers of `POST .../complete` must migrate to ship + receive.

## Quality Gates

**StockTransfer-scoped verification (recorded at Sprint 15.2 completion):**

| Gate | Result |
|---|---|
| Stock Transfer tests (`php artisan test --filter=StockTransfer`) | PASS — 81 tests, 435 assertions |
| Pint (`vendor/bin/pint`) | PASS |
| Routes (`php artisan route:list --name=stock-transfer`) | PASS — 10 routes; no `complete` route |

> **Release note:** The gates above are **StockTransfer-scoped only**. A full-suite quality gate
> (`php artisan test`, `npm run build`, and full route audit) is still required before the final
> Sprint 15.2 release commit.

## Architecture Notes

- **Layering preserved:** `Controller → Request → Service → Repository → Model`.
- **Ledger-only stock:** Ship and receive append movements to `trx_inventory_movements`; transfer
  tables never store or mutate balances.
- **No mutable stock columns:** Product and location rows are unchanged; derived stock only.
- **Branch isolation:** Transfers remain same-branch, location-aware; `BranchContext` resolves
  branch; policies enforce active-branch ownership.
- **Two-phase transfer flow:** Operational handoff split across ship (OUT) and receive (IN);
  in-transit documents show OUT movements; received documents show both OUT and IN.
- **Atomic per phase:** Each ship or receive runs in a single DB transaction with `lockForUpdate()`
  on transfer, locations, products, and items.
- **Reference linkage:** Movements reference `trx_stock_transfers` via polymorphic
  `reference_type`/`reference_id`.

## Known Decisions

- Sprint 15.2 **replaces** the Sprint 14 single-step `completeTransfer()` workflow; the complete
  route and service method are intentionally removed (404 for legacy integrations).
- `STATUS_COMPLETED` is a read-only legacy status constant; new transitions end at `received`.
- `completed_at` / `approved_by` column names were not renamed in 15.2 (receive metadata); ship
  audit uses `shipped_at` / `shipped_by`.
- Inter-branch transfer remains **out of scope**.

## Assumptions

- External integrations previously calling `POST .../complete` must adopt ship then receive.
- Legacy `completed` rows require no data migration for display; operators see Diterima.
- View-only users (`view_inventory`) can list and view transfers but cannot ship, receive, or
  cancel.

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 15.2 — Transfer Receiving Workflow |
| Status | COMPLETED (StockTransfer-scoped gates verified; full-suite gate pending release commit) |
| Design Doc | `docs/sprint_15_2_transfer_receiving_design.md` |

---

# Architectural Decisions Timeline

1. **S0** — Modular monolith; `Controller → Request → Service → Repository → Model`; central
   `RepositoryServiceProvider`.
2. **S0** — Single PostgreSQL DB; prefix-based table taxonomy (`mst_/trx_/sys_/inv_`).
3. **S0** — Pest + `RefreshDatabase`; prove allowed and denied paths.
4. **S1** — Spatie Permission; policy/gate-first authz; one centralized Super Admin `Gate::before`.
5. **S2** — `is_active` lifecycle over hard delete for master data.
6. **S3** — Status-log + audit-log pattern; **non-enforcing** polymorphic morph map.
7. **S3+** — Transactions around multi-write operations; strip serial `id` on item inserts.
8. **S4** — Named-gate pattern for workflow actions where a model policy already exists.
9. **S6** — Files on disk + path/metadata in DB; never base64 in the database.
10. **S8** — Read-only reporting via a dedicated aggregation repository; no schema growth.
11. **S10** — `BranchContext` as the single server-side branch resolver; never trust request
    `branch_id`.
12. **S11** — Branch-scoped repositories (`int $branchId` first), branch-aware policies failing
    closed; isolation as a security invariant.
13. **S12** — Ledger-derived inventory; no mutable stock columns; location-aware operations.
14. **post-12** — Official UI design system + reusable dashboard component families
    (`owner-dashboard.*`, `branch-dashboard.*`, `inventory.*`).
15. **S13** — Stock Opname keeps physical counts as snapshots and posts stock-changing variance
    adjustments only through finalization into the movement ledger.
16. **S14** — Stock Transfer uses a document workflow (`draft → submitted → completed`) and posts
    paired `TRANSFER_OUT`/`TRANSFER_IN` ledger movements atomically on completion; transfer tables
    never store stock balances.
17. **S15.2** — Stock Transfer evolved to a two-phase ship/receive workflow (`draft → submitted →
    in_transit → received`); ship posts `TRANSFER_OUT` only, receive posts `TRANSFER_IN` only;
    legacy `complete` workflow removed; `STATUS_COMPLETED` retained for legacy rows only.

---

# Domain Rules Timeline

1. **S3** — Lab Order status enum is the canonical lifecycle; transitions validated in services.
2. **S4** — Production assignment/transition rules (start/pause/resume/complete/send-to-QC).
3. **S5** — QC pass/reject drives order status; rejection can create a remake that loops the order.
4. **S6** — Delivery requires POD (receiver, signature, photo) to complete; clinic derived via the
   order.
5. **S7** — Invoice lifecycle DRAFT→ISSUED→(PARTIALLY_PAID)→PAID; OVERDUE/VOID; outstanding
   recomputed on payment; VOID excluded from revenue.
6. **S8.1** — Medical Record Number optional on lab orders, searchable.
7. **S10** — Active branch is resolved centrally; default MAIN branch fallback by code.
8. **S11** — No user may see/mutate/select/infer another branch's records.
9. **S12** — `current stock = SUM(in) − SUM(out)`; adjustment-out requires sufficient
   location-level stock; valuation = derived stock × average_cost; no mutable stock state.
10. **S13** — Stock opname compares counted vs derived stock and posts adjustment ledger movements
    on finalize; draft counts are never a stock source of truth.
11. **S14** — Stock transfer moves quantity between locations within one branch via paired
    `TRANSFER_OUT`/`TRANSFER_IN` movements in one transaction; source location sufficiency checked
    at completion; transfer line quantities are document-only until completion.
12. **S15.2** — Stock transfer ship/receive split: ship (`submitted → in_transit`) posts OUT only
    with source sufficiency check; receive (`in_transit → received`) posts IN only; cancel blocked
    after ship/receive; duplicate ship/receive guarded; legacy `completed` rows display as
    Diterima.

---

# UI Evolution Timeline

1. **S0–S2** — Laravel Breeze baseline: Figtree font, `bg-gray-100`, top nav + permission-aware
   `layouts/sidebar.blade.php`, `components/settings-shell.blade.php` shell, Breeze form
   primitives.
2. **S3–S8** — Operational list/detail/form pages per module: filter card + table + pagination;
   early inline status badges (blue/indigo), `bg-gray-800` filter buttons, `sm:rounded-lg` cards.
3. **S12** — UI maturity step in `inventory/products/index`: **teal-700 primary**, bordered
   `rounded-lg border shadow-sm` cards, **dual desktop-table / mobile-card** responsive lists,
   `<th scope>`, `tabular-nums`, status row-tinting, rich empty states; reusable inventory badge
   partials (`_status-badge`, `_low-stock-badge`).
4. **post-12** — Reusable dashboard component families committed (`owner-dashboard.*`,
   `branch-dashboard.*`, `inventory.*`); a **UI/UX audit** (`docs/ui_ux_audit.md`) and the
   **official UI design system** (`docs/ui_design_system.md`) were produced, standardizing on
   teal, semantic badges, the dual responsive table, and accessibility rules — and flagging the
   older indigo/`sm:rounded-lg`/no-mobile views (e.g. `inventory/stock/index`, `invoices/index`)
   as legacy to converge.
5. **S14** — Stock Transfer UI (`inventory/stock-transfers/*`) follows Sprint 12 inventory
   conventions: teal primary, bordered cards, dual responsive table/mobile layout, Indonesian
   operator labels, permission-gated workflow buttons, ledger movement display on completed
   transfers.
6. **S15.2** — Stock Transfer UI actions renamed for two-phase workflow: **Kirim Transfer** and
   **Terima Transfer** replace **Selesaikan Transfer**; status badges add **Dalam Perjalanan**
   (`in_transit`) and **Diterima** (`received` + legacy `completed`); ledger panel driven by
   `isInTransit()` / `isReceived()`.

---

# Lessons Learned (recurring patterns)

- **Read the real migration/model, not the schema doc.** Implemented tables drifted from early
  docs (e.g. `total_amount` not `grand_total`; Delivery has no `clinic_id`). Ground every change
  in actual code.
- **Super Admin bypass changes test expectations.** Because `Gate::before` grants Super Admin
  everything, "invalid action" tests for a super admin hit the service `ValidationException`, not a
  403 — assert denials with permission-holding non-admin users.
- **PostgreSQL strictness.** Don't pass explicit `NULL` to serial PKs (strip `id` from item
  arrays). Name composite/unique indexes explicitly to stay under the 63-char identifier limit.
- **Carbon 3 / Query Builder gotchas.** `diffInMinutes` returns float (cast to int); `->tap()` is
  unavailable (use `->when(true, ...)`).
- **Environment quirks.** GD is absent — fake uploads with `->create()` not `->image()`. Pint's
  `fully_qualified_strict_types` auto-imports FQCNs — re-run tests after Pint.
- **Additive evolution wins.** Branch and inventory shipped as foundations (opt-in filters, TODO
  markers, nullable columns) so existing behavior and tests stayed green — every sprint extended
  patterns instead of replacing them.
- **Branch-consistency in factories.** Child FKs resolve their branch from the already-expanded
  parent (`fn ($attrs) => Child::factory()->create(['branch_id' => Parent::find($attrs['parent_id'])->branch_id])`).
- **Same toolchain prefix on Windows/XAMPP.** Prepend `$env:Path = "C:\xampp\php;" + $env:Path`
  for PHP/artisan; tinker can't take inline `use` with namespaces via PowerShell — run a script
  file.

---

# Protected Decisions (future sprints MUST NOT violate)

1. **Layering:** No sprint may bypass `Controller → Request → Service → Repository → Model`.
2. **Branch safety:** No sprint may bypass `BranchContext` for branch-owned data; never trust a
   user-submitted `branch_id`; branch-owned queries are always `where('branch_id', $branchId)`.
3. **Ledger inventory:** No sprint may create mutable inventory stock columns or update product
   stock directly; stock is always `SUM(quantity_in) - SUM(quantity_out)`. `current_stock` is a
   query alias only.
4. **Location integrity:** Every stock operation requires `inventory_location_id`; product and
   location branches must match; location filters must not leak other branches.
5. **Authorization:** Policy/gate-first; the single centralized Super Admin `Gate::before` is not
   duplicated; UI gating is not the only protection.
6. **Stack:** No React/Vue/new CSS or permission framework; Blade + Tailwind + Alpine + Spatie only.
7. **Data lifecycle:** Prefer `is_active` deactivation over hard delete for transaction-referenced
   records; no base64 files in the DB.
8. **Schema discipline:** No schema changes unless explicitly in scope; timestamped, prefixed,
   indexed migrations; branch-scoped uniqueness where a business code exists.
9. **Tests:** New features ship with auth, branch-isolation, validation, happy-path, and (for
   inventory) ledger tests, using existing Pest helpers/factories.
10. **UI:** Follow `docs/ui_design_system.md` — teal primary, semantic badges, dual responsive
    tables, empty states, permission-aware actions, no fabricated data.

---

# Future Sprint Constraints

(From `architecture_rules.md` §Future Sprint Protection Rules — bindings for upcoming work.)

- **Sprint 13 completed baseline — Stock Opname:** Future changes must preserve ledger-derived
  stock, branch+location isolation, no mutable stock columns, service-managed finalization, and
  transactional posting. Opname compares counted vs derived stock and posts adjustment ledger
  movements **on finalize**; draft counts are never a stock source of truth.
- **Sprint 14 completed baseline — Stock Transfer (superseded by 15.2 for workflow):** Original
  single-step `complete` workflow was replaced in Sprint 15.2. Future changes must preserve the
  **15.2 ship/receive workflow** (`draft → submitted → in_transit → received`), ship posting
  `TRANSFER_OUT` only and receive posting `TRANSFER_IN` only, per-location source sufficiency
  checks at ship, same-branch-only locations, branch isolation, and no mutable stock columns.
  Never transfer by updating a stock column or by manual adjustment pairs.
- **Sprint 15.2 completed baseline — Transfer Receiving:** Future changes must preserve the
  two-phase ledger flow, duplicate ship/receive guards, cancel blocked after ship/receive, legacy
  `completed` row compatibility via `isReceived()`, and removal of the `complete` route/service.
- **Sprint 15 — Purchasing:** POs express intent and **must not increase stock**; stock rises only
  through a receipt/ledger movement. Validate supplier branch ownership and permission scope.
- **Sprint 16 — Goods Receipt:** May create inventory movements only after validation (active
  branch, active location, product/supplier in branch, qty > 0, documented unit-cost rules,
  transactional ledger write).
- **Sprint 17 — HR Core:** Separate module; not directly coupled to production/payroll/attendance
  except through explicit services/relationships. Branch-owned employees use `branch_id` +
  `BranchContext`.
- **Sprint 18 — Attendance:** Branch- and employee-aware; attendance events are **transactional
  records**, not mutable daily-summary fields; reports may aggregate, raw logs stay source of truth.
- **Sprint 19 — Payroll:** Isolated from HR/attendance via services/repositories; calculations
  auditable and reproducible; must not mutate attendance; reference **locked periods/snapshots** if
  immutability is required.

**Universal gate before any sprint starts:** answer (1) which module owns it, (2) which records are
branch-owned, (3) which policies/permissions protect it, (4) which service owns the rule, (5) which
repository owns the query, (6) which tests prove branch isolation, (7) which UI conventions apply,
(8) what is explicitly out of scope.

---

# AI Agent Memory Summary (read this in under 2 minutes)

**What ADLMS is:** A Laravel **modular monolith** ERP for a multi-branch dental lab — lab order →
production → QC → delivery/POD → invoice/payment → reporting, plus multi-branch and ledger-based
inventory. Pilot/live status: small, scoped, tested changes only.

**Stack:** Laravel + Blade + Tailwind + Alpine + PostgreSQL + Pest + Spatie Permission. **No** new
frameworks.

**Always:** `Controller → Request → Service → Repository → Model`. Validate in Form Requests, rules
in Services, queries in Repositories (interfaces bound in `RepositoryServiceProvider`). Use
`BranchContext::requireId()` for branch-owned data and `where('branch_id', $branchId)` in repos.
Authorize with policies/gates (Super Admin bypass is centralized). Add auth + branch-isolation +
validation + happy-path tests with existing Pest helpers/factories. Follow
`docs/ui_design_system.md` for UI.

**Never:** Trust user-submitted `branch_id`. Query branch-owned data unscoped. Create mutable
inventory stock columns or update stock directly (`stock = SUM(in) − SUM(out)`, always derived;
`current_stock` is a query alias only). Put business logic in controllers or Blade. Leak
cross-branch data in lists/selectors/dashboards/reports. Add roles/permissions or schema casually.
Implement future-sprint features by accident.

**Module map:** `AccessControl, User` (S1) · `Clinic, Doctor, Patient, LabService, Technician` (S2)
· `LabOrder` (S3) · `Production` (S4) · `QualityControl` (S5) · `Delivery` (S6) · `Invoice` (S7,
invoices+payments) · `Reporting` (S8) · `Branch` (S10–11, incl. `BranchContext`) · `Inventory`
(S12–15.2, ledger-derived, location-aware, Stock Opname, Stock Transfer with ship/receive).

**Roles:** Super Admin, Admin Lab, Technician, Quality Control, Delivery Coordinator, Courier,
Finance, Doctor.

**Read before coding:** `docs/architecture_rules.md`, `docs/ai_development_guide.md`, this file,
the target module under `app/Modules`, relevant routes/policies/tests, and (branch work)
`app/Modules/Branch/Services/BranchContext.php`. Inventory invariants and the Future Sprint
Constraints above are binding.

---

*Historical record only — this document changes no application code. It reflects decisions as of
Sprint 15.2 and must be updated as each new sprint completes.*
