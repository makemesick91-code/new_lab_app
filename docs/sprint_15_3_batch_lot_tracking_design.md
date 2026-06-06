# Sprint 15.3 Technical Design — Batch & Lot Tracking

Date: 2026-06-06
Status: DESIGN (not implemented)
Module: `app/Modules/Inventory`
Prerequisites: Sprint 15.1 Stock Transfer Workflow (complete), Sprint 15.2 Transfer Receiving Workflow (complete)

## Sprint Numbering Note

The repository roadmap memory (`.cursor/memory/sprint-roadmap-13-20.md`) originally placed batch/lot tracking later in the milestone. Active planning for the current inventory slice labels this work **Sprint 15.3** immediately after ship/receive transfer receiving.

| Planning slice | Dependency |
|---|---|
| Sprint 15.1 | Stock Transfer document workflow (`draft → submitted`) |
| Sprint 15.2 | Ship/receive ledger split (`submitted → in_transit → received`) |
| Sprint 15.3 | This document — batch/lot identity on ledger movements |

Implementation must not break Sprint 15.2 ship/receive contracts, Sprint 12 ledger rules, or Sprint 13 stock opname finalization semantics.

---

## Problem Statement

ADLMS inventory correctly tracks **how much** stock exists per branch and location through the movement ledger (`trx_inventory_movements`). It does **not** track **which physical lot or batch** a quantity belongs to.

For a dental laboratory, that gap creates operational and compliance risk:

1. **Material traceability** — Ceramics, zirconia discs, resins, alloys, and impression materials often arrive with manufacturer batch/lot identifiers. When a remake or QC issue occurs, staff cannot answer “which lot was used?” from the system.
2. **Expiry control** — Many lab consumables have shelf life. Without `expiry_date` tied to inbound lots, expired material may remain selectable for use.
3. **Recall readiness** — Supplier recalls require tracing all stock and downstream usage from a batch/lot number. Ledger totals alone are insufficient.
4. **Transfer integrity** — Sprint 15.2 ship/receive moves quantity between locations but does not preserve lot identity across the OUT/IN pair.
5. **Future production usage** — Sprint 16 production material consumption will need to deduct stock from a specific batch, not only from aggregate location stock.

Sprint 15.3 introduces **batch/lot identity** as a first-class inventory concept while preserving the non-negotiable ADLMS invariant: stock quantity remains **ledger-derived only** (`SUM(quantity_in) - SUM(quantity_out)`), with no mutable stock columns on products or locations.

---

## Business Value for Dental Lab

| Capability | Operational benefit |
|---|---|
| Batch/lot capture on receive | Links supplier delivery documents (surat jalan, COA) to system stock |
| Expiry visibility | Reduces risk of using expired resin, ceramic liquid, or bonding agents |
| Location + batch stock view | Warehouse staff see not only “10 kg” but “Lot A-2024-03, expires 2026-12-01” |
| Transfer traceability | Ship/receive preserves the same batch identity across locations |
| Adjustment audit | Corrections reference the affected batch, not anonymous quantity |
| Production hook (future) | Enables “this crown used zirconia disc lot XYZ” without redesigning inventory |

Dental lab operators work under time pressure. Batch fields must be **optional per product** so fast-moving non-critical items (gloves, packaging) stay on the current simple receive flow.

---

## Current Inventory Behavior

### Ledger model (Sprint 12+)

```text
Branch → Inventory Location → Product → Movement Ledger (trx_inventory_movements)
```

Stock calculation (unchanged):

```text
current_stock(product, location) =
  SUM(quantity_in) - SUM(quantity_out)
  WHERE branch_id = active_branch
    AND product_id = product
    AND inventory_location_id = location
```

### Movement types in codebase

| Type | Workflow | Direction |
|---|---|---|
| `OPENING` | Opening stock form | IN |
| `PURCHASE` | Receive stock form | IN |
| `ADJUSTMENT_IN` | Adjustment in form | IN |
| `ADJUSTMENT_OUT` | Adjustment out form | OUT |
| `TRANSFER_OUT` | Stock transfer ship (15.2) | OUT |
| `TRANSFER_IN` | Stock transfer receive (15.2) | IN |

### Key service facts (codebase)

- `InventoryStockService` — `receiveStock()`, `adjustIn()`, `adjustOut()`, `createOpeningStock()` write single movement rows via `createInboundMovement()` / direct create; no batch concept.
- `StockTransferService` — `shipTransfer()` posts `TRANSFER_OUT`; `receiveTransfer()` posts `TRANSFER_IN`; `createTransferMovement()` sets `reference_type = trx_stock_transfers`.
- `StoreReceiveStockRequest` — validates location, quantity, unit_cost, supplier_id, notes only.
- Stock card (`InventoryMovementRepository::stockCard()`) eager-loads location, product, supplier, createdBy — no batch dimension.
- UI — `inventory/stock/_operation-form.blade.php` (Indonesian copy, teal/emerald operational forms).

### What is explicitly absent today

- No `batch_number`, `lot_number`, `expiry_date`, or `received_date` on movements or products.
- No batch-level stock query.
- No product flag indicating batch tracking requirement.
- Stock transfer items are product + quantity only (`trx_stock_transfer_items`).

---

## Target Batch/Lot Behavior

### Domain definitions

| Term | Meaning in ADLMS |
|---|---|
| **Batch** | A traceable inbound identity for a quantity of one product in one branch. Identified primarily by `batch_number` (operator-facing). |
| **Lot** | Secondary manufacturer/supplier lot identifier (`lot_number`). Optional when batch number alone is sufficient. |
| **Received date** | Calendar date the batch was first received into the lab (defaults to movement date on receive). |
| **Expiry date** | Optional shelf-life end date for the batch. Used for warnings and selection filtering — not auto-destruction in 15.3. |

### Core principles

1. **Ledger remains source of truth for quantity** — Batch records do not store `current_quantity` or any mutable balance.
2. **Batch identity is stable** — `inv_inventory_batches` holds descriptive attributes; movements reference `inventory_batch_id`.
3. **Nullable batch linkage** — Movements with `inventory_batch_id = NULL` behave exactly as today (backward compatible).
4. **Product-level opt-in** — `inv_products.requires_batch_tracking` gates whether batch fields are required on inbound/outbound operations.
5. **Branch isolation** — Batches belong to one branch; cross-branch batch references are rejected.
6. **Location-aware batch stock** — Batch quantity at a location is derived from movements with that `inventory_batch_id` at that `inventory_location_id`.
7. **Transfer preserves batch** — Ship OUT and receive IN for the same transfer line reference the **same** `inventory_batch_id`.
8. **No FIFO/FEFO auto-selection in 15.3** — Operators explicitly choose batch on outbound operations; automatic expiry-priority picking is a future enhancement.

### Batch stock derivation (new, additive)

```text
batch_stock(batch, location) =
  SUM(quantity_in) - SUM(quantity_out)
  WHERE branch_id = active_branch
    AND inventory_batch_id = batch
    AND inventory_location_id = location
```

Product-level stock remains the sum across all batches (and NULL-batch movements) at that location. Invariant:

```text
current_stock(product, location) >= SUM(batch_stock for all batches of product at location)
```

Equality holds when all movements for that product/location carry a batch_id. Legacy NULL-batch movements may cause product stock to exceed sum of batch stocks until historical data is migrated or excluded products stay non-batch.

---

## Design Options Evaluation

### Option A — Batch fields directly on `trx_inventory_movements`

Add `batch_number`, `lot_number`, `expiry_date`, `received_date` columns on each movement row.

| Pros | Cons |
|---|---|
| Fewer tables and joins | Duplicates batch metadata on every IN/OUT for the same lot |
| Simpler initial migration | Hard to enforce “same lot” across transfer OUT/IN pair |
| | Expiry/batch master queries require `DISTINCT` over movement history |
| | Lot corrections (typo fix) require updating many rows |
| | Violates normalization expected by other `inv_*` master tables |

### Option B — `inv_inventory_batches` + `inventory_batch_id` on movements (**preferred**)

Create a batch master table; movements optionally reference `inventory_batch_id`.

| Pros | Cons |
|---|---|
| Aligns with `inv_products`, `inv_suppliers` master pattern | Extra table, repository, and joins on stock card |
| Single source for batch attributes | Batch creation step on receive |
| Transfer OUT/IN share one batch FK trivially | Slightly more service complexity |
| Efficient expiry and batch lookup indexes | |
| Supports future production usage reference without schema churn | |

### Decision

**Adopt Option B (`inv_inventory_batches`)** because it fits the existing modular monolith architecture (master `inv_*` + transaction `trx_*`), preserves ledger-only quantity rules, and gives dental lab traceability without denormalizing batch metadata across movement history.

---

## Proposed Schema

### New table: `inv_inventory_batches`

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `id` | `bigIncrements` | No | PK |
| `branch_id` | `foreignId → mst_branches` | No | Branch ownership |
| `product_id` | `foreignId → inv_products` | No | Product this batch belongs to |
| `batch_number` | `string(100)` | No | Primary traceability code (internal or supplier) |
| `lot_number` | `string(100)` | Yes | Manufacturer/supplier lot |
| `received_date` | `date` | No | First receipt date |
| `expiry_date` | `date` | Yes | Shelf-life end (optional) |
| `supplier_id` | `foreignId → inv_suppliers` | Yes | Supplier at receipt (audit) |
| `notes` | `text` | Yes | COA reference, storage conditions |
| `created_by` | `foreignId → users` | Yes | Actor who created batch |
| `timestamps` | | | |

**Indexes and constraints:**

```text
INDEX (branch_id)
INDEX (product_id)
INDEX (expiry_date)
INDEX (branch_id, product_id)
INDEX (branch_id, expiry_date)
UNIQUE (branch_id, product_id, batch_number, lot_number)
  -- lot_number NULL treated as distinct in PostgreSQL unique semantics;
  -- use COALESCE(lot_number, '') in application validation for duplicate detection
```

**Forbidden columns on this table:**

- `current_stock`, `quantity_on_hand`, `available_quantity`, or any mutable balance.

### Alter table: `inv_products`

| Column | Type | Default | Purpose |
|---|---|---|---|
| `requires_batch_tracking` | `boolean` | `false` | When true, stock operations must supply batch context |

Additive only. Existing products default to non-batch behavior.

### Alter table: `trx_inventory_movements`

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `inventory_batch_id` | `foreignId → inv_inventory_batches` | Yes | Links movement to batch identity |

```text
INDEX (inventory_batch_id)
INDEX (branch_id, inventory_location_id, inventory_batch_id)
```

**No** `batch_number` / `lot_number` / `expiry_date` duplicated on movements.

### Alter table: `trx_stock_transfer_items` (batch-aware transfers)

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `inventory_batch_id` | `foreignId → inv_inventory_batches` | Yes | Required when product `requires_batch_tracking` |

Transfer document lines for batch-tracked products must identify which batch is being moved. Nullable for legacy/non-batch products.

### No changes to

- `trx_stock_transfers` header (status workflow unchanged from 15.2)
- `trx_stock_opnames` / `trx_stock_opname_items` (batch-aware opname deferred; see Out of Scope)
- Mutable stock columns anywhere

---

## Model Relationships

```text
Branch
  └── Product (requires_batch_tracking)
        └── InventoryBatch (inv_inventory_batches)
              └── InventoryMovement (many, via inventory_batch_id)

InventoryMovement
  ├── branch
  ├── inventoryLocation
  ├── product
  ├── supplier (nullable)
  ├── inventoryBatch (nullable)
  └── createdBy

StockTransferItem
  ├── stockTransfer
  ├── product
  └── inventoryBatch (nullable)
```

### New model: `InventoryBatch`

- Table: `inv_inventory_batches`
- Relations: `belongsTo` Branch, Product, Supplier, User (createdBy); `hasMany` InventoryMovement
- Scopes: `forBranch($branchId)`, `forProduct($productId)`, `expiringBefore($date)`, `notExpired($asOfDate)`
- **No** stock accessor that mutates; optional computed method delegates to repository `batchStock()`

### Updated model: `InventoryMovement`

- Add `inventory_batch_id` to `$fillable`
- Add `inventoryBatch()` `BelongsTo` relationship
- Movement types unchanged

### Updated model: `Product`

- Add `requires_batch_tracking` to `$fillable` / casts
- Add `batches()` `HasMany` InventoryBatch

### Updated model: `StockTransferItem`

- Add `inventory_batch_id` to `$fillable`
- Add `inventoryBatch()` `BelongsTo`

---

## Movement Integration

### When `inventory_batch_id` is set

| Movement type | Batch behavior |
|---|---|
| `OPENING` | Optional. If provided, batch must exist or be created in same transaction for batch-tracked products. |
| `PURCHASE` (receive) | **Creates** `InventoryBatch` row, then movement with FK. |
| `ADJUSTMENT_IN` | Optional batch. If product requires tracking, service may require batch selection or new batch creation (see Adjustment). |
| `ADJUSTMENT_OUT` | **Required** when `requires_batch_tracking = true`. Validates `batch_stock >= qty`. |
| `TRANSFER_OUT` | **Required** when product requires tracking. Uses line `inventory_batch_id`. |
| `TRANSFER_IN` | Same `inventory_batch_id` as corresponding OUT line. |

### When `inventory_batch_id` is NULL

Behavior identical to pre-15.3: aggregate location stock checks only.

### Aggregate invariants (service layer)

On every outbound movement with `inventory_batch_id`:

```text
batch_stock(batch, location) >= quantity_out
```

On every outbound movement (with or without batch):

```text
current_stock(product, location) >= quantity_out
```

Both checks run inside `DB::transaction()` with existing `lockForUpdate()` patterns on product and location.

---

## Receiving Stock Behavior

### Happy path (batch-tracked product)

1. Operator opens **Terima Stok** for product with `requires_batch_tracking = true`.
2. Form shows additional required fields: **Nomor Batch**, optional **Nomor Lot**, **Tanggal Terima**, optional **Tanggal Kedaluwarsa**.
3. `InventoryStockService::receiveStock()` (extended) inside transaction:
   - Validate branch, active product, active location, supplier if provided.
   - Validate batch uniqueness: `(branch_id, product_id, batch_number, lot_number)`.
   - Create `InventoryBatch` with `received_date` (default: today), `expiry_date`, `supplier_id`.
   - Create `PURCHASE` movement with `quantity_in = qty`, `inventory_batch_id = batch.id`.
4. Redirect with Indonesian success flash.

### Happy path (non-batch product)

Unchanged. `inventory_batch_id` remains NULL; no batch form fields shown.

### Validation rules (receive)

| Field | Rule |
|---|---|
| `batch_number` | Required if `requires_batch_tracking`; max 100; unique per branch+product+lot |
| `lot_number` | Optional; max 100 |
| `received_date` | Required if batch tracking; `date`; not in the future |
| `expiry_date` | Optional; `date`; must be `>= received_date` when provided |
| `quantity` | Required; `gt:0` (unchanged) |
| `inventory_location_id` | Required; active; same branch (unchanged) |

### Opening stock

Mirror receive batch creation when `requires_batch_tracking` and batch fields supplied. Batch fields optional when flag is false.

---

## Transfer Ship/Receive Behavior

Sprint 15.2 workflow states are unchanged. Batch tracking adds **line-level batch selection** and **movement FK propagation**.

### Draft / edit transfer

When adding items for batch-tracked products:

- UI requires **batch selector** populated from batches with `batch_stock(batch, source_location) > 0`.
- Store `inventory_batch_id` on `trx_stock_transfer_items`.
- Validate batch `product_id` matches line product.
- Validate batch `branch_id` matches transfer branch.

Non-batch products: `inventory_batch_id` NULL (unchanged).

### Ship (`shipTransfer`)

For each item with `inventory_batch_id`:

1. Assert `batch_stock(batch, source_location) >= item.quantity`.
2. Post `TRANSFER_OUT` with same `inventory_batch_id`.

Also retain existing aggregate `current_stock` check at source (defense in depth).

### Receive (`receiveTransfer`)

For each item:

1. Post `TRANSFER_IN` at destination with **same** `inventory_batch_id` as the line.
2. No new batch row created — identity moves with the lot.

### In-transit semantics

In-transit remains a **document state** (15.2). Batch quantity leaves source on ship (OUT posted) and appears at destination only after receive (IN posted). No separate in-transit ledger bucket.

### Example

Batch B1: 10 units at Warehouse. Transfer 4 to Production Room.

| Step | batch_stock(B1, Warehouse) | batch_stock(B1, Production) |
|---|---|---|
| After ship | 6 | 0 |
| After receive | 6 | 4 |

---

## Adjustment Behavior

### Adjustment OUT

| Product flag | Behavior |
|---|---|
| `requires_batch_tracking = false` | Unchanged — location aggregate check only |
| `requires_batch_tracking = true` | Require `inventory_batch_id` on request; validate batch stock at location |

### Adjustment IN

| Scenario | Behavior |
|---|---|
| Non-batch product | Unchanged |
| Batch-tracked, existing batch | Movement IN links to selected batch (e.g. found physical stock) |
| Batch-tracked, new batch | Service creates batch row (like receive) then posts `ADJUSTMENT_IN` — rare; UI copy warns “untuk koreksi dengan identitas lot baru” |

### Stock opname (Sprint 13)

**Out of scope for 15.3 implementation.** Opname finalization continues to post aggregate adjustments without batch. Document as follow-up: batch-aware opname variance in Sprint 15.4 or 16.

---

## Expiry Tracking

### Storage

`expiry_date` lives on `inv_inventory_batches` only.

### Queries (repository)

- `expiringBatches($branchId, $withinDays)` — batches where `expiry_date` between today and today+N.
- `expiredBatches($branchId)` — `expiry_date < today` and `batch_stock > 0` anywhere in branch.

### Operational rules (15.3)

| Rule | 15.3 behavior |
|---|---|
| Block receive with past expiry | **Warn** in UI; allow with confirmation (supplier edge cases) |
| Block OUT of expired batch | **Default block** with override note field out of scope — hard block in service |
| Dashboard widget | Optional “Batch mendekati kedaluwarsa” count on inventory dashboard |
| Stock card | Show expiry column when movement has batch |

### Display

Indonesian labels: **Kedaluwarsa**, **Mendekati Kedaluwarsa** (≤ 30 hari), badge colors per `docs/ui_design_system.md` semantic badges.

---

## Branch Isolation Rules

1. **Batch creation:** `branch_id` from `BranchContext::requireId()` — never from request.
2. **Batch lookup:** `InventoryBatchRepository::findInBranch($branchId, $id)`.
3. **Product-batch consistency:** `batch.product_id` must match movement `product_id`; `batch.branch_id` must match movement `branch_id`.
4. **Cross-branch denial:** Policy and service reject batch FK from another branch (403 / validation error).
5. **Transfer lines:** Batch must belong to active branch and source location must hold sufficient batch stock.
6. **List/filter endpoints:** All batch lists scoped by `branch_id`.

### Sprint Consistency Check

| Sprint | Requirement | 15.3 compliance |
|---|---|---|
| 10 | Branch via `BranchContext` | Yes |
| 11 | Branch-scoped queries | Yes — new batch repository |
| 12 | Ledger-derived stock | Yes — quantity still from movements only |
| 15.2 | Ship/receive workflow | Yes — workflow unchanged; batch FK added to movements |

---

## Validation Rules

### `StoreReceiveStockRequest` (extended)

Existing rules plus:

```php
'batch_number' => ['required_if:requires_batch_tracking,true', 'nullable', 'string', 'max:100'],
'lot_number' => ['nullable', 'string', 'max:100'],
'received_date' => ['required_if:requires_batch_tracking,true', 'nullable', 'date', 'before_or_equal:today'],
'expiry_date' => ['nullable', 'date', 'after_or_equal:received_date'],
```

Server-side must re-check `requires_batch_tracking` from loaded product — never trust client flags alone.

### `StoreAdjustOutRequest` / `StoreAdjustInRequest` (extended)

- `inventory_batch_id` — `required_if` product requires tracking; `exists:inv_inventory_batches,id` plus service branch/product match.

### Stock transfer item validation

- `inventory_batch_id` required when product requires tracking on create/update transfer.
- Custom rule: sufficient batch stock at source location at ship time (service), not only at draft save time.

### Error messages (Indonesian, consistent with existing inventory copy)

| Key | Message |
|---|---|
| batch required | `Nomor batch wajib diisi untuk produk ini.` |
| batch stock insufficient | `Stok batch pada lokasi ini tidak mencukupi.` |
| duplicate batch | `Kombinasi nomor batch dan lot sudah digunakan untuk produk ini.` |
| expired batch | `Batch ini sudah kedaluwarsa dan tidak dapat dikeluarkan.` |
| invalid batch branch | `Batch tidak valid untuk cabang aktif.` |

---

## UI Changes

All copy in **Bahasa Indonesia**. Follow `docs/ui_design_system.md` and patterns from `inventory/stock/_operation-form.blade.php` and `inventory/products/index`.

### Product form (`inv_products` create/edit)

- Toggle: **Pelacakan Batch/Lot** (`requires_batch_tracking`).
- Help text: “Aktifkan untuk bahan yang wajib memiliki nomor batch/lot dan tanggal kedaluwarsa.”

### Receive stock / opening stock forms

Extend `_operation-form.blade.php` (or batch partial) when `$product->requires_batch_tracking`:

| Field | Label |
|---|---|
| `batch_number` | Nomor Batch * |
| `lot_number` | Nomor Lot |
| `received_date` | Tanggal Terima * |
| `expiry_date` | Tanggal Kedaluwarsa |

Show only when flag is true. Preserve teal/emerald button tones and ledger banner.

### Product show page

New section **Stok per Batch** (when tracking enabled):

- Desktop table / mobile cards: Batch, Lot, Lokasi, Jumlah, Kedaluwarsa, Status badge.
- Data from service `getBatchStockRows($productId, ?$locationId)` — precomputed in controller.

### Stock card

Add columns: **Batch**, **Lot**, **Kedaluwarsa** (from `movement.inventoryBatch`).

Optional filter: by batch.

### Stock transfer create/edit

For batch-tracked line items:

- Batch dropdown filtered to source location with stock > 0.
- Show expiry in dropdown: `B-2024-001 · Lot L12 · exp 2026-05-30 · stok 5`.

### Stock transfer show

Display batch on each line. Ledger panel shows batch on OUT/IN movements.

### Adjustment forms

Batch selector when product requires tracking (OUT mandatory, IN optional/create).

### Inventory dashboard (optional slice)

Widget: **Batch Mendekati Kedaluwarsa** — count from `expiringBatches(30)`.

### Sidebar

No new top-level menu in 15.3. Batch context lives inside existing inventory screens (per frontend-design: avoid menu sprawl).

### Permissions

Reuse `view_inventory` / `manage_inventory`. No new permissions unless audit export is added later.

---

## Test Plan

New and updated tests under `tests/Feature/Inventory/`.

### Batch model / repository

| Scenario | Expected |
|---|---|
| Create batch in branch | Persisted with branch_id |
| findInBranch cross-branch | Returns null / 404 |
| Unique batch+lot per product per branch | Duplicate rejected |

### Receive stock

| Scenario | Expected |
|---|---|
| Batch-tracked product happy path | Batch row + PURCHASE movement with FK |
| Non-batch product | movement.inventory_batch_id NULL |
| Duplicate batch_number+lot | Validation error |
| expiry_date < received_date | Validation error |
| Inactive product/location | Rejected (existing) |

### Adjustment OUT / IN

| Scenario | Expected |
|---|---|
| Batch-tracked OUT with sufficient batch stock | Movement with FK; batch stock decreases |
| Batch-tracked OUT insufficient batch stock | Validation error |
| Batch-tracked OUT without batch_id | Validation error |
| Non-batch OUT | Unchanged behavior |

### Stock transfer

| Scenario | Expected |
|---|---|
| Draft with batch line | inventory_batch_id saved on item |
| Ship posts OUT with batch FK | Source batch stock reduced |
| Receive posts IN with same FK | Destination batch stock increased |
| Ship insufficient batch stock | Validation error (even if aggregate stock OK) |
| Non-batch transfer | Unchanged 15.2 behavior |

### Branch isolation

| Scenario | Expected |
|---|---|
| Receive referencing batch from other branch | 403 / validation |
| Transfer item with foreign batch | Rejected |

### Ledger correctness

| Scenario | Expected |
|---|---|
| Product stock equals sum of batch stocks | After batch-only receive chain |
| Legacy NULL-batch movements | Product stock still correct |
| Expired batch OUT | Blocked |

### UI tests

| Scenario | Expected |
|---|---|
| Batch fields visible only when flag true | Receive form |
| Indonesian labels present | Batch, Lot, Kedaluwarsa |
| Transfer batch selector | Shown for tracked products |

### Quality gates (definition of done)

```bash
php artisan test --filter=Inventory
php artisan test --filter=Batch
php artisan test
./vendor/bin/pint --test
php artisan route:list --name=inventory
npm run build
```

---

## Migration Plan

### Migration 1 — `create_inv_inventory_batches_table`

Create `inv_inventory_batches` with indexes and unique constraint as specified.

### Migration 2 — `add_batch_tracking_columns`

1. Add `requires_batch_tracking` to `inv_products` (default `false`).
2. Add `inventory_batch_id` nullable FK to `trx_inventory_movements`.
3. Add `inventory_batch_id` nullable FK to `trx_stock_transfer_items`.

All additive. No drops. No data backfill required for existing movements.

### Migration 3 (optional seed) — demo products

In dev seeders only: mark sample dental materials `requires_batch_tracking = true` for UAT. Not required for production.

### Deployment order

1. Run migrations.
2. Deploy application code (models, services, UI).
3. Operators enable `requires_batch_tracking` per product when ready — **no forced big-bang**.

---

## Backward Compatibility

| Area | Compatibility |
|---|---|
| Existing movements | `inventory_batch_id` NULL — stock calculations unchanged |
| Products | Default `requires_batch_tracking = false` — all existing flows identical |
| Stock transfer | Lines without batch_id work under 15.2 rules |
| Stock card / dashboard | Batch columns show “—” when NULL |
| API/request clients | New fields optional unless product flag enabled |
| Inventory valuation | Unchanged — still `derived_stock × average_cost` |
| Low stock alerts | Unchanged — aggregate stock |

### Product flag rollout strategy

Enable batch tracking per product when staff are trained. Mixed mode (some batch, some not) is supported indefinitely.

---

## Rollback Considerations

### Application rollback

Revert deploy. Nullable FK columns are safe if new code is removed — old code ignores `inventory_batch_id`.

### Migration rollback

Down migrations:

1. Drop FK + column `inventory_batch_id` from `trx_stock_transfer_items`.
2. Drop FK + column `inventory_batch_id` from `trx_inventory_movements`.
3. Drop `requires_batch_tracking` from `inv_products`.
4. Drop `inv_inventory_batches`.

**Data loss warning:** Rolling back after batches were created loses batch master data. Export batch table before rollback in production.

### Partial rollback

Cannot remove batch columns while keeping batch-aware code. Treat as atomic release.

---

## Out of Scope (Sprint 15.3)

- FIFO/FEFO automatic batch picking on outbound.
- Purchase order module integration (Sprint 15 purchasing slice).
- Production material usage posting (Sprint 16).
- Batch-aware stock opname finalization.
- Barcode/QR scanning.
- Inter-branch batch transfer (still same-branch only).
- New movement types (`PRODUCTION_USAGE`, `SALES_ISSUE`).
- Accounting-grade FIFO/LIFO costing.

---

## Definition of Done

Sprint 15.3 is complete when:

1. `inv_inventory_batches` table and `InventoryBatch` model exist with branch-scoped repository.
2. `trx_inventory_movements.inventory_batch_id` and `inv_products.requires_batch_tracking` deployed.
3. Receive stock creates batch + linked PURCHASE movement for tracked products.
4. Adjustment OUT (and optional IN) respect batch stock for tracked products.
5. Stock transfer ship/receive propagates `inventory_batch_id` on movements and items.
6. Expired batch OUT is blocked by service rule.
7. Product show and stock card display batch/lot/expiry in Indonesian UI.
8. All new tests pass; existing Inventory and StockTransfer suites remain green.
9. Quality gates executed and reported honestly.
10. `docs/sprint_history.md` updated with Sprint 15.3 decisions.

---

## Implementation Phases (recommended)

| Phase | Deliverable |
|---|---|
| 15.3.1 | Migrations, models, batch repository, factory |
| 15.3.2 | `InventoryStockService` receive/opening/adjust + requests |
| 15.3.3 | `StockTransferService` + transfer UI batch lines |
| 15.3.4 | Product flag UI, batch stock views, stock card columns |
| 15.3.5 | Expiry queries, dashboard widget (optional), tests + docs |

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Mixed NULL-batch and batch movements skew reconciliation | Product stock ≠ sum of batch stocks | Document invariant; report “untracked stock” on product show; gradual product flag rollout |
| Operators skip batch entry under time pressure | Weak traceability | Require batch only when flag enabled; clear validation messages |
| Transfer draft saved but batch stock consumed before ship | Ship failure | Re-validate batch stock at ship; show fresh quantities in UI |
| Expiry hard-block frustrates users | Workarounds outside system | Allow adjust-in with new batch for legitimate exceptions; audit via notes |
| Performance on batch stock aggregates | Slow selectors | Index `(branch_id, inventory_location_id, inventory_batch_id)` on movements; repository SQL aggregates |
| Scope creep into production usage | Delayed delivery | Strict out-of-scope list; design FK ready for Sprint 16 |

---

## Files Expected (implementation reference)

| Area | Files |
|---|---|
| Migrations | `create_inv_inventory_batches_table`, `add_batch_tracking_to_inventory_tables` |
| Models | `InventoryBatch.php`; update `InventoryMovement`, `Product`, `StockTransferItem` |
| Interfaces/Repos | `InventoryBatchRepositoryInterface`, `InventoryBatchRepository` |
| Services | `InventoryStockService`, `StockTransferService`, new `InventoryBatchService` (optional facade) |
| Requests | `StoreReceiveStockRequest`, adjust requests, stock transfer item requests |
| Controllers | `InventoryStockController`, `StockTransferController`, `ProductController` |
| Views | `inventory/stock/_operation-form`, `inventory/products/show`, `inventory/stock-transfers/*`, stock card partial |
| Tests | `InventoryBatchTest`, `ReceiveStockBatchTest`, `StockTransferBatchTest`, `AdjustOutBatchTest` |
| Provider | `RepositoryServiceProvider` binding |

---

## Assumptions

1. PostgreSQL unique NULL handling for `lot_number` is acceptable with application-level duplicate checks using normalized empty string for NULL lots.
2. One batch record represents one inbound identity; splitting a batch into two identities requires adjustment workflows (not automatic split).
3. Dental lab users accept per-product opt-in rather than mandatory global batch tracking.
4. Sprint 15.2 ship/receive service structure remains the integration point for transfer batch propagation.
5. Quality gate results will be reported only after implementation — none claimed in this design phase.

---

*Design only — no application code changed in this document.*
