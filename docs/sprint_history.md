# ADLMS Sprint History

Version: 1.0
Last updated: June 2026
Status: **Permanent project memory.** Captures the major decisions from Sprint 0 through
Sprint 12 (with current Sprint 13 work in progress).

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
| 13 (in progress) | Inventory Advanced / Stock Opname | — | `trx_stock_opnames`, `trx_stock_opname_items` |

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
10. **S13 (planned)** — Stock opname compares counted vs derived stock and posts adjustment ledger
    movements on finalize; draft counts are never a stock source of truth.

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

- **Sprint 13 — Inventory Advanced / Stock Opname:** Preserve ledger-derived stock, branch+location
  isolation, no mutable stock columns, service-managed finalization, transactional posting. Opname
  compares counted vs derived stock and posts adjustment ledger movements **on finalize**; draft
  counts are never a stock source of truth. *(Schema for `trx_stock_opnames` / `trx_stock_opname_items`
  and models/repositories already scaffolded.)*
- **Sprint 14 — Stock Transfer:** Ledger-based only — outbound movement from source location +
  inbound to destination, wrapped in one transaction; per-location source sufficiency checked;
  same branch unless inter-branch is explicitly designed. Never transfer by updating a stock column.
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
(S12, ledger-derived, location-aware).

**Roles:** Super Admin, Admin Lab, Technician, Quality Control, Delivery Coordinator, Courier,
Finance, Doctor.

**Read before coding:** `docs/architecture_rules.md`, `docs/ai_development_guide.md`, this file,
the target module under `app/Modules`, relevant routes/policies/tests, and (branch work)
`app/Modules/Branch/Services/BranchContext.php`. Inventory invariants and the Future Sprint
Constraints above are binding.

---

*Historical record only — this document changes no application code. It reflects decisions as of
Sprint 12 (with Sprint 13 stock-opname scaffolding in progress) and must be updated as each new
sprint completes.*
