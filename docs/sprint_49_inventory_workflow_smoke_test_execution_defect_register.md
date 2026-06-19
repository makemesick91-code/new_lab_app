# Sprint 49 — Inventory Workflow Smoke Test Execution & Defect Register

## 1. Title

**Sprint 49 — Inventory Workflow Smoke Test Execution & Defect Register**

- Branch: `feature/sprint-49-inventory-workflow-smoke-test-execution-defect-register`
- Feature tag: `sprint-49-inventory-workflow-smoke-test-execution-defect-register`
- Future GO tag (after PR merge only): `sprint-49-inventory-workflow-smoke-test-execution-defect-register-go`
- Base branch: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
- Baseline: Sprint 48 GO / merge commit `7e8fadb`
  (`sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness-go`)

## 2. Status

Local Inventory smoke execution implementation / pending PR.

This sprint is local/test smoke execution + documentation + defect register + checklist regression test only.

## 3. Baseline

Sprint 49 builds on the Sprint 48 GO baseline:

- Merge commit `7e8fadb` — "Merge pull request #44 ... sprint-48 inventory workflow audit ...".
- GO tag at baseline HEAD: `sprint-48-inventory-workflow-audit-empty-database-setup-smoke-test-readiness-go`.
- Sprint 48 audit doc: `docs/sprint_48_inventory_workflow_audit_empty_database_setup_smoke_test_readiness.md`.

Sprint 48 documented the Inventory workflow from empty-database setup through smoke-test readiness.
Sprint 49 now **executes** that smoke workflow safely in the local/test environment and records the
result in a defect register. No Inventory runtime logic is rewritten.

## 4. Purpose

Prove, through safe automated local/test smoke tests and documentation, that the Inventory module
supports the basic operational flow:

```text
Empty/fresh test setup
→ branch/user/permission prerequisites
→ inventory location
→ product unit
→ product category
→ supplier
→ product/item
→ opening stock
→ stock receive
→ stock usage/out
→ adjustment in
→ adjustment out
→ current stock validation
→ stock card validation
→ low stock validation
→ branch isolation validation
→ inactive product/location guard validation
→ defect register
```

## 5. Scope

- Inspect the current Inventory service/test architecture at the Sprint 48 baseline.
- Add Sprint 49 smoke execution documentation (this file).
- Add a defect register for Inventory workflow smoke outcomes.
- Add automated local/test Sprint 49 smoke coverage using the existing `InventoryStockService` and
  existing model factories.
- Validate ledger-based stock movement from opening stock through receive / usage / adjustment.
- Validate positive-quantity, insufficient-stock, inactive product/location, branch isolation, stock
  card / movement trail, current stock, and low-stock behavior where existing APIs support them.
- Update sprint history.
- Run targeted safe tests, Pint, and whitespace checks.

## 6. Non-goals / forbidden actions

Sprint 49 explicitly does **not**:

- No deployment.
- No VPS/production/server access.
- No production database/log/file access.
- No real backup/restore/rollback.
- No `.env` change.
- No dependency/package install.
- No migration/schema change.
- No runtime behavior change.
- No direct stock mutation.
- No financial logic rewrite.
- No RME work.
- No Pilot Health Check governance continuation.
- No production data/evidence collection.
- No bugfix implementation unless separately approved (runtime bugs are logged as defect candidates for
  a future implementation sprint instead).

## 7. Sprint 48 carry-forward summary

From Sprint 48, confirmed at baseline and re-used here:

- Core ledger service: `App\Modules\Inventory\Services\InventoryStockService`.
- Ledger model: `App\Modules\Inventory\Models\InventoryMovement` with movement types `OPENING`,
  `PURCHASE`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `TRANSFER_IN`, `TRANSFER_OUT`.
- Current stock is ledger-derived: `current stock = stock in - stock out`. No mutable stock column on
  `inv_products`, `inv_inventory_locations`, or `inv_inventory_batches`.
- Permissions: `view_inventory` / `manage_inventory` plus granular analytics/batch/cross-branch grants.
- Master data setup order: branches → users/roles/permissions → inventory locations → product units →
  product categories → suppliers → products → opening stock → stock movements.
- Guards already implemented: positive-quantity guard, insufficient-stock guard, inactive
  product/location/supplier guards, inactive/expired batch guards, and branch isolation via
  `BranchContext::requireId()`.

## 8. Inventory smoke execution principle

Smoke execution proves the **happy path plus the most important guards** of the Inventory ledger using
existing service APIs and factories — not exhaustive coverage. Every stock change is created **only**
through a stock movement / ledger entry (`InventoryStockService` → `InventoryMovement`). Current stock,
stock card, low stock, and inventory value are all **derived** from the ledger, never from a stored
mutable quantity. Inventory stock must be changed only through stock movements / ledger entries.

## 9. Local/test environment boundary

Sprint 49 executes smoke coverage only in the local/test environment using `RefreshDatabase` and
seeded/factory data. The following are explicitly true for this sprint:

```text
Sprint 49 is Inventory-focused.
Sprint 49 executes smoke coverage only in local/test environment.
No production/VPS/server access.
No production database/log/file access.
No deployment.
No production command execution.
No production backup.
No production restore.
No rollback execution.
No `.env` change.
No dependency/package install.
No migration/schema change.
No runtime behavior change.
No direct stock mutation.
Inventory stock must be changed only through stock movements / ledger entries.
RME remains complete/closed for current planning.
Pilot Health Check governance loop remains stopped.
KTP / ktp_number remains hidden and is not part of Inventory workflow.
WhatsApp remains manual-only and is not part of Inventory automation.
Zero-remaining receivable rule remains preserved.
Overpayment guard remains preserved.
Financial rules are not rewritten.
```

## 10. Smoke execution data setup sequence

```text
1. Create or resolve branch context in test environment.
2. Create Inventory-capable user/role/permission if the current tests require it.
3. Create inventory location for branch.
4. Create product unit.
5. Create product category.
6. Create supplier.
7. Create product/item with minimum stock.
8. Record opening stock through Inventory stock movement/service.
9. Record stock receive through Inventory stock movement/service.
10. Record stock out/usage or adjustment out through current service API.
11. Record adjustment in through current service API.
12. Validate current stock from ledger.
13. Validate movement trail / stock card.
14. Validate low-stock threshold.
15. Validate insufficient stock rejection.
16. Validate zero/negative quantity rejection.
17. Validate inactive product/location rejection.
18. Validate branch isolation.
19. Capture observations and defect candidates.
```

In the local/test environment the branch context is resolved by seeding `BranchSeeder` and using the
main branch (`Branch::MAIN_CODE`), matching the existing `InventoryStockServiceTest` pattern.

## 11. Master data smoke setup

The Sprint 49 smoke test builds master data with existing factories:

- `Branch` (main branch via `BranchSeeder`; a second branch via `Branch::factory()` for isolation).
- `ProductUnit`, `ProductCategory`, `Supplier`, `InventoryLocation`, and `Product` via their factories.
- `Product::factory()` carries `minimum_stock` and `average_cost`; `inactive()` state for guard checks.
- `InventoryLocation::factory()->inactive()` for the inactive-location guard.

No production master data is referenced. KTP / `ktp_number` is irrelevant to Inventory and not touched.

## 12. Opening stock smoke execution

`InventoryStockService::createOpeningStock()` records a `TYPE_OPENING` inbound movement. The smoke test
asserts the resulting current stock increases by the opening quantity and that the movement is a ledger
entry (no direct column mutation). Opening stock is the first `OPENING` movement after physical count.

## 13. Stock receive smoke execution

`InventoryStockService::receiveStock()` records a `TYPE_PURCHASE` inbound movement (optionally with a
supplier and batch metadata). The smoke test asserts current stock increases and the movement is typed
`PURCHASE` with the active branch and selected location.

## 14. Stock usage / stock out smoke execution

Stock out / usage is recorded through `InventoryStockService::adjustOut()`, which writes a
`TYPE_ADJUSTMENT_OUT` movement and decreases ledger-derived current stock. The smoke test asserts the
decrease and confirms the out movement appears in the stock card.

## 15. Adjustment in smoke execution

`InventoryStockService::adjustIn()` records a `TYPE_ADJUSTMENT_IN` inbound movement. The smoke test
asserts current stock increases by the adjustment quantity.

## 16. Adjustment out smoke execution

`InventoryStockService::adjustOut()` records a `TYPE_ADJUSTMENT_OUT` movement and decreases current
stock. The smoke test asserts the decrease and that attempting to remove more than available is rejected
(insufficient-stock guard).

## 17. Current stock validation

The smoke test asserts `current stock = stock in - stock out` by summing ledger movements directly and
comparing to `InventoryStockService::getCurrentStock()`, mirroring the existing
`InventoryStockServiceTest` "derives ... from movements only" pattern.

## 18. Stock card / movement trail validation

`InventoryStockService::getStockCard()` returns the ordered movement trail. The smoke test asserts the
expected sequence of movement types (`OPENING`, `PURCHASE`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`) appears in
the stock card for the product/location.

## 19. Low-stock validation

`InventoryStockService::getLowStockProducts()` lists products whose ledger-derived stock is at or below
`minimum_stock`. The smoke test asserts a product opened below its minimum appears in the low-stock list
and a healthy product does not.

## 20. Branch isolation validation

Branch isolation is enforced by `BranchContext::requireId()` plus per-branch lookups. The smoke test
asserts that a product/location/supplier belonging to another branch is rejected when used in the active
branch context, mirroring the existing branch-isolation assertions.

## 21. Permission and access-control validation

Inventory access is gated by `view_inventory` / `manage_inventory` and the Inventory policies
(`ProductPolicy`, `InventoryLocationPolicy`, `InventoryMovementPolicy`, `SupplierPolicy`, etc.).
Permission/route authorization is already covered by existing suites
(`InventoryPermissionHardeningTest`, `InventoryRouteAuthorizationTest`). Sprint 49 reviews this and
treats it as already covered; the service-level smoke test focuses on ledger behavior and guards.

## 22. Inactive product/location guard validation

`InventoryStockService` rejects movements for inactive products
(`lockAndAssertProductInBranch` → `is_active`) and inactive locations
(`lockAndAssertLocationInBranch` → `is_active`). The smoke test asserts both rejections via
`Product::factory()->inactive()` and `InventoryLocation::factory()->inactive()`.

## 23. Zero/negative quantity guard validation

`InventoryStockService::assertPositiveQuantity()` rejects quantities `<= 0`. The smoke test asserts both
zero and negative quantities throw `ValidationException` for inbound and outbound movements.

## 24. Insufficient stock guard validation

`adjustOut()` rejects an out movement above available location stock with a `ValidationException`. The
smoke test asserts the rejection and that stock is unchanged after a rejected attempt.

## 25. Defect register classification

```text
PASS — expected behavior confirmed.
OBSERVATION — behavior works but needs UX/docs/manual clarification.
DEFECT — behavior fails expected rule but has workaround or limited impact.
BLOCKER — behavior prevents safe Inventory operation.
FOLLOW-UP — not a defect now, but should be handled in future sprint.
```

Severity scale: `Critical`, `High`, `Medium`, `Low`, `Info`.

Status values: `Open`, `Triaged`, `Deferred`, `Closed`, `Needs Implementation Sprint`.

## 26. Defect register table

| ID | Area | Scenario | Expected | Actual | Classification | Severity | Evidence | Status | Recommended Follow-up |
|----|------|----------|----------|--------|----------------|----------|----------|--------|-----------------------|
| INV-SMOKE-001 | Opening stock ledger | Opening stock creates stock-in movement | Stock increases through ledger (`TYPE_OPENING`) | Stock increases through ledger | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-002 | Stock receive | Receive/purchase increases current stock | `TYPE_PURCHASE` inbound, stock increases | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-003 | Adjustment in | Adjustment in increases current stock | `TYPE_ADJUSTMENT_IN` inbound, stock increases | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-004 | Adjustment out / stock out | Adjustment out decreases current stock | `TYPE_ADJUSTMENT_OUT`, stock decreases | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-005 | Insufficient stock guard | Out movement above available stock is rejected | `ValidationException`, stock unchanged | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-006 | Zero/negative quantity guard | Zero/negative quantity is rejected | `ValidationException` | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-007 | Inactive product guard | Inactive product rejected for new movement | `ValidationException` | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-008 | Inactive location guard | Inactive location rejected for new movement | `ValidationException` | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-009 | Current stock calculation | Current stock equals stock-in minus stock-out | Ledger sum equals service result | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-010 | Stock card / movement trail | Stock card lists movements in order | Ordered `OPENING`/`PURCHASE`/`ADJUSTMENT_IN`/`ADJUSTMENT_OUT` | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-011 | Low stock | Product at/below minimum stock listed as low | Listed when stock ≤ minimum, healthy excluded | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-012 | Branch isolation | Branch A records rejected in Branch B context | `ValidationException`, no cross-branch leak | As expected | PASS | Sprint49 targeted test | Closed | None |
| INV-SMOKE-013 | Ledger-only stock | No mutable stock column on product/location/batch | No `current_stock`/`quantity_on_hand`/`stock` columns | As expected | PASS | Sprint49 targeted test + Sprint48 audit | Closed | None |
| INV-OBS-001 | Permission/access-control | Inventory routes/policies gate `view_inventory`/`manage_inventory` | Gated, already covered by existing suites | Confirmed via `InventoryPermissionHardeningTest` / `InventoryRouteAuthorizationTest` | OBSERVATION | Info | Existing Inventory suite | Closed | Keep covered by existing suites |
| INV-FU-001 | Negative-stock hardening | Confirm no path can drive ledger below zero across concurrent out movements | Locks prevent oversell | Guarded by row locks + insufficient-stock guard; concurrency not load-tested here | FOLLOW-UP | Low | `InventoryStockService` review | Open | Consider concurrency/load test in a future stabilization sprint |

## 27. Smoke execution result summary

- **PASS:** 13 — INV-SMOKE-001 … INV-SMOKE-013 (opening stock, receive, adjustment in/out, insufficient
  stock guard, zero/negative quantity guard, inactive product/location guards, current stock, stock card,
  low stock, branch isolation, ledger-only stock).
- **OBSERVATION:** 1 — INV-OBS-001 (permission/access-control already covered by existing suites).
- **DEFECT:** 0.
- **BLOCKER:** 0.
- **FOLLOW-UP:** 1 — INV-FU-001 (negative-stock concurrency hardening; not a confirmed defect).

No confirmed defect or blocker was found during local/test smoke execution. The basic Inventory
operational flow is exercised end-to-end through the ledger.

## 28. Follow-up recommendation

- No bugfix sprint is required by Sprint 49 findings.
- INV-FU-001 (concurrency/load hardening for negative stock) is a non-blocking watch item; consider it in
  a future stabilization sprint only if a real concurrency concern is reported.
- A future implementation sprint (e.g. `Sprint 50 — Inventory Bugfix Batch / Workflow Stabilization`)
  should be opened **only if** a confirmed `DEFECT`/`BLOCKER` is later found; this sprint records none.

## 29. Safety confirmation

- Local/test environment only; no production/VPS/server access.
- No production database/log/file access; no production command execution.
- No backup/restore/rollback; no deployment.
- No `.env`, dependency, migration, schema, or runtime behavior change.
- No direct stock mutation — Inventory stock remains ledger-based (stock movements only).
- Branch isolation preserved; inactive product/location/supplier guards preserved; positive-quantity and
  insufficient-stock guards preserved.
- KTP / `ktp_number` remains hidden and irrelevant to Inventory.
- WhatsApp remains manual-only; not part of Inventory automation.
- Zero-remaining receivable rule preserved; overpayment guard preserved; financial rules not rewritten.
- RME remains complete/closed for current planning; Pilot Health Check governance loop remains stopped.
- No production data/evidence (secrets, logs, dumps, credentials, tokens, WA numbers, KTP, patient
  identifiers) collected or archived.

## 30. Validation commands

```bash
php artisan test --filter=Sprint49InventoryWorkflowSmokeTestExecutionDefectRegister
php artisan test tests/Feature/Inventory
vendor/bin/pint --test
git diff --check
git status --short
```

## 31. AI agent memory summary

```text
Sprint 49 — Inventory Workflow Smoke Test Execution & Defect Register.
Branch: feature/sprint-49-inventory-workflow-smoke-test-execution-defect-register.
Feature tag: sprint-49-inventory-workflow-smoke-test-execution-defect-register.
Future GO tag (after PR merge only): sprint-49-inventory-workflow-smoke-test-execution-defect-register-go.
Base branch: feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report.
Baseline: Sprint 48 GO / merge commit 7e8fadb.
Local/test smoke execution + documentation + defect register + checklist regression only.
Smoke executed through InventoryStockService against the ledger (InventoryMovement):
opening stock, receive, adjustment in/out, current stock, stock card, low stock, branch isolation,
inactive product/location guards, zero/negative quantity guard, insufficient stock guard,
ledger-only (no mutable stock column).
Result: 13 PASS, 1 OBSERVATION, 0 DEFECT, 0 BLOCKER, 1 FOLLOW-UP.
No deployment / VPS / production / server / database / log / file access.
No .env / dependency / migration / schema / runtime change. No direct stock mutation.
Inventory stock remains ledger-based. KTP hidden. WhatsApp manual-only.
Zero-remaining receivable rule preserved. Overpayment guard preserved. Financial rules not rewritten.
RME complete/closed. Pilot Health Check governance loop stopped.
Next: ask user whether to push branch + feature tag and open PR. GO tag only after PR merge.
```
