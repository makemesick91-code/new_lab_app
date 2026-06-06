# Sprint 16.3 Goods Receipt Workflow Completion Summary

Date: 2026-06-06  
Status: **COMPLETED**  
Baseline: Sprint 16.2 Purchase Order complete

---

## Sprint Objective

Sprint 16.3 closes the procurement chain introduced in Sprints 16.1 and 16.2:

```text
Purchase Request → Purchase Order → Goods Receipt → PURCHASE Inventory Movement
```

Sprint 16.1 (Purchase Request) captures **intent only** — no stock impact.  
Sprint 16.2 (Purchase Order) captures **supplier commitment** — still no stock impact.  
Sprint 16.3 (Goods Receipt) is the **first procurement sprint that writes to the inventory ledger**. When a Goods Receipt is **posted**, the system creates `trx_inventory_movements` rows with `movement_type = PURCHASE` inside a single `DB::transaction()`. Stock remains ledger-derived; no mutable stock columns are added.

Sprint 16.3 exists because dental lab operators need a controlled, auditable receiving workflow that ties physical receipt to an approved/sent PO, records accepted vs rejected quantities, updates PO fulfillment tracking, and increases branch/location stock through the existing movement ledger — not through ad hoc balance columns.

---

## Scope Completed

| Area | Status |
|---|---|
| Goods Receipt schema (`trx_goods_receipts`, `trx_goods_receipt_items`) | ✓ |
| PO schema extension (`quantity_received` cache) | ✓ |
| Goods Receipt models + factories | ✓ |
| Extended PurchaseOrder / PurchaseOrderItem models | ✓ |
| GoodsReceiptRepository + interface + provider binding | ✓ |
| Extended PurchaseOrderRepository (service-controlled cache increment) | ✓ |
| GoodsReceiptService (draft, submit, post, cancel) | ✓ |
| Ledger posting via InventoryStockService with reference params | ✓ |
| Form Requests + ValidatesGoodsReceiptInput concern | ✓ |
| GoodsReceiptPolicy + PurchaseOrderPolicy::receive | ✓ |
| GoodsReceiptController + 9 routes | ✓ |
| Blade UI (index, create, edit, show, partials) | ✓ |
| Sidebar **Penerimaan Barang** | ✓ |
| PO show **Terima Barang** integration | ✓ |
| Hardening + safeguard tests (16.3.7) | ✓ |
| Regression tests (PO, inventory stock, procurement E2E) | ✓ |

---

## Database Changes

### New Tables

**`trx_goods_receipts`** — branch-scoped goods receipt document header. Records receiving intent and workflow state only; does not store stock balances.

Key columns: `branch_id`, `purchase_order_id`, `receipt_number` (unique, `GR-{YYYYMMDD}-{branch_id}-{seq}`), `receipt_date`, `status`, `supplier_delivery_number`, `supplier_invoice_number`, `notes`, audit timestamps (`submitted_at`, `posted_at`, `cancelled_at`) and user FKs (`created_by`, `submitted_by`, `posted_by`, `cancelled_by`).

**`trx_goods_receipt_items`** — per-line received quantities and cost snapshots.

Key columns: `goods_receipt_id`, `purchase_order_item_id`, `product_id`, `inventory_location_id`, `ordered_qty`, `previously_received_qty`, `received_qty`, `accepted_qty`, `rejected_qty`, `unit_cost`, `line_total`, `inventory_movement_id`, `notes`.

### Existing Tables Updated

**`trx_purchase_order_items.quantity_received`** — decimal(12,2), default `0`. Cumulative **accepted** quantity across all **posted** GRs. A derived fulfillment cache — not stock.

### Cost and Movement Linkage Fields

| Field | Table | Purpose |
|---|---|---|
| `unit_cost` | `trx_goods_receipt_items` | Cost snapshot copied from PO item `unit_price` at post time; immutable after post; written to movement `unit_cost` |
| `line_total` | `trx_goods_receipt_items` | Persisted `accepted_qty × unit_cost` at post time for line-level audit and future valuation reports |
| `inventory_movement_id` | `trx_goods_receipt_items` | 1:1 FK to the PURCHASE movement created for that line (when `accepted_qty > 0`); enables audit chain from GR line → movement → stock card |

---

## Models Added / Updated

### Added

**`GoodsReceipt`** (`trx_goods_receipts`)

- Status constants: `draft`, `submitted`, `posted`, `cancelled`
- Relations: `branch`, `purchaseOrder`, `items`, `createdBy`, `submittedBy`, `postedBy`, `cancelledBy`
- Helpers: `isDraft()`, `isSubmitted()`, `isPosted()`, `isCancelled()`, `canBeEdited()`, `canBePosted()`, `canBeCancelled()`

**`GoodsReceiptItem`** (`trx_goods_receipt_items`)

- Relations: `goodsReceipt`, `purchaseOrderItem`, `product`, `inventoryLocation`, `inventoryMovement`

### Updated

**`PurchaseOrder`**

- New statuses: `partially_received`, `fully_received`
- Relation: `hasMany(GoodsReceipt::class)`

**`PurchaseOrderItem`**

- Column: `quantity_received` (not mass-assignable)
- Accessor: `quantityRemaining()` = `quantity_ordered - quantity_received`
- Relation: `hasMany(GoodsReceiptItem::class)` via `purchase_order_item_id`

---

## Workflow Implemented

```text
Purchase Request (intent)
    → Purchase Order (commitment)
        → Goods Receipt (receiving document)
            → PURCHASE Inventory Movement (ledger write on post)
```

### Quantity Semantics

| Field | Meaning |
|---|---|
| `received_qty` | Total physical quantity on the delivery line = `accepted_qty + rejected_qty` (operator-facing; validated on input) |
| `accepted_qty` | Quantity accepted into stock; drives PURCHASE movement `quantity_in` and PO `quantity_received` increment |
| `rejected_qty` | Quantity rejected on receipt; audit-only — **does not** enter stock or PO fulfillment cache |

Partial receipt is supported: multiple GR documents per PO; each GR may include a subset of lines and/or partial quantities.

---

## Goods Receipt Status Workflow

```text
draft ──submit──► submitted ──post──► posted (terminal, immutable)
  │                                      │
  └──cancel──► cancelled (terminal)      └── (no reversal in 16.3)
```

| Status | Stock impact | Editable | Notes |
|---|---|---|---|
| **draft** | None | Yes (header + lines) | Can submit, post directly, or cancel |
| **submitted** | None | No | Review checkpoint before posting; can post; cannot cancel or edit |
| **posted** | Creates PURCHASE movements | No — **immutable** | Terminal; `posted_at` / `posted_by` set |
| **cancelled** | None | No | Draft-only cancel; no ledger writes |

Posting is allowed from **draft** or **submitted**. Double-post is blocked by `posted_at` + status guard inside a locked transaction.

---

## Inventory Ledger Behavior

- **Ledger-only inventory** — stock = `SUM(quantity_in) - SUM(quantity_out)` from `trx_inventory_movements`
- **No mutable stock columns** — no `current_stock`, `qty_on_hand`, `stock_qty`, or `available_stock` on products or locations
- **PURCHASE movement creation** — one movement per GR line with `accepted_qty > 0`
- **`accepted_qty` enters stock** via movement `quantity_in`
- **`rejected_qty` does not enter stock** — recorded on GR line only
- **Traceability** — movement `reference_type = trx_goods_receipts`, `reference_id = goods_receipt.id`
- **`inventory_movement_id` linkage** — per-line FK on `trx_goods_receipt_items` for 1:1 audit path

Audit chain: **stock card → movement → GR item → GR header → PO item → PO header**.

Standalone **Terima Stok** (product stock card) continues with `reference_type = null` for ad-hoc receives without PO.

---

## Purchase Order Receiving Behavior

### `quantity_received` Cache

- **Ownership:** exclusively updated by `GoodsReceiptService::post()` via `PurchaseOrderRepository::incrementItemQuantityReceived()`
- **Increment rule:** `quantity_received += accepted_qty` only (never `rejected_qty`)
- **Not user-editable** — excluded from all GR form requests and PO forms

### PO Status Progression

Eligible for new GR: `approved`, `sent`, `partially_received`.

After GR post:

| Condition | PO status |
|---|---|
| Every line: `quantity_received >= quantity_ordered` | `fully_received` |
| At least one line partially received, not all complete | `partially_received` |
| No lines received yet (edge) | Keep `approved` or `sent` |

Blocked for new GR: `draft`, `submitted`, `cancelled`, `fully_received`.

### Over-Receive Prevention

On create and post:

```text
quantity_received + accepted_qty <= quantity_ordered
```

`rejected_qty` excluded from calculation. Error (Indonesian): *Jumlah diterima melebihi sisa pesanan untuk item ini.*

---

## Branch Enforcement

All branch-owned operations use `BranchContext::requireId()` — never request `branch_id`.

| Protection | Enforcement |
|---|---|
| PO linkage | PO must belong to active branch |
| GR create/update/post | GR `branch_id` from BranchContext; repository `findInBranch` |
| Inventory location | Location must belong to same branch as GR |
| Posting | Service locks GR + PO + PO items with `lockForUpdate()` inside transaction |
| Viewing | Policy `belongsToActiveBranch()` denies cross-branch access |
| Updating | Draft-only + branch match |

Tests: `GoodsReceiptBranchIsolationTest` (6 tests).

---

## Authorization

Permissions (existing — no new Spatie permissions added):

| Permission | Capability |
|---|---|
| `view_inventory` | Index, show GR; view PO receiving status |
| `manage_inventory` | Create, update, submit, post, cancel GR |

### GoodsReceiptPolicy Matrix

| Ability | Permission | Status guard | Branch |
|---|---|---|---|
| `viewAny` | `view_inventory` | — | active branch |
| `view` | `view_inventory` | — | `belongsToActiveBranch` |
| `create` | `manage_inventory` | — | active branch |
| `update` | `manage_inventory` | `draft` only | `belongsToActiveBranch` |
| `submit` | `manage_inventory` | `draft` only | `belongsToActiveBranch` |
| `post` | `manage_inventory` | draft or submitted; not posted/cancelled | `belongsToActiveBranch` |
| `cancel` | `manage_inventory` | `draft` only | `belongsToActiveBranch` |

### PurchaseOrderPolicy Extension

| Ability | Rule |
|---|---|
| `receive` | `manage_inventory` + PO status ∈ {approved, sent, partially_received} + branch match |

---

## UI Implemented

### Pages

| Page | Route | Purpose |
|---|---|---|
| Index | `inventory.goods-receipts.index` | List with filters (status, PO, date) |
| Create | `inventory.goods-receipts.create` | New GR; prefill via `?purchase_order_id=` |
| Edit | `inventory.goods-receipts.edit` | Draft-only line editing |
| Show | `inventory.goods-receipts.show` | Detail; posted shows linked movements |

Views: `resources/views/inventory/goods-receipts/` (index, create, edit, show, `_form`, `_status-badge`).

### Sidebar

**Persediaan → Penerimaan Barang** — gated by `view_inventory`.

### Purchase Order Integration

- **Terima Barang** button on PO show when `@can('receive', $purchaseOrder)` and remaining qty exists
- PO receiving status badges: **Sebagian Diterima**, **Lengkap Diterima**

### UX Improvements

- Auto-calculated `received_qty` = `accepted_qty + rejected_qty` with helper text
- Read-only PO context columns: ordered / previously received / remaining
- Over-receive warning markup on form
- Item-level validation display section
- Posting confirmation modal with ledger impact warning
- Mobile-responsive dual layout (Sprint 12 inventory conventions)

---

## Hardening Results

Sprint 16.3.7 safeguard and regression coverage:

| Test file | Focus | Tests |
|---|---|---|
| `GoodsReceiptSchemaTest` | Tables, columns, indexes, no mutable stock columns | 9 |
| `GoodsReceiptModelTest` | Relations, status helpers, PO extensions | 9 |
| `GoodsReceiptServiceTest` | Workflow, partial receipt, over-receive, PO sync, posting guard, cost snapshot, rollback | 24 |
| `GoodsReceiptPolicyTest` | Auth matrix, branch denial | 7 |
| `GoodsReceiptRequestTest` | Validation, excluded fields, post/cancel guards | 16 |
| `GoodsReceiptControllerTest` | HTTP happy/deny paths, double-post denial | 18 |
| `GoodsReceiptLedgerTest` | Stock increases, movement refs, PO cache, audit chain | 8 |
| `GoodsReceiptBranchIsolationTest` | Cross-branch PO/GR/location denied | 6 |
| `GoodsReceiptUiTest` | Blade labels, buttons, permission gates, UX helpers | 23 |
| `GoodsReceiptProcurementE2ETest` | PR → PO → GR → ledger end-to-end | 1 |

**Total GoodsReceipt tests:** 121 passed (462 assertions).

Key safeguard scenarios verified:

- Posted GR cannot be posted twice; no duplicate PURCHASE movements
- Movement `reference_type/id` traceability to GR header
- `unit_cost` / `line_total` snapshotted at post; movement cost matches GR item
- PO `quantity_received` increases by `accepted_qty` only; `rejected_qty` excluded
- PO `partially_received` / `fully_received` transitions
- `fully_received` PO blocks new GR
- Atomic rollback on movement failure (no partial PO cache or GR status)
- Draft/submitted/cancelled GR create no stock impact

---

## Quality Gates

Recorded at Sprint 16.3.7 completion (2026-06-06):

| Gate | Command | Result |
|---|---|---|
| Full test suite | `php artisan test` | **PASS** — 950 tests, 3178 assertions (234.64s) |
| GoodsReceipt tests | `php artisan test --filter=GoodsReceipt` | **PASS** — 121 tests, 462 assertions (33.67s) |
| Routes | `php artisan route:list --name=goods-receipts` | **PASS** — 9 routes registered |
| Frontend build | `npm.cmd run build` | **PASS** — Vite 6.4.3, built in 2.47s |
| Code style | `./vendor/bin/pint --test` | **PASS** |
| Whitespace | `git diff --check` | **PASS** (CRLF warnings on graphify cache only) |
| Knowledge graph | `graphify update .` | **PASS** — 7451 nodes, 11764 edges |

---

## Coverage Summary

| Metric | Count |
|---|---|
| GoodsReceipt test files | 10 |
| GoodsReceipt tests | 121 |
| Total application tests | 950 |
| Goods-receipt routes | 9 |

### Remaining Non-Blocking Gaps

| Gap | Notes |
|---|---|
| Concurrency harness | Row locks implemented; dedicated concurrent double-post stress test deferred |
| GR reversal | Posted GR immutable; credit/adjustment sprint required |
| AP integration | Supplier invoice / accounts payable out of scope |
| Costing engine | Advanced valuation / FIFO beyond line snapshots deferred |
| Batch tracking on GR form | Sprint 15.3 batch path exists on `InventoryStockService`; GR UI batch fields not wired in 16.3 |

---

## Known Limitations

**Explicitly out of scope for Sprint 16.3:**

- Supplier Invoice
- Accounts Payable
- Payment
- HR / Payroll
- Advanced Costing engine
- Goods Receipt Reversal / void / credit note
- Over-receiving override or approval path
- PO `closed` status (use `fully_received` as receiving terminal)
- Cross-branch receiving

Posted GR is **immutable**. Corrections require a future reversal/adjustment sprint.

---

## Implementation vs Design Review

The implementation delivers all core Sprint 16.3 objectives: PO-linked receiving, ledger posting, PO fulfillment cache, branch isolation, authorization, UI, and safeguard tests.

**Approved deviations from `docs/sprint_16_3_goods_receipt_technical_design.md`:**

| Topic | Design | Implementation | Rationale |
|---|---|---|---|
| GR status flow | `draft → posted` (cancel from draft) | `draft → submitted → posted` (+ cancel from draft only) | Adds review checkpoint aligned with PR/PO patterns; post allowed from draft or submitted |
| Document number column | `goods_receipt_number` | `receipt_number` | Consistent with model attribute naming |
| Supplier on GR header | Denormalized `supplier_id` / `supplier_snapshot_name` | Accessed via `purchaseOrder` relation | PO is required link; avoids redundant snapshot |
| Delivery note field | `supplier_delivery_note` | `supplier_delivery_number` | Operator terminology (surat jalan number) |
| Line quantity columns | `accepted_qty`, `rejected_qty` only | Also `ordered_qty`, `previously_received_qty`, `received_qty` | UX context snapshots; `received_qty = accepted + rejected` |
| Submit route | Not specified | `inventory.goods-receipts.submit` | Supports submitted review step |
| Supplier invoice field | Not in design | `supplier_invoice_number` optional on header | Forward-compatible placeholder for 16.4 |
| Batch fields on GR form | Sprint 15.3 batch rules on GR lines | Not wired in GR UI/service in 16.3 | Non-blocking; standalone Terima Stok retains batch path |
| Cancel from submitted | Design implied draft-only | Submitted cannot cancel | Stricter guard — submitted GR must post or remain |

All ledger, branch, PO cache ownership, over-receive, traceability, and immutability rules match the technical design.

---

## Suggested Next Sprint

**Recommended: Sprint 16.4 — Supplier Invoice Preparation**

Rationale:

- Procurement chain is now PR → PO → GR → ledger; the natural next document is supplier invoice matching against posted GR lines (`unit_cost`, `line_total`, `quantity_received` snapshots are ready).
- Keeps the procurement milestone cohesive before switching domains.
- GR cost snapshots and PO fulfillment cache provide the data contract for three-way match (PO / GR / invoice).

**Alternative: Sprint 17 — HR Core**

Rationale if business priority shifts away from procurement:

- HR was deferred throughout inventory sprints; core employee/attendance foundations can proceed independently.
- No dependency on GR completion.

---

## Release Information

| Field | Value |
|---|---|
| Completion Date | 2026-06-06 |
| Sprint Slice | 16.3 — Goods Receipt Workflow |
| Branch | `feature/sprint-16-procurement` |
| Status | COMPLETED (full-suite gates verified) |
| Suggested tag | `sprint-16.3-complete` |

---

*End of Sprint 16.3 Completion Summary*
