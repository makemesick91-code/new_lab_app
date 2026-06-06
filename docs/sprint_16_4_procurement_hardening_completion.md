# Sprint 16.4 — Procurement Hardening Completion

Date: 2026-06-06  
Status: **COMPLETED**  
Branch: `feature/sprint-16-procurement`  
HEAD: `e020eee`  
Baseline: Sprint 16.3 Goods Receipt complete (`4de1a40`)  
Audit source: `docs/sprint_16_4_procurement_hardening_audit.md`

---

## 1. Executive Summary

Sprint 16.4 closes the procurement hardening backlog identified in the Sprint 16.4 audit. The sprint does **not** re-architect procurement; it hardens operator UX, batch compliance, reversal workflow, branch isolation tests, and permission/UI regression coverage on top of the completed PR → PO → GR chain from Sprints 16.1–16.3.

**Delivered in four slices:**

| Slice | Commit | Focus |
|---|---|---|
| 16.4.1 | `af8a986` | Batch/lot wiring on Goods Receipt receive |
| 16.4.2 | `5a100af` | Purchase Order receiving visibility |
| 16.4.3 | `b9f14b8` | Goods Receipt void workflow + reversal ledger |
| 16.4.4 | `7b2494b`, `398055c`, `e020eee` | PR/PO branch isolation tests, GR regression, UI permission hardening |

**Architectural verdict:** All ADLMS non-negotiables preserved — modular monolith layering, `BranchContext` branch resolution, ledger-only inventory, no mutable stock columns. Purchase Request and Purchase Order remain **zero stock impact**. Goods Receipt **post** writes `PURCHASE` movements; Goods Receipt **void** writes `ADJUSTMENT_OUT` reversal movements and rolls back PO fulfillment cache.

**Test growth:** Full suite increased from **950 tests** (Sprint 16.3 baseline) to **1003 tests** (3405 assertions) at Sprint 16.4 sign-off.

---

## 2. Branch dan Commit Baseline

```text
feature/sprint-16-procurement

4de1a40  Complete Sprint 16.3 goods receipt workflow          ← 16.3 baseline
af8a986  Implement Sprint 16.4.1 goods receipt batch lot wiring ← 16.4.1
5a100af  Implement Sprint 16.4.2 purchase order receiving visibility ← 16.4.2
b9f14b8  Implement Sprint 16.4.3 goods receipt void workflow  ← 16.4.3
7b2494b  Add purchase request branch isolation tests          ← 16.4.4 (PR)
398055c  Add purchase order branch isolation tests            ← 16.4.4 (PO)
e020eee  Add goods receipt hardening regression tests         ← 16.4.4 (GR)
```

### Procurement chain (unchanged architecture)

```text
Purchase Request (intent, no stock)
    → Purchase Order (commitment, no stock)
        → Goods Receipt (receiving document)
            → post  → PURCHASE movement (quantity_in)
            → void  → ADJUSTMENT_OUT reversal (quantity_out)
```

---

## 3. Deliverables 16.4.1 — Batch/Lot on Goods Receipt

**Commit:** `af8a986`

### `requires_batch_tracking`

- Migration `2026_06_08_100000_add_requires_batch_tracking_to_inv_products_table.php` adds `requires_batch_tracking` boolean on `inv_products`.
- Product model and factory updated; GR create flow preloads `requires_batch_tracking` per PO line for UI conditional rendering.

### Batch fields on GR item

- Migration `2026_06_08_100001_add_batch_fields_to_trx_goods_receipt_items_table.php` adds:
  - `inventory_batch_id` (FK → `inv_inventory_batches`)
  - `batch_number`, `lot_number`, `batch_received_date`, `expiry_date`
- `GoodsReceiptItem` model relations and fillable/casts updated.
- Blade partial `_batch-item-fields.blade.php` wired into GR create/edit forms.

### Batch validation

- New concern `ValidatesGoodsReceiptBatchInput.php` — validates batch mode (`new` vs `existing`), required fields when `requires_batch_tracking = true`, branch/product consistency for existing batches.
- `ValidatesGoodsReceiptInput` extended; `GoodsReceiptService` asserts batch input on create/update/post via `assertBatchInputForItem()` and `assertExistingBatchForItem()`.
- Rejects cross-branch batch selection and empty `batch_number` for tracked products.

### Movement `inventory_batch_id`

- `GoodsReceiptService::post()` passes `batchData` to `InventoryStockService::receiveStock()` for tracked products.
- Posted PURCHASE movements carry `inventory_batch_id`; GR item persists linked batch after post.
- Void reversal preserves `inventory_batch_id` on ADJUSTMENT_OUT movement (batch-scoped stock check).

### Tests

- `tests/Feature/Inventory/GoodsReceiptBatchTest.php` — 8 tests covering validation, post linkage, cross-branch rejection, void with batch preservation, schema assertions.

---

## 4. Deliverables 16.4.2 — PO Receiving Visibility

**Commit:** `5a100af`

### PO item receiving visibility

- `PurchaseOrderItem` gains `receivingStatus()`, `receivingStatusLabel()`, and constants `pending` / `partial` / `complete`.
- PO show item table extended with **Dipesan**, **Diterima**, **Sisa**, and **Status Penerimaan** columns.

### Received / remaining / status

- Display uses `quantity_ordered`, `quantity_received` (fulfillment cache), `quantityRemaining()`, and per-line receiving badge via `_receiving-status-badge.blade.php`.
- PO index can surface receiving status badges for `partially_received` / `fully_received` PO headers.

### Linked GR panel

- PO show page section **Penerimaan Barang Terkait** lists linked Goods Receipts with receipt number, date, status badge, and link to GR detail.
- `PurchaseOrderRepository` query loads branch-scoped linked receipts; cross-branch GRs excluded from PO show.

### Tests

- `tests/Feature/Inventory/PurchaseOrderReceivingVisibilityTest.php` — 5 tests for quantity columns, linked GR panel, branch isolation on show, permission denial, empty state.

---

## 5. Deliverables 16.4.3 — GR Void Workflow

**Commit:** `b9f14b8`

### Submitted cancel

- `GoodsReceiptService::cancel()` accepts **draft** and **submitted** statuses.
- Submitted cancel writes no ledger movements; PO `quantity_received` unchanged (no post occurred).
- `CancelGoodsReceiptRequest` accepts optional `reason`; `cancellation_reason` persisted on header.
- Controller cancel route and UI cancel button on draft/submitted GR show pages.

### Posted void

- New status `GoodsReceipt::STATUS_VOID` (`void`).
- New route `inventory.goods-receipts.void` → `GoodsReceiptController::void()`.
- `VoidGoodsReceiptRequest` requires `reason` (min length); `voided_at`, `voided_by`, `cancellation_reason` audit fields.
- Migration `2026_06_09_100001_add_void_fields_to_trx_goods_receipts_table.php`.
- `GoodsReceiptPolicy` — `void` ability for `manage_inventory` on posted GR only; terminal states blocked for edit/cancel/post.

### Reversal ledger movement

- `InventoryStockService::reversePurchaseMovement()` creates `ADJUSTMENT_OUT` with `quantity_out` equal to original PURCHASE `quantity_in`.
- Reversal references GR (`reference_type` / `reference_id`); `reversal_movement_id` stored on GR item.
- Original PURCHASE movement retained (append-only ledger); insufficient stock/batch stock blocked before reversal.
- Batch-tracked void preserves `inventory_batch_id` on reversal movement.

### PO cache rollback

- `PurchaseOrderRepository::decrementItemQuantityReceived()` rolls back accepted qty per voided line.
- `GoodsReceiptService::recalculatePurchaseOrderReceivingStatus()` recomputes PO status (`sent` / `partially_received` / `fully_received`) from item caches after void.

### Tests

- `tests/Feature/Inventory/GoodsReceiptVoidTest.php` — 10 tests covering submitted cancel, void reversal, stock reduction, PO cache restore, double-void guard, auth, cross-branch denial, route contracts.

---

## 6. Deliverables 16.4.4 — Final Hardening

**Commits:** `7b2494b`, `398055c`, `e020eee`

### PR branch isolation tests

- New `tests/Feature/Inventory/PurchaseRequestBranchIsolationTest.php` — 11 tests.
- Covers view/edit/update/submit/approve/reject/cancel denial across branches, index scoping, service `listForBranch` scoping, service branch guard, unauthorized user denial.

### PO branch isolation tests

- New `tests/Feature/Inventory/PurchaseOrderBranchIsolationTest.php` — 14 tests.
- Covers full workflow route denial across branches, index scoping, PO show GR panel branch isolation, service create guards (supplier/product/location), no inventory movements from cross-branch attempts.

### GR regression

- Extended `GoodsReceiptBranchIsolationTest.php` — cross-branch void route denial, store route PO rejection.
- Extended `GoodsReceiptBatchTest.php` — void with batch preservation regression.
- Extended `GoodsReceiptVoidTest.php` — additional route/policy edge cases.
- Existing GR suite retained: ledger, service, policy, controller, UI, procurement E2E (144 tests under `--filter=GoodsReceipt`).

### UI permission hardening

- GR show: void button visible for `manage_inventory` on posted GR; hidden on void/cancelled/posted-immutable states.
- GR show: cancel button on draft/submitted only; posted requires void path.
- Sidebar/menu visibility gated by `view_inventory` / policy `viewAny`.
- Mutation buttons hidden for `view_inventory`-only users on GR show.
- PO show: linked GR panel does not leak other-branch receipts.
- Status badge partial updated for `void` label (**Divid**).

---

## 7. Ledger Rules

| Document / Action | Stock impact | Movement type |
|---|---|---|
| Purchase Request (all statuses) | **None** | — |
| Purchase Order (all statuses) | **None** | — |
| Goods Receipt draft / submitted / cancelled | **None** | — |
| Goods Receipt post | **Inbound** | `PURCHASE` (`quantity_in` = `accepted_qty`) |
| Goods Receipt void (posted) | **Outbound reversal** | `ADJUSTMENT_OUT` (`quantity_out` = original `quantity_in`) |

### Invariants enforced

- **PR/PO tidak menulis stok** — confirmed by model, service, controller, and branch isolation tests; no `trx_inventory_movements` rows created during PR/PO lifecycle.
- **GR post menulis PURCHASE** — `GoodsReceiptService::post()` → `InventoryStockService::receiveStock()` with `movement_type = PURCHASE`.
- **GR void menulis reversal ADJUSTMENT_OUT** — `GoodsReceiptService::void()` → `InventoryStockService::reversePurchaseMovement()`.
- **Tidak ada mutable stock column** — no `current_stock`, `qty_on_hand`, or equivalent added to products, locations, or procurement tables. `quantity_received` on PO items remains an **allowed fulfillment cache** (sum of posted GR accepted qty), not stock.
- **Derived stock** — `SUM(quantity_in) - SUM(quantity_out)` per branch/location[/batch].

---

## 8. Quality Gates

Executed on branch `feature/sprint-16-procurement` at HEAD `e020eee`, 2026-06-06.

| Gate | Result | Detail |
|---|---|---|
| `php artisan test --filter=PurchaseRequest` | **PASS** | 60 tests, 214 assertions |
| `php artisan test --filter=PurchaseOrder` | **PASS** | 159 tests, 557 assertions |
| `php artisan test --filter=GoodsReceipt` | **PASS** | 144 tests, 581 assertions |
| `php artisan test --filter=Inventory` | **PASS** | 627 tests, 2501 assertions |
| `php artisan test` | **PASS** | 1003 tests, 3405 assertions |
| `vendor/bin/pint` (`--test`) | **PASS** | No style violations |
| `git diff --check` | **PASS** | No conflict markers or whitespace errors |

---

## 9. Out of Scope

The following remain explicitly **out of Sprint 16.4** scope (unchanged from audit):

| Item | Notes |
|---|---|
| Supplier Invoice / Accounts Payable | Future sprint; no AP tables or invoice matching |
| Procurement advanced reporting | Valuation/export dashboards deferred |
| Multi-level procurement approval | Single approve permission per PR/PO retained |
| HR / Attendance / Payroll | Not touched |

Also deferred (not required for 16.4 sign-off):

- Branch-scoped `exists:` validation rules (defense-in-depth optional)
- Concurrent double-post stress harness
- PR → PO forward navigation link on PR show (low priority UX)
- Location mismatch warning on GR form

---

## Files Changed (Sprint 16.4 aggregate)

### Migrations

- `2026_06_08_100000_add_requires_batch_tracking_to_inv_products_table.php`
- `2026_06_08_100001_add_batch_fields_to_trx_goods_receipt_items_table.php`
- `2026_06_09_100001_add_void_fields_to_trx_goods_receipts_table.php`

### Application code (key)

- `GoodsReceiptService.php`, `InventoryStockService.php`, `PurchaseOrderRepository.php`
- `ValidatesGoodsReceiptBatchInput.php`, `ValidatesGoodsReceiptInput.php`
- `VoidGoodsReceiptRequest.php`, `CancelGoodsReceiptRequest.php`
- `GoodsReceiptPolicy.php`, `GoodsReceiptController.php`
- `PurchaseOrderItem.php`, `GoodsReceipt.php`, `GoodsReceiptItem.php`
- `routes/web.php` — `goods-receipts.void`

### Views

- `inventory/goods-receipts/_batch-item-fields.blade.php`, `_form.blade.php`, `show.blade.php`, `_status-badge.blade.php`
- `inventory/purchase-orders/show.blade.php`, `_receiving-status-badge.blade.php`

### Tests added/extended

- `GoodsReceiptBatchTest.php`
- `GoodsReceiptVoidTest.php`
- `PurchaseOrderReceivingVisibilityTest.php`
- `PurchaseRequestBranchIsolationTest.php`
- `PurchaseOrderBranchIsolationTest.php`
- `GoodsReceiptBranchIsolationTest.php` (extended)
- `GoodsReceiptUiTest.php`, `GoodsReceiptPolicyTest.php`, `GoodsReceiptControllerTest.php` (extended)

### Documentation

- `docs/sprint_16_4_procurement_hardening_audit.md` (added at 16.4.1)
- `docs/sprint_16_4_procurement_hardening_completion.md` (this file)

---

## Assumptions

1. Sprint 16.4 scope is procurement hardening only; Supplier Invoice work on parallel branches is separate.
2. `quantity_received` on `trx_purchase_order_items` is a fulfillment cache, consistent with Sprint 16.3 design and `docs/inventory_rules.md`.
3. Void is the correction path for posted GR; standalone stock adjustment remains available for non-procurement corrections.
4. Batch tracking on GR follows Sprint 15.3 batch patterns (`inv_inventory_batches` ledger linkage).

---

## Risks / Follow-up

| Risk | Mitigation status |
|---|---|
| Operator posts GR without reviewing batch fields | Batch validation enforced; UI shows conditional fields |
| Void with insufficient derived stock | Blocked by `reversePurchaseMovement()` stock check |
| Cross-branch ID in form `exists` rules | Service/policy branch guards remain enforcement layer |
| Audit doc drift vs code | Completion doc tied to commit SHAs above |

**Recommended follow-up (post-16.4):** update `docs/sprint_history.md` with Sprint 16.4 record; run `graphify update .` after doc merge.

---

## Sign-off

| Check | Verdict |
|---|---|
| 16.4.1 batch/lot on GR | **PASS** |
| 16.4.2 PO receiving visibility | **PASS** |
| 16.4.3 GR void + reversal | **PASS** |
| 16.4.4 branch isolation + regression | **PASS** |
| Ledger rules preserved | **PASS** |
| Quality gates | **PASS** |

---

*End of Sprint 16.4 Procurement Hardening Completion*
