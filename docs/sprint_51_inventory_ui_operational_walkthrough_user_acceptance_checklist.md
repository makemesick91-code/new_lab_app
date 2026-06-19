# Sprint 51 — Inventory UI Operational Walkthrough & User Acceptance Checklist

## Status

Local Inventory UI walkthrough implementation / pending PR.

## Baseline

- **Base branch:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- **Baseline:** Sprint 50 GO / merge commit `6b5028b`
  (`sprint-50-inventory-bugfix-batch-1-workflow-stabilization-go`).
- **Feature branch:** `feature/sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist`
- **Feature tag:** `sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist`
- **Future GO tag (after PR merge only):**
  `sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist-go`

## Purpose

Sprint 48 completed the Inventory workflow audit and empty-database setup. Sprint 49 executed the local
smoke suite and a defect register (0 defects, 0 blockers). Sprint 50 closed workflow stabilization with no
runtime changes and no confirmed defects. Sprint 51 now verifies Inventory from the **user-facing UI and
operational acceptance perspective**: the Inventory menu, dashboard, master-data pages, stock-movement
entry points, stock card, current stock, low-stock display, branch-aware behavior, permission/access
behavior, inactive product/location behavior, and validation messages — closing with an operator/admin
**user acceptance checklist**.

**Sprint 51 is Inventory UI-focused.**
**Sprint 51 is local/test UI walkthrough and user acceptance checklist only.**

## Scope

- Review Sprint 48–50 Inventory findings and carry them forward.
- Inspect Inventory UI routes, views, controllers, requests, and policies (read-only).
- Document an Inventory UI operational walkthrough.
- Add an operator/admin user acceptance checklist.
- Add targeted local/test UI/HTTP regression coverage where safe.
- Record UI observations and follow-up candidates.
- Update sprint history.

## Non-goals / forbidden actions

Sprint 51 explicitly does **not** do any of the following:

- No production/VPS/server access.
- No production database/log/file access.
- No deployment.
- No production command execution.
- No production backup.
- No production restore.
- No rollback execution.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.
- No broad runtime behavior change.
- No direct stock mutation.
- No financial logic rewrite.
- No RME work.
- No Pilot Health Check governance continuation.
- No production data/evidence collection.
- No speculative UI redesign.

The following invariants remain in force and are not changed by this sprint:

- Inventory stock remains ledger-based.
- RME remains complete/closed for current planning.
- Pilot Health Check governance loop remains stopped.
- KTP / ktp_number remains hidden and is not part of Inventory workflow.
- WhatsApp remains manual-only and is not part of Inventory automation.
- Zero-remaining receivable rule remains preserved.
- Overpayment guard remains preserved.
- Financial rules are not rewritten.

## Sprint 48–50 carry-forward summary

- **Sprint 48** — Inventory workflow audit, empty-database setup, and smoke-test readiness.
- **Sprint 49** — Inventory workflow smoke test execution and defect register: **0 defects, 0 blockers**.
- **Sprint 50** — Inventory Bugfix Batch 1 & workflow stabilization: no runtime bug reproduced, no runtime
  code changed; ledger-only stock invariants, branch isolation, guards, and a representative access-control
  redirect validated; `INV-FU-001` concurrency item remains a deferred non-blocking FOLLOW-UP.

Sprint 51 builds on this stable baseline and adds **only** UI walkthrough documentation, an acceptance
checklist, and targeted UI/HTTP regression — no Inventory business logic is rewritten.

## Inventory UI module map

All Inventory UI routes live under the `inventory.` route name prefix / `/inventory` URL prefix, behind
the `auth` middleware; page-level authorization is enforced by policies and controller checks
(`view_inventory`, `manage_inventory`, and the master-data permission used by CRUD pages).

| Area | Route name | View |
| --- | --- | --- |
| Dashboard | `inventory.dashboard` | `resources/views/inventory/dashboard.blade.php` |
| Low-stock / alerts | `inventory.alerts.index` | `resources/views/inventory/alerts/index.blade.php` |
| Analytics | `inventory.analytics.index` | `resources/views/inventory/analytics/index.blade.php` |
| Current stock | `inventory.stock.index` | `resources/views/inventory/stock/` |
| Products | `inventory.products.index` / `create` / `show` / `edit` | `resources/views/inventory/products/` |
| Stock card | `inventory.products.stock-card` | `resources/views/inventory/` stock-card view |
| Opening stock | `inventory.products.opening-stock.create` / `store` | stock movement form |
| Stock receive | `inventory.products.receive-stock.create` / `store` | stock movement form |
| Adjustment in | `inventory.products.adjust-in.create` / `store` | stock movement form |
| Adjustment out | `inventory.products.adjust-out.create` / `store` | stock movement form |
| Locations | `inventory.locations.index` (+ CRUD) | `resources/views/inventory/locations/` |
| Product categories | `inventory.product-categories.index` (+ CUD) | `resources/views/inventory/product-categories/` |
| Product units | `inventory.product-units.index` (+ CUD) | `resources/views/inventory/product-units/` |
| Suppliers | `inventory.suppliers.index` (+ CRUD) | `resources/views/inventory/suppliers/` |
| Batches | `inventory.batches.index` / `show` | `resources/views/inventory/batches/` |
| Activity logs | `inventory.activity-logs.index` / `show` | `resources/views/inventory/activity-logs/` |
| Reports | `inventory.reports.index` / `export` | `resources/views/inventory/` reports views |

Confirmed UI headers used as acceptance anchors: dashboard `Dashboard Inventory`; products
`Produk Persediaan`; locations `Lokasi Persediaan`; product units `Satuan Produk`; product categories
`Kategori Produk`; suppliers `Pemasok Persediaan`; alerts/low-stock `Peringatan Persediaan`; stock card
`Kartu Stok`.

## UI walkthrough principle

Each walkthrough item is a **navigable, observable** check: a route a real operator/admin would open, the
expected on-screen content, and the expected guard/validation behavior. The walkthrough does not mutate
real data; representative checks run against the local/test database only through the existing
`InventoryStockService` ledger APIs and existing Inventory routes.

## Local/test UI boundary

The walkthrough and all automated checks run against the **local/test** environment only. No screenshots,
dumps, credentials, tokens, WhatsApp numbers, KTP, or patient identifiers are collected. No production
surface is touched.

## Dashboard walkthrough

- Inventory dashboard (`inventory.dashboard`) loads for an authenticated, authorized user.
- Dashboard shows the stock summary / KPI cards (`Kartu KPI Persediaan`, stock value, stock per location).
- Dashboard surfaces a low-stock indicator / alert entry point where implemented.
- Dashboard values align with movement-ledger expectations (ledger-derived, not a mutable column).
- Dashboard does not expose unrelated patient/RME data and does not expose KTP.

## Master data navigation walkthrough

The Inventory master-data pages are reachable from the Inventory menu and follow a consistent
index → create → edit (→ show where implemented) pattern. Each is branch-scoped.

## Product unit page checklist

- List page `inventory.product-units.index` opens (`Satuan Produk`) for an authorized user.
- Create page `inventory.product-units.create` opens.
- Edit page `inventory.product-units.edit` opens for an existing unit.
- Required field clarity (name/symbol) and validation feedback on empty submit.
- Branch context applied; cross-branch units are not listed/editable.

## Product category page checklist

- List page `inventory.product-categories.index` opens (`Kategori Produk`).
- Create / edit pages open for authorized users.
- Required field clarity and validation feedback.
- Active/inactive status visibility where implemented.
- Branch context applied.

## Supplier page checklist

- List page `inventory.suppliers.index` opens (`Pemasok Persediaan`).
- Create / edit / show pages open for authorized users.
- Active/inactive supplier status visible; inactive supplier rejected for receive-stock.
- Required field clarity and validation feedback.
- Branch context applied.

## Inventory location page checklist

- List page `inventory.locations.index` opens (`Lokasi Persediaan`).
- Create / edit / show pages open for authorized users.
- Active/inactive location status visible; inactive location rejected for stock movement.
- Required field clarity and validation feedback.
- Branch context applied.

## Product / item page checklist

- List page `inventory.products.index` opens (`Produk Persediaan`, `Status Stok`, current-stock columns).
- Create / edit / show pages open for authorized users.
- Show page exposes stock-movement entry points and the stock card link.
- Active/inactive product status visible; inactive product rejected for stock movement.
- Required field clarity and validation feedback.
- Cross-branch product access is forbidden.

## Stock movement entry point checklist

From a product show page an operator can reach the four ledger entry points:

- Opening stock (`inventory.products.opening-stock.create`).
- Stock receive (`inventory.products.receive-stock.create`).
- Adjustment in (`inventory.products.adjust-in.create`).
- Adjustment out (`inventory.products.adjust-out.create`).

Each entry point posts through `InventoryStockService` and writes a ledger movement row; none mutate a
stock column directly.

## Opening stock UI readiness

- Opening-stock form requires location and a positive quantity.
- Submitting opening stock redirects to the stock card and writes a `TYPE_OPENING` ledger row.
- Cross-branch location is rejected with a validation error.

## Stock receive UI readiness

- Receive form requires location, positive quantity, and (optionally) a supplier and unit cost.
- A supplier from another branch is rejected (`supplier_id` validation error).
- Successful receive writes a `TYPE_PURCHASE` ledger row.

## Adjustment in UI readiness

- Adjustment-in form requires location and a positive quantity.
- Successful adjustment writes a `TYPE_ADJUSTMENT_IN` ledger row.
- Zero/negative quantity is rejected.

## Adjustment out UI readiness

- Adjustment-out form requires location and a positive quantity.
- Insufficient stock is rejected and leaves stock unchanged.
- Successful adjustment writes a `TYPE_ADJUSTMENT_OUT` ledger row.

## Current stock UI checks

- Current stock is displayed per product/location/branch on the products index and stock pages.
- Current stock equals the ledger sum (`stock in − stock out`); there is no mutable stock column.
- Branch A current stock is unaffected by Branch B movements.

## Stock card / movement trail UI checks

- Stock card (`inventory.products.stock-card`, `Kartu Stok`) renders the movement history for a product.
- The movement trail is shown in workflow order
  (`TYPE_OPENING` → `TYPE_PURCHASE` → `TYPE_ADJUSTMENT_IN` → `TYPE_ADJUSTMENT_OUT`).
- The stock card explains the running balance and is understandable for audit.

## Low-stock UI checks

- The low-stock / alerts page (`inventory.alerts.index`, `Peringatan Persediaan`) loads for an authorized
  user.
- Low-stock state appears when current stock is at or below the configured minimum.
- The low-stock view supports a restock decision (product, location, current vs minimum).

## Branch-aware UI checks

- Master-data lists and stock pages are scoped to the active branch (`branch_id` / `BranchContext`).
- Cross-branch product/location/supplier access from the UI is forbidden or rejected.
- Branch context is visible/consistent across Inventory pages.

## Permission/access-control UI checks

- Unauthenticated user is redirected to login from Inventory routes (`inventory.dashboard`,
  `inventory.stock.index`, `inventory.products.index`).
- A user with `view_inventory` can open the allowed read pages.
- A user with the management permission can open management actions (master data, stock movement).
- Unauthorized management actions are blocked (cross-branch access is forbidden); permission-name gating
  remains covered by existing `InventoryPermissionHardeningTest` / `InventoryRouteAuthorizationTest`.

## Inactive product/location UI checks

- An inactive product is rejected for opening stock / receive / adjustment.
- An inactive location is rejected for stock movement.
- An inactive supplier is rejected for receive-stock.
- These guards are enforced server-side via `InventoryStockService` and surfaced as validation errors.

## Validation and error-message checks

- Empty/invalid master-data submissions return field validation errors.
- Zero/negative stock-movement quantity is rejected.
- Insufficient-stock adjustment-out is rejected and stock is unchanged.
- Cross-branch references (location/supplier) are rejected with field errors.
- Error feedback is understandable and does not leak unrelated data.

## User acceptance checklist

Acceptance statuses: `PASS`, `OBSERVATION`, `FAIL`, `BLOCKER`, `FOLLOW-UP`.

| # | Acceptance area | Status |
| --- | --- | --- |
| 1 | Inventory menu accessible | PASS |
| 2 | Dashboard understandable | PASS |
| 3 | Product units manageable | PASS |
| 4 | Product categories manageable | PASS |
| 5 | Suppliers manageable | PASS |
| 6 | Inventory locations manageable | PASS |
| 7 | Products/items manageable | PASS |
| 8 | Opening stock process understandable | PASS |
| 9 | Stock receive process understandable | PASS |
| 10 | Adjustment in/out process understandable | PASS |
| 11 | Current stock visible and understandable | PASS |
| 12 | Stock card useful for audit trail | PASS |
| 13 | Low stock useful for restock planning | PASS |
| 14 | Branch context clear | PASS |
| 15 | Permission behavior correct | PASS |
| 16 | Inactive product/location behavior correct | PASS |
| 17 | Validation messages understandable | PASS |
| 18 | Operator can complete daily workflow | PASS |
| 19 | Admin can review stock condition | PASS |
| 20 | No direct stock mutation required | PASS |
| 21 | No unrelated patient/KTP/WA data appears | PASS |

## UI observation/follow-up register classification

- **PASS** — UI behavior acceptable.
- **OBSERVATION** — UI works but could be clearer.
- **FAIL** — UI behavior fails expected workflow.
- **BLOCKER** — UI issue prevents safe Inventory operation.
- **FOLLOW-UP** — enhancement or UX polish for a future sprint.
- **DEFERRED** — larger improvement requiring a separate sprint.

Severity values: `Critical`, `High`, `Medium`, `Low`, `Info`.
Status values: `Open`, `Triaged`, `Fixed`, `Deferred`, `Closed`, `Needs UX Sprint`,
`Needs Implementation Sprint`.

## UI observation/follow-up register table

| ID | Area | Scenario | Expected UI Behavior | Actual / Observed | Classification | Severity | Evidence | Status | Recommended Follow-up |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| INV-UI-001 | Dashboard | Open Inventory dashboard as authorized user | Dashboard + KPI/summary render; no patient/KTP data | Matches expectation | PASS | `Sprint51` dashboard test | Closed | None |
| INV-UI-002 | Master data | Open product units / categories / suppliers / locations index | Branch-scoped index pages render with correct headers | Matches expectation | PASS | `Sprint51` master-data test | Closed | None |
| INV-UI-003 | Products | Open products index and product stock card | Products list + `Kartu Stok` movement trail render | Matches expectation | PASS | `Sprint51` products/stock-card test | Closed | None |
| INV-UI-004 | Stock movement | Submit opening stock via UI | Redirect to stock card + ledger `TYPE_OPENING` row | Matches expectation | PASS | `Sprint51` opening-stock test | Closed | None |
| INV-UI-005 | Low stock | Open low-stock/alerts page | `Peringatan Persediaan` page renders | Matches expectation | PASS | `Sprint51` low-stock test | Closed | None |
| INV-UI-006 | Access control | Unauthenticated Inventory request | Redirect to login | Matches expectation | PASS | `Sprint51` auth-redirect test | Closed | None |
| INV-UI-007 | Branch isolation | Open cross-branch product from UI | Forbidden | Matches expectation | PASS | `Sprint51` branch-isolation test | Closed | None |
| INV-FU-001 | Stock concurrency | Concurrent adjustment-out under race | Negative stock prevented under concurrency | Carried forward from Sprint 50 (not reproduced in UI) | FOLLOW-UP | Sprint 50 register | Deferred | Needs Implementation Sprint |
| INV-UI-FU-002 | Stock movement UX | Stock movement entry points discoverable from product show | Clear inline guidance for opening vs receive vs adjustment | Works; could add inline helper copy | FOLLOW-UP | UI review | Open | Needs UX Sprint |

## Walkthrough result summary

- PASS: 7 (INV-UI-001..007)
- OBSERVATION: 0
- FAIL: 0
- BLOCKER: 0
- FOLLOW-UP: 2 (`INV-FU-001` carried forward, `INV-UI-FU-002` new UX polish)
- DEFERRED: `INV-FU-001` deferred to a separate implementation sprint

No FAIL and no BLOCKER were found. No runtime UI code was changed; Sprint 51 is documentation +
acceptance checklist + targeted UI/HTTP regression only.

## Follow-up recommendation

- Keep `INV-FU-001` (negative-stock concurrency) deferred to a dedicated concurrency/locking implementation
  sprint; do not patch speculatively.
- Consider `INV-UI-FU-002` (stock-movement entry-point helper copy) for a future UX-polish sprint.

## Safety confirmation

Sprint 51 is Inventory UI-focused and local/test UI walkthrough and user acceptance checklist only.
No production/VPS/server access. No production database/log/file access. No deployment. No production
command execution. No production backup. No production restore. No rollback execution. No `.env` change.
No dependency/package install. No migration/schema change. No broad runtime behavior change. No direct
stock mutation. Inventory stock remains ledger-based. RME remains complete/closed for current planning.
Pilot Health Check governance loop remains stopped. KTP / ktp_number remains hidden and is not part of
Inventory workflow. WhatsApp remains manual-only and is not part of Inventory automation. Zero-remaining
receivable rule remains preserved. Overpayment guard remains preserved. Financial rules are not rewritten.

## Validation commands

```bash
php artisan test --filter=Sprint51InventoryUiOperationalWalkthroughUserAcceptanceChecklist
php artisan test tests/Feature/Inventory/InventoryUiTest.php
vendor/bin/pint --test
git diff --check
git status --short
```

## AI agent memory summary

- Sprint 51 verifies Inventory from the user-facing UI and operational acceptance perspective.
- Baseline: Sprint 50 GO / merge commit `6b5028b`.
- Branch: `feature/sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist`.
- Feature tag: `sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist`
  (future GO tag `sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist-go` after PR
  merge only).
- Deliverables: this doc, sprint-history entry,
  `Sprint51InventoryUiOperationalWalkthroughUserAcceptanceChecklistTest`.
- No runtime UI bug reproduced; no runtime code changed. Inventory stock remains ledger-based; no direct
  stock mutation. Branch isolation, inactive guards, positive-quantity and insufficient-stock guards
  preserved. RME closed; Pilot Health Check governance loop stopped; KTP hidden; WhatsApp manual-only;
  zero-remaining receivable and overpayment guards preserved.
