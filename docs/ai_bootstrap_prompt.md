# ADLMS AI Bootstrap Prompt

Version: 1.0
Last updated: June 2026
Status: **Mandatory.** Every AI agent must execute this bootstrap before any implementation work
on the Asia Dental Lab Management System (ADLMS).

---

# Foundation-First Sprint Lock

Foundation-first sprint lock is active. Read
[`docs/architecture/foundation-first-sprint-lock-governance.md`](architecture/foundation-first-sprint-lock-governance.md)
before planning or implementing work. Non-foundation work must be recorded as
POST-FOUNDATION BACKLOG and must not execute until FOUNDATION GO is complete.

# Purpose

This document is the **single mandatory startup procedure** for any AI agent (Claude Code, Solo
Trae, Codex, Cursor, GitHub Copilot Agent, or any future assistant) working on ADLMS.

ADLMS is a live-testing, multi-branch Laravel modular monolith with permissions, reporting,
branch isolation, and **ledger-derived inventory**. Its value depends on consistency: branch data
must never leak, stock must always be derived from the movement ledger, and every change must flow
through `Controller → Request → Service → Repository → Model`.

**No analysis, planning, code, schema, or test may be produced before this bootstrap is completed.**
Executing this prompt forces the agent to load the architecture, business rules, UI rules,
inventory rules, and sprint history into context, confirm understanding, validate the change
against existing architecture, and only then plan and implement. Skipping it is itself a violation.

---

# Required Reading Order

Every AI agent must read these documents, **in this exact order**, before doing anything else:

1. [`docs/architecture_rules.md`](architecture_rules.md) — the architecture constitution
   (layering, branch isolation, ledger inventory, anti-patterns, future-sprint protection).
2. [`docs/ai_development_guide.md`](ai_development_guide.md) — the operating manual (module
   structure, naming, BranchContext, testing, UI/UX standards, checklists, prompt templates).
3. [`docs/inventory_rules.md`](inventory_rules.md) — the inventory authority (ledger-only stock,
   movement types, location rules, opname, valuation, anti-patterns).
4. [`docs/ui_design_system.md`](ui_design_system.md) — the official UI/UX standard (layout,
   navigation, dashboards, tables, forms, badges, empty states, responsive, accessibility).
5. [`docs/sprint_history.md`](sprint_history.md) — permanent project memory (Sprint 0–12 decisions,
   protected decisions, future-sprint constraints).

Supporting reads when relevant to the task: the target module under `app/Modules/<Module>`, related
routes in `routes/web.php`, relevant policies/permissions, relevant tests under
`tests/Feature/<Module>`, and for branch-owned work `app/Modules/Branch/Services/BranchContext.php`.

> **No implementation is allowed before reading documents 1–5.** If the agent cannot access them,
> it must stop and say so rather than guess.

---

# Project Understanding Phase

After reading, the agent must produce a brief written summary proving it understood the system,
covering:

- **Business purpose** — operational ERP for a multi-branch dental laboratory: lab order intake →
  production → QC → delivery/POD → invoice/payment → reporting, plus multi-branch and ledger-based
  inventory. Pilot/live status → small, scoped, tested changes only.
- **Architecture** — Laravel modular monolith; `app/Modules/<Module>` owning
  Controllers/Requests/Services/Repositories+Interfaces/Models/Policies; mandatory flow
  `Controller → Request → Service → Repository → Model`; bindings/policies/gates registered in
  `RepositoryServiceProvider`; single PostgreSQL DB; stack = Blade + Tailwind + Alpine + Pest +
  Spatie Permission (no new frameworks).
- **Branch model** — branch-owned data carries `branch_id`; the active branch is resolved
  centrally by `BranchContext` (`id`, `branch`, `requireId`, `forUser`); user input never decides
  `branch_id`; cross-branch leakage is a security bug.
- **Inventory model** — `Branch → Inventory Location → Product → Movement Ledger`; stock is
  **derived only**: `current stock = SUM(quantity_in) - SUM(quantity_out)`; no mutable stock
  columns; movement types `OPENING/PURCHASE/ADJUSTMENT_IN/ADJUSTMENT_OUT`; location-aware;
  valuation = derived stock × `average_cost`.
- **UI standards** — dense internal SaaS; `<x-settings-shell>` + permission-aware sidebar; teal
  primary; bordered cards; dual desktop-table / mobile-card lists; semantic status badges; empty
  states; reuse the `owner-dashboard.*`, `branch-dashboard.*`, `inventory.*` component families.
- **Sprint history** — S0 foundation → S1 access → S2 master data → S3 lab order → S4 production →
  S5 QC → S6 delivery → S7 invoice/payment → S8(.1) reporting/MRN → S9 hardening → S10 branch
  context → S11 branch enforcement → S12 inventory core; S13 stock opname scaffolding in progress.

**The agent must explicitly confirm understanding** (a short "I have read docs 1–5 and understand
X, Y, Z" statement) **before writing any code or plan.**

---

# Architecture Validation Phase

Before designing the change, the agent must identify and list:

- **Affected modules** — which `app/Modules/<Module>` own the behavior (and whether a new module is
  genuinely required vs extending an existing one).
- **Affected services** — which services own the business rule(s); whether a transaction is needed.
- **Affected repositories** — which repositories/interfaces own the queries; whether new
  branch-scoped methods are needed.
- **Affected policies** — which policies/gates authorize the action; whether new permissions must
  be seeded (rarely — follow existing conventions).
- **Affected tests** — which `tests/Feature/<Module>` suites change; which new auth /
  branch-isolation / validation / ledger tests are required.

The agent must then **explain how the proposed work aligns with the existing architecture** — which
established patterns it extends, and confirm it does not introduce a forbidden dependency, a new
architecture, or scope creep into future-sprint features.

---

# Implementation Rules

The AI must **always**:

- Follow the modular monolith structure (code lives in the owning module).
- Follow `Controller → Request → Service → Repository → Model`.
- Use `BranchContext::requireId()` for branch-owned operations.
- Use Policies/Gates for authorization (Super Admin bypass is centralized — never duplicated).
- Use Permissions (`@can`/`@canany`/`@role` in UI; route/controller authorization for actions).
- Keep business logic in Services; wrap multi-write/workflow/ledger operations in `DB::transaction`.
- Keep database access in Repositories (branch-scoped, `int $branchId` first, `findInBranch`-style
  lookups, no unbounded `all()`).
- Add focused tests (happy path, validation failure, authorization, branch isolation, and ledger
  correctness for inventory), using existing Pest helpers and factories.

The AI must **never**:

- Put business logic in Controllers.
- Access Models directly from Controllers (or query domain data in controllers/views).
- Create mutable stock columns or update product stock directly.
- Trust a request-supplied `branch_id`.
- Bypass policies/permissions or invent a parallel auth/role system.
- Leak cross-branch data in selectors, lists, dashboards, reports, exports, or route-bound pages.
- Introduce a new framework, or implement future-sprint features by accident.

---

# UI Rules

When the change touches Blade/views, the AI must:

- Follow [`docs/ui_design_system.md`](ui_design_system.md) exactly.
- Render inside `<x-settings-shell>`; use the permission-aware sidebar; teal primary (not indigo);
  bordered `rounded-lg border border-gray-200 bg-white shadow-sm` cards.
- **Reuse existing dashboard components** before authoring new markup: `owner-dashboard.*`,
  `branch-dashboard.*`, `inventory.*`, plus the Breeze form primitives.
- Follow the Sprint 12 **Inventory UI conventions** (the `inventory/products/index` reference):
  dual desktop-table / mobile-card lists, `<th scope>`, `tabular-nums`, status row-tinting,
  ledger-derived labeling, the `_status-badge` / `_low-stock-badge` partials.
- **Support mobile layouts** — primary lists render the stacked mobile-card layout; dense tables
  scroll horizontally; controls stay reachable; sidebar is reachable on mobile.
- **Preserve accessibility** — associated `<label for>`, visible teal focus rings, `<th scope>`,
  keyboard operability, sufficient contrast, status never by color alone, empty states everywhere,
  no fabricated data, no `prompt()`/`alert()` for destructive flows (use `<x-modal>`).

---

# Inventory Rules

When the change touches inventory, the AI must:

- Follow [`docs/inventory_rules.md`](inventory_rules.md).
- **Use ledger-derived stock** — `current stock = SUM(quantity_in) - SUM(quantity_out)`; never read
  or write a stored stock value. `current_stock` is a query alias only.
- **Use `trx_inventory_movements` as the source of truth** — every quantity change is a new,
  append-only movement row (`branch_id`, `inventory_location_id`, `product_id`, `movement_type`,
  `movement_date`, `quantity_in`, `quantity_out`, optional `supplier_id`/`unit_cost`/`reference_*`/
  `created_by`), written inside a transaction.
- **Preserve location awareness** — every stock operation requires `inventory_location_id`;
  selectors show only active locations in the active branch; adjustment-out checks
  **location-specific** stock; stock card ordered `movement_date ASC, id ASC`.
- **Preserve branch awareness** — product/location/supplier branches must match the active branch;
  reject cross-branch combinations; low-stock/valuation/dashboards never include another branch.
- Respect the forbidden list: no mutable stock columns, no direct stock mutation, no
  transfer-by-manual-adjustment guidance, no FIFO/LIFO/costing engine, no stock increase from a PO.
- Stock opname stays snapshot + ledger-posting on finalize (variance → `ADJUSTMENT_IN/OUT`), never
  a stock source of truth.

---

# Sprint Consistency Check

Before coding, the AI must check whether the proposed change could violate any locked decision —
in particular:

- **Sprint 10 — Branch Context:** Does the change resolve the active branch centrally via
  `BranchContext`, or does it (wrongly) trust a submitted `branch_id` / read an unscoped record?
- **Sprint 11 — Branch Enforcement:** Are all branch-owned queries scoped by `branch_id`? Are
  route-bound models branch-validated by policy/service? Can another branch's record be viewed,
  mutated, or leaked into a list/dashboard/report?
- **Sprint 12 — Inventory Core:** Does the change keep stock ledger-derived and location-aware? Did
  it avoid adding a mutable stock column or mutating product stock? Does adjustment-out still reject
  insufficient location stock?

**If a violation is detected, the AI must STOP and explain the conflict** (which protected decision,
why the proposed approach breaks it, and a compliant alternative) instead of proceeding.

---

# Required Implementation Workflow

1. **Read documentation** — docs 1–5 in order, plus the target module/routes/tests.
2. **Summarize understanding** — business/architecture/branch/inventory/UI/history; confirm
   understanding explicitly.
3. **Identify affected files** — modules, services, repositories, policies, requests, views, tests.
4. **Create an implementation plan** — phased, scoped to the request; list new/changed files,
   transactions, permissions, and tests; run the Sprint Consistency Check; **wait for approval**.
5. **Implement in phases** — small, layered changes following
   `Controller → Request → Service → Repository → Model`; register bindings/policies; keep scope tight.
6. **Run tests** — `php artisan test`; add/adjust focused tests for behavior and branch isolation.
7. **Run quality gates** — the gates listed below that the user requested; report results honestly.
8. **Perform architecture review** — run the Pre-Commit Review Checklist; verify no forbidden
   dependency, no branch leakage, no mutable stock, no scope creep.
9. **Provide final summary** — files created/modified, behavior changed, test/gate results,
   assumptions, and residual risks.

---

# Required Quality Gates

Run the gates the user requested; report which were executed, which passed/failed, and why any were
skipped. **Never claim a gate passed without running it.**

```text
php artisan test
php artisan route:list
php artisan migrate:fresh --seed
npm.cmd run build
vendor/bin/pint
```

(On Windows/XAMPP, prepend `$env:Path = "C:\xampp\php;" + $env:Path` for PHP/artisan; use
`npm.cmd` and `.\vendor\bin\pint`.)

---

# Pre-Commit Review Checklist

The AI must verify before committing or proposing merge:

- **Architecture compliance** — thin controllers; validation in Form Requests; rules in Services;
  queries in Repositories; interfaces and policies registered; no business logic in controllers or
  Blade.
- **Branch compliance** — `BranchContext::requireId()` used; all branch-owned queries
  `where('branch_id', $branchId)`; route-bound models branch-validated; selectors limited to the
  active branch; another branch's ID cannot be submitted to mutate data.
- **Inventory compliance** — stock derived from the ledger; no mutable stock columns; every movement
  has branch/location/product; product/location/supplier branches validated; adjustment-out rejects
  insufficient location stock; stock card `movement_date ASC, id ASC`; opname stays snapshot/posting.
- **UI compliance** — design-system conventions followed; reused components; responsive (mobile +
  desktop); accessible; empty states present; permission-aware actions; no fabricated data.
- **Security compliance** — routes/actions authorized; all input validated; file uploads validated
  if applicable; denial paths tested; no cross-branch leakage.
- **Test coverage** — happy path, validation failure, authorization, branch isolation, and (for
  inventory) ledger correctness; tests use existing Pest helpers/factories; gates run and reported.

---

# Standard Startup Prompt

Copy-paste this block into **Claude Code, Solo Trae, Codex, Cursor, or GitHub Copilot Agent** at the
start of any ADLMS task. It must be run before implementation.

```text
You are working on ADLMS (Asia Dental Lab Management System): a live, multi-branch Laravel
modular monolith (Blade + Tailwind + Alpine + PostgreSQL + Pest + Spatie Permission). Do NOT use
React/Vue or any new framework. Treat it as production-adjacent: small, scoped, tested changes.

STEP 1 — READ (mandatory, in order, before anything else):
  1. docs/architecture_rules.md
  2. docs/ai_development_guide.md
  3. docs/inventory_rules.md
  4. docs/ui_design_system.md
  5. docs/sprint_history.md
Also read the target module under app/Modules, relevant routes, policies, and tests, and
app/Modules/Branch/Services/BranchContext.php for branch-owned work.

STEP 2 — SUMMARIZE FINDINGS:
Briefly summarize the business purpose, architecture (Controller -> Request -> Service ->
Repository -> Model), branch model (BranchContext; never trust request branch_id), inventory
model (stock = SUM(quantity_in) - SUM(quantity_out); no mutable stock columns; location-aware),
UI standards (settings-shell, teal, reuse dashboard components, dual responsive tables, badges,
empty states, accessibility), and where this task sits in the sprint history. Explicitly confirm
understanding.

STEP 3 — IDENTIFY RISKS:
List affected modules/services/repositories/policies/tests. Run the Sprint Consistency Check
against Sprint 10 (Branch Context), Sprint 11 (Branch Enforcement), and Sprint 12 (Inventory
Core). If the task could create mutable stock, trust request branch_id, leak cross-branch data,
bypass policies, or put business logic in controllers/Blade, STOP and explain the conflict.

STEP 4 — PRODUCE AN IMPLEMENTATION PLAN:
A phased plan scoped strictly to the request: new/changed files by layer, required transactions,
permissions/policies, UI components reused, and the tests you will add (happy path, validation,
authorization, branch isolation, ledger correctness). State assumptions and what is explicitly
out of scope.

STEP 5 — WAIT FOR APPROVAL:
Do NOT write code, schema, or tests until the plan is approved. After approval, implement in
phases, run the requested quality gates (php artisan test, route:list, migrate:fresh --seed,
npm.cmd run build, vendor/bin/pint), run the Pre-Commit Review Checklist, and deliver a final
summary of files changed, behavior changed, test/gate results, and residual risks.

ALWAYS: modular monolith; Controller -> Request -> Service -> Repository -> Model; BranchContext;
policies + permissions; business logic in Services; queries in Repositories; add tests.
NEVER: business logic in controllers/Blade; direct model access from controllers; mutable stock
columns; trust request branch_id; bypass policies; leak cross-branch data; scope creep into
future sprints.
```

---

*Documentation only — this bootstrap changes no application code. It defines the mandatory startup
procedure every AI agent must follow before implementing on ADLMS, and points to the five
authoritative documents that govern all work.*
