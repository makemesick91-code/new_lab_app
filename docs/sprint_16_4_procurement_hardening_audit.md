# Sprint 16.4 — Procurement Hardening Audit

Date: 2026-06-06  
Branch audited: `feature/sprint-16-procurement`  
Baseline: Sprint 16.3 Goods Receipt complete (`4de1a40`)  
Scope: Purchase Request → Purchase Order → Goods Receipt → Inventory ledger integration  
Out of scope: HR, Attendance, Payroll, Supplier Invoice/AP (future), Production Material Usage (Sprint 17)

---

## Executive Summary

Sprint 16.1–16.3 procurement workflow is **functionally complete and architecturally compliant** with ADLMS modular monolith, branch isolation, and ledger-only inventory rules. Purchase Order and Purchase Request remain **intent/commitment documents with zero stock impact**. Goods Receipt posting is the **sole procurement path that writes `PURCHASE` movements** to `trx_inventory_movements`.

Sprint 16.4 should focus on **hardening, closure, and operator UX gaps** — not re-architecting. No mutable stock columns were found. Branch isolation is enforced in services, policies, and request validators, with dedicated GR branch tests. Primary gaps: batch/lot on GR receive, PO receiving visibility on PO detail, submitted-GR cancel policy, dedicated PR/PO branch isolation test files, and concurrency/reversal edge cases.

**Quality gates at audit time:**

| Gate | Result |
|---|---|
| `git diff --check` | **PASS** (no conflict markers / whitespace errors on tracked diff) |
| `php artisan test --filter=PurchaseOrder` | **PASS** — 140 tests, 485 assertions |
| `php artisan test --filter=GoodsReceipt` | **PASS** — 121 tests, 462 assertions |

---

## Current State

### Milestone delivery (Sprint 16.1 → 16.3)

| Slice | Status | Stock impact |
|---|---|---|
| 16.1 Purchase Request | Complete | None |
| 16.2 Purchase Order | Complete | None |
| 16.3 Goods Receipt | Complete | `PURCHASE` ledger on post |

### Database artifacts

| Table | Purpose |
|---|---|
| `trx_purchase_requests` / `_items` | Branch-scoped purchase intent |
| `trx_purchase_orders` / `_items` | Branch-scoped supplier commitment |
| `trx_purchase_order_items.quantity_received` | **Fulfillment cache** (accepted qty from posted GRs only — not stock) |
| `trx_goods_receipts` / `_items` | Receiving document + cost/movement linkage |

No forbidden columns (`current_stock`, `qty_on_hand`, etc.) on procurement or product/location tables.

### Permissions (existing — no new Spatie permissions in 16.3)

| Permission | Usage |
|---|---|
| `view_inventory` | List/show PR, PO, GR |
| `manage_inventory` | CRUD + workflow mutations (except PR/PO approve) |
| `approve_inventory_purchase_request` | PR approve/reject |
| `approve_inventory_purchase_order` | PO approve |

Legacy `manage master data` also grants PR/PO approval (consistent with S16.1/16.2).

### Test coverage snapshot

| Area | Test files | Approx. tests |
|---|---|---|
| Purchase Request | 7 | 45 |
| Purchase Order | 7 | 140 (filter) |
| Goods Receipt | 10 | 121 |
| Procurement E2E | 1 | 1 (PR → PO → GR → ledger) |

Full suite at 16.3 completion: **950 tests** (per `docs/sprint_16_3_goods_receipt_completion_summary.md`).

---

## Files Reviewed

### Mandatory reads

| File | Notes |
|---|---|
| `AGENTS.md` | Modular monolith, ledger, branch rules |
| `docs/project_rules.md` | Layering, module structure |
| `docs/inventory_rules.md` | Ledger-first, forbidden mutable stock |
| `docs/sprint_history.md` | Sprint 16 procurement placement |
| `.cursor/memory/sprint-roadmap-13-20.md` | Sprint 16 scope |
| `docs/sprint_16_3_goods_receipt_completion_summary.md` | 16.3 completion baseline |
| `docs/sprint_16_3_goods_receipt_technical_design.md` | Design vs implementation deltas |

**Note:** `.cursor/snippets/adlms_master_workflow.md` was not found at the requested path during this audit.

### Services (workflow core)

| File | Role |
|---|---|
| `app/Modules/Inventory/Services/PurchaseRequestService.php` | PR draft → submit → approve/reject/cancel |
| `app/Modules/Inventory/Services/PurchaseOrderService.php` | PO draft → submit → approve → send/cancel; PR linkage |
| `app/Modules/Inventory/Services/GoodsReceiptService.php` | GR draft → submit → post/cancel; PO cache + ledger orchestration |
| `app/Modules/Inventory/Services/InventoryStockService.php` | `receiveStock()` → `PURCHASE` movement with optional refs |

### Repositories

| File | Role |
|---|---|
| `PurchaseRequestRepository.php` | Branch-scoped CRUD |
| `PurchaseOrderRepository.php` | Branch-scoped CRUD; `incrementItemQuantityReceived()` |
| `GoodsReceiptRepository.php` | Branch-scoped CRUD; PO receivable queries; posting locks |

### Models

| File | Role |
|---|---|
| `PurchaseRequest.php` / `PurchaseRequestItem.php` | PR document |
| `PurchaseOrder.php` / `PurchaseOrderItem.php` | PO document + `quantity_received` cache |
| `GoodsReceipt.php` / `GoodsReceiptItem.php` | GR document + movement FK |
| `InventoryMovement.php` | Ledger (`reference_type`, `reference_id`) |

### Policies

| File | Role |
|---|---|
| `PurchaseRequestPolicy.php` | View/manage + separate approve permission |
| `PurchaseOrderPolicy.php` | Workflow + `receive` ability for GR entry |
| `GoodsReceiptPolicy.php` | Draft-only edit/cancel; draft/submitted post |
| `Policies/Concerns/ChecksInventoryAccess.php` | Shared branch + permission helpers |

### Controllers & routes

| File | Routes |
|---|---|
| `PurchaseRequestController.php` | 6 workflow routes + resource |
| `PurchaseOrderController.php` | 5 workflow routes + resource |
| `GoodsReceiptController.php` | 3 workflow routes + resource |
| `routes/web.php` (inventory group) | All under `inventory.*` prefix |

### Form requests & validation concerns

| File | Role |
|---|---|
| `Store/Update*Request.php` (PR, PO, GR) | Input rules |
| `ValidatesPurchaseRequestInput.php` | PR item/branch validation |
| `ValidatesPurchaseOrderInput.php` | PO item/supplier/PR linkage |
| `ValidatesGoodsReceiptInput.php` | GR qty semantics, excluded fields, post guards |
| `Post/Submit/Cancel*Request.php` | Workflow mutation guards |

### Migrations & factories

| Migration | Purpose |
|---|---|
| `2026_06_07_100000` – `100003` | PR + PO tables |
| `2026_06_07_100004` – `100005` | GR tables |
| `2026_06_07_100006` | `quantity_received` on PO items |
| `database/factories/*Purchase*.php`, `*GoodsReceipt*.php` | Test fixtures |

### UI

| Path | Purpose |
|---|---|
| `resources/views/inventory/purchase-requests/*` | PR CRUD + workflow |
| `resources/views/inventory/purchase-orders/*` | PO CRUD + workflow + Terima Barang |
| `resources/views/inventory/goods-receipts/*` | GR CRUD + post modal |
| `resources/views/layouts/sidebar.blade.php` | Persediaan → PR, PO, Penerimaan Barang |

### Provider wiring

| File | Bindings |
|---|---|
| `app/Providers/RepositoryServiceProvider.php` | Repo interfaces, policy registration |

### Tests (procurement)

All under `tests/Feature/Inventory/`:

- `PurchaseRequest{Schema,Model,Service,Policy,Request,Controller,Ui}Test.php`
- `PurchaseOrder{Schema,Model,Service,Policy,Request,Controller,Ui}Test.php`
- `GoodsReceipt{Schema,Model,Service,Policy,Request,Controller,Ui,Ledger,BranchIsolation,ProcurementE2E}Test.php`

---

## Workflow Map

```text
┌─────────────────────────────────────────────────────────────────────────────┐
│                        PROCUREMENT → INVENTORY FLOW                          │
└─────────────────────────────────────────────────────────────────────────────┘

Purchase Request (trx_purchase_requests)
  draft ──submit──► submitted ──approve──► approved
    │                    │                    │
    └──cancel──► cancelled  reject──► rejected  │
                                               │
                                               ▼
Purchase Order (trx_purchase_orders)          [optional link via purchase_request_id]
  draft ──submit──► submitted ──approve──► approved ──send──► sent
    │                    │                                      │
    └──cancel──► cancelled                                      │
                                                                │
                    ┌───────────────────────────────────────────┘
                    │  (also from approved — send optional)
                    ▼
              [approved | sent | partially_received]  ◄── receivable PO statuses
                    │
                    ▼
Goods Receipt (trx_goods_receipts)
  draft ──submit──► submitted ──post──► posted (terminal, immutable)
    │                                      │
    └──cancel──► cancelled                 │
                                           ▼
                              trx_inventory_movements
                              movement_type = PURCHASE
                              quantity_in = accepted_qty
                              reference_type = trx_goods_receipts
                              reference_id = goods_receipt.id
                                           │
                                           ▼
                              PO item quantity_received += accepted_qty
                              PO status → partially_received | fully_received
```

### Quantity semantics (GR)

| Field | Enters stock? | Updates PO cache? |
|---|---|---|
| `accepted_qty` | Yes (`quantity_in`) | Yes |
| `rejected_qty` | No (audit only) | No |
| `received_qty` | — (must equal accepted + rejected) | No |

### Standalone receive (unchanged)

`InventoryStockController` → `InventoryStockService::receiveStock()` for ad-hoc **Terima Stok** remains available with `reference_type = null`.

---

## Risk List

| ID | Severity | Area | Finding | Mitigation for 16.4 |
|---|---|---|---|---|
| R1 | **Medium** | Batch/lot | GR post calls `receiveStock()` without `batchData`; products with `requires_batch_tracking` receive without batch linkage | Wire batch fields on GR form/service per Sprint 15.3 patterns; add tests |
| R2 | **Medium** | UX / ops | Submitted GR cannot be cancelled — mistaken submit leaves document post-or-stuck | Document SOP; consider draft-only submit requirement or cancel-from-submitted with audit |
| R3 | **Low** | UX | PO show page lacks per-line `quantity_received` / remaining / linked GR list | Add receiving progress panel on PO show |
| R4 | **Low** | Concurrency | Row locks exist; no dedicated concurrent double-post test | Optional stress test or documented acceptance |
| R5 | **Low** | Reversal | Posted GR immutable — no void/credit path | Explicitly out of scope; document correction via adjustment sprint |
| R6 | **Low** | Validation | Form `exists:` rules on PO/GR IDs are not branch-scoped at rule level | Service/policy block cross-branch; optional tighten rules |
| R7 | **Low** | Transactions | Nested `DB::transaction()` in `post()` → `receiveStock()` → `createInboundMovement()` | Acceptable via savepoints; optional flatten in hardening |
| R8 | **Info** | Location | GR allows receive location ≠ PO line suggested location | Intentional flexibility; document for operators |
| R9 | **Info** | Supplier | Inactive supplier blocked at post via `InventoryStockService::assertSupplierInBranch` | Covered indirectly; add explicit GR post test |
| R10 | **Info** | Product | Inactive product/location blocked at post via stock service re-validation | Covered at movement layer; add GR post regression test |

### Verified non-risks (audit passed)

- PO/PR actions do **not** create inventory movements (controller + model tests confirm).
- `quantity_received` is excluded from mass assignment and form input stripping.
- Over-receive blocked on create, update, and post.
- Double-post blocked by status + `posted_at` + movement existence checks.
- Branch isolation on GR: dedicated `GoodsReceiptBranchIsolationTest` (6 tests).
- Cross-branch PO access denied in controller/policy tests.
- Ledger traceability: `reference_type/id` + `inventory_movement_id` on GR items.

---

## Missing Tests

| Priority | Test | Rationale |
|---|---|---|
| High | GR post with `requires_batch_tracking` product | R1 — batch gap |
| High | GR post rejects inactive product/supplier/location at post time | R9/R10 — explicit regression |
| Medium | `PurchaseOrderBranchIsolationTest` (dedicated file) | Parity with GR; consolidate scattered PO cross-branch cases |
| Medium | `PurchaseRequestBranchIsolationTest` | PR has cross-branch checks in controller but no dedicated file |
| Medium | PO show receiving progress after partial GR | R3 — UI contract |
| Medium | Submitted GR workflow edge (post without submit path) | Both paths allowed; document + test parity |
| Low | Concurrent double-post harness | R4 |
| Low | PO `partially_received` still allows second GR for remaining lines | Partial receipt path |
| Low | GR with all lines rejected-only blocked at post | Service has `assertHasPostableQuantity` — add controller test if missing |

---

## Missing Validations

| Priority | Validation | Current behavior | Recommendation |
|---|---|---|---|
| High | Batch required when product `requires_batch_tracking` | Not enforced on GR | Add to `ValidatesGoodsReceiptInput` + service |
| Medium | `accepted_qty + rejected_qty === received_qty` on post | Validated on store/update only | Re-validate from persisted items or trust draft immutability |
| Medium | PO line location vs GR location consistency | GR location independent | Optional warning in UI if mismatch |
| Low | `supplier_invoice_number` format/uniqueness | Nullable string only | Defer to Sprint 16.4+ supplier invoice |
| Low | Branch-scoped `exists` rules | Global table exists | Custom rule `existsInBranch` for defense-in-depth |

---

## UI Issues

| ID | Page | Issue | Severity |
|---|---|---|---|
| U1 | PO show | No columns for Diterima / Sisa on item table | Medium |
| U2 | PO show | No list of linked Goods Receipts | Medium |
| U3 | GR create/edit | No batch/lot fields for tracked products | Medium |
| U4 | GR submitted | Cancel button hidden but no guidance to post or revert | Low |
| U5 | PR index/show | No link forward to created PO when exists | Low |
| U6 | Sidebar | Three separate procurement links (acceptable) | Info — monitor menu density |

**Verified UI strengths:**

- Indonesian operator labels throughout.
- Permission gates on actions (`@can`).
- PO “Tidak menambah stok” callout present.
- Terima Barang button gated by `receive` policy.
- Post confirmation modal with ledger impact warning.
- Mobile dual layout on GR index.

---

## Security / Branch Isolation Notes

### Branch resolution

All write paths use `BranchContext::requireId()`. Request `branch_id` is never trusted.

### Policy matrix summary

| Model | Cross-branch view | Mutation guard |
|---|---|---|
| PurchaseRequest | Denied via `belongsToActiveBranch` | Draft-only edit; approve separate permission |
| PurchaseOrder | Denied | Draft-only edit; cancel draft/submitted only |
| GoodsReceipt | Denied | Draft-only edit/cancel; post draft/submitted |

### Repository scoping

All list/find methods accept `int $branchId` first and apply `where('branch_id', $branchId)`.

### Posting locks

`GoodsReceiptService::post()` uses:

- `lockForPosting()` on GR
- `lockForUpdate()` on PO
- `InventoryStockService` locks product/location on movement

### Excluded / stripped fields (GR)

`quantity_received`, `inventory_movement_id`, `posted_at`, `posted_by`, `status`, `unit_cost`, `line_total`, snapshot qty fields — stripped in `ValidatesGoodsReceiptInput`.

### Authorization gaps (none critical)

- Route model binding resolves by ID globally; **policy + service branch check** is the enforcement layer (consistent with other inventory modules).
- Super Admin `Gate::before` bypass remains centralized — invalid transitions surface as validation errors, not 403.

---

## Recommended Implementation Checklist (Sprint 16.4)

Use this as the hardening closure backlog. Items marked **required** vs **optional**.

### Documentation & closure

- [ ] **Required:** Update `docs/sprint_history.md` with Sprint 16.1–16.3 completion record
- [ ] **Required:** Tag `sprint-16.3-complete` on procurement branch when merging
- [ ] **Required:** Add procurement workflow summary to `.cursor/memory/inventory.md` if not present
- [ ] Optional: Operator SOP for submitted-GR handling (R2)

### Ledger & inventory integrity

- [ ] **Required:** Verify no regression in standalone Terima Stok after GR changes
- [ ] **Required:** Batch/lot on GR receive for `requires_batch_tracking` products (R1)
- [ ] Optional: Flatten nested transactions in GR post path (R7)

### PO workflow hardening

- [ ] **Required:** PO show — display `quantity_ordered`, `quantity_received`, remaining per line (R3/U1)
- [ ] **Required:** PO show — list linked Goods Receipts with status + link (U2)
- [ ] Optional: Block PO cancel if any posted GR exists (currently cancel only draft/submitted — safe)

### GR workflow hardening

- [ ] **Required:** Tests for inactive product/supplier/location at post time
- [ ] Optional: Cancel-from-submitted with reason (product decision)
- [ ] Optional: Concurrency double-post test (R4)

### Branch & security tests

- [ ] **Required:** `PurchaseOrderBranchIsolationTest` dedicated file
- [ ] **Required:** `PurchaseRequestBranchIsolationTest` dedicated file
- [ ] Optional: Branch-scoped `exists` validation rules

### UI polish

- [ ] **Required:** Receiving status badges on PO index when `partially_received` / `fully_received`
- [ ] Optional: PR → PO navigation link when active PO exists (U5)
- [ ] Optional: Location mismatch warning on GR form (R8)

### Explicitly defer (out of Sprint 16.4 procurement hardening)

- Supplier Invoice / Accounts Payable
- GR reversal / void / credit note
- HR / Attendance / Payroll
- Production material usage (Sprint 17)
- Over-receive approval override

### Quality gates (before 16.4 sign-off)

- [ ] `php artisan test`
- [ ] `php artisan test --filter=PurchaseRequest`
- [ ] `php artisan test --filter=PurchaseOrder`
- [ ] `php artisan test --filter=GoodsReceipt`
- [ ] `./vendor/bin/pint --test`
- [ ] `php artisan route:list --name=purchase`
- [ ] `php artisan route:list --name=goods-receipts`
- [ ] `npm run build` (if Blade/JS touched)
- [ ] `graphify update .`

---

## Assumptions

1. Audit performed on branch `feature/sprint-16-procurement` at commit `4de1a40` (workspace was on `feature/sprint-16.4-supplier-invoice` before checkout).
2. `.cursor/snippets/adlms_master_workflow.md` was unavailable; workflow followed via `AGENTS.md`, project rules, and sprint docs.
3. Sprint 16.4 scope is **procurement hardening and closure**, not Supplier Invoice implementation (despite branch name `sprint-16.4-supplier-invoice` on parallel work).
4. `quantity_received` is treated as an **allowed fulfillment cache** (documented in 16.3), not a forbidden mutable stock column per `docs/inventory_rules.md`.

---

## Audit Sign-off

| Check | Verdict |
|---|---|
| PO workflow intact (no stock leak) | **PASS** |
| GR → ledger integration | **PASS** |
| Branch isolation | **PASS** (with test coverage gaps for PR/PO dedicated files) |
| No mutable stock columns | **PASS** |
| Layering consistency | **PASS** |
| Permission/policy alignment | **PASS** |
| Sprint 16.4 hardening needed | **YES** — batch on GR, PO receiving visibility, test gaps, ops edge cases |

---

*End of Sprint 16.4 Procurement Hardening Audit*
