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

## Sprint 20 Phase 1.3.1 — Odontogram Placeholder Foundation

**Status:** COMPLETE. Branch `feature/sprint-20-rme-core`, tag `sprint-20-phase-1-3-1-odontogram-placeholder`,
completion date 2026-06-10.

**Business objective:** Lay the data and HTTP foundation for the Odontogram feature — a placeholder
record linked 1:1 to each ClinicVisit — without implementing the interactive tooth-map chart, SVG,
canvas drawing, or any clinical diagnosis logic. The placeholder is idempotent, branch-safe, and
ready to be extended in Sprint 20 Phase 1.3.2.

### Scope

- Database table `trx_odontograms` (unique `clinic_visit_id`, branch-scoped, JSON `tooth_map_payload`
  reserved for future use, soft deletes).
- Odontogram module: Model, Factory, Repository + Interface, Service, Policy, FormRequest, Controller.
- Routes under `rme.*` prefix.
- Blade view placeholder (no canvas/SVG/chart).
- "Buka Odontogram" link in ClinicVisit show page (permission-gated).
- `ClinicVisit` model extended with `odontogram()` hasOne relation.
- 25 Pest tests proving auth, branch isolation, idempotency, validation, and UI presence.
- sprint_history.md updated.

### Tables Added

| Table | Scope |
|---|---|
| `trx_odontograms` | Branch-scoped; unique `clinic_visit_id` |

**Schema:**
- `id`, `clinic_visit_id` (unique FK → `trx_clinic_visits`), `branch_id` (FK → `mst_branches`)
- `medical_record_id` (nullable FK → `trx_medical_records`)
- `status` string default `draft`
- `summary_notes` nullable text
- `tooth_map_payload` nullable jsonb
- `created_by`, `updated_by` (nullable FK → `users`)
- `timestamps`, `softDeletes`
- Indexes: `(branch_id, status)`, `medical_record_id`

### Module Added

`app/Modules/Odontogram/` with:

| File | Purpose |
|---|---|
| `Models/Odontogram.php` | Model with casts, relations, STATUS_DRAFT constant |
| `Interfaces/OdontogramRepositoryInterface.php` | Repository contract |
| `Repositories/OdontogramRepository.php` | `findByClinicVisit`, idempotent `createForClinicVisit`, `updatePlaceholder` |
| `Services/OdontogramService.php` | `getOrCreateForVisit`, `updateNotes` with branch isolation |
| `Policies/OdontogramPolicy.php` | view (view_clinic_visits+branch), update (manage_clinic_visits+branch) |
| `Requests/UpdateOdontogramPlaceholderRequest.php` | `summary_notes` max:5000, `tooth_map_payload` array |
| `Controllers/OdontogramController.php` | `show` (get-or-create), `update` (notes only) |

**Factory:** `database/factories/OdontogramFactory.php`

### Routes Added

All under `rme.*` prefix with `auth` + `view_clinic_visits|manage_clinic_visits` middleware:

| Method | URI | Name |
|---|---|---|
| GET | `rme/visits/{clinicVisit}/odontogram` | `rme.visits.odontogram.show` |
| PATCH | `rme/odontograms/{odontogram}` | `rme.odontograms.update` (manage_clinic_visits required) |

### Permission Rules

Reuses existing Sprint 20 permissions — **no new permissions created**:

- **View / open placeholder:** `view_clinic_visits` or `manage_clinic_visits` + active branch ownership.
- **Update summary_notes:** `manage_clinic_visits` + active branch ownership.
- **Branch isolation:** `BranchContext::requireId()` in service; policy denies cross-branch access.
- Super Admin bypass remains centralized in `RepositoryServiceProvider`.

### ClinicVisit Changes

- `odontogram()` hasOne relation added to `ClinicVisit` model.
- "Buka Odontogram" link added to `rme/visits/show.blade.php` (gated by `@can('create', [Odontogram, $visit])`).

### Tests

`tests/Feature/RME/OdontogramTest.php` — **25 tests, 45 assertions** (PASS):

| Group | Tests |
|---|---|
| Model / factory / relation | factory create, default status, json cast, hasOne/belongsTo relations |
| Service layer | getOrCreateForVisit creates, idempotent, cross-branch rejected, updateNotes, cross-branch update rejected |
| HTTP show | manager OK, viewer OK, creates on first open, no duplicate on second open, unauthenticated redirect, cross-branch 403 |
| HTTP update | manager updates, viewer 403, no-permission 403, cross-branch 403, max-length validation, null allowed |
| UI | visit show page has "Buka Odontogram" link for authorized user |

### Quality Gates

| Gate | Result |
|---|---|
| `php artisan test --filter=Odontogram` | **PASS — 25 tests, 45 assertions** |
| `php artisan test --filter=ClinicVisit` | **PASS — 37 tests, 126 assertions** |
| `php artisan test` (full suite) | PASS — 1672 passed; 4 pre-existing ClinicMasterData failures unrelated to this phase |
| `./vendor/bin/pint --dirty` | PASS — no changes |
| `php artisan route:list \| grep odontogram` | PASS — 2 routes registered |

### Architecture Notes

- `clinic_visit_id` is unique on `trx_odontograms` — 1:1 relationship enforced at DB level.
- `createForClinicVisit()` is idempotent: if a row already exists, it is returned unchanged.
- `tooth_map_payload` reserved as nullable JSONB — accepted through `UpdateOdontogramPlaceholderRequest`
  but no clinical logic operates on it in Phase 1.3.1.
- Branch isolation follows the same pattern as `MedicalRecord`: `BranchContext::requireId()` in
  service, `branch_id` on the record, policy denies cross-branch `view`/`update`.
- Layering preserved: `Controller → Request → Service → Repository → Model`.

### Explicit Out-of-Scope

No interactive tooth map (canvas/SVG/chart), no per-tooth diagnosis codes, no ICD-10 dental codes,
no treatment plan from odontogram, no billing, no cicilan, no lab prosthesis integration, no
inventory/lab module changes, no new permissions, no new roles.

### Next Phase Suggestion

**Sprint 20 Phase 1.3.2 — Odontogram Tooth Map Draft UI**
- Add tooth number constants (FDI notation 11–48) to the Odontogram model.
- Alpine.js-based interactive tooth-map grid (click to mark status per tooth).
- `tooth_map_payload` persisted as structured JSON via the existing PATCH endpoint.
- Condition codes: normal, karies, missing, crown, root-treated (basic set only).
- Visual-only status badges per tooth cell; no billing or ICD-10 integration.

### Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Tag | `sprint-20-phase-1-3-1-odontogram-placeholder` |
| Completion date | 2026-06-10 |
| Status | COMPLETE |

---

## Sprint 20 Phase 1.3.2 — Odontogram Tooth Map Draft UI

**Status:** COMPLETE. Branch `feature/sprint-20-rme-core`,
tag `sprint-20-phase-1-3-2-odontogram-tooth-map-draft-ui`,
completion date 2026-06-10.

**Business objective:** Extend the Phase 1.3.1 odontogram placeholder with a functional
draft tooth-map UI: a 32-tooth FDI grid rendered in Blade + Alpine.js, status-per-tooth
selection, JSON serialisation into `tooth_map_payload`, and validated PATCH persistence.
No canvas, no SVG, no final diagnosis logic, no billing.

### Scope

- `UpdateOdontogramPlaceholderRequest` hardened: JSON string decode in
  `prepareForValidation()`, nested tooth-map rules, FDI tooth-number whitelist via
  `withValidator()`.
- `OdontogramService::updateNotes()` renamed to `updatePlaceholder()` (branch guard,
  `summary_notes` + `tooth_map_payload`, `updated_by` — unchanged behaviour).
- `OdontogramController::update()` wired to `updatePlaceholder()`; success message updated.
- `show.blade.php` fully replaced: legend, Alpine.js status selector (manager only),
  32-tooth FDI grid (clickable for managers / read-only for viewers), reactive hidden
  `tooth_map_payload` input, `summary_notes` textarea, single save action.
- 10 new Pest tests added (35 total, 98 assertions). All Phase 1.3.1 tests retained.

### FDI Tooth Layout

```
Upper:  [18 17 16 15 14 13 12 11] | [21 22 23 24 25 26 27 28]
                       right ← center → left
Lower:  [48 47 46 45 44 43 42 41] | [31 32 33 34 35 36 37 38]
```

### Allowed Status Values

| Value | Label | Colour |
|---|---|---|
| `normal` | Normal (ditandai) | green-100 |
| `caries` | Karies | red-200 |
| `missing` | Hilang | gray-800 |
| `crown` | Crown | amber-200 |
| `root_treated` | PSA | sky-200 |

Teeth with no entry in `teeth` object display as white (default/normal unset).

### JSON Format

```json
{
  "teeth": {
    "11": { "status": "caries" },
    "21": { "status": "crown" },
    "18": { "status": "root_treated" }
  }
}
```

### Validation Rules

- `tooth_map_payload` — nullable, array (or JSON string decoded by `prepareForValidation`)
- `tooth_map_payload.teeth` — nullable array; keys must be valid FDI numbers (11–18,
  21–28, 31–38, 41–48); enforced in `withValidator()`
- `tooth_map_payload.teeth.*.status` — nullable, `in:normal,caries,missing,crown,root_treated`
- `summary_notes` — nullable, string, max:5000
- Invalid tooth numbers (e.g. `99`, `10`, `abc`) → error on `tooth_map_payload.teeth`
- Invalid status → error on `tooth_map_payload.teeth.{N}.status`

### UI Behaviour

- **Manager** (`manage_clinic_visits`): sees status selector (5 coloured buttons), tooth
  grid is clickable; clicking a tooth applies active status; clicking same status again
  removes the tooth entry; save form with `summary_notes` textarea and submit button.
- **Viewer** (`view_clinic_visits`): sees tooth grid in read-only mode (no selector, no
  save form, no save button); `summary_notes` displayed read-only if present.
- Both roles see the legend and full 32-tooth grid on page load.
- Alpine.js serialises `{ teeth: {...} }` into a hidden `tooth_map_payload` input
  reactively; standard form POST carries the JSON string; FormRequest decodes it.

### Permission

Reuses existing permissions — **no new permissions**:
- `view_clinic_visits` or `manage_clinic_visits` → open odontogram page
- `manage_clinic_visits` → update (tooth map + notes)
- Branch isolation via `BranchContext::requireId()` and `OdontogramPolicy` (unchanged)

### Tests (Phase 1.3.2 additions)

| Test | Description |
|---|---|
| `displays all 32 FDI tooth numbers` | All teeth 11–18, 21–28, 31–38, 41–48 visible on page |
| `manager can update tooth_map_payload via JSON string` | Full form-POST flow: JSON → decode → save → DB |
| `update rejects invalid tooth status` | `rotten` fails `in:` rule |
| `update rejects invalid FDI tooth number` | `99` fails whitelist |
| `update rejects tooth number out of FDI range` | `10` fails whitelist |
| `update rejects alphabetic tooth number` | `abc` fails whitelist |
| `viewer cannot update tooth_map_payload` | 403 for `view_clinic_visits` user |
| `cross-branch user cannot update tooth_map_payload` | 403 for cross-branch manager |
| `updating tooth map does not create duplicate odontogram` | Count stays 1 after update |
| `can update summary_notes and tooth_map_payload together` | Both fields persisted |

### Files Changed

| File | Change |
|---|---|
| `app/Modules/Odontogram/Requests/UpdateOdontogramPlaceholderRequest.php` | JSON decode, nested rules, FDI whitelist |
| `app/Modules/Odontogram/Services/OdontogramService.php` | Renamed `updateNotes` → `updatePlaceholder` |
| `app/Modules/Odontogram/Controllers/OdontogramController.php` | Calls `updatePlaceholder`, updated flash message |
| `resources/views/rme/visits/odontogram/show.blade.php` | Full tooth map UI (legend, selector, grid, form) |
| `tests/Feature/RME/OdontogramTest.php` | Updated 2 service tests, added 10 new tests |
| `docs/sprint_history.md` | This entry |

### Quality Gates

| Gate | Result |
|---|---|
| `php artisan test --filter=Odontogram` | **PASS — 35 tests, 98 assertions** |
| `php artisan test --filter=ClinicVisit` | **PASS — 37 tests, 126 assertions** |
| `php artisan route:list \| grep odontogram` | PASS — 2 routes registered |
| `./vendor/bin/pint --dirty` | PASS — 2 files fixed (spacing only) |

### Explicit Out-of-Scope

No canvas/SVG, no ICD-10/diagnosis codes, no treatment plan, no billing, no cicilan,
no lab workflow, no new permissions, no new roles, no schema changes, no inventory changes.

### Next Phase Suggestion

**Sprint 20 Phase 1.3.3 — Odontogram Status Polish & Finalize**
- Finalize odontogram (lock tooth map, change status from `draft` → `final`)
- Status badge progression (Draft → Final)
- Read-only display after finalization
- Optional: per-tooth notes field

### Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Tag | `sprint-20-phase-1-3-2-odontogram-tooth-map-draft-ui` |
| Completion date | 2026-06-10 |
| Status | COMPLETE |

---

## Sprint 20 Phase 1.3.3 — Odontogram Finalize

### Summary

Adds a finalize workflow to the Odontogram module. A draft odontogram can be locked by a Manager, transitioning it to `finalized` status. Once finalized, `tooth_map_payload` and `summary_notes` are permanently immutable. The UI shows a **Draft** or **Final** badge on the header and hides the save form after finalization.

### Scope

- New migration: `2026_06_10_200002_add_finalized_columns_to_trx_odontograms.php` — adds `finalized_at` (nullable timestamp) and `finalized_by` (nullable FK → `users`) to `trx_odontograms`.
- `Odontogram` model: `STATUS_FINALIZED` constant, `finalized_at`/`finalized_by` in `$fillable`, `finalized_at` datetime cast, `finalizer()` BelongsTo relation, `isFinalized(): bool` helper.
- `OdontogramRepositoryInterface` and `OdontogramRepository`: new `finalize(Odontogram, array): Odontogram` method.
- `OdontogramService::finalize()`: branch guard, idempotent (returns existing if already finalized), sets `status=finalized`, `finalized_at=now()`, `finalized_by`, `updated_by`.
- `OdontogramService::updatePlaceholder()`: rejects with `ValidationException` if odontogram is finalized.
- `OdontogramPolicy::finalize()`: requires `manage_clinic_visits` + same active branch.
- `OdontogramPolicy::update()`: returns `false` if odontogram is finalized (policy-level immutability).
- New route: `POST rme/odontograms/{odontogram}/finalize` → `rme.odontograms.finalize`.
- `OdontogramController::finalize()`: authorizes via policy, delegates to service, redirects with success message.
- `show.blade.php`: Draft/Final badge in header; finalize button (manager + draft only) with JS confirm; save form hidden when finalized; read-only notice banner when finalized; tooth grid and status selector remain reactive but `canEdit` is false after finalization.

### Status Workflow

```
draft  →  finalized
```

Transition is one-way. Once finalized, the odontogram cannot revert to draft. Calling `finalize()` on an already-finalized odontogram is idempotent (service returns existing record, no duplicate written).

### Permission Behaviour

| Action | Required permission | Extra condition |
|---|---|---|
| view | `view_clinic_visits` OR `manage_clinic_visits` | same active branch |
| update (save tooth map / notes) | `manage_clinic_visits` | same branch + not finalized |
| finalize | `manage_clinic_visits` | same active branch |

Cross-branch access is rejected with 403 at the policy layer. Viewer (`view_clinic_visits` only) can never finalize or update.

### Immutable Rule After Finalized

- `OdontogramPolicy::update()` returns `false` when `isFinalized()` is true → HTTP 403 on PATCH.
- `OdontogramService::updatePlaceholder()` additionally throws `ValidationException` with a domain message if called directly on a finalized odontogram.
- `finalized_at` and `finalized_by` are set exactly once; subsequent `finalize()` calls are no-ops.

### Tests (Phase 1.3.3 additions — 13 new tests, 48 total)

| Test | Coverage |
|---|---|
| finalize sets status, timestamp, user | service happy path |
| finalize is idempotent for already-finalized | service no-op guard |
| finalize service rejects cross-branch odontogram | branch isolation |
| updatePlaceholder throws for finalized odontogram | service immutability |
| manager can finalize draft odontogram | HTTP happy path |
| viewer cannot finalize | permission denial |
| user without permission cannot finalize | permission denial |
| cross-branch user cannot finalize | branch isolation HTTP |
| finalize does not duplicate odontogram | idempotency |
| finalized odontogram cannot update summary_notes via HTTP | HTTP immutability |
| finalized odontogram cannot update tooth_map_payload via HTTP | HTTP immutability |
| finalized odontogram page shows final badge | UI badge |
| draft odontogram page shows draft badge | UI badge |

All 35 Phase 1.3.1+1.3.2 tests retained and passing (48 total, 122 assertions).

### Out of Scope

- No canvas/SVG tooth map.
- No handwriting tablet.
- No treatment plan.
- No ICD-10 structured field.
- No PDF export.
- No lab workflow integration.
- No payment/billing.
- No per-tooth notes field.
- No new permissions or roles.
- HR, Inventory, Procurement, Payment, Cicilan, Lab Workflow untouched.

### Next Phase Suggestion

**Sprint 20 Phase 1.4 — Odontogram Extended / Treatment Plan**
- Per-tooth notes or multi-condition support
- Treatment plan linked to odontogram conditions
- Or: RME PDF export / print view

### Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Tag | `sprint-20-phase-1-3-3-odontogram-finalize` |
| Completion date | 2026-06-10 |
| Status | COMPLETE |

---

## Sprint 20 Phase 1.4 — Odontogram Per-Tooth Notes Foundation

### Summary

Adds a simple free-text note field to each tooth entry in `tooth_map_payload`. When a manager clicks a tooth on the interactive FDI map, a small panel appears below the grid showing the tooth number, its status badge, and an editable textarea (max 1 000 chars). The note is serialised inside the existing JSONB column — no schema migration is required. Finalized odontograms remain fully immutable.

### Scope

- **`UpdateOdontogramPlaceholderRequest`**: added `tooth_map_payload.teeth.*.note` rule (`nullable|string|max:1000`). FDI whitelist and status enum validation unchanged.
- **`OdontogramService::updatePlaceholder()`**: no changes required — `tooth_map_payload` is already passed through as an opaque array; the `note` field travels inside it.
- **`show.blade.php`**: Alpine.js `x-data` extended with `selectedTooth`, `toothNote`, `clickTooth()` (now sets `selectedTooth` before the `canEdit` guard so viewers can inspect too), `syncNote()` (keeps `toothNote` ↔ `teeth[key].note` in sync on every keypress). Per-tooth note panel rendered after the tooth grid, inside the Tooth Map card. Read-only users see the note as plain text; managers see a textarea. Tooth buttons now show `cursor-pointer` for both edit and read-only modes. No new JS dependencies.

### JSON Format

```json
{
  "teeth": {
    "11": {
      "status": "caries",
      "note": "Karies oklusal ringan"
    },
    "21": {
      "status": "crown",
      "note": ""
    }
  }
}
```

`note` is optional per-tooth. Existing payloads without `note` continue to work.

### Validation Rules

| Field | Rule |
|---|---|
| `tooth_map_payload` | `nullable\|array` |
| `tooth_map_payload.teeth` | `nullable\|array` |
| `tooth_map_payload.teeth.*` | `nullable\|array` |
| `tooth_map_payload.teeth.*.status` | `nullable\|string\|in:normal,caries,missing,crown,root_treated` |
| `tooth_map_payload.teeth.*.note` | `nullable\|string\|max:1000` |
| `summary_notes` | `nullable\|string\|max:5000` |
| tooth number keys | must be valid FDI numbers: 11–18, 21–28, 31–38, 41–48 |

### Permission Behaviour

| Action | Required permission | Extra condition |
|---|---|---|
| view (+ inspect note panel) | `view_clinic_visits` OR `manage_clinic_visits` | same active branch |
| save note | `manage_clinic_visits` | same branch + status draft |
| finalize | `manage_clinic_visits` | same active branch |

Viewer can click teeth to read notes but cannot edit. Cross-branch access is 403 at the policy layer.

### Immutable Finalized Rule

`OdontogramPolicy::update()` returns `false` for finalized odontograms → any PATCH (including one carrying a `note`) results in HTTP 403. `OdontogramService::updatePlaceholder()` additionally throws `ValidationException` if called directly on a finalized odontogram. No separate note-specific gate is needed.

### Tests (Phase 1.4 additions — 9 new tests, 57 total)

| Test | Coverage |
|---|---|
| manager can save per-tooth note | HTTP happy path |
| per-tooth note is persisted alongside status | DB persistence |
| per-tooth note of exactly 1000 chars is accepted | max boundary |
| per-tooth note exceeding 1000 chars is rejected | max boundary violation |
| per-tooth note can be null | nullable |
| viewer cannot update per-tooth note | permission denial |
| cross-branch user cannot update per-tooth note | branch isolation |
| finalized odontogram cannot update per-tooth note via HTTP | HTTP immutability |
| existing tooth status update still works after phase 1.4 | regression check |

All 48 Phase 1.3.1+1.3.2+1.3.3 tests retained and passing (57 total, 141 assertions).

### Out of Scope

- No canvas/SVG tooth map.
- No handwriting tablet.
- No treatment plan or treatment plan linking.
- No ICD-10 structured field.
- No PDF export.
- No lab workflow integration.
- No payment/billing.
- No new permissions or roles.
- No schema migration (note lives inside existing JSONB column).
- HR, Inventory, Procurement, Payment, Cicilan, Lab Workflow untouched.

### Next Phase Suggestion

**Sprint 20 Phase 1.5 — Odontogram Multi-Condition Per Tooth Foundation** *(completed)*

### Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Tag | `sprint-20-phase-1-4-odontogram-per-tooth-notes` |
| Completion date | 2026-06-10 |
| Status | COMPLETE |

---

## Sprint 20 Phase 1.5 — Odontogram Multi-Condition Per Tooth Foundation

### Summary

Extends `tooth_map_payload` with an optional `conditions` array per tooth. A manager can assign multiple clinical conditions (e.g. `["caries", "crown"]`) to a single tooth in addition to the existing primary `status`. On the interactive FDI map, the per-tooth panel gains a set of checkboxes (manager + draft) or read-only badge chips (viewer / finalized). No schema migration required — conditions live inside the existing JSONB column. Finalized odontograms remain fully immutable.

### Scope

- **`UpdateOdontogramPlaceholderRequest`**: added `tooth_map_payload.teeth.*.conditions` rule (`nullable|array`) and `tooth_map_payload.teeth.*.conditions.*` (`nullable|string|in:caries,missing,crown,root_treated,mobility,impaction,filling`). `withValidator` now also rejects duplicate values within the same tooth's `conditions` array. Existing FDI whitelist and status enum validation unchanged.
- **`OdontogramService::updatePlaceholder()`**: after `array_intersect_key`, iterates each tooth's `conditions` and applies `array_unique` + `array_filter` as a safety-net normalisation (duplicates from non-HTTP paths are silently deduplicated).
- **`show.blade.php`**: Alpine.js `x-data` extended with `hasCondition(condition)` and `toggleCondition(condition)` methods; `clickTooth()` now preserves `existingConditions` when a tooth status changes. Per-tooth panel gains a "Kondisi Tambahan" section rendered with `@foreach` over the 7 allowed conditions — checkboxes for managers on draft, teal badge chips for viewers / finalized. No new JS dependencies.

### JSON Format (target)

```json
{
  "teeth": {
    "11": {
      "status": "caries",
      "conditions": ["caries", "crown"],
      "note": "Karies oklusal ringan"
    }
  }
}
```

`conditions` is optional per tooth. Existing payloads without `conditions` continue to work. Primary `status` remains the source of truth for grid colour.

### Allowed Conditions

| Value | Label |
|---|---|
| `caries` | Karies |
| `missing` | Hilang |
| `crown` | Crown |
| `root_treated` | PSA |
| `mobility` | Mobility |
| `impaction` | Impaksi |
| `filling` | Tambalan |

Note: `mobility`, `impaction`, and `filling` are valid condition values but are **not** valid primary `status` values.

### Validation Rules

| Field | Rule |
|---|---|
| `tooth_map_payload` | `nullable\|array` |
| `tooth_map_payload.teeth` | `nullable\|array` |
| `tooth_map_payload.teeth.*` | `nullable\|array` |
| `tooth_map_payload.teeth.*.status` | `nullable\|string\|in:normal,caries,missing,crown,root_treated` |
| `tooth_map_payload.teeth.*.note` | `nullable\|string\|max:1000` |
| `tooth_map_payload.teeth.*.conditions` | `nullable\|array` |
| `tooth_map_payload.teeth.*.conditions.*` | `nullable\|string\|in:caries,missing,crown,root_treated,mobility,impaction,filling` |
| conditions uniqueness | duplicate values in same tooth's `conditions` array → validation error |
| `summary_notes` | `nullable\|string\|max:5000` |
| tooth number keys | must be valid FDI numbers: 11–18, 21–28, 31–38, 41–48 |

### Permission Behaviour

| Action | Required permission | Extra condition |
|---|---|---|
| view (+ inspect conditions panel) | `view_clinic_visits` OR `manage_clinic_visits` | same active branch |
| save conditions | `manage_clinic_visits` | same branch + status draft |
| finalize | `manage_clinic_visits` | same active branch |

Viewer can click teeth to read conditions but cannot check/uncheck. Cross-branch access is 403 at the policy layer.

### Finalized Immutable Rule

`OdontogramPolicy::update()` returns `false` for finalized odontograms → any PATCH (including one carrying `conditions`) results in HTTP 403. `OdontogramService::updatePlaceholder()` additionally throws `ValidationException` if called directly on a finalized odontogram. No separate conditions-specific gate is needed.

### Tests (Phase 1.5 additions — 12 new tests, 69 total)

| Test | Coverage |
|---|---|
| manager can save multiple conditions per tooth | HTTP happy path |
| conditions persist alongside existing status and note | DB persistence |
| conditions can be empty array | empty array valid |
| conditions can be null or omitted | nullable |
| duplicate conditions per tooth are rejected | validation denial |
| invalid condition value is rejected | validation denial |
| viewer cannot update conditions | permission denial |
| cross-branch cannot update conditions | branch isolation |
| finalized odontogram cannot update conditions via HTTP | HTTP immutability |
| updating conditions does not create duplicate odontogram | idempotency |
| existing tooth status update still works after phase 1.5 | regression check |
| filling and mobility are not valid status values | status enum boundary |
| existing note update still works after phase 1.5 | regression check |

All 57 Phase 1.3.x + 1.4 tests retained and passing (69+ total).

### Out of Scope

- No canvas/SVG tooth map.
- No handwriting tablet.
- No treatment plan or treatment plan linking.
- No ICD-10 structured field.
- No PDF export.
- No lab workflow integration.
- No payment/billing.
- No new permissions or roles.
- No schema migration (conditions live inside existing JSONB column).
- No final treatment plan generation.
- HR, Inventory, Procurement, Payment, Cicilan, Lab Workflow untouched.

### Next Phase Suggestion

**Sprint 20 Phase 1.6 — Odontogram Treatment Plan Link or PDF Export** *(completed)*

### Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Tag | `sprint-20-phase-1-5-odontogram-multi-condition` |
| Completion date | 2026-06-10 |
| Status | COMPLETE |

---

## Sprint 20 Phase 1.6 — Odontogram Print/PDF Export Foundation

### Summary

Adds a print-friendly HTML view for the odontogram. Any authorized user (viewer or manager) with the correct branch can open `rme/odontograms/{odontogram}/print` to see a standalone printable page, then print or save as PDF via the browser. The page displays full patient/visit/RM data, the 32-tooth FDI grid (colour-coded by status), a per-tooth condition and note table, and the odontogram summary notes. Because `barryvdh/laravel-dompdf` was already present in the project, a separate PDF export route was not added — the browser print-to-PDF path covers the use case without a new dependency.

### Scope

- **`OdontogramPolicy::print()`**: new ability — same rule as `view` (`view_clinic_visits` OR `manage_clinic_visits` + same active branch). Viewer can print; cross-branch is 403.
- **`OdontogramController::print()`**: authorizes via `print` policy, eager-loads `clinicVisit.patient`, `clinicVisit.doctor`, and `finalizer`, returns the print view.
- **`routes/web.php`**: `GET rme/odontograms/{odontogram}/print` → `rme.odontograms.print` inside the outer `view_clinic_visits|manage_clinic_visits` middleware group.
- **`show.blade.php`**: added "Cetak Odontogram" anchor link (opens in new tab) guarded by `@can('print', $odontogram)`. Visible to both viewer and manager.
- **`print.blade.php`**: standalone HTML page (no `x-settings-shell`, no heavy JS). CSS `@media print` hides the print/close button strip. Content includes:
  - App name + document title
  - Patient name, No. Rekam Medis (from `patient.medical_record_number`)
  - No. Kunjungan, Tanggal Kunjungan, Dokter
  - Status badge (Draft / Final) with finalized_at and finalizer name when finalized
  - Catatan Ringkasan (`summary_notes`)
  - Colour-coded FDI 32-tooth grid rendered in pure PHP/Blade (no Alpine.js)
  - Kondisi Per Gigi table: tooth number | status label | condition chips | note — only marked teeth shown
  - Legend for tooth status colours
  - Footer: app name, print timestamp, odontogram ID

### Route

| Method | URI | Name |
|---|---|---|
| GET | `rme/odontograms/{odontogram}/print` | `rme.odontograms.print` |

### Print View Content

| Section | Content |
|---|---|
| Header | App name, "Odontogram" title |
| Info grid | Pasien, No. RM, No. Kunjungan, Tanggal, Dokter, Status |
| Catatan Ringkasan | `summary_notes` or italicised placeholder |
| Legend | Six status colour swatches |
| Peta Gigi | 32-cell FDI grid (Q1/Q2 upper, Q4/Q3 lower) colour-coded by status |
| Kondisi Per Gigi | Table listing only marked teeth: status label + condition chips + note |
| Footer | App name, printed timestamp, odontogram ID + visit number |

### Permission Behaviour

| Action | Required permission | Extra condition |
|---|---|---|
| Open print view | `view_clinic_visits` OR `manage_clinic_visits` | same active branch |
| Cross-branch access | — | 403 |

No new permissions or roles introduced.

### Out of Scope

- No canvas/SVG tooth map rendering.
- No handwriting tablet.
- No treatment plan or treatment plan linking.
- No ICD-10 structured field.
- No new PDF library installed (browser print-to-PDF used instead).
- No lab workflow integration.
- No payment/billing.
- No schema migration.
- HR, Inventory, Procurement, Payment, Cicilan, Lab Workflow untouched.

### Tests (Phase 1.6 additions — 12 new tests)

| Test | Coverage |
|---|---|
| authorized manager can open print view | HTTP happy path |
| authorized viewer can open print view | HTTP happy path (viewer) |
| user without permission cannot open print view | permission denial |
| cross-branch user cannot open print view | branch isolation |
| print view displays patient name | content |
| print view displays visit number | content |
| print view displays odontogram status | content (finalized badge) |
| print view displays tooth status | content (status label) |
| print view displays tooth conditions | content (condition chip) |
| print view displays per-tooth note | content (note text) |
| print button appears on odontogram show page for manager | UI visibility (manager) |
| print button appears on odontogram show page for viewer | UI visibility (viewer) |

All 69 Phase 1.3.x–1.5 tests retained and passing.

### Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Tag | `sprint-20-phase-1-6-odontogram-print-view` |
| Completion date | 2026-06-10 |
| Status | COMPLETE |

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
28. **S20 Phase 1.3.1** — `Odontogram` placeholder 1:1 with `ClinicVisit` via unique
    `clinic_visit_id` FK; `trx_odontograms` branch-scoped; `createForClinicVisit()` is idempotent
    (find-or-create); `tooth_map_payload` reserved as nullable JSONB for future tooth-map data;
    reuses `view_clinic_visits`/`manage_clinic_visits` permissions (no new permissions); Viewer may
    open, Manager may update `summary_notes`; interactive chart deferred to Phase 1.3.2.

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
- **Sprint 20 Phase 1.3.1 completed baseline — Odontogram Placeholder Foundation:** Future changes
  must preserve `Odontogram` 1:1 with `ClinicVisit` (unique `clinic_visit_id` FK on
  `trx_odontograms`); `createForClinicVisit()` idempotent (find-or-create, never duplicate);
  `tooth_map_payload` as nullable JSONB (no clinical logic operating on it until Phase 1.3.2+);
  branch isolation via `BranchContext::requireId()` and `OdontogramPolicy`; `view_clinic_visits`/
  `manage_clinic_visits` as the permission pair (no new permissions); no canvas/SVG/interactive
  tooth map in this phase; interactive chart deferred to Phase 1.3.2.
- **Sprint 20 Phase 1.5 completed baseline — Odontogram Multi-Condition Per Tooth:** Future changes must preserve `tooth_map_payload.teeth.*.conditions` as a `nullable|array` field inside the existing JSONB column (no schema migration); `conditions` is optional per tooth — absence is valid; allowed values: `caries`, `missing`, `crown`, `root_treated`, `mobility`, `impaction`, `filling`; `mobility`, `impaction`, `filling` are NOT valid primary `status` values; duplicate conditions within a tooth are rejected by `UpdateOdontogramPlaceholderRequest` and also deduplicated by `OdontogramService`; primary `status` remains the grid-colour source of truth; finalized odontograms remain immutable; no new permissions or roles introduced; `conditions` editable only when status is `draft` and user has `manage_clinic_visits`.
- **Sprint 20 Phase 1.4 completed baseline — Odontogram Per-Tooth Notes:** Future changes must preserve `tooth_map_payload.teeth.*.note` as a `nullable|string|max:1000` field inside the existing JSONB column (no schema migration); `note` is optional per tooth — absence is valid; finalized odontograms remain immutable (policy + service layer both guard); no new permissions or roles introduced; `note` is editable only when status is `draft` and user has `manage_clinic_visits`; `summary_notes` and FDI whitelist rules unchanged.
- **Sprint 20 Phase 1.6 completed baseline — Odontogram Print View:** Future changes must preserve `rme.odontograms.print` as a GET route accessible by `view_clinic_visits` OR `manage_clinic_visits` (same active branch); `OdontogramPolicy::print()` mirrors the `view` rule — viewer may print, cross-branch is 403; `print.blade.php` is a standalone HTML page with no `x-settings-shell` and no new JS or PDF library dependencies; `@media print` hides the print/close button strip; no new permissions, roles, or schema changes introduced.
- **Sprint 20 Phase 1.7 completed baseline — RME Visit Print Bundle Foundation:** Future changes
  must preserve `rme.visits.print` as a GET route (`rme/visits/{clinicVisit}/print`) accessible by
  `view_clinic_visits` OR `manage_clinic_visits` (same active branch); `ClinicVisitPolicy::print()`
  mirrors the `view` rule — viewer may print, cross-branch is 403; `print.blade.php` is a
  standalone HTML page (no `x-settings-shell`, no new JS or PDF library); `@media print` hides the
  print/close button strip; bundle includes: patient name + medical_record_number, visit_number +
  visit_date + queue_number + doctor + chief_complaint, medical record (subjective / objective /
  assessment / plan / notes) with draft/final badge if present, odontogram summary (status badge,
  summary_notes, marked-tooth table with status+conditions+note) if present, or "belum tersedia"
  fallback for missing medical record / odontogram; "Cetak RME" button on visit show page gated by
  `@can('print', $visit)`; no new permissions, roles, or schema changes introduced; no treatment
  plan, no billing, no PDF library, no canvas/SVG, no handwriting tablet.
- **Sprint 20 Phase 1.7.1 — RME Test Stability Hardening:** No functional or behavioural change.
  Flaky test `finalize sets status to finalized` was caused by `OdontogramFactory::definition()`
  eagerly calling `ClinicVisit::factory()->create()` unconditionally — even when `clinic_visit_id`
  was explicitly overridden by the caller — creating a "ghost" `ClinicVisit` that competed for the
  same random `visit_number` space as the test's explicit visit; the globally-unique `visit_number`
  constraint (`trx_clinic_visits.visit_number UNIQUE`) then fired intermittently (birthday-paradox
  collision with `fake()->numberBetween(1, 999)`). Fix: (1) `OdontogramFactory` converted to lazy
  factory reference (`'clinic_visit_id' => ClinicVisit::factory()`) with a `configure/afterMaking`
  hook that derives `branch_id` from the linked visit when not explicitly supplied — eliminates
  ghost records entirely; (2) `ClinicVisitFactory` switched to `fake()->unique()->numberBetween(1,
  999)` so all factory calls within a single test get distinct queue numbers. Both changes are
  factory-only: no migrations, no service logic, no route/policy/permission changes, no test
  assertions altered. Full suite: 84 Odontogram + 46 ClinicVisit tests all pass.
- **Sprint 20 Phase 1.8 completed baseline — RME Initial Service + Full Handwriting:** Future changes
  must preserve: (A) `initial_treatment_id` (FK → `mst_treatments`, nullable, nullOnDelete) and
  `initial_service_note` (nullable text) columns on `trx_clinic_visits`; `initial_treatment_id` is
  **required** in `StoreClinicVisitRequest` and optional in `UpdateClinicVisitRequest`; only active
  treatments (`is_active = true`) are accepted; initial service is **triage/queue context only** —
  it must NOT create any invoice or payment record; `ClinicVisitController` passes `$treatments` to
  `create()` and `edit()` views; `MedicalRecordController::show()` eager-loads `initialTreatment`
  so the RME page can display it. (B) `trx_medical_record_handwritings` table with columns:
  `medical_record_id` (FK restrictOnDelete), `clinic_visit_id` (FK), `branch_id` (FK), `doctor_id`
  (nullable FK), `handwriting_path`, `handwriting_hash` (sha256, 64 chars), `saved_at`, `created_by`;
  route `rme.visits.medical-record.handwriting.store` (POST) gated by `manage_clinic_visits` +
  same active branch via `MedicalRecord` policy `update` action; handwriting stored to `public` disk
  at `handwritings/{branch_id}/{visit_id}/handwriting_{YmdHis}.png`; validation rejects missing /
  empty / non-PNG payloads (PNG magic bytes check: `\x89PNG`); finalized records are immutable
  (ValidationException); canvas UI (900×500, mouse + touch) with Clear + Save buttons lives in
  `medical-record/show.blade.php`; read-only preview shown for finalized or viewer-only state.
  (C) `MedicalRecordService` alignment helpers: `requiresHandwritingBeforeFinal()` → `true`,
  `hasRequiredHandwriting(MedicalRecord)` delegates to `$record->hasHandwriting()`,
  `canFinalizeRme(MedicalRecord)` → `false` if already final, `true` otherwise (Phase 1.9 will
  enforce handwriting before finalization). No cashier billing, no new permissions/roles introduced;
  full test suite: 205 RME tests all passing.
- **Sprint 20 Phase 1.3.3 completed baseline — Odontogram Finalize:** Future changes must preserve
  `draft → finalized` as a one-way, irreversible status transition; `finalize()` idempotent (returns
  existing record if already finalized, never duplicates); `finalized_at` and `finalized_by` set
  exactly once on first finalization; `OdontogramPolicy::update()` returns `false` when
  `isFinalized()` is true (policy-layer immutability); `OdontogramService::updatePlaceholder()`
  throws `ValidationException` for finalized odontograms (service-layer immutability); `finalize`
  action gated by `manage_clinic_visits` + same active branch; cross-branch finalize is 403;
  `view_clinic_visits`/`manage_clinic_visits` remain the only permission pair (no new permissions);
  no canvas/SVG, no per-tooth notes, no treatment plan, no PDF export in this phase.

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
`view_clinic_visits` / `manage_clinic_visits`) · `Odontogram` (S20 Phase 1.3.3–1.5, finalize
workflow + per-tooth notes + multi-condition, table `trx_odontograms`, status `draft→finalized`,
`finalized_at`/`finalized_by` columns, policy- and service-layer immutability after finalization,
32-tooth FDI interactive map, Draft/Final badge, per-tooth note panel (JSONB, max 1 000 chars),
per-tooth conditions array (7 allowed values: caries/missing/crown/root_treated/mobility/impaction/filling),
print view at `rme.odontograms.print` accessible by viewer + manager same-branch,
reuses `view_clinic_visits`/`manage_clinic_visits`) · `RME Visit Print Bundle` (S20 Phase 1.7,
route `rme.visits.print`, standalone HTML bundle with patient info + medical record + odontogram
summary, accessible by viewer + manager same-branch, `ClinicVisitPolicy::print()` mirrors view rule,
"Cetak RME" button on visit show page) · `RME Initial Service + Handwriting` (S20 Phase 1.8,
`initial_treatment_id`/`initial_service_note` on `trx_clinic_visits`, triage-only — no invoice;
`trx_medical_record_handwritings` table, canvas-based freehand handwriting stored to `public` disk
as PNG, `MedicalRecordHandwritingController`, alignment helpers on `MedicalRecordService` for
Phase 1.9 finalization gate).

**Roles:** Super Admin, Admin Lab, Admin Klinik, Technician, Quality Control, Delivery
Coordinator, Courier, Finance, Doctor. RME pilot: Admin Lab + Admin Klinik (full RME);
Doctor (`view_clinic_visits` + `manage_clinic_visits`); cashier via Admin Klinik
(`manage_rme_billing`). No Kasir role.

**Read before coding:** `docs/architecture_rules.md`, `docs/ai_development_guide.md`, this file,
the target module under `app/Modules`, relevant routes/policies/tests, and (branch work)
`app/Modules/Branch/Services/BranchContext.php`. Inventory invariants and the Future Sprint
Constraints above are binding.

---

## Sprint 20 Phase 1.12 — RME Limited Pilot Hardening & Documentation

### Summary

Sprint 20 RME is hardened and documented for a **single-branch limited pilot** covering admin/front
office, doctor, and cashier roles. End-to-end workflow is verified: visit creation → odontogram →
handwriting RME → finalization → cashier billing → full payment → receipt/print. No new features;
hardening, permission alignment, one missing test, and pilot documentation only.

### Final RME Workflow

1. **Admin/front office** creates visit with required initial service (triage context only).
2. **Doctor** fills odontogram (draft → finalize).
3. **Doctor** saves full handwriting RME (PNG canvas, mandatory before finalization).
4. **Doctor** finalizes RME → visit becomes `cashier_pending`.
5. **Cashier** (Admin Klinik or user with `manage_rme_billing`) creates final treatment invoice.
6. **Cashier** records **full payment only** → invoice `PAID`, visit `completed`.
7. **Staff** prints RME bundle, odontogram, invoice detail, or receipt via browser print.

### Route Summary (`rme.*`)

| Route name | Method | Permission |
|---|---|---|
| `rme.visits.*` | GET/POST/PATCH | `view_clinic_visits` / `manage_clinic_visits` |
| `rme.visits.transition` | POST | `manage_clinic_visits` |
| `rme.visits.medical-record.*` | GET/POST/PATCH | view / manage |
| `rme.visits.medical-record.finalize` | POST | `manage_clinic_visits` |
| `rme.visits.medical-record.handwriting.store` | POST | `manage_clinic_visits` |
| `rme.visits.odontogram.show` | GET | `view_clinic_visits` / `manage_clinic_visits` |
| `rme.odontograms.update` / `finalize` | PATCH/POST | `manage_clinic_visits` |
| `rme.odontograms.print` | GET | `view_clinic_visits` / `manage_clinic_visits` |
| `rme.visits.print` | GET | `view_clinic_visits` / `manage_clinic_visits` |
| `rme.medical-records.index` | GET | `view_clinic_visits` / `manage_clinic_visits` |
| `rme.cashier.*` | GET/POST | `manage_rme_billing` |
| `rme.cashier.payment.*` | GET/POST | `manage_rme_billing` |
| `rme.cashier.receipt.show` | GET | `manage_rme_billing` |

### Permission Summary

| Permission | Purpose |
|---|---|
| `view_clinic_visits` | Read-only RME/visit/odontogram/print |
| `manage_clinic_visits` | Create/update/finalize visits, RME, odontogram |
| `manage_rme_billing` | Cashier index, invoice, payment, receipt |

**Role assignments (pilot):**

| Role | RME permissions |
|---|---|
| Super Admin | all |
| Admin Lab | `view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing` |
| Admin Klinik | `view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing` |
| Doctor | `view_clinic_visits`, `manage_clinic_visits` (added Phase 1.12) |
| Finance | lab invoice/payment only — **no** `manage_rme_billing` |
| Kasir | role does not exist; **Admin Klinik** serves as cashier in pilot |

### Hardening Verified

- All `rme.*` routes permission-protected.
- Finalized RME immutable (service + policy + HTTP).
- Cashier cannot bill unfinalized RME (`RmeInvoiceService` + new test).
- Payment requires invoice items; full payment only (no partial/cicilan).
- Full payment → invoice `PAID`, visit `completed`.
- Initial service remains non-billing (no invoice/payment on create).
- Handwriting immutable after finalization.
- Branch isolation tests exist for billing, payment, finalization, visits, odontogram.
- Print pages (RME bundle, odontogram, invoice, receipt) tested — browser `window.print()` only.

### Tests & Quality Gates

```bash
php artisan migrate:fresh --seed
php artisan test --filter=RME          # 256 tests (Phase 1.12 +1)
php artisan test --filter=ClinicVisit
php artisan test --filter=MedicalRecord
php artisan test --filter=Odontogram
php artisan test --filter=CashierBilling
php artisan test --filter=RmePayment
./vendor/bin/pint --dirty
```

### Known Limitations (Pilot)

- **Browser print only** — no server-side PDF export; users save via browser print-to-PDF.
- **Full payment only** — partial payments and cicilan/installment deferred to Sprint 21+.
- **No lab order integration** from RME visit.
- **No RME dashboard** beyond existing visit widgets.
- **No Kasir role** — cashier duties via Admin Klinik + `manage_rme_billing`.
- **Single-branch pilot** — cross-branch analytics not in scope.

### Sprint 21 Recommendations

1. Dedicated **Kasir** role with `manage_rme_billing` only (least privilege).
2. Partial payment / cicilan workflow with outstanding balance tracking.
3. Lab order creation from finalized RME visit.
4. Server-side PDF export (optional DomPDF) for RME bundle and receipt.
5. RME operational dashboard (cashier queue, unpaid invoices, daily totals).
6. Owner/manager read-only RME reports (`view_clinic_visits` without manage).

### Release Information

| Field | Value |
|---|---|
| Branch | `feature/sprint-20-rme-core` |
| Tag | `sprint-20-rme-limited-pilot-complete` |
| Completion date | 2026-06-10 |
| Status | COMPLETE — limited pilot ready |

### Code Changes (Phase 1.12)

- `RoleSeeder`: Doctor role granted `view_clinic_visits` + `manage_clinic_visits`.
- `CashierBillingTest`: test for unfinalized RME billing rejection.
- `docs/sprint_20_rme_limited_pilot_summary.md`: pilot operator guide.
- `CLAUDE.md`: RME module quick reference.

### Sprint 20 Post-Completion — Pilot Backup Import Tooling

**Status:** COMPLETE. Branch `feature/sprint-20-rme-core`.

Safe pilot backup import tooling for RME limited pilot testing:

- Artisan command `rme:import-pilot-backup` with `--dry-run`, `--only`, `--limit`
- Whitelisted master data only: `mst_branches`, `mst_doctors`, `mst_patients`, `mst_lab_services` (mapped to treatment/tariff)
- No transaction restore; no roles/users/sessions; no old invoice/payment import
- Parser reads PostgreSQL COPY blocks line-by-line; never executes raw SQL from dump
- Guide: `docs/rme_pilot_backup_import_guide.md`

---

### Sprint 20 Post-Completion — Hide SOAP from Doctor RME UI

**Status:** COMPLETE. Branch `feature/sprint-20-rme-core`. Tag: `sprint-20-rme-hide-soap-doctor-ui`.

Sprint 20 pilot uses **handwriting RM** as the primary doctor-facing clinical input. SOAP fields
(`subjective`, `objective`, `assessment`, `plan`, `notes`) remain optional legacy structured fields
in `trx_medical_records` but are **hidden from the doctor-facing RME show page** — no editable
SOAP form, no empty SOAP labels on finalized records. Existing SOAP data is shown read-only only when
present (legacy import/history). Finalization still requires handwriting only; cashier billing unchanged.

---

### Sprint 20 Phase 2 — RME UI Modernization (TailAdmin Integration)

**Status:** COMPLETE. Branch `feature/ui-tailadmin-integration`.
**Tag:** `sprint-20-rme-ui-modernization-complete`
**Date:** 2026-06-11

All RME views modernized from raw Tailwind/Bootstrap-era HTML to TailAdmin-style components
(`x-ui.card`, `x-ui.table`, `x-ui.badge`, `x-ui.button`, `x-settings-shell`).

#### Phases Completed

| Phase | Focus | Commit | Tag |
|---|---|---|---|
| 2B | Visit/doctor views (index, show, medical-record index/show, odontogram show) | `a542ad2` | `sprint-20-rme-ui-modernization-phase-2b` |
| 2C.1 | Cashier queue/index | `f6c022f` | `sprint-20-rme-ui-modernization-phase-2c-1-cashier-index` |
| 2C.2 | Cashier billing detail/show | `0b0bef4` | `sprint-20-rme-ui-modernization-phase-2c-2-cashier-show` |
| 2C.3 | Cashier billing create | `23b2f46` | `sprint-20-rme-ui-modernization-phase-2c-3-cashier-create` |
| 2C.4 | Cashier payment create | `ad7c1f4` | `sprint-20-rme-ui-modernization-phase-2c-4-payment-create` |
| 2C.5 | Cashier receipt | `1d8da61` | `sprint-20-rme-ui-modernization-phase-2c-5-receipt` |
| 2C.6 | Final audit & documentation | — | `sprint-20-rme-ui-modernization-complete` |

#### Audit Findings (Phase 2C.6)

- No legacy `bg-indigo`, `text-indigo`, `ring-indigo`, `sm:rounded-lg` in RME views after audit.
- One missed `text-indigo-700` on Grand Total cell in `cashier/show.blade.php` corrected to `text-blue-700`.
- Raw `<table>` in `cashier/receipt/show.blade.php` receipt body: intentional — print-friendly layout.
- Raw `<button>` in `visits/show.blade.php` workflow transitions: intentional — dynamic PHP class interpolation required.

#### Preservation Notes

- Business logic, controllers, services, models, routes, policies unchanged.
- Form field names, route names, permission gates unchanged.
- Handwriting preview present on show and print pages.
- SOAP hidden from doctor UI (legacy data shown read-only only when present).
- Odontogram Alpine component intact.
- Print/receipt pages remain print-friendly (`@media print` isolation, `#receipt-body`).

#### Quality Gates

- `php artisan test`: **1842 passed, 6290 assertions**
- `./vendor/bin/pint --dirty`: passed (no changes)
- `npm run build`: success

---

### Sprint 20 Final Closure — RME Core + UI Modernization

**Status:** CLOSED
**Branch:** `feature/sprint-20-rme-core`
**Final merge commit:** `8246008` (Merge TailAdmin UI modernization into Sprint 20 core)
**Merge tag:** `sprint-20-rme-ui-modernization-merged`
**Final closure tag:** `sprint-20-rme-core-ui-complete`
**Date:** 2026-06-11

Sprint 20 is formally closed. The UI modernization branch (`feature/ui-tailadmin-integration`,
tag `sprint-20-rme-ui-modernization-complete`) was merged into `feature/sprint-20-rme-core`
at commit `8246008`. The `feature/sprint-20-rme-core` branch is the single authoritative
source for future deployment.

#### Full Scope Delivered

| Area | Result |
|---|---|
| RME core workflow (phases 1.2–1.12) | COMPLETE |
| Pilot hardening (SOAP hide, queue lock, Alpine fix, import tooling, handwriting previews) | COMPLETE |
| UI modernization — inventory views (TailAdmin) | COMPLETE |
| UI modernization — RME visit/doctor views | COMPLETE |
| UI modernization — RME cashier views (index, create, show, payment, receipt) | COMPLETE |
| Final UI audit (Phase 2C.6) | COMPLETE |

#### Test Results (final merge validation)

| Suite | Result |
|---|---|
| Full test suite (`php artisan test`) | **1842 passed, 6290 assertions** |
| RME suite (`php artisan test --filter=RME`) | **283 passed, 718 assertions** |
| Pint (`./vendor/bin/pint --dirty`) | Passed — no changes |
| npm run build | Success |

#### Deployment Note

No VPS deployment was performed in this phase. The branch is ready for single-branch pilot
deployment pending UAT sign-off. Full deployment recommendation in
`docs/sprint_20_final_closure_report.md`.

#### Sprint 21 Backlog (documented, not started)

- Lab integration for RME treatments requiring lab work
- PDF export for RME bundle / receipt
- Cicilan / installment payment workflow
- Dedicated Kasir role with least-privilege `manage_rme_billing`
- Owner RME analytics dashboard
- WhatsApp / notification integration
- Multi-branch pilot hardening
- Production VPS deployment checklist

---

*Historical record only — this document changes no application code. It reflects decisions as of
Sprint 20 Final Closure (2026-06-11) and must be updated as each new sprint completes.*

---

### Sprint 21 Planning — RME Advanced Workflow + Pilot Deployment

**Status:** PLANNING
**Branch:** `feature/sprint-21-planning`
**Planning doc:** `docs/sprint_21_planning.md`
**Date:** 2026-06-11

Sprint 21 planning branch created from `feature/sprint-20-rme-core` at tag
`sprint-20-rme-core-ui-complete`. No feature code added in this phase — documentation only.

**Sprint 21 theme:** RME Advanced Workflow + Pilot Deployment

**Planned phases:**

| Phase | Focus |
|---|---|
| 21.1 | RME → Lab Integration Architecture (design only) |
| 21.2 | Lab Order Generation from paid RME invoice (tests-first) |
| 21.3 | RME PDF Export |
| 21.4 | Cicilan / Installment Payment Design (approval-gated) |
| 21.5 | Owner Dashboard RME Analytics |
| 21.6 | WhatsApp / Notification Planning (templates only) |
| 21.7 | Multi-Branch Pilot Hardening |
| 21.8 | VPS Pilot Deployment Checklist |

**Recommended first implementation phase:** 21.1 design → 21.2 lab integration (tests-first).

**Key constraints carried forward from Sprint 20:**
- SOAP doctor UI remains hidden — handwriting RM is primary clinical input.
- Full-payment-only rule remains in force until Phase 21.4 is explicitly approved.
- No `migrate:fresh` on VPS.
- Branch isolation mandatory on all new RME-linked records.

---

### Sprint 21 Phase 21.1 — RME → Lab Integration Architecture

**Status:** COMPLETE
**Branch:** `feature/sprint-21-rme-lab-architecture`
**Tag:** `sprint-21-phase-21-1-rme-lab-architecture`
**Document:** `docs/sprint_21_rme_lab_integration_architecture.md`
**Date:** 2026-06-11
**Type:** Design / Documentation only — no behavior changes

**Purpose:** Produce a written integration architecture before any code is written, covering
trigger point, creation strategy, data mapping, duplicate prevention, branch isolation, audit
trail, error handling, and pilot recommendation.

**Key decisions:**

| Decision | Outcome |
|---|---|
| Integration trigger | After `RmePaymentService::pay()` sets `trx_rme_invoices.status = PAID` |
| Creation strategy | `LabCaseCandidate` staging table first (not direct LabOrder) |
| Eligibility filter | `mst_treatments.requires_lab = true` (column already exists in migration) |
| Idempotency | `UNIQUE(rme_invoice_item_id)` on `trx_lab_case_candidates` |
| Transaction strategy | Payment commits first; candidate generation is post-commit |
| Branch isolation | `branch_id` from RME invoice; validated against `BranchContext::requireId()` |
| Lab payment bleed | No `trx_payments` (lab billing) records from RME payment — enforced |
| LabOrder mapping gap | `LabOrderItem.lab_service_id` vs `RmeInvoiceItem.treatment_id` — no mapping exists; deferred to Phase 21.2 |

**Phase 21.2 readiness:** unblocked pending project owner approval of architecture document.

*No application code, migrations, routes, views, or tests were changed in this phase.*

---

### Sprint 21 Phase 21.2 — RME → Lab Case Candidate Generation

**Status:** COMPLETE
**Branch:** `feature/sprint-21-lab-case-candidates`
**Tag:** `sprint-21-phase-21-2-lab-case-candidates`
**Date:** 2026-06-11
**Type:** Implementation (tests-first)

**Purpose:** Implement the first functional RME → Lab integration layer. When a patient's RME
invoice is fully paid, items whose linked treatment has `requires_lab = true` automatically
create `trx_lab_case_candidates` staging records. No real `LabOrder` is created in this phase
— candidates wait for Admin Lab review before conversion.

**Files added:**

| File | Role |
|---|---|
| `database/migrations/2026_06_14_210001_create_trx_lab_case_candidates_table.php` | New staging table |
| `app/Modules/LabOrder/Models/LabCaseCandidate.php` | Eloquent model, status constants, relationships |
| `database/factories/LabCaseCandidateFactory.php` | Factory for tests |
| `app/Modules/RmeInvoice/Services/RmeLabIntegrationService.php` | Generation service |
| `tests/Feature/RME/LabIntegrationTest.php` | 11 integration tests |

**Files modified:**

| File | Change |
|---|---|
| `app/Modules/RmeInvoice/Services/RmePaymentService.php` | Post-commit hook to `RmeLabIntegrationService` |
| `docs/sprint_21_planning.md` | Phase 21.2 marked complete |
| `docs/sprint_21_rme_lab_integration_architecture.md` | Phase 21.2 implementation note |
| `docs/sprint_history.md` | This entry |
| `CLAUDE.md` | Phase 21.2 memory entry |

**Data model:** `trx_lab_case_candidates`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `branch_id` | FK → mst_branches | Mandatory; enforces branch isolation |
| `clinic_visit_id` | FK → trx_clinic_visits | Source visit |
| `rme_invoice_id` | FK → trx_rme_invoices | Source invoice |
| `rme_invoice_item_id` | FK → trx_rme_invoice_items, UNIQUE | Idempotency key |
| `patient_id` | FK → mst_patients | |
| `doctor_id` | FK → mst_doctors, nullable | Item-level doctor, else visit-level |
| `treatment_id` | FK → mst_treatments, nullable | Carries requires_lab treatment reference |
| `medical_record_id` | FK → trx_medical_records, nullable | |
| `source_description` | string | Cashier-entered item description |
| `quantity` | unsigned int | From invoice item qty |
| `estimated_price` | decimal(15,2) | From invoice item unit_price |
| `status` | string | pending_review / converted_to_lab_order / rejected / cancelled |
| `converted_lab_order_id` | unsigned bigint, nullable | Set when Admin Lab converts to LabOrder |
| `reviewed_by` | FK → users, nullable | Admin Lab reviewer |
| `reviewed_at` | timestamp, nullable | |
| `notes` | text, nullable | |
| `metadata` | json, nullable | Extensible; can store odontogram id, invoice_number, etc. |
| `created_by` | FK → users, nullable | Cashier who triggered payment |
| `timestamps` | | |
| `deleted_at` | softDeletes | |

**Service behavior (`RmeLabIntegrationService`):**
- `generateForPaidInvoice(RmeInvoice $invoice, ?User $actor)`: iterates invoice items, skips items
  with `requires_lab = false` or null treatment, calls `generateForInvoiceItem` for each eligible.
- `generateForInvoiceItem(RmeInvoiceItem $item, ...)`: uses `firstOrCreate` keyed on
  `rme_invoice_item_id` — idempotent, duplicate-safe.
- Branch isolation: validates `invoice.branch_id === BranchContext::id()`; throws
  `ValidationException` if mismatch while a branch context is active.
- Returns `Collection<LabCaseCandidate>`.

**Payment hook behavior:**
- `RmePaymentService::pay()` now assigns the transaction result to `$payment` instead of returning
  it directly.
- After the transaction commits, calls `RmeLabIntegrationService::generateForPaidInvoice()` in a
  `try/catch(\Throwable)`.
- If generation fails: `Log::warning(...)` — payment is NOT rolled back.
- Returns the `$payment` object unchanged.

**Duplicate prevention:** `UNIQUE(rme_invoice_item_id)` database constraint + `firstOrCreate`
service semantics. Safe to call multiple times with the same invoice.

**Branch isolation:** Every candidate carries `branch_id` from the source invoice. Cross-branch
generation throws `ValidationException`. Tests 7 and 8 cover this.

**Sprint 20 preservation:**
- Full-payment-only rule: unchanged — partial payment still throws `ValidationException`.
- `trx_payments` (lab billing): not created by RME payment.
- No real `LabOrder` created in this phase.
- SOAP doctor UI: remains hidden.
- `trx_rme_invoices` total/payment calculation: unchanged.

**Test results:**

| Suite | Result |
|---|---|
| `php artisan test --filter=LabIntegration` | **11 passed, 24 assertions** |
| `php artisan test --filter=RmePayment` | **16 passed, 24 assertions** |
| `php artisan test --filter=CashierBilling` | **17 passed, 28 assertions** |
| `php artisan test --filter=RME` | **294 passed, 742 assertions** |
| `php artisan test` (full suite) | **1853 passed** |
| `./vendor/bin/pint --dirty` | Passed — no changes |
| `npm run build` | Success |

---

## Sprint 21 Phase 21.3 — Admin Lab Candidate Queue UI

**Date:** 2026-06-11
**Branch:** `feature/sprint-21-lab-candidate-queue`
**Tag:** `sprint-21-phase-21-3-lab-candidate-queue`
**Base:** `bd047fe` (Sprint 21.2)

### Goal

Read-only Admin Lab queue UI for `LabCaseCandidate` records generated from paid RME invoices.

### What changed

- Added `LabCaseCandidatePolicy` (viewAny + view with branch isolation)
- Registered policy in `RepositoryServiceProvider`
- Added `LabCaseCandidateController` (index + show, branch-scoped, eager-loaded)
- Routes: `GET /lab/case-candidates` and `GET /lab/case-candidates/{candidate}`
- Views: `resources/views/lab/case-candidates/index.blade.php` and `show.blade.php`
- Sidebar: "Kandidat Lab RME" menu item gated by `view_lab_orders | manage_lab_orders`

### Authorization

- Reuses existing `view_lab_orders` / `manage_lab_orders` permissions — no new permissions added.
- Branch isolation: Policy enforces `candidate->branch_id === BranchContext::forUser($user)`.

### Phase boundaries preserved

- No `LabOrder` records created.
- No `LabOrderItem` records created.
- No convert-to-LabOrder action (Phase 21.4).
- No RME payment hook modified.
- No candidate generation logic changed.

**Test results:**

| Suite | Result |
|---|---|
| `php artisan test --filter=LabCaseCandidateQueue` | **12 passed, 26 assertions** |
| `php artisan test --filter=LabIntegration` | **11 passed, 24 assertions** |
| `php artisan test --filter=RmePayment` | **16 passed, 24 assertions** |
| `php artisan test --filter=RME` | **306 passed** |
| `php artisan test` (full suite) | All passed |
| `./vendor/bin/pint --dirty` | Passed |
| `npm run build` | Success |

---

## Sprint 21 Phase 21.4 — Convert LabCaseCandidate to LabOrder

**Date:** 2026-06-11
**Branch:** `feature/sprint-21-candidate-to-laborder`
**Tag:** `sprint-21-phase-21-4-candidate-to-laborder`
**Base:** `0eed855` (Sprint 21.3)

### Goal

Explicit manual conversion from `LabCaseCandidate` to `LabOrder` after Admin Lab review.
RME payment continues to generate candidates only.

### What changed

- Added `LabCaseCandidateConversionService` — idempotent, branch-scoped, transaction-safe
- Added `ConvertLabCaseCandidateRequest` — requires explicit `lab_service_id` and `due_date`
- Extended `LabCaseCandidate` model with `isPendingReview()`, `isConverted()`, `canConvert()`, `convertedLabOrder()`
- Extended `LabCaseCandidatePolicy` with `convert` (reuses `create_lab_orders` / `manage_lab_orders`)
- Added `POST /lab/case-candidates/{candidate}/convert` route
- Show page: conversion form for authorized pending candidates; link to converted Lab Order

### Lab service mapping rule

- `lab_service_id` must be selected explicitly by Admin Lab.
- No automatic mapping from RME `treatment_id` to `lab_service_id`.

### Phase boundaries preserved

- RME payment does not create `LabOrder`.
- No lab invoice/payment records created during conversion.
- Full-payment-only RME rule unchanged.
- SOAP doctor UI unchanged.

**Test results:**

| Suite | Result |
|---|---|
| `php artisan test --filter=LabCaseCandidateConversion` | **16 passed, 39 assertions** |
| `php artisan test --filter=LabCaseCandidateQueue` | **12 passed** |
| `php artisan test --filter=LabIntegration` | **11 passed** |
| `php artisan test --filter=RME` | All passed |
| `php artisan test` (full suite) | All passed |
| `./vendor/bin/pint --dirty` | Passed |
| `npm run build` | Success |

---

## Sprint 21 Phase 21.5 — RME Lab Workflow Polish

**Date:** 2026-06-11
**Branch:** `feature/sprint-21-rme-lab-workflow-polish`
**Tag:** `sprint-21-phase-21-5-rme-lab-workflow-polish`
**Base:** `cb68615` (Sprint 21.4)

### Goal

Improve workflow visibility across the full RME → Lab Candidate → LabOrder path without changing payment, generation, or conversion business rules.

### What changed

- RME cashier invoice show + receipt: read-only **Status Pekerjaan Lab RME** / **Kandidat Lab RME** panels (counts, status, authorized links)
- Lab case candidate index: Lab Order column with order number link when converted; model status helpers
- Lab case candidate show: linked RME invoice, visit number, conversion metadata, pending state
- Lab order show: **Sumber RME** section when order originated from a candidate
- Model relations: `RmeInvoice::labCaseCandidates()`, `LabOrder::rmeLabCaseCandidate()`
- Reusable Blade partial: `components/rme/lab-workflow-panel`

### Phase boundaries preserved

- No RME payment rule changes
- No candidate generation rule changes
- No conversion business rule changes
- No auto LabOrder from RME payment
- No lab invoice/payment records
- No PDF, cicilan, WhatsApp, or SOAP doctor UI changes

**Test results:**

| Suite | Result |
|---|---|
| `php artisan test --filter=RmeLabWorkflowPolish` | **16 passed, 59 assertions** |
| `php artisan test --filter=LabCaseCandidateConversion` | **16 passed** |
| `php artisan test --filter=LabCaseCandidateQueue` | **12 passed** |
| `php artisan test --filter=LabIntegration` | **11 passed** |
| `php artisan test --filter=RME` | All passed |
| `php artisan test` (full suite) | All passed |
| `./vendor/bin/pint --dirty` | Passed |
| `npm run build` | Success |

---

## Sprint 21 Phase 21.6 — RME PDF Export / Print Hardening

**Date:** 2026-06-11
**Branch:** `feature/sprint-21-rme-pdf-print-hardening`
**Tag:** `sprint-21-phase-21-6-rme-pdf-print-hardening`
**Base:** `243eb78` (Sprint 21.5)

### Goal

Harden RME visit print and receipt print for pilot deployment. Add PDF download via existing DomPDF package.

### What changed

- RME visit print: branch, clinic, initial treatment, finalized RM metadata, handwriting, odontogram, paid invoice/payment, lab workflow summary; legacy SOAP hidden
- RME visit PDF: `rme.visits.pdf` route using `barryvdh/laravel-dompdf`
- RME receipt print: lab workflow panel no longer `print:hidden`
- Shared partial `rme/visits/partials/print-body.blade.php`

### Phase boundaries preserved

- No RME payment, candidate generation, or conversion changes
- No auto LabOrder, lab billing, cicilan, WhatsApp, or SOAP doctor UI
- No migrations

**Test results:**

| Suite | Result |
|---|---|
| `php artisan test --filter=RmePdfPrintHardening` | **21 passed, 71 assertions** |
| `php artisan test --filter=RmeLabWorkflowPolish` | **16 passed** |
| `php artisan test` (full suite) | **1918 passed** |
| `./vendor/bin/pint --dirty` | Passed |
| `npm run build` | Success |

---

## Sprint 21 Phase 21.7 — VPS Pilot Deployment Checklist

**Date:** 2026-06-11
**Branch:** `feature/sprint-21-vps-pilot-checklist`
**Tag:** `sprint-21-phase-21-7-vps-pilot-checklist`
**Base:** `327e55f` (Sprint 21.6)
**Document:** `docs/sprint_21_vps_pilot_deployment_checklist.md`

### Goal

Safe VPS pilot deployment runbook for Sprint 21 — backup, git pull, `migrate --force`, permissions, smoke tests, rollback. Documentation only.

### What changed

- New runbook: `docs/sprint_21_vps_pilot_deployment_checklist.md`
- Updated `docs/sprint_21_planning.md` (Phase 21.7 note)
- Updated `CLAUDE.md` (Phase 21.7 memory)

### Phase boundaries preserved

- **Type:** documentation / runbook only
- **No application behavior changes**
- **No deployment performed** — no SSH, no VPS migrations, no production commands
- **No `migrate:fresh` / `db:wipe` on VPS** rule reinforced in runbook

### Deployment target (when runbook is executed)

| Item | Value |
|---|---|
| VPS path | `/var/www/asia-dental-lab-v2` |
| Branch | `feature/sprint-21-rme-pdf-print-hardening` |
| Tag | `sprint-21-phase-21-6-rme-pdf-print-hardening` |
| Commit | `327e55f` or newer approved deploy commit |

---

## Sprint 21 Phase 21.8 — Sprint 21 Closure / Release Candidate Merge Plan

**Date:** 2026-06-11
**Branch:** `feature/sprint-21-closure-rc-plan`
**Tag:** `sprint-21-phase-21-8-closure-rc-plan`
**Base:** `18d2eec` (Sprint 21.7)
**Document:** `docs/sprint_21_closure_release_candidate_plan.md`

### Goal

Close Sprint 21 and document the release candidate merge/deployment plan — deliverables summary, RC baseline, merge order, VPS deployment order, rollback strategy, and post-merge verification.

### What changed

- New closure plan: `docs/sprint_21_closure_release_candidate_plan.md`
- Updated `docs/sprint_21_planning.md` (Phase 21.8 note)
- Updated `CLAUDE.md` (Phase 21.8 memory)
- Updated `docs/sprint_history.md` (this entry)

### Phase boundaries preserved

- **Type:** documentation / release planning only
- **No application behavior changes**
- **No merge performed** — `main` and `feature/sprint-20-rme-core` not merged
- **No deployment performed** — no SSH, no VPS migrations, no production commands
- RC baseline, rollback, and deployment order documented for stakeholder review

### RC baseline documented

| Item | Value |
|---|---|
| Functional RC branch | `feature/sprint-21-vps-pilot-checklist` |
| Functional RC commit | `18d2eec` |
| Functional RC tag | `sprint-21-phase-21-7-vps-pilot-checklist` |
| Closure branch | `feature/sprint-21-closure-rc-plan` |
| Deferred RC tag | `sprint-21-release-candidate` (after approval) |
| Pre-Sprint 21 rollback | `sprint-20-rme-core-ui-complete` / `48c9fe6` |

### VPS deployment reference

Follow `docs/sprint_21_vps_pilot_deployment_checklist.md` with updated baseline `18d2eec`. Never `migrate:fresh` or `db:wipe` on VPS.

---

## Sprint 22 Phase 22.0 — Planning & Baseline

**Date:** 2026-06-11
**Branch:** `feature/sprint-22-planning`
**Tag:** `sprint-22-planning`
**Base:** `3ef3fd6` (Sprint 21.9 Kasir RME sidebar menu hotfix)
**Document:** `docs/sprint_22_planning.md`

### Goal

Plan Sprint 22 as the pilot stabilization sprint after the Sprint 21 RME Advanced Workflow release was deployed to the Hostinger VPS pilot.

### Baseline

| Item | Value |
|---|---|
| Release branch | `release/sprint-21-rme-advanced-workflow` |
| Latest hotfix commit | `3ef3fd6` - Add Kasir RME sidebar menu hotfix |
| Sprint 21 RC tag | `sprint-21-release-candidate` |
| Sprint 21.9 hotfix tag | `sprint-21-phase-21-9-cashier-rme-sidebar-hotfix` |
| VPS pilot status | Deployed and live |

### What changed

- Added Sprint 22 planning document: `docs/sprint_22_planning.md`
- Updated `CLAUDE.md` with Sprint 22 current focus memory
- Updated `docs/sprint_history.md` with this Phase 22.0 entry

### Sprint 22 planned focus

- Pilot role, permission, and sidebar hardening for RME, cashier, lab candidates, owner, and admin users
- Safe RME end-to-end smoke-test data and workflow checks
- RME cashier, payment, receipt, and PDF/print stabilization
- RME paid invoice to `LabCaseCandidate` validation and manual candidate conversion checks
- Read-only Owner Dashboard foundation and KPI detail
- VPS pilot hardening and Sprint 22 release candidate preparation

### Phase boundaries preserved

- **Type:** documentation / planning only
- **No application behavior changes**
- **No routes, controllers, services, models, migrations, policies, Blade UI, seeders, tests, or dependency files changed**
- RME payment behavior unchanged: full payment only
- RME payment does not create `LabOrder`
- `LabCaseCandidate` remains the staging layer
- RME payment does not create lab payment records
- Doctor SOAP UI remains hidden; handwriting RM remains primary
- Owner Dashboard planned as read-only for Sprint 22
- VPS rule reinforced: never `migrate:fresh` or `db:wipe` on VPS

### Verification

Docs-only lightweight verification planned:

| Command | Purpose |
|---|---|
| `git status --short` | Confirm changed files |
| `git diff --stat` | Confirm docs-only scope |
| `git diff -- docs/sprint_22_planning.md docs/sprint_history.md CLAUDE.md` | Review Sprint 22 documentation diff |

Heavy test suites were intentionally not planned for Phase 22.0 because no application code changes are included.

---

## Sprint 22 Phase 22.1 — Pilot Role/Permission/Menu Hardening

**Branch:** `feature/sprint-22-role-permission-menu-hardening`
**Tag:** `sprint-22-phase-22-1-pilot-role-permission-menu-hardening`
**Type:** Implementation — pilot access hardening only

### Objective

Harden pilot-facing roles, permissions, route guards, and sidebar/menu visibility so RME, cashier, lab, owner, and operational users only see and access features appropriate to their role during VPS pilot testing.

### Permissions added

| Permission | Purpose |
|---|---|
| `view_owner_dashboard` | Explicit read-only owner landing dashboard access |
| `view_branch_dashboard` | Reserved explicit branch-dashboard permission (seeded; assignment starts with Admin Lab / Admin Klinik) |

### Roles hardened / added

| Role | Pilot permissions (summary) |
|---|---|
| **Owner** (new) | Read-only executive: reports, owner dashboard, read-only RME visits, inventory executive/cross-branch analytics; no manage/operational writes |
| **Kasir** (new) | `view_clinic_visits` + `manage_rme_billing` only (least-privilege cashier) |
| **Perawat** (new) | Clinic front-desk: `manage patients`, `view/manage_clinic_visits`; no cashier or lab access |
| **Doctor** | Clinical only; removed `view_lab_orders`; no `manage_rme_billing` |
| **Admin Klinik** | Added `view_clinic_master_data`, `view_branch_dashboard` |
| **Admin Lab** | Added `view_branch_dashboard` (unchanged operational superset) |

### Route / menu hardening

- `/dashboard` now requires `view dashboard` or `view_owner_dashboard` (auth + verified preserved).
- Sidebar **Dasbor** link gated with the same permissions.
- Dashboard shell now distinguishes **Owner**, **Admin Cabang**, and **clinic operational** users; clinic-only roles get a lightweight operational landing with RME shortcuts.
- Existing `rme.*`, `lab-case-candidates.*`, and module permission middleware preserved; no payment/generation/conversion behavior changed.

### Files changed

- `database/seeders/PermissionSeeder.php`
- `database/seeders/RoleSeeder.php`
- `routes/web.php`
- `resources/views/layouts/partials/sidebar.blade.php`
- `resources/views/dashboard.blade.php`
- `tests/Pest.php` (`userInRole` helper)
- `tests/Feature/Auth/RolePermissionHardeningTest.php` (new)
- `tests/Feature/Navigation/SidebarPermissionVisibilityTest.php` (new)
- `tests/Feature/Pilot/PilotRouteAuthorizationTest.php` (new)
- `tests/Feature/Dashboard/OwnerDashboardUiTest.php`
- `tests/Feature/Dashboard/BranchAdminDashboardUiTest.php`
- `tests/Feature/RME/ClinicVisitTest.php`
- `tests/Feature/Inventory/InventoryPermissionHardeningTest.php`
- `tests/Feature/Inventory/InventoryActivityLogTest.php`
- `tests/Feature/Inventory/InventoryBatchTest.php`
- `tests/Feature/Inventory/InventoryExecutiveDashboardUiTest.php`
- `tests/Feature/Inventory/InventoryReportTest.php`
- `tests/Feature/Inventory/ProductCategoryCrudTest.php`
- `tests/Feature/ClinicMasterData/WaReminderTemplateTest.php`
- `tests/Feature/AccessControl/RoleManagementTest.php`
- `tests/Feature/Reporting/ReportPermissionTest.php`
- `docs/sprint_history.md`

### Tests run

```bash
php artisan optimize:clear
php artisan test --filter=RolePermissionHardening      # 8 passed
php artisan test --filter=SidebarPermissionVisibility  # 6 passed
php artisan test --filter=PilotRouteAuthorization      # 6 passed
php artisan test --filter=RME                          # 366 passed
php artisan test --filter=Permission                   # 149 passed
php artisan test --filter=Authorization                # 50 passed
./vendor/bin/pint --dirty
php artisan test                                       # 1940 passed
```

### Preserved Sprint 21 / pilot boundaries

- No RME payment behavior changes (full payment only).
- No auto `LabOrder` from RME payment; `LabCaseCandidate` staging unchanged.
- No lab payment records from RME payment.
- SOAP doctor UI remains hidden; handwriting-first RM unchanged.
- No database schema migrations.

### Follow-up items for Phase 22.2

1. RME end-to-end smoke-test data and operator checklist.
2. Map pilot VPS users to new **Owner** / **Kasir** / **Perawat** roles (seeders only define roles; user assignment remains manual).
3. Owner Dashboard live KPI wiring (Phase 22.5+) — foundation permissions now explicit.
4. Super Admin still lands on branch-admin dashboard when holding operational permissions (known Sprint 14 audit item; deferred).
5. Re-run `php artisan db:seed --class=PermissionSeeder` and `RoleSeeder` on VPS after deploy (no `migrate:fresh` / `db:wipe`).

---

## Sprint 22 Phase 22.2 — RME End-to-End Smoke-Test Data & Operator Checklist

**Branch:** `feature/sprint-22-rme-smoke-test-checklist`
**Tag:** `sprint-22-phase-22-2-rme-smoke-test-checklist`
**Type:** Implementation — smoke-test seed data, operator checklist, developer notes, tests

### Objective

Prepare reliable RME pilot smoke-test data and an operator checklist so clinic users can test the RME flow end-to-end without developer guidance.

### Smoke-test data created

| Entity | Identifier |
|--------|------------|
| Branch | `MAIN` — Klinik Gigi Daengtisia Pusat |
| Clinic | `CLN-SMOKE-TEST` |
| Doctor (master) | `DOC-SMOKE-TEST` — DOKTER SMOKE TEST |
| Patient | `MRN-SMOKE-TEST-RME` — PASIEN SMOKE TEST RME |
| Visit (clinical) | `VIS-SMOKE-TEST-RME` — `in_progress` + draft MR + draft odontogram |
| Visit (cashier) | `VIS-SMOKE-CASHIER-RME` — `cashier_pending` + final MR |

### Test accounts created

| Role | Email | Password |
|------|-------|----------|
| Doctor | `dokter.smoke@pilot-test.local` | `SmokeTestPilot!` |
| Perawat | `perawat.smoke@pilot-test.local` | `SmokeTestPilot!` |
| Kasir | `kasir.smoke@pilot-test.local` | `SmokeTestPilot!` |
| Owner | `owner.smoke@pilot-test.local` | `SmokeTestPilot!` |

### Checklist docs created

- `docs/pilot/rme_smoke_test_operator_checklist.md` — Indonesian operator checklist
- `docs/pilot/rme_smoke_test_developer_notes.md` — seeder design, routes, verification commands

### Safe commands

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder
```

`RmeSmokeTestSeeder` is **not** registered in `DatabaseSeeder` — explicit invocation only.

### Forbidden commands

```bash
php artisan migrate:fresh
php artisan db:wipe
php artisan migrate:fresh --seed
```

### Files changed

- `database/seeders/RmeSmokeTestSeeder.php` (new)
- `docs/pilot/rme_smoke_test_operator_checklist.md` (new)
- `docs/pilot/rme_smoke_test_developer_notes.md` (new)
- `tests/Feature/Pilot/RmeSmokeTestSeederTest.php` (new)
- `tests/Feature/Pilot/RmeSmokeTestRouteTest.php` (new)
- `docs/sprint_history.md`

### Tests run

```bash
php artisan optimize:clear
php artisan test --filter=RmeSmokeTestSeeder   # 6 passed (Cursor terminal)
php artisan test --filter=RmeSmokeTestRoute    # 6 passed (Cursor terminal)
php artisan test --filter=Pilot                # 34 passed (Cursor terminal)
./vendor/bin/pint --dirty
```

### Preserved boundaries

- No HR work.
- No global UI redesign.
- No database schema migrations.
- No RME payment/generation/conversion behavior changes.
- `DatabaseSeeder` unchanged — smoke seeder opt-in only.

### Risks / follow-up for Phase 22.3

1. Map real VPS pilot users to Owner/Kasir/Perawat roles if not using smoke-test accounts.
2. Handwriting RM PNG not pre-seeded — doctor adds during manual finalize step.
3. Cashier visit has final MR but no pre-built invoice — kasir creates during manual test.
4. Owner dashboard KPIs may remain placeholder until Phase 22.5+.
5. `BranchContext` still falls back to MAIN when users lack `branch_id` assignment.

---

## Sprint 22 Phase 22.3 — VPS Pilot Deployment Checklist & Safe Seeder Rollout

**Branch:** `feature/sprint-22-vps-pilot-deployment-checklist`
**Tag:** `sprint-22-phase-22-3-vps-pilot-deployment-checklist`
**Type:** Documentation / runbook — no VPS deployment performed

### Objective

Provide a safe, operator-friendly VPS pilot deployment checklist and safe seeder rollout process for Sprint 22 Phase 22.1 and 22.2 changes without damaging existing pilot data.

### Checklist docs created

- `docs/pilot/vps_pilot_deployment_checklist.md` — Indonesian VPS deploy runbook (backup, deploy sequence, verification, rollback, forbidden commands)
- `docs/pilot/safe_seeder_rollout.md` — Indonesian safe seeder rollout guide

### Safe seeder command order

```bash
php artisan db:seed --class=PermissionSeeder
php artisan db:seed --class=RoleSeeder
php artisan db:seed --class=RmeSmokeTestSeeder
```

`RmeSmokeTestSeeder` is **opt-in** only. `DatabaseSeeder` unchanged — not for VPS production reset.

### Backup / rollback summary

- **Backup:** `pg_dump -U "$DB_USER" -d "$DB_NAME" -F c -f "$BACKUP_FILE"` after creating `BACKUP_DIR`; verify non-zero file size before deploy.
- **Rollback app:** `git checkout` previous stable tag, `composer install`, cache rebuild, optional `queue:restart`.
- **Rollback DB:** restore only from verified backup if corruption occurred; prefer not restoring otherwise.
- **Forbidden on VPS:** `migrate:fresh`, `migrate:fresh --seed`, `db:wipe`, unqualified `db:seed`.

### Files changed

- `docs/pilot/vps_pilot_deployment_checklist.md` (new)
- `docs/pilot/safe_seeder_rollout.md` (new)
- `scripts/vps_pilot_preflight.sh` (new — read-only preflight)
- `tests/Feature/Pilot/VpsPilotDeploymentChecklistTest.php` (new)
- `docs/sprint_history.md`

### Tests added

- `tests/Feature/Pilot/VpsPilotDeploymentChecklistTest.php` — doc existence, backup guidance, safe seeder commands, forbidden commands section, Ubuntu Terminal rule, rollback guidance, smoke-test identifiers, preflight script safety

### Verification commands

```bash
php artisan optimize:clear
php artisan test --filter=VpsPilotDeploymentChecklist
php artisan test --filter=Pilot
./vendor/bin/pint --dirty
```

Heavy suites (RME + full) run separately in Ubuntu Terminal per project terminal rule.

### Risks / follow-up for Phase 22.4

1. Map real VPS pilot users to Owner/Kasir/Perawat roles if not using smoke-test accounts.
2. Confirm no new migrations before enabling `migrate --force` on VPS.
3. Operator must run backup manually — preflight script does not automate backup.
4. Handwriting RM PNG and cashier invoice still manual during smoke test.
5. Phase 22.4 (RME → Lab candidate end-to-end validation) remains separate implementation scope.

---

## Sprint 22 Phase 22.4 — RME → Lab Candidate End-to-End Validation

**Branch:** `feature/sprint-22-rme-lab-candidate-e2e-validation`
**Tag:** `sprint-22-phase-22-4-rme-lab-candidate-e2e-validation`
**Type:** Validation / documentation / tests — no schema changes, no payment/generation/conversion logic changes

### Objective

Validate and document the full pilot RME-to-lab handoff: visit/RME finalize → cashier billing → full payment → lab case candidate → Admin Lab conversion → traceable lab order, with role boundaries and finance isolation.

### Flow validated

```text
ClinicVisit → MedicalRecord finalize → cashier_pending
→ RmeInvoice → RmePayment (full) → PAID + visit completed
→ LabCaseCandidate (requires_lab items)
→ LabCaseCandidateConversionService → LabOrder
```

Boundaries confirmed: no `trx_payments` / lab `Invoice` auto-created; partial payment rejected; idempotent generation and conversion.

### Files changed

- `tests/Feature/Pilot/RmeLabCandidateE2EValidationTest.php` (new)
- `docs/pilot/rme_lab_candidate_e2e_operator_checklist.md` (new — Indonesian)
- `docs/pilot/rme_lab_candidate_e2e_developer_notes.md` (new)
- `docs/pilot/rme_smoke_test_operator_checklist.md` (reference section)
- `docs/pilot/rme_smoke_test_developer_notes.md` (reference section)
- `docs/sprint_history.md`

**Not changed:** `RmeSmokeTestSeeder`, `DatabaseSeeder`, application business logic, schema.

### Tests added

- `tests/Feature/Pilot/RmeLabCandidateE2EValidationTest.php` — full happy path, idempotency, non-lab guard, partial payment guard, role boundaries, cross-branch denial, visit status transition, pilot doc presence

### Operator checklist

- `docs/pilot/rme_lab_candidate_e2e_operator_checklist.md`

### Developer notes

- `docs/pilot/rme_lab_candidate_e2e_developer_notes.md`

### Verification commands

```bash
php artisan optimize:clear
php artisan test --filter=RmeLabCandidateE2EValidation
php artisan test --filter=LabCaseCandidate
php artisan test --filter=Pilot
./vendor/bin/pint --dirty
```

Heavy: `php artisan test --filter=RME` and full suite in Ubuntu Terminal only.

### Risks / follow-up for Phase 22.5

1. Optional smoke seeder: lab-required treatment + Admin Lab smoke account (not done in 22.4).
2. VPS operators must pick `requires_lab` treatment manually during kasir step.
3. Handwriting RM still manual before finalize in smoke data.
4. Treatment → `lab_service_id` mapping still explicit at conversion.
5. Owner dashboard / RME→lab funnel metrics deferred.

---

## Sprint 22 Phase 22.5 — Owner Dashboard RME/Lab Pilot KPI Wiring

**Branch:** `feature/sprint-22-owner-dashboard-rme-lab-kpi`
**Tag:** `sprint-22-phase-22-5-owner-dashboard-rme-lab-kpi`
**Type:** Read-only implementation — no schema changes, no payment/generation/conversion logic changes

### Objective

Wire real, read-only RME/Lab pilot KPIs into the Owner Dashboard so the owner can monitor clinic pilot progress across visit → RM → cashier → lab candidate → lab order without mutating operational data.

### KPI section added

**Monitoring Pilot RME & Lab** on `Dasbor Owner` (`/dashboard`), including:

- KPI cards (Indonesian labels)
- **Funnel RME ke Lab** pipeline card
- **Perlu Perhatian** branch attention panel
- Operational note: data is pilot monitoring from current RME/Lab transactions

### Files changed

- `app/Modules/Reporting/Services/OwnerDashboardRmeLabKpiService.php` (new)
- `app/Http/Controllers/HomeDashboardController.php` (new)
- `routes/web.php` — `/dashboard` now uses controller (loads KPIs only for Owner shell users)
- `resources/views/dashboard.blade.php` — RME/Lab pilot section
- `tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php` (new)
- `docs/pilot/owner_dashboard_rme_lab_kpi_notes.md` (new)
- `docs/sprint_history.md`

### KPI definitions implemented

Visits today, waiting/in-progress/cashier-pending/completed-today, RM draft/final today, unpaid RME invoices, paid invoices and revenue today, pending lab candidates, converted candidates today, lab orders from RME today, conversion-rate display, funnel stages, and per-branch attention items. See `docs/pilot/owner_dashboard_rme_lab_kpi_notes.md`.

### Branch / Owner aggregation rule

Owner dashboard uses **global aggregate across all active branches**. Service supports optional single-branch scope via `metrics($branchId)` for future Phase 22.6 filters. Branch admin dashboard behavior unchanged.

### Tests added/updated

- `tests/Feature/Dashboard/OwnerDashboardRmeLabKpiTest.php` — 9 tests: labels, empty state, KPI correctness, funnel, cross-branch aggregate, attention items, authorization boundaries, no side effects
- Existing `OwnerDashboardUiTest`, `BranchAdminDashboardUiTest`, `PilotRouteAuthorizationTest` — unchanged, still pass

### Commands run

```bash
php artisan test --filter=OwnerDashboardRmeLabKpi   # 9 passed
php artisan test --filter=OwnerDashboardUi        # 3 passed
php artisan test --filter=Dashboard               # 78 passed
php artisan test --filter=Pilot                   # 56 passed
./vendor/bin/pint --dirty                         # PASS
```

Heavy: `php artisan test --filter=RME` and full suite — run in Ubuntu Terminal before VPS deploy.

### Risks / follow-up for Phase 22.6

1. Date-range and branch-comparison filters not yet in UI.
2. Executive KPI cards (lab pipeline, inventory) remain placeholder.
3. Attention-item loop is per-branch; review if branch count grows.
4. Owner has read-only clinic visit permission but no deep links from KPI cards (by design).
5. Phase 22.7 VPS checklist should mention new Owner dashboard smoke check after deploy.

---

## Sprint 22 Phase 22.6 — Owner Dashboard Branch Filter & KPI Drilldown Polish

**Branch:** `feature/sprint-22-owner-dashboard-branch-filter-drilldown`
**Tag:** `sprint-22-phase-22-6-owner-dashboard-branch-filter-drilldown`
**Type:** Read-only UI/service polish — no schema changes, no payment/generation/conversion logic changes

### Objective

Let Owner monitor all branches or one selected branch on the RME/Lab pilot dashboard, compare per-branch attention at a glance, and jump to existing read-only index pages when permitted.

### Branch filter behavior

- Default: **Semua Cabang** — global aggregate across active branches (`?branch_id` omitted).
- Selected: `?branch_id=<active_id>` — KPI cards, funnel, attention panel, and branch summary scoped to that branch.
- Invalid/inactive id: ignored; falls back to all active branches without error.
- Branch admin dashboard unchanged (no filter, no Owner RME/Lab section).

### Branch summary behavior

**Ringkasan Per Cabang** table: Cabang, Kunjungan Hari Ini, Menunggu Kasir, Invoice Belum Dibayar, Kandidat Lab Pending, Dikonversi Hari Ini, Status Perhatian. Inactive branches excluded. Pilot-scale: all active branches in one table (no pagination).

### Drilldown rules

`OwnerDashboardRmeLabDrilldownService` — permission-aware read-only links on KPI cards to existing index routes (`rme.visits.index`, `rme.medical-records.index`, `rme.cashier.index`, `lab-case-candidates.index`, `lab-orders.index`). No link when permission missing. Owner pilot role typically gets clinic-visit links only.

### Files changed

- `app/Modules/Reporting/Services/OwnerDashboardRmeLabKpiService.php` — `resolveSelectedBranchId()`, `activeBranches()`, `branchSummary()`, attention status per branch
- `app/Modules/Reporting/Services/OwnerDashboardRmeLabDrilldownService.php` (new)
- `app/Http/Controllers/HomeDashboardController.php` — branch filter + drilldown wiring
- `resources/views/dashboard.blade.php` — filter UI, branch summary table, drilldown hrefs, monitoring disclaimer
- `resources/views/components/owner-dashboard/owner-kpi-card.blade.php` — optional no-access hint
- `tests/Feature/Dashboard/OwnerDashboardBranchFilterDrilldownTest.php` (new, 11 tests)
- `docs/pilot/owner_dashboard_rme_lab_kpi_notes.md`
- `docs/sprint_history.md`

### Tests added/updated

- `OwnerDashboardBranchFilterDrilldownTest.php` — filter, aggregate, selected branch, invalid id, branch summary, inactive exclusion, branch admin unchanged, permission-aware drilldowns, no side effects, empty state
- Existing `OwnerDashboardRmeLabKpiTest`, `OwnerDashboardUiTest`, `BranchAdminDashboardUiTest` — still pass

### Commands run

```bash
php artisan test --filter=OwnerDashboardBranchFilterDrilldown  # 11 passed
php artisan test --filter=OwnerDashboardRmeLabKpi               # 9 passed
php artisan test --filter=OwnerDashboardUi                    # 3 passed
php artisan test --filter=BranchAdminDashboardUi                # pass
./vendor/bin/pint --dirty                                       # PASS
```

Heavy: `php artisan test --filter=RME`, `php artisan test --filter=Dashboard`, full suite — Ubuntu Terminal only.

### Risks / follow-up for Phase 22.7

1. Date-range filters not yet in UI.
2. Drilldown index pages use `BranchContext`, not dashboard `branch_id` — operator may need to switch branch on destination.
3. Branch summary table may need pagination if branch count grows.
4. Executive KPI cards remain placeholder.
5. VPS pilot checklist: add Owner branch-filter smoke step after deploy.

---

## Sprint 22 Phase 22.7 — VPS Pilot Checklist Update & Owner Dashboard Manual Smoke Test

**Branch:** `feature/sprint-22-vps-owner-dashboard-smoke-checklist`
**Tag:** `sprint-22-phase-22-7-owner-dashboard-smoke-checklist`
**Type:** Documentation, checklist, test-backed content assertions — no application logic or schema changes

### Objective

Make it safe for operators to deploy/pull Sprint 22.5–22.6 Owner Dashboard work to VPS and manually validate KPI monitoring, branch filter, branch summary, permission-aware drilldowns, role boundaries, and read-only (no mutation) behavior.

### Checklist added

- `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md` — Indonesian operator manual smoke test (35-step table, screenshot evidence, bug report format, known limitations)

### VPS checklist update

- `docs/pilot/vps_pilot_deployment_checklist.md` — section **8.1 Owner Dashboard Smoke Test — Sprint 22.5–22.6**; Phase 22.6 deploy targets; explicit note that Phase 22.5–22.6 do not require destructive data reset; safe seeder reminder

### Safe seeder note

- `docs/pilot/safe_seeder_rollout.md` — section 12: Owner branch filter/KPI drilldown does not require new seeder; `PermissionSeeder` + `RoleSeeder` sufficient; `RmeSmokeTestSeeder` optional

### Preflight script update

- `scripts/vps_pilot_preflight.sh` — read-only reminders: Owner dashboard manual smoke checklist path, validate `/dashboard` as Owner after deploy, avoid destructive commands and unqualified `db:seed`

### Tests added/updated

- `tests/Feature/Pilot/OwnerDashboardManualSmokeChecklistTest.php` (new, 12 tests) — checklist content, VPS doc cross-reference, safe seeder note, preflight safety

### Other docs

- `docs/pilot/owner_dashboard_rme_lab_kpi_notes.md` — link to manual smoke checklist

### Commands run

```bash
php artisan test --filter=OwnerDashboardManualSmokeChecklist
php artisan test --filter=VpsPilotDeploymentChecklist
php artisan test --filter=Dashboard
php artisan test --filter=Pilot
./vendor/bin/pint --dirty
```

Heavy (Ubuntu Terminal only): `php artisan test --filter=RME`, full `php artisan test`.

### Risks / follow-up for Phase 22.8

1. Date-range filters still deferred.
2. Drilldown destinations use `BranchContext`, not dashboard `branch_id`.
3. Branch summary may need pagination as branch count grows.
4. Executive KPI cards remain placeholder.
5. Operator must run manual Owner smoke checklist on VPS after each deploy touching dashboard reporting.
6. Real Owner pilot account may lack lab drilldown links — document as expected when permissions are read-only.

---

## Sprint 22 Phase 22.8 — Sprint 22 Closure, Release Candidate Notes & VPS Pilot Go/No-Go Checklist

**Branch:** `feature/sprint-22-closure-rc-go-no-go`
**Tag:** `sprint-22-phase-22-8-closure-rc-go-no-go`
**Commit:** `dc1060b`
**Type:** Documentation, release candidate notes, go/no-go checklist, test-backed content assertions — no application logic, schema, or seeder behavior changes

### Objective

Close Sprint 22 with a clear release candidate package and VPS pilot go/no-go checklist answering: what changed, deploy target, safe/forbidden VPS commands, verification requirements, GO/NO-GO criteria, rollback, known limitations, and Sprint 23 backlog.

### Docs created

- `docs/pilot/sprint_22_release_candidate_notes.md` — Indonesian RC notes (phases 22.1–22.8 table, deploy sequence, GO/NO-GO, rollback, limitations, Sprint 23 backlog)
- `docs/pilot/vps_pilot_go_no_go_checklist.md` — Indonesian operational GO/GO dengan catatan/NO-GO checklist with sign-off table

### Docs updated

- `docs/pilot/vps_pilot_deployment_checklist.md` — section **14. Sprint 22 Release Candidate & Go/No-Go**; references to RC and go/no-go docs
- `docs/pilot/owner_dashboard_manual_smoke_test_checklist.md` — reference to go/no-go checklist
- `docs/pilot/safe_seeder_rollout.md` — section **13. Sprint 22 Release Candidate & Go/No-Go**
- `scripts/vps_pilot_preflight.sh` — read-only reminders for RC/go-no-go doc paths and backup/screenshot evidence

### Tests added

- `tests/Feature/Pilot/Sprint22ReleaseCandidateChecklistTest.php` (17 tests) — RC notes content, go/no-go checklist, cross-references, preflight safety

### Deploy target recommendation

| Item | Value |
|------|-------|
| Functional baseline (Phase 22.7) | `feature/sprint-22-vps-owner-dashboard-smoke-checklist` @ `1c5c198` / tag `sprint-22-phase-22-7-owner-dashboard-smoke-checklist` |
| Documentation-inclusive RC (Phase 22.8) | `feature/sprint-22-closure-rc-go-no-go` / tag `sprint-22-phase-22-8-closure-rc-go-no-go` |
| Optional stakeholder RC tag | `sprint-22-release-candidate` (only after approval and full verification) |

### Commands run

```bash
php artisan test --filter=Sprint22ReleaseCandidateChecklist
php artisan test --filter=OwnerDashboardManualSmokeChecklist
php artisan test --filter=VpsPilotDeploymentChecklist
php artisan test --filter=Pilot
php artisan test --filter=Dashboard
./vendor/bin/pint --dirty
```

Heavy (Ubuntu Terminal only): `php artisan test --filter=RME`, full `php artisan test`.

### Sprint 22 closure summary

Sprint 22 delivered pilot stabilization after Sprint 21 VPS deploy: role/permission/menu hardening (22.1), RME smoke-test data and operator checklist (22.2), VPS deploy runbook and safe seeder rollout (22.3), RME → Lab candidate E2E validation (22.4), Owner Dashboard RME/Lab KPI wiring (22.5), branch filter and drilldown polish (22.6), Owner manual smoke checklist and VPS checklist update (22.7), and closure RC/go-no-go documentation (22.8). No HR, no global UI redesign, no schema changes in Phase 22.8. Owner Dashboard remains read-only.

### Follow-up Sprint 23 backlog

1. Owner dashboard date range filter.
2. Branch comparison polish / pagination for Ringkasan Per Cabang.
3. Drilldown branch context alignment with dashboard filter.
4. Pilot bug triage from manual smoke results.
5. VPS deploy execution and evidence capture.
6. Optional production-grade backup verification checklist.
7. UI/UX polish after pilot safety is stable.
8. Inventory/RME executive dashboard consolidation.

## Sprint 23 Phase 23.3 — Owner Dashboard Access/Menu/Role Enablement + RME Patient Identity Hardening

**Branch:** `feature/sprint-23-phase-23-3-owner-dashboard-rme-identity` (from `sprint-22-release-candidate`).
**Tag:** `sprint-23-phase-23-3-owner-dashboard-rme-identity`.
**Status:** PASS. Phase doc: `docs/sprint_23_phase_23_3_owner_dashboard_rme_identity.md`.

### Owner Dashboard role/menu enablement
- Owner Dashboard route (`dashboard`), `view_owner_dashboard` permission, `Owner` role, and "Dasbor" sidebar entry were already wired in Sprint 22 Phase 22.1. Phase 23.2's "not available" was a deployment/data condition (seed + role assignment not applied on the pilot), not missing code.
- Added safe Owner pilot enablement command `php artisan pilot:assign-owner {email}` — assigns existing `Owner` role to an existing user; no user creation, no passwords/secrets, idempotent, fails safely.
- Test-locked access rules (Owner sees KPI, Super Admin reaches route, operational roles do not see `Dasbor Owner`, guests redirect to login).

### RME role/menu hardening
- Kept existing clinical permission naming (`view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`) — no new `view_rme/manage_rme` names introduced. Menu visibility per role already covered by `SidebarPermissionVisibilityTest` (Doctor/Kasir/Perawat/Owner/Admin Klinik/Technician).

### Patient identity foundation
- `mst_patients.medical_record_number` already existed (nullable, unique, indexed) — no migration needed.
- New: `config/patient.php` (configurable code format), `PatientCodeGenerator` service, and `PatientService::create()` auto-generates a code when blank. New patient → generated code; returning patient → existing code preserved.
- Temporary format `RM-{YYYYMM}-{SEQ6}` (e.g. `RM-202606-000001`), PENDING owner approval; configurable via config/env. No backfill performed.

### UI polish
- Added sidebar group icons for `Pengadaan` and `Master Data Klinik`. Batch entry already exists under Inventory ("Batch & Lot") — not rebuilt; clinical batch module deferred.

### Tests run
- `PatientCodeGenerationTest` (6) + `OwnerEnablementTest` (7) — 13 passed.
- `--filter='Owner|Dashboard|Patient|Sidebar|ClinicVisit|RolePermission|PilotRoute'` — 233 passed (932 assertions).
- `./vendor/bin/pint --dirty` passed; `npm run build` OK.

### Final status
PASS — local only, no VPS deploy, no schema/data migration, no destructive DB commands.

### Next phase
Sprint 23 Phase 23.4 — VPS Deploy + Owner Dashboard/RME Identity Smoke (re-seed roles, run `pilot:assign-owner`, smoke Owner KPI + new-patient code; confirm final patient code format with owner; plan optional backfill).

## Sprint 23 Phase 23.5 — Branch Scope Correction + Dashboard Renaming + RME Report Roles

Branch `feature/sprint-23-phase-23-5-branch-scope-dashboard-rename` (from tag `sprint-23-phase-23-3-owner-dashboard-rme-identity`). Tag `sprint-23-phase-23-5-branch-scope-dashboard-rename`. Local only — no VPS work, additive migration only. Full doc: `docs/sprint_23_phase_23_5_branch_scope_dashboard_rename.md`.

### Business rule update
- RME multi-branch, Inventory multi-branch, **Lab single-branch/global**. RME and Lab KPI separated. Module dashboard labels renamed to English "Dashboard …". RME report access split into patient vs payment roles.

### Branch scope
- `config/module_branch_scope.php` + `App\Modules\Branch\Support\ModuleBranchScope` centralize the rule (`rme`/`inventory` = multi_branch, `lab` = single_branch).

### Master Data Cabang
- Additive migration `2026_06_15_100001` adds `is_rme_enabled` / `is_inventory_enabled` to `mst_branches` (default true). `Branch` model: fillable + casts + `scopeRmeEnabled()`/`scopeInventoryEnabled()`. No duplicate table. Branch master CRUD UI remains unrouted (deferred since Sprint 9).

### Lab multi-branch removal
- Owner dashboard Lab metrics made global (branch filter removed from lab queries). Lab order list already global (opt-in filter, no caller). Legacy `branch_id` columns kept — **not dropped**. `LabCaseCandidate` RME→Lab isolation retained.

### KPI separation
- New `RmeDashboardKpiService` (branch-aware) and `LabDashboardKpiService` (global, no branch param, `scope_label = "Laboratorium global"`).

### Dashboard renaming
- "Dashboard Owner" (sidebar conditional on `view_owner_dashboard`, else "Dashboard"), "Dashboard RME" (Klinik/RME group header), "Dashboard Inventory" (inventory dashboard), "Dashboard Lab" (reporting dashboard). Route names unchanged.

### RME report roles
- New permissions `view_rme_patient_reports`, `view_rme_payment_reports`. New roles `Laporan Pasien RME`, `Laporan Pembayaran RME`. Owner gets both; Kasir gets payment only; Super Admin both; Doctor neither. Routes `rme.reports.patients` / `rme.reports.payments` (branch-aware), gated per permission; views under `resources/views/rme/reports/`.

### Tests run
- New `tests/Feature/BranchScope/BranchScopeDashboardRenameTest.php` (14 passed). Updated 8 dashboard/nav/pilot/inventory/reporting tests to the new "Dashboard" labels.
- Focused suites: Dashboard+RME+Reporting+Auth (487), LabOrder+Lab integration+Navigation+Pilot+drilldown (157), BranchScope (14) — all passed. `pint --dirty` passed; `npm run build` OK. Full suite not run end-to-end (runtime budget) — documented honestly.

### Final status
PASS — local only, no VPS deploy, additive migration only, no destructive DB commands, no Lab `branch_id` columns dropped.

### Next phase
Sprint 23 Phase 23.6 — VPS Deploy + Branch Scope/Dashboard/RME Report Smoke (backup DB first, run Permission/Role seeders, smoke separated dashboards, Lab global KPI, RME branch filter, split RME report roles).

## Sprint 23 Phase 23.7 — Master Data Cabang CRUD UI for RME + Inventory
- Branch: `feature/sprint-23-phase-23-7-branch-master-crud`. Tag `sprint-23-phase-23-7-branch-master-crud`. Local only — no VPS deploy, no push.

### Business rule
- Master Data Cabang serves the multi-branch modules (RME + Inventory) only. **Lab stays single-branch / global** — no Lab checkbox, no Lab branch filter, legacy Lab `branch_id` columns not dropped. Branch code + name are entered manually; the code is reserved as a future patient-ID component (format NOT finalized this phase).

### Data model
- Reused existing `mst_branches` columns (`code`, `name`, `is_active`, `is_rme_enabled`, `is_inventory_enabled`). **No new migration** — all required fields already existed (created Sprint 9 + Phase 23.5 module-flags migration). Branch code: manual, trimmed + uppercased, `regex:[A-Z0-9-]`, `max:20`, unique.

### Permissions / roles
- New permissions `view_branch_master_data`, `manage_branch_master_data` (PermissionSeeder). Assigned to `Owner` (view+manage) and `Super Admin` (via `*`). Doctor/Kasir/Perawat/Courier: no access.

### CRUD
- Wired the pre-existing Branch module skeleton (Controller→Request→Service→Repository→Policy, Sprint 9) to live routes. `BranchPolicy` updated from `manage branches` to the new permissions (view→canView, write→canManage). Store/Update requests normalize code + coerce checkbox booleans + validate module flags. Destroy is a soft delete; the default `MAIN` branch is protected from deletion (controller guard + hidden delete button).

### Routes
- `Route::resource('branches')->except(['show'])` inside the `settings` group, gated `permission:view_branch_master_data|manage_branch_master_data`: `settings.branches.index|create|store|edit|update|destroy`.

### UI
- Views `resources/views/settings/branches/{index,create,edit,_form}.blade.php` (TailAdmin `x-settings-shell` style). Index columns: Kode Cabang, Nama Cabang, Status, RME, Inventory, Aksi. Form: Kode Cabang (with hint "Kode cabang diisi manual dan akan digunakan sebagai komponen format ID pasien."), Nama Cabang, Aktif, Digunakan untuk RME, Digunakan untuk Inventory. No Lab option. Sidebar item "Master Data Cabang" added to the master-data group, gated by the two new permissions.

### Tests run
- New `tests/Feature/Branch/BranchMasterDataTest.php` (11), `tests/Feature/BranchScope/BranchModuleScopeTest.php` (3), +1 sidebar test. Focused suites all passed: Branch (293), Permission (155), Dashboard (120), Sidebar (42), Lab (195), Inventory+Rme (1056). `pint --dirty` OK; `npm run build` OK. Full end-to-end suite not run (runtime budget) — documented honestly.

### Final status
PASS — local only, no VPS deploy, no new migration, no destructive DB commands, Lab remains global, no Lab `branch_id` columns dropped, patient ID format NOT finalized.

### Next phase
Sprint 23 Phase 23.8 — Patient ID Format Finalization + New Patient Registration Flow (owner to approve `{BRANCH_CODE}` token format; review existing branch data on VPS before enabling final patient code).

## Sprint 23 Phase 23.8 — Patient ID Format Finalization + New Patient Registration Flow
- Branch: `feature/sprint-23-phase-23-8-patient-id-registration` (from `sprint-23-phase-23-7-branch-master-crud` / `ed73294`). Tag `sprint-23-phase-23-8-patient-id-registration-flow`. Local only — no VPS deploy, no push. Full doc: `docs/sprint_23_phase_23_8_patient_id_registration_flow.md`.

### Business rule
- Final patient RM format locked to `RM DG-{KODE_CABANG}-{TAHUN_DAFTAR}-{NOMOR_RM_MANUAL}` (e.g. `RM DG-TKM1-2026-0001`). Prefix fixed `RM DG`; branch code from Master Data Cabang (uppercased/trimmed); year from registration date; manual RM number entered by admin — **no auto-sequence**, leading zeros preserved, numeric only. Final value globally unique. Same manual number allowed across branches because the composed value differs. Existing patients never rewritten.

### Data model
- Additive migration `2026_06_16_100001_add_registration_fields_to_mst_patients_table` — nullable `branch_id` (FK→mst_branches, nullOnDelete), `registered_at` (date), `manual_rm_number` (string 50). All guarded by `hasColumn`, no backfill, no rewrites. `medical_record_number` keeps the final composed value.

### Services / requests
- New `PatientMedicalRecordNumberService` (`compose`, `composeForRegistration`, `exists`). `PatientService::create/update` compose the final RM when branch+manual present and no explicit code; explicit code always wins; legacy `PatientCodeGenerator` auto-sequence kept only as backward-compatible fallback. `Store/UpdatePatientRequest`: nullable branch_id (active + is_rme_enabled), registered_at, manual_rm_number (regex `^[0-9]+$`), `required_with` pairing, composed-final uniqueness check. New branch repo/service method `listRmeEnabled()`.

### RME visit new-patient flow
- `StoreClinicVisitRequest` adds `patient_mode` (existing|new); existing requires `patient_id`, new requires `new_patient.{name,branch_id,manual_rm_number}` (+ optional demographics). `ClinicVisitService::create` creates the patient first (final RM composed) then attaches the visit in the same transaction. New-patient creation additionally authorizes `create` on Patient (`manage patients`). Visit `branch_id` still from `BranchContext` (unchanged). Patient select shows `MRN — name` / `Belum ada RM — name`.

### BranchContext hotfix
- Does not depend on `users.branch_id` (guarded by `Schema::hasColumn`, cannot 500). Generic fallback: active MAIN else first active branch. New `rmeBranchId()`/`requireRmeBranchId()` (MAIN if active+rme-enabled, else first active rme-enabled, clear exception when none) and `inventoryBranchId()`. Lab not branch-enforced. Patient-ID branch comes from the form, not the fallback.

### Tests run
- New: `PatientMedicalRecordNumberTest`, `PatientRegistrationTest`, `ClinicVisitNewPatientFlowTest`, `BranchContextFallbackTest` (28 passed). Focused suites: Patient (49), ClinicVisit (65), Branch (305) + Rme/Dashboard/Permission/Sidebar/MasterData — all passed. `pint --dirty` OK; `npm run build` OK. Full end-to-end suite not run (runtime budget) — documented honestly.

### Final status
PASS — local only, no VPS deploy, additive migration only, no destructive DB commands, no existing patients rewritten, Lab remains global.

### Next phase
Sprint 23 Phase 23.9 — VPS Deploy + Patient Registration / RME Visit Smoke (backup DB first, `migrate --force` only, confirm Master Data Cabang codes, smoke new-patient registration + RME visit new-patient flow; plan optional legacy RM backfill separately).

## Sprint 23 Phase 23.9.1 — RME Clinic Source from Branch Master

- Branch: `feature/sprint-23-phase-23-9-1-rme-clinic-branch-source` (from `sprint-23-phase-23-8-patient-id-registration-flow` / `6277bbb`). Tag `sprint-23-phase-23-9-1-rme-clinic-branch-source`. Local only — no VPS deploy, no push. Full doc: `docs/sprint_23_phase_23_9_1_rme_clinic_source_from_branch.md`.

### Business rule
- **Klinik = Cabang RME.** RME patient registration and RME visit creation source the Klinik/Cabang choice from `mst_branches where is_active = true and is_rme_enabled = true` (ordered by name). The legacy `mst_clinics` master is no longer the source for new RME Klinik choices. MAIN (`is_rme_enabled = false`) is the technical fallback only and is hidden from operational RME dropdowns. Operational branches: TKM1 — Cabang Telkomas, LDK2 — Cabang Landak, ATG3 — Cabang Antang.

### RME visit impact
- Visit form "Klinik" dropdown replaced by **Klinik/Cabang (Cabang RME)** sourced from `BranchService::listRmeEnabled()` (field `branch_id`). `ClinicVisitService::resolveBranchId()` sets visit `branch_id` from: new-patient mode → the new patient's selected Cabang RME (patient + visit share one branch); existing mode → submitted `branch_id`; else `BranchContext::requireId()` fallback (legacy/programmatic callers). New RME visits store `clinic_id = null`. Visit show displays Klinik/Cabang as `{code} — {name}` (legacy clinic name fallback). `StoreClinicVisitRequest`: `clinic_id` now nullable; new `branch_id` rule (active + is_rme_enabled).

### Patient impact
- Patient create/edit already used RME branches (Phase 23.8); kept authoritative. `branch_id` → `mst_patients.branch_id`; RM composed from `branch.code`.

### Data model
- Additive migration `2026_06_17_100001_make_clinic_id_nullable_for_rme_branch_source` — makes `trx_clinic_visits.clinic_id` and `mst_patients.clinic_id` nullable. `mst_clinics` and the `clinic_id` columns/FKs are NOT dropped; existing rows keep their `clinic_id`.

### Legacy compatibility
- `mst_clinics` master + `settings.clinics.*` routes still work. Lab remains global / not branch-enforced. Inventory remains inventory-enabled-branch aware.

### Tests run
- New `RmeClinicSourceFromBranchTest` (15 passed / 32 assertions). `ClinicVisitTest` "ignores branch_id" replaced by selected-RME-branch + fallback tests. Focused suites: ClinicVisit/Patient/Rme/RME — 469 passed (1319 assertions); Branch/Dashboard/Permission/Sidebar — passed. `pint --dirty` OK; `npm run build` OK. Full end-to-end suite not run (runtime budget) — documented honestly.

### Final status
PASS — local only, no VPS deploy, additive migration only, no destructive DB commands, no `mst_clinics`/`clinic_id` dropped, no existing rows rewritten, Lab remains global.

### Next phase
Sprint 23 Phase 23.9.2 — VPS Deploy + RME Clinic Source Smoke (backup DB first, `migrate --force` only; smoke patient create, RME visit existing patient, RME visit new patient; confirm MAIN hidden from Klinik/Cabang dropdown).

## Sprint 23 Phase 23.9.3 — RME Visit List Branch Filter Fix

- Branch: `feature/sprint-23-phase-23-9-3-rme-visit-list-branch-filter` (from `sprint-23-phase-23-9-1-rme-clinic-branch-source` / `cf1f591`). Tag `sprint-23-phase-23-9-3-rme-visit-list-branch-filter`. Local only — no VPS deploy, no push. Full doc: `docs/sprint_23_phase_23_9_3_rme_visit_list_branch_filter.md`.

### Bug
- A new patient (Megasanti) + visit (VIS-20260613-001) saved correctly at branch ATG3 (`branch_id=3`, `clinic_id=null`), but Daftar Kunjungan did not show the visit. The index query was forced to the single `BranchContext` fallback branch; when the fallback was not ATG3, ATG3 visits were silently hidden. An all-branch DB query found the visit.

### Root cause
- `ClinicVisitService::paginate()` used `BranchContext::requireId()` and `ClinicVisitRepository::paginate()` filtered `where('branch_id', $branchId)`, scoping the list to the fallback branch. `ClinicVisitPolicy::belongsToActiveBranch()` had the same single-branch assumption.

### Fix
- Daftar Kunjungan default scope = **active RME-enabled branch set** (`BranchService::rmeEnabledIds()`) via new repo method `paginateForBranches()`. Optional **Cabang RME** filter (`branch_id`): valid RME branch narrows the list, any other value is ignored (full RME scope). No `clinic_id` filter, no `users.branch_id`, MAIN excluded. Branch column shows `{code} — {name}`. Counts (`visitsTodayCount/waitingCount/inProgressCount`) accept `?int $branchId` and align with list scope. Policy `belongsToActiveRmeBranch()` allows view/print/update/transition for any active RME-enabled branch; non-RME branch visits remain forbidden.

### Tests run
- New `RmeVisitListBranchFilterTest` (16 passed). Updated 4 `ClinicVisitTest` + 2 `RmePdfPrintHardeningTest` isolation tests to use a non-RME "other branch". Focused suites: ClinicVisit 66, Rme 470, Permission 156, Sidebar 42, Dashboard|Patient|Branch 455 — all passed. `pint --dirty` OK; `npm run build` OK. Full end-to-end suite not run (runtime budget).

### Final status
PASS — local only, no VPS deploy, no schema/migration change, no destructive DB commands, no patient/visit data rewritten, Lab remains global.

### Next phase
Sprint 23 Phase 23.9.4 — VPS Deploy + Visit List Branch Filter Smoke (backup DB first, `migrate --force` only; confirm Megasanti / VIS-20260613-001 appears in Daftar Kunjungan, Cabang RME filter works, existing + new patient visits both appear, no 500).

## Sprint 23 Phase 23.9.5 — VPS Smoke Closure Documentation

- Branch: `feature/sprint-23-phase-23-9-5-vps-smoke-closure-docs` (from `sprint-23-phase-23-9-3-rme-visit-list-branch-filter` / `c9a5ebb`). Tag `sprint-23-phase-23-9-5-vps-smoke-closure-documentation`. **Docs-only closure** — no app/code/migration/seeder/test changes, no VPS deploy, no push. Full doc: `docs/sprint_23_phase_23_9_5_vps_smoke_closure_documentation.md`.

### Scope
- Documents the completed VPS deployment and browser smoke from Sprint 23 Phase 23.9.4. Deployed commit `c9a5ebb`, tag `sprint-23-phase-23-9-3-rme-visit-list-branch-filter`, pilot environment, maintenance mode OFF after deploy. Pre-deploy backup `sprint-23-phase-23-9-4-vps-visit-list-branch-filter-20260613-015453.sql` (374K) captured before pull/migrate.

### VPS smoke result
- VPS smoke PASS. Deployment evidence (checkout, composer install, npm ci/build, `migrate --force` nothing to migrate, Permission/Role seeders, Owner role ensured, MAIN hidden from RME/Inventory dropdowns, cache rebuild, storage perms, app live) all PASS. HTTP: `/login` 200, `/rme/visits` 302 to `/login` pre-auth (not 500).
- **Daftar Kunjungan branch filter PASS:** Megasanti / VIS-20260613-001 (branch ATG3, `branch_id=3`, `clinic_id=null`, status `waiting`) appears under Semua Cabang RME and ATG3 filter, and is correctly absent under TKM1. Regression (create visit, existing + new patient visit, Master Data Cabang) all PASS. Operational RME branches: ATG3 — Cabang Antang, LDK2 — Cabang Landak, TKM1 — Cabang Telkomas. MAIN flags active=1, rme=0, inventory=0.

### Final status
GO WITH WATCH — bug resolved; ATG3 visit no longer hidden by BranchContext fallback.

### Watch items
- Node VPS still v18 while `@tailwindcss/oxide` requires Node >=20.
- npm audit still reports 5 vulnerabilities.
- Legacy `mst_clinics` intentionally preserved for compatibility.
- Backfill old patients to new RM format not done yet.

### Next phase
Sprint 23 Phase 23.10 — RME Pilot Data Entry Hardening (verify create-visit flow registration→cashier, harden existing patient branch behavior, safe old-patient RM/backfill preview report, confirm treatment/tariff/payment flow after branch-source changes, prepare pilot checklist for clinic users).

## Sprint 23 Phase 23.10 — RME Pilot Data Entry Hardening

- Branch: `feature/sprint-23-phase-23-10-rme-pilot-data-entry-hardening` (from `sprint-23-phase-23-9-5-vps-smoke-closure-documentation` / `4034078`). Tag `sprint-23-phase-23-10-rme-pilot-data-entry-hardening`. Local only — no VPS deploy, no push, no schema/migration change, no destructive DB commands. Full doc: `docs/sprint_23_phase_23_10_rme_pilot_data_entry_hardening.md`.

### Scope
- Extended the Phase 23.9.3 multi-branch correction (visit list) into the **rest of the pilot data-entry flow**. The doctor odontogram/medical-record stack and the cashier billing/payment/lab-candidate stack were still scoped to a single `BranchContext::requireId()` / `id()`, which resolves to MAIN in the pilot (not RME-enabled) — so those flows would have been empty or forbidden for every real RME-branch visit. All now scope to the active **Cabang RME** set (`is_active = true AND is_rme_enabled = true`).

### Existing patient behavior
- Selected Cabang RME becomes `visit.branch_id`. `patient.branch_id` is **never** rewritten automatically. Legacy patients (`branch_id` null) may still create visits under a selected branch. Legacy `clinic_id` preserved, never used for RME scoping.

### Null clinic_id hardening
- RME views use `visit.branch` as the primary location label; `clinic_id` null does not break visit list/detail, odontogram, medical record, or cashier create.

### Changes
- Cashier: `RmeInvoiceService` (queue + invoice create use visit branch), `RmePaymentService`, `RmeLabIntegrationService`, `RmeInvoicePolicy`, repo `paginateCashierPendingForBranches`. Doctor: `OdontogramService`/`OdontogramPolicy`, `MedicalRecordService`/`MedicalRecordPolicy` + multi-branch repo paginate/counts. Patient: `branchLabel()`/`selectorLabel()`/`isLegacyWithoutBranch()` + read-only `legacyWithoutBranch()` preview (no backfill). Views: patient selector/list/detail show RM + branch/legacy indicator; cashier queue gains a Cabang column.

### Tests / build
- New `RmePilotDataEntryHardeningTest` (26 passed). Updated isolation tests (isolation = non-RME branch) in `CashierBillingTest`, `RmePaymentTest`, `LabIntegrationTest`, `RmeLabWorkflowPolishTest`, `OdontogramTest` (×10), `MedicalRecordTest` (×3), `MedicalRecordFinalizationTest`. Focused suites: RME 434 (1112 assertions), Permission|Sidebar|Branch 526 (1605 assertions) — all passed. `pint --dirty` OK; `npm run build` OK. Full end-to-end suite not run (runtime budget).

### Final status
PASS — local only; no automatic legacy backfill; `mst_clinics`/`clinic_id` preserved; Lab remains global; full-payment-only rule unchanged.

### Next phase
Sprint 23 Phase 23.10.1 — VPS Deploy + RME Pilot Data Entry Smoke (backup DB first, `migrate --force` only; browser-smoke new/existing patient → odontogram → medical record → cashier billing → payment → lab candidate across TKM1/LDK2/ATG3).

## Sprint 23 Phase 23.10.2 — Odontogram Additional Conditions and Notes Input Fix

- Branch: `feature/sprint-23-phase-23-10-2-odontogram-additional-fields` (from `sprint-23-phase-23-10-rme-pilot-data-entry-hardening` / `bf3a43a`). Tag `sprint-23-phase-23-10-2-odontogram-additional-fields`. Local only — no VPS deploy, no push, no destructive DB commands. Full doc: `docs/sprint_23_phase_23_10_2_odontogram_additional_fields.md`.

### Smoke bug (from 23.10.1)
- VPS browser smoke found that while filling the odontogram, the general **"kondisi tambahan"** and **"catatan odontogram"** inputs did not appear — only per-tooth conditions (hidden until a tooth is selected) and a single generic "Catatan Ringkasan" field existed.

### Fix
- Added a **"Kondisi Tambahan & Catatan Odontogram"** section on the odontogram fill/edit page, **visible and editable before finalization** (draft + `manage_clinic_visits`), with `old()` + saved values. "Kondisi Tambahan" → new additive `additional_conditions` column; "Catatan Odontogram" → existing `summary_notes` (relabelled). Additive migration `2026_06_18_100001` adds only `additional_conditions` (text nullable); no backfill, no `notes` column.
- Save: request validation `additional_conditions nullable|string|max:5000`; whitelisted in `OdontogramService::updatePlaceholder`; model/factory/repo updated. Show/print: both fields shown on show (read-only when finalized/viewer), odontogram print, and the visit print bundle; escaped, null-safe. Finalization preserves both fields and renders them read-only via existing rules.

### Tests / build
- New `OdontogramAdditionalFieldsTest` (15 passed). Focused suites: Odontogram 103 (252), RmePilotDataEntryHardeningTest 26 (67), RmeVisitListBranchFilterTest 16 (45), RmeClinicSourceFromBranchTest 15 (32), Rme 511 (1438) — all passed. `pint --dirty` OK; `npm run build` OK.

### Final status
PASS — local only; tooth-grid UI unchanged; no old-data rewrite; finalized behavior preserved.

### Next phase
Sprint 23 Phase 23.10.3 — VPS Deploy + Odontogram Additional Fields Smoke (backup DB first, `migrate --force` only; browser-smoke fields visible/editable before finalization and preserved after finalization).

## Sprint 23 Phase 23.10.4 — Odontogram Selected Results Table Notes Fix

- Branch: `feature/sprint-23-phase-23-10-4-odontogram-selected-results-table` (from `sprint-23-phase-23-10-2-odontogram-additional-fields` / `3378500`). Tag `sprint-23-phase-23-10-4-odontogram-selected-results-table`. Local only — no VPS deploy, no push, no destructive DB commands. Full doc: `docs/sprint_23_phase_23_10_4_odontogram_selected_results_table.md`.

### Correction (from 23.10.2)
- The 23.10.2 **global** "Kondisi Tambahan" / "Catatan Odontogram" textareas were wrong for the clinic workflow. Corrected to a **per-selected-row** model: each tooth marked on the FDI grid renders as a row in a new **"Hasil Odontogram yang Dipilih"** table with per-row **Kondisi Tambahan** (`additional_condition`) and **Catatan Tambahan** (`additional_note`).

### Fix
- Storage: per-row data lives in `tooth_map_payload.teeth.<num>` (new optional `additional_condition` / `additional_note` keys) — **no new migration**. Old payloads without the keys render `—` safely. Validation `tooth_map_payload.teeth.*.additional_condition|additional_note nullable|string|max:1000`; service whitelist/normalization preserve the keys.
- UI: `show.blade.php` table (live Alpine edit while draft; server-rendered read-only when finalized/viewer) + empty state `Belum ada kondisi odontogram yang dipilih.`; `app.js` `odontogramEditor` gains `selectedRows`/`statusLabel`/`setAdditional` and preserves per-row fields on status re-apply. Odontogram print and visit print bundle gain the per-row columns. Previous global fields retained but de-emphasized as optional general "Catatan Umum Odontogram" (legacy); `additional_conditions`/`summary_notes` columns kept, finalized behavior and tooth-grid unchanged.

### Tests / build
- New `OdontogramSelectedResultsTableTest` (23 passed, 64 assertions). Focused: Odontogram + RmePilotDataEntryHardeningTest + RmeVisitListBranchFilterTest + RmeClinicSourceFromBranchTest + RmePdfPrintHardeningTest 202 (527) — all passed. Broader Rme|ClinicVisit|Patient|Permission|Sidebar|Branch — all passed (exit 0). `pint --dirty` OK; `npm run build` OK.

### Final status
PASS — local only; no new migration; no column removal; no data rewrite; tooth-grid and finalized behavior preserved.

### Next phase
Sprint 23 Phase 23.10.5 — VPS Deploy + Odontogram Selected Results Table Smoke (backup DB first, `migrate --force` only; browser-smoke the selected results table appears, updates live, and saves per-row Kondisi Tambahan / Catatan Tambahan, preserved after finalization).

---

## Sprint 23 Phase 23.10.6 — Merge Odontogram Selected Results into Medical Record Print

- Branch: `feature/sprint-23-phase-23-10-6-medical-record-print-odontogram-merge` (from `sprint-23-phase-23-10-5-rme-cashier-branch-clinical-summary` / `e48c645`). Tag `sprint-23-phase-23-10-6-medical-record-print-odontogram-merge`. Local only — no VPS deploy, no push, no destructive DB commands, **no migration**. Full doc: `docs/sprint_23_phase_23_10_6_medical_record_print_odontogram_merge.md`.

### Goal
- Cetak Rekam Medis (combined visit print bundle, `rme.visits.print` → `print.blade.php` / `print-pdf.blade.php` via `partials/print-body.blade.php`) is the **main combined print output** and now embeds the **"Hasil Odontogram yang Dipilih"** selected-results table. The user no longer needs to open the separate Cetak Odontogram to see odontogram results.

### Change
- Extracted shared partial `resources/views/rme/visits/partials/odontogram-selected-results.blade.php` (subsection title + merged table + safe empty states) and included it from `print-body.blade.php`, replacing the inline odontogram table to avoid duplicate logic.
- Merged, user-friendly columns: **No**, **Gigi / Area**, **Kondisi Odontogram**, **Tanda Klinis / Kondisi Tambahan** (`conditions` + `additional_condition`), **Catatan Gigi / Catatan Tambahan** (`note` + `additional_note`).
- Empty states: no odontogram → `Belum ada data odontogram.` (kept legacy `Odontogram belum tersedia.` for existing ClinicVisitTest); odontogram with no selected rows → `Belum ada kondisi odontogram yang dipilih.`; empty cell → `—`. All output Blade-escaped; no raw `tooth_map_payload` JSON.
- Data source: odontogram linked to the visit — `tooth_map_payload.teeth.<num>.status` / `.additional_condition` / `.additional_note`. Old payloads without the keys render `—` safely.
- Separate Cetak Odontogram (`rme.odontograms.print` / `odontogram/print.blade.php`) left untouched and still works. Phase 23.10.5 cashier branch scoping + clinical summary (e48c645) and 23.10.4 selected-results behavior (d461ad8) preserved.

### Tests / build
- New `MedicalRecordPrintOdontogramMergeTest` (11 passed, 32 assertions). Regression: `OdontogramSelectedResultsTableTest | RmePdfPrintHardeningTest | Odontogram | RmePilotDataEntryHardeningTest | RmeVisitListBranchFilterTest | RmeClinicSourceFromBranchTest | ClinicVisit` 278 passed (762 assertions). Broader `Rme | Patient | Permission | Sidebar | Branch` 953 passed (2843 assertions). `pint --dirty` OK; `npm run build` OK.

### Final status
PASS — local only; no new migration; no column removal; separate odontogram print preserved; cashier branch scoping + clinical summary (e48c645) intact.

### Next phase
Sprint 23 Phase 23.10.7 — VPS deploy + browser smoke of the combined Cetak Rekam Medis (backup DB first, `migrate --force` only; confirm odontogram selected-results table renders in the medical record print without opening Cetak Odontogram).

---

## Sprint 24 — RME Receivable / Payment Hardening Track

Sprint 24 delivered RME receivable/payment hardening: partial-payment (cicilan) foundation, Piutang RME dashboard, Owner Dashboard receivable + follow-up KPIs, receivable aging buckets with CSV export, and a receivable follow-up/reminder foundation. Each foundation phase was followed by a VPS browser smoke validation.

Phase tags (`creatordate` order): 24.1 `sprint-24-phase-24-1-rme-partial-payment-foundation` (`ed36d6a`) · 24.2.1 hotfix `sprint-24-phase-24-2-1-rme-new-patient-branch-consistency` (`bc5e480`) · 24.2 `sprint-24-phase-24-2-vps-rme-partial-payment-smoke` (`a09f0a5`) · 24.3 `sprint-24-phase-24-3-rme-receivable-dashboard-foundation` (`7dcacd4`) · 24.3 VPS `sprint-24-phase-24-3-vps-piutang-rme-smoke` (`9aff71c`) · Graphify `sprint-24-graphify-sprint-22-to-24-update` (`a167791`) · 24.4 `sprint-24-phase-24-4-owner-dashboard-rme-receivable-kpi` (`afbc3e3`) · 24.5 `sprint-24-phase-24-5-vps-owner-dashboard-receivable-kpi-smoke` (`7ceb0c0`) · Graphify `sprint-24-graphify-sprint-24-4-to-24-5-update` (`ae5fb4a`) · 24.6 `sprint-24-phase-24-6-rme-receivable-aging-export-foundation` (`28c9361`) · 24.7 `sprint-24-phase-24-7-vps-rme-receivable-aging-export-smoke` (`fd27c43`) · 24.8 `sprint-24-phase-24-8-rme-receivable-follow-up-reminder-foundation` (`f0a4a61`) · 24.9 `sprint-24-phase-24-9-vps-rme-receivable-follow-up-smoke` (`43cfcd5`) · 24.10 `sprint-24-phase-24-10-owner-dashboard-receivable-follow-up-kpi` (`ea17ce4`) · 24.11 `sprint-24-phase-24-11-vps-owner-dashboard-follow-up-kpi-smoke` (`b15b936`).

### Sprint 24 Phase 24.12 — Closure RC / Go-No-Go

Branch `feature/sprint-24-phase-24-12-closure-rc-go-no-go` (from `b15b936`). Closure/documentation only — no new product features, no payment/follow-up/dashboard logic changes. Full doc: `docs/sprint_24_phase_24_12_closure_rc_go_no_go.md`.

- **Sprint 24 status:** Closure RC / Go-No-Go.
- **Final sprint status:** GO release candidate.
- **Key phase range:** 24.1–24.12 (all 15 Sprint 24 tags present and coherent; none MISSING).
- **Final tag recommendation:** `sprint-24-phase-24-12-closure-rc-go-no-go`.
- **No payment logic regression.** Full-payment-only constraint intentionally superseded by the 24.1 partial-payment foundation; follow-up/dashboard logic unchanged in closure.
- **VPS smoke coverage:** through Phase 24.11 (Owner Dashboard follow-up KPI cards, branch filter, billing-shortcut permission, `follow_up_filter` URLs, CSV export — all PASS; Laravel log CLEAN).

Quality gates were limited to targeted Sprint 24 regression coverage under Limit Saver 1 mode (CashierBillingTest 28, RmeReceivableFollowUpTest 9, OwnerDashboardReceivableFollowUpKpiTest 8, OwnerDashboardRmeLabKpiTest 11, OwnerDashboardBranchFilterDrilldownTest 13 — 69 passed / 256 assertions). Routes verified, `view:cache` OK, `pint --dirty` passed, `git diff --check` clean. No full suite was run for the closure step.

Decision: **GO**.

---

## Sprint 27 Phase 27.3 — RME Follow-up / Control Visit Workflow

**Branch:** `feature/sprint-27-phase-27-3-rme-control-visit-workflow`
**Doc:** `docs/sprint_27_phase_27_3_rme_control_visit_workflow.md`

- Control visits reuse existing patient RM; no duplicate patient on follow-up.
- New visits link to prior visit via `follow_up_of_visit_id`; `visit_type` distinguishes baru/kontrol/lanjutan/emergency.
- RME, odontogram, and parent invoices are not auto-mutated.
- UI: visit type on create form, **Buat Kontrol**, patient visit history panel, doctor/cashier/odontogram context for control visits.

---

## Sprint 27 Phase 27.4 — RME Control Visit Receivable Carry-Over Payment Allocation

**Branch:** `feature/sprint-27-phase-27-4-rme-control-receivable-carry-over-payment-allocation`
**Doc:** `docs/sprint_27_phase_27_4_rme_control_receivable_carry_over_payment_allocation.md`

- FIFO carry-over: parent receivables paid first from control cashier payment; remainder applies to control invoice.
- Separate invoices preserved — no item merge/move between parent and control invoices.
- Cashier UI shows **Piutang Kunjungan Sebelumnya**, **Tagihan Kontrol Hari Ini**, **Total Harus Dibayar**, and receipt **Alokasi Pembayaran**.
- Additive migration: nullable `payment_batch_uuid` on `trx_rme_payments` for grouped split payments.
- Service: `RmeControlReceivableService` + `RmePaymentService::allocateControlPayment()`.

---

## Sprint 27 Phase 27.4.1 — Control Visit Free Follow-up Completion Rule (Hotfix)

**Branch:** `feature/sprint-27-phase-27-4-1-control-visit-free-follow-up-completion-rule`
**Doc:** `docs/sprint_27_phase_27_4_1_control_visit_free_follow_up_completion_rule.md`

- Hotfix to Phase 27.4 completion rule: a **free follow-up** control visit no longer gets stuck at
  `cashier_pending` after paying an installment toward a previous-visit receivable.
- Control visit completion now depends only on the **control invoice's own remaining balance**, never on
  the combined parent + current total. Parent receivables are payable from the control screen but never
  block control-visit status.
- Free control (control invoice remaining 0): `completed` once any payment is recorded in the batch.
  Control with additional cost: `completed` only when its own invoice is fully paid. No payment → not
  auto-completed. Normal non-control `pay()` flow unchanged.
- Change isolated to `RmePaymentService` (`completeControlVisitIfSettled()` helper). No migration, no new
  route/service/test file. 7 cases added to existing `RmeControlVisitReceivableCarryOverPaymentTest`
  (33 passed / 103 assertions).

---

## Sprint 27 Phase 27.4.2 — Exclude Zero-Remaining Invoices from Active Receivables (Hotfix)

**Branch:** `feature/sprint-27-phase-27-4-2-exclude-zero-remaining-rme-receivables`
**Doc:** `docs/sprint_27_phase_27_4_2_exclude_zero_remaining_rme_receivables.md`

- Bug (VPS): after Phase 27.4.1, free control invoices (`grand_total` 0, `paid_amount` 0,
  `status` UNPAID) still appeared on `/rme/cashier/receivables` even though there was nothing to
  collect. Other already-settled invoices with a stale UNPAID/PARTIAL status could leak in too.
- Final business rule: an **active receivable** is one with a real outstanding balance —
  `grand_total > 0` **and** `grand_total > SUM(payments.amount)` (i.e. `remaining > 0`). Invoices with
  `grand_total = 0`, `remaining = 0`, or `paid_amount >= grand_total` are not receivables.
- Zero-value invoices (e.g. free control visits) remain in the system as billing/history records; they
  are simply excluded from the **active receivables** list, aging summary, counts, pagination, follow-up
  offers, and CSV export.
- Fix is query-level only: new `applyActiveReceivableConstraint()` helper applied inside the shared
  `RmeInvoiceController::receivableQuery()`, so listing / aging / export stay consistent and pre-existing
  Rp0 UNPAID rows auto-drop without any data mutation.
- No migration. No data edits. Payment allocation (27.4) and completion rule (27.4.1) unchanged. 5 cases
  added to `CashierBillingTest` + 2 regression cases in `RmeControlVisitReceivableCarryOverPaymentTest`.

---

## Sprint 27 Phase 27.5 — RME Control Workflow Stabilization & Regression Closure

**Branch:** `feature/sprint-27-phase-27-5-rme-control-workflow-stabilization-regression-closure`
**Doc:** `docs/sprint_27_phase_27_5_rme_control_workflow_stabilization_regression_closure.md`

### Scope

- Stabilization and regression closure for RME control visit workflow.
- Documents final business rules from Phase 27.4, 27.4.1, and 27.4.2.
- Adds operator checklist for control registration, free control billing, parent receivable installment, visit completion check, active receivables check, receipt check, and receivable export check.
- Adds developer regression checklist for focused test commands and manual smoke.
- No migration expected.
- No destructive data operation.

### Closure posture

- Control visit keeps the same patient/RM but creates a new visit.
- Previous visit, RME, odontogram, invoice, and invoice items are not overwritten.
- Carry-over payment allocation remains FIFO: parent/previous invoice first, then current control invoice.
- Parent receivable does not block control visit completion.
- Free control can complete after a payment batch even if parent receivable remains partial.
- Paid control completes only when the current control invoice is fully paid.
- Rp0 control invoice remains billing/history but is excluded from active receivables, aging, and export.

### Regression anchor

- `tests/Feature/RME/RmeControlWorkflowStabilizationClosureTest.php`
- `tests/Feature/RME/RmeControlVisitReceivableCarryOverPaymentTest.php`
- `tests/Feature/RME/CashierBillingTest.php`
- `tests/Feature/RME/ClinicVisitControlWorkflowTest.php`

---

## Sprint 27 Phase 27.7 — RME Control Workflow Final Closure & Sprint 27 GO/NO-GO Report

**Branch:** `feature/sprint-27-phase-27-7-rme-control-workflow-final-closure-go-no-go-report`
**Doc:** `docs/sprint_27_phase_27_7_rme_control_workflow_final_closure_go_no_go_report.md`
**Mode:** Final closure / GO-NO-GO report-only
**Deployment:** Not deployed in this phase
**Migration:** No migration

### Scope

- Final closure report for Sprint 27 RME Control Workflow.
- Consolidates Phase 27.3, 27.4, 27.4.1, 27.4.2, 27.5, and skipped/already-done 27.6 posture.
- Confirms Phase 27.5 merge anchor `f74ad78` and feature anchor `e8cbb8a`.
- Confirms Phase 27.4.2 anchors `82155c8` and `b908722`.
- Documents final Sprint 27 GO/NO-GO posture.
- Keeps the closure report-only and safety-first.

### Final RME control workflow posture

- Control visits reuse the same patient/RM but always create a new visit.
- Old visit, RME, odontogram, invoice, and invoice items are not overwritten.
- Carry-over payment allocation remains FIFO: previous receivable first, then current control invoice.
- Parent receivable does not block control visit completion.
- Free control may complete after payment batch to old receivable while parent invoice remains `UNPAID` or `PARTIAL`.
- Paid control completes only when the current control invoice is fully paid.
- Rp0 control invoice remains billing/history but is excluded from active receivables, aging, and export.
- Active receivables only include invoices with remaining balance > 0.
- Receipt must show allocation when payment batch is split between parent and control invoices.

### Safety

No deployment, no migration, no destructive data operation, and no production code change.

### Decision

GO CANDIDATE FOR PR REVIEW.

Final Sprint 27 GO tag is allowed only after focused validation passes, PR is reviewed, PR is merged into the base branch, and the final GO tag is created on the merge commit.

---

## Sprint 28 Phase 28.0 — Post Sprint 27 Baseline, Pilot Readiness & Next Backlog Planning

**Branch:** `feature/sprint-28-phase-28-0-post-sprint-27-baseline-pilot-readiness-backlog-planning`
**Doc:** `docs/sprint_28_phase_28_0_post_sprint_27_baseline_pilot_readiness_backlog_planning.md`
**Mode:** Planning / baseline / backlog alignment only
**Deployment:** Not deployed in this phase
**Migration:** No migration
**Baseline:** Sprint 27 RME Control Workflow GO at `c9e378a`

### Scope

- Planning baseline after Sprint 27 RME Control Workflow GO.
- Confirms Sprint 27 final GO tag `sprint-27-rme-control-workflow-go`.
- Defines pilot readiness posture after RME control workflow closure.
- Defines candidate Sprint 28 backlog lanes:
  - Pilot readiness and operator smoke checklist.
  - WhatsApp appointment reminder and receivable follow-up planning.
  - RME cashier/reporting polish.
  - Monitoring, backup, and restore rehearsal.
  - Branch rollout readiness.
- Recommends Sprint 28 Phase 28.1 as a low-risk pilot readiness/operator smoke checklist phase.
- Keeps Sprint 28.0 planning-only and non-destructive.

### Safety

No deployment, no migration, no destructive data operation, no production code change, and no RME/payment/receivable business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 28 Phase 28.1 — Pilot Readiness & Operator Smoke Checklist

**Branch:** `feature/sprint-28-phase-28-1-pilot-readiness-operator-smoke-checklist`
**Doc:** `docs/sprint_28_phase_28_1_pilot_readiness_operator_smoke_checklist.md`
**Mode:** Pilot readiness / operator smoke checklist only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Baseline:** Sprint 28.0 GO at `c36b852`

### Scope

- Docs/checklist only — turns Sprint 28.0 Lane A into an actionable operator-facing checklist.
- Operator smoke checklist: login/role, patient search, registration, visit creation, RME input, odontogram print, cashier billing, payment receipt, receivable check, report export/print, logout.
- RME control workflow smoke checklist protecting Sprint 27 GO behavior: same patient/RM, new visit created, old visit/RME/odontogram/invoice not overwritten, parent receivable visible in cashier control, FIFO previous-receivable-first allocation, parent receivable does not block completion, Rp0 invoice excluded from active receivables.
- Support/admin checklist: Laravel log check, backup presence, disk usage, route/menu quick check, user/role quick check, operator feedback collection notes.
- GO/NO-GO criteria and recommended Sprint 28 Phase 28.2 options.

### Safety

No deployment, no migration, no destructive data operation, no production code change, and no RME/payment/receivable business rule change.

### Recommended Next Phase

Sprint 28 Phase 28.2 — one of: Pilot daily operation runbook, WhatsApp Reminder & Receivable Follow-up Workflow Planning, or Monitoring/backup/restore rehearsal.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 28 Phase 28.2 — Pilot Daily Operation Runbook

**Branch:** `feature/sprint-28-phase-28-2-pilot-daily-operation-runbook`
**Doc:** `docs/sprint_28_phase_28_2_pilot_daily_operation_runbook.md`
**Mode:** Pilot daily operation runbook only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Baseline:** Sprint 28.1 GO at `fa9842f`

### Scope

- Docs/runbook/test only — turns the Sprint 28.1 pilot readiness and operator smoke checklist into a practical daily operation runbook for pilot use.
- Daily runbook with pilot roles/responsibilities and how-to-use guidance.
- Pre-opening checklist: app reachable, operator account ready, menu visibility, printer/browser print, backup presence, Laravel log baseline, disk usage, feedback log ready.
- Daily operator flow: login, patient search, new patient registration, visit creation, RME visit input, odontogram input, odontogram/RME print, logout/session check.
- RME control visit daily guardrail protecting Sprint 27 GO behavior: same patient/RM, new visit created, old data protected, parent receivable visible, FIFO allocation protected, completion not blocked by parent receivable, Rp0 invoice excluded from active receivables.
- Cashier/receivable flow: open cashier billing, check invoice amount, check previous receivable, process payment, verify FIFO behavior, print receipt, active receivable check.
- Support/admin monitoring flow: Laravel log check, backup presence, disk usage, route/menu quick check, user/role quick check, feedback collection check.
- Operator feedback log format with privacy note, end-of-day closing checklist, escalation rules, GO/NO-GO criteria, and recommended Sprint 28 Phase 28.3 options.

### Safety

No deployment, no migration, no destructive operation, no production code change, and no RME/payment/receivable/cashier business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 28 Phase 28.3 — WhatsApp Reminder & Receivable Follow-up Workflow Planning

**Branch:** `feature/sprint-28-phase-28-3-whatsapp-reminder-receivable-follow-up-workflow-planning`
**Doc:** `docs/sprint_28_phase_28_3_whatsapp_reminder_receivable_follow_up_workflow_planning.md`
**Mode:** WhatsApp reminder / receivable follow-up workflow planning only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Integration change:** No WhatsApp/API integration implemented
**Baseline:** Sprint 28.2 GO at `05539ef`

### Scope

- Appointment reminder workflow planning.
- Receivable/piutang follow-up workflow planning.
- Manual operator/cashier handling checklist.
- Privacy-safe message template drafts.
- RME control workflow safety notes.
- Manual log format.
- Future automation candidate design.
- Risk/mitigation and GO/NO-GO.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No WhatsApp/API integration.
- No RME/payment/receivable/cashier business rule change.

### Recommended Next Phase

Sprint 28 Phase 28.4 — one of: Monitoring/backup/restore rehearsal, WhatsApp reminder manual pilot SOP, Pilot issue triage and stabilization backlog, or WhatsApp reminder technical design (planning-only).

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 28 Phase 28.4 — Monitoring, Backup & Restore Rehearsal Planning

**Branch:** `feature/sprint-28-phase-28-4-monitoring-backup-restore-rehearsal-planning`
**Doc:** `docs/sprint_28_phase_28_4_monitoring_backup_restore_rehearsal_planning.md`
**Mode:** Monitoring / backup / restore rehearsal planning only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Backup execution:** No real backup executed
**Restore execution:** No real restore executed
**Baseline:** Sprint 28.3 GO at `7f54016`

### Scope

- Monitoring planning checklist.
- Backup readiness checklist.
- Restore rehearsal planning.
- Restore verification checklist.
- RME/payment/receivable safety notes.
- Support/admin daily evidence format.
- Incident escalation rules.
- Future implementation candidate backlog.
- Risk/mitigation and GO/NO-GO.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No real backup execution.
- No real restore execution.
- No RME/payment/receivable/cashier business rule change.

### Recommended Next Phase

Sprint 28 Phase 28.5 — one of: Pilot issue triage and stabilization backlog, WhatsApp reminder manual pilot SOP, Monitoring/backup/restore rehearsal execution on a non-production target, or WhatsApp reminder technical design (planning-only).

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 28 Phase 28.5 — Pilot Issue Triage & Stabilization Backlog

**Branch:** `feature/sprint-28-phase-28-5-pilot-issue-triage-stabilization-backlog`
**Doc:** `docs/sprint_28_phase_28_5_pilot_issue_triage_stabilization_backlog.md`
**Mode:** Pilot issue triage / stabilization backlog planning only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Bug fix execution:** No bug fix implemented
**Baseline:** Sprint 28.4 GO at `1086d0f`

### Scope

- Issue intake sources.
- Issue intake form.
- Severity classification.
- Stabilization lane categories.
- RME control workflow triage guardrails.
- Cashier/receivable triage guardrails.
- Pilot backlog template.
- GO/NO-GO decision matrix.
- Support/admin daily triage checklist.
- Privacy/evidence rules.
- Future stabilization candidate backlog.
- Risk/mitigation and GO/NO-GO.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No bug fix implementation.
- No RME/payment/receivable/cashier business rule change.

### Recommended Next Phase

Sprint 28 Phase 28.6 — one of: Pilot stabilization backlog prioritization, WhatsApp reminder manual pilot SOP, Monitoring/backup/restore rehearsal execution on a non-production target, RME/cashier high-risk regression stabilization planning, or Sprint 28 closure GO/NO-GO report.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 28 Phase 28.6 — Sprint 28 Closure GO/NO-GO Report

**Branch:** `feature/sprint-28-phase-28-6-sprint-28-closure-go-no-go-report`
**Doc:** `docs/sprint_28_phase_28_6_sprint_28_closure_go_no_go_report.md`
**Mode:** Sprint 28 closure / GO-NO-GO report only
**Deployment:** No deployment
**Migration:** No migration
**Production code change:** No production code change
**Bug fix execution:** No bug fix implemented
**Baseline:** Sprint 28.5 GO at `3e44a8d`

### Scope

- Consolidates Sprint 28.0–28.5 deliverables.
- Confirms Sprint 28 pilot readiness posture.
- Confirms RME Control Workflow protection summary.
- Confirms payment/receivable/cashier safety posture.
- Confirms no production code/runtime behavior change.
- Defines Sprint 28 final GO/NO-GO criteria.
- Recommends Sprint 29 candidate starting lanes.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No bug fix implementation.
- No RME/payment/receivable/cashier business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 29 Phase 29.0 — Pilot Stabilization Backlog Prioritization

**Branch:** `feature/sprint-29-phase-29-0-pilot-stabilization-backlog-prioritization`
**Doc:** `docs/sprint_29_phase_29_0_pilot_stabilization_backlog_prioritization.md`
**Mode:** pilot stabilization backlog prioritization only
**Deployment:** no deployment
**Migration:** no migration
**Production code change:** no production code change
**Bug fix execution:** no bug fix implemented
**Runtime behavior change:** no runtime behavior change
**Baseline:** Sprint 28 closure GO at `b55d485`

### Scope

- Uses Sprint 28.0–28.6 outputs as input.
- Defines prioritization principles.
- Defines P0/P1/P2/P3/P4/NEEDS CONFIRMATION levels.
- Defines scoring model.
- Defines stabilization lanes.
- Protects RME Control Workflow guardrails.
- Protects cashier/payment/receivable guardrails.
- Adds prioritized backlog template.
- Recommends Sprint 29 candidate phases.
- Defines GO/NO-GO decision.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No bug fix implementation.
- No stabilization implementation.
- No runtime behavior change.
- No RME/payment/receivable/cashier business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 29 Phase 29.1 — P0/P1 RME Control Workflow Regression Stabilization Planning

**Branch:** `feature/sprint-29-phase-29-1-p0-p1-rme-control-workflow-regression-stabilization-planning`
**Doc:** `docs/sprint_29_phase_29_1_p0_p1_rme_control_workflow_regression_stabilization_planning.md`
**Mode:** P0/P1 RME control workflow regression stabilization planning only
**Deployment:** no deployment
**Migration:** no migration
**Production code change:** no production code change
**Bug fix execution:** no bug fix implemented
**Stabilization execution:** no stabilization implemented
**Runtime behavior change:** no runtime behavior change
**Baseline:** Sprint 29.0 GO at `21ff95a`

### Scope

- Defines P0/P1 RME Control Workflow regression risk.
- Defines evidence requirements before implementation.
- Documents RME Control Workflow invariants.
- Documents cashier/payment/receivable connected guardrails.
- Adds P0/P1 triage matrix.
- Adds stabilization planning checklist.
- Adds future regression test planning.
- Adds future implementation sequencing.
- Defines GO/NO-GO decision.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No bug fix implementation.
- No stabilization implementation.
- No runtime behavior change.
- No RME/payment/receivable/cashier business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 29 Phase 29.2 — Cashier Payment Receivable High-Risk Stabilization Planning

**Branch:** `feature/sprint-29-phase-29-2-cashier-payment-receivable-high-risk-stabilization-planning`
**Doc:** `docs/sprint_29_phase_29_2_cashier_payment_receivable_high_risk_stabilization_planning.md`
**Mode:** cashier/payment/receivable high-risk stabilization planning only
**Deployment:** no deployment
**Migration:** no migration
**Production code change:** no production code change
**Bug fix execution:** no bug fix implemented
**Stabilization execution:** no stabilization implemented
**Runtime behavior change:** no runtime behavior change
**Baseline:** Sprint 29.1 GO at `39b4fd9`

### Scope

- Defines P0/P1 cashier/payment/receivable high-risk issues.
- Defines evidence requirements before implementation.
- Documents cashier/payment/receivable invariants.
- Documents RME control visit connected guardrails.
- Adds P0/P1 triage matrix.
- Adds stabilization planning checklist.
- Adds future regression test planning.
- Adds future implementation sequencing.
- Defines GO/NO-GO decision.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No bug fix implementation.
- No stabilization implementation.
- No runtime behavior change.
- No RME/payment/receivable/cashier business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 29 Phase 29.3 — WhatsApp Reminder Manual Pilot SOP

**Branch:** `feature/sprint-29-phase-29-3-whatsapp-reminder-manual-pilot-sop`
**Doc:** `docs/sprint_29_phase_29_3_whatsapp_reminder_manual_pilot_sop.md`
**Mode:** WhatsApp reminder manual pilot SOP only
**Deployment:** no deployment
**Migration:** no migration
**Production code change:** no production code change
**WhatsApp API integration:** no WhatsApp API integration
**WhatsApp automation:** no WhatsApp automation
**Queue/job/notification change:** no queue/job/notification change
**Runtime behavior change:** no runtime behavior change
**Baseline:** Sprint 29.2 GO at `266a0d2`

### Scope

- Defines manual appointment reminder SOP.
- Defines manual receivable/piutang follow-up SOP.
- Defines cashier/payment/receivable guardrails.
- Defines RME control visit connected guardrails.
- Defines privacy and consent rules.
- Adds approved manual message templates.
- Adds manual log template.
- Adds escalation rules.
- Adds manual pilot daily checklist.
- Adds future automation readiness criteria.
- Defines GO/NO-GO decision.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production code change.
- No WhatsApp API integration.
- No WhatsApp automation.
- No queue/job/notification change.
- No bug fix implementation.
- No stabilization implementation.
- No runtime behavior change.
- No RME/payment/receivable/cashier business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 29 Phase 29.4 — Monitoring Backup Restore Rehearsal on Non-Production Target

**Branch:** `feature/sprint-29-phase-29-4-monitoring-backup-restore-rehearsal-non-production-target`
**Doc:** `docs/sprint_29_phase_29_4_monitoring_backup_restore_rehearsal_non_production_target.md`
**Mode:** monitoring backup restore rehearsal planning/SOP only
**Target:** non-production target only
**Production server:** not touched
**Deployment:** no deployment
**Migration:** no migration
**Production code change:** no production code change
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Backup automation:** no backup automation implemented
**Monitoring automation:** no monitoring automation implemented
**Runtime behavior change:** no runtime behavior change
**Baseline:** Sprint 29.3 GO at `06c5d81`

### Scope

- Defines non-production target rules.
- Defines monitoring readiness SOP.
- Defines backup inventory SOP.
- Defines restore rehearsal SOP on non-production target.
- Defines data privacy and safety rules.
- Adds rehearsal evidence template.
- Adds P0/P1 escalation matrix.
- Adds pilot daily monitoring checklist.
- Adds future implementation/rehearsal sequencing.
- Defines GO/NO-GO decision.

### Safety

- No deployment.
- No migration.
- No destructive data operation.
- No production/VPS access.
- No real backup execution.
- No real restore execution.
- No production code change.
- No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change.
- No runtime behavior change.
- No RME/payment/receivable/cashier/WhatsApp business rule change.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 29 Phase 29.5 — Pilot Safety Review & Final Stabilization Checklist

**Branch:** `feature/sprint-29-phase-29-5-pilot-safety-review-final-stabilization-checklist`
**Doc:** `docs/sprint_29_phase_29_5_pilot_safety_review_final_stabilization_checklist.md`
**Mode:** pilot safety review / final stabilization checklist-test only
**Production server:** not touched
**Deployment:** no deployment
**Migration:** no migration
**Production code change:** no production code change
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Runtime behavior change:** no runtime behavior change
**Baseline:** Sprint 29.4 GO at `b6334fc`

### Scope

- Consolidates Sprint 29.0–29.4 into a final pilot safety review.
- Defines the P0/P1 final stabilization checklist.
- Defines safety gates before Sprint 30.
- Defines the pilot operational smoke checklist (defined, not executed).
- Adds a pilot evidence template.
- Defines Go / Watch / No-Go criteria and a decision matrix.

### Safety

- No production code change.
- No migration.
- No deployment.
- No production/VPS access.
- No real backup/restore execution.
- No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change.
- No runtime behavior change.
- No RME/payment/receivable/cashier/WhatsApp business rule change.

### Next recommended sprint

Sprint 30 — Pilot Execution Bugfix & Operational Smoke.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 30 — Pilot Execution Bugfix & Operational Smoke

**Branch:** `feature/sprint-30-pilot-execution-bugfix-operational-smoke`
**Doc:** `docs/sprint_30_pilot_execution_bugfix_operational_smoke.md`
**Mode:** local pilot execution simulation + operational smoke + safe bugfix (local-only)
**Production server:** not touched
**Deployment:** no deployment
**Migration:** no migration
**Production code change:** no production code change (no bug required a fix)
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Runtime behavior change:** no runtime behavior change
**Baseline:** Sprint 29.5 GO at `721bb55`

### Scope

- Executes the Sprint 29.5 pilot operational smoke checklist locally (defined in 29.5, executed here).
- Validates core clinic/lab paths: patient identity, RME visit, odontogram/treatment note,
  invoice, payment, receivable/piutang, receipt/print/export, cashier, RME control,
  WhatsApp manual reminder evidence, reporting/export, and pilot role/menu/permission access.
- References Sprint 29.4 / 29.5 monitoring + backup/restore evidence readiness (not executed).

### Bugfixes / tests / docs summary

- **Bugfixes:** none — no production code bugfix required in this local pass.
- **Tests:** added `tests/Feature/Sprint30/Sprint30PilotExecutionBugfixOperationalSmokeTest.php`
  (checklist/documentation completeness). Operational smoke evidence: 303 targeted tests
  passed (202 core + 101 secondary) with no regressions.
- **Docs:** added `docs/sprint_30_pilot_execution_bugfix_operational_smoke.md`; updated this history.

### Safety

- Local-only validation.
- No deployment / no VPS / no production action.
- No real backup/restore.
- No automation/runtime behavior change outside approved bugfixes (none needed).
- No dependency install. No `.env` change. No GO tag.

### Next recommended phase

Supervised local-then-pilot operational smoke execution session with operator evidence capture,
followed by the Sprint 29.4/29.5 monitoring + backup/restore rehearsal against a non-production
target. Production/VPS pilot action remains gated on explicit owner approval.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 31 — Backup Restore Rehearsal Execution & Recovery Readiness

**Branch:** `feature/sprint-31-backup-restore-rehearsal-execution-recovery-readiness`
**Doc:** `docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md`
**Mode:** docs / non-production rehearsal execution checklist / recovery readiness test only
**Baseline:** Sprint 30 GO at `53c3442`
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Production code change:** no production code change
**Migration:** no migration
**Deployment:** no deployment
**Runtime behavior change:** no runtime behavior change

### Scope

- Converts Sprint 29.4 and Sprint 29.5 backup/restore readiness planning into a controlled,
  auditable **non-production backup/restore rehearsal execution checklist** plus recovery
  readiness closure.
- Defines backup inventory, restore rehearsal steps, recovery readiness gates, post-restore
  smoke, an evidence template, and an incident/escalation matrix.
- **Non-production target only** — isolated DB and isolated runtime file target; no production
  overwrite; commands are checklist examples and are not executed in this pass.

### Recovery readiness gates

Backup inventory complete, checksum recorded, non-production target verified, restore target
empty/approved, rollback path documented, operator/reviewer/escalation contact assigned, privacy
review complete, and a GO / WATCH / NO-GO decision recorded.

### Evidence template

Tabular evidence template covering date/time, environment, operator, reviewer, backup identifier,
restore target, scenario, expected/actual result, evidence path, issue severity, decision, and
follow-up owner.

### Go / Watch / No-Go criteria

- **GO** — safe to proceed to controlled non-production rehearsal execution in a separate
  supervised run.
- **WATCH** — proceed only with documented mitigations.
- **NO-GO** — stop due to safety, privacy, data integrity, or recovery risk.

### Tests / docs summary

- **Tests:** added `tests/Feature/Sprint31/Sprint31BackupRestoreRehearsalExecutionRecoveryReadinessTest.php`
  (checklist/documentation completeness only).
- **Docs:** added `docs/sprint_31_backup_restore_rehearsal_execution_recovery_readiness.md`;
  updated this history.

### Safety

- Docs/checklist-test only.
- No real backup/restore execution.
- No production/VPS access. No deployment. No migration.
- No destructive operation. No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change. No runtime behavior change.
- No route/controller/service/model/view/config/seeder change. No WhatsApp send.
- No dependency install. No `.env` change. No GO tag.

### Next recommended sprint

Sprint 32 — Go-Live Readiness, Training, Handover & SLA.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 32 — Go-Live Readiness, Training, Handover & SLA

**Branch:** `feature/sprint-32-go-live-readiness-training-handover-sla`
**Doc:** `docs/sprint_32_go_live_readiness_training_handover_sla.md`
**Mode:** docs / go-live readiness / training / handover / SLA checklist-test only
**Baseline:** Sprint 31 GO at `0ad4a45`
**Real go-live execution:** no real go-live executed
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Production code change:** no production code change
**Migration:** no migration
**Deployment:** no deployment
**Runtime behavior change:** no runtime behavior change

### Scope

- Converts the Sprint 30 pilot operational smoke and Sprint 31 recovery readiness artifacts into a
  single, auditable **go-live readiness package**.
- Defines a go-live readiness scope, an operational readiness checklist, a training plan, a
  handover package checklist, an SLA/support model, go-live decision gates, Go/Watch/No-Go
  criteria, a go-live runbook placeholder, and evidence/training-acceptance/handover sign-off
  templates.
- Baseline lineage: Sprint 30 GO `sprint-30-pilot-execution-bugfix-operational-smoke-go` at
  `53c3442` → Sprint 31 GO `sprint-31-backup-restore-rehearsal-execution-recovery-readiness-go` at
  `0ad4a45` (Sprint 31 feature commit `de85daf`).

### Training plan

Training checklist for Owner/management, Admin cabang, Cashier, Doctor/clinic operator (RME),
Lab/admin operator, and Support/technical maintainer — each with objective, material, demo
scenario, acceptance evidence, and follow-up owner.

### Handover package & SLA/support model

Handover package checklist (scope, branch/GO tag, SOPs, backup/restore reference, incident
reporting, escalation matrix, known limitations, open risks, acceptance sign-off) and an
SLA/support model with severity levels P0/P1/P2/P3, triage workflow, and daily/weekly routines.

### Go-live decision gates & Go / Watch / No-Go criteria

- Gates: Sprint 30 smoke accepted, Sprint 31 recovery readiness accepted, training complete,
  handover complete, support + escalation owner assigned, privacy review complete, known
  limitations accepted, rollback path documented, owner/admin sign-off, decision recorded.
- **GO** — ready for a separate supervised go-live execution workflow.
- **WATCH** — proceed only with documented mitigations and active support monitoring.
- **NO-GO** — stop due to safety, privacy, data integrity, recovery, training, support, or
  acceptance risk.

### Tests / docs summary

- **Tests:** added `tests/Feature/Sprint32/Sprint32GoLiveReadinessTrainingHandoverSlaTest.php`
  (checklist/documentation completeness only).
- **Docs:** added `docs/sprint_32_go_live_readiness_training_handover_sla.md`; updated this history.

### Safety

- Docs/checklist-test only.
- No real go-live execution.
- No real backup/restore execution.
- No production/VPS access. No deployment. No migration.
- No destructive operation. No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change. No runtime behavior change.
- No route/controller/service/model/view/config/seeder change. No WhatsApp send.
- No dependency install. No `.env` change. No GO tag.

### Next recommended sprint

Sprint 33 — Controlled Go-Live Execution & Hypercare Watch.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 33 — Controlled Go-Live Execution & Hypercare Watch

**Branch:** `feature/sprint-33-controlled-go-live-execution-hypercare-watch`
**Doc:** `docs/sprint_33_controlled_go_live_execution_hypercare_watch.md`
**Mode:** docs / controlled go-live execution plan / hypercare watch checklist-test only
**Baseline:** Sprint 32 GO at `54ed93a`
**Real go-live execution:** no real go-live executed
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Production code change:** no production code change
**Migration:** no migration
**Deployment:** no deployment
**Production/VPS access:** no production/VPS access
**Runtime behavior change:** no runtime behavior change

### Scope

- Converts the accepted Sprint 32 go-live readiness, training, handover, and SLA package into an
  auditable **controlled go-live execution plan** and a **hypercare watch checklist**.
- Defines pre-go-live approval gates, a controlled go-live execution runbook placeholder, a
  post-go-live smoke verification checklist, a hypercare watch checklist, an incident triage and
  escalation matrix, a rollback/recovery decision checklist, a communication plan, evidence and
  issue-log and acceptance templates, hypercare closure criteria, and Go/Watch/No-Go/Rollback
  criteria.
- Baseline lineage: Sprint 31 GO
  `sprint-31-backup-restore-rehearsal-execution-recovery-readiness-go` at `0ad4a45` → Sprint 32 GO
  `sprint-32-go-live-readiness-training-handover-sla-go` at `54ed93a` (Sprint 32 feature commit
  `1b53cd2`).

### Pre-go-live approval gates & smoke verification

Approval gates cover Sprint 32 readiness/training/handover acceptance, owner/admin sign-off,
support and escalation owner assignment, rollback/escalation path, privacy review, Sprint 31
backup/restore readiness reference, known limitations, communication channel, support coverage, and
a recorded GO/WATCH/NO-GO decision. A post-go-live smoke verification checklist covers app boot,
login, role menus, RME/odontogram/cashier/receivable/print/reporting smoke, WhatsApp manual SOP
evidence, monitoring evidence, and owner/admin acceptance.

### Incident triage, escalation, rollback/recovery & communication

Incident triage and escalation matrix with severity levels P0/P1/P2/P3 (owner, action, escalation
rule, communication rule, decision outcome per level); a rollback/recovery decision checklist
(decision only — no execution); and a communication plan for announcements, support channel,
escalation, issue reporting, daily summaries, and closure.

### Go / Watch / No-Go / Rollback criteria

- **GO** — proceed to a separate supervised go-live execution, or continue after smoke acceptance.
- **WATCH** — continue with active mitigations and daily hypercare monitoring.
- **NO-GO** — stop before go-live due to safety, privacy, data integrity, recovery, training,
  support, or acceptance risk.
- **ROLLBACK** — trigger recovery/escalation due to severe production impact, data integrity risk,
  or unacceptable workflow failure (executed only in the separate supervised workflow).

### Tests / docs summary

- **Tests:** added
  `tests/Feature/Sprint33/Sprint33ControlledGoLiveExecutionHypercareWatchTest.php`
  (checklist/documentation completeness only).
- **Docs:** added `docs/sprint_33_controlled_go_live_execution_hypercare_watch.md`; updated this
  history.

### Safety

- Docs/checklist-test only.
- No real go-live execution.
- No real backup/restore execution.
- No production/VPS access. No deployment. No migration.
- No destructive operation. No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change. No runtime behavior change.
- No route/controller/service/model/view/config/seeder change. No WhatsApp send.
- No dependency install. No `.env` change. No GO tag.

### Next recommended sprint

Sprint 34 — Post Go-Live Stabilization, Issue Burn-down & Operational Closure (gated by owner/admin
acceptance and unresolved issue severity).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 34 — Post Go-Live Stabilization, Issue Burn-down & Operational Closure

**Branch:** `feature/sprint-34-post-go-live-stabilization-issue-burn-down-operational-closure`
**Doc:** `docs/sprint_34_post_go_live_stabilization_issue_burn_down_operational_closure.md`
**Mode:** docs / post go-live stabilization plan / issue burn-down / operational closure checklist-test only
**Baseline:** Sprint 33 GO at `203c5e2`
**Real go-live execution:** no real go-live executed
**Real post-go-live operation:** no real post-go-live operation executed
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Production code change:** no production code change
**Migration:** no migration
**Deployment:** no deployment
**Production/VPS access:** no production/VPS access
**Runtime behavior change:** no runtime behavior change

### Scope

- Converts the accepted Sprint 33 controlled go-live execution and hypercare watch planning into an
  auditable **post go-live stabilization plan**, an **issue burn-down workflow**, a **support
  metrics review**, an **accepted backlog consolidation**, and an **operational closure** package.
- Defines a stabilization readiness checklist, an issue burn-down workflow, an issue severity and
  burn-down matrix (P0/P1/P2/P3), a stabilization smoke re-check checklist, a support metrics
  review, accepted backlog consolidation, operational closure gates, an operational handover closure
  checklist, an incident closure and escalation review, evidence/issue-log/sign-off templates, and
  Go/Watch/Extend Hypercare/No-Go criteria.
- Baseline lineage: Sprint 31 GO
  `sprint-31-backup-restore-rehearsal-execution-recovery-readiness-go` at `0ad4a45` → Sprint 32 GO
  `sprint-32-go-live-readiness-training-handover-sla-go` at `54ed93a` → Sprint 33 GO
  `sprint-33-controlled-go-live-execution-hypercare-watch-go` at `203c5e2` (Sprint 33 feature commit
  `724c8e2`).

### Stabilization, burn-down & smoke re-check

A stabilization readiness checklist confirms the Sprint 33 hypercare package, owner/admin
acceptance, support/escalation ownership, issue log, P0–P3 handling rules, support coverage,
operator feedback, monitoring evidence, the Sprint 31 backup/restore readiness reference, the Sprint
32 SLA/support model reference, and a recorded GO/WATCH/EXTEND HYPERCARE/NO-GO decision. The issue
burn-down workflow covers intake, classification, duplicate check, owner assignment, impact, mitigation,
resolution target, validation evidence, closure approval, backlog conversion, daily review, and
escalation — with no production bugfix implemented this sprint. A stabilization smoke re-check
checklist covers app boot, login, role menus, RME/odontogram/cashier/receivable/print/reporting
smoke, WhatsApp manual SOP evidence, monitoring evidence, backup/restore evidence location, and
owner/admin acceptance.

### Metrics, backlog, closure & handover

A support metrics review captures window, issue counts (open/closed and P0–P3), response/resolution
times, recurring patterns, training/documentation gaps, change requests, and a closure
recommendation. Accepted backlog consolidation classifies items as bugfix/enhancement/documentation/
training/operational support/deferred risk. Operational closure gates require no unresolved P0, P1
resolved or accepted with mitigation, P2/P3 logged, metrics reviewed, feedback reviewed, gaps
identified, backlog consolidated, support/SLA handover confirmed, owner/admin acceptance, and a
recorded decision. An operational handover closure checklist and an incident closure/escalation
review (review only — no fixes) complete the package, alongside evidence, issue burn-down log, and
operational closure sign-off templates.

### Go / Watch / Extend Hypercare / No-Go criteria

- **GO** — stabilization package accepted; operation can transition to normal support.
- **WATCH** — operation continues with active mitigations and support monitoring.
- **EXTEND HYPERCARE** — hypercare continues due to unresolved operational risk or support volume.
- **NO-GO** — stop closure due to safety, privacy, data integrity, recovery, unresolved P0/P1,
  support, or acceptance risk.

### Tests / docs summary

- **Tests:** added
  `tests/Feature/Sprint34/Sprint34PostGoLiveStabilizationIssueBurnDownOperationalClosureTest.php`
  (checklist/documentation completeness only).
- **Docs:** added
  `docs/sprint_34_post_go_live_stabilization_issue_burn_down_operational_closure.md`; updated this
  history.

### Safety

- Docs/checklist-test only.
- No real go-live/post-go-live execution.
- No real backup/restore execution.
- No production/VPS access. No deployment. No migration.
- No destructive operation. No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change. No runtime behavior change.
- No route/controller/service/model/view/config/seeder change. No WhatsApp send.
- No dependency install. No `.env` change. No GO tag.

### Next recommended sprint

Sprint 35 — Production Operations Baseline, Continuous Improvement & Roadmap Lock (gated by
owner/admin acceptance and unresolved issue severity).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 35 — Production Operations Baseline, Continuous Improvement & Roadmap Lock

**Branch:** `feature/sprint-35-production-operations-baseline-continuous-improvement-roadmap-lock`
**Doc:** `docs/sprint_35_production_operations_baseline_continuous_improvement_roadmap_lock.md`
**Mode:** docs / production operations baseline / continuous improvement / roadmap lock checklist-test only
**Baseline:** Sprint 34 GO at `2b594a3`
**Real go-live execution:** no real go-live executed
**Real post-go-live operation:** no real post-go-live operation executed
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Production code change:** no production code change
**Migration:** no migration
**Deployment:** no deployment
**Production/VPS access:** no production/VPS access
**Runtime behavior change:** no runtime behavior change

### Scope

Sprint 35 converts the Sprint 34 operational closure into an auditable normal operations baseline and
continuous improvement roadmap lock package. It defines a **production operations baseline**, a
**support metrics baseline**, a **continuous improvement backlog review**, a **roadmap lock
framework**, an **ownership and governance model**, and a **long-term operational monitoring policy**.
It also documents **change control and release governance**, an **incident/support governance
review**, **operations acceptance gates**, and **evidence templates**, with
**GO / WATCH / EXTEND SUPPORT / NO-GO** criteria. No real operational execution is performed.

### Go / Watch / Extend Support / No-Go criteria

- **GO** — normal operations baseline accepted; project can transition to governed continuous improvement.
- **WATCH** — operations continue with active support monitoring and tracked mitigations.
- **EXTEND SUPPORT** — support/hypercare-like watch continues due to unresolved operational risk or support volume.
- **NO-GO** — stop baseline closure due to safety, privacy, data integrity, recovery, unresolved P0/P1, support, or acceptance risk.

### Tests / docs summary

- **Tests:** added
  `tests/Feature/Sprint35/Sprint35ProductionOperationsBaselineContinuousImprovementRoadmapLockTest.php`
  (checklist/documentation completeness only).
- **Docs:** added
  `docs/sprint_35_production_operations_baseline_continuous_improvement_roadmap_lock.md`; updated this
  history.

### Safety

- Docs/checklist-test only.
- No real go-live/post-go-live operation execution.
- No real backup/restore execution.
- No production/VPS access. No deployment. No migration.
- No destructive operation. No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change. No runtime behavior change.
- No route/controller/service/model/view/config/seeder change. No WhatsApp send.
- No dependency install. No `.env` change. No GO tag.

### Next recommended sprint

Sprint 36 — Operational Governance, Maintenance Cadence & Expansion Readiness (gated by owner/admin
acceptance, unresolved issue severity, and roadmap lock status).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 36 — Operational Governance, Maintenance Cadence & Expansion Readiness

**Branch:** `feature/sprint-36-operational-governance-maintenance-cadence-expansion-readiness`
**Doc:** `docs/sprint_36_operational_governance_maintenance_cadence_expansion_readiness.md`
**Mode:** docs / operational governance / maintenance cadence / expansion readiness checklist-test only
**Baseline:** Sprint 35 GO at `c4293ec`
**Real maintenance execution:** no real maintenance executed
**Real branch expansion:** no real branch expansion executed
**Real go-live execution:** no real go-live/post-go-live operation executed
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Production code change:** no production code change
**Migration:** no migration
**Deployment:** no deployment
**Production/VPS access:** no production/VPS access
**Runtime behavior change:** no runtime behavior change

### Scope

Sprint 36 converts the Sprint 35 production operations baseline and roadmap lock into an auditable
**operational governance, maintenance cadence, and expansion readiness** package. It defines an
**operational governance cadence**, a **maintenance cadence and maintenance calendar**, a **support
review cadence**, an **expansion readiness framework**, a **controlled roadmap execution policy**, and
an **ownership discipline model**. It also documents a **governance risk and decision matrix**, a
**long-term monitoring evidence policy**, a **maintenance readiness checklist**, an **expansion
readiness checklist**, **operations governance acceptance gates**, and **evidence templates**, with
**GO / WATCH / EXTEND SUPPORT / NO-GO** criteria. No real maintenance, branch expansion,
go-live/post-go-live operation, or backup/restore is executed.

### Go / Watch / Extend Support / No-Go criteria

- **GO** — governance baseline accepted and operation can continue under controlled cadence.
- **WATCH** — governance continues with active mitigations and support monitoring.
- **EXTEND SUPPORT** — support/hypercare-like watch continues due to unresolved operational risk, expansion risk, or support volume.
- **NO-GO** — stop governance closure or expansion/maintenance approval due to safety, privacy, data integrity, recovery, unresolved R0/R1, support, or acceptance risk.

### Tests / docs summary

- **Tests:** added
  `tests/Feature/Sprint36/Sprint36OperationalGovernanceMaintenanceCadenceExpansionReadinessTest.php`
  (checklist/documentation completeness only).
- **Docs:** added
  `docs/sprint_36_operational_governance_maintenance_cadence_expansion_readiness.md`; updated this
  history.

### Safety

- Docs/checklist-test only.
- No production code/migration/deployment/runtime changes.
- No real maintenance/branch expansion execution.
- No real go-live/post-go-live operation execution.
- No real backup/restore execution.
- No production/VPS access.
- No destructive operation. No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change.
- No route/controller/service/model/view/config/seeder change. No WhatsApp send.
- No dependency install. No `.env` change. No GO tag.

### Next recommended sprint

Sprint 37 — Controlled Roadmap Execution Batch 1 & Governance Review (gated by Sprint 36 governance
acceptance and unresolved risk severity).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 37 — Controlled Roadmap Execution Batch 1 & Governance Review

**Branch:** `feature/sprint-37-controlled-roadmap-execution-batch-1-governance-review`
**Doc:** `docs/sprint_37_controlled_roadmap_execution_batch_1_governance_review.md`
**Mode:** docs / controlled roadmap execution Batch 1 / governance review checklist-test only
**Baseline:** Sprint 36 GO at `7a1f959`
**Real roadmap/RME/cashier/reporting/WhatsApp implementation:** none
**Real maintenance execution:** no real maintenance executed
**Real branch expansion:** no real branch expansion executed
**Real backup execution:** no real backup executed
**Real restore execution:** no real restore executed
**Production code change:** no production code change
**Migration:** no migration
**Deployment:** no deployment
**Production/VPS access:** no production/VPS access
**Runtime behavior change:** no runtime behavior change

### Scope

Sprint 37 converts the Sprint 35–36 governance and roadmap lock into an auditable **controlled
roadmap Batch 1 execution plan and governance review package**. It performs a **Batch 1 roadmap
candidate review** across RME workflow, cashier/payment/receivable, reporting/export, WhatsApp
manual reminder, monitoring/backup/recovery governance, branch expansion, UX/UI, training/docs, and
technical debt; defines **Batch 1 selection criteria**; and locks a **recommended Batch 1 scope —
RME Workflow Improvement Batch 1** (with cashier/reporting/training discovery as supporting
candidates). It adds an **implementation-readiness outline for Sprint 38**, a **risk and decision
matrix** (R0/P0, R1/P1, R2/P2, R3/P3), a **controlled implementation gate for future Sprint 38**, a
**test strategy for future implementation**, and **roadmap batch / acceptance criteria / future
implementation checklist evidence templates**, with **GO / WATCH / DEFER / NO-GO** criteria.

Sprint 37 is **docs/checklist-test only**: no production code/migration/deployment/runtime changes,
and no actual roadmap/RME/cashier/reporting/WhatsApp implementation. It explicitly does **not**
implement RME changes — RME Workflow Improvement Batch 1 is a future recommendation only.

### Go / Watch / Defer / No-Go criteria

- **GO** — Batch 1 roadmap scope accepted for a future implementation sprint.
- **WATCH** — Batch 1 scope needs active monitoring, more review, or tighter constraints before implementation.
- **DEFER** — Batch 1 item is valuable but postponed due to risk, dependency, or unclear acceptance criteria.
- **NO-GO** — stop future implementation due to safety, privacy, data integrity, recovery, unresolved R0/R1, support, or acceptance risk.

### Tests / docs summary

- **Tests:** added
  `tests/Feature/Sprint37/Sprint37ControlledRoadmapExecutionBatch1GovernanceReviewTest.php`
  (checklist/documentation completeness only).
- **Docs:** added
  `docs/sprint_37_controlled_roadmap_execution_batch_1_governance_review.md`; updated this history.

### Safety

- Docs/checklist-test only.
- No production code/migration/deployment/runtime changes.
- No actual roadmap/RME/cashier/reporting/WhatsApp implementation.
- No real maintenance/branch expansion execution.
- No real backup/restore execution.
- No production/VPS access.
- No destructive operation. No monitoring/backup/restore automation.
- No cron/scheduler/job/queue/notification change.
- No route/controller/service/model/view/config/seeder change. No WhatsApp send.
- No dependency install. No `.env` change. No GO tag.

### Next recommended sprint

Sprint 38 — RME Workflow Improvement Batch 1 (implement the approved, controlled RME Workflow
Improvement Batch 1 under Sprint 36–37 governance gates, with targeted tests, explicit approval, and
no production deployment until reviewed).

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 38 — RME Workflow Improvement Batch 1

**Baseline:** Sprint 37 GO at `078be4e`
**Doc:** [sprint_38_rme_workflow_improvement_batch_1.md](sprint_38_rme_workflow_improvement_batch_1.md)
**Theme:** First controlled RME Workflow Improvement Batch 1 — local implementation, targeted regression only.

Sprint 38 executes the RME Workflow Improvement Batch 1 selected by Sprint 37 controlled roadmap
governance. Discovery confirmed the functional baseline already exists from earlier RME format work:
`mst_patients.ktp_number` (nullable, unique) and `whatsapp_number`, duplicate-KTP blocking in
`StorePatientRequest`/`UpdatePatientRequest` (and the RME new-patient visit flow), KTP hidden from
RME visit detail/print, and the cashier Surat Persetujuan Tindakan consent checklist enforced in
`RmePaymentService`. Sprint 38 therefore layers **workflow clarity** on top of that baseline rather
than reworking it.

**Implemented (local only):**
- **KTP / identity handling** — confirmed `ktp_number` binds patient identity; reused existing field;
  no schema change required.
- **Duplicate identity validation** — confirmed and regression-covered for patient create, patient
  update (including keeping the patient's own KTP), and the RME new-patient visit flow.
- **WA workflow clarity** — added operational help text on the patient form and RME new-patient
  visit form explaining WhatsApp number is used for visit attendance confirmation and
  receivable/piutang follow-up, and that the system sends no automated WhatsApp message. WA usage
  context also surfaced on the RME visit detail.
- **Treatment consent checklist clarity** — surfaced a cashier-facing, read-only `TTD Surat
  Persetujuan Tindakan` verification status on the RME visit detail (verified / not yet verified),
  reusing the existing `hasVerifiedConsent()` helper. No digital signature, no upload.
- **RME print / privacy protection** — confirmed and regression-asserted that No. KTP is never
  rendered on RME visit detail or print output; only WA appears where operationally intended.
- **Targeted regression tests** — `Sprint38RmeWorkflowImprovementBatch1Test` (doc/history checklist
  + clarity-copy assertions) plus the existing `RmePatientFormatConsentTest` functional coverage.

**Safety:** Local implementation only. No production/VPS access. No deployment. No production
migration execution. No external WhatsApp send/automation. No signature upload/capture integration.
No backup/restore/rollback execution. No destructive operation. No `.env` change. No
dependency/package install. No GO tag.

### Next recommended sprint

Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1 (controlled cashier/payment/receivable
workflow improvement based on Sprint 37 roadmap governance and the Sprint 38 RME workflow results).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 39 — Cashier, Payment & Receivable Improvement Batch 1

**Baseline:** Sprint 38 GO at `253f025`
**Doc:** [sprint_39_cashier_payment_receivable_improvement_batch_1.md](sprint_39_cashier_payment_receivable_improvement_batch_1.md)
**Theme:** First controlled Cashier, Payment & Receivable Improvement Batch 1 — local implementation, targeted regression only.

Sprint 39 follows Sprint 38 RME Workflow Improvement Batch 1 (`253f025`). Discovery confirmed the
financial baseline already enforces the business rules: `RmeInvoice::remainingAmount()` /
`refreshInvoiceStatus()` drive PAID/PARTIAL status, overpayment is guarded in both
`RmePaymentService::pay()` and `CreateRmePaymentRequest`, the receivable queue already excludes
zero-remaining (fully paid) invoices (status UNPAID/PARTIAL with remaining > 0), and
`ClinicVisit::hasVerifiedConsent()` already models the cashier consent verification. Sprint 39
therefore layers **cashier/payment/receivable clarity + regression coverage** on top of that baseline
rather than rewriting any financial calculation.

**Implemented (local only):**
- **Cashier verification clarity** — surfaced a read-only `Status Persetujuan Tindakan (TTD)`
  verification badge (Terverifikasi / Belum Diverifikasi) in the cashier clinical summary, reusing
  `hasVerifiedConsent()`. Verification status / checklist only — no digital signature, no upload.
- **Payment/remaining-balance clarity** — confirmed and regression-covered Grand Total, Dibayar,
  Sisa Tagihan and payment status already shown on the cashier payment screen; preserved overpayment
  guard messaging.
- **Receivable/piutang follow-up context** — surfaced patient WA number in the receivable list and
  follow-up form for manual follow-up context.
- **WA manual follow-up** — added explicit copy that WhatsApp follow-up is performed manually by the
  cashier and the system sends no automated WhatsApp message and runs no follow-up automation.
- **Treatment consent checklist/status visibility** — cashier-facing read-only consent verification
  status, preserved as checklist verification only.
- **Zero-remaining receivable exclusion** — preserved and regression-asserted: fully paid /
  zero-remaining invoices do not appear as active receivables; partially paid ones do.
- **Overpayment/validation review** — preserved and regression-asserted that a payment exceeding the
  remaining balance is rejected.
- **KTP / privacy protection** — confirmed and regression-asserted No. KTP is never rendered in
  cashier/payment/receivable/receipt views.
- **Targeted regression tests** — `Sprint39CashierPaymentReceivableImprovementBatch1Test`
  (doc/history checklist + functional cashier/payment/receivable clarity and privacy assertions).

**Safety:** Local implementation only. No production/VPS access. No deployment. No production
migration execution. No external WhatsApp send/automation. No signature upload/capture integration.
No risky financial calculation rewrite. No backup/restore/rollback execution. No destructive
operation. No `.env` change. No dependency/package install. No GO tag.

### Next recommended sprint

Sprint 40 — Reporting, Export & Owner Dashboard Improvement (controlled reporting/export and
owner/admin dashboard improvement based on Sprint 37 roadmap governance and the Sprint 39
cashier/payment/receivable results).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 40 — Reporting, Export & Owner Dashboard Improvement

**Baseline:** Sprint 39 GO at `1097d98`
**Doc:** [sprint_40_reporting_export_owner_dashboard_improvement.md](sprint_40_reporting_export_owner_dashboard_improvement.md)
**Theme:** First controlled Reporting, Export & Owner Dashboard Improvement — local implementation, targeted regression only.

Sprint 40 follows Sprint 39 Cashier, Payment & Receivable Improvement Batch 1 (`1097d98`). Discovery
confirmed a mature reporting/dashboard baseline: the Owner Dashboard (`HomeDashboardController` +
`OwnerDashboardRmeLabKpiService`) already separates visit/RME, cashier/payment, and receivable/piutang
KPIs; RME and lab report/export/print routes already exist (`rme.reports.*`, `ExportReportController`,
`rme.cashier.receivables.export`, `rme.visits.pdf` via existing `barryvdh/laravel-dompdf`); active
receivables already exclude zero-remaining (fully paid) invoices; and KTP is not rendered in
dashboard/report/export views. Sprint 40 therefore layers **reporting/dashboard clarity + regression
coverage** on top of that baseline rather than introducing new export infrastructure or rewriting any
financial calculation.

**Implemented (local only):**
- **Reporting overview clarity** — added a clarifying caption to the Owner Dashboard "Ringkasan Piutang
  per Cabang" section stating that fully-paid (zero-remaining) invoices are not counted as active
  receivables and that follow-up is performed manually.
- **Export consistency** — reused and documented existing export/print routes; no new export/PDF package
  installed, no new export infrastructure introduced.
- **Owner/admin dashboard KPI visibility** — preserved and regression-covered the existing visit /
  cashier-pending / unpaid-invoice / receivable-remaining / follow-up KPI cards, branch-aware via the
  Owner branch filter.
- **Receivable/payment reporting continuity** — Sprint 39 receivable/payment clarity flows into the
  dashboard; active receivables remain `UNPAID + PARTIAL` only with remaining floored at 0.
- **WA manual follow-up context** — dashboard explicitly states follow-up is manual with no automatic
  WhatsApp send; no WhatsApp message sent, no automation added.
- **KTP / privacy protection** — confirmed and regression-asserted No. KTP is never rendered on the
  Owner Dashboard / reporting views.
- **Zero-remaining receivable exclusion** — preserved and regression-asserted at the dashboard service
  level: fully-paid invoices are excluded from active receivable counts/totals.
- **Permission/authorization review** — Owner Dashboard access still requires
  `view_owner_dashboard | manage_report`; regression test confirms unauthorized users do not see KPI
  content.
- **Targeted regression tests** — `Sprint40ReportingExportOwnerDashboardImprovementTest`
  (doc/history checklist + functional dashboard KPI/privacy/zero-remaining assertions).

**Safety:** Local implementation only. No production/VPS access. No deployment. No production migration
execution. No external WhatsApp send/automation. No new export/PDF dependency. No risky financial
calculation rewrite. No backup/restore/rollback execution. No destructive operation. No `.env` change.
No dependency/package install. No GO tag.

### Next recommended sprint

Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow (controlled manual reminder
workflow, follow-up logging, reminder templates, operator SOP, with an explicit no-automation/no-send
boundary unless separately approved later).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 41 — WhatsApp Manual Reminder Operationalization & Follow-up Workflow

**Baseline:** Sprint 40 GO at `8647b0f` (`sprint-40-reporting-export-owner-dashboard-improvement-go`).
Builds on Sprint 39 GO at `1097d98` and the existing RME receivable follow-up workflow (Sprint 24
Phase 24.8) plus the existing `WaReminderTemplate` module (Sprint 19 Phase 5).

Controlled, local-only **WhatsApp Manual Reminder Operationalization & Follow-up Workflow**. Small,
additive, fully manual — no message is ever sent by the system.

- **Manual WhatsApp reminder clarity** — added a dedicated manual WhatsApp helper card to the RME
  receivable follow-up create view stating the text is copy-only and the operator reviews and sends
  manually.
- **Follow-up logging workflow** — reused the existing `RmeReceivableFollowUp` model/controller/
  request/view; context (patient name, WA number, invoice/receivable, status, channel, note,
  contacted date, next follow-up date) already supported, no schema change.
- **Reminder template guidance** — reused the existing `WaReminderTemplate` module; sharpened the
  template index safety notice to operator-facing copy-only / manual send / no WhatsApp API / no KTP.
- **Receivable/piutang follow-up continuity** — receivables list keeps the manual WA disclaimer, WA
  number, last/next follow-up context, and follow-up entry point; no receivable query/service changed.
- **Dashboard/reporting continuity** — no dashboard/reporting code changed; Owner Dashboard receivable
  follow-up KPI remains branch-aware and intact.
- **WA manual follow-up context** — added a privacy-safe copyable draft (patient name, branch, invoice
  number, remaining balance) and a clearly-labeled client-side `wa.me` manual link; server never sends
  or calls any external API.
- **KTP / privacy protection** — manual draft, helper card, and template guidance never include No.
  KTP / identity number; regression-asserted.
- **Zero-remaining receivable exclusion** — preserved; paid/zero-remaining invoices stay excluded from
  active receivables, partial/unpaid stay visible; regression-asserted.
- **Permission/authorization review** — reused `manage_rme_billing` and existing follow-up policy /
  branch isolation; unauthorized users and non-RME-branch invoices remain forbidden; no permission
  added or relaxed.
- **Targeted regression tests** — `Sprint41WhatsAppManualReminderOperationalizationFollowUpWorkflowTest`
  (doc/history checklist + functional manual-helper / `wa.me` / KTP-privacy / zero-remaining /
  authorization assertions).

**Safety:** Local implementation only. No production/VPS access. No deployment. No production migration
execution. No external WhatsApp send/automation. No WhatsApp API integration. No queue/job/cron/
scheduler automation. No new notification provider. No new dependency/package install. No risky
financial calculation rewrite. No backup/restore/rollback execution. No destructive operation. No
`.env` change. No GO tag.

### Next recommended sprint

Sprint 42 — Monitoring, Backup & Recovery Governance Hardening (controlled monitoring evidence review,
backup/recovery governance hardening, restore readiness documentation, operational review cadence and
safety gates — without executing real production backup/restore unless separately approved).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 42 — Monitoring, Backup & Recovery Governance Hardening

**Baseline:** Sprint 41 GO at `19e5f74` (`sprint-41-whatsapp-manual-reminder-operationalization-follow-up-workflow-go`).
Builds on Sprint 40 GO at `8647b0f` and the existing monitoring/backup/restore governance docs
(`backup_restore_rehearsal_plan.md`, `non_production_restore_runbook.md`, Sprint 25/28/29/31/36
operational baselines).

Governance-only, local-only **Monitoring, Backup & Recovery Governance Hardening**. Documentation and
checklist regression only — no runtime application behavior change, no real operation executed.

- **Monitoring governance hardening** — defined daily/weekly monitoring evidence review covering
  Laravel log review, queue/scheduler review (review-only), application health, database health, disk/
  storage headroom, and owner/admin dashboard/reporting continuity; no external monitoring integration.
- **Backup governance hardening** — defined database and runtime-file backup evidence expectations,
  backup inventory checklist, retention review, integrity-check expectation, and secure location note;
  no real backup executed.
- **Recovery readiness governance** — documented recovery objective notes, restore rehearsal
  prerequisites, non-production restore target requirement, data-loss/partial-restore risk review, and
  stakeholder approval requirement; no real restore executed.
- **Restore rehearsal approval gates** — approval required first, non-production target, identified
  backup source, isolated environment, documented rollback path, prepared validation checklist, and
  recorded success/failure evidence.
- **Incident escalation and rollback decision gates** — severity levels, owner/operator
  responsibility, communication path, rollback/no-rollback criteria, evidence capture, and
  post-incident review.
- **Evidence checklist** — Area / Evidence / Cadence / Owner / Status matrix across application health,
  Laravel logs, database health, disk/storage, backup inventory, backup integrity, recovery readiness,
  restore rehearsal readiness, incident escalation, and rollback decision gate.
- **Review cadence** — daily, weekly, per-backup-cycle, pre-rehearsal, per-incident, and per-sprint
  governance review.
- **Targeted regression tests** — `Sprint42MonitoringBackupRecoveryGovernanceHardeningTest`
  (doc/history checklist assertions over the Sprint 42 doc + baseline references + safety boundaries +
  PR marker + Sprint 43 recommendation).

**Safety:** Documentation/checklist regression only. No production/VPS access. No deployment. No
production migration. No production backup execution. No production restore execution. No rollback
execution. No external monitoring integration. No scheduler/queue/cron automation. No `.env` change.
No dependency/package install. No GO tag.

### Next recommended sprint

Sprint 43 — Operational Monitoring Evidence Review & Pilot Health Check (controlled local
documentation/evidence review or supervised pilot health-check preparation, exercising the Sprint 42
cadence and evidence checklist — without production access or real backup/restore/rollback unless
separately approved).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 43 — Operational Monitoring Evidence Review & Pilot Health Check

**Status:** Local governance implementation / pending PR.
**Scope:** Documentation + checklist regression test only.

**Baseline:** Sprint 42 GO at `5876070`
(`sprint-42-monitoring-backup-recovery-governance-hardening-go`). Builds on the Sprint 42
monitoring/backup/recovery governance baseline
(`docs/sprint_42_monitoring_backup_recovery_governance_hardening.md`).

Governance-only, local-only **Operational Monitoring Evidence Review & Pilot Health Check**.
Documentation and checklist regression only — no runtime application behavior change, no real
operation executed.

- **Operational monitoring evidence review checklist** — review-only evidence rows covering app
  availability, Laravel log review, queue/scheduler status review (review-only), database
  connectivity, storage permission, backup inventory observation, restore rehearsal readiness,
  incident log summary, manual sign-off, and reviewer/date/time/environment fields. No secret and no
  patient KTP exposure.
- **Pilot health-check readiness checklist** — review-only readiness items for a future supervised
  pilot health check: approved environment, deployment out of scope, no production mutation, read-only
  checks only, route availability review, login/role smoke (review-only), cashier/RME/receivable/
  reporting smoke scope as checklist only, manual WhatsApp follow-up, rollback decision tree
  (review-only), escalation/owner sign-off, and evidence archive naming convention.
- **Evidence package structure** — suggested `docs/evidence/sprint-43/YYYY-MM-DD-pilot-health-check-review/`
  naming convention only; not a command to collect production data; no secrets/KTP.
- **Approval gates + incident escalation review gates** — evidence review, pilot window, and any
  production/VPS/backup/restore/rollback/deployment/automation gated behind separate supervised,
  approved workflows; observe → classify → escalate → decide → execute-only-if-approved → document →
  post-review.
- **Targeted regression tests** — `Sprint43OperationalMonitoringEvidenceReviewPilotHealthCheckTest`
  (doc/history checklist assertions over the Sprint 43 doc + baseline references + safety boundaries +
  privacy/financial constraints + validation commands).

**Validation:** `php artisan test --filter=Sprint43OperationalMonitoringEvidenceReviewPilotHealthCheck`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:** `feature/sprint-43-operational-monitoring-evidence-review-pilot-health-check` /
`sprint-43-operational-monitoring-evidence-review-pilot-health-check` (future GO tag
`sprint-43-operational-monitoring-evidence-review-pilot-health-check-go` after PR merge only).

**Safety:** Documentation/checklist regression only. No production/VPS access. No deployment. No
production migration. No production backup execution. No production restore execution. No rollback
execution. No external monitoring integration. No scheduler/queue/cron automation. No `.env` change.
No dependency/package install. KTP remains hidden. WhatsApp manual-only. Zero-remaining receivables
remain excluded from active receivables. Overpayment guard preserved. No GO tag.

### Next recommended sprint

Sprint 44 — Supervised Pilot Health-Check Dry-Run (owner-approved, read-only execution of the Sprint
43 monitoring evidence review and pilot health-check checklists in an approved environment, capturing
evidence under the Sprint 43 package convention — still without production mutation or real
backup/restore/rollback unless separately approved).

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 44 — Pilot Health Check Dry-Run Evidence Package & Operational Sign-off

**Status:** Local governance implementation / pending PR.
**Scope:** Documentation + checklist regression test only.

**Baseline:** Sprint 43 GO at `5c2d8b5`
(`sprint-43-operational-monitoring-evidence-review-pilot-health-check-go`). Builds on the Sprint 43
operational monitoring evidence review and pilot health-check readiness baseline
(`docs/sprint_43_operational_monitoring_evidence_review_pilot_health_check.md`).

Governance-only, local-only **Pilot Health Check Dry-Run Evidence Package & Operational Sign-off**.
Documentation and checklist regression only — no runtime application behavior change, **no real
pilot health-check execution**. The evidence package is a **dry-run template only**, and operational
sign-off is a **governance checklist only** (not permission to execute production actions).

- **Pilot health-check dry-run checklist** — review-only items confirming approved dry-run scope, that
  the target environment is not accessed, no production command execution, no deployment/maintenance,
  no backup/restore/rollback execution, review-only route/login/role and RME/cashier/receivable/
  reporting checks, no patient KTP exposure, manual WhatsApp follow-up, preserved zero-remaining
  receivable and overpayment-guard rules, escalation reviewed only, owner/admin sign-off fields, and
  next supervised workflow requirements.
- **Dry-run evidence package template** — template fields (Evidence Package ID, prepared by/reviewer/
  approver, scope, checklist version, evidence index, KTP exposure check, WA/manual follow-up check,
  receivable/overpayment checks, escalation review, open risks, approval decision, sign-off
  timestamp). Screenshots/log excerpts are placeholders only; no real production screenshots, logs,
  dumps, secrets, or patient identifiers collected.
- **Evidence package naming convention** — suggested
  `docs/evidence/sprint-44/YYYY-MM-DD-pilot-health-check-dry-run/` convention only; not a command to
  collect production data; no secrets/KTP.
- **Operational sign-off workflow + approval gates** — prepare checklist → review scope/forbidden
  actions → review template → review privacy/financial constraints → review escalation → record risks
  → owner/admin review → approve/approve-with-conditions/reject → document next supervised workflow →
  close. Production/VPS/backup/restore/rollback/deployment/automation gated behind separate supervised,
  approved workflows. Incident escalation dry-run gates: observe → classify → escalate → decide →
  execute-only-if-approved → document → post-review.
- **Targeted regression tests** —
  `Sprint44PilotHealthCheckDryRunEvidencePackageOperationalSignOffTest` (doc/history checklist
  assertions over the Sprint 44 doc + baseline references + dry-run/sign-off structure + safety
  boundaries + privacy/financial constraints + validation commands).

**Validation:**
`php artisan test --filter=Sprint44PilotHealthCheckDryRunEvidencePackageOperationalSignOff`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:**
`feature/sprint-44-pilot-health-check-dry-run-evidence-package-operational-sign-off` /
`sprint-44-pilot-health-check-dry-run-evidence-package-operational-sign-off` (future GO tag
`sprint-44-pilot-health-check-dry-run-evidence-package-operational-sign-off-go` after PR merge only).

**Safety:** Documentation/checklist regression only. Dry-run evidence package template only. No real
pilot health-check execution. No production/VPS access. No deployment. No production migration. No
production backup execution. No production restore execution. No rollback execution. No external
monitoring integration. No scheduler/queue/cron automation. No `.env` change. No dependency/package
install. No runtime behavior change. KTP remains hidden. WhatsApp manual-only. Zero-remaining
receivables remain excluded from active receivables. Overpayment guard preserved. No GO tag.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 45 — Pilot Health Check Supervised Readiness Runbook & Go/No-Go Control

**Status:** Local governance implementation / pending PR.
**Scope:** Documentation + checklist regression test only.

**Baseline:** Sprint 44 GO at `f1debae`
(`sprint-44-pilot-health-check-dry-run-evidence-package-operational-sign-off-go`). Builds on the
Sprint 44 pilot health-check dry-run evidence package and operational sign-off baseline
(`docs/sprint_44_pilot_health_check_dry_run_evidence_package_operational_sign_off.md`).

Governance-only, local-only **Pilot Health Check Supervised Readiness Runbook & Go/No-Go Control**.
Documentation and checklist regression only — no runtime application behavior change, **no real
pilot health-check execution**. The supervised readiness runbook is **documentation only, not
execution approval**, and the Go/No-Go control is **governance only, not deployment authorization**.

- **Supervised readiness definition + prerequisites** — readiness means a reviewed runbook,
  owner/admin approval gates, evidence checklist, Go/No-Go criteria, abort criteria, escalation path,
  and post-review plan; it does not authorize execution. Prerequisites confirm base branch/baseline,
  previous GO tag, Sprint 44 dry-run evidence review, reviewer/environment owner identification,
  proposed-but-not-executed window, forbidden actions, privacy/financial checklists, escalation path,
  rollback decision tree (documentation-only), and exit criteria.
- **Supervised pilot health-check runbook phases** — twelve review-only phases (pre-check readiness,
  scope/environment confirmation, privacy/safety briefing, evidence preparation, read-only and
  functional smoke checklist reviews, incident/escalation scenario review, Go/No-Go decision,
  conditional Go follow-up, No-Go/abort handling, post-review documentation, closure/next workflow).
  Phases are prepared for a future supervised workflow and **not executed in Sprint 45**.
- **Go/No-Go control framework** — governance-only Go, Conditional Go, No-Go, and Abort criteria with
  owner/admin sign-off, gated behind a separate explicitly approved supervised workflow before any
  execution. Evidence checklist (Package ID, reviewer/approver, baseline, scope, read-only/functional
  smoke checks, KTP exposure check, WA/manual follow-up check, receivable/overpayment checks,
  escalation review, Go/No-Go decision, conditions, abort triggers, risks, final sign-off) is template
  only; no real production screenshots, logs, dumps, secrets, tokens, credentials, patient
  identifiers, or KTP data collected.
- **Operational sign-off workflow + approval gates** — prepare runbook → confirm baseline/GO tag →
  review Sprint 44 evidence template → review scope/forbidden actions → review privacy/financial
  constraints → review Go/No-Go and abort criteria → review escalation → record risks → owner/admin
  decision (Go/Conditional Go/No-Go) → document next supervised workflow → close. Production/VPS/
  server/backup/restore/rollback/deployment/automation gated behind separate supervised, approved
  workflows. Incident escalation and rollback decision gates are review-only: observe → classify →
  escalate → decide → rollback-tree-review-only → execute-only-if-approved → document → post-review.
- **Targeted regression tests** —
  `Sprint45PilotHealthCheckSupervisedReadinessRunbookGoNoGoControlTest` (doc/history checklist
  assertions over the Sprint 45 doc + baseline references + supervised readiness/runbook/Go-No-Go
  structure + safety boundaries + privacy/financial constraints + validation commands).

**Validation:**
`php artisan test --filter=Sprint45PilotHealthCheckSupervisedReadinessRunbookGoNoGoControl`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:**
`feature/sprint-45-pilot-health-check-supervised-readiness-runbook-go-no-go-control` /
`sprint-45-pilot-health-check-supervised-readiness-runbook-go-no-go-control` (future GO tag
`sprint-45-pilot-health-check-supervised-readiness-runbook-go-no-go-control-go` after PR merge only).

**Safety:** Documentation/checklist regression only. Supervised readiness runbook is documentation
only (not execution approval); Go/No-Go control is governance only (not deployment authorization). No
real pilot health-check execution. No production/VPS/server access. No deployment. No production
command execution. No production backup execution. No production restore execution. No rollback
execution. No external monitoring integration. No scheduler/queue/cron automation. No `.env` change.
No dependency/package install. No migration/schema change. No runtime behavior change. KTP remains
hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded from active receivables.
Overpayment guard preserved. No GO tag.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 46 — Pilot Health Check Supervised Execution Plan & Evidence Review Pack

**Status:** Local governance implementation / pending PR.
**Scope:** Documentation + checklist regression test only.

**Baseline:** Sprint 45 GO at `9aa3c11`
(`sprint-45-pilot-health-check-supervised-readiness-runbook-go-no-go-control-go`). Builds on the
Sprint 45 pilot health-check supervised readiness runbook and Go/No-Go control baseline
(`docs/sprint_45_pilot_health_check_supervised_readiness_runbook_go_no_go_control.md`).

Governance-only, local-only **Pilot Health Check Supervised Execution Plan & Evidence Review Pack**.
Documentation and checklist regression only — no runtime application behavior change, **no real
pilot health-check execution**. The supervised execution plan is **documentation only, not execution
approval**, and the evidence review pack is **template only, not real evidence collection**.

- **Supervised execution plan definition + prerequisites** — the plan means a reviewed,
  owner/admin-approved future workflow describing who may perform checks, what may be observed, what
  evidence may be recorded, when activity must stop, how incidents escalate, and how Go/No-Go
  decisions carry forward; it does not authorize execution. Prerequisites confirm base
  branch/baseline, latest GO tag, Sprint 45 readiness runbook review, reviewer/owner/evidence-reviewer
  identification, intended-but-not-executed environment/window, forbidden actions, privacy/financial
  checklists, Go/No-Go carry-forward criteria, abort criteria, escalation path, rollback decision tree
  (documentation-only), evidence storage/naming, and exit criteria.
- **Observation-only supervised execution phases** — fourteen review-only phases (pre-execution
  readiness, scope/environment authorization, privacy/financial briefing, evidence pack preparation,
  read-only and functional smoke observation, RME/cashier/receivable/reporting observation, manual
  WhatsApp follow-up observation, incident/escalation review, Go/No-Go carry-forward decision, abort
  handling, evidence review and acceptance, post-execution review draft, closure/next workflow).
  Phases are prepared for a future supervised workflow and **not executed in Sprint 46**.
- **Evidence review pack + acceptance/rejection rules** — governance-only evidence review pack
  template (Pack ID, prepared by/owner/reviewer/approver, baseline, scope, evidence index, KTP and
  patient-identifier exposure checks, WA/manual follow-up check, receivable/overpayment checks,
  incident/escalation review, Go/No-Go carry-forward decision, abort trigger review, risks, sign-off)
  is template only; no real production screenshots, logs, dumps, secrets, tokens, credentials,
  patient identifiers, WA numbers, or KTP data collected. Acceptance/rejection rules forbid KTP,
  unnecessary patient identifiers, secrets/tokens/credentials/`.env`, out-of-scope or
  production-mutating evidence, and any deployment/backup/restore/rollback/automation or WhatsApp
  API/send activity.
- **Go/No-Go carry-forward, sign-off workflow + approval gates** — Sprint 45 Go/No-Go result must be
  reviewed before any future execution; Conditional Go needs named conditions/owner/deadline/re-review;
  No-Go needs documented blockers; abort criteria override any Go. Operational sign-off workflow
  (prepare plan → confirm baseline/GO tag → review Sprint 45 runbook → review scope/forbidden actions
  → review privacy/financial constraints → review Go/No-Go and abort criteria → review
  acceptance/rejection rules → review escalation → record risks → owner/admin decision → document next
  workflow → close) does not authorize deployment/VPS/production/backup/restore/rollback/automation or
  real execution. Incident escalation and rollback decision gates are review-only; a post-execution
  review template is provided for a future approved workflow and is not filled with production
  evidence.
- **Targeted regression tests** —
  `Sprint46PilotHealthCheckSupervisedExecutionPlanEvidenceReviewPackTest` (doc/history checklist
  assertions over the Sprint 46 doc + baseline references + supervised execution plan / evidence
  review pack structure + Go/No-Go carry-forward + safety boundaries + privacy/financial constraints
  + validation commands).

**Validation:**
`php artisan test --filter=Sprint46PilotHealthCheckSupervisedExecutionPlanEvidenceReviewPack`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:**
`feature/sprint-46-pilot-health-check-supervised-execution-plan-evidence-review-pack` /
`sprint-46-pilot-health-check-supervised-execution-plan-evidence-review-pack` (future GO tag
`sprint-46-pilot-health-check-supervised-execution-plan-evidence-review-pack-go` after PR merge only).

**Safety:** Documentation/checklist regression only. Supervised execution plan is documentation only
(not execution approval); evidence review pack is template only (not real evidence collection). No
real pilot health-check execution. No production/VPS/server access. No database/log/file access. No
deployment. No production command execution. No production backup execution. No production restore
execution. No rollback execution. No external monitoring integration. No scheduler/queue/cron
automation. No `.env` change. No dependency/package install. No migration/schema change. No runtime
behavior change. KTP remains hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded
from active receivables. Overpayment guard preserved. No GO tag.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 47 — Pilot Health Check Supervised Execution Approval Gate & Operator Checklist

**Status:** Local governance implementation / pending PR.
**Scope:** Documentation + checklist regression test only.

**Baseline:** Sprint 46 GO at `8130050`
(`sprint-46-pilot-health-check-supervised-execution-plan-evidence-review-pack-go`). Builds on the
Sprint 46 pilot health-check supervised execution plan and evidence review pack baseline
(`docs/sprint_46_pilot_health_check_supervised_execution_plan_evidence_review_pack.md`).

Governance-only, local-only **Pilot Health Check Supervised Execution Approval Gate & Operator
Checklist**. Documentation and checklist regression only — no runtime application behavior change,
**no real pilot health-check execution**. The supervised execution approval gate is **documentation
only, not execution authorization**, and the operator checklist is **template only, not a command to
perform real execution**.

- **Supervised execution approval gate + prerequisites** — the approval gate means the team has
  reviewed and signed off the future supervised health-check scope, operator responsibilities,
  evidence handling rules, stop/abort triggers, escalation path, and Go/No-Go carry-forward before any
  future real activity may occur; it does not authorize execution. Prerequisites confirm base
  branch/baseline, latest GO tag, Sprint 46 execution-plan and evidence-review-pack review,
  approver/owner/operator/evidence-reviewer/communication-channel identification, intended-but-not-executed
  future environment/window, forbidden actions, privacy/financial checklists, Go/No-Go carry-forward,
  abort criteria, escalation path, rollback decision tree (documentation-only), evidence storage/naming,
  and exit criteria.
- **Approval matrix + operator role boundaries** — role responsibilities (owner/admin approver,
  execution owner, operator, evidence reviewer, observer/recorder) defined for a future workflow only;
  in Sprint 47 no operator performs real production checks and no operator accesses VPS/production/server/
  database/logs/files/backups/live services or executes deployment/backup/restore/rollback/queue/cron/
  scheduler/production commands.
- **Operator readiness checklist + checklist phases** — readiness items (Sprint 45 runbook + Sprint 46
  plan read, approval-gate boundaries, no execution in Sprint 47, privacy/KTP-hidden, WA manual-only,
  receivable/overpayment rules, no financial rewrite, evidence accept/reject, stop/abort, escalation,
  handoff) and seventeen operator checklist phases prepared for a future supervised workflow and **not
  executed in Sprint 47**.
- **Evidence handling + acceptance/rejection reminders** — operator records only approved observations;
  no KTP, unnecessary patient identifiers, secrets/tokens/credentials/`.env`, database dumps, raw logs,
  or WA numbers recorded; evidence labeled with scope/reviewer/date/source and distinguishes observation
  from execution; rejection reminders forbid out-of-scope/production-mutating evidence and any
  deployment/backup/restore/rollback/automation or WhatsApp API/send activity.
- **Stop/abort gates, escalation, Go/No-Go carry-forward, sign-off + approval gates** — stop/abort on
  any unauthorized production access, production mutation, secret/KTP/WA/patient-identifier exposure,
  required backup/restore/rollback/deployment, automation introduction, WhatsApp API/send, financial
  rule change, missing sign-off, unsafe evidence, unclear operator role, unavailable communication
  channel, or failed safety gates. Communication/escalation, Go/No-Go carry-forward (Sprint 45 result +
  Sprint 46 control reviewed before any future execution; abort overrides any Go), operational sign-off
  workflow, approval gates, incident escalation/rollback decision gates (review-only), and a post-check
  operator handoff template are provided for a future approved workflow and not filled with production
  evidence.
- **Targeted regression tests** —
  `Sprint47PilotHealthCheckSupervisedExecutionApprovalGateOperatorChecklistTest` (doc/history checklist
  assertions over the Sprint 47 doc + baseline references + approval gate / operator checklist / approval
  matrix structure + Go/No-Go carry-forward + stop/abort gates + safety boundaries + privacy/financial
  constraints + validation commands).

**Validation:**
`php artisan test --filter=Sprint47PilotHealthCheckSupervisedExecutionApprovalGateOperatorChecklist`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:**
`feature/sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist` /
`sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist` (future GO tag
`sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist-go` after PR merge
only).

**Safety:** Documentation/checklist regression only. Supervised execution approval gate is documentation
only (not execution authorization); operator checklist is template only (not a command to perform real
execution). No real pilot health-check execution. No production/VPS/server access. No database/log/file
access. No deployment. No production command execution. No production backup execution. No production
restore execution. No rollback execution. No external monitoring integration. No scheduler/queue/cron
automation. No `.env` change. No dependency/package install. No migration/schema change. No runtime
behavior change. KTP remains hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded
from active receivables. Overpayment guard preserved. No GO tag.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 48 — Inventory Workflow Audit, Empty-Database Setup & Smoke Test Readiness

**Status:** Local Inventory audit implementation / pending PR.
**Scope:** Local controlled audit + documentation + checklist regression test only.

**Baseline:** Sprint 47 GO / `cd16bf5`
(`sprint-47-pilot-health-check-supervised-execution-approval-gate-operator-checklist-go`). Inventory
audit doc: `docs/sprint_48_inventory_workflow_audit_empty_database_setup_smoke_test_readiness.md`.

Inventory-focused, local-only **Inventory Workflow Audit, Empty-Database Setup & Smoke Test Readiness**.
RME is considered complete/closed for current planning, and the Pilot Health Check governance loop is
stopped. Documentation + checklist regression only — no Inventory runtime logic rewritten.

- **Inventory module map reviewed** — models, repositories, services, controllers, requests, policies,
  routes, views, tests, and permissions under `app/Modules/Inventory/` confirmed at baseline. Core
  ledger service is `InventoryStockService`; movement types are `OPENING`, `PURCHASE`, `ADJUSTMENT_IN`,
  `ADJUSTMENT_OUT`, `TRANSFER_IN`, `TRANSFER_OUT`; permissions `view_inventory` / `manage_inventory`
  plus granular analytics/batch/cross-branch/executive grants.
- **Empty-database setup workflow + master data input order documented** — branches, users/roles,
  `view_inventory`/`manage_inventory`, inventory locations, product units, product categories, suppliers,
  products, then opening stock and stock movements. Migrations/seeders restricted to a safe local/test
  environment.
- **Opening stock and ledger stock movement workflow documented** — current stock is ledger-derived
  (`current stock = stock in - stock out`); no direct stock mutation; opening stock is the first
  `OPENING` movement after physical count.
- **Stock receive, stock out, adjustment in/out, stock card, current stock, low stock, and stock opname
  workflows documented** — including positive-quantity guard, insufficient-stock guard, inactive
  product/location/supplier guards, expired/insufficient batch guards, and branch isolation as confirmed
  in `InventoryStockService`.
- **Smoke-test readiness checklist + empty-database seed/input templates added** — 24-point checklist
  and illustrative CSV templates (units, categories, locations, suppliers, products, opening stock) for a
  safe local/test environment, with no real production data, secrets, KTP, or WhatsApp numbers.
- **Defect/risk register candidates + follow-up recommendation added** — watch-list for negative stock,
  zero/negative quantity, inactive product/location use, cross-branch leakage, stock-card vs current-stock
  mismatch, dashboard reconciliation, and opening-stock duplication; recommended follow-up
  `Sprint 49 — Inventory Bugfix Batch 1 & Workflow Stabilization` only if confirmed bugs are found.
- **Targeted regression test** —
  `Sprint48InventoryWorkflowAuditEmptyDatabaseSetupSmokeTestReadinessTest` (doc/history checklist
  assertions over the Sprint 48 doc + baseline references + module map + empty-database setup principle +
  master data order + opening stock + stock movement/ledger workflows + smoke-test checklist + seed
  templates + defect register + safety boundaries + privacy/financial constraints + validation commands).

**Validation:**
`php artisan test --filter=Sprint48InventoryWorkflowAuditEmptyDatabaseSetupSmokeTestReadiness`,
optionally `php artisan test tests/Feature/Inventory`, `vendor/bin/pint --test`, `git diff --check`,
`git status --short`.

**Feature branch/tag:**
`feature/sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness` /
`sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness` (future GO tag
`sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness-go` after PR merge only).

**Safety:** Documentation/checklist regression only. No real Inventory data mutation. No direct stock
mutation — Inventory stock is changed through stock movements / ledger entries only. No deployment. No
VPS/production/server access. No production database/log/file access. No production command execution.
No production backup/restore/rollback. No `.env` change. No dependency/package install. No
migration/schema change. No runtime behavior change. No real production evidence collected. RME
considered complete/closed for current planning; Pilot Health Check governance loop stopped. KTP remains
hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded from active receivables.
Overpayment guard preserved. Financial rules not rewritten. No GO tag.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 49 — Inventory Workflow Smoke Test Execution & Defect Register

**Status:** Local Inventory smoke execution implementation / pending PR.
**Scope:** Local/test smoke execution + documentation + defect register + checklist regression only.

**Baseline:** Sprint 48 GO / merge commit `7e8fadb`
(`sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness-go`). Smoke execution doc:
`docs/sprint_49_inventory_workflow_smoke_test_execution_defect_register.md`.

Inventory-focused, local-only **Inventory Workflow Smoke Test Execution & Defect Register**. Sprint 48
documented the Inventory workflow from empty-database setup to smoke-test readiness; Sprint 49 executes
that smoke workflow safely in the local/test environment and records the result. RME remains
complete/closed for current planning, and the Pilot Health Check governance loop remains stopped.
Documentation + defect register + targeted regression only — no Inventory runtime logic rewritten.

- **Inventory workflow smoke executed in local/test environment** — through `InventoryStockService`
  against the `InventoryMovement` ledger, mirroring the existing `InventoryStockServiceTest` setup
  (`BranchSeeder`, main-branch context, existing model factories). No production data or mutation.
- **Master data setup flow validated** — branch/user/permission prerequisites, inventory location,
  product unit, product category, supplier, and product established via factories before stock movements.
- **Opening stock and ledger movement workflow validated** — opening stock creates a `TYPE_OPENING`
  inbound movement; current stock is ledger-derived (`current stock = stock in - stock out`); no direct
  stock mutation.
- **Stock receive, adjustment in/out, current stock, stock card/movement trail, low stock, branch
  isolation, and guard checks reviewed/validated** as supported by current APIs — positive-quantity
  guard, insufficient-stock guard, inactive product/location guards, branch isolation, and ledger-only
  (no mutable stock column) all confirmed PASS. Permission/access-control treated as already covered by
  existing `InventoryPermissionHardeningTest` / `InventoryRouteAuthorizationTest`.
- **Defect register added** — 13 PASS, 1 OBSERVATION (permission/access-control already covered), 0
  DEFECT, 0 BLOCKER, 1 FOLLOW-UP (non-blocking negative-stock concurrency watch item). No bugfix sprint
  is required by Sprint 49 findings.
- **Targeted regression test** —
  `Sprint49InventoryWorkflowSmokeTestExecutionDefectRegisterTest` (Part A doc/history checklist
  assertions over the Sprint 49 doc + baseline references + setup sequence + movement sections + defect
  register; Part B actual local/test Inventory smoke execution through `InventoryStockService`).

**Validation:**
`php artisan test --filter=Sprint49InventoryWorkflowSmokeTestExecutionDefectRegister`,
optionally `php artisan test tests/Feature/Inventory`, `vendor/bin/pint --test`, `git diff --check`,
`git status --short`.

**Feature branch/tag:**
`feature/sprint-49-inventory-workflow-smoke-test-execution-defect-register` /
`sprint-49-inventory-workflow-smoke-test-execution-defect-register` (future GO tag
`sprint-49-inventory-workflow-smoke-test-execution-defect-register-go` after PR merge only).

**Safety:** Local/test smoke execution + documentation + defect register + regression only. No real
Inventory data mutation. No direct stock mutation — Inventory stock is changed through stock movements /
ledger entries only. No deployment. No VPS/production/server access. No production database/log/file
access. No production command execution. No production backup/restore/rollback. No `.env` change. No
dependency/package install. No migration/schema change. No runtime behavior change. No real production
evidence collected. RME considered complete/closed for current planning; Pilot Health Check governance
loop stopped. KTP remains hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded from
active receivables. Overpayment guard preserved. Financial rules not rewritten. No GO tag.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 50 — Inventory Bugfix Batch 1 & Workflow Stabilization

**Status:** Local Inventory stabilization implementation / pending PR.
**Scope:** Local/test stabilization + targeted regression + bugfix/stabilization register only.

**Baseline:** Sprint 49 GO / merge commit `fd9f8e5`
(`sprint-49-inventory-workflow-smoke-test-execution-defect-register-go`). Stabilization doc:
`docs/sprint_50_inventory_bugfix_batch_1_workflow_stabilization.md`.

Inventory-focused, local-only **Inventory Bugfix Batch 1 & Workflow Stabilization**. Sprint 49 executed
the Inventory workflow smoke suite and carried forward **0 defects and 0 blockers**; Sprint 50 therefore
applies **no speculative bugfixes** and closes as regression hardening and workflow stabilization. RME
remains complete/closed for current planning, and the Pilot Health Check governance loop remains stopped.

- **No runtime bug reproduced — no runtime code changed.** Sprint 50 is documentation + targeted
  regression only. A runtime fix would only have been applied for a reproduced, small, safe, Inventory-only
  bug requiring no schema/dependency/`.env` change; none was found.
- **Ledger-only stock invariants reviewed/stabilized** — current stock is ledger-derived
  (`stock in - stock out`); no direct stock mutation; no mutable `current_stock` column.
- **Current stock and stock card consistency validated** — `getCurrentStock()` equals the raw ledger sum
  and `getStockCard()` returns the movement trail in workflow order.
- **Branch isolation reviewed/stabilized** — cross-branch product/location/supplier rejected; Branch A
  movement does not affect Branch B current stock (`BranchContext` / `branch_id`).
- **Permission/access-control representative check validated** — unauthenticated Inventory route
  (`inventory.stock.index`) redirects to login; permission-name gating remains covered by existing
  `InventoryPermissionHardeningTest` / `InventoryRouteAuthorizationTest`.
- **Inactive product/location/supplier guards, positive-quantity guard, and insufficient-stock guard
  reviewed/validated** as supported by current `InventoryStockService` APIs.
- **Concurrency watch item documented** — `INV-FU-001` negative-stock concurrency remains a non-blocking
  deferred FOLLOW-UP; a true race requires a separate approved concurrency/locking sprint and was not
  patched speculatively.
- **Bugfix/stabilization register added** — 8 PASS/STABILIZED (`INV-STAB-001..008`), 0 DEFECT,
  0 BLOCKER, 1 FOLLOW-UP/DEFERRED (`INV-FU-001`).
- **Targeted regression test** —
  `Sprint50InventoryBugfixBatch1WorkflowStabilizationTest` (Part A doc/history checklist assertions over
  the Sprint 50 doc + baseline references + policy/invariant/guard/register sections; Part B local/test
  Inventory stabilization through `InventoryStockService` + a representative access-control redirect).

**Validation:**
`php artisan test --filter=Sprint50InventoryBugfixBatch1WorkflowStabilization`,
optionally `php artisan test tests/Feature/Inventory/InventoryStockServiceTest.php`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:**
`feature/sprint-50-inventory-bugfix-batch-1-workflow-stabilization` /
`sprint-50-inventory-bugfix-batch-1-workflow-stabilization` (future GO tag
`sprint-50-inventory-bugfix-batch-1-workflow-stabilization-go` after PR merge only).

**Safety:** Local/test stabilization + documentation + regression only. No real Inventory data mutation.
No direct stock mutation — Inventory stock is changed through stock movements / ledger entries only. No
deployment. No VPS/production/server access. No production database/log/file access. No production command
execution. No production backup/restore/rollback. No `.env` change. No dependency/package install. No
migration/schema change. No broad runtime behavior change. No real production evidence collected. RME
considered complete/closed for current planning; Pilot Health Check governance loop stopped. KTP remains
hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded from active receivables.
Overpayment guard preserved. Financial rules not rewritten. No GO tag before PR merge.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 51 — Inventory UI Operational Walkthrough & User Acceptance Checklist

**Status:** Local Inventory UI walkthrough implementation / pending PR.
**Scope:** Local/test UI walkthrough + user acceptance checklist + targeted UI regression only.

**Baseline:** Sprint 50 GO / merge commit `6b5028b`
(`sprint-50-inventory-bugfix-batch-1-workflow-stabilization-go`). UI walkthrough doc:
`docs/sprint_51_inventory_ui_operational_walkthrough_user_acceptance_checklist.md`.

Inventory-focused, local-only **UI Operational Walkthrough & User Acceptance Checklist**. Following the
Sprint 48 audit, Sprint 49 smoke execution (0 defects, 0 blockers), and Sprint 50 stabilization (no runtime
changes), Sprint 51 verifies Inventory from the **user-facing UI and operational acceptance perspective**.
No Inventory business logic is rewritten. RME remains complete/closed for current planning; the Pilot
Health Check governance loop remains stopped.

- **Inventory UI operational walkthrough documented** — Inventory menu, dashboard, master-data pages,
  stock-movement entry points, stock card, current stock, low-stock display, branch-aware behavior,
  permission/access behavior, inactive product/location behavior, and validation messages.
- **Dashboard walkthrough documented** — `inventory.dashboard` renders KPI/summary; no patient/KTP data.
- **Master data page walkthrough documented** — product units (`Satuan Produk`), categories
  (`Kategori Produk`), suppliers (`Pemasok Persediaan`), locations (`Lokasi Persediaan`), products
  (`Produk Persediaan`); all branch-scoped.
- **Stock movement UI walkthrough documented** — opening stock, receive, adjustment in, adjustment out,
  each writing a ledger movement (`TYPE_OPENING`/`TYPE_PURCHASE`/`TYPE_ADJUSTMENT_IN`/`TYPE_ADJUSTMENT_OUT`)
  with positive-quantity, insufficient-stock, inactive-product/location/supplier and cross-branch guards.
- **Current stock / stock card / low-stock UI walkthrough documented** — ledger-derived current stock,
  `Kartu Stok` movement trail in workflow order, and `Peringatan Persediaan` low-stock page.
- **Branch-aware and permission/access-control UI checks documented** — unauthenticated redirect to login,
  authorized read/manage access, and forbidden cross-branch access.
- **Inactive product/location UI checks and validation/error-message checks documented.**
- **User acceptance checklist added** — 21 acceptance areas (operator + admin), all PASS.
- **UI observation/follow-up register added** — 7 PASS (`INV-UI-001..007`), 0 FAIL, 0 BLOCKER, 2 FOLLOW-UP
  (`INV-FU-001` carried forward/deferred, `INV-UI-FU-002` UX polish). **No runtime UI bug reproduced — no
  runtime code changed.**
- **Targeted regression test** —
  `Sprint51InventoryUiOperationalWalkthroughUserAcceptanceChecklistTest` (Part A doc/history checklist
  assertions over the Sprint 51 doc + baseline references + section/register coverage; Part B local/test
  Inventory UI/HTTP walkthrough through existing `inventory.*` routes + `InventoryStockService` ledger APIs).

**Validation:**
`php artisan test --filter=Sprint51InventoryUiOperationalWalkthroughUserAcceptanceChecklist`,
optionally `php artisan test tests/Feature/Inventory/InventoryUiTest.php`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:**
`feature/sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist` /
`sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist` (future GO tag
`sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist-go` after PR merge only).

**Safety:** Local/test UI walkthrough + documentation + regression only. No real Inventory data mutation.
No direct stock mutation — Inventory stock is changed through stock movements / ledger entries only. No
deployment. No VPS/production/server access. No production database/log/file access. No production command
execution. No production backup/restore/rollback. No `.env` change. No dependency/package install. No
migration/schema change. No broad runtime behavior change. No production data/evidence collected. RME
considered complete/closed for current planning; Pilot Health Check governance loop stopped. KTP remains
hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded from active receivables.
Overpayment guard preserved. Financial rules not rewritten. No GO tag before PR merge.

### Decision

GO CANDIDATE FOR PR REVIEW.

---

## Sprint 52 — Inventory Operator Pilot Input Template & Stock Opname Readiness

**Status:** Local Inventory operator template implementation / pending PR.
**Scope:** Local/test documentation + operator templates + stock opname readiness checklist regression only.

**Baseline:** Sprint 51 GO / merge commit `ceb2bab`
(`sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist-go`). Operator template doc:
`docs/sprint_52_inventory_operator_pilot_input_template_stock_opname_readiness.md`.

Inventory-focused, local-only **Operator Pilot Input Template & Stock Opname Readiness**. Following the
Sprint 48 audit, Sprint 49 smoke execution, Sprint 50 stabilization, and Sprint 51 UI walkthrough/user
acceptance, Sprint 52 prepares practical operator-facing templates so real users can stage master data,
opening stock, and physical stock count safely **before** operational pilot input. No Inventory business
logic is rewritten. RME remains complete/closed for current planning; the Pilot Health Check governance
loop remains stopped.

- **Inventory operator pilot input templates added** — CSV-style templates for branch/inventory location,
  product unit, product category, supplier, product/item, opening stock, stock opname count sheet, and
  stock opname adjustment review. All example rows are dummy/example data only.
- **Operator checklist added** — reviewed templates only, branch context first, master data before stock,
  opening stock through movement/ledger, no direct current-stock edit, escalate on doubt.
- **Reviewer checklist added** — code uniqueness, branch/location mapping, category/unit/supplier
  references, physical-count and opname-difference verification, adjustment approval, stock-card check.
- **Data validation checklist added** — required fields, uniqueness, reference existence, numeric/positive
  quantities, and a sensitive-data guard (no KTP/patient/WA/credential/token/log/dump data).
- **Stock opname readiness checklist added** — master-data confirmation, count team/reviewer assignment,
  count sheet + system reference prep, difference/adjustment rules, negative-stock and inactive guards,
  stock-card review, low-stock follow-up, and pilot handoff owner.
- **Pilot handoff checklist added** — completion of master data/opening stock/opname templates, role
  assignment, risk listing, data validation, acceptance criteria, and the ready-for-pilot decision.
- **Stock opname execution boundary documented** — templates only; the real opname runs later through
  existing `TYPE_ADJUSTMENT_IN` / `TYPE_ADJUSTMENT_OUT` ledger movements with the insufficient-stock guard.
- **Follow-up recommended** — Sprint 53 — Inventory Pilot Data Entry Dry-Run & Template Validation.
- **Targeted regression test** —
  `Sprint52InventoryOperatorPilotInputTemplateStockOpnameReadinessTest` (doc/history checklist assertions
  over the Sprint 52 doc + baseline references + template/checklist/section coverage).

**Validation:**
`php artisan test --filter=Sprint52InventoryOperatorPilotInputTemplateStockOpnameReadiness`,
`vendor/bin/pint --test`, `git diff --check`, `git status --short`.

**Feature branch/tag:**
`feature/sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness` /
`sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness` (future GO tag
`sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness-go` after PR merge only).

**Safety:** Local/test documentation + operator templates + checklist regression only. No real stock
opname execution. No real data import. No direct stock mutation — Inventory stock is changed through
stock movements / ledger entries only. No deployment. No VPS/production/server access. No production
database/log/file access. No production command execution. No production backup/restore/rollback. No
`.env` change. No dependency/package install. No migration/schema change. No runtime behavior change. No
production data/evidence collected. RME considered complete/closed for current planning; Pilot Health
Check governance loop stopped. KTP remains hidden. WhatsApp manual-only. Zero-remaining receivables remain
excluded from active receivables. Overpayment guard preserved. Financial rules not rewritten. Dummy/example
data only. No GO tag before PR merge.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 53 — Permission Page Module Grouping Hotfix

**Status:** Local permission UI hotfix implementation / pending PR.
**Scope:** Permission page UI grouping + documentation + targeted regression only.

**Baseline:** Sprint 52 GO / merge commit `cf29f45`
(`sprint-52-inventory-operator-pilot-input-template-stock-opname-readiness-go`). Hotfix doc:
`docs/sprint_53_permission_page_module_grouping_hotfix.md`.

Focused, UI-only **Permission Page Module Grouping Hotfix**. The role permission assignment page already
renders permissions per module via `App\Modules\AccessControl\Services\PermissionGroupingService`, but RME
permissions (`view_clinic_visits`, `manage_clinic_visits`, `manage_rme_billing`, `view_rme_patient_reports`,
`view_rme_payment_reports`) fell into the **Other / Uncategorized** bucket. Sprint 53 adds a dedicated
**RME / Rekam Medis** group so Inventory, Lab, and RME are clearly separated for admin/owner classification.

- **Permission page grouped by module** — generic Blade renders ordered module sections from the grouping
  service; no view/controller change required.
- **Inventory, Lab, and RME groups present** — Inventory (`Inventory / Persediaan`) and Lab (`Lab Order`)
  already existed; the new RME group (`RME / Rekam Medis`) is added between Master Data and Lab Order.
- **Other / Uncategorized fallback included** — unmatched permissions still render under Other.
- **Permission slugs preserved** — no rename, no deletion; the new group references existing slugs only.
- **Authorization behavior preserved** — no policy/middleware/gate change; form submission unchanged.
- **Selected permission state preserved** — checked permissions remain checked when editing a role.
- **No policy/middleware rewrite**, no migration/schema/seeder rewrite (`PermissionSeeder` unchanged).
- **No deployment / VPS / production / server / database / log / file access**, no `.env`/dependency change.
- **No financial logic rewrite** — KTP remains hidden, WhatsApp manual-only, zero-remaining receivables
  remain excluded, overpayment guard preserved. RME remains complete/closed for current planning; the Pilot
  Health Check governance loop remains stopped; Inventory stock remains ledger-based and unrelated.
- **Targeted regression test** —
  `Sprint53PermissionPageModuleGroupingHotfixTest` (doc/history assertions + grouping-behavior assertions
  proving RME/Inventory/Lab/Other buckets, slug preservation, and full seeded-permission coverage).

**Validation:**
`php artisan test --filter=Sprint53PermissionPageModuleGroupingHotfix`,
`php artisan test tests/Feature/AccessControl`, `vendor/bin/pint --test`, `git diff --check`,
`git status --short`.

**Feature branch/tag:**
`feature/sprint-53-permission-page-module-grouping-hotfix` /
`sprint-53-permission-page-module-grouping-hotfix` (future GO tag
`sprint-53-permission-page-module-grouping-hotfix-go` after PR merge only).

**Safety:** Local UI/helper + documentation + regression only. No deployment. No VPS/production/server
access. No production database/log/file access. No production command execution. No production
backup/restore/rollback. No `.env` change. No dependency/package install. No migration/schema change. No
permission slug rename. No permission deletion. No authorization logic rewrite. No policy/middleware
rewrite. KTP remains hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded. Overpayment
guard preserved. RME considered complete/closed for current planning; Pilot Health Check governance loop
stopped. No GO tag before PR merge.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 54 — Permission UI Polish & Role Assignment Usability

**Status:** Local permission UI polish implementation / pending PR.
**Scope:** Permission grouped UI polish + role assignment usability + documentation + targeted regression only.

**Baseline:** Sprint 53 GO / merge commit `6b7e977`
(`sprint-53-permission-page-module-grouping-hotfix-go`). Sprint doc:
`docs/sprint_54_permission_ui_polish_role_assignment_usability.md`.

Focused, UI-usability polish on top of the Sprint 53 module grouping. The role create/edit permission
assignment view already grouped permissions per module via
`App\Modules\AccessControl\Services\PermissionGroupingService`; Sprint 54 makes the grouped UI easier to
scan and assign without any authorization, slug, or schema change.

- **Permission grouping from Sprint 53 preserved** — Inventory, Lab, and RME groups remain visible; the
  Other / Uncategorized fallback remains available.
- **Module descriptions / readability improved** — each module group now carries a short helper description
  (added as an additive `description` field on the grouping service), rendered under the group heading.
- **Role assignment helper text added** — a "Permission Role" heading plus guidance copy explains how to
  assign role permissions, that active permissions stay checked on edit, and what Other / Uncategorized means.
- **Selected permission state preserved** — checked permissions remain checked when editing a role; a
  per-module "selected / total permission dipilih" count is shown for clarity.
- **Permission slugs preserved** — no rename, no deletion; checkbox `name`/`value` inputs are unchanged.
- **Authorization behavior preserved** — no policy/middleware/gate change; existing form submission unchanged.
- **No policy/middleware rewrite**, no migration/schema/seeder rewrite (`PermissionSeeder` unchanged).
- **No deployment / VPS / production / server / database / log / file access**, no `.env`/dependency change.
- **No financial logic rewrite** — KTP remains hidden, WhatsApp manual-only, zero-remaining receivables
  remain excluded, overpayment guard preserved. RME remains complete/closed for current planning; the Pilot
  Health Check governance loop remains stopped; Inventory stock remains ledger-based and unrelated.
- **VPS update deferred** — VPS update is a separate supervised post-merge workflow, not executed in this sprint.
- **Targeted regression test** —
  `Sprint54PermissionUiPolishRoleAssignmentUsabilityTest` (doc/history assertions + grouping-behavior and
  rendered-UI assertions proving group descriptions/helper copy, preserved RME/Inventory/Lab/Other buckets,
  slug preservation, and preserved checked state on edit).

**Validation:**
`php artisan test --filter=Sprint54PermissionUiPolishRoleAssignmentUsability`,
`php artisan test tests/Feature/AccessControl`, `vendor/bin/pint --test`, `git diff --check`,
`git status --short`.

**Feature branch/tag:**
`feature/sprint-54-permission-ui-polish-role-assignment-usability` /
`sprint-54-permission-ui-polish-role-assignment-usability` (future GO tag
`sprint-54-permission-ui-polish-role-assignment-usability-go` after PR merge only).

**Safety:** Local UI/helper + documentation + regression only. No deployment. No VPS/production/server
access. No production database/log/file access. No production command execution. No production
backup/restore/rollback. No `.env` change. No dependency/package install. No migration/schema change. No
permission slug rename. No permission deletion. No authorization logic rewrite. No policy/middleware
rewrite. KTP remains hidden. WhatsApp manual-only. Zero-remaining receivables remain excluded. Overpayment
guard preserved. RME considered complete/closed for current planning; Pilot Health Check governance loop
stopped. No GO tag before PR merge.

### Decision

GO CANDIDATE FOR PR REVIEW.

## Sprint 55 — Permission Group Classification Cleanup

**Status:** Local permission grouping cleanup / pending PR.
**Scope:** `PermissionGroupingService` classification cleanup + docs + targeted regression only.

**Baseline:** Sprint 54 GO / merge commit `0501e77`
(`sprint-54-permission-ui-polish-role-assignment-usability-go`). Sprint doc:
`docs/sprint_55_permission_group_classification_cleanup.md`.

After a VPS role permission audit, actual role access was confirmed business-valid — the only outstanding
issue was UI classification, where several operational permissions still surfaced under **Other /
Uncategorized** or under labels that did not clearly describe their module. Sprint 55 cleans up the
permission group classification only.

- **Doctor/Kasir/Perawat flagged permissions business-approved and unchanged** — Doctor
  `manage_clinic_visits`, Kasir `manage_rme_billing`, and Perawat `manage_clinic_visits` are
  business-approved and unchanged.
- **Delivery permissions moved out of Other** — `assign_courier`, `manage deliveries`, `mark_delivered`
  now group under **Delivery / Pengiriman**.
- **Technician/Assignment permissions moved out of Other** — `assign_technicians`,
  `reassign_technicians`, `manage technicians`, `manage assignments` now group under
  **Technician / Assignment**.
- **Quality Control permissions moved out of Other** — `manage_quality_control`, `view_quality_control`,
  `request_remake` now group under **Quality Control**.
- **Purchase/Procurement permissions moved out of Other** — `view_purchase_request`,
  `manage_purchase_request` now group under **Purchase / Procurement**.
- **Branch Master Data permissions moved out of Other** — `view_branch_master_data`,
  `manage_branch_master_data` now group under **Branch Master Data**.
- **Master Data permission moved out of Other** — `manage master data` groups under **Master Data**.
- **Other fallback preserved** — Other / Uncategorized still catches unknown/future slugs.
- **Permission slugs preserved** — no rename, no deletion.
- **Role assignments preserved** — no role → permission grant/revoke; `PermissionSeeder` unchanged.
- **Authorization behavior preserved** — no policy/middleware rewrite, no gate change.
- **Sprint 54 UI polish preserved** — group descriptions / helper copy / checked-state preservation intact.
- **No deployment / VPS / production access during local sprint**; no `.env`/dependency/migration/schema change.

**Validation:**
`php artisan test --filter=Sprint55PermissionGroupClassificationCleanup`,
`php artisan test --filter=Sprint54PermissionUiPolishRoleAssignmentUsability`,
`php artisan test tests/Feature/AccessControl`, `vendor/bin/pint --test`, `git diff --check`,
`git status --short`.

**Feature branch/tag:**
`feature/sprint-55-permission-group-classification-cleanup` /
`sprint-55-permission-group-classification-cleanup` (future GO tag
`sprint-55-permission-group-classification-cleanup-go` after PR merge only).

**Safety:** Local grouping/classification + documentation + regression only. No deployment. No
VPS/production/server access. No production database/log/file access. No `.env` change. No
dependency/package install. No migration/schema change. No permission slug rename. No permission
deletion. No role assignment change. No authorization logic rewrite. No policy/middleware rewrite. KTP
remains hidden. WhatsApp manual-only. Zero-remaining receivable rule preserved. Overpayment guard
preserved. RME remains complete/closed. Pilot Health Check governance loop remains stopped. Inventory
stock remains ledger-based. No GO tag before PR merge.

---

## Hotfix — Inventory Unified Branch Master Guardrail (June 2026)

Inventory must use `mst_branches` as the unified branch source shared with RME and all modules
(Lab, Cashier, Receivables, Owner Dashboard, Access Control). Audit confirmed the Inventory module
already references `mst_branches` exclusively via `App\Modules\Branch\Models\Branch` and
`BranchContext`; no redundant branch master (`inventory_branches`, `inv_branches`, `lab_branches`,
`cashier_branches`, `rme_branches`) exists. This hotfix is a governance guardrail only: it adds
`tests/Feature/Inventory/InventoryUnifiedBranchMasterHotfixTest.php` (DB foreign-key introspection,
model-relation reflection, migration/seeder/source file scanning) to prevent any future redundant
branch master, plus `docs/hotfix_inventory_unified_branch_master.md`. **Safety:** no migration, no
schema change, no data change, no business-flow/stock-movement/RME change.

---

## Hotfix — Inventory Batch/Lot Stock-In UI Visibility (June 2026)

Clarified that the Batch/Lot page is monitoring-only and added visible stock-in guidance/fields for
Goods Receipt and Opening Stock workflows. Added an operator guidance card (with `Route::has`-guarded
shortcuts) on the Batch & Lot index, batch helper text on the Goods Receipt and Receive/Adjustment-In
forms, and wired the Opening Stock form + `StoreOpeningStockRequest` to the existing
`ValidatesInventoryBatchInput` concern and the already-present `InventoryStockService::createOpeningStock`
`$batchData` parameter. **Safety:** no manual batch create button/route, no new/duplicate batch table,
no migration, no ledger-stock logic change, no RME change, no branch master change. Tests:
`tests/Feature/Inventory/InventoryBatchLotUiVisibilityHotfixTest.php` (11 passed); full Inventory suite
1200 passed; Pint passed. Doc: `docs/hotfix_inventory_batch_lot_ui_visibility.md`.

---

## Hotfix — Goods Receipt Batch Fields Visible (June 2026)

Goods Receipt item lines now visibly show Batch/Lot fields for batch-tracked products and prevent
posting batch-tracked receipts without `batch_number`. Root cause was UI-only: the batch section in
`_form.blade.php` and `_batch-item-fields.blade.php` was gated by
`item.requires_batch_tracking && Number(item.accepted_qty || 0) > 0`, so the Batch/Lot inputs hid
whenever accepted_qty was blank/zero. The gate is now `item.requires_batch_tracking` alone, with a
mandatory operator notice ("Produk ini wajib batch. Isi Nomor Batch sebelum Submit/Post Goods
Receipt."). Backend validation and the existing ledger post flow (which already creates the
`InventoryBatch` and stamps `inventory_batch_id` on item + movement) were already correct and are
unchanged. **Safety:** no migration, no new/duplicate batch table, no manual batch create/store route,
no ledger-stock logic change, no procurement/PO lifecycle change, no RME change, no branch master
change. Tests: `tests/Feature/Inventory/GoodsReceiptBatchFieldsVisibleHotfixTest.php` (8 passed). Doc:
`docs/hotfix_goods_receipt_batch_fields_visible.md`.

---

## Hotfix — Goods Receipt Create PO Batch Panel Rendering (June 2026)

Ensures the actual create-from-PO receipt page renders Batch/Lot inputs for PO items whose products
require batch tracking. Follow-up to the batch-visibility hotfix: even though the panel was gated only
on `item.requires_batch_tracking`, on VPS the batch panel never reached the live DOM (browser console:
`document.body.innerText.includes('Produk ini wajib batch') === false`) for `PO-20260624-4-000002`
whose four products (COTTON ROLL, ALKOHOL, ASEPTIC GEL, CAIRAN SPIRTUS) are all batch-tracked. Root
cause was UI-only: the desktop items table placed **two sibling `<tr>` roots** (data row + batch row)
inside one `<template x-for>`, but Alpine `x-for` requires a **single root element**, so it cloned only
the data row and silently dropped the batch row — the server HTML still contained the markup (inside
the inert `<template>`), so `assertSee` passed while the desktop DOM showed nothing. Fix: make the
per-item **`<tbody>` the single x-for root** wrapping both rows (a table may hold multiple `<tbody>`),
plus a hidden `requires_batch_tracking` input so the flag round-trips through old-input re-render. Data
binding was already correct (`buildPrefillItemsFromPurchaseOrder` emits the flag; edit eager-loads
`items.product`). **Safety:** no migration, no manual batch create/store route, no ledger redesign, no
procurement lifecycle change, no RME change, no branch master change, no destructive data change.
Tests: `tests/Feature/Inventory/GoodsReceiptCreatePoBatchPanelRenderingHotfixTest.php` (8 passed);
full Inventory suite 1216 passed (6178 assertions); Pint passed; `git diff --check` clean. Doc:
`docs/hotfix_goods_receipt_create_po_batch_panel_rendering.md`.

## Hotfix — Stock Transfer Checklist Branding Daengtisia (June 2026)

The downloaded/printed **"Checklist Pengiriman Barang Antar Lokasi"** still showed the legacy brand
text `ASIA DENTAL LAB` in the document header. Root cause was a hardcoded brand string in the
checklist PDF template (`resources/views/inventory/stock-transfers/checklist-pdf.blade.php`:
`<p class="brand">Asia Dental Lab</p>`); the `.brand` CSS already uppercases the text, so it rendered
as `ASIA DENTAL LAB`. Fix: replace the literal with a config-driven expression
`{{ strtoupper(config('app.name', 'Daengtisia Management System')) }}`, which resolves `APP_NAME`
(`"Daengtisia Management System"` in `.env`) so the header now reads `DAENGTISIA MANAGEMENT SYSTEM`.
Route/view fixed: `inventory.stock-transfers.checklist`
(`StockTransferController@downloadChecklist`) → `inventory/stock-transfers/checklist-pdf.blade.php`.
Test added to `tests/Feature/Inventory/StockTransferChecklistPdfTest.php` renders the actual checklist
view with real transfer data and asserts it contains `DAENGTISIA MANAGEMENT SYSTEM` and not
`ASIA DENTAL LAB` (download route returns compressed binary PDF, so text is asserted against the
rendered Blade view the route loads). **Safety:** no migration, no stock transfer lifecycle change, no
inventory ledger change, no branch master change, no route redesign, no RME change. Validation:
`php artisan test --filter=StockTransfer` 116 passed (552 assertions);
`php artisan test --filter=Inventory` 1217 passed (6181 assertions); Pint passed; `git diff --check`
clean. Doc: `docs/hotfix_stock_transfer_checklist_branding_daengtisia.md`.

## Hotfix — Supervisor RME Role with Full RME Permissions (June 2026)

Added a new least-privilege role **`Supervisor RME`** that grants full access to the entire RME
module only. The role is defined in `database/seeders/RoleSeeder.php::ROLE_PERMISSIONS` (the existing
idempotent `Role::firstOrCreate` + `syncPermissions` seeder), so re-running the seeder is safe and no
existing role is changed. Permissions were selected by enumerating every permission that gates an RME
route in `routes/web.php` (cross-checked against the `rme` group in
`App\Modules\AccessControl\Services\PermissionGroupingService`): `view dashboard`, `manage patients`
(patient register/edit + KTP scan documents that feed the RME workflow, and the patient-audit page),
`view_clinic_visits`, `manage_clinic_visits` (visit queue, room assignment, medical record,
odontogram, print/PDF bundle, doctor examination), `view_treatment_worklist`, `manage_rme_billing`
(cashier billing, payment, receivables, follow-ups), `view_rme_patient_reports`, and
`view_rme_payment_reports`. The role intentionally excludes Lab, Inventory, Procurement, Access
Control admin, Owner/branch dashboards, HR, and system settings. **Safety:** no migration, no
permission added/removed/renamed, no route/policy/middleware change, no behavior change to existing
roles. Validation: new `tests/Feature/Auth/SupervisorRmeRolePermissionTest.php` (8 passed, 42
assertions) asserts the role exists, holds every RME permission, is idempotent, excludes
Lab/Inventory/Procurement/Access-Control/Owner permissions, and that a Supervisor RME user can reach
representative RME pages (dashboard, visit list/create, queue, treatment worklist, medical records,
cashier, receivables, patient/payment reports, patient list/create, patient audit) while being
forbidden from lab-order and lab-candidate pages. `--filter=Permission` 211 passed; `--filter=Role`
76 passed; Pint passed; `git diff --check` clean.
