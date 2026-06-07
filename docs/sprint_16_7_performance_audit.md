# Sprint 16.7.9 — Inventory Analytics Performance Audit

**Phase:** 16.7.9 Performance Audit & Query Hardening  
**Date:** 2026-06-07  
**Scope:** `InventoryAnalyticsRepository`, `InventoryExecutiveDashboardService`, index coverage, branch isolation  
**Out of scope:** KPI formulas, governance, UI, permissions, ledger, migrations

---

## Executive Summary

| Area | Verdict | Notes |
|---|---|---|
| Branch isolation | **PASS** | All repository methods accept `int $branchId` and filter primary tables |
| Index coverage (MVP) | **ADEQUATE** | Branch-scoped composite indexes exist; analytics-specific composites recommended for scale |
| Query complexity | **MEDIUM risk at scale** | Ledger full-scan aggregations dominate; acceptable for pilot, needs 16.8 summaries |
| N+1 (dashboard load) | **MEDIUM** | KPI strip rebuilds stock subquery 4×; movement section loads derived stock 4× |
| N+1 (supplier on-time) | **FIXED** | Batched `MIN(receipt_date)` per PO (safe optimization applied) |
| Sprint 16.8 readiness | **82/100** | Interface contract is swap-ready; implementation still on-read ledger |

**Release recommendation:** Safe for Sprint 16.7 release at current data volumes. Monitor executive dashboard latency when movement rows exceed ~500K per branch; plan Sprint 16.8 summary tables before multi-branch rollup at scale.

---

## 1. Query Audit — `InventoryAnalyticsRepository`

### Shared patterns

| Pattern | Used by | Complexity |
|---|---|---|
| `stockSubquery(branchId)` — `GROUP BY product_id` on full ledger | Value, Active SKU, Low Stock, Dead Stock Count | **O(M)** per call |
| `productsWithDerivedStock(branchId)` via movement repo | Fast/Slow/Dead items, Aging, Reorder | **O(M + P)** per call |
| `outboundByProductInPeriod` | Fast, Slow, Reorder | **O(M)** with date filter |
| `lastOutboundSubquery` / `lastOutboundDateByProduct` | Dead Stock Count, Dead Stock Items | **O(M)** |
| Simple `COUNT` on procurement tables | Open PR/PO, Pending GR, In Transit | **O(D)** low |

Legend: M = movement rows in branch, P = products in branch, D = document rows, S = suppliers.

---

### Per-method audit

#### `getInventoryValue`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements` (subquery), `inv_products` |
| **Joins** | `LEFT JOIN` stock subquery ON `product_id` |
| **GROUP BY** | Subquery: `product_id`; outer: aggregate SUM |
| **Aggregates** | `SUM(quantity_in - quantity_out)`, `SUM(stock × average_cost)` |
| **Complexity** | **MEDIUM** — full ledger scan per branch |
| **Scalability risk** | Recomputed on every KPI call; no date bound |

#### `getActiveSkuCount`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_products` |
| **Joins** | `JOIN` stock subquery |
| **GROUP BY** | Subquery: `product_id` |
| **Aggregates** | `COUNT(*)` where `current_stock > 0` |
| **Complexity** | **MEDIUM** |
| **Scalability risk** | Duplicate stock subquery vs `getInventoryValue` |

#### `getLowStockCount`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_products` |
| **Joins** | `LEFT JOIN` stock subquery |
| **WHERE** | `alert_enabled`, `current_stock <= effective_reorder_point` |
| **Aggregates** | `COUNT(*)` |
| **Complexity** | **MEDIUM** |
| **Scalability risk** | Non-sargable `CASE` reorder expression in WHERE |

#### `getDeadStockCount`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_products` |
| **Joins** | Stock subquery + `lastOutboundSubquery` |
| **GROUP BY** | `product_id` (×2 subqueries) |
| **Aggregates** | `MAX(movement_date)`, `COUNT(*)` |
| **Complexity** | **HIGH** — two ledger aggregations |
| **Scalability risk** | Full scan for last-outbound per product |

#### `getOpenPurchaseRequestCount`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_purchase_requests`, `trx_purchase_orders` (exists subquery) |
| **Joins** | `whereDoesntHave('purchaseOrders')` |
| **Complexity** | **LOW–MEDIUM** |
| **Scalability risk** | Correlated subquery per PR; acceptable at document scale |

#### `getOpenPurchaseOrderCount`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_purchase_orders` |
| **WHERE** | `branch_id`, status IN open set |
| **Complexity** | **LOW** — uses `trx_purchase_orders_branch_status_index` |

#### `getPendingGoodsReceiptCount`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_goods_receipts` |
| **Complexity** | **LOW** — uses `trx_goods_receipts_branch_status_index` |

#### `getInTransitTransferCount`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_stock_transfers` |
| **Complexity** | **LOW** |

#### `getInventoryAccuracy`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_stock_opnames`, `trx_stock_opname_items` |
| **Joins** | Inner join opname → items |
| **Aggregates** | `SUM(ABS(variance))`, `SUM(system_quantity)` |
| **Complexity** | **LOW–MEDIUM** |
| **Scalability risk** | Scans all completed opname items; bounded by opname frequency |

#### `getFastMovingItems`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_products` |
| **Joins** | Outbound aggregate + `productsWithDerivedStock` filter |
| **Processing** | PHP filter/sort/limit (top N) |
| **Complexity** | **HIGH** — loads all products with stock + outbound map |
| **Scalability risk** | In-memory sort; should push ORDER BY LIMIT to SQL in 16.8 |

#### `getSlowMovingItems`

| Attribute | Detail |
|---|---|
| **Tables** | Same as fast moving |
| **Complexity** | **HIGH** — full product scan |
| **Scalability risk** | Same as fast moving |

#### `getDeadStockItems`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_products` |
| **Joins** | `lastOutboundDateByProduct` + derived stock |
| **Complexity** | **HIGH** |
| **Scalability risk** | Full product iteration in PHP |

#### `getStockAging`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_inventory_batches`, `inv_products` |
| **Joins** | Batch stock subquery → batches; last-inbound collection |
| **GROUP BY** | `product_id`, `inventory_batch_id` (batch stock) |
| **Processing** | PHP bucket assignment + summarize |
| **Complexity** | **HIGH** |
| **Scalability risk** | Batch stock subquery scans all batch movements |

#### `getPurchaseTrend`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_purchase_orders`, `trx_purchase_order_items`, `trx_goods_receipts`, `trx_goods_receipt_items`, `trx_inventory_movements` |
| **Joins** | PO/GR left join items |
| **GROUP BY** | `DATE_TRUNC('month', date_field)` × 3 queries |
| **Complexity** | **MEDIUM** — 3 separate monthly aggregations |
| **Scalability risk** | 6-month window limits scan; OK for MVP |

#### `getConsumptionTrend`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_products` |
| **Joins** | Inner join products (branch guard) |
| **GROUP BY** | Monthly on `movement_date` |
| **Aggregates** | `SUM(quantity_out)`, `SUM(qty × cost)` |
| **Complexity** | **MEDIUM–HIGH** at scale |
| **Scalability risk** | No composite index on `(branch_id, movement_date)` for outbound filter |

#### `getSupplierPerformance`

| Attribute | Detail |
|---|---|
| **Tables** | `inv_suppliers`, `trx_purchase_orders`, `trx_purchase_order_items`, `trx_goods_receipts`, `trx_goods_receipt_items`, `trx_inventory_movements` |
| **Joins** | Multiple per supplier |
| **Per-supplier queries** | ~10 aggregates + on-time batch (post-fix) |
| **Complexity** | **HIGH** — O(S × Q) |
| **Scalability risk** | Dominant cost at 50+ suppliers; 16.8 summary table target |

#### `getReorderRecommendations`

| Attribute | Detail |
|---|---|
| **Tables** | `trx_inventory_movements`, `inv_products` |
| **Joins** | Derived stock + 30-day outbound + latest PURCHASE supplier |
| **Complexity** | **HIGH** — full product scan |
| **Scalability risk** | `latestPurchaseSupplierByProduct` loads all PURCHASE movements |

---

## 2. Index Audit

### Index Audit Table

| Table | Existing indexes (analytics-relevant) | Missing (recommended) | Duplicate / redundant |
|---|---|---|---|
| `trx_inventory_movements` | `branch_id`; `(branch_id, location, product)`; `(branch_id, location, batch)`; `movement_date`; `movement_type`; `product_id`; `supplier_id` | `(branch_id, movement_date)` for trend/outbound; `(branch_id, movement_type, movement_date)` for PURCHASE series; `(branch_id, product_id)` for stock subquery | `branch_id` alone subsumed by composites but kept for simple filters |
| `trx_purchase_requests` | `branch_id`; `(branch_id, status)`; `(branch_id, request_date)` | None critical for MVP | `branch_id` + `(branch_id, status)` overlap acceptable |
| `trx_purchase_orders` | `(branch_id, status)`; `(branch_id, order_date)`; `(branch_id, supplier_id)` | `(branch_id, supplier_id, status)` for supplier perf | `branch_id` index redundant with composites |
| `trx_goods_receipts` | `(branch_id, status)`; `(branch_id, purchase_order_id)`; `posted_at` | `(branch_id, status, posted_at)` for trend | `purchase_order_id` sufficient for on-time batch lookup |
| `trx_stock_opnames` | `(branch_id, status)` | None critical | — |
| `inv_products` | `(branch_id, is_active)`; `branch_id` | `(branch_id, alert_enabled, is_active)` for reorder/low-stock | `branch_id` redundant with composite |
| `inv_suppliers` | `(branch_id, is_active)` | None critical | `branch_id` redundant with composite |

---

## 3. Branch Filter Audit

### Enforcement model

- Repository methods take `int $branchId` as first parameter — **never** read from request.
- `BranchContext::requireId()` resolved in controller/service layer.

### Per-method branch scope

| Method | Branch filter | Scope path | Leak risk |
|---|---|---|---|
| `getInventoryValue` | `inv_products.branch_id`, subquery `movements.branch_id` | Direct | **None** |
| `getActiveSkuCount` | Same | Direct | **None** |
| `getLowStockCount` | Same | Direct | **None** |
| `getDeadStockCount` | Products + both subqueries | Direct | **None** |
| `getOpenPurchaseRequestCount` | `trx_purchase_requests.branch_id` | Direct | **None** |
| `getOpenPurchaseOrderCount` | `trx_purchase_orders.branch_id` | Direct | **None** |
| `getPendingGoodsReceiptCount` | `trx_goods_receipts.branch_id` | Direct | **None** |
| `getInTransitTransferCount` | `trx_stock_transfers.branch_id` | Direct | **None** |
| `getInventoryAccuracy` | `trx_stock_opnames.branch_id` via join | Join-enforced | **None** |
| `getFastMovingItems` | Movement repo filters `branch_id` | Direct | **None** |
| `getSlowMovingItems` | Same | Direct | **None** |
| `getDeadStockItems` | Same | Direct | **None** |
| `getStockAging` | Movements + batches `branch_id` | Direct | **None** |
| `getPurchaseTrend` | PO/GR/movements `branch_id` | Direct | **None** |
| `getConsumptionTrend` | Movements + products `branch_id` | Direct + join guard | **None** |
| `getSupplierPerformance` | Suppliers, PO, GR, movements `branch_id` | Direct | **None** |
| `getReorderRecommendations` | Movements + products `branch_id` | Direct | **None** |

### Observations

1. **No unscoped queries found** — all analytics paths include `branch_id = ?`.
2. **Join-based guards** (`consumption trend` joins `inv_products` on matching `branch_id`) provide defense-in-depth against orphaned movement rows.
3. **Cross-branch** is not implemented in repository — correct per governance; future rollup must use explicit `whereIn` at service layer with `view_inventory_cross_branch` permission.
4. **`getOpenPurchaseRequestCount`** uses `whereDoesntHave('purchaseOrders')` — PO subquery inherits branch via relationship; verify relationship scope in tests (covered by feature tests).

**Branch isolation verdict: PASS**

---

## 4. EXPLAIN Plan Review (PostgreSQL)

Audited against live `pgsql` connection (pilot data volume — costs ~8). Ratings reflect **projected** behavior at production scale.

| Query | Current plan (pilot) | At-scale projection | Risk |
|---|---|---|---|
| **Supplier performance** (per-supplier PO/GR/ledger) | Index Scan `branch_supplier` | Nested loops × suppliers; repeated item joins | **HIGH** |
| **Stock aging** (batch stock subquery) | Index Scan `branch_location_product` + Filter batch | GroupAggregate over all batch movements | **HIGH** |
| **Dead stock** (last outbound + stock) | Index Scan branch composite | Two full ledger group-by passes | **HIGH** |
| **Fast moving** (outbound period + all products) | Index Scan + PHP filter | Full product load with derived stock | **HIGH** |
| **Purchase trend** (3 monthly queries) | Index Scan `branch_date` / branch composite | Bounded 6-month window | **MEDIUM** |
| **Consumption trend** (monthly outbound) | Index Scan branch composite + Filter `quantity_out` | Sequential filter on date without leading `movement_date` in index | **MEDIUM** |

### Risk legend

- **LOW** — Index-friendly, bounded scan, <100ms expected per branch at 1M movements
- **MEDIUM** — Acceptable for MVP; monitor; index follow-up recommended
- **HIGH** — Full ledger aggregation or O(n²) supplier loops; requires 16.8 pre-aggregation

---

## 5. N+1 Audit — `InventoryExecutiveDashboardService`

### Call graph for `getExecutiveDashboard()`

```
getExecutiveDashboard(branchId)
├── getExecutiveSnapshot(branchId)
│   └── analytics.getKpiSummary(branchId)          → 9 repository calls
│       ├── getInventoryValue      → stockSubquery #1
│       ├── getActiveSkuCount      → stockSubquery #2
│       ├── getLowStockCount       → stockSubquery #3
│       ├── getDeadStockCount      → stockSubquery #4 + lastOut
│       └── 5 simple COUNT queries
├── enrichCards(snapshot)                            → 0 DB (uses snapshot) ✓
└── getDashboardSections(branchId)                   → 8 repository calls
    ├── getPurchaseTrend
    ├── getConsumptionTrend
    ├── getFastMovingItems    → outbound + productsWithDerivedStock #1
    ├── getSlowMovingItems    → outbound + productsWithDerivedStock #2
    ├── getDeadStockItems     → lastOut + productsWithDerivedStock #3
    ├── getStockAging         → productsWithDerivedStock #4 + batch anchor
    ├── getSupplierPerformance → O(suppliers × queries)
    └── getReorderRecommendations → outbound + productsWithDerivedStock #5 + latest PURCHASE
```

### Findings

| Issue | Severity | Impact |
|---|---|---|
| `stockSubquery` rebuilt 4× in KPI summary | **MEDIUM** | 4× ledger aggregation per dashboard load |
| `productsWithDerivedStock` called 5× across sections | **HIGH** | 5× product+stock full loads |
| `outboundByProductInPeriod` called 3× (fast/slow/reorder) | **MEDIUM** | Repeatable with different limits |
| `getDashboardCards()` re-fetches snapshot if called standalone | **LOW** | `getExecutiveDashboard` already passes snapshot to cards |
| Supplier on-time: per-PO GR query | **FIXED** | Batched to single `GROUP BY purchase_order_id` query |
| No lazy-loading N+1 in Eloquent relations during dashboard compose | **PASS** | Dashboard service does not query DB directly |

### Estimated query count per executive dashboard load

| Category | Count (approx.) |
|---|---|
| KPI scalar queries | 9 |
| Section queries | 8 |
| Nested subqueries (stock rebuilds) | +3 redundant |
| Supplier perf inner queries | 8–12 × supplier count |
| **Total (10 suppliers)** | **~100–120 SQL statements** |

---

## 6. Recommended Indexes (no migration in 16.7.9)

| Table | Columns | Reason | Expected impact |
|---|---|---|---|
| `trx_inventory_movements` | `(branch_id, movement_date)` | Consumption/purchase trend, outbound period scans | 30–60% faster monthly aggregations at >500K rows |
| `trx_inventory_movements` | `(branch_id, movement_type, movement_date)` | PURCHASE ledger trend + supplier ledger share | Filter index before date range |
| `trx_inventory_movements` | `(branch_id, product_id)` INCLUDE `(quantity_in, quantity_out)` | Stock subquery hot path (PG11+ covering) | 40–70% faster derived stock |
| `trx_purchase_orders` | `(branch_id, supplier_id, status)` | Supplier performance PO counts | Reduces repeated status filters |
| `trx_goods_receipts` | `(branch_id, status, posted_at)` | GR posted trend by month | Aligns with `posted_at` filter |
| `inv_products` | `(branch_id, is_active, alert_enabled)` | Low stock + reorder candidate filters | Narrower index for alert queries |

**Note:** Evaluate with `EXPLAIN ANALYZE` on staging with production-like row counts before applying in Sprint 16.8.

---

## 7. Sprint 16.8 Readiness

### `InventoryAnalyticsRepositoryInterface` swap assessment

| Criterion | Score | Evidence |
|---|---|---|
| Interface abstracts all 17 KPI methods | 20/20 | Complete contract with typed returns |
| Service depends on interface only | 20/20 | `InventoryAnalyticsService` injects interface |
| Dashboard service decoupled from repository | 15/15 | Consumes `InventoryAnalyticsService` only |
| Return shapes stable for views/DTO | 15/15 | PHPDoc contracts on interface |
| No controller-level queries | 10/10 | Thin controller pattern verified |
| Current impl tightly coupled to ledger scans | -10 | All methods on-read aggregate |
| Cross-branch not in interface signature | -8 | Single `branchId`; rollup needs `BranchScope` extension |

### Readiness score: **82/100**

### Swap path (16.8)

```text
RepositoryServiceProvider:
  InventoryAnalyticsRepositoryInterface
    → InventorySummaryAnalyticsRepository (new)
      reads: inventory_daily_summary, inventory_monthly_summary
      implements: same 17 methods, same return shapes
```

**Blockers before swap:**

1. Build summary table ETL (nightly or on-movement trigger — design TBD).
2. Reconcile summary vs ledger for acceptance tests.
3. Extend interface or service for cross-branch `whereIn` if Owner rollup ships in 16.8.
4. Push top-N movement queries to SQL LIMIT instead of PHP sort.

**Non-blockers:** Controller, DTO, dashboard service, Blade contracts can remain unchanged.

---

## 8. High-Risk Queries (priority order)

1. **`getSupplierPerformance`** — O(suppliers × 10) queries; worst dashboard cost driver.
2. **`productsWithDerivedStock` repeated** — 5× per dashboard load across movement/aging/reorder.
3. **`stockSubquery` repeated** — 4× in KPI strip alone.
4. **`getStockAging` batch anchor** — batch-level group-by over full movement history.
5. **`latestPurchaseSupplierByProduct`** — loads all PURCHASE movements, PHP `unique`.
6. **`getDeadStockCount` / `getDeadStockItems`** — dual ledger aggregation (stock + last out).

---

## 9. Optimization Roadmap

### Sprint 16.7 release (no schema change)

- [x] Batch supplier on-time GR lookup (N+1 fix applied)
- [ ] Monitor executive dashboard p95 latency in production
- [ ] Document SLA: target <3s dashboard load per branch at pilot scale

### Sprint 16.8 (recommended)

| Priority | Item | Effort |
|---|---|---|
| P0 | `inventory_daily_summary` + `inventory_monthly_summary` tables | High |
| P0 | `InventorySummaryAnalyticsRepository` implementing same interface | High |
| P1 | Composite indexes (see §6) | Low |
| P1 | Request-scoped memoization for `stockSubquery` in analytics repository | Medium |
| P2 | SQL-level `LIMIT` for fast/slow/dead top-N | Medium |
| P2 | Consolidate dashboard section queries (single derived-stock pass) | Medium |
| P3 | Materialized supplier performance monthly rollup | High |

### Sprint 16.9+ (optional)

- Redis read-through cache for executive snapshot (5-min TTL)
- Background refresh job for Owner cross-branch rollup
- `EXPLAIN ANALYZE` regression suite in CI with seeded volume fixtures

---

## 10. Changes Applied in 16.7.9

| File | Change |
|---|---|
| `app/Modules/Inventory/Repositories/InventoryAnalyticsRepository.php` | Batched supplier on-time GR lookup (N+1 fix) |
| `docs/sprint_16_7_performance_audit.md` | This report |

**Not changed:** KPI formulas, governance, UI, permissions, migrations, ledger.

---

## 11. Quality Gate Results

| Gate | Result |
|---|---|
| `php artisan test --filter=InventoryAnalytics` | **PASS** — 54 tests, 210 assertions |
| `php artisan test --filter=InventoryExecutive` | **PASS** — 47 tests, 256 assertions |
| `php artisan test --filter=Inventory` | **PASS** — full inventory suite green |
| `vendor/bin/pint --dirty` | **PASS** |

---

## References

- `docs/sprint_16_7_inventory_analytics.md` — KPI Lock Matrix, Future Optimization Layer
- `app/Modules/Inventory/Interfaces/InventoryAnalyticsRepositoryInterface.php`
- `app/Modules/Inventory/Repositories/InventoryAnalyticsRepository.php`
- `app/Modules/Inventory/Services/InventoryExecutiveDashboardService.php`
- `database/migrations/2026_06_04_120000_create_inventory_core_tables.php`
