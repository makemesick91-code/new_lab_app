# Sprint 15.5 Technical Design — Inventory Analytics

Date: 2026-06-06
Status: COMPLETED
Module: `app/Modules/Inventory`
Prerequisites: Sprint 12 Inventory Core (complete), Sprint 13 Stock Opname (complete), Sprint 15.1 Stock Transfer (complete), Sprint 15.2 Transfer Receiving (complete), Sprint 15.3 Batch & Lot Tracking (complete), Sprint 15.4 Reorder Point & Inventory Alerts (complete)

## Sprint Numbering Note

The repository roadmap memory (`.cursor/memory/sprint-roadmap-13-20.md`) originally placed inventory reporting and valuation in **Sprint 18**. Active milestone planning for the current inventory slice labels this work **Sprint 15.5** immediately after unified inventory alerts.

| Planning slice | Dependency |
|---|---|
| Sprint 15.1 | Stock Transfer document workflow |
| Sprint 15.2 | Ship/receive ledger split |
| Sprint 15.3 | Batch/lot identity on ledger movements |
| Sprint 15.4 | Reorder configuration and unified alerts |
| Sprint 15.5 | This document — read-only inventory analytics |

Implementation must not break Sprint 15.4 alert contracts, Sprint 15.3 batch contracts, Sprint 12 ledger rules, or existing inventory dashboard KPIs without an explicit migration path documented below.

---

## Problem Statement

ADLMS inventory correctly answers **how much** stock exists today (ledger-derived) and **what needs attention** (Sprint 15.4 alerts). Operators and branch managers still lack **movement intelligence** to optimize purchasing, reduce dead capital, and rotate materials before expiry:

1. **No fast/slow mover visibility** — Warehouse staff cannot see which resin discs, zirconia blocks, or bonding liquids move fastest vs sit on shelves with positive stock.
2. **No dead stock detection** — Products with stock but zero outbound activity for extended periods tie up cash and shelf space; there is no configurable idle-days report.
3. **No aging analysis** — Sprint 15.3 added batch expiry dates, but there is no consolidated aging view by product, batch, or age bucket for FEFO planning.
4. **No turnover metric** — Management cannot compare outbound velocity against average on-hand stock to judge replenishment efficiency.
5. **No value trend surface** — `InventoryStockService::getInventoryValue()` returns a **current snapshot** only; there is no time-series or category/location breakdown for trend analysis.
6. **Reporting module gap** — Sprint 8 `Reporting` module covers lab-order/finance flows only; inventory analytics belong in the Inventory module with branch-safe queries.

Sprint 15.5 introduces a **read-only analytics layer** computed from the movement ledger and existing master data — with no mutable stock columns, no cross-branch leakage, and Indonesian operator labels.

---

## Business Value for Dental Lab

| Capability | Operational benefit |
|---|---|
| Fast moving products | Prioritize reorder and shelf placement for high-consumption materials (e.g. polishing paste, PMMA discs) |
| Slow moving products | Identify overstocked SKUs before they become dead stock; adjust purchase quantities |
| Dead stock report | Free shelf space and capital; flag SKUs for promotion, transfer, or write-off review |
| Inventory aging | Support FEFO rotation; align with batch expiry alerts (15.4) for liquids and bonding agents |
| Inventory turnover | Measure how efficiently stock converts to usage; compare branches or locations when permitted |
| Inventory value trend | Show whether tied-up inventory value is rising or falling; breakdown by category/location when full time-series is impractical |
| Branch-safe analytics | Each lab branch sees only its own movement history and derived stock |
| Ledger integrity preserved | All numbers traceable to `trx_inventory_movements`; no shadow stock tables |

Dental labs carry high SKU variety with uneven consumption. Analytics must be **actionable and honest** — derived values labeled as such, inactive products excluded by default, and limitations documented where historical reconstruction is expensive.

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

Movement types in production:

| Type | Direction | Introduced |
|---|---|---|
| `OPENING` | IN | Sprint 12 |
| `PURCHASE` | IN | Sprint 12 |
| `ADJUSTMENT_IN` | IN | Sprint 12 |
| `ADJUSTMENT_OUT` | OUT | Sprint 12 |
| `TRANSFER_IN` | IN | Sprint 15.1/15.2 |
| `TRANSFER_OUT` | OUT | Sprint 15.1/15.2 |

**Analytics OUT scope:** All movement rows with `quantity_out > 0` in the selected period, regardless of `movement_type`. Transfer OUT counts as consumption from the source location's perspective; transfer IN is excluded from OUT aggregates.

### Existing read surfaces (relevant to 15.5)

| Component | Behavior |
|---|---|
| `InventoryMovementRepository::currentStock*()` | Ledger aggregates by product, location, batch |
| `InventoryMovementRepository::inventoryValue()` | `SUM(derived_stock × average_cost)` per branch/location |
| `InventoryStockService::getBranchSummary()` | Returns current `inventory_value` snapshot |
| `InventoryAlertService` | Stock/batch alert severity (15.4) — complementary, not replaced |
| `inventory/dashboard` | KPI cards, alert widgets, stock value card |
| `inventory/stock` | Location/product stock list with optional location filter |
| `inventory/alerts` | Unified alert index (15.4) |
| `Reporting` module | Lab order / production / finance — **no inventory reports** |

### Batch baseline (Sprint 15.3)

| Field | Use in 15.5 |
|---|---|
| `inv_inventory_batches.received_date` | Primary aging anchor when batch stock > 0 |
| `inv_inventory_batches.expiry_date` | Display alongside aging; alerts remain in `InventoryAlertService` |
| `trx_inventory_movements.inventory_batch_id` | Batch-level stock and movement attribution |

### What is explicitly absent today

- No fast/slow/dead stock analytics service or routes.
- No turnover or aging bucket reports.
- No inventory analytics sidebar entry.
- No historical inventory value snapshots table.
- No owner cross-branch analytics rollup (deferred, same as 15.4).
- No CSV/PDF export for inventory analytics (optional follow-up; out of 15.5 MVP unless trivial).

---

## Analytics Definitions

All metrics are **computed on read** from ledger rows. No persisted analytics counters.

### Shared symbols

| Symbol | Meaning |
|---|---|
| `B` | Active branch ID from `BranchContext::requireId()` |
| `L` | Optional `inventory_location_id` filter (validated in branch) |
| `P` | Product ID |
| `period` | Inclusive date range `[date_from, date_to]` on `movement_date` |
| `S` | Derived current stock: `SUM(quantity_in) - SUM(quantity_out)` scoped to `B` and optional `L` |
| `OUT_period(P)` | `SUM(quantity_out)` for product `P` where `movement_date` in `period` and `branch_id = B` and optional location filter |

### 1. Fast Moving Products (Produk Pergerakan Cepat)

**Definition:** Active products ranked by **highest outbound quantity** in the selected period.

```text
fast_moving_score(P) = OUT_period(P)
```

| Rule | Detail |
|---|---|
| Inclusion | `is_active = true`; `OUT_period(P) > 0` |
| Ranking | `ORDER BY OUT_period(P) DESC`, then product name ASC |
| Default limit | Top 25 (configurable 10–100 via filter) |
| Stock column | Show current `S` for context (not used in ranking) |
| Value column | Optional: `OUT_period(P) × product.average_cost` as **Nilai Keluar** |

Fast moving answers: *"What did we use most in this period?"*

### 2. Slow Moving Products (Produk Pergerakan Lambat)

**Definition:** Active products with **positive derived stock** but **low outbound quantity** in the selected period.

```text
slow_moving(P) =
  S > 0
  AND OUT_period(P) <= slow_moving_threshold
```

| Rule | Detail |
|---|---|
| `slow_moving_threshold` | Default: `1` unit in period (configurable 0–100 via filter) |
| Exclusion | Products already classified as dead stock (see below) when `dead_stock_days` filter would subsume them |
| Ranking | `ORDER BY OUT_period(P) ASC`, then `S DESC`, then product name |
| Default limit | Top 25 |

Slow moving answers: *"What is sitting on the shelf but barely moving?"*

**Note:** Slow moving is intentionally **not** the complement of fast moving. A product with zero stock is excluded (not slow — it is simply out of stock).

### 3. Dead Stock (Stok Mati)

**Definition:** Products with **positive derived stock** and **no outbound movement** for a configurable number of days.

```text
last_out_date(P) = MAX(movement_date) WHERE quantity_out > 0 AND branch_id = B [AND location = L]

dead_stock(P) =
  S > 0
  AND (
    last_out_date(P) IS NULL
    OR last_out_date(P) < today - dead_stock_days
  )
```

| Rule | Detail |
|---|---|
| `dead_stock_days` | Default: **90** days (configurable 30–365 via filter) |
| `last_out_date` | Evaluated on all historical OUT rows in branch (not limited to filter period) |
| Inclusion | `is_active = true` |
| Ranking | `ORDER BY days_since_last_out DESC`, then `S DESC` |
| Display | Show `days_since_last_out`, `last_out_date`, current `S`, **Nilai Stok** = `S × average_cost` |

Dead stock answers: *"What has stock but has not been used in X days?"*

**Edge case:** Product with only IN movements (never OUT) and `S > 0` → `last_out_date IS NULL` → classified as dead stock. This is intentional.

### 4. Inventory Aging (Penuaan Persediaan)

**Definition:** Group on-hand stock into **age buckets** based on how long inventory has been held.

Two granularities (tabs or sub-views):

#### 4a. Product-level aging (default)

Age anchor = date of the **most recent inbound movement** that contributes to current stock (proxy):

```text
last_in_date(P) = MAX(movement_date) WHERE quantity_in > 0 AND branch_id = B [AND location = L]

age_days(P) = today - last_in_date(P)
```

| Bucket | Label (ID) | Range |
|---|---|---|
| `fresh` | 0–30 Hari | 0–30 days |
| `aging` | 31–60 Hari | 31–60 days |
| `stale` | 61–90 Hari | 61–90 days |
| `old` | 91–180 Hari | 91–180 days |
| `very_old` | > 180 Hari | > 180 days |

Report shows: bucket summary counts (product count, total qty, total value) plus drill-down table.

**Limitation (documented):** `last_in_date` is a **FIFO proxy**, not true lot-level aging when multiple receipts exist. Batch-level aging (4b) is more accurate when batch tracking is used.

#### 4b. Batch-level aging (when `inventory_batch_id` present)

Age anchor = `COALESCE(inv_inventory_batches.received_date, MIN(movement_date) for batch IN movements)`:

```text
batch_age_days(batch) = today - age_anchor(batch)
batch_stock(batch) = SUM(quantity_in) - SUM(quantity_out) for batch_id, branch, [location]
```

Include batch rows where `batch_stock > 0`. Same bucket labels as 4a. Show `batch_number`, `lot_number`, `expiry_date`, product, location, stock, value.

### 5. Inventory Turnover (Perputaran Persediaan)

**Definition:** Ratio of outbound activity to average on-hand stock in the selected period.

**Quantity turnover:**

```text
out_qty_period = SUM(quantity_out) in period for product P (branch, optional location)

avg_stock_period = (stock_at_period_start + stock_at_period_end) / 2

turnover_ratio_qty(P) = out_qty_period / NULLIF(avg_stock_period, 0)
```

**Value turnover:**

```text
out_value_period = SUM(quantity_out × product.average_cost) in period
avg_stock_value_period = (stock_value_at_start + stock_value_at_end) / 2

turnover_ratio_value(P) = out_value_period / NULLIF(avg_stock_value_period, 0)
```

| Rule | Detail |
|---|---|
| `stock_at_period_*` | Reconstructed from ledger: cumulative `SUM(quantity_in) - SUM(quantity_out)` for all movements with `movement_date <= boundary_date` |
| Display | Show ratio to 2 decimal places; if `avg_stock = 0`, show `—` (not applicable) |
| Branch aggregate | Optional summary row: total OUT / total average branch stock |
| Ranking | Default: by `turnover_ratio_qty DESC` for active products with `out_qty_period > 0` |

**Limitation:** Uses `average_cost` at report time for value turnover (not historical unit cost per movement). Acceptable for operational pilot; not accounting-grade COGS.

### 6. Inventory Value Trend (Tren Nilai Persediaan)

**Definition:** Show how inventory **value** changes over time or by dimension.

#### Feasibility assessment

| Approach | Feasible in 15.5? | Notes |
|---|---|---|
| Daily historical stock value from ledger replay | **Partial** | Computationally expensive: requires per-day cumulative sum across all products for branch. Acceptable for pilot branches with < 50k movements; risky at scale without caching. |
| Monthly snapshot table | **Out of scope** | Would require new `trx_inventory_value_snapshots` table and scheduled job — deferred. |
| Current value by category | **Yes** | Single aggregate query; high value, low cost. |
| Current value by location | **Yes** | Reuse `stockByLocationSummary()` pattern. |
| OUT value trend by month | **Yes** | `SUM(quantity_out × average_cost)` grouped by `DATE_TRUNC('month', movement_date)` — shows usage value, not on-hand value. |

**15.5 MVP delivery:**

1. **Nilai per Kategori** — bar/table: `SUM(S × average_cost)` grouped by `inv_product_categories`.
2. **Nilai per Lokasi** — reuse/extend `stockByLocationSummary()`.
3. **Tren Nilai Keluar Bulanan** — monthly OUT value for selected period (line chart or table).
4. **Nilai Persediaan Saat Ini** — headline KPI (existing `getInventoryValue()`).

**Documented limitation:** True **on-hand value time-series** (stock value at end of each month) is **not** in 15.5 MVP. The UI must label monthly OUT value trend as **"Tren Nilai Keluar (bukan nilai stok)"** to avoid misinterpretation. A future sprint may add snapshot table or materialized daily aggregates if pilot data volume requires it.

---

## Data Source Tables

| Table | Role in analytics |
|---|---|
| `trx_inventory_movements` | **Primary source** — all OUT/IN aggregates, period filters, stock reconstruction, last movement dates |
| `inv_products` | Product master, `average_cost`, `is_active`, `product_category_id` |
| `inv_product_categories` | Category labels for value breakdown |
| `inv_inventory_locations` | Location filter and value-by-location |
| `inv_inventory_batches` | Batch aging anchors (`received_date`, `expiry_date`, `batch_number`) |
| `inv_product_units` | Display `symbol` on tables |

**Forbidden new source-of-truth tables in 15.5:**

- No `current_stock` on products/locations/batches.
- No `analytics_cache` or precomputed mover rankings (unless added in a later performance sprint with explicit invalidation design).

---

## Calculation Rules

### Repository: extend `InventoryMovementRepositoryInterface`

New methods (proposed):

| Method | Purpose |
|---|---|
| `outboundByProductInPeriod(int $branchId, array $filters): Collection` | Fast/slow mover base data |
| `lastOutboundDateByProduct(int $branchId, ?int $locationId): Collection` | Dead stock |
| `stockAtDate(int $branchId, string $asOfDate, ?int $locationId, ?int $productId): float\|Collection` | Turnover boundaries |
| `monthlyOutboundValue(int $branchId, array $filters): Collection` | Value trend (OUT) |
| `inventoryValueByCategory(int $branchId, ?int $locationId): Collection` | Category breakdown |
| `lastInboundDateByProduct(int $branchId, ?int $locationId): Collection` | Product aging proxy |
| `batchStockWithAge(int $branchId, ?int $locationId): Collection` | Batch aging |

All methods:

- First parameter `int $branchId` (mandatory).
- Apply `where('branch_id', $branchId)` on every movement query.
- Use aggregate SQL; avoid per-product loops in PHP for list endpoints.

### Service: `InventoryAnalyticsService` (new)

Centralizes analytics business rules. Injected dependencies:

- `InventoryMovementRepositoryInterface`
- `InventoryBatchRepositoryInterface` (batch aging list)
- `InventoryLocationRepositoryInterface` (location validation)
- `ProductRepositoryInterface` (category filter validation)
- `BranchContext`

Public methods (proposed):

| Method | Returns |
|---|---|
| `getFastMovingProducts(array $filters): Collection` | Ranked fast mover DTOs |
| `getSlowMovingProducts(array $filters): Collection` | Ranked slow mover DTOs |
| `getDeadStockProducts(array $filters): Collection` | Dead stock DTOs |
| `getProductAgingSummary(array $filters): array` | Bucket totals + optional detail rows |
| `getBatchAgingSummary(array $filters): array` | Batch bucket totals + detail rows |
| `getTurnoverReport(array $filters): Collection` | Per-product turnover DTOs |
| `getValueByCategory(?int $locationId): Collection` | Category value rows |
| `getValueByLocation(): Collection` | Location value rows (may delegate to existing repo method) |
| `getMonthlyOutboundValueTrend(array $filters): Collection` | Month → OUT value |
| `getAnalyticsSummary(array $filters): array` | KPI strip counts for analytics index |

### DTO shape (array or readonly class)

```php
// Fast / slow / dead / turnover row
[
    'product_id' => int,
    'product_code' => string,
    'product_name' => string,
    'category_name' => string|null,
    'unit_symbol' => string|null,
    'current_stock' => float,
    'outbound_qty_period' => float|null,
    'outbound_value_period' => float|null,
    'stock_value' => float, // current_stock × average_cost
    'last_out_date' => string|null,       // dead stock
    'days_since_last_out' => int|null,    // dead stock
    'last_in_date' => string|null,        // aging
    'age_days' => int|null,               // aging
    'age_bucket' => string|null,          // aging
    'turnover_ratio_qty' => float|null,
    'turnover_ratio_value' => float|null,
    'avg_stock_period' => float|null,
    'inventory_location_id' => int|null,
    'inventory_location_name' => string|null,
]

// Batch aging row
[
    'inventory_batch_id' => int,
    'batch_number' => string,
    'lot_number' => string|null,
    'product_id' => int,
    'product_name' => string,
    'expiry_date' => string|null,
    'received_date' => string|null,
    'age_days' => int,
    'age_bucket' => string,
    'batch_stock' => float,
    'batch_value' => float,
    'inventory_location_id' => int,
    'inventory_location_name' => string,
]
```

### Default filter constants (service-level)

```php
public const DEFAULT_PERIOD_DAYS = 30;
public const DEFAULT_FAST_LIMIT = 25;
public const DEFAULT_SLOW_THRESHOLD = 1.0;
public const DEFAULT_DEAD_STOCK_DAYS = 90;
public const AGING_BUCKET_FRESH_MAX = 30;
public const AGING_BUCKET_AGING_MAX = 60;
public const AGING_BUCKET_STALE_MAX = 90;
public const AGING_BUCKET_OLD_MAX = 180;
```

---

## Branch Isolation Rules

| Rule | Enforcement |
|---|---|
| Active branch | `BranchContext::requireId()` in every `InventoryAnalyticsService` public method |
| Movement queries | `where('branch_id', $branchId)` on all `trx_inventory_movements` access |
| Product scope | `inv_products.branch_id = $branchId`; active products only unless filter overrides |
| Location filter | `inventory_location_id` validated via `InventoryLocationRepository::findInBranch()` |
| Category filter | `product_category_id` validated as belonging to `$branchId` |
| Batch scope | `inv_inventory_batches.branch_id = $branchId` |
| Cross-branch request IDs | Reject with 404 or empty result — never leak other branch product names |
| Owner multi-branch rollup | **Out of scope** — same deferral as Sprint 15.4; future owner dashboard uses separate repository entry points |

Tests must prove: user in Branch A never sees Branch B product codes in any analytics tab when movements exist in both branches.

---

## Permission Rules

| Action | Permission / policy |
|---|---|
| View analytics index | `view_inventory` OR `manage_inventory` (match alerts/dashboard) |
| Export (if added later) | `view_inventory` minimum; `manage_inventory` for bulk export |
| No write operations | Analytics is read-only — no `manage_inventory` required beyond existing view gate |

**Controller authorization:**

```php
$this->authorize('viewAny', InventoryMovement::class);
```

Reuse `InventoryMovementPolicy` / `ChecksInventoryAccess` — same pattern as `InventoryAlertController`. No new `InventoryAnalyticsPolicy` (no persisted analytics model).

**Sidebar:** Gate with `@can('viewAny', \App\Modules\Inventory\Models\InventoryMovement::class)` under Persediaan group.

**Forbidden:** Weakening branch checks because user has `manage_inventory`. Permission grants access to the page; `BranchContext` still scopes data.

---

## UI Plan

### Route

| Method | URI | Name |
|---|---|---|
| GET | `inventory/analytics` | `inventory.analytics.index` |

Single index page with **tabbed sections** (Alpine or query-string tabs — match existing inventory filter patterns). Default tab: **Pergerakan Cepat**.

### Sidebar

Add under **Persediaan** (after **Peringatan Stok**, before **Transfer Stok**):

```text
Analitik Persediaan → route('inventory.analytics.index')
```

### Page structure (`resources/views/inventory/analytics/index.blade.php`)

```text
<x-settings-shell title="Analitik Persediaan">
  ├── Header (title, description, branch context note)
  ├── Shared filter bar (sticky on desktop)
  ├── KPI strip (optional: fast count, slow count, dead count, total value)
  ├── Tab navigation
  │     ├── Pergerakan Cepat
  │     ├── Pergerakan Lambat
  │     ├── Stok Mati
  │     ├── Penuaan Persediaan (sub-toggle: Produk | Batch)
  │     ├── Perputaran Persediaan
  │     └── Tren Nilai
  └── Data table / chart per tab (desktop table + mobile cards)
</x-settings-shell>
```

### Indonesian labels (operator-facing)

| English | Indonesian UI label |
|---|---|
| Inventory Analytics | Analitik Persediaan |
| Fast Moving | Pergerakan Cepat |
| Slow Moving | Pergerakan Lambat |
| Dead Stock | Stok Mati |
| Inventory Aging | Penuaan Persediaan |
| Inventory Turnover | Perputaran Persediaan |
| Value Trend | Tren Nilai |
| Outbound Qty (period) | Jumlah Keluar (periode) |
| Outbound Value | Nilai Keluar |
| Current Stock | Stok Saat Ini |
| Stock Value | Nilai Stok |
| Days Since Last Out | Hari Tanpa Keluar |
| Last Out Date | Tanggal Keluar Terakhir |
| Age Bucket | Kelompok Usia |
| Turnover Ratio | Rasio Perputaran |
| Avg Stock (period) | Rata-rata Stok (periode) |
| Value by Category | Nilai per Kategori |
| Value by Location | Nilai per Lokasi |
| Monthly Outbound Value | Nilai Keluar Bulanan |
| Period | Periode |
| Location | Lokasi |
| Category | Kategori |
| Dead stock days | Hari tanpa keluar (ambang) |
| Slow moving threshold | Ambang pergerakan lambat |
| No data | Tidak ada data untuk filter ini |
| Derived from ledger | Dihitung dari ledger pergerakan |

### Visual design

Follow `docs/ui_design_system.md` and `inventory/products/index` reference:

- Teal primary accents; bordered white cards.
- `tabular-nums` for quantities and currency (`format_number_id`, `format_currency_id`).
- Semantic badges for age buckets (fresh = teal, old/very_old = amber/red).
- Empty states per tab with filter adjustment hint.
- Optional compact bar chart for category value and monthly OUT trend (CSS/SVG or minimal Alpine — no new JS framework).
- Pagination on detail tables (default 25).

### Dashboard integration (optional, low priority)

- Small **Analitik** link card on `inventory/dashboard` pointing to analytics index.
- Do **not** duplicate full analytics widgets on dashboard in 15.5 MVP (avoid KPI sprawl).

---

## Filter Plan

**Form request:** `InventoryAnalyticsFilterRequest`

| Parameter | Type | Default | Validation |
|---|---|---|---|
| `tab` | string | `fast_moving` | `in:fast_moving,slow_moving,dead_stock,aging,turnover,value_trend` |
| `date_from` | date | `today - 30 days` | `nullable`, `date`, `before_or_equal:date_to` |
| `date_to` | date | `today` | `nullable`, `date`, `after_or_equal:date_from` |
| `inventory_location_id` | int | null | `nullable`, exists in branch |
| `product_category_id` | int | null | `nullable`, exists in branch |
| `limit` | int | 25 | `nullable`, `integer`, `min:10`, `max:100` |
| `dead_stock_days` | int | 90 | `nullable`, `integer`, `min:30`, `max:365` |
| `slow_moving_threshold` | numeric | 1 | `nullable`, `numeric`, `min:0`, `max:1000` |
| `aging_granularity` | string | `product` | `in:product,batch` |
| `include_inactive` | bool | false | `nullable`, `boolean` |

**Filter behavior:**

- `date_from` / `date_to` apply to fast/slow/turnover OUT aggregates and monthly value trend.
- Dead stock uses `dead_stock_days` against **all-time** last OUT date (not limited to period).
- Location and category filters apply across all tabs consistently.
- Query string preserved on pagination (`->withQueryString()`).
- Reset filters link clears to defaults.

---

## Service / Repository Plan

### File manifest (implementation phase)

| Layer | File | Action |
|---|---|---|
| Service | `app/Modules/Inventory/Services/InventoryAnalyticsService.php` | Create |
| Interface | `app/Modules/Inventory/Interfaces/InventoryMovementRepositoryInterface.php` | Extend |
| Repository | `app/Modules/Inventory/Repositories/InventoryMovementRepository.php` | Extend |
| Interface | `app/Modules/Inventory/Interfaces/InventoryBatchRepositoryInterface.php` | Extend (batch aging query) |
| Repository | `app/Modules/Inventory/Repositories/InventoryBatchRepository.php` | Extend |
| Request | `app/Modules/Inventory/Requests/InventoryAnalyticsFilterRequest.php` | Create |
| Controller | `app/Modules/Inventory/Controllers/InventoryAnalyticsController.php` | Create |
| View | `resources/views/inventory/analytics/index.blade.php` | Create |
| Partials | `resources/views/inventory/analytics/_*.blade.php` | Create (tabs, badges, empty states) |
| Routes | `routes/web.php` | Add analytics route |
| Sidebar | `resources/views/layouts/sidebar.blade.php` | Add menu item |
| Tests | `tests/Feature/Inventory/InventoryAnalyticsTest.php` | Create |

**No migration required** for 15.5 MVP unless query explain plans justify a composite index (optional):

```sql
-- Optional performance follow-up
CREATE INDEX idx_inv_movements_branch_date_out
  ON trx_inventory_movements (branch_id, movement_date)
  WHERE quantity_out > 0;
```

(PostgreSQL partial index — only if profiling shows seq scans.)

### Controller pattern (thin)

```php
public function index(InventoryAnalyticsFilterRequest $request): View
{
    $this->authorize('viewAny', InventoryMovement::class);

    $filters = $request->validated();
    $tab = $filters['tab'] ?? 'fast_moving';

    return view('inventory.analytics.index', [
        'filters' => $filters,
        'tab' => $tab,
        'data' => $this->analytics->getTabData($tab, $filters),
        'summary' => $this->analytics->getAnalyticsSummary($filters),
        'locations' => $this->locations->listActive($this->branchContext->requireId()),
        'categories' => $this->categories->listActive($this->branchContext->requireId()),
    ]);
}
```

### Layering compliance

```text
InventoryAnalyticsController
  → InventoryAnalyticsFilterRequest
  → InventoryAnalyticsService
  → InventoryMovementRepositoryInterface / InventoryBatchRepositoryInterface
  → Models (read-only)
```

No analytics queries in Blade. No controller SQL.

---

## Test Plan

New file: `tests/Feature/Inventory/InventoryAnalyticsTest.php`

### Required test cases

| # | Scenario | Assertion |
|---|---|---|
| 1 | Guest cannot access analytics | 302 redirect to login |
| 2 | User without inventory permission denied | 403 |
| 3 | Fast moving ranks by OUT qty in period | Highest OUT product first |
| 4 | Slow moving requires positive stock and low OUT | Zero-stock product excluded |
| 5 | Dead stock detects no OUT in N days | Product with stock, old last OUT included |
| 6 | Dead stock excludes recent OUT | Product with OUT within threshold excluded |
| 7 | Product aging assigns correct bucket | `age_days` maps to bucket label |
| 8 | Batch aging uses `received_date` | Batch stock > 0 in correct bucket |
| 9 | Turnover ratio computed | `out_qty / avg_stock` matches fixture |
| 10 | Value by category sums correctly | `SUM(stock × average_cost)` per category |
| 11 | Monthly OUT value trend groups by month | Correct month buckets |
| 12 | Branch isolation | Branch B products absent for Branch A user |
| 13 | Location filter scopes movements | Only selected location OUT counts |
| 14 | Invalid location in other branch rejected | 422 or empty with validation error |
| 15 | Analytics index renders Indonesian labels | See **Pergerakan Cepat**, **Stok Mati**, etc. |
| 16 | Empty period returns empty state | No false rows |

### Test data pattern

Use existing factories:

- `ProductFactory`, `InventoryLocationFactory`, `InventoryMovementFactory` (or direct movement create helpers from `InventoryStockServiceTest` patterns).
- Create branch A and branch B with overlapping product codes to prove isolation.
- Seed movements with varied `movement_date`, `quantity_in`, `quantity_out`, and `movement_type` including `TRANSFER_OUT`.

### Regression

- Run full inventory test suite — alerts, dashboard, stock, batch tests must remain green.
- No changes to `InventoryAlertService` behavior in 15.5 unless shared repository refactor is carefully regression-tested.

---

## Performance Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Full ledger scan for dead stock `MAX(movement_date)` | Medium | Single grouped SQL query per branch; index on `(branch_id, product_id, movement_date)` |
| Stock-at-date reconstruction for turnover | Medium | Compute only for products with OUT in period; cache period boundaries per request |
| Batch aging join across movements + batches | Medium | Limit to batches with positive derived stock subquery |
| Monthly trend over long date ranges | Low | Cap `date_to - date_from` at 365 days in validation |
| Product aging `last_in_date` on large ledgers | Medium | Same grouped MAX pattern; location filter reduces scope |
| N+1 in view rendering | Low | Eager-load `product.unit`, `product.category`, `inventoryLocation` in repository |
| Pilot branch with 100k+ movements | Low (pilot) | Document optional partial index; defer snapshot table to future sprint |

**Forbidden performance patterns:**

- Loading all movements into PHP collections for aggregation.
- Per-product `currentStock()` calls in a loop for list endpoints.
- Unbounded date range defaults (always default to 30 days).

**Target:** Analytics index TTFB < 2s on pilot dataset (< 20k movements per branch). If exceeded, add repository-level raw SQL and consider request-level memoization within service (not persistent cache in 15.5).

---

## Backward Compatibility

| Area | Guarantee |
|---|---|
| Ledger schema | No change to `trx_inventory_movements` structure in 15.5 MVP |
| Product schema | No new columns required |
| `InventoryStockService` | Unchanged public API |
| `InventoryAlertService` | Unchanged; alerts and analytics are complementary |
| `inventory/dashboard` | Optional link only; existing KPIs unchanged |
| `lowStockProducts()` | Retained; not removed |
| Movement write paths | Untouched |
| Batch outbound guards | Untouched |
| Routes | Additive only — one new GET route |
| Permissions | No new permission seeds required — reuse `view_inventory` / movement policy |

**Relationship to Sprint 15.4 alerts:**

| Concern | Alerts (15.4) | Analytics (15.5) |
|---|---|---|
| Purpose | Actionable thresholds & expiry warnings | Historical movement patterns & optimization |
| Time horizon | Current snapshot | Configurable period + historical last-out |
| Overlap | Low stock / OOS | Slow/dead stock (different criteria) |

A product can be **low stock** (alert) without being **dead stock** (analytics), and vice versa. UI copy must not conflate them.

**Relationship to roadmap Sprint 18:** Sprint 15.5 delivers operational analytics inside Inventory. Sprint 18 may add audit exports, owner rollup, and accounting-grade valuation — building on 15.5 repository methods without breaking contracts.

---

## Definition of Done

Sprint 15.5 is complete when:

1. `docs/sprint_15_5_inventory_analytics_design.md` approved and implementation matches it.
2. `InventoryAnalyticsService` computes all six analytics domains from ledger and master tables only.
3. `inventory/analytics` route renders tabbed UI with Indonesian labels and shared filters.
4. Sidebar **Analitik Persediaan** link visible to authorized inventory viewers.
5. No mutable stock columns added anywhere.
6. All analytics queries are branch-scoped via `BranchContext::requireId()`.
7. Value trend limitation documented in UI copy (monthly OUT value ≠ on-hand value time-series).
8. `InventoryAnalyticsTest` passes with auth, branch isolation, and calculation cases listed above.
9. Full quality gates pass: `php artisan test`, `vendor/bin/pint`, `php artisan route:list`, `npm run build` (if views change).
10. `docs/sprint_history.md` updated with Sprint 15.5 completion record.

---

## Implementation Phases (recommended)

| Phase | Deliverable |
|---|---|
| 15.5.1 | Repository analytics queries + `InventoryAnalyticsService` unit-facing methods |
| 15.5.2 | `InventoryAnalyticsFilterRequest`, controller, route |
| 15.5.3 | Analytics index view + tab partials + sidebar |
| 15.5.4 | `InventoryAnalyticsTest` + branch isolation + regression run |
| 15.5.5 | `docs/sprint_history.md` update + quality gates |

---

## Assumptions

1. PostgreSQL aggregate functions (`DATE_TRUNC`, `COALESCE`) remain available as in existing inventory queries.
2. `average_cost` on `inv_products` is the accepted valuation multiplier for operational analytics (per `inventory_rules.md`).
3. Pilot branches have movement volume where on-the-fly aggregation is acceptable without snapshot tables.
4. Production material usage (future Sprint 16) will add new OUT movement types; analytics will include them automatically once posted to ledger.
5. Indonesian UI labels follow the same migration standard as Sprint 15.4 alerts and inventory dashboard.

---

## Out of Scope (Sprint 15.5)

- Owner / multi-branch consolidated analytics dashboard.
- CSV, Excel, or PDF export.
- Scheduled email reports.
- Inventory value snapshot table or background aggregation jobs.
- FIFO/LIFO costing or movement-level historical cost for turnover value.
- Purchase order or reorder automation triggered by analytics.
- ML forecasting or demand prediction.
- Changes to `Reporting` module (inventory analytics stay in Inventory module).
- Graph/chart library beyond existing Blade + Alpine + Tailwind patterns.

---

## References

- `docs/inventory_rules.md` — ledger authority
- `docs/architecture_rules.md` — layering and branch rules
- `docs/ui_design_system.md` — UI contract
- `docs/sprint_15_4_reorder_alerts_design.md` — adjacent sprint design pattern
- `docs/sprint_history.md` — Sprint 12–15.4 baseline
- `app/Modules/Inventory/Services/InventoryStockService.php` — valuation snapshot
- `app/Modules/Inventory/Services/InventoryAlertService.php` — alert complement
- `app/Modules/Inventory/Repositories/InventoryMovementRepository.php` — aggregate patterns
- `app/Modules/Reporting/Repositories/ReportingRepository.php` — read-only aggregation reference
