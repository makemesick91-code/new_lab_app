# Sprint 16.8 — Analytics Optimization & Summary Tables

**Status:** DESIGN (Step 16.8.1 — audit & design only)  
**Baseline:** Sprint 16.7 Inventory Analytics & Executive Dashboard (`sprint-16.7-complete`)  
**Module:** `app/Modules/Inventory`  
**Prerequisites:** Sprint 12–16.7 inventory, procurement, analytics layer  
**Authority inputs:** `docs/sprint_16_7_inventory_analytics.md`, `docs/sprint_16_7_performance_audit.md`, live repository audit (2026-06-07)  
**Last updated:** 2026-06-07

---

## Sprint Objective

Mengurangi biaya query on-read pada analytics inventory (executive dashboard, halaman analitik, operational dashboard widgets) dengan **summary tables read-only** yang di-refresh dari ledger dan procurement — **tanpa** menambah mutable stock column, **tanpa** mengubah formula KPI Sprint 16.7, dan **tanpa** mengubah behavior bisnis Sprint 16.1–16.7.

Target teknis:

- Executive dashboard load: dari ~100–120 SQL statements → target **<15** reads pada cabang dengan data volume produksi.
- Pertahankan swap path yang sudah didesain di Sprint 16.7: ganti implementasi `InventoryAnalyticsRepositoryInterface` saja; controller, DTO, dan dashboard compose service tetap.

---

## Current Analytics Flow

### Arsitektur (implemented — Sprint 16.7)

```text
Operational dashboard:
  InventoryDashboardController
    → InventoryStockService (getBranchSummary, getStockByLocationSummary, getRecentMovements)
    → InventoryAlertService (getAlertSummary, getStockAlerts, getBatchExpiryAlerts)

Analytics page (Sprint 15.5 + 16.7):
  InventoryAnalyticsController
    → InventoryAnalyticsService
      → InventoryAnalyticsRepositoryInterface (17 KPI methods — Sprint 16.7)
      → InventoryMovementRepositoryInterface (Sprint 15.5 movement intel)
      → InventoryBatchRepositoryInterface (batch aging tab)

Executive dashboard (Sprint 16.7):
  InventoryExecutiveDashboardController
    → InventoryExecutiveDashboardService (compose only — no DB)
      → InventoryAnalyticsService
        → InventoryAnalyticsRepositoryInterface
      → InventoryExecutiveSnapshot DTO
```

Binding saat ini (`RepositoryServiceProvider`):

```text
InventoryAnalyticsRepositoryInterface → InventoryAnalyticsRepository (on-read ledger)
```

### File map (audit 16.8.1)

| Layer | File | Peran |
|---|---|---|
| Interface | `Interfaces/InventoryAnalyticsRepositoryInterface.php` | Kontrak 17 method KPI — swap point 16.8 |
| Repository | `Repositories/InventoryAnalyticsRepository.php` | Agregat ledger + procurement + opname |
| Repository | `Repositories/InventoryMovementRepository.php` | Derived stock, outbound period, value by category/location, turnover boundaries |
| Repository | `Repositories/InventoryBatchRepository.php` | Batch aging (`batchStockWithAge`) |
| Service | `Services/InventoryAnalyticsService.php` | KPI calculation + Sprint 15.5 tab data |
| Service | `Services/InventoryExecutiveDashboardService.php` | Compose snapshot, cards, sections |
| Service | `Services/InventoryStockService.php` | Operational valuation (`getBranchSummary`) |
| Service | `Services/InventoryAlertService.php` | Low stock, batch expiry alerts (operational dashboard) |
| DTO | `DTOs/InventoryExecutiveSnapshot.php` | 9-field executive KPI strip |
| Controller | `Controllers/InventoryExecutiveDashboardController.php` | Thin — authorize + branch + view |
| Controller | `Controllers/InventoryAnalyticsController.php` | Thin — authorize + filters + view |
| Controller | `Controllers/InventoryDashboardController.php` | Thin — operational widgets |
| View | `resources/views/inventory/executive-dashboard.blade.php` | Executive UI |
| View | `resources/views/inventory/analytics/index.blade.php` | Deep-dive analytics tabs |
| View | `resources/views/inventory/dashboard.blade.php` | Operational dashboard |
| Tests | `tests/Feature/Inventory/InventoryAnalyticsRepositoryTest.php` | Repository contract |
| Tests | `tests/Feature/Inventory/InventoryExecutive*.php` | Executive + integration |
| Audit | `docs/sprint_16_7_performance_audit.md` | Query/N+1 baseline |

### Domain coverage (honest audit)

| Domain | Implemented? | Where |
|---|---|---|
| Total stock value | ✅ | `InventoryAnalyticsRepository::getInventoryValue`, `InventoryMovementRepository::inventoryValue`, `InventoryStockService::getBranchSummary` |
| Stock movement trend | ✅ (partial) | `getConsumptionTrend`, `getMonthlyOutboundValueTrend`; **bukan** on-hand value time-series |
| Stock in/out summary | ✅ (outbound only in trends) | Consumption = `SUM(quantity_out)`; inbound trend via `getPurchaseTrend` ledger PURCHASE series |
| Low stock count | ✅ | `getLowStockCount`; operational alerts via `InventoryAlertService` |
| Expiring batch/lot | ✅ (operational only) | `InventoryAlertService::getBatchExpiryAlerts` — **tidak** ada KPI batch expiry di executive repository |
| PO status summary | ✅ | `getOpenPurchaseOrderCount`; trend via `getPurchaseTrend` |
| GR status summary | ✅ | `getPendingGoodsReceiptCount`; trend via `getPurchaseTrend` |
| Supplier performance | ✅ | `getSupplierPerformance` — **dominant cost driver** |
| Inventory value by branch/location/category | ✅ (single branch) | `getInventoryValueByCategory/Location` via movement repo; cross-branch **belum** diimplementasi |
| Procurement outstanding amount | ⚠️ partial | Open PO **count** ada; **nilai outstanding PO** belum KPI terpisah — dihitung implisit di supplier `order_value` minus received |
| Stock opname analytics | ⚠️ partial | Hanya `getInventoryAccuracy` — tidak ada trend opname atau adjustment analytics dashboard |
| Stock adjustment analytics | ❌ dedicated | Adjustment masuk consumption/outbound ledger; tidak ada report adjustment terpisah |
| Dashboard KPI cards | ✅ | Executive snapshot (9 KPI) + operational alert summary |

### Executive dashboard call graph (verified)

`InventoryExecutiveDashboardService::getExecutiveDashboard()`:

```text
getExecutiveSnapshot()
  → getKpiSummary() → 9 repository calls
      ├── getInventoryValue        → stockSubquery #1
      ├── getActiveSkuCount        → stockSubquery #2
      ├── getLowStockCount         → stockSubquery #3
      ├── getDeadStockCount        → stockSubquery #4 + lastOutboundSubquery
      └── 5 simple COUNT (PR/PO/GR/transfer/accuracy)

getDashboardSections()
  ├── getPurchaseTrend             → 3 monthly aggregations (PO, GR, ledger)
  ├── getConsumptionTrend          → 1 monthly outbound scan
  ├── getFastMovingItems           → outbound + productsWithDerivedStock #1
  ├── getSlowMovingItems           → outbound + productsWithDerivedStock #2
  ├── getDeadStockItems            → lastOut + productsWithDerivedStock #3
  ├── getStockAging                → productsWithDerivedStock #4 + batch anchor subquery
  ├── getSupplierPerformance       → O(suppliers × ~10 queries)
  └── getReorderRecommendations    → outbound + productsWithDerivedStock #5 + all PURCHASE movements
```

Estimasi: **~100–120 SQL statements** per load (10 suppliers) — sumber: `docs/sprint_16_7_performance_audit.md` §5.

### Analytics index page call graph (additional load)

`InventoryAnalyticsController::index()` memanggil **seluruh tab sekaligus** (bukan lazy per tab):

- `getAnalyticsSummary` → fast/slow/dead count (limit 100 each), turnover (limit 100), monthly outbound, `inventoryValue`
- Plus: fast/slow/dead lists, aging (product/batch), turnover, value by category/location, outbound trend

Ini **duplikasi berat** dengan executive dashboard untuk movement intel dan valuation queries.

---

## Heavy Query Candidates

Prioritas berdasarkan audit kode + performance report 16.7.9.

| Priority | Query / pattern | Tables | Complexity | Called from | 16.8 mitigation |
|---|---|---|---|---|---|
| P0 | `stockSubquery(branchId)` — full ledger `GROUP BY product_id` | `trx_inventory_movements`, `inv_products` | O(M) per call; **4×** in KPI strip | Analytics repo KPI methods | `rpt_inventory_branch_summaries` + `rpt_inventory_product_summaries` |
| P0 | `productsWithDerivedStock()` — full product + stock join | movements, products | O(M+P); **5×** executive + alerts + analytics tabs | Executive sections, AlertService, AnalyticsService 15.5 | Product summary table refreshed nightly/incremental |
| P0 | `getSupplierPerformance()` — ~10 aggregates × supplier count | PO, PO items, GR, GR items, movements, suppliers | O(S×Q) HIGH | Executive section 5 | Pre-aggregate supplier metrics in `rpt_procurement_daily_summaries` + supplier rollup view |
| P1 | `getStockAging()` — batch stock subquery + PHP bucket | movements, batches, products | HIGH | Executive valuation section | Product summary: `age_bucket`, `last_in_date`, batch anchor |
| P1 | `getDeadStockCount/Items` — dual ledger pass (stock + last out) | movements, products | HIGH | KPI + movement section | Product summary: `last_out_date`, `is_dead_stock` flag |
| P1 | `getFastMoving/Slow/Reorder` — outbound period + full product scan + PHP sort | movements, products | HIGH | Executive + analytics | Product summary: `outbound_qty_30d`, ranked index |
| P1 | `getConsumptionTrend` / `monthlyOutboundValue` | movements, products | MEDIUM–HIGH at scale | Trends | `rpt_inventory_daily_summaries` daily outbound rollups |
| P2 | `getPurchaseTrend` — 3 separate monthly queries | PO, GR, movements | MEDIUM | Executive trends | `rpt_procurement_daily_summaries` |
| P2 | `getInventoryTurnover` / `stockAtDate` — cumulative ledger to boundary dates | movements | MEDIUM per product | Analytics turnover tab | Daily closing stock snapshots in product/branch summary |
| P2 | `inventoryValueByCategory` / `stockByLocationSummary` | movements, products, locations | MEDIUM | Analytics value tab, operational dashboard | Branch summary by location/category dimension |
| P2 | `latestPurchaseSupplierByProduct` — all PURCHASE movements, PHP unique | movements | MEDIUM | Reorder recommendations | Product summary: `preferred_supplier_id` |
| P3 | `InventoryAlertService::getStockAlerts` — all products derived stock + PHP classify | movements, products | MEDIUM | Operational dashboard | Reuse `rpt_inventory_product_summaries` low-stock flags |
| P3 | `InventoryAlertService::getBatchExpiryAlerts` | batches, movements | MEDIUM | Operational dashboard | Optional batch summary extension (future) |
| LOW | Open PR/PO/GR/transfer COUNT | procurement, transfers | O(D) | KPI strip | Can remain live COUNT or daily snapshot counts |

Legend: M = movement rows per branch, P = products, S = suppliers, D = document rows.

### Index recommendations (deferred from 16.7.9 — apply with migrations in 16.8.2)

| Table | Columns | Supports |
|---|---|---|
| `trx_inventory_movements` | `(branch_id, movement_date)` | Consumption/purchase trend |
| `trx_inventory_movements` | `(branch_id, movement_type, movement_date)` | PURCHASE ledger series |
| `trx_inventory_movements` | `(branch_id, product_id)` | Stock subquery hot path |
| `trx_purchase_orders` | `(branch_id, supplier_id, status)` | Supplier performance |
| `trx_goods_receipts` | `(branch_id, status, posted_at)` | GR trend |
| `inv_products` | `(branch_id, is_active, alert_enabled)` | Low stock / reorder |

---

## Proposed Summary Tables

Prinsip desain:

1. **Read-only reporting layer** — bukan source of truth; ledger tetap authority.
2. **Prefix `rpt_`** — membedakan dari tabel transaksional `trx_` / master `inv_`.
3. **Tidak ada mutable stock column** pada `inv_products` atau `inv_inventory_locations`.
4. Summary menyimpan **angka agregat hasil perhitungan ledger**, bukan saldo yang di-update langsung oleh user.
5. Refresh **incremental atau nightly** — detail strategi di bawah.

### Overview matrix

| Table | Granularity | Primary use |
|---|---|---|
| `rpt_inventory_daily_summaries` | branch × day | Trend charts, daily in/out, dashboard period KPI |
| `rpt_inventory_branch_summaries` | branch × snapshot_date | KPI strip, branch comparison (16.8 cross-branch), location/category rollups |
| `rpt_inventory_product_summaries` | branch × product × snapshot_date | Movement intel, aging, dead/low stock, reorder, top-N |
| `rpt_procurement_daily_summaries` | branch × day [× supplier optional] | PO/GR trends, supplier performance, open commitment value |

---

### 1. `rpt_inventory_daily_summaries`

**Tujuan:** Menyimpan agregat harian per cabang untuk trend konsumsi, inbound, dan movement volume — menggantikan full-scan `trx_inventory_movements` pada `getConsumptionTrend`, `getMonthlyOutboundValueTrend`, dan komponen inbound trend.

**Kolom yang diperlukan:**

| Column | Type | Description |
|---|---|---|
| `id` | bigint PK | Surrogate key |
| `branch_id` | bigint FK | Cabang — wajib |
| `summary_date` | date | Hari agregat (UTC+7 business date) |
| `quantity_in_total` | decimal(18,4) | `SUM(quantity_in)` semua tipe |
| `quantity_out_total` | decimal(18,4) | `SUM(quantity_out)` — consumption definition LOCKED |
| `inbound_value` | decimal(18,2) | `SUM(quantity_in × unit_cost)` |
| `outbound_value` | decimal(18,2) | `SUM(quantity_out × COALESCE(unit_cost, product.average_cost))` |
| `purchase_inbound_value` | decimal(18,2) | Subset movement_type = PURCHASE |
| `adjustment_in_qty` | decimal(18,4) | Subset ADJUSTMENT_IN |
| `adjustment_out_qty` | decimal(18,4) | Subset ADJUSTMENT_OUT |
| `transfer_in_qty` | decimal(18,4) | Subset TRANSFER_IN |
| `transfer_out_qty` | decimal(18,4) | Subset TRANSFER_OUT |
| `movement_count` | int | COUNT(*) rows |
| `refreshed_at` | timestamp | Waktu refresh terakhir |
| `created_at` / `updated_at` | timestamp | Laravel timestamps |

**Sumber data:**

- Primary: `trx_inventory_movements` grouped by `branch_id`, `DATE(movement_date)`
- Cost: `unit_cost` on movement, fallback `inv_products.average_cost` via join

**Refresh granularity:** Daily — recompute row for `summary_date` when any movement posted on that date; nightly job backfills last 7 days for late corrections.

**Unique key / index:**

```sql
UNIQUE (branch_id, summary_date)
INDEX (branch_id, summary_date DESC)
```

**Dashboard queries accelerated:**

- Executive: `getConsumptionTrend` (monthly rollup from daily rows)
- Analytics: `getMonthlyOutboundValueTrend`
- Future: stock in/out summary cards, adjustment volume monitoring
- Monthly trend charts without scanning full ledger

---

### 2. `rpt_inventory_branch_summaries`

**Tujuan:** Snapshot KPI cabang per hari untuk executive KPI strip, operational dashboard headline, dan (future) cross-branch comparison — menggantikan 4× `stockSubquery` + scalar COUNT recomputation per request.

**Kolom yang diperlukan:**

| Column | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `branch_id` | bigint FK | |
| `snapshot_date` | date | Tanggal snapshot (typically today for current row) |
| `inventory_value` | decimal(18,2) | `SUM(S(P) × average_cost)` active products |
| `active_sku_count` | int | Products with derived stock > 0 |
| `low_stock_count` | int | `S(P) ≤ effective_reorder_point`, alert_enabled |
| `dead_stock_count` | int | Default 90-day dead stock definition |
| `dead_stock_value` | decimal(18,2) | Sum stock value for dead SKUs |
| `out_of_stock_count` | int | Optional — aligns with alert service |
| `batch_expiring_soon_count` | int | Batches expiring within alert window |
| `batch_expired_count` | int | Expired batches with stock > 0 |
| `inventory_accuracy_pct` | decimal(5,2) nullable | Latest rolling accuracy from completed opnames |
| `open_pr_count` | int | PR submitted/approved not converted |
| `open_po_count` | int | PO approved/sent/partially_received |
| `open_po_outstanding_value` | decimal(18,2) | Sum unfulfilled PO line value (ordered − received) × unit_price |
| `pending_gr_count` | int | GR draft/submitted |
| `in_transit_transfer_count` | int | Stock transfers in_transit |
| `total_quantity_on_hand` | decimal(18,4) | Sum derived stock (sanity check vs ledger) |
| `refreshed_at` | timestamp | |
| `created_at` / `updated_at` | timestamp | |

**Sumber data:**

- Stock/value/SKU/low/dead: ledger subquery + `inv_products` (same formulas as `InventoryAnalyticsRepository`)
- Batch expiry counts: `inv_inventory_batches` + batch derived stock (`InventoryBatchRepository::batchesWithDerivedStockForAlerts` logic)
- Procurement counts: `trx_purchase_requests`, `trx_purchase_orders`, `trx_goods_receipts`
- Transfer: `trx_stock_transfers`
- Accuracy: `trx_stock_opnames` COMPLETED + items (same as `getInventoryAccuracy`)
- Outstanding PO value: `trx_purchase_order_items` where PO in open status set

**Refresh granularity:**

- **Current snapshot row:** refreshed on-demand (executive dashboard) with TTL 5–15 minutes, OR incremental after movement/procurement events
- **Historical snapshot_date rows:** nightly for trend of KPI over time (optional 16.8.6+)

**Unique key / index:**

```sql
UNIQUE (branch_id, snapshot_date)
INDEX (snapshot_date, branch_id)
```

**Dashboard queries accelerated:**

- Executive KPI strip: all 9 `InventoryExecutiveSnapshot` fields (+ outstanding PO value future card)
- Operational dashboard: `InventoryStockService::getBranchSummary`, alert summary counts
- Cross-branch comparison section (deferred 16.7, target 16.8.6)
- Owner rollup: `GROUP BY branch_id` on latest `snapshot_date`

---

### 3. `rpt_inventory_product_summaries`

**Tujuan:** Snapshot per produk per cabang — menggantikan repeated `productsWithDerivedStock`, fast/slow/dead scans, aging iteration, dan reorder candidate full-table walks.

**Kolom yang diperlukan:**

| Column | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `branch_id` | bigint FK | |
| `product_id` | bigint FK | |
| `snapshot_date` | date | |
| `current_stock` | decimal(18,4) | Ledger-derived S(P) — **computed, not user-editable** |
| `stock_value` | decimal(18,2) | `current_stock × average_cost` |
| `average_cost` | decimal(18,4) | Copy from product at refresh time |
| `product_category_id` | bigint nullable | Denormalized for category rollup |
| `is_active` | boolean | |
| `alert_enabled` | boolean | |
| `effective_reorder_point` | decimal(18,4) | CASE reorder_point/minimum_stock |
| `is_low_stock` | boolean | Precomputed flag |
| `is_dead_stock` | boolean | 90-day default dead stock flag |
| `last_in_date` | date nullable | MAX inbound movement date |
| `last_out_date` | date nullable | MAX outbound movement date |
| `age_days` | int | Days since age anchor |
| `age_bucket` | varchar(20) | fresh/aging/stale/old/very_old |
| `outbound_qty_7d` | decimal(18,4) | Rolling window |
| `outbound_qty_30d` | decimal(18,4) | Rolling window — fast/slow/reorder |
| `outbound_qty_90d` | decimal(18,4) | Executive movement intel default |
| `outbound_value_30d` | decimal(18,2) | |
| `avg_daily_consumption_30d` | decimal(18,4) | outbound_qty_30d / 30 |
| `preferred_supplier_id` | bigint nullable | Latest PURCHASE movement supplier |
| `fast_moving_rank` | int nullable | Branch rank by outbound_qty_90d |
| `refreshed_at` | timestamp | |
| `created_at` / `updated_at` | timestamp | |

**Sumber data:**

- `trx_inventory_movements` — stock, last in/out, outbound windows
- `inv_products` — cost, reorder config, category
- `inv_inventory_batches` — batch age anchor when batch stock > 0 (product-level min received_date)
- Latest PURCHASE movement per product for supplier

**Refresh granularity:**

- Nightly full refresh per branch (acceptable at pilot SKU counts)
- Incremental: on movement post, upsert affected `product_id` row for `snapshot_date = today`
- Rolling windows recomputed from daily summaries + same-day partial when incremental

**Unique key / index:**

```sql
UNIQUE (branch_id, product_id, snapshot_date)
INDEX (branch_id, snapshot_date, is_low_stock) WHERE is_low_stock = true
INDEX (branch_id, snapshot_date, is_dead_stock) WHERE is_dead_stock = true
INDEX (branch_id, snapshot_date, outbound_qty_90d DESC)
INDEX (branch_id, product_category_id, snapshot_date)
```

**Dashboard queries accelerated:**

- `getFastMovingItems`, `getSlowMovingItems`, `getDeadStockItems` — SQL `ORDER BY` + `LIMIT`
- `getStockAging` — aggregate from `age_bucket`
- `getReorderRecommendations` — filter `is_low_stock`
- `getInventoryValueByCategory/Location` — join location dimension via separate location-product extension OR keep location rollup in branch summary
- Analytics page all-product tabs
- `InventoryAlertService` stock alert classification (optional convergence 16.8.5)

**Catatan lokasi:** Snapshot produk saat ini **branch-level** (Sprint 16.7 repo juga branch-level, bukan per-location untuk executive). Location filter Sprint 15.5 tetap on-read dari movement repo until optional `rpt_inventory_location_product_summaries` follow-up.

---

### 4. `rpt_procurement_daily_summaries`

**Tujuan:** Agregat harian procurement per cabang (dan opsional per supplier) — menggantikan 3-query `getPurchaseTrend` dan bulk supplier performance loops.

**Kolom yang diperlukan (branch-level row, `supplier_id` NULL):**

| Column | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `branch_id` | bigint FK | |
| `supplier_id` | bigint nullable | NULL = branch total; non-null = supplier slice |
| `summary_date` | date | |
| `po_created_count` | int | PO created (non-draft, non-cancelled) by order_date |
| `po_created_value` | decimal(18,2) | Sum line qty × unit_price |
| `po_open_count` | int | End-of-day open PO count (approved/sent/partial) |
| `po_open_outstanding_value` | decimal(18,2) | Unreceived PO value at EOD |
| `gr_posted_count` | int | GR posted by posted_at date |
| `gr_received_value` | decimal(18,2) | Sum GR line totals |
| `ledger_purchase_value` | decimal(18,2) | PURCHASE movements by movement_date |
| `pr_submitted_count` | int | Optional demand signal |
| `supplier_order_count` | int | Per-supplier PO count (when supplier_id set) |
| `supplier_received_value` | decimal(18,2) | Per-supplier received |
| `supplier_on_time_count` | int | PO with expected_delivery_date received on time |
| `supplier_dated_po_count` | int | Denominator for on-time % |
| `supplier_fulfilled_qty` | decimal(18,4) | Received qty aggregate |
| `supplier_ordered_qty` | decimal(18,4) | Ordered qty aggregate |
| `refreshed_at` | timestamp | |
| `created_at` / `updated_at` | timestamp | |

**Sumber data:**

- `trx_purchase_orders`, `trx_purchase_order_items`
- `trx_goods_receipts`, `trx_goods_receipt_items`
- `trx_purchase_requests` (optional)
- `trx_inventory_movements` (PURCHASE type)
- On-time: `expected_delivery_date` vs `MIN(gr.receipt_date)` — same rules as `supplierOnTimeStats()` in analytics repo

**Refresh granularity:** Daily per branch; supplier slice rows refreshed nightly (supplier perf is not real-time critical).

**Unique key / index:**

```sql
UNIQUE (branch_id, supplier_id, summary_date)  -- supplier_id NULL = branch rollup (PG: use COALESCE sentinel or partial unique index)
INDEX (branch_id, summary_date DESC)
INDEX (branch_id, supplier_id, summary_date DESC) WHERE supplier_id IS NOT NULL
```

**Dashboard queries accelerated:**

- `getPurchaseTrend` — `SUM` daily rows grouped by month
- `getSupplierPerformance` — aggregate supplier slice over rolling window (e.g. 365 days) instead of per-supplier live queries
- Open PO outstanding value KPI (new card — aligns with business ask)
- Procurement spend trend on executive dashboard

---

## Source Tables (authority)

| Layer | Tables | Role |
|---|---|---|
| Ledger (primary) | `trx_inventory_movements` | Stock derivation, consumption, PURCHASE inbound |
| Master | `inv_products`, `inv_product_categories`, `inv_inventory_locations`, `inv_suppliers` | Cost, reorder, labels |
| Batch | `inv_inventory_batches` | Aging anchor, expiry alerts |
| Procurement | `trx_purchase_requests`, `trx_purchase_orders`, `trx_purchase_order_items`, `trx_goods_receipts`, `trx_goods_receipt_items` | Open counts, trends, supplier metrics |
| Opname | `trx_stock_opnames`, `trx_stock_opname_items` | Inventory accuracy only |
| Transfer | `trx_stock_transfers` | In-transit count |
| **Forbidden KPI source** | `inv_inventory_activity_logs` | Audit/drill-down only — LOCKED Sprint 16.7 |

Summary tables are **derived caches**. On reconciliation mismatch, ledger wins.

---

## Refresh Strategy

### Options evaluated

| Strategy | Pros | Cons | Recommendation |
|---|---|---|---|
| A. Nightly batch only | Simple, predictable load | Intraday dashboard stale | **Phase 16.8.3 MVP** |
| B. Event-driven incremental | Fresher KPI | Complex invalidation on movement post | **Phase 16.8.5** for product/branch snapshot |
| C. On-read with request memoization | No migration logic | Does not fix scale; already insufficient | 16.7.9 deferred — not sufficient alone |
| D. Manual refresh artisan command | Ops control | Requires discipline | **`inventory:refresh-analytics-summaries`** for backfill |

### Recommended phased approach

1. **16.8.3 — Nightly job** (`inventory:refresh-analytics-summaries {--branch=} {--date=}`)
   - Recompute yesterday + today for all active branches
   - Idempotent upsert on unique keys
   - Log duration and row counts

2. **16.8.5 — Incremental hooks** (optional, post-MVP)
   - After movement ledger write (receive, adjust, transfer receive, GR post): queue lightweight upsert for affected branch/product/day
   - After PO/GR status change: upsert procurement daily row
   - Use Laravel queue **only if** already approved project-wide; else synchronous upsert in same transaction **after** ledger commit (read-only table, no business rule change)

3. **TTL read-through** (16.8.6 optional)
   - Executive snapshot: if `refreshed_at` within 15 minutes, serve summary; else trigger async refresh
   - No Redis required — check `refreshed_at` on summary row

4. **Backfill**
   - On first deploy: backfill last 180 days daily + current product/branch snapshot
   - Reconciliation test compares summary vs live repo for sample branch

### Refresh ownership

```text
InventoryAnalyticsSummaryRefreshService (new, 16.8.3)
  → reads ledger + procurement (same SQL as current repo)
  → writes rpt_* tables in DB::transaction per branch+date
  → NEVER writes inv_products or movement rows
```

---

## Branch Isolation Rules

| Rule | Enforcement |
|---|---|
| Every summary row carries `branch_id` | NOT NULL FK to `branches` |
| Refresh job iterates branches independently | No cross-branch INSERT in one product row |
| Repository read | `WHERE branch_id = ?` — same as current analytics repo |
| Cross-branch rollup (16.8.6) | Only when `view_inventory_cross_branch` + service `BranchScope`; query `WHERE branch_id IN (...)` with explicit allowed set — **never** global aggregate without branch label |
| Product/location integrity | Refresh validates `inv_products.branch_id = summary.branch_id` |
| Request tampering | Controllers continue using `BranchContext::requireId()` — summary tables do not trust request `branch_id` |

Tests required (mirror 16.7):

- User branch A cannot read branch B summary via analytics endpoints
- Refresh job does not write cross-branch contaminated rows
- Cross-branch owner sees labeled per-branch cards only

---

## Data Correctness Rules

1. **Ledger authority:** If `rpt_*` disagrees with live ledger computation, treat as refresh bug — fix refresh, not ledger.
2. **No mutable stock:** `current_stock` on `rpt_inventory_product_summaries` is a **cached derivative**, recomputed from movements each refresh — never updated by stock operations directly.
3. **Formula lock:** All KPI formulas remain as Sprint 16.7 KPI Lock Matrix — summary tables store outputs of those formulas, not new definitions.
4. **Operational valuation disclaimer:** `average_cost` snapshot at refresh time — not accounting FIFO/LIFO.
5. **Consumption definition:** `quantity_out` includes TRANSFER_OUT, ADJUSTMENT_OUT — daily summaries must not filter to "production only".
6. **Open PO definition:** statuses `{approved, sent, partially_received}` — unchanged.
7. **Inventory accuracy:** Store `null` when no completed opname — never coerce to 0.
8. **Supplier on-time:** Exclude PO without `expected_delivery_date` from on-time denominator — store `supplier_dated_po_count` separately.
9. **Reconciliation gate:** Before binding swap, automated test samples ≥20 products and compares `getInventoryValue`, `getActiveSkuCount`, `getLowStockCount` live vs summary-backed repo within tolerance 0.01.
10. **Activity log:** Still forbidden as refresh input.

---

## Risk Notes

| Risk | Impact | Mitigation |
|---|---|---|
| Summary drift from ledger | Wrong executive KPI | Reconciliation tests + nightly full rebuild window |
| Stale intraday data | Ops decisions on old stock | Incremental refresh 16.8.5; display `refreshed_at` in UI |
| Storage growth | DB size | Retention policy: daily rows ≥365 days → archive (16.8.7) |
| Dual code path during swap | Maintenance burden | Feature flag `analytics.use_summary_tables`; single interface |
| Location-level analytics gap | Product summary branch-level only | Document limitation; optional future table |
| Batch expiry on executive dashboard | Not in 16.7 executive KPI | Add to `rpt_inventory_branch_summaries` counts; UI card follow-up |
| Outstanding PO value not in 16.7 snapshot | Incomplete procurement KPI | Add column + DTO field in 16.8.4 (additive) |
| Cross-branch permission not seeded | Branch comparison blocked | 16.8.6 permission + seeder |
| Refresh job failure | Empty dashboard | Fallback to `InventoryAnalyticsRepository` (live) if summary missing — **fail open for read, log alert** |
| Migration on production | Deploy risk | Additive migrations only; no change to trx_/inv_ core tables |

---

## Implementation Plan (Sprint 16.8.2 – 16.8.7)

### 16.8.2 — Schema & indexes

- Migrations: create 4 `rpt_*` tables with unique keys above
- Add recommended movement/procurement indexes from 16.7.9 §6
- Models read-only under `app/Modules/Inventory/Models/Reporting/` (or `app/Modules/Reporting/` if module boundary preferred — default: Inventory owns inventory analytics summaries)
- **No application binding swap yet**

### 16.8.3 — Refresh service & command

- `InventoryAnalyticsSummaryRefreshService` — branch-scoped refresh methods per table
- Artisan `inventory:refresh-analytics-summaries`
- Unit tests: refresh from known movement fixtures produces expected row
- Schedule nightly in `routes/console.php` or kernel equivalent

### 16.8.4 — Summary-backed repository (implemented)

- `InventorySummaryAnalyticsRepository implements InventoryAnalyticsRepositoryInterface`
- Read from `rpt_*` with safe empty defaults when summary not yet refreshed
- Feature flag: `config/inventory.php` → `analytics_summary_enabled` (env: `INVENTORY_ANALYTICS_SUMMARY_ENABLED`, **default `false`**)
- Binding (`RepositoryServiceProvider`): flag `false` → `InventoryAnalyticsRepository`; flag `true` → `InventorySummaryAnalyticsRepository`
- Fallback to live `InventoryAnalyticsRepository` for:
  - `getSupplierPerformance` (avg lead time, coverage %, cancelled PO rate)
  - `getFastMovingItems` / `getSlowMovingItems` when `$days` ∉ `{7, 30, 90}`
  - `getStockAging` when no product summary snapshot exists
- Rollback: set `INVENTORY_ANALYTICS_SUMMARY_ENABLED=false` (or omit env) — no code deploy required beyond config

### 16.8.5 — Reconciliation & Incremental Refresh Safety (implemented)

- Reconciliation feature test suite: summary vs ledger repo
- Incremental refresh safety tests (date/branch isolation, idempotency)
- Binding swap integration tests (flag on/off, executive dashboard load)
- Command selective refresh validation tests
- Feature flag activation and rollback checklist documented below

#### Tujuan reconciliation

Membuktikan bahwa `InventorySummaryAnalyticsRepository` aman dipakai sebagai swap target `InventoryAnalyticsRepositoryInterface` — output KPI dan trend harus setara (atau shape-compatible) dengan `InventoryAnalyticsRepository` setelah `InventoryAnalyticsSummaryRefreshService::refreshAll` dijalankan. Ledger (`trx_inventory_movements`) tetap authority; mismatch = refresh bug, bukan perubahan formula bisnis.

#### Method yang sudah setara live vs summary (setelah refresh)

| Method | Sumber summary | Catatan |
|---|---|---|
| `getInventoryValue` | `rpt_inventory_branch_summaries` | Toleransi 0.01 IDR |
| `getActiveSkuCount` | `rpt_inventory_branch_summaries` | Exact match |
| `getLowStockCount` | `rpt_inventory_branch_summaries` | Exact match |
| `getDeadStockCount` (90 hari) | `rpt_inventory_product_summaries.is_dead_stock` | Exact match pada default 90 hari |
| `getOpenPurchaseRequestCount` | `rpt_inventory_branch_summaries` | Exact match |
| `getOpenPurchaseOrderCount` | `rpt_inventory_branch_summaries` | Exact match |
| `getPendingGoodsReceiptCount` | `rpt_inventory_branch_summaries` | Exact match |
| `getInTransitTransferCount` | `rpt_inventory_branch_summaries` | Exact match |
| `getInventoryAccuracy` | `rpt_inventory_branch_summaries` | `null` when no opname |
| `getConsumptionTrend` | `rpt_inventory_daily_summaries` | Monthly rollup, toleransi 0.01 |
| `getPurchaseTrend` | `rpt_procurement_daily_summaries` (branch rollup) | Monthly rollup, toleransi 0.01 |
| `getFastMovingItems` (7/30/90 hari) | `rpt_inventory_product_summaries` | Product ID order match |
| `getSlowMovingItems` (7/30/90 hari) | `rpt_inventory_product_summaries` | Shape + count match |
| `getDeadStockItems` (90 hari) | `rpt_inventory_product_summaries` | Shape + count match |
| `getStockAging` | `rpt_inventory_product_summaries` | Bucket totals match when snapshot exists |
| `getReorderRecommendations` | `rpt_inventory_product_summaries.is_low_stock` | Shape compatible |
| `current_stock` per product | Ledger-derived di refresh | `SUM(quantity_in) - SUM(quantity_out)` |

#### Method yang masih fallback / known limitation

| Method | Perilaku | Alasan |
|---|---|---|
| `getSupplierPerformance` | **Selalu** delegate ke `InventoryAnalyticsRepository` | `avg_lead_time_days`, `coverage_percentage`, `cancelled_po_rate` belum fully di supplier daily slice |
| `getFastMovingItems` / `getSlowMovingItems` | Fallback live jika `$days` ∉ `{7, 30, 90}` | Summary hanya menyimpan `outbound_qty_7d/30d/90d` |
| `getDeadStockCount` / `getDeadStockItems` | Custom `$days` ≠ 90: recompute dari `last_out_date` di product summary | Flag `is_dead_stock` hanya untuk default 90 hari |
| `getStockAging` | Fallback live jika tidak ada product summary snapshot | Empty-safe |
| Summary kosong (belum di-refresh) | KPI strip returns `0` / `null`; lists empty | Fail-open read; tidak error |
| Location-level analytics | Tidak ada di summary | Product summary branch-level only (Sprint 16.7 parity) |
| Cross-branch rollup | Belum di summary repo | Deferred 16.8.6 |

#### Incremental refresh safety rules

1. Refresh tanggal T **tidak** mengubah baris summary tanggal T-1 (daily/branch/product/procurement).
2. Refresh `--branch=ID` **tidak** mengubah summary cabang lain.
3. `refreshProductSummaries` cabang A **tidak** menghapus/mengubah product summary cabang B.
4. Re-run command tanggal sama = idempotent upsert; row count tidak double.
5. Supplier slice `rpt_procurement_daily_summaries` tidak duplicate saat refresh ulang.
6. Invalid `--branch` gagal tanpa INSERT summary baru.
7. Reconciliation purchase trend memerlukan `refreshProcurementDailySummaries` untuk setiap tanggal yang punya PO (`order_date`), GR posted (`posted_at`), **atau** movement ledger `PURCHASE` — agar `ledger_purchase_value` monthly rollup match live.

#### Feature flag activation checklist

```bash
# 1. Backfill summaries (semua cabang aktif, tanggal hari ini)
php artisan inventory:analytics-summary:refresh --all

# 2. Aktifkan flag di .env
INVENTORY_ANALYTICS_SUMMARY_ENABLED=true

# 3. Reload config
php artisan config:clear
php artisan config:cache

# 4. Verifikasi dashboard executive load tanpa error
#    (manual UAT atau: php artisan test tests/Feature/Inventory/InventoryAnalyticsRepositoryBindingTest.php)
```

#### Rollback

```bash
# 1. Nonaktifkan flag
INVENTORY_ANALYTICS_SUMMARY_ENABLED=false

# 2. Reload config
php artisan config:clear
php artisan config:cache
```

Tidak perlu hapus tabel `rpt_*` — binding kembali ke `InventoryAnalyticsRepository` (live ledger). Data transaksional tidak terpengaruh.

#### Catatan otoritas data

- `trx_inventory_movements` = **source of truth** stok dan consumption.
- `rpt_*` = **read model / cache / reporting optimization** saja.
- Jangan menambahkan mutable stock column ke `inv_products` atau `inv_inventory_locations`.
- Feature flag default tetap `false` (`config/inventory.php`).

#### Test files (16.8.5)

- `tests/Feature/Inventory/InventoryAnalyticsSummaryReconciliationTest.php`
- `tests/Feature/Inventory/InventoryAnalyticsSummaryIncrementalRefreshTest.php`
- `tests/Feature/Inventory/InventoryAnalyticsRepositoryBindingTest.php` (extended)
- `tests/Feature/Inventory/RefreshInventoryAnalyticsSummaryCommandTest.php` (extended)

### 16.8.6 — Cross-Branch Permission, Branch Comparison UI, Deferred Analytics Tabs (implemented)

#### Deferred tab strategy

- `InventoryAnalyticsController` delegates page composition to `InventoryAnalyticsPageService`.
- Default tab `summary` loads KPI ringkas + tab metadata only — **tidak** memuat semua section berat sekaligus.
- Tab berat dimuat on-demand via query param `?tab=`:
  - `movement` — fast/slow/dead stock intelligence
  - `aging` — stock aging
  - `turnover` — inventory turnover
  - `value` — value by category/location
  - `trend` — monthly outbound value trend
  - `supplier` — supplier performance
  - `reorder` — reorder recommendations
  - `procurement` — purchase trend (PO/GR/ledger)
  - `branch-comparison` — cross-branch comparison (permission-gated nav)
- Legacy tab keys `fast`, `slow`, `dead` tetap didukung untuk backward compatibility.
- Invalid tab fallback ke `summary` tanpa error validasi.

#### Branch comparison access rule

| User | Behavior |
|---|---|
| `view_inventory` (tanpa cross-branch) | Hanya data cabang aktif (`BranchContext`); tab comparison **tidak** ditampilkan di nav |
| `view_inventory_cross_branch_analytics` | Boleh melihat semua cabang aktif dalam tabel perbandingan |
| Tanpa `view_inventory` / analytics permission | Route `inventory.analytics.index` tetap `403` |

Data provider: `InventoryBranchComparisonService` — branch-scoped, tidak mencampur row antar cabang.

#### Permission yang digunakan

| Permission | Purpose |
|---|---|
| `view_inventory` / `view_inventory_analytics` / `manage_inventory` | Akses halaman analytics (existing policy `viewAnalytics`) |
| `view_inventory_cross_branch_analytics` | **Baru (16.8.6)** — opt-in lintas cabang; di-seed ke `Admin Lab` + `Super Admin` |

`view_inventory_executive_dashboard` **≠** cross-branch permission (unchanged Sprint 16.7 lock).

#### Summary/live mode indicator

- UI hint di analytics index (`_meta-hint.blade.php`):
  - Flag `false`: **Live ledger mode** + **Summary belum di-refresh**
  - Flag `true` + `refreshed_at`: **Analytics summary mode aktif** + **Last refreshed: …**
  - Flag `true` tanpa snapshot: **Summary belum di-refresh**
- Tidak menampilkan nama env mentah ke user.

#### Data source branch comparison

| Mode | Source |
|---|---|
| `INVENTORY_ANALYTICS_SUMMARY_ENABLED=true` | `rpt_inventory_branch_summaries` latest per `branch_id` |
| `false` (default) | Live `InventoryAnalyticsRepositoryInterface` scalar KPIs per branch |

Kolom: branch name, inventory value, active SKU, low/dead/out-of-stock counts, outstanding PO value, accuracy %, refreshed_at.

#### Known limitations

- Live mode branch comparison: `out_of_stock_count`, `open_po_outstanding_value`, `total_quantity_on_hand` default `0` (belum di interface live repo).
- `getSupplierPerformance` tetap live fallback di summary repo (16.8.4 parity).
- Executive dashboard belum di-refactor deferred tabs (analytics index only).
- Owner role belum di-seed terpisah; cross-branch via permission assignment.

#### Tidak ada perubahan source of truth

- `trx_inventory_movements` tetap authority stok.
- `rpt_*` tetap read model/cache.
- Tidak ada mutable stock column baru.
- Feature flag default tetap `false`.

#### Test files (16.8.6)

- `tests/Feature/Inventory/InventoryAnalyticsDeferredTabsTest.php`
- `tests/Feature/Inventory/InventoryBranchComparisonAuthorizationTest.php`

### 16.8.7 — Scheduler, Retention, Performance Regression, Production Notes (implemented)

#### Scheduler strategy

- Lokasi: `routes/console.php` (Laravel 12 style).
- **Daily refresh** — `inventory:analytics-summary:refresh --all` at **01:30** server time.
- `withoutOverlapping()` — mencegah overlap run panjang.
- **Tidak** menggunakan `onOneServer()` — project belum memakai multi-server scheduler lock pattern.
- Scheduler **tidak** mengaktifkan feature flag — summary dipersiapkan sebelum `INVENTORY_ANALYTICS_SUMMARY_ENABLED=true`.
- **Monthly prune** (opsional) — `inventory:analytics-summary:prune` on **1st at 02:30**, `withoutOverlapping()`.

#### Retention strategy

- Config: `inventory.analytics_summary_retention_days` (env `INVENTORY_ANALYTICS_SUMMARY_RETENTION_DAYS`, **default 730**).
- Retention hanya berlaku untuk:
  - `rpt_inventory_daily_summaries.summary_date`
  - `rpt_procurement_daily_summaries.summary_date`
- **Tidak** menghapus:
  - `trx_inventory_movements` atau tabel transaksi
  - `rpt_inventory_branch_summaries` (current/historical KPI snapshot)
  - `rpt_inventory_product_summaries` (product snapshot)
- Purge otomatis **tidak** aktif tanpa command — prune via artisan + optional scheduler.

#### Prune command

- `php artisan inventory:analytics-summary:prune`
- Options: `--days=` (min 30), `--dry-run` (count only).
- Idempotent; transactional delete per run.
- Registered in `bootstrap/app.php`.

#### Performance regression coverage

- `tests/Feature/Inventory/InventoryAnalyticsPerformanceRegressionTest.php`
- Asserts:
  - Default `?tab=summary` tidak render supplier/reorder/procurement/branch-comparison sections.
  - Deferred tabs load hanya section masing-masing.
  - Branch comparison permission-gated.
  - Summary flag true + refreshed → summary tab renders.
  - Flag false → live ledger mode renders.
  - Coarse response-body guard (bukan query count rapuh).

#### Production activation / rollback summary

- Dokumen: `docs/sprint_16_8_production_notes.md`
- Activation: migrate → refresh --all → flag true → config cache → verify dashboard.
- Rollback: flag false → config cache — **no truncate rpt_***.
- Cron: `* * * * * php artisan schedule:run`.

#### Invariant source of truth

- `trx_inventory_movements` tetap authority stok.
- `rpt_*` tetap read model/cache only.
- Tidak ada mutable stock column baru.
- Feature flag default tetap `false`.
- Prune tidak menyentuh ledger/transaksi.
- Rollback aman via `INVENTORY_ANALYTICS_SUMMARY_ENABLED=false`.

#### Test files (16.8.7)

- `tests/Feature/Inventory/InventoryAnalyticsSchedulerTest.php`
- `tests/Feature/Inventory/PruneInventoryAnalyticsSummaryCommandTest.php`
- `tests/Feature/Inventory/InventoryAnalyticsPerformanceRegressionTest.php`

### 16.8.8 — Final completion (planned)

- Update `docs/sprint_history.md` dengan Sprint 16.8 completion record
- Final UAT checklist
- Optional: CSV export gate via `manage_inventory_analytics`

---

## Out of Scope (Sprint 16.8)

- HR module
- Mutable stock columns on `inv_products` / `inv_inventory_locations`
- Changing Sprint 16.1–16.7 business workflows (PO, GR, transfer, opname posting)
- Accounting-grade costing / COGS
- Redis cache layer (deferred 16.9+)
- Dedicated stock adjustment analytics dashboard (adjustments remain in movement ledger; daily summary columns capture volume)
- Data warehouse / external OLAP

---

## Quality Gates (design step)

| Gate | Result |
|---|---|
| Audit based on live code | ✅ This document |
| No migration in 16.8.1 | ✅ Design only |
| No application code change in 16.8.1 | ✅ Design only |
| Ledger-only preserved | ✅ |
| Formulas aligned with 16.7 KPI Lock Matrix | ✅ |

---

## References

- `docs/sprint_16_7_inventory_analytics.md` — KPI Lock Matrix, Future Optimization Layer
- `docs/sprint_16_7_performance_audit.md` — heavy query baseline, 82/100 swap readiness
- `docs/sprint_16_7_inventory_analytics_completion.md` — delivered components
- `docs/sprint_15_5_inventory_analytics_design.md` — movement intel definitions
- `docs/inventory_rules.md` — ledger constitution
- `app/Modules/Inventory/Repositories/InventoryAnalyticsRepository.php` — current on-read implementation
- `app/Modules/Inventory/Services/InventoryExecutiveDashboardService.php` — compose call graph

---

*Sprint 16.8.1 deliverable: analytics optimization design authority — ready for review before 16.8.2 migrations.*
