# Sprint 15.4 Technical Design — Reorder Point & Inventory Alerts

Date: 2026-06-06
Status: DESIGN (not implemented)
Module: `app/Modules/Inventory`
Prerequisites: Sprint 12 Inventory Core (complete), Sprint 13 Stock Opname (complete), Sprint 15.1 Stock Transfer (complete), Sprint 15.2 Transfer Receiving (complete), Sprint 15.3 Batch & Lot Tracking (complete)

## Sprint Numbering Note

The repository roadmap memory (`.cursor/memory/sprint-roadmap-13-20.md`) originally placed low-stock alerts and reorder planning in **Sprint 17**. Active milestone planning for the current inventory slice labels this work **Sprint 15.4** immediately after batch/lot tracking.

| Planning slice | Dependency |
|---|---|
| Sprint 15.1 | Stock Transfer document workflow |
| Sprint 15.2 | Ship/receive ledger split |
| Sprint 15.3 | Batch/lot identity on ledger movements |
| Sprint 15.4 | This document — reorder configuration and unified inventory alerts |

Implementation must not break Sprint 15.3 batch contracts, Sprint 12 ledger rules, or existing inventory dashboard KPIs without an explicit migration path documented below.

---

## Problem Statement

ADLMS inventory correctly derives **how much** stock exists per branch and location from the movement ledger (`trx_inventory_movements`). Sprint 12 introduced `minimum_stock` on products and a basic low-stock widget, but operators still lack a complete **alerting and replenishment** layer:

1. **No reorder planning fields** — Staff cannot configure a reorder point (when to act) or reorder quantity (how much to order) per product. `minimum_stock` alone is a single threshold with no suggested order size.
2. **No severity tiers** — The UI collapses “menipis” (low) and “habis” (out) into one widget. Production-critical materials need a distinct **critical** band between low and zero.
3. **Batch expiry alerts are fragmented** — Sprint 15.3 added batch expiry metadata and batch index filters, but there is no unified alert surface combining stock thresholds and batch expiry risks.
4. **No dedicated alert dashboard** — Operators must visit stock index, batch index, and product forms separately to understand what needs attention today.
5. **Owner/branch visibility gap** — Management needs a scannable, branch-safe alert summary without mutable stock columns or cross-branch leakage.

Sprint 15.4 introduces **product-level reorder configuration** and a **computed alert engine** that surfaces low, critical, out-of-stock, expired-batch, and expiring-soon-batch conditions — while preserving the non-negotiable ADLMS invariant: stock quantity remains **ledger-derived only** (`SUM(quantity_in) - SUM(quantity_out)`), with no mutable stock columns.

---

## Business Value for Dental Lab

| Capability | Operational benefit |
|---|---|
| Reorder point per product | Warehouse staff know when to initiate purchase before production stalls |
| Reorder quantity hint | Reduces guesswork on how much resin, zirconia, alloy, or ceramic liquid to reorder |
| Critical stock tier | Highlights materials that can block same-day production (e.g. last shade guide, final disc) |
| Out-of-stock visibility | Prevents technicians discovering missing consumables mid-case |
| Expired batch alerts | Reduces compliance and quality risk from using expired bonding agents or liquids |
| Expiring-soon warnings | Enables FEFO-style rotation before hard expiry blocks outbound use (Sprint 15.3) |
| Unified alert dashboard | Single morning checklist for inventory coordinators across branches |
| Branch-safe filtering | Each lab branch sees only its own alerts — no data leakage |

Dental labs run lean inventory with high SKU variety. Alerts must be **actionable, not noisy**: inactive products and zero-threshold products should not flood the dashboard.

---

## Current Inventory Baseline

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

current_stock(product, branch) =
  SUM(quantity_in) - SUM(quantity_out)
  WHERE branch_id = active_branch
    AND product_id = product
```

### Existing low-stock behavior (Sprint 12)

| Component | Behavior |
|---|---|
| `inv_products.minimum_stock` | Single numeric threshold per product (default 0) |
| `InventoryMovementRepository::lowStockProducts()` | Active products where derived branch stock `<= minimum_stock` |
| `InventoryStockService::getBranchSummary()` | Returns `low_stock_count` and `out_of_stock_count` (subset of low stock where stock `<= 0`) |
| `_low-stock-badge.blade.php` | **Habis** (≤ 0), **Menipis** (≤ minimum), **Aman** (else) |
| `inventory/dashboard` | KPI cards + `low-stock-widget` listing top low-stock products |

### Batch expiry baseline (Sprint 15.3)

| Component | Behavior |
|---|---|
| `inv_inventory_batches.expiry_date` | Optional shelf-life date on batch master |
| `InventoryBatchService::EXPIRING_SOON_DAYS` | Fixed 30-day threshold |
| `InventoryBatchRepository` | Index filters: `expired`, `expiring_soon`, `valid` |
| Outbound guard | Expired batches hard-blocked on adjust out / transfer ship |
| Batch UI | Read-only index/show under Persediaan sidebar |

### What is explicitly absent today

- No `reorder_point` or `reorder_quantity` on products.
- No `critical_stock` severity tier distinct from `minimum_stock`.
- No unified alert type taxonomy or alert dashboard route.
- No location-scoped alert aggregation on a dedicated page (stock index supports location filter separately).
- No owner-dashboard alert integration beyond placeholder references in `docs/ui_owner_dashboard_design.md`.
- No alert persistence, acknowledgment, or notification channel (email/push).

---

## Target Behavior

### Domain definitions

| Term | Meaning in ADLMS 15.4 |
|---|---|
| **Reorder point** | Derived-stock level at or below which replenishment should be initiated for a product |
| **Reorder quantity** | Suggested purchase/receive quantity when reorder point is breached (informational only in 15.4) |
| **Critical stock** | Optional lower threshold indicating severe production risk (above zero, below low) |
| **Low stock** | Derived stock at or below reorder point but above critical band |
| **Critical stock alert** | Derived stock at or below critical threshold but greater than zero |
| **Out of stock** | Derived stock less than or equal to zero |
| **Expired batch** | Batch with `expiry_date < today` and positive derived batch stock in branch |
| **Expiring soon batch** | Batch with `expiry_date` within 30 days (inclusive) and positive derived batch stock |
| **Alert** | A computed, read-only condition — never a mutable counter |

### Core principles

1. **Ledger remains source of truth for quantity** — Alerts are calculated from `trx_inventory_movements` aggregates; no `current_stock` columns.
2. **Product-level reorder settings** — `reorder_point`, `reorder_quantity`, and optional `critical_stock` live on `inv_products`.
3. **Backward compatible thresholds** — When `reorder_point` is null or zero, fall back to `minimum_stock` for effective reorder point.
4. **Branch isolation** — All alert queries scoped by `BranchContext::requireId()`; owner cross-branch views are out of scope for 15.4.
5. **Optional location filter** — Dashboard and alert list accept `?inventory_location_id=` validated against active branch.
6. **Active products only** — Inactive products excluded from stock alerts.
7. **Batch alerts require stock** — Expired/expiring-soon only reported when derived `batch_stock > 0` somewhere in branch (or location when filtered).
8. **No purchase order workflow** — `reorder_quantity` is a planning hint; PO/receiving automation is a future sprint.
9. **No alert persistence** — 15.4 computes alerts on read; no `trx_inventory_alerts` table unless a later sprint adds acknowledgment.
10. **Indonesian operator copy** — UI labels follow existing inventory Bahasa Indonesia conventions.

### Effective threshold resolution

```text
effective_reorder_point(product) =
  COALESCE(NULLIF(product.reorder_point, 0), product.minimum_stock)

effective_critical_stock(product) =
  COALESCE(
    NULLIF(product.critical_stock, 0),
    FLOOR(effective_reorder_point(product) * 0.25)
  )
  -- when effective_reorder_point is 0, critical defaults to 0 (only OOS tier applies)
```

Products with `effective_reorder_point = 0` are **excluded** from low/critical stock alerts (no threshold configured). They may still appear in out-of-stock lists if stock is zero and `alert_enabled` is true (see rules below).

### Stock alert severity (branch scope, optional location filter)

Let `S` = derived stock for product (branch total or location-specific when filtered).

| Severity | Code | Condition | Badge (ID) |
|---|---|---|---|
| OK | `ok` | `S > effective_reorder_point` OR product excluded | Aman |
| Low | `low` | `S > effective_critical_stock` AND `S <= effective_reorder_point` | Menipis |
| Critical | `critical` | `S > 0` AND `S <= effective_critical_stock` | Kritis |
| Out of stock | `out_of_stock` | `S <= 0` | Habis |

**Ordering for dashboard:** `out_of_stock` → `critical` → `low` (most severe first), then product name.

### Batch expiry alert severity

Let `B` = batch with `expiry_date` not null and `batch_stock(branch)` or `batch_stock(branch, location)` > 0.

| Severity | Code | Condition | Badge (ID) |
|---|---|---|---|
| Expired | `batch_expired` | `expiry_date < today` | Kedaluwarsa |
| Expiring soon | `batch_expiring_soon` | `today <= expiry_date <= today + 30 days` | Mendekati Kedaluwarsa |

Batches without `expiry_date` never generate expiry alerts. Reuse `InventoryBatchService::EXPIRING_SOON_DAYS = 30` constant — do not duplicate magic numbers.

---

## Data Model Impact

### Migration: extend `inv_products`

Additive columns only:

| Column | Type | Default | Purpose |
|---|---|---|---|
| `reorder_point` | `decimal(12,2)` nullable | `null` | Replenishment trigger level; null/0 → use `minimum_stock` |
| `reorder_quantity` | `decimal(12,2)` nullable | `null` | Suggested order qty when reorder point breached |
| `critical_stock` | `decimal(12,2)` nullable | `null` | Optional critical band; null → derive 25% of effective reorder point |
| `alert_enabled` | `boolean` | `true` | When false, exclude product from stock alert surfaces (still visible in stock reports) |

**Forbidden:** Any mutable stock, alert count, or `last_alert_at` columns on products, locations, or batches.

### No new transactional tables (15.4 scope)

Alerts are computed views over existing tables:

- `inv_products` — thresholds
- `trx_inventory_movements` — derived stock
- `inv_inventory_batches` — expiry metadata
- `inv_inventory_locations` — optional filter

### Model updates

`Product` model:

- Add fillable/casts for new columns.
- Optional accessor `effective_reorder_point` (computed in service, not DB column).

### Index awareness

Existing indexes sufficient for alert queries:

- `trx_inventory_movements (branch_id, product_id)`
- `trx_inventory_movements (branch_id, inventory_location_id, product_id)`
- `inv_inventory_batches (branch_id, expiry_date)` — add index on `(branch_id, expiry_date)` if explain plans show seq scans at scale (optional follow-up migration).

---

## Alert Calculation Rules

### Service: `InventoryAlertService` (new)

Centralizes all alert logic. Injected dependencies:

- `InventoryMovementRepositoryInterface` (or extend with alert-specific queries)
- `InventoryBatchRepositoryInterface`
- `BranchContext`
- `InventoryLocationRepositoryInterface` (location filter validation)

Public methods (proposed):

| Method | Returns |
|---|---|
| `getStockAlerts(?int $locationId, ?string $severityFilter)` | Collection of stock alert DTOs |
| `getBatchExpiryAlerts(?int $locationId, ?string $severityFilter)` | Collection of batch alert DTOs |
| `getAlertSummary(?int $locationId)` | Counts by severity/type for KPI cards |
| `getUnifiedAlerts(?int $locationId, array $filters)` | Merged, sorted list for dashboard table |

Each DTO shape (array or readonly class):

```php
// Stock alert
[
    'type' => 'stock',
    'severity' => 'low|critical|out_of_stock',
    'product_id' => int,
    'product_code' => string,
    'product_name' => string,
    'unit_symbol' => string|null,
    'current_stock' => float,
    'effective_reorder_point' => float,
    'effective_critical_stock' => float,
    'reorder_quantity' => float|null,
    'inventory_location_id' => int|null, // null = branch aggregate
    'inventory_location_name' => string|null,
]

// Batch alert
[
    'type' => 'batch',
    'severity' => 'batch_expired|batch_expiring_soon',
    'inventory_batch_id' => int,
    'batch_number' => string,
    'lot_number' => string|null,
    'product_id' => int,
    'product_name' => string,
    'expiry_date' => string,
    'days_until_expiry' => int,
    'batch_stock' => float, // branch total or location when filtered
    'inventory_location_id' => int|null,
    'inventory_location_name' => string|null,
]
```

### Stock alert SQL strategy

Extend repository with a single aggregate query pattern (same as `lowStockProducts`):

```sql
-- Pseudologic
WITH stock AS (
  SELECT product_id,
         SUM(quantity_in) - SUM(quantity_out) AS current_stock
  FROM trx_inventory_movements
  WHERE branch_id = :branchId
    AND (:locationId IS NULL OR inventory_location_id = :locationId)
  GROUP BY product_id
)
SELECT p.*, COALESCE(stock.current_stock, 0) AS current_stock
FROM inv_products p
LEFT JOIN stock ON stock.product_id = p.id
WHERE p.branch_id = :branchId
  AND p.is_active = true
  AND p.alert_enabled = true
  AND (
    COALESCE(NULLIF(p.reorder_point, 0), p.minimum_stock) > 0
    AND COALESCE(stock.current_stock, 0) <= COALESCE(NULLIF(p.reorder_point, 0), p.minimum_stock)
    OR COALESCE(stock.current_stock, 0) <= 0
  )
```

Severity classification happens in service layer PHP for clarity and testability.

### Batch expiry SQL strategy

Reuse `InventoryBatchRepository` patterns:

```text
expired_batch_alert(batch) =
  batch.expiry_date IS NOT NULL
  AND batch.expiry_date < today
  AND batch.is_active = true
  AND batch_stock(batch, branch[, location]) > 0

expiring_soon_batch_alert(batch) =
  batch.expiry_date IS NOT NULL
  AND batch.expiry_date >= today
  AND batch.expiry_date <= today + 30 days
  AND batch.is_active = true
  AND batch_stock(batch, branch[, location]) > 0
```

### Performance rules

- One aggregate query for stock alerts; one for batch alerts — avoid N+1 per product.
- Paginate unified alert list (default 25, max 100).
- Eager-load `product.unit`, `inventoryLocation` only when rendering.
- Cache not required in 15.4; revisit if dashboard latency exceeds pilot threshold.

---

## Reorder Point Rules

### Configuration

| Rule | Detail |
|---|---|
| Scope | Per product, per branch (`inv_products.branch_id`) |
| `reorder_point` | Nullable, numeric, `min:0` |
| Effective value | `COALESCE(NULLIF(reorder_point, 0), minimum_stock)` |
| Zero effective | Product excluded from low/critical tiers; may still trigger OOS if stock ≤ 0 |
| Edit permission | `manage_inventory` on product update |
| Validation | `reorder_point >= critical_stock` when both explicitly set and non-zero |

### Operational meaning

When derived stock crosses at or below effective reorder point:

- Product appears in **low** or more severe stock alert tier.
- Dashboard shows `reorder_quantity` as **Saran pesanan** when configured.
- No automatic PO, email, or movement is created in 15.4.

### Migration from `minimum_stock`

Existing products continue to work unchanged:

- `reorder_point` null → effective reorder point = `minimum_stock`.
- Product forms show both fields with help text: *“Stok minimum (legacy) digunakan jika titik pesan ulang kosong.”*
- Long-term: operators may set `reorder_point` explicitly and treat `minimum_stock` as deprecated UI label — removal is out of scope for 15.4.

---

## Reorder Quantity Rules

### Configuration

| Rule | Detail |
|---|---|
| Scope | Per product, per branch |
| `reorder_quantity` | Nullable, numeric, `min:0` |
| Required when | Never required in 15.4 — optional planning hint |
| Display | Shown on alert rows and product show when `severity` is low/critical/OOS |
| Validation | If set, must be `> 0` |

### Operational meaning

Informational only:

- Answers “how much should we order?” on the alert dashboard.
- Does not reserve stock, create draft PO, or post ledger movements.
- Future Sprint 15.x/16+ purchase workflow may consume this field.

---

## Batch Expiry Rules

### Alert inclusion

| Condition | Include in alerts? |
|---|---|
| `expiry_date` is null | No |
| `is_active = false` | No |
| Derived batch stock = 0 | No (empty lot — no operational risk) |
| Expired + stock > 0 | Yes — `batch_expired` |
| Expiring within 30 days + stock > 0 | Yes — `batch_expiring_soon` |

### Relationship to outbound guards (Sprint 15.3)

- **Alerts** inform operators proactively.
- **Outbound hard block** on expired batches remains unchanged in `InventoryStockService`.
- Expiring-soon batches are **not** blocked on outbound — alert only.

### Location filter behavior

When `inventory_location_id` filter is set:

- Batch stock computed for that location only.
- Batch appears if any location stock > 0 when unfiltered; when filtered, only that location’s batch stock counts.

### Threshold configurability

Expiring-soon window remains **30 days** (constant shared with `InventoryBatchService`). Per-product or per-branch threshold configuration is **out of scope** for 15.4 — document as follow-up.

---

## Branch Isolation Rules

1. **Alert queries:** Every query includes `where('branch_id', $branchId)` from `BranchContext::requireId()`.
2. **Location filter:** `inventory_location_id` validated via `findInBranch($branchId, $id)` before use.
3. **Product-batch consistency:** Batch alerts only for batches where `batch.branch_id = active branch`.
4. **Cross-branch denial:** Policy rejects foreign branch product/batch/location IDs (403 / validation error).
5. **Dashboard data:** No aggregation across branches in 15.4 inventory alert dashboard.
6. **Owner dashboard:** Cross-branch alert rollup deferred — `docs/ui_owner_dashboard_design.md` updated in implementation step, not in this design-only slice.

### Sprint Consistency Check

| Sprint | Requirement | 15.4 compliance |
|---|---|---|
| 10 | Branch via `BranchContext` | Yes |
| 11 | Branch-scoped queries | Yes — all alert repository methods |
| 12 | Ledger-derived stock | Yes — no mutable stock columns |
| 15.3 | Batch expiry metadata | Yes — alerts read `inv_inventory_batches.expiry_date` |

---

## Permission Rules

### Existing permissions (no new permission in 15.4)

| Permission | Alert capability |
|---|---|
| `view_inventory` | View alert dashboard, widgets, read reorder fields on product show |
| `manage_inventory` | Edit `reorder_point`, `reorder_quantity`, `critical_stock`, `alert_enabled` on product forms |

### Policy

| Resource | Policy | Rules |
|---|---|---|
| `InventoryMovement` | `InventoryMovementPolicy::viewAny` | Reuse for alert dashboard index (same as inventory dashboard) |
| `Product` | `ProductPolicy::update` | Required to change reorder fields |

No new `InventoryAlertPolicy` — alerts are not persisted models. Controller authorizes via existing inventory view permission.

### UI gates

- Sidebar link **Peringatan Stok** visible under `@can('viewAny', InventoryMovement::class)` (consistent with inventory dashboard).
- Reorder fields on product form wrapped in `@can('update', $product)`.

---

## UI Plan

All copy in **Bahasa Indonesia**. Follow `docs/ui_design_system.md`, `inventory/dashboard.blade.php`, and `inventory/products/index` patterns. Teal primary, semantic badges, dual desktop-table / mobile-card layouts.

### Product form (`inventory/products/_form.blade.php`)

New section **Pengaturan Peringatan & Pesanan Ulang**:

| Field | Label | Help text |
|---|---|---|
| `reorder_point` | Titik Pesan Ulang | “Stok turun ke level ini → tindakan pesan ulang disarankan. Kosongkan untuk memakai stok minimum.” |
| `reorder_quantity` | Jumlah Pesan Ulang | “Saran jumlah saat titik pesan ulang tercapai (informasi saja).” |
| `critical_stock` | Stok Kritis | “Opsional. Di bawah level ini → peringatan kritis. Kosongkan untuk hitung otomatis (25% titik pesan).” |
| `alert_enabled` | Aktifkan peringatan | Toggle — exclude from alert dashboard when off |
| `minimum_stock` | Stok Minimum (legacy) | Retain existing field; help text notes fallback behavior |

### Product show page

New panel **Pengaturan Pesanan Ulang** showing configured values and effective thresholds (precomputed in controller).

### New route: Inventory Alert Dashboard

| Method | URI | Name |
|---|---|---|
| GET | `inventory/alerts` | `inventory.alerts.index` |

**Controller:** `InventoryAlertController@index` (thin — authorize, call `InventoryAlertService`, return view).

**View:** `inventory/alerts/index.blade.php` inside `<x-settings-shell>`.

Page structure:

1. **Header** — title “Peringatan Persediaan”, description explaining ledger-derived alerts.
2. **Filters** — location dropdown (active branch locations), severity/type filter (all / stock / batch / per severity), preserve query string on pagination.
3. **KPI strip** — counts: Stok Habis, Stok Kritis, Stok Menipis, Batch Kedaluwarsa, Batch Mendekati Kedaluwarsa.
4. **Unified alert table** (desktop) / cards (mobile):
   - Columns: Severity badge, Tipe, Produk/Batch, Lokasi, Stok saat ini / Kedaluwarsa, Titik pesan, Saran pesanan, Aksi (link to product show, batch show, terima stok).
5. **Empty state** — “Tidak ada peringatan aktif untuk cabang ini.”

### Badge components

| Component | Purpose |
|---|---|
| `inventory/alerts/_stock-severity-badge` | Habis / Kritis / Menipis |
| Reuse `inventory/batches/_batch-status-badge` | Kedaluwarsa / Mendekati Kedaluwarsa |

### Sidebar (`resources/views/layouts/sidebar.blade.php`)

Under **Persediaan**, after **Batch & Lot**:

```text
Peringatan Stok → route('inventory.alerts.index')
```

Permission: `@can('viewAny', InventoryMovement::class)`.

### Inventory dashboard enhancements (`inventory/dashboard.blade.php`)

1. Replace or extend KPI row with **Stok Kritis** count (new).
2. Add KPI **Batch Kedaluwarsa** and **Batch Mendekati Kedaluwarsa** (from `getAlertSummary()`).
3. Add widget `x-inventory.alert-summary-widget` linking to `inventory.alerts.index`.
4. Update `low-stock-widget` to show three-tier badges (Habis / Kritis / Menipis) using new severity logic.
5. Optional compact **Batch peringatan** table (top 5 expired/expiring) linking to alert dashboard.

### Stock index (`inventory/stock/index.blade.php`)

- Update summary cards to use new severity counts from `InventoryAlertService`.
- Align `_low-stock-badge` partial with critical tier (or delegate to shared severity badge).

---

## Dashboard Widget Plan

| Widget | Location | Data source | Link |
|---|---|---|---|
| Alert KPI strip (5 counts) | `inventory/alerts/index` | `getAlertSummary()` | — |
| Alert summary card | `inventory/dashboard` | `getAlertSummary()` | `inventory.alerts.index` |
| Low/critical stock list | `inventory/dashboard` | `getStockAlerts(limit: 8)` | `inventory.alerts.index` |
| Batch expiry mini-list | `inventory/dashboard` | `getBatchExpiryAlerts(limit: 5)` | `inventory.alerts.index` |
| Owner dashboard low stock | `owner/dashboard` | **Deferred** — requires cross-branch repository method |

Widget rules:

- All values precomputed in controller — no service calls in Blade.
- Show scope label: “Cabang aktif” or location name when filtered.
- Honest empty states per widget.

---

## Test Plan

New test file: `tests/Feature/Inventory/InventoryAlertTest.php`

### Stock alert tests

| Test | Assertion |
|---|---|
| Product below reorder point appears as low | severity = `low` |
| Product below critical threshold appears as critical | severity = `critical` |
| Product at zero stock appears as out_of_stock | severity = `out_of_stock` |
| `reorder_point` overrides `minimum_stock` | effective threshold uses reorder_point |
| Null reorder_point falls back to minimum_stock | backward compatible |
| Derived critical when critical_stock null | 25% of effective reorder point |
| `alert_enabled = false` excludes product | not in alert list |
| Effective reorder point 0 excludes low/critical | only OOS if stock ≤ 0 |
| Location filter scopes stock | branch + location aggregate |
| Inactive product excluded | not in alerts |
| Cross-branch product never appears | branch isolation |
| Foreign location filter rejected | 404 or validation error |

### Batch expiry alert tests

| Test | Assertion |
|---|---|
| Expired batch with stock > 0 appears | severity = `batch_expired` |
| Expiring within 30 days with stock > 0 | severity = `batch_expiring_soon` |
| Expired batch with zero stock excluded | not in alerts |
| Batch without expiry_date excluded | not in alerts |
| Inactive batch excluded | not in alerts |
| Cross-branch batch excluded | branch isolation |

### Authorization tests

| Test | Assertion |
|---|---|
| Guest redirected | login required |
| User without view_inventory denied | 403 |
| User with view_inventory allowed | 200 on alerts index |
| Reorder fields updatable with manage_inventory | 200 on product update |
| User without manage_inventory cannot set reorder_point | 403 |

### UI tests (extend `InventoryUiTest`)

| Test | Assertion |
|---|---|
| Alerts page renders KPI counts | sees Indonesian labels |
| Sidebar shows Peringatan Stok when permitted | link present |
| Sidebar hides link without permission | no link |
| Dashboard shows critical count KPI | new KPI visible |
| Empty alert state | honest copy when no alerts |

### Regression tests

- Existing `lowStockProducts()` consumers remain correct or are migrated to `InventoryAlertService` with equivalent counts.
- `getBranchSummary()` low_stock_count / out_of_stock_count semantics documented if changed.
- Batch index/show tests unaffected.
- Full suite + Pint + route:list before merge.

---

## Backward Compatibility

| Area | Compatibility approach |
|---|---|
| `minimum_stock` | Retained; acts as fallback effective reorder point |
| Existing low-stock widget | Updated badges add Kritis tier; counts may increase granularity without breaking routes |
| `lowStockProducts()` repository method | Deprecated gradually — wrap or delegate to alert service; keep until callers migrated |
| Products without new columns | Migration defaults: null reorder/critical, `alert_enabled = true` |
| Batch workflows | Unchanged — alert layer is read-only |
| API / routes | Additive only — new `inventory.alerts.index` route |
| Ledger | No movement type or schema changes to `trx_inventory_movements` |

---

## Risks

| Risk | Mitigation |
|---|---|
| **Threshold confusion** (`minimum_stock` vs `reorder_point`) | Clear UI help text; effective threshold displayed on product show |
| **Alert noise** for products with threshold 0 | Exclude from low/critical; optional `alert_enabled` toggle |
| **Performance** on large movement history | Aggregate SQL; indexed columns; pagination; monitor explain plans |
| **Count semantics change** on inventory dashboard | Document KPI changes; update tests; changelog in sprint history |
| **Duplicate logic** with `lowStockProducts()` | Single `InventoryAlertService`; repository methods delegate |
| **Owner cross-branch alerts deferred** | Explicit out-of-scope; owner dashboard remains placeholder |
| **No notification channel** | Operators must open dashboard — document as known limitation |
| **reorder_quantity without PO workflow** | Label as informational; avoid implying auto-order |
| **Critical auto-derive 25%** may not fit all SKUs | Allow explicit `critical_stock` override per product |

---

## Definition of Done

Sprint 15.4 is complete when:

1. Migration adds `reorder_point`, `reorder_quantity`, `critical_stock`, `alert_enabled` to `inv_products` with no mutable stock columns.
2. `InventoryAlertService` computes stock and batch expiry alerts from ledger and batch tables only.
3. Product create/update forms accept and validate reorder fields (`StoreProductRequest`, `UpdateProductRequest`).
4. `GET inventory/alerts` dashboard renders unified alerts with filters, KPI strip, and empty states.
5. Inventory dashboard widgets updated with critical stock and batch expiry counts.
6. Sidebar link **Peringatan Stok** added with permission gate.
7. All tests in Test Plan pass; branch isolation and authorization covered.
8. Quality gates pass: `php artisan test`, `vendor/bin/pint`, `php artisan route:list`.
9. `docs/sprint_history.md` updated with Sprint 15.4 completion record.
10. No regression to Sprint 15.3 batch outbound guards or Sprint 12 ledger correctness.

---

## Implementation Outline (for next step)

Recommended implementation order:

1. **Schema** — migration + `Product` model/factory updates.
2. **Repository** — alert aggregate queries on `InventoryMovementRepository` / `InventoryBatchRepository`.
3. **Service** — `InventoryAlertService` with severity resolution and DTO assembly.
4. **Requests** — extend product store/update validation.
5. **Controller + route** — `InventoryAlertController`, register in `routes/web.php`.
6. **Views** — alerts index, product form/show, dashboard widgets, sidebar, badges.
7. **Refactor** — migrate `getBranchSummary()` / `lowStockProducts()` to use alert service thresholds.
8. **Tests** — `InventoryAlertTest` + UI extensions.
9. **Docs** — `sprint_history.md` entry after verification.

---

## Out of Scope (15.4)

- Purchase order / purchase request creation
- Email, SMS, or push notifications
- Alert acknowledgment or snooze persistence
- Per-branch or per-product expiring-soon day configuration
- Owner multi-branch alert rollup
- Auto-FEFO batch selection on outbound
- `requires_batch_tracking` product flag (deferred from 15.3 design)
- Mutable `alert_count` or cached alert tables
- CSV export of alerts (candidate for Sprint 18 reporting)

---

## References

- `docs/inventory_rules.md` — ledger-only stock authority
- `docs/sprint_15_3_batch_lot_tracking_design.md` — batch expiry baseline
- `docs/ui_design_system.md` — dashboard and badge standards
- `app/Modules/Inventory/Repositories/InventoryMovementRepository.php` — `lowStockProducts()`
- `app/Modules/Inventory/Services/InventoryBatchService.php` — `EXPIRING_SOON_DAYS`
- `resources/views/inventory/dashboard.blade.php` — current inventory dashboard
- `resources/views/inventory/_low-stock-badge.blade.php` — current two-tier badges
