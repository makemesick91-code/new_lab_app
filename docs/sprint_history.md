# ADLMS Sprint History

Version: 1.0
Last updated: June 2026
Status: **Permanent project memory.** Captures the major decisions from Sprint 0 through
Sprint 17.7.

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
| 15.3 | Batch & Lot Tracking | (Sprint 15.3 batch/lot implementation) | `inv_inventory_batches`; `inventory_batch_id` on `trx_inventory_movements` and `trx_stock_transfer_items` |
| 15.4 | Reorder Point & Inventory Alerts | (Sprint 15.4 reorder/alerts implementation) | `reorder_point`, `reorder_quantity`, `alert_enabled` on `inv_products` |
| 15.5 | Inventory Analytics | (Sprint 15.5 analytics implementation) | (none — read-only analytics from ledger) |
| 15.6 | Inventory Advanced Hardening & Navigation Closure | (Sprint 15.6 navigation/dashboard hardening) | (none — UI/navigation only) |
| 16.1 | Purchase Request Workflow | (Sprint 16.1 purchase request implementation) | `trx_purchase_requests`, `trx_purchase_request_items` |
| 16.2 | Purchase Order Workflow | (Sprint 16.2 purchase order implementation) | `trx_purchase_orders`, `trx_purchase_order_items` |
| 16.3 | Goods Receipt Workflow | (Sprint 16.3 goods receipt implementation) | `trx_goods_receipts`, `trx_goods_receipt_items`; `quantity_received` on `trx_purchase_order_items` |
| 16.5 | Inventory Permission Hardening | (Sprint 16.5 permission/policy hardening) | (none — permission/policy alignment) |
| 16.6 | Inventory Audit Trail & Activity Log | `sprint-16.6-complete` | `inv_inventory_activity_logs` |
| 16.7 | Inventory Analytics & Executive Dashboard | `sprint-16.7-complete` | (none — read-only analytics from ledger + procurement) |
| 16.8 | Analytics Optimization & Summary Tables | `sprint-16.8-complete` | `rpt_inventory_daily_summaries`, `rpt_inventory_branch_summaries`, `rpt_inventory_product_summaries`, `rpt_procurement_daily_summaries` |

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

# Sprint 15.3 — Batch & Lot Tracking

## Sprint 15.3 Overview

**Status:** COMPLETED. Completion date 2026-06-06.

**Branch:** `feature/sprint-15-inventory-advanced`

**Scope:** Inventory Advanced (Sprint 15 milestone slice)

**Business objective:** Introduce batch/lot identity as a first-class inventory concept for dental
lab material traceability, expiry visibility, and transfer integrity — while preserving the
non-negotiable ADLMS invariant that stock quantity remains **ledger-derived only**
(`SUM(quantity_in) - SUM(quantity_out)`), with no mutable stock columns on products, locations,
batches, or transfer items.

**Planning note:** Design authority: `docs/sprint_15_3_batch_lot_tracking_design.md`. Implemented
in four steps: schema/model layer, `InventoryStockService` integration, `StockTransferService`
batch propagation, and Batch UI with expiry visibility. Step 5 (this record) covers hardening,
documentation, and release readiness.

## Deliverables

**Database (additive):**
- `inv_inventory_batches` — batch/lot identity master (`batch_number`, `lot_number`, `received_date`,
  `expiry_date`, `supplier_id`, `notes`, `is_active`; branch-scoped unique on
  `branch_id + product_id + batch_number + lot_number`)
- `inventory_batch_id` nullable FK on `trx_inventory_movements`
- `inventory_batch_id` nullable FK on `trx_stock_transfer_items`

**Models and factories:**
- `InventoryBatch` model + `InventoryBatchFactory`

**Repository layer:**
- `InventoryBatchRepositoryInterface` + `InventoryBatchRepository` (branch-scoped pagination,
  derived stock aggregates, movements, transfer references)

**Services:**
- `InventoryBatchService` — index/show data, expiry status resolution (`EXPIRING_SOON_DAYS = 30`)
- `InventoryStockService` — receive/opening/adjust with optional batch create/select; batch stock
  queries; expired/inactive batch guards on outbound
- `StockTransferService` — ship/receive propagates `inventory_batch_id` on movements and items

**Authorization:**
- `InventoryBatchPolicy` — `viewAny` / `view` via `view_inventory` + active-branch ownership

**Requests:**
- `InventoryBatchFilterRequest` — index filters (product, supplier, expiry status, search)
- `ValidatesInventoryBatchInput` concern — shared batch validation for stock/transfer requests

**Controllers and routes (read-only batch UI):**

| Method | URI | Name |
|---|---|---|
| GET | `inventory/batches` | `inventory.batches.index` |
| GET | `inventory/batches/{inventoryBatch}` | `inventory.batches.show` |

**Views:**
- `inventory/batches/index` — filtered batch list with derived stock and expiry badges
- `inventory/batches/show` — stock by location, movement history, transfer references
- `inventory/stock/_batch-fields` — receive/adjust batch field partial
- `inventory/batches/_batch-status-badge` — Kedaluwarsa / Mendekati Kedaluwarsa badges

**Batch integration in stock workflows:**
- Receive stock — create new batch or select existing batch; posts `PURCHASE` with
  `inventory_batch_id`
- Adjustment IN — optional batch create/select
- Adjustment OUT — batch selection with batch-level sufficiency check
- Transfer ship — `TRANSFER_OUT` with line `inventory_batch_id`; batch stock validated at source
- Transfer receive — `TRANSFER_IN` with same `inventory_batch_id` (no new batch row)

**Tests:**
- `InventoryBatchTest`, `InventoryBatchModelTest`, `StockTransferBatchTest` (+ batch cases in
  `InventoryStockServiceTest`, `InventoryUiTest`)

## Ledger Notes

- **Batch stores identity metadata only** — `inv_inventory_batches` has no `current_stock`,
  `quantity_on_hand`, or any mutable balance column.
- **Quantity remains on `trx_inventory_movements`** — append-only ledger unchanged from Sprint 12.
- **Batch stock derivation:**

```text
batch_stock(batch, location) =
  SUM(quantity_in) - SUM(quantity_out)
  WHERE branch_id = active_branch
    AND inventory_batch_id = batch
    AND inventory_location_id = location
```

- **Product stock** remains the aggregate across all batches (and NULL-batch movements) at a
  location.
- **No mutable stock columns** anywhere in the batch slice.

## Workflow Notes

- Receive stock can **create** a new batch (batch_number, lot_number, received_date, expiry_date)
  or **select** an existing batch via `inventory_batch_id`.
- Adjustment IN/OUT can reference a batch when batch context is supplied on the form.
- Transfer ship creates `TRANSFER_OUT` with the line's `inventory_batch_id`.
- Transfer receive creates `TRANSFER_IN` with the **same** `inventory_batch_id`.
- Inactive batches (`is_active = false`) are rejected for new movements.
- Expired batches are **hard-blocked** on outbound movements (adjust out, transfer ship).
- Movements with `inventory_batch_id = NULL` behave exactly as pre-15.3 (backward compatible).

## UI Notes

- **Batch & Lot** menu under **Persediaan** in sidebar — permission-gated via
  `@can('viewAny', InventoryBatch::class)`; no unauthorized link exposure.
- Batch index with filters: product, supplier, expiry status (expired / expiring soon / valid),
  search by batch/lot number.
- Batch show: derived total stock, stock by location, movement ledger rows, transfer line
  references.
- Expired and expiring-soon warnings via `_batch-status-badge` (threshold: 30 days).
- Indonesian operator labels: **Batch & Lot**, **Nomor Batch**, **Nomor Lot**, **Tanggal Terima**,
  **Tanggal Kedaluwarsa**, **Kedaluwarsa**, **Mendekati Kedaluwarsa**.

## Quality Gates

**Full-suite verification (recorded at Sprint 15.3 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 574 tests, 1787 assertions |
| Batch-filtered tests (`php artisan test --filter=Batch`) | PASS — 57 tests, 211 assertions |
| Pint (`vendor/bin/pint`) | PASS |
| Routes (`php artisan route:list`) | PASS — 192 routes total; 2 batch routes (`inventory.batches.index`, `inventory.batches.show`) |
| Migration (`php artisan migrate:fresh --seed`) | PASS — all migrations including 15.3 batch tables |
| Frontend build (`npm run build`) | PASS |

## Architecture Notes

- **Layering preserved:** `Controller → Request → Service → Repository → Model`.
- **Ledger-only stock:** Batch quantity is always computed from `trx_inventory_movements`; batch
  master table holds descriptive attributes only.
- **Branch isolation:** `BranchContext::requireId()` in services; `findForBranch()` in repository;
  policy denies cross-branch show; index scoped to active branch.
- **Sprint 15.2 compatibility:** Ship/receive workflow unchanged; batch FK added to movement and
  transfer item rows only.
- **Read-only batch UI:** No create/edit/delete routes on batch master — batches are created
  implicitly through receive/adjust workflows.

## Known Risks

- **Mixed NULL-batch and batch movements** — product aggregate stock may exceed the sum of batch
  stocks until historical data is migrated or products stay on optional batch entry.
- **Expiring-soon threshold fixed at 30 days** — not configurable per product or branch in 15.3.
- **`requires_batch_tracking` enforcement deferred** — product-level opt-in flag from the design
  doc was not migrated; batch fields remain optional for all products until a follow-up slice
  adds the column and conditional validation.
- **Large product×location batch option matrix** — transfer create/edit builds a batch options
  matrix that may need pagination or lazy-load optimization at scale.

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 15.3 — Batch & Lot Tracking |
| Branch | `feature/sprint-15-inventory-advanced` |
| Status | COMPLETED (full-suite gates verified) |
| Design Doc | `docs/sprint_15_3_batch_lot_tracking_design.md` |

---

# Sprint 15.4 — Reorder Point & Inventory Alerts

## Sprint 15.4 Overview

**Status:** COMPLETED. Completion date 2026-06-06.

**Branch:** `feature/sprint-15-inventory-advanced`

**Scope:** Inventory Advanced (Sprint 15 milestone slice)

**Business objective:** Add product-level reorder configuration and a unified, read-only inventory
alert engine that surfaces out-of-stock, critical, low-stock, and batch expiry conditions — while
preserving ledger-derived stock (`SUM(quantity_in) - SUM(quantity_out)`) with no mutable stock
columns.

**Planning note:** Design authority: `docs/sprint_15_4_reorder_alerts_design.md`. Implemented in
four steps: schema/product fields, `InventoryAlertService`, alerts index page and dashboard widgets,
and stock-value-card alignment with alert summary counts.

## Deliverables

**Database (additive):**
- `reorder_point` (nullable `decimal(12,2)`) on `inv_products`
- `reorder_quantity` (nullable `decimal(12,2)`) on `inv_products`
- `alert_enabled` (`boolean`, default `true`) on `inv_products`
- Migration: `2026_06_06_220000_add_reorder_fields_to_inv_products_table`

**Models and factories:**
- `Product` model — fillable/casts for reorder fields
- `ProductFactory` — reorder field defaults for tests

**Service layer:**
- `InventoryAlertService` — stock severity classification, batch expiry alerts, unified alert list,
  `getAlertSummary()` for KPI counts
- Repository extensions: `productsWithDerivedStock()`, `batchesWithDerivedStockForAlerts()`

**Controllers and routes:**

| Method | URI | Name |
|---|---|---|
| GET | `inventory/alerts` | `inventory.alerts.index` |

**Views and components:**
- `inventory/alerts/index` — unified alert dashboard with filters, KPI strip, pagination
- `inventory/alerts/_stock-severity-badge` — Habis / Kritis / Menipis badges
- `x-inventory.alert-summary-widget` — dashboard alert summary card
- `x-inventory.stock-alert-widget` — top stock alerts on dashboard
- `x-inventory.batch-alert-widget` — top batch expiry alerts on dashboard
- `x-inventory.stock-value-card` — inventory value + five alert KPI counts (aligned with
  `InventoryAlertService`)
- Product form/show — reorder fields and **Pengaturan Peringatan & Pesanan Ulang** panel
- Sidebar **Peringatan Stok** link under Persediaan

**Requests:**
- `StoreProductRequest` / `UpdateProductRequest` — reorder field validation
- `InventoryAlertFilterRequest` — location, severity, and type filters

**Tests:**
- `InventoryAlertTest` — stock/batch severity, branch isolation, authorization, UI index
- `InventoryDashboardTest` — dashboard alert summary alignment with `getAlertSummary()`
- Extensions in `InventoryUiTest`, `ProductTest`

## Alert Rules

Stock alerts (branch-scoped; optional `inventory_location_id` filter):

| Severity | Code | Condition |
|---|---|---|
| Out of stock | `out_of_stock` | `current_stock <= 0` |
| Critical stock | `critical` | `current_stock > 0` AND `current_stock <= minimum_stock` (when `minimum_stock > 0`) |
| Low stock | `low` | `current_stock <= effective_reorder_point` (when effective reorder point `> 0` and not already critical/OOS) |

Effective reorder point:

```text
effective_reorder_point(product) =
  COALESCE(NULLIF(reorder_point, 0), minimum_stock)
```

Batch expiry alerts (derived batch stock `> 0` required):

| Severity | Code | Condition |
|---|---|---|
| Expired batch | `batch_expired` | `expiry_date < today` AND derived batch stock `> 0` |
| Expiring soon | `batch_expiring_soon` | `expiry_date` within 30 days (inclusive) AND derived batch stock `> 0` |

Additional rules:
- `alert_enabled = false` excludes product from stock alerts.
- Inactive products and batches excluded.
- Batches without `expiry_date` excluded from expiry alerts.
- Alerts are computed on read — no `trx_inventory_alerts` persistence table.

## Ledger Rules

- Stock remains **ledger-derived only:**

```text
current_stock = SUM(quantity_in) - SUM(quantity_out)
```

- **No mutable stock columns** on products, locations, or batches.
- Batch stock remains derived from movements filtered by `inventory_batch_id`.
- `reorder_quantity` is informational only — no automatic PO or ledger write in 15.4.

## UI Notes

- **Peringatan Stok** sidebar menu — permission-gated via `@can('viewAny', InventoryMovement::class)`.
- Indonesian operator labels on dashboard and alerts index:
  - **Stok Habis** — out-of-stock count
  - **Stok Kritis** — critical count
  - **Stok Rendah** — low-stock count
  - **Batch Kedaluwarsa** — expired batch count
  - **Segera Kedaluwarsa** — expiring-soon batch count
  - **Rekomendasi Reorder** — reorder quantity hint on alert rows
  - **Jumlah Pesan Ulang** — product form field for `reorder_quantity`
- `stock-value-card` displays inventory value plus the five alert KPI counts sourced from
  `InventoryAlertService::getAlertSummary()`.

## Quality Gates

**Full-suite verification (recorded at Sprint 15.4 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 607 tests, 1902 assertions |
| Alert-filtered tests (`php artisan test --filter=InventoryAlert`) | PASS — 17 tests, 45 assertions |
| Pint (`vendor/bin/pint`) | PASS |
| Routes (`php artisan route:list`) | PASS — 193 routes total; 1 alert route (`inventory.alerts.index`) |
| Migration (`php artisan migrate:fresh --seed`) | PASS — all migrations including 15.4 reorder fields on `inv_products` |
| Frontend build (`npm run build`) | PASS — Vite production build (`app-TtdU21Qi.css`, `app-CoaHkm5D.js`) |

## Architecture Notes

- **Layering preserved:** `InventoryAlertController` → `InventoryAlertFilterRequest` →
  `InventoryAlertService` → movement/batch repositories.
- **Branch isolation:** `BranchContext::requireId()`; location filter validated via
  `findInBranch()`; cross-branch products/batches never appear in alert lists.
- **Sprint 15.3 compatibility:** Batch expiry alerts reuse `InventoryBatchService::EXPIRING_SOON_DAYS`
  and derived batch stock; outbound expired-batch guards unchanged.
- **Legacy coexistence:** `lowStockProducts()` repository method retained for the dashboard
  low-stock widget; alert KPIs use `InventoryAlertService` as source of truth.

## Known Risks

- **No notification channel yet** — operators must open dashboard/alerts page; email/SMS/push deferred.
- **Owner cross-branch rollup deferred** — owner dashboard alert integration remains out of scope.
- **KPI duplication on dashboard** — KPI strip, `stock-value-card`, and `alert-summary-widget` may
  need a UX consolidation pass.
- **`lowStockProducts()` still exists** — legacy low-stock widget uses older threshold logic;
  gradual migration to `InventoryAlertService` recommended.

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 15.4 — Reorder Point & Inventory Alerts |
| Branch | `feature/sprint-15-inventory-advanced` |
| Status | COMPLETED (full-suite gates verified) |
| Design Doc | `docs/sprint_15_4_reorder_alerts_design.md` |

---

# Sprint 15.5 — Inventory Analytics

## Sprint 15.5 Overview

**Status:** COMPLETED. Completion date 2026-06-06.

**Branch:** `feature/sprint-15-inventory-advanced`

**Scope:** Inventory Advanced (Sprint 15 milestone slice)

**Business objective:** Add read-only inventory analytics computed from the movement ledger —
fast/slow/dead stock, aging, turnover, value by category/location, and monthly outbound value
trend — with branch-safe queries, Indonesian operator UI, and no mutable stock columns.

**Planning note:** Design authority: `docs/sprint_15_5_inventory_analytics_design.md`. Implemented
in three phases: 15.5.1 repository/service analytics, 15.5.2 controller/route/sidebar scaffold,
15.5.3 full analytics UI, tests, documentation, and release completion.

## Deliverables

**Service layer:**
- `InventoryAnalyticsService` — fast/slow/dead stock, product/batch aging, turnover, value
  breakdowns, monthly outbound trend, analytics summary KPIs
- Repository extensions on `InventoryMovementRepository` and `InventoryBatchRepository` for
  analytics aggregate queries

**Controllers and routes:**

| Method | URI | Name |
|---|---|---|
| GET | `inventory/analytics` | `inventory.analytics.index` |

**Requests:**
- `InventoryAnalyticsFilterRequest` — date range, location, category, dead-stock days,
  slow-moving threshold, limit, aging granularity; branch-validated location/category

**Views:**
- `inventory/analytics/index` — full responsive analytics page (filters, KPI strip, all sections,
  desktop tables, mobile cards, empty states, disclaimers)
- `inventory/analytics/_age-bucket-badge`, `_empty-state` partials
- Dashboard header link to analytics (permission-gated)
- Sidebar **Analitik Persediaan** under Persediaan

**Analytics domains:**
- Produk Cepat Bergerak / Produk Lambat Bergerak / Stok Mati
- Umur Persediaan (product or batch granularity)
- Perputaran Persediaan
- Nilai per Kategori / Nilai per Lokasi
- Tren Nilai Keluar (monthly outbound value)

**Tests:**
- `InventoryAnalyticsServiceTest` — calculation and branch isolation
- `InventoryAnalyticsControllerTest` — auth, validation, UI labels, disclaimers, empty states
- `InventoryUiTest` — analytics page responsive sections

## Ledger Rules

- All stock/value analytics derive from `trx_inventory_movements` only.
- **No mutable stock columns** on products, locations, or batches.
- `NULL` `inventory_batch_id` movements remain valid; product-level aging uses last-inbound proxy.
- Valuation uses `average_cost` at report time (operational, not accounting-grade COGS).

## UI Notes

- Indonesian operator labels throughout.
- Responsive layout: desktop tables (`hidden md:block`) + mobile stacked cards (`md:hidden`).
- Shared filter bar with apply/reset.
- Empty states: *Belum ada data analitik untuk filter ini.*
- Disclaimers: ledger-derived stock, outbound trend ≠ on-hand value history, approximate product aging.

## Known Risks

- **True historical on-hand value trend deferred** — monthly chart shows outbound value, not stock value snapshots.
- **Large ledgers may need snapshot table or indexes later** — on-the-fly aggregation acceptable for pilot volume.
- **Product aging without batch is approximate** — `last_in_date` FIFO proxy, not lot-level FIFO.
- **Owner cross-branch analytics deferred** — same as Sprint 15.4.

## Quality Gates

**Full-suite verification (recorded at Sprint 15.5 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 642 tests, 2039 assertions |
| Analytics tests (`php artisan test --filter=InventoryAnalytics`) | PASS — 34 tests, 131 assertions |
| Alert tests (`php artisan test --filter=InventoryAlert`) | PASS — 17 tests, 45 assertions |
| Pint (`vendor/bin/pint`) | PASS |
| Routes (`php artisan route:list`) | PASS — 194 routes total; 1 analytics route (`inventory.analytics.index`) |
| Migration (`php artisan migrate:fresh --seed`) | NOT RUN — blocked by destructive-reset safety gate (requires explicit operator approval) |
| Frontend build (`npm run build`) | PASS — Vite production build (`app-Bl1yIQXu.css`, `app-CoaHkm5D.js`) |

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 15.5 — Inventory Analytics |
| Branch | `feature/sprint-15-inventory-advanced` |
| Status | COMPLETED |
| Design Doc | `docs/sprint_15_5_inventory_analytics_design.md` |

---

# Sprint 15.6 — Inventory Advanced Hardening & Navigation Closure

## Sprint 15.6 Overview

**Status:** COMPLETED. Completion date 2026-06-06.

**Branch:** `feature/sprint-15-inventory-advanced`

**Scope:** Inventory Advanced (Sprint 15 milestone closure slice)

**Business objective:** Close navigation and dashboard UX gaps across the Sprint 15 inventory
advanced feature set — improve operator discoverability of Stock Opname, remove duplicate alert KPIs
on the inventory dashboard, add permission-gated quick actions, and remove sidebar placeholder dead
links — without changing ledger rules, branch isolation, or authorization contracts.

**Planning note:** Implemented in four steps: 15.6.1 Stok Opname sidebar discovery, 15.6.2 dashboard
KPI deduplication, 15.6.3 dashboard quick actions, 15.6.4 sidebar dead-link removal. Step 15.6.5
covers documentation and memory sync.

## Deliverables

**Navigation (sidebar):**
- **Stok Opname** link added under Persediaan — permission-gated; operators can discover the
  Sprint 13 workflow from the sidebar.
- Dead placeholder links removed from the Persediaan section (no links to unbuilt routes).

**Inventory dashboard:**
- Duplicate alert KPI strip removed; **`InventoryAlertService` confirmed as the canonical source**
  for alert counts on the dashboard (`stock-value-card` and related widgets).
- **Quick actions panel** added — permission-gated shortcuts to common inventory workflows
  (e.g. receive stock, transfer, opname, alerts) without menu sprawl.

**Preserved invariants:**
- Inventory **ledger-only** rule unchanged — no mutable stock columns; stock remains
  `SUM(quantity_in) - SUM(quantity_out)`.
- **Branch isolation** and **permission gates** preserved on all dashboard links and quick actions.

## UI Notes

- Stok Opname discoverable from sidebar alongside existing Persediaan links (Transfer Stok, Batch
  & Lot, Peringatan Stok, Analitik Persediaan).
- Dashboard no longer shows redundant KPI counts that duplicated `stock-value-card` alert summary.
- Quick actions respect `@can` / policy gates — view-only users see no unauthorized actions.
- Sidebar contains only routes to implemented, permission-gated inventory features.

## Quality Gates

**Full-suite verification (recorded at Sprint 15.6 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 644 tests, 2061 assertions |
| Pint (`vendor/bin/pint`) | PASS |
| Frontend build (`npm run build`) | PASS |

## Architecture Notes

- **No schema changes** — navigation and dashboard UX only.
- **Layering unchanged** — no business logic moved into Blade; quick actions link to existing
  routes/controllers.
- **`InventoryAlertService::getAlertSummary()`** remains the single source of truth for dashboard
  alert KPI counts (resolves Sprint 15.4 known risk of KPI duplication).
- **Branch isolation:** Dashboard and sidebar remain active-branch scoped via existing controller
  and policy patterns; no cross-branch data exposure introduced.

## Known Decisions

- Sprint 15.6 **does not** add Purchase Request, Purchase Order, or Goods Receipt workflows — those
  remain Sprint 16 candidates per roadmap.
- Notification channels for alerts remain deferred (unchanged from Sprint 15.4).
- Owner cross-branch dashboard rollup remains deferred.

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 15.6 — Inventory Advanced Hardening & Navigation Closure |
| Branch | `feature/sprint-15-inventory-advanced` |
| Status | COMPLETED (full-suite gates verified) |

---

# Sprint 16.1 — Purchase Request Workflow

## Sprint 16.1 Overview

**Business objective:** Introduce a branch-scoped Purchase Request (PR) workflow so operators can
document material purchase needs and route them through draft → submitted → approved/rejected
approval — **without** creating inventory movements or mutating ledger-derived stock.

**Out of scope:** Purchase Order, Goods Receipt, supplier delivery, `PURCHASE` inventory movements,
stock updates, HR module.

## Deliverables

**Schema:**
- `trx_purchase_requests` — header with `purchase_request_number`, `request_date`, workflow
  statuses (`draft`, `submitted`, `approved`, `rejected`, `cancelled`), approval/rejection audit
  fields.
- `trx_purchase_request_items` — line items with `product_id`, optional `inventory_location_id`,
  `quantity_requested`, optional `estimated_unit_price`.

**Application layer:**
- `PurchaseRequest` / `PurchaseRequestItem` models with status constants and relations.
- `PurchaseRequestRepository` + interface; `PurchaseRequestService` owns workflow transitions.
- `PurchaseRequestPolicy` with `viewAny`, `view`, `create`, `update`, `submit`, `approve`,
  `reject`, `cancel`; branch isolation via `ChecksInventoryAccess`.
- Permission `approve_inventory_purchase_request` (Admin Lab); `manage_inventory` retains full
  manage + approve path.
- Form requests: `StorePurchaseRequestRequest`, `UpdatePurchaseRequestRequest`,
  `RejectPurchaseRequestRequest`.
- `PurchaseRequestController` + routes `inventory.purchase-requests.*`.
- Blade UI under `resources/views/inventory/purchase-requests/` (Indonesian labels).
- Sidebar link **Permintaan Pembelian**; dashboard quick action **Buat Permintaan Pembelian**.
- Reorder alerts shortcut **Buat PR** (query-param prefill only; no auto-create).

**PR number format:** `PR-{YYYYMMDD}-{branch_id}-{sequence}` (branch-scoped sequential).

## Preserved Invariants

- **No inventory movements** created on PR create/submit/approve/reject/cancel.
- **Ledger-only stock** — no mutable stock columns added.
- **Branch isolation** — `BranchContext::requireId()`; policies deny cross-branch access.
- **Authorization** — view via `view_inventory`; mutate via `manage_inventory`; approve via
  `approve_inventory_purchase_request` or `manage_inventory`.

## Quality Gates

Run at Sprint 16.1 completion: `php artisan migrate:fresh --seed`, `php artisan test`,
`vendor/bin/pint`, `npm run build`, `php artisan route:list`.

## Known Decisions

- Purchase Request expresses intent only; stock increases remain a future Goods Receipt / ledger
  movement concern (Sprint 16+).
- Alerts shortcut pre-fills create form via query params; user must submit form to persist PR.

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 16.1 — Purchase Request Workflow |
| Status | COMPLETED |

---

# Sprint 16.2 — Purchase Order Workflow

## Sprint 16.2 Overview

**Status:** COMPLETED. Completion date 2026-06-06.

**Business objective:** Introduce a branch-scoped Purchase Order (PO) workflow so operators can
document supplier purchase commitments and route them through draft → submitted → approved → sent
(or cancelled) — **without** creating inventory movements, updating ledger-derived stock, or
implementing Goods Receipt.

**Out of scope:** Goods Receipt / receiving (Sprint 16.3), `PURCHASE` inventory movements, stock
updates, supplier invoice/payment, HR module.

## Document-Only Workflow

Purchase Order is implemented as a **document-only workflow**:

- PO does **not** update stock.
- PO does **not** create `trx_inventory_movements`.
- Inventory `PURCHASE` movement type is deferred until Goods Receipt / receiving sprint.
- Goods Receipt is deferred to **Sprint 16.3**.

## Deliverables

**Schema:**
- `trx_purchase_orders` — header with `purchase_order_number`, `order_date`, workflow statuses,
  `supplier_id`, `supplier_snapshot_name`, `supplier_reference_number`, `currency` (default `IDR`),
  optional `purchase_request_id`, expected delivery date, audit fields (`submitted_by/at`,
  `approved_by/at`, `sent_by/at`, `created_by`).
- `trx_purchase_order_items` — line items with `product_id`, optional `inventory_location_id`,
  `quantity_ordered`, `unit_price`.

**Header total intentionally NOT stored:** `total_amount` is **not** persisted on
`trx_purchase_orders`. Total is computed via `PurchaseOrder::totalAmount()` and the
`total_amount` accessor from line items.

**Models and factories:**
- `PurchaseOrder` / `PurchaseOrderItem` models with status constants and relations.
- `PurchaseOrderFactory` / `PurchaseOrderItemFactory` (manual PO supported via
  `purchase_request_id = null`).

**Repository and provider binding:**
- `PurchaseOrderRepository` + `PurchaseOrderRepositoryInterface`; wired in
  `RepositoryServiceProvider`.

**Service:**
- `PurchaseOrderService` owns workflow transitions inside `DB::transaction()` boundaries.

**Policy and permissions:**
- `PurchaseOrderPolicy` with `viewAny`, `view`, `create`, `update`, `submit`, `approve`, `send`,
  `cancel`; branch isolation via `ChecksInventoryAccess`.
- `view_inventory` — view PO list/detail.
- `manage_inventory` — create, update, submit, send, cancel (and approve via fallback).
- `approve_inventory_purchase_order` — approve path; also accepted via `manage_inventory` or
  legacy `manage master data` (PR approval fallback pattern preserved).

**Form requests:**
- `StorePurchaseOrderRequest`, `UpdatePurchaseOrderRequest`
- `ValidatesPurchaseOrderInput` (shared concern)

**Controller and routes:**

| Method | URI | Name |
|---|---|---|
| GET | `inventory/purchase-orders` | `inventory.purchase-orders.index` |
| GET | `inventory/purchase-orders/create` | `inventory.purchase-orders.create` |
| POST | `inventory/purchase-orders` | `inventory.purchase-orders.store` |
| GET | `inventory/purchase-orders/{purchase_order}` | `inventory.purchase-orders.show` |
| GET | `inventory/purchase-orders/{purchase_order}/edit` | `inventory.purchase-orders.edit` |
| PUT/PATCH | `inventory/purchase-orders/{purchase_order}` | `inventory.purchase-orders.update` |
| POST | `inventory/purchase-orders/{purchaseOrder}/submit` | `inventory.purchase-orders.submit` |
| POST | `inventory/purchase-orders/{purchaseOrder}/approve` | `inventory.purchase-orders.approve` |
| POST | `inventory/purchase-orders/{purchaseOrder}/send` | `inventory.purchase-orders.send` |
| POST | `inventory/purchase-orders/{purchaseOrder}/cancel` | `inventory.purchase-orders.cancel` |

**Blade UI:**
- Views under `resources/views/inventory/purchase-orders/` (index, create, edit, show, `_form`,
  `_status-badge`).
- Sidebar link **Pesanan Pembelian** (permission-gated).
- Dashboard quick action **Buat Pesanan Pembelian**.
- **Buat PO** button on approved Purchase Request show page (PR → PO integration).
- **No** Goods Receipt / Terima Barang / Update Stok UI.

## Workflow Statuses

**Implemented:**
- `draft` — editable; supplier snapshot refreshed if supplier changes during draft edit.
- `submitted` — awaiting approval.
- `approved` — approved, ready to send.
- `sent` — terminal; PO communicated to supplier (document state only).
- `cancelled` — terminal off-ramp.

**Future statuses NOT implemented (deferred to receiving sprint):**
- `partially_received`
- `fully_received`
- `closed`

## PR Integration Rules

**Manual PO:** `purchase_request_id = null` — operator creates PO directly without a linked PR.

**PR-linked PO:**
- Created from **approved** Purchase Request only.
- Duplicate active PO for the same PR is **blocked** (one active PO per approved PR).
- Cancelled PO allows a new PO to be created for the same PR.

## Supplier Snapshot and Currency

- **Supplier snapshot:** `supplier_snapshot_name` captured at PO creation; refreshed if supplier
  changes during draft edit (`displaySupplierName()` prefers snapshot).
- **Currency:** defaults to `IDR`; no exchange rate or multi-currency accounting in 16.2.
- **Supplier reference:** optional `supplier_reference_number` on header.

**PO number format:** `PO-{YYYYMMDD}-{branch_id}-{sequence}` (branch-scoped sequential).

## Preserved Invariants

- **No inventory movements** on PO create/submit/approve/send/cancel.
- **Ledger-only stock** — no mutable stock columns added.
- **Branch isolation** — `BranchContext::requireId()`; policies deny cross-branch access.
- **HR module untouched.**

## Quality Gates

**Full-suite verification (recorded at Sprint 16.2 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 828 tests |
| PurchaseOrder-filtered tests (`php artisan test --filter=PurchaseOrder`) | PASS — 139 tests |
| Pint (`vendor/bin/pint`) | PASS |
| Frontend build (`npm run build`) | PASS |

## Architecture Notes

- **Layering preserved:** `Controller → Request → Service → Repository → Model`.
- **Document-only PO:** PO tables store workflow identity and line quantities/prices only; stock
  remains `SUM(quantity_in) - SUM(quantity_out)` from `trx_inventory_movements`.
- **Branch isolation:** Every PO carries `branch_id` from `BranchContext`; repository methods
  scope by branch; policies enforce active-branch ownership.
- **Sprint 16.1 compatibility:** PR → PO integration preserves PR as intent-only; approved PR is
  the gate for linked PO creation.

## Known Decisions

- PO expresses supplier commitment only; stock increases remain a **Goods Receipt** concern
  (Sprint 16.3).
- Receiving statuses (`partially_received`, `fully_received`, `closed`) are reserved for the
  receiving sprint — not present in 16.2 schema or workflow.
- `total_amount` is computed, not stored — line totals are the source of truth for PO value.
- Approval permission aligns with PR pattern: dedicated `approve_inventory_purchase_order` plus
  `manage_inventory` and legacy `manage master data` fallback.

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 16.2 — Purchase Order Workflow |
| Status | COMPLETED (full-suite gates verified) |

---

# Sprint 16.3 — Goods Receipt Workflow

## Sprint 16.3 Overview

**Status:** COMPLETED. Completion date 2026-06-06.

**Business objective:** Introduce a branch-scoped Goods Receipt (GR) workflow that closes the
procurement chain:

```text
Purchase Request → Purchase Order → Goods Receipt → PURCHASE Inventory Movement
```

Sprint 16.3 is the **first procurement sprint that writes to the inventory ledger**. Posting a GR
creates `trx_inventory_movements` rows with `movement_type = PURCHASE` inside `DB::transaction()`
boundaries. Stock remains ledger-derived; no mutable stock columns are added.

**Out of scope:** Supplier invoice/payment, GR reversal/void, HR module, advanced costing, batch
fields on GR UI (standalone Terima Stok batch path preserved).

## Deliverables

**Schema:**
- `trx_goods_receipts` — header with `receipt_number`, `receipt_date`, workflow statuses
  (`draft`, `submitted`, `posted`, `cancelled`), required `purchase_order_id`, optional delivery/
  invoice reference fields, audit timestamps and user FKs.
- `trx_goods_receipt_items` — lines with `accepted_qty`, `rejected_qty`, `received_qty`
  (accepted + rejected), context snapshots (`ordered_qty`, `previously_received_qty`), cost
  snapshots (`unit_cost`, `line_total`), required `inventory_location_id`, optional
  `inventory_movement_id` (1:1 link after post).
- `trx_purchase_order_items.quantity_received` — derived cache of cumulative **accepted** qty
  across posted GRs (default `0`).

**Models and factories:**
- `GoodsReceipt` / `GoodsReceiptItem` with status constants, relations, and workflow helpers.
- Extended `PurchaseOrder` (receiving statuses `partially_received`, `fully_received`, `goodsReceipts()`
  relation) and `PurchaseOrderItem` (`quantity_received`, `quantityRemaining()` accessor).

**Repository and provider binding:**
- `GoodsReceiptRepository` + `GoodsReceiptRepositoryInterface`; wired in `RepositoryServiceProvider`.
- Extended `PurchaseOrderRepository` — `incrementItemQuantityReceived()` callable only from
  `GoodsReceiptService::post()`.

**Service:**
- `GoodsReceiptService` owns draft create/update, submit, post (ledger write), and cancel inside
  `DB::transaction()` with row locks and posting guard/idempotency.
- Extended `InventoryStockService::receiveStock()` with optional `reference_type` / `reference_id`.

**Policy and permissions:**
- `GoodsReceiptPolicy` with `viewAny`, `view`, `create`, `update`, `submit`, `post`, `cancel`.
- Extended `PurchaseOrderPolicy::receive` for **Terima Barang** CTA.
- `view_inventory` — view GR list/detail.
- `manage_inventory` — create, update, submit, post, cancel (no separate approve permission).

**Form requests:**
- `StoreGoodsReceiptRequest`, `UpdateGoodsReceiptRequest`, `PostGoodsReceiptRequest`,
  `SubmitGoodsReceiptRequest`, `CancelGoodsReceiptRequest`
- `ValidatesGoodsReceiptInput` (shared concern) — excludes `quantity_received`, `unit_cost`,
  `line_total`, `inventory_movement_id` from user input.

**Controller and routes:**

| Method | URI | Name |
|---|---|---|
| GET | `inventory/goods-receipts` | `inventory.goods-receipts.index` |
| GET | `inventory/goods-receipts/create` | `inventory.goods-receipts.create` |
| POST | `inventory/goods-receipts` | `inventory.goods-receipts.store` |
| GET | `inventory/goods-receipts/{goods_receipt}` | `inventory.goods-receipts.show` |
| GET | `inventory/goods-receipts/{goods_receipt}/edit` | `inventory.goods-receipts.edit` |
| PUT/PATCH | `inventory/goods-receipts/{goods_receipt}` | `inventory.goods-receipts.update` |
| POST | `inventory/goods-receipts/{goodsReceipt}/submit` | `inventory.goods-receipts.submit` |
| POST | `inventory/goods-receipts/{goodsReceipt}/post` | `inventory.goods-receipts.post` |
| POST | `inventory/goods-receipts/{goodsReceipt}/cancel` | `inventory.goods-receipts.cancel` |

**Blade UI:**
- Views under `resources/views/inventory/goods-receipts/` (index, create, edit, show, `_form`,
  `_status-badge`).
- Sidebar link **Penerimaan Barang** (permission-gated).
- PO show **Terima Barang** button when PO is receivable and has remaining qty.

## Workflow Statuses

**Goods Receipt:**
- `draft` — editable; no stock impact; can submit, post directly, or cancel.
- `submitted` — review checkpoint; no stock impact; can post; not editable or cancellable.
- `posted` — terminal; creates PURCHASE movements; immutable.
- `cancelled` — terminal; draft-only; no ledger writes.

**Purchase Order receiving (new in 16.3):**
- `partially_received` — at least one posted GR; some lines still open.
- `fully_received` — all lines have `quantity_received >= quantity_ordered`; blocks new GR.

PO eligibility for new GR: `approved`, `sent`, `partially_received`.

## Ledger Decision

- GR post creates one `PURCHASE` movement per line with `accepted_qty > 0`.
- Movement `reference_type = trx_goods_receipts`, `reference_id = goods_receipt.id`.
- Line FK `inventory_movement_id` for 1:1 traceability.
- `rejected_qty` recorded on GR line only — does not enter stock or PO cache.
- `quantity_received` on PO items updated **only** by `GoodsReceiptService::post()` using
  `accepted_qty` only — service-owned derived cache, not user-editable.
- Cost snapshots (`unit_cost`, `line_total`) persisted at post time from PO item `unit_price`.
- No mutable stock columns added (`current_stock`, `qty_on_hand`, etc.).

## Branch Enforcement

- `BranchContext::requireId()` on all service writes; never trust request `branch_id`.
- Repository `findInBranch` / `paginateForBranch` scoping.
- Policies enforce `belongsToActiveBranch()` on view/mutate.
- Cross-branch PO, GR, and inventory location linkage rejected in service and request layers.
- Posting transaction locks GR + PO + PO items with `lockForUpdate()`.

## Preserved Invariants

- **Ledger-only stock** — no mutable stock columns.
- **PO document workflow unchanged** except receiving status progression and cache column.
- **PR intent-only** — no PR ledger writes.
- **Standalone Terima Stok** preserved with `reference_type = null`.
- **HR module untouched.**

## Quality Gates

**Full-suite verification (recorded at Sprint 16.3 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 950 tests, 3178 assertions |
| GoodsReceipt-filtered tests (`php artisan test --filter=GoodsReceipt`) | PASS — 121 tests, 462 assertions |
| Routes (`php artisan route:list --name=goods-receipts`) | PASS — 9 routes |
| Pint (`./vendor/bin/pint --test`) | PASS |
| Frontend build (`npm.cmd run build`) | PASS |
| Knowledge graph (`graphify update .`) | PASS |

## Architecture Notes

- **Layering preserved:** `Controller → Request → Service → Repository → Model`.
- **First procurement ledger write:** GR post is the authoritative stock-in path for PO-linked
  receiving; PO alone does not increase stock.
- **Approved deviation:** intermediate `submitted` status and submit route added (design had
  draft→posted direct); post allowed from draft or submitted.
- **Approved deviation:** GR header uses PO relation for supplier context instead of denormalized
  supplier columns; `receipt_number` naming; extra line context columns for UX.

## Known Decisions

- Posted GR is **immutable** in 16.3; reversal deferred to future sprint.
- Over-receiving blocked with no override flag.
- `fully_received` is the receiving terminal PO status (`closed` deferred).
- Batch tracking on GR form not wired; `InventoryStockService` batch path available for future.
- Completion summary: `docs/sprint_16_3_goods_receipt_completion_summary.md`.

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 16.3 — Goods Receipt Workflow |
| Status | COMPLETED (full-suite gates verified) |
| Suggested tag | `sprint-16.3-complete` |

---

# Sprint 16.6 — Inventory Audit Trail & Activity Log

## Sprint 16.6 Overview

**Goal:** Append-only activity log for inventory/procurement workflows without changing ledger
stock, BranchContext, or Sprint 16.5 permissions.

**Delivered:**

- Dedicated table `inv_inventory_activity_logs` (not `sys_audit_logs`)
- `InventoryActivityLogService` with `log()` / `logForBranch()` and optional `correlation_id`
- Workflow logging in PR, PO, GR, Stock Transfer, Stock Opname, movement, and batch services
- Read-only UI at `/inventory/activity-logs` with branch-scoped filters
- Permission `view_inventory_activity_log` with fallback to existing inventory view permissions
- Non-blocking logging — business transactions succeed even if log write fails

**Out of scope:** Mutable stock columns, workflow changes, correlation chain full propagation,
export, log retention/archival.

## Quality Gates

**Full-suite verification (recorded at Sprint 16.6 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 1121 tests, 3778 assertions |
| Pint (`./vendor/bin/pint`) | PASS |
| Frontend build (`npm run build`) | PASS |

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-07 |
| Sprint Slice | 16.6 — Inventory Audit Trail & Activity Log |
| Status | COMPLETED (full-suite gates verified) |
| Tag | `sprint-16.6-complete` |
| Completion doc | `docs/sprint_16_6_inventory_audit_trail_completion.md` |

---

# Sprint 16.7 — Inventory Analytics & Executive Dashboard

## Sprint 16.7 Overview

**Goal:** Unified analytics layer and executive dashboard for inventory management — answering
business questions on stock value, movement intelligence, purchase/consumption trends, supplier
performance, and reorder recommendations — with read-only, ledger-derived, branch-safe metrics.

**Delivered:**

- `InventoryAnalyticsRepositoryInterface` with 17 KPI methods and provider binding
- `InventoryAnalyticsRepository` — on-read aggregates from ledger, procurement, opname tables
- `InventoryAnalyticsService` extended with KPI Lock Matrix (16 KPI) per governance design lock
- `InventoryExecutiveSnapshot` immutable DTO (9 typed executive KPI fields)
- `InventoryExecutiveDashboardService` — compose-only dashboard orchestration (no direct DB)
- Executive Dashboard UI at `/inventory/executive-dashboard` with KPI strip, trends, movement
  intelligence, valuation/aging, supplier table, reorder recommendations
- Permission `view_inventory_executive_dashboard` with policy gate and sidebar link
- Performance audit (16.7.9) — branch isolation PASS, supplier on-time N+1 fix, 16.8 readiness 82/100

**Analytics architecture:**

```text
Controller → InventoryExecutiveDashboardService → InventoryAnalyticsService
  → InventoryAnalyticsRepositoryInterface → ledger + procurement + master tables
```

Activity log (`inv_inventory_activity_logs`) explicitly **not** used as KPI source — audit/drill-down only.

**Executive dashboard:**

- Route `inventory.executive-dashboard`; thin `InventoryExecutiveDashboardController`
- Operational Inventory Value disclaimer (derived stock × `average_cost`, not accounting valuation)
- Reuse Sprint 15.5 fast/slow/dead/aging definitions; extend with procurement intelligence

**Performance audit:**

- Branch isolation: PASS (all methods `where branch_id = ?`)
- MVP index coverage: ADEQUATE; composite index follow-up deferred to 16.8
- Dashboard load: ~100–120 SQL statements at 10 suppliers (acceptable at pilot scale)
- Supplier on-time N+1 batched; stock subquery/product derived-stock repetition deferred to 16.8

**Out of scope:** Cross-branch rollup UI (`view_inventory_cross_branch` not seeded), summary tables,
Redis cache, CSV/PDF export, enhanced analytics page tabs, accounting-grade valuation.

## Quality Gates

**Full-suite verification (recorded at Sprint 16.7 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 1189 tests, 4124 assertions |
| Inventory analytics filter | PASS — 54 tests, 210 assertions |
| Inventory executive filter | PASS — 47 tests, 256 assertions |
| Pint (`./vendor/bin/pint`) | PASS |
| Frontend build (`npm run build`) | PASS |

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-07 |
| Sprint Slice | 16.7 — Inventory Analytics & Executive Dashboard |
| Status | COMPLETED (full-suite gates verified) |
| Tag | `sprint-16.7-complete` |
| Completion doc | `docs/sprint_16_7_inventory_analytics_completion.md` |
| Performance audit | `docs/sprint_16_7_performance_audit.md` |

---

# Sprint 16.8 — Analytics Optimization & Summary Tables

## Sprint 16.8 Overview

**Goal:** Reduce on-read query cost for inventory analytics (executive dashboard, analytics page,
operational widgets) via **read-only summary tables** refreshed from ledger and procurement — without
mutable stock columns, without changing Sprint 16.7 KPI formulas, and without altering Sprint
16.1–16.7 transaction workflows.

**Status:** COMPLETE
**Branch:** `feature/sprint-16-procurement`
**Release tag:** `sprint-16.8-complete`

**Delivered:**

- Design authority: `docs/sprint_16_8_analytics_optimization_design.md`
- 4 summary tables (`rpt_*`) — derived read models, not transaction sources
- `InventoryAnalyticsSummaryRefreshService` + `RefreshInventoryAnalyticsSummaryCommand`
- `InventorySummaryAnalyticsRepository` — swap implementation behind existing
  `InventoryAnalyticsRepositoryInterface`; original `InventoryAnalyticsRepository` retained
- Feature flag `INVENTORY_ANALYTICS_SUMMARY_ENABLED=false` (default) via `config/inventory.php`
- Conditional binding in `RepositoryServiceProvider`
- Reconciliation, incremental refresh, binding swap, and selective refresh tests
- `InventoryAnalyticsPageService` — deferred analytics tabs (summary default; movement, supplier,
  reorder, procurement, branch-comparison on demand)
- `InventoryBranchComparisonService` + branch comparison UI tab
- Permission `view_inventory_cross_branch_analytics` (Admin Lab, Super Admin)
- Scheduler: daily refresh 01:30, monthly prune 02:30 (1st)
- `PruneInventoryAnalyticsSummaryCommand` with retention `INVENTORY_ANALYTICS_SUMMARY_RETENTION_DAYS=730`
- Production notes: `docs/sprint_16_8_production_notes.md`
- Performance regression tests
- Completion summary: `docs/sprint_16_8_completion_summary.md`

**Summary tables added:**

| Table | Granularity | Purpose |
|---|---|---|
| `rpt_inventory_daily_summaries` | branch × day | Daily consumption/inbound trends |
| `rpt_inventory_branch_summaries` | branch × snapshot_date | Branch KPI strip, branch comparison |
| `rpt_inventory_product_summaries` | branch × product × snapshot_date | Movement intel, aging, reorder |
| `rpt_procurement_daily_summaries` | branch × day [× supplier] | PO/GR trends, supplier rollup |

**Key services/commands:**

- `InventoryAnalyticsSummaryRefreshService`
- `InventorySummaryAnalyticsRepository`
- `InventoryAnalyticsPageService`
- `InventoryBranchComparisonService`
- `RefreshInventoryAnalyticsSummaryCommand` (`inventory:analytics-summary:refresh`)
- `PruneInventoryAnalyticsSummaryCommand` (`inventory:analytics-summary:prune`)

**Feature flags / config:**

| Env | Default | Effect |
|---|---|---|
| `INVENTORY_ANALYTICS_SUMMARY_ENABLED` | **`false`** | `false` → live ledger repo; `true` → summary repo |
| `INVENTORY_ANALYTICS_SUMMARY_RETENTION_DAYS` | **730** | Prune retention for daily/procurement summaries |

**Permissions:**

- `view_inventory_cross_branch_analytics` — cross-branch analytics tab (Admin Lab, Super Admin)

**Analytics architecture (post-16.8):**

```text
InventoryAnalyticsController
  → InventoryAnalyticsPageService (deferred tabs)
  → InventoryAnalyticsService
    → InventoryAnalyticsRepositoryInterface
      → InventoryAnalyticsRepository (live ledger)  [flag false]
      → InventorySummaryAnalyticsRepository (rpt_*)   [flag true]
```

Refresh path (scheduled + manual):

```text
RefreshInventoryAnalyticsSummaryCommand
  → InventoryAnalyticsSummaryRefreshService
    → trx_inventory_movements + procurement tables → rpt_* (upsert)
```

**Invariants preserved:**

- `trx_inventory_movements` remains source of truth
- `rpt_*` read model/cache only — never written by transaction workflows
- No mutable stock columns
- Branch isolation via `BranchContext::requireId()`
- Instant rollback: set flag `false`, clear config cache

## Quality Gates

**Full-suite verification (recorded at Sprint 16.8 completion):**

| Gate | Result |
|---|---|
| Full test suite (`php artisan test`) | PASS — 1289 tests, 4584 assertions |
| Refresh command (`inventory:analytics-summary:refresh --all`) | PASS |
| Selective date refresh (`--date=2026-06-07 --all`) | PASS |
| Scheduler (`php artisan schedule:list`) | PASS |
| Pint (`./vendor/bin/pint`) | PASS |
| Frontend build (`npm run build`) | PASS |
| Git diff check (`git diff --check`) | PASS |
| `migrate:fresh --seed` | Not run (destructive safety); `RefreshDatabase` tests PASS |

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-07 |
| Sprint Slice | 16.8 — Analytics Optimization & Summary Tables |
| Status | COMPLETED (full-suite gates verified) |
| Tag | `sprint-16.8-complete` |
| Design doc | `docs/sprint_16_8_analytics_optimization_design.md` |
| Production notes | `docs/sprint_16_8_production_notes.md` |
| Completion doc | `docs/sprint_16_8_completion_summary.md` |

---

# Sprint 17.7 — Inventory Reports Page + Room Stock Report

## Status

**IMPLEMENTED** (documentation + closure quality gates; not yet committed)

**Design / completion doc:** `docs/sprint_17_7_inventory_reports_page.md`

**Suggested commit message:** `Add inventory reports page and room stock report`

**Suggested tag:** `sprint-17.7-inventory-reports-page`

## Summary

Adds a dedicated read-only **Laporan Inventory** page under Persediaan with six tabbed,
ledger-derived reports (current stock, stock card, low stock, mutation, valuation, room stock),
shared filters, independent per-tab pagination, and CSV export. Uses existing
`view_inventory` / `InventoryMovement::viewAny` authorization. No migrations, no new
permissions, no mutable stock columns, and no transfer or purchase-request workflow side effects.

## Routes

| Method | Path | Route name |
|---|---|---|
| GET | `/inventory/reports` | `inventory.reports.index` |
| GET | `/inventory/reports/export` | `inventory.reports.export` |

## Files Changed

**Added:**

- `app/Modules/Inventory/Controllers/InventoryReportController.php`
- `app/Modules/Inventory/Requests/InventoryReportFilterRequest.php`
- `app/Modules/Inventory/Services/InventoryReportService.php`
- `resources/views/inventory/reports/index.blade.php`
- `resources/views/inventory/reports/_empty-table.blade.php`
- `tests/Feature/Inventory/InventoryReportTest.php`
- `docs/sprint_17_7_inventory_reports_page.md`

**Modified:**

- `app/Modules/Inventory/Interfaces/InventoryMovementRepositoryInterface.php`
- `app/Modules/Inventory/Repositories/InventoryMovementRepository.php`
- `resources/views/layouts/sidebar.blade.php`
- `routes/web.php`

## Reports Added

| Tab | Label |
|---|---|
| `current_stock` | Laporan Stok Saat Ini |
| `stock_card` | Laporan Kartu Stok |
| `low_stock` | Laporan Low Stock |
| `mutation` | Laporan Mutasi Stok |
| `valuation` | Laporan Nilai Persediaan |
| `room_stock` | Laporan Stok per Ruangan |

## Export Added

- CSV only via `inventory.reports.export` (no Excel).
- `report_type`: `current_stock`, `stock_card`, `low_stock`, `mutation`, `valuation`, `room_stock`
- 5,000-row cap with trailing CATATAN row when exceeded.
- UTF-8 BOM for spreadsheet compatibility.
- Stock card export requires `product_id`.

## Authorization

- `InventoryReportController` authorizes `viewAny` on `InventoryMovement`.
- Existing `view_inventory` permission path; **no new permission**.
- Read-only; recommendations are text only.

## Ledger Invariants

- `trx_inventory_movements` source of truth.
- `current_stock = SUM(quantity_in) - SUM(quantity_out)`.
- Grouping: `branch_id + product_id + inventory_location_id` (current stock, low stock, valuation);
  `branch_id + inventory_location_id + product_id` (room stock).
- No mutable stock columns; no schema changes.

## Branch Isolation

- Active branch via `BranchContext::requireId()`; submitted `branch_id` does not widen access.
- Export remains active-branch scoped.
- No cross-branch refill recommendations.

## Tests / Quality Gates

Focused suite: `php artisan test --filter=InventoryReport` — **108 tests, 449 assertions** (PASS
at sprint implementation).

Closure gate results (2026-06-08):

| Gate | Result |
|---|---|
| `php artisan test --filter=InventoryReport` | PASS — 108 tests, 449 assertions |
| `.\vendor\bin\pint --test` | PASS |
| `git diff --check` | PASS |
| `php artisan route:list --path=inventory` | PASS — 106 routes |
| `npm run build` | PASS |
| `php artisan test` (full) | **FAIL** — 1430 passed, 1 failed (~407s); `InventoryUiTest` `assertDontSee('Inventory')` conflicts with sidebar **Laporan Inventory** label |

## Limitations

- Products without movement rows excluded.
- Export capped at 5,000 rows.
- Valuation is operational estimate (`average_cost × derived stock`).
- Minimum stock remains product-level; per-room thresholds deferred.
- Full suite completed but **one pre-existing UI assertion failed**: `InventoryUiTest` expects no
  English "Inventory" on the dashboard; new sidebar label **Laporan Inventory** triggers it.
  Focused `InventoryReport` suite (108 tests) remains the sprint regression anchor.

## Recommended Next Sprint

**Sprint 17.8 — Minimum Stock per Room**

- `inv_location_product_minimums` table
- Per-room minimum/maximum stock
- Room stock refill thresholds and printable checklist
- Room stock opname and refill request workflow from Gudang Utama
- Update room stock report to use per-room thresholds

---

# Sprint 19 — Clinic Master Data

## Sprint 19 Overview

**Status:** COMPLETE. Branch `feature/sprint-19-clinic-master-data`, final commit `0247d0a`,
completion date 2026-06-09.

**Business objective:** Extend Daengtisia Management System with six clinic-specific master data
modules needed as a prerequisite for future RME, cashier, and WhatsApp reminder workflows.
All modules are master data only — no transaction, billing, or messaging logic is implemented.

**Timeline:**
- Phase 0–1: Clinic Room (`cadba17`)
- Phase 2: Treatment Category & Treatment (`d605e0d`)
- Phase 3: Tariff (`42a9647`, tag `sprint-19-phase-3-tariff-master-data`)
- Phase 4: Payment Method (`b935949`, tag `sprint-19-phase-4-payment-method-master-data`)
- Phase 5: WA Reminder Templates (`0247d0a`, tag `sprint-19-phase-5-wa-reminder-template-master-data`)

## Modules Added

| Module | Scope |
|---|---|
| `ClinicRoom` | Branch-scoped |
| `TreatmentCategory` | Global |
| `Treatment` | Global |
| `Tariff` | Branch-scoped |
| `PaymentMethod` | Global |
| `WaReminderTemplate` | Global |

## Tables Added

| Table | Scope |
|---|---|
| `mst_clinic_rooms` | Branch-scoped (`branch_id` FK) |
| `mst_treatment_categories` | Global |
| `mst_treatments` | Global |
| `mst_tariffs` | Branch-scoped (`branch_id` FK) |
| `mst_payment_methods` | Global |
| `mst_wa_reminder_templates` | Global |

## Route Groups Added

All under `settings.*` prefix with `auth` middleware and permission gates:

| Route Group | Resource |
|---|---|
| `settings.clinic-rooms.*` | CRUD clinic rooms |
| `settings.treatment-categories.*` | CRUD treatment categories |
| `settings.treatments.*` | CRUD treatments |
| `settings.tariffs.*` | CRUD tariffs |
| `settings.payment-methods.*` | CRUD payment methods |
| `settings.wa-reminder-templates.*` | CRUD WA reminder templates |

## Permission Model

- `view_clinic_master_data` — read access to all Sprint 19 modules
- `manage_clinic_master_data` — write access (create/update/delete)

Both seeded in `PermissionSeeder` and assigned in `RoleSeeder`. Super Admin covered by
centralized `Gate::before` in `RepositoryServiceProvider`.

## Branch Scope Decisions

- **Branch-scoped:** `ClinicRoom` (physical rooms per branch) and `Tariff` (branch-level pricing
  per treatment per effective date; unique key `(branch_id, treatment_id, effective_date)`).
- **Global:** `TreatmentCategory`, `Treatment`, `PaymentMethod`, `WaReminderTemplate` — shared
  catalogues with no branch isolation requirement.

Branch-scoped services call `BranchContext::requireId()` and repositories scope by `branch_id`.

## Architecture Pattern

Sprint 19 modules follow the canonical flow:
```
Controller → FormRequest → Service → Interface → Repository → Model → Policy → Blade → Routes → Seeder/Factory/Test
```
Interface-to-repository bindings for all six modules registered in `RepositoryServiceProvider`.

## Quality Gates

Quality gate run: 2026-06-09

| Gate | Result |
|---|---|
| `php artisan migrate:fresh --seed` | PASS — 61 migrations, 15 seeders |
| `php artisan test tests/Feature/ClinicMasterData` | **PASS — 129 tests, 444 assertions** |
| `php artisan test` (full suite) | **PASS — 1565 tests, 5601 assertions** |
| `./vendor/bin/pint` | PASS — no changes |
| `npm run build` | PASS |
| `git status` | Clean |

## Architecture Notes

- `TreatmentCategory` is global; `Treatment` references it globally; branch-level pricing is
  modelled entirely in `Tariff` so the treatment catalogue stays shared while pricing stays
  isolated.
- `WaReminderTemplate` stores message body templates and variable metadata only. No WhatsApp
  API integration, no job/scheduler, and no automatic delivery — templates are a library for
  future wiring.
- All modules use `is_active` + soft deletes for lifecycle management; no hard deletes on records
  referenced by future transactions.
- Tariff seeder seeds default pricing for the MAIN branch only; branch admins seed their own.

## Known Limitations

- Master data only; cashier, RME, and WhatsApp workflows deferred.
- Tariff records the master price per treatment but is not yet connected to a billing/RME flow.
- WA templates do not send messages automatically.

## Explicit Out-of-Scope

No RME workflow, no patient visit workflow, no odontogram, no cashier payment transactions,
no payment installment, no WhatsApp API integration or scheduler, no changes to the Inventory
ledger stock rules.

## Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-19-clinic-master-data` |
| Final commit | `0247d0a` |
| Completion date | 2026-06-09 |
| Status | COMPLETE |

## Documentation Added

- `docs/sprint_19_completion_summary.md`
- Sprint 19 section added to `docs/sprint_history.md`

---

# Sprint 20 — RME Core

## Sprint 20 Phase 1.2 — RME Core Medical Record

**Status:** COMPLETE / CLOSED. Branch `feature/sprint-20-rme-core`, final commit `ccf08dd`,
tag `sprint-20-phase-1-2-9-rme-polish`, completion date 2026-06-10.

**Business objective:** Build the RME (Rekam Medis Elektronik) foundation — a medical record
linked 1:1 to every ClinicVisit, covering schema, service layer, HTTP layer, SOAP draft/final UI,
finalization metadata, listing/search, sidebar navigation, dashboard widgets, and UI permission
polish. Designed to be extended with odontogram, ICD-10, PDF export, and lab workflow in future
phases.

**Timeline (Phase 1.2):**
- Phase 1.2.1: Medical Record Foundation (`f0e9763`, tag `sprint-20-phase-1-2-1-medical-record-foundation`)
- Phase 1.2.2: Repository & Service Layer (`5042e5e`, tag `sprint-20-phase-1-2-2-medical-record-service-layer`)
- Phase 1.2.3: HTTP Layer (`a6f2277`, tag `sprint-20-phase-1-2-3-medical-record-http-layer`)
- Phase 1.2.4: ClinicVisit Integration UI (`f2a80ee`, tag `sprint-20-phase-1-2-4-medical-record-ui-integration`)
- Phase 1.2.5: Metadata & Finalization Polish (`cade2fd`, tag `sprint-20-phase-1-2-5-medical-record-metadata-finalization`)
- Phase 1.2.6: List & Search Polish (`84f2fed`, tag `sprint-20-phase-1-2-6-medical-record-list-search`)
- Phase 1.2.7: RME Sidebar Navigation (`4ba8b3e`, tag `sprint-20-phase-1-2-7-rme-sidebar-navigation`)
- Phase 1.2.8: RME Dashboard Widgets (`d7ca4e7`, tag `sprint-20-phase-1-2-8-rme-dashboard-widgets`)
- Phase 1.2.9: UI Permission Polish (`ccf08dd`, tag `sprint-20-phase-1-2-9-rme-polish`)

## Sub-phases Completed

### Phase 1.2.1 — Medical Record Foundation
- Migration `trx_medical_records`
- `MedicalRecord` model, `MedicalRecordFactory`
- `ClinicVisit` `hasOne` `MedicalRecord`
- Basic model/factory/relation tests

### Phase 1.2.2 — Repository & Service Layer
- `MedicalRecordRepositoryInterface`, `MedicalRecordRepository`
- `MedicalRecordService`: `createDraft()`, `finalize()`
- Branch-safe and duplicate-safe service tests

### Phase 1.2.3 — HTTP Layer
- `MedicalRecordPolicy`
- Store/Update/Finalize Form Requests
- `MedicalRecordController`
- Nested routes under `ClinicVisit`
- `updateDraft()` method
- HTTP tests: create/update/finalize/show/permissions/branch isolation

### Phase 1.2.4 — ClinicVisit Integration UI
- Rekam Medis section in `ClinicVisit` show
- `MedicalRecord` show view with SOAP draft form
- Final records rendered read-only
- Finalize action for draft records
- UI tests for draft/final behavior

### Phase 1.2.5 — Metadata & Finalization Polish
- `finalized_at` migration, fillable, cast
- `finalize()` sets `finalized_at`; idempotent (does not overwrite)
- Metadata display: `recorded_by`, `created_at`, `updated_at`, `finalized_at`

### Phase 1.2.6 — List & Search Polish
- `rme.medical-records.index` route
- Branch-safe listing with filters: status, patient/doctor/visit number search, visit date range
- Pagination, empty state, `ViewAny` policy

### Phase 1.2.7 — RME Sidebar Navigation
- Sidebar group **RME**
- Links: **Kunjungan**, **Rekam Medis**
- Permission gate: `view_clinic_visits|manage_clinic_visits`
- Sidebar visibility tests

### Phase 1.2.8 — RME Dashboard Widgets
- Widget cards on `rme.visits.index`:
  - Kunjungan Hari Ini
  - Menunggu
  - Sedang Dilayani
  - RM Draft
  - RM Final Hari Ini
- Branch-safe count methods; widget tests

### Phase 1.2.9 — UI Permission Polish
- Viewer sees read-only SOAP; no edit/finalize UI shown
- Null-safe action link in medical record index
- Clearer date filter labels
- UI permission tests

## Tables Added

| Table | Scope |
|---|---|
| `trx_medical_records` | Branch-scoped (`branch_id`, `patient_id`, `doctor_id` denormalized) |

## Route Groups Added

All under `rme.*` prefix with `auth` middleware:

| Route | Description |
|---|---|
| `rme.medical-records.index` | Medical record listing |
| `rme.visits.index` | Visit listing with dashboard widgets |
| `rme.visits.create` | New visit form |
| `rme.visits.store` | Create visit |
| `rme.visits.show` | Visit detail |
| `rme.visits.edit` | Edit visit form |
| `rme.visits.update` | Update visit |
| `rme.visits.medical-record.show` | Medical record show (nested) |
| `rme.visits.medical-record.store` | Create medical record draft (nested) |
| `rme.visits.medical-record.update` | Update medical record draft (nested) |
| `rme.visits.medical-record.finalize` | Finalize medical record (nested) |
| `rme.visits.transition` | ClinicVisit status transition |

## Permission Model

- `view_clinic_visits` — read access to visits and medical records (read-only SOAP)
- `manage_clinic_visits` — create/update/finalize medical records and manage visits

Both permissions gate the RME sidebar group and are checked in `MedicalRecordPolicy`.

## Architecture Notes

- `MedicalRecord` linked 1:1 to `ClinicVisit` via unique `visit_id` FK.
- `branch_id`, `patient_id`, `doctor_id` denormalized on `trx_medical_records` for query
  performance and branch isolation — never derived from join at query time.
- Branch isolation enforced via `BranchContext::requireId()` in service and `branch_id` filter
  in repository.
- `finalize()` is idempotent: sets `status = final` and `finalized_at` only if not already set;
  calling again is a no-op.
- Final medical records are immutable: no update or re-finalization after `finalized_at` is set.
- Viewer role sees SOAP content read-only; Manager role may create/update/finalize.
- Listing always branch-safe and paginated; no unscoped queries.

## Quality Gates

Quality gate run: 2026-06-10

| Gate | Result |
|---|---|
| `php artisan route:list --name=rme` | **12 RME routes active** |
| `php artisan test --filter=MedicalRecord` | **PASS — 49 tests, 123 assertions** |
| `php artisan test --filter=ClinicVisit` | **PASS — 37 tests, 126 assertions** |
| `./vendor/bin/pint --dirty` | PASS — no changes |
| `git status` | Clean |

## Known Limitations / Backlog

1. **Registered visit rule** — business decision pending: whether "Buat Rekam Medis" is allowed
   when visit status is still `registered`, or requires at least `waiting`/`in_progress`.
2. **Status badge refactor** — status label/badge duplicated across several Blade views; candidate
   for helper/component extraction.
3. **Odontogram** — not implemented; candidate for Sprint 20 Phase 1.3.1 (Odontogram Placeholder
   Foundation).
4. **ICD-10 / structured diagnosis field** — not implemented; SOAP fields are free-text only.
5. **PDF export** — not implemented; deferred until final medical record layout is agreed.
6. **Lab workflow integration** — not in Phase 1.2; will be a separate phase.
7. **Treatment / payment / cicilan** — out of scope Phase 1.2; will be a separate phase.

## Explicit Out-of-Scope

No odontogram, no ICD-10 structured field, no PDF export, no lab workflow integration,
no treatment/payment/cicilan, no cashier module, no changes to Inventory ledger rules,
no changes to clinic master data modules.

## Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Final commit | `ccf08dd` |
| Final tag | `sprint-20-phase-1-2-9-rme-polish` |
| Completion date | 2026-06-10 |
| Status | COMPLETE / CLOSED |

## Documentation Added

- Sprint 20 Phase 1.2 section added to `docs/sprint_history.md`

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
18. **S15.3** — Batch/lot identity on `inv_inventory_batches`; movements optionally reference
    `inventory_batch_id`; batch stock ledger-derived; expired outbound blocked; read-only batch UI.
19. **S15.4** — Unified inventory alerts computed on read via `InventoryAlertService`; product
    reorder fields (`reorder_point`, `reorder_quantity`, `alert_enabled`) on `inv_products`; stock
    and batch alerts remain ledger-derived; no alert persistence table.
20. **S15.5** — Read-only inventory analytics via `InventoryAnalyticsService`; all metrics
    ledger-derived from `trx_inventory_movements`; no analytics persistence or mutable stock columns.
21. **S15.6** — Inventory navigation/dashboard hardening: Stok Opname sidebar discovery, dashboard
    KPI deduplication with `InventoryAlertService` as canonical alert source, permission-gated quick
    actions, dead sidebar links removed; no schema or ledger-rule changes.
22. **S16.1** — Purchase Request as intent-only document workflow; no inventory movements; branch-
    scoped approval via `PurchaseRequestService` and `approve_inventory_purchase_request`.
23. **S16.2** — Purchase Order as document-only workflow; no stock updates or `trx_inventory_movements`;
    `total_amount` computed via model accessor (not stored); supplier snapshot at creation; PR-linked PO
    from approved PR only with duplicate-active-PO guard; receiving statuses deferred to Sprint 16.3.
24. **S16.3** — Goods Receipt as first procurement ledger write; GR post creates PURCHASE movements
    with `reference_type/id` traceability; PO item `quantity_received` as service-owned derived cache
    (accepted qty only); PO receiving statuses `partially_received`/`fully_received`; posted GR immutable;
    intermediate `submitted` review step; no mutable stock columns.
25. **S16.6** — Dedicated `inv_inventory_activity_logs` for inventory/procurement audit trail;
    branch-scoped append-only logs; non-blocking workflow logging; optional `correlation_id`; UI at
    `/inventory/activity-logs`; permission `view_inventory_activity_log`; no ledger or workflow changes.
26. **S16.8** — Analytics summary tables (`rpt_*`) as read-only derived cache refreshed from ledger
    and procurement; swap via `InventoryAnalyticsRepositoryInterface` + feature flag default `false`;
    original live ledger repository retained for instant rollback; scheduler refresh/prune; cross-branch
    comparison tab gated by `view_inventory_cross_branch_analytics`; deferred analytics tabs on index page.
27. **S20** — `MedicalRecord` 1:1 with `ClinicVisit`; `trx_medical_records` denormalizes
    `branch_id`, `patient_id`, `doctor_id` for query performance and branch isolation; branch safety
    via `BranchContext::requireId()` and `branch_id` filter in repository; draft/finalize workflow;
    idempotent `finalize()` never overwrites `finalized_at`; final record immutable after
    `finalized_at` is set; Viewer read-only, Manager may create/update/finalize.

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
13. **S15.3** — Batch/lot identity is metadata on `inv_inventory_batches`; quantity remains on
    the movement ledger; nullable `inventory_batch_id` preserves pre-15.3 behavior; outbound
    movements reject inactive or expired batches.
14. **S15.4** — Stock alert severities: out-of-stock (`<= 0`), critical (`<= minimum_stock`),
    low (`<= effective_reorder_point`); batch expiry alerts require derived batch stock `> 0`;
    `alert_enabled` gates stock alerts; `reorder_quantity` is informational only.
15. **S16.1** — Purchase Request is intent-only; no ledger writes on PR workflow; approved PR is
    prerequisite for linked PO creation in 16.2.
16. **S16.2** — Purchase Order is document-only; PO workflow does not increase stock; `PURCHASE`
    movements deferred to Goods Receipt; PO total computed from line items, not stored on header.
17. **S16.3** — Goods Receipt post creates PURCHASE ledger movements; `accepted_qty` enters stock;
    `rejected_qty` is audit-only; PO `quantity_received` cache updated by service on post (accepted
    only); over-receive blocked; posted GR immutable; stock remains `SUM(in) − SUM(out)`.
18. **S20** — `MedicalRecord` linked 1:1 to `ClinicVisit`; branch isolation via
    `BranchContext::requireId()` and `branch_id` filter; Manager may create/update/finalize;
    Viewer is read-only; final record immutable; `finalize()` idempotent — never overwrites
    `finalized_at`; registered-visit-create-rule pending business decision.

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
7. **S15.3** — Batch & Lot UI under Persediaan sidebar; index/show read-only; expiry badges;
   batch context integrated into receive, adjust, and transfer forms without menu sprawl beyond
   one permission-gated **Batch & Lot** link.
8. **S15.4** — **Peringatan Stok** alerts index with unified stock/batch table; dashboard KPI
   strip and `stock-value-card` aligned to `InventoryAlertService`; severity badges Habis / Kritis /
   Menipis / Kedaluwarsa / Segera Kedaluwarsa; reorder fields on product form/show.
9. **S15.5** — **Analitik Persediaan** read-only analytics page; Indonesian labels; responsive
   desktop-table / mobile-card layout; ledger-derived disclaimers.
10. **S15.6** — **Stok Opname** sidebar link; inventory dashboard quick actions panel;
    duplicate alert KPI strip removed (`InventoryAlertService` canonical); sidebar dead placeholder
    links removed.
11. **S16.1** — **Permintaan Pembelian** sidebar link; dashboard quick action **Buat Permintaan
    Pembelian**; alerts **Buat PR** shortcut (prefill only).
12. **S16.2** — **Pesanan Pembelian** sidebar link; dashboard quick action **Buat Pesanan Pembelian**;
    **Buat PO** on approved PR show page; no Goods Receipt / Terima Barang / Update Stok UI.
13. **S20** — RME module UI: SOAP draft form on `ClinicVisit` show; read-only final record display;
    Finalize action for draft records; metadata panel (`recorded_by`, `created_at`, `updated_at`,
    `finalized_at`); medical records listing with status/search/date-range filters; RME sidebar
    group (Kunjungan + Rekam Medis) permission-gated; dashboard widget strip (Kunjungan Hari Ini /
    Menunggu / Sedang Dilayani / RM Draft / RM Final Hari Ini); Viewer sees read-only SOAP only.

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
- **Sprint 15.3 completed baseline — Batch & Lot Tracking:** Future changes must preserve
  ledger-derived batch stock (no mutable quantity on `inv_inventory_batches`), nullable
  `inventory_batch_id` backward compatibility, branch-scoped batch access, inactive/expired batch
  outbound guards, and transfer ship/receive batch FK propagation. Batch-aware stock opname and
  `requires_batch_tracking` product flag remain follow-up work.
- **Sprint 15.4 completed baseline — Reorder Point & Inventory Alerts:** Future changes must
  preserve ledger-derived alert quantities (no mutable stock or alert-count columns), read-only
  computed alerts via `InventoryAlertService`, branch-scoped alert queries, `alert_enabled`
  gating, and informational-only `reorder_quantity`. Notification channels, alert persistence,
  and owner cross-branch rollup remain follow-up work.
- **Sprint 15.5 completed baseline — Inventory Analytics:** Future changes must preserve
  ledger-derived analytics (no mutable stock columns, no analytics cache tables), branch-scoped
  queries via `BranchContext::requireId()`, read-only `InventoryAnalyticsService`, and UI disclaimers
  that outbound value trend ≠ on-hand value history. Owner cross-branch rollup and value snapshot
  tables remain follow-up work.
- **Sprint 15.6 completed baseline — Inventory Advanced Hardening & Navigation Closure:** Future
  changes must preserve `InventoryAlertService` as the canonical dashboard alert KPI source (no
  duplicate KPI strips), permission-gated sidebar and quick-action links only to implemented routes,
  Stok Opname sidebar discoverability, and removal of dead placeholder navigation. No mutable stock
  columns; branch isolation and ledger rules unchanged.
- **Sprint 16.1 completed baseline — Purchase Request:** Future changes must preserve PR as
  intent-only (no `trx_inventory_movements` on PR workflow), ledger-derived stock unchanged, branch
  isolation via `BranchContext` and `PurchaseRequestPolicy`, status-gated transitions in
  `PurchaseRequestService`, and permission `approve_inventory_purchase_request` for approval paths.
- **Sprint 16.2 completed baseline — Purchase Order:** Future changes must preserve PO as
  document-only (no `trx_inventory_movements` on PO workflow), ledger-derived stock unchanged,
  branch isolation via `BranchContext` and `PurchaseOrderPolicy`, status-gated transitions in
  `PurchaseOrderService`, computed `total_amount` (not stored on header), supplier snapshot at
  creation, PR-linked PO from approved PR only with duplicate-active-PO guard, and permission
  `approve_inventory_purchase_order` (with `manage_inventory` / legacy `manage master data`
  fallback) for approval paths. Receiving statuses (`partially_received`, `fully_received`,
  `closed`) remain unimplemented until Sprint 16.3.
- **Sprint 16.3 completed baseline — Goods Receipt:** Future changes must preserve GR as the first
  procurement ledger write; PO alone does not increase stock; posted GR immutability; branch isolation
  via `BranchContext` and `GoodsReceiptPolicy`.
- **Sprint 16.6 completed baseline — Inventory Activity Log:** Future changes must preserve
  `inv_inventory_activity_logs` as the dedicated inventory audit store (not `sys_audit_logs`);
  append-only logs with `branch_id` scope; non-blocking logging in workflow services; ledger-derived
  stock unchanged; Activity Log UI permission-gated via `view_inventory_activity_log` with inventory
  view fallbacks.
- **Sprint 16.7 completed baseline — Inventory Analytics & Executive Dashboard:** Future changes
  must preserve ledger-derived analytics (no mutable stock columns), branch-scoped queries via
  `BranchContext::requireId()`, read-only `InventoryAnalyticsService` behind
  `InventoryAnalyticsRepositoryInterface`, compose-only `InventoryExecutiveDashboardService`, KPI
  Lock Matrix formulas, activity log forbidden as KPI source, and Operational Inventory Value
  disclaimer. Summary tables and cross-branch analytics were delivered in Sprint 16.8.
- **Sprint 16.8 completed baseline — Analytics Optimization & Summary Tables:** Future changes
  must preserve `trx_inventory_movements` as source of truth; `rpt_*` tables as read model/cache only
  (never written by transaction workflows); feature flag `INVENTORY_ANALYTICS_SUMMARY_ENABLED` default
  `false`; original `InventoryAnalyticsRepository` retained; instant rollback via flag without truncate;
  branch isolation via `BranchContext`; cross-branch comparison gated by
  `view_inventory_cross_branch_analytics`; no mutable stock columns; refresh idempotent via
  `InventoryAnalyticsSummaryRefreshService`.
- **Sprint 17 — HR Core:** Separate module; not directly coupled to production/payroll/attendance
  except through explicit services/relationships. Branch-owned employees use `branch_id` +
  `BranchContext`.
- **Sprint 18 — Daengtisia Rebranding:** Safe branding-only sprint; all technical names, routes,
  namespaces, and module structures retained. `APP_NAME`, dashboard text, and MAIN branch seed
  name changed to Daengtisia Management System. No schema changes; no module renames.
- **Sprint 19 completed baseline — Clinic Master Data:** Future changes must preserve
  `view_clinic_master_data` / `manage_clinic_master_data` as the permission pair for all six
  clinic master data modules; `BranchContext::requireId()` for ClinicRoom and Tariff; global
  (no `branch_id`) for TreatmentCategory, Treatment, PaymentMethod, and WaReminderTemplate;
  `is_active` + soft deletes lifecycle; no WhatsApp sending, no cashier/RME billing transactions,
  no Inventory ledger changes; Tariff as master price only (not yet wired to billing workflow).
- **Sprint 20 Phase 1.2 completed baseline — RME Core Medical Record:** Future changes must
  preserve `MedicalRecord` 1:1 with `ClinicVisit` (unique `visit_id` FK); `branch_id`,
  `patient_id`, `doctor_id` denormalized on `trx_medical_records` (never derived from join at
  query time); `finalize()` idempotent — never overwrites `finalized_at`; final record immutable
  (no update or re-finalization after `finalized_at` is set); Viewer permission is read-only;
  Manager may create/update/finalize; listing always branch-safe and paginated via
  `BranchContext::requireId()`; `view_clinic_visits` / `manage_clinic_visits` as the permission
  pair for all RME routes; no odontogram, no ICD-10 structured field, no PDF export, no lab
  workflow integration, no treatment/payment/cicilan in this phase.

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
(S12–15.6, ledger-derived, location-aware, Stock Opname, Stock Transfer with ship/receive,
Batch & Lot tracking, Reorder Point & Inventory Alerts, Inventory Analytics, navigation/dashboard
hardening, Purchase Request workflow, Purchase Order workflow) · `ClinicRoom, TreatmentCategory,
Treatment, Tariff, PaymentMethod, WaReminderTemplate` (S19, clinic master data under
`settings.*` routes, permissions `view_clinic_master_data` / `manage_clinic_master_data`) ·
`MedicalRecord, ClinicVisit` (S20 Phase 1.2, RME core under `rme.*` routes, permissions
`view_clinic_visits` / `manage_clinic_visits`).

**Roles:** Super Admin, Admin Lab, Technician, Quality Control, Delivery Coordinator, Courier,
Finance, Doctor.

**Read before coding:** `docs/architecture_rules.md`, `docs/ai_development_guide.md`, this file,
the target module under `app/Modules`, relevant routes/policies/tests, and (branch work)
`app/Modules/Branch/Services/BranchContext.php`. Inventory invariants and the Future Sprint
Constraints above are binding.

---

*Historical record only — this document changes no application code. It reflects decisions as of
Sprint 20 Phase 1.2 (RME Core Medical Record, 2026-06-10) and must be updated as each new sprint completes.*
