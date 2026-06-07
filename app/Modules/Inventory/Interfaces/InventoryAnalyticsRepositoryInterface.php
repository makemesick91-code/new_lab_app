<?php

namespace App\Modules\Inventory\Interfaces;

use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryExecutiveDashboardService;
use App\Providers\RepositoryServiceProvider;
use Illuminate\Support\Collection;

/**
 * Abstraction layer for inventory analytics KPI queries (Sprint 16.7).
 *
 * All methods are read-only aggregations scoped to a single branch via {@see $branchId}.
 * Branch resolution (BranchContext, cross-branch opt-in) is owned by the service layer —
 * this repository never trusts request-supplied branch identifiers without prior validation.
 *
 * ## Service dependency plan
 *
 * {@see InventoryAnalyticsService} MUST depend on this
 * interface — NOT on a concrete repository class.
 *
 * {@see InventoryExecutiveDashboardService} MUST consume
 * KPIs through {@see InventoryAnalyticsService} only;
 * it MUST NOT inject this repository directly.
 *
 * ## Sprint 16.8 summary-table compatibility
 *
 * This interface is the swap point for analytics data sources. Sprint 16.8 may replace the
 * concrete implementation with one backed by pre-aggregated tables:
 *
 * - `inventory_daily_summary`
 * - `inventory_monthly_summary`
 *
 * WITHOUT changing:
 * - {@see InventoryAnalyticsService}
 * - {@see InventoryExecutiveDashboardService}
 * - Controller or view contracts
 *
 * Only the {@see RepositoryServiceProvider} binding changes:
 * `InventoryAnalyticsRepositoryInterface` → `InventorySummaryAnalyticsRepository` (future).
 *
 * ## Analytics data source rule (LOCKED)
 *
 * Primary sources: `trx_inventory_movements`, `trx_purchase_requests`, `trx_purchase_orders`,
 * `trx_goods_receipts`, `trx_stock_opnames`, `trx_stock_transfers`, `inv_products`,
 * `inv_suppliers`, `inv_inventory_locations`, `inv_inventory_batches`.
 *
 * `inv_inventory_activity_logs` is FORBIDDEN as a KPI source — audit/drill-down only.
 *
 * ## Return type design
 *
 * - Scalar KPI counts and monetary totals → `int` or `float`
 * - Nullable accuracy when no completed opname exists → `?float`
 * - Ranked product/supplier lists with fixed top-N → `Collection` (array-shaped rows)
 * - Time-series and bucket summaries with fixed keys → `array` (typed via PHPDoc)
 *
 * `mixed` is intentionally avoided so consumers and future summary-table implementations
 * share an explicit contract.
 */
interface InventoryAnalyticsRepositoryInterface
{
    /**
     * Total operational inventory value for the branch.
     *
     * KPI purpose: Executive snapshot "Nilai Persediaan" — capital tied up in on-hand stock.
     *
     * Formula (LOCKED): `SUM(S(P) × inv_products.average_cost)` where `S(P)` is ledger-derived
     * stock per active product P.
     *
     * Source tables: `trx_inventory_movements` (derived stock), `inv_products` (`average_cost`, `is_active`).
     *
     * Governance: Operational Inventory Value only — NOT accounting valuation. Do not use FIFO,
     * LIFO, or weighted-average costing. `average_cost` is maintained operationally and is not
     * recalculated automatically on Goods Receipt posting.
     *
     * Return type: `float` — monetary total in IDR; returns `0.0` when no stock exists.
     */
    public function getInventoryValue(int $branchId): float;

    /**
     * Count of active SKUs with positive derived stock.
     *
     * KPI purpose: Executive snapshot "SKU Berstok" — breadth of stocked product catalog.
     *
     * Formula (LOCKED): `COUNT(P)` where `S(P) > 0` AND `inv_products.is_active = true`.
     *
     * Source tables: `trx_inventory_movements`, `inv_products`.
     *
     * Governance: Ledger-derived stock only; never read mutable stock columns. Single-branch scope.
     *
     * Return type: `int` — non-negative count; returns `0` when none qualify.
     */
    public function getActiveSkuCount(int $branchId): int;

    /**
     * Count of products at or below effective reorder point.
     *
     * KPI purpose: Executive alert density — products needing replenishment attention.
     *
     * Formula (LOCKED): `COUNT(P)` where `S(P) ≤ effective_reorder_point(P)`.
     * Effective reorder point aligns with {@see InventoryAlertService}.
     *
     * Source tables: `trx_inventory_movements`, `inv_products` (`reorder_point`, `minimum_stock`, `alert_enabled`).
     *
     * Governance: Default single-branch only (no cross-branch rollup). Respects `alert_enabled` and active product filters.
     *
     * Return type: `int` — non-negative count; returns `0` when none qualify.
     */
    public function getLowStockCount(int $branchId): int;

    /**
     * Count of dead-stock SKUs (positive stock, no outbound activity within threshold).
     *
     * KPI purpose: Executive snapshot "SKU Stok Mati" — idle capital indicator.
     *
     * Formula (LOCKED): `COUNT(P)` where `S(P) > 0` AND
     * (`last_out_date IS NULL` OR `last_out_date < today - $days`).
     *
     * Source tables: `trx_inventory_movements` (derived stock, last outbound date), `inv_products`.
     *
     * Governance: Default `$days = 90` per Sprint 15.5/16.7 KPI Lock Matrix. Single-branch scope.
     *
     * Return type: `int` — non-negative count; returns `0` when none qualify.
     */
    public function getDeadStockCount(int $branchId, int $days = 90): int;

    /**
     * Count of open purchase requests not fully converted to purchase orders.
     *
     * KPI purpose: Executive snapshot demand pipeline — unfulfilled procurement intent.
     *
     * Formula (LOCKED): `COUNT(PR)` where status ∈ `{submitted, approved}` and PR is not fully converted.
     *
     * Source tables: `trx_purchase_requests` (+ items for conversion check against `trx_purchase_orders`).
     *
     * Governance: Single-branch scope (`branch_id = $branchId`). Excludes draft, rejected, cancelled.
     *
     * Return type: `int` — non-negative count; returns `0` when none qualify.
     */
    public function getOpenPurchaseRequestCount(int $branchId): int;

    /**
     * Count of open purchase orders representing active supplier commitment.
     *
     * KPI purpose: Executive snapshot "PO Terbuka" — outstanding purchase obligations.
     *
     * Formula (LOCKED): `COUNT(PO)` where status ∈ `{approved, sent, partially_received}`.
     * Status `sent` is included — PO dispatched to supplier but not fully received.
     *
     * Source tables: `trx_purchase_orders`.
     *
     * Governance: Excludes `draft`, `submitted`, `fully_received`, `cancelled`. Single-branch scope.
     *
     * Return type: `int` — non-negative count; returns `0` when none qualify.
     */
    public function getOpenPurchaseOrderCount(int $branchId): int;

    /**
     * Count of goods receipts awaiting ledger posting.
     *
     * KPI purpose: Executive snapshot "Penerimaan Draft" — inbound pipeline not yet in ledger.
     *
     * Formula (LOCKED): `COUNT(GR)` where status ∈ `{draft, submitted}` (not posted).
     *
     * Source tables: `trx_goods_receipts`.
     *
     * Governance: Posted (`posted`) and terminal (`cancelled`, `void`) statuses excluded. Single-branch scope.
     *
     * Return type: `int` — non-negative count; returns `0` when none qualify.
     */
    public function getPendingGoodsReceiptCount(int $branchId): int;

    /**
     * Count of stock transfers in transit (shipped, not yet received).
     *
     * KPI purpose: Executive snapshot in-transit exposure — stock between locations/branches.
     *
     * Formula (LOCKED): `COUNT(transfer)` where status = `in_transit` (issued/shipped, not received).
     *
     * Source tables: `trx_stock_transfers`.
     *
     * Governance: Aligns with Sprint 15.2 two-phase ship/receive workflow. Single-branch scope.
     *
     * Return type: `int` — non-negative count; returns `0` when none in transit.
     */
    public function getInTransitTransferCount(int $branchId): int;

    /**
     * Inventory accuracy percentage from completed stock opnames.
     *
     * KPI purpose: Executive snapshot "Akurasi Persediaan" — physical vs ledger reconciliation quality.
     *
     * Formula (LOCKED): `accuracy = 1 - (SUM(ABS(variance_quantity)) / SUM(system_quantity))`,
     * clamped to 0–100%. Computed only when ≥ 1 opname with status `COMPLETED` exists.
     *
     * Source tables: `trx_stock_opnames` (status `COMPLETED`), `trx_stock_opname_items`
     * (`variance_quantity`, `system_quantity`).
     *
     * Governance: Returns `null` (not `0%`) when no completed opname exists — UI must show
     * "Belum ada stock opname selesai." Draft/in-progress opnames are excluded.
     *
     * Return type: `?float` — percentage 0.0–100.0, or `null` when insufficient opname data.
     */
    public function getInventoryAccuracy(int $branchId): ?float;

    /**
     * Top fast-moving products ranked by outbound quantity in the lookback period.
     *
     * KPI purpose: Movement intelligence — highest consumption velocity products.
     *
     * Formula (LOCKED): Order by `SUM(quantity_out)` in period DESC; top `$limit` products.
     * Consumption includes all outbound movement types (`ADJUSTMENT_OUT`, `TRANSFER_OUT`, etc.).
     *
     * Source tables: `trx_inventory_movements`, `inv_products`.
     *
     * Governance: Single-branch scope. Default `$days = 90`, `$limit = 10`. Active products only.
     *
     * Return type: `Collection` — ordered list of associative rows (not paginated).
     * Each row shape:
     * `{product_id, product_code, product_name, current_stock, outbound_qty_period, outbound_value_period, stock_value}`.
     *
     * @return Collection<int, array{
     *     product_id: int,
     *     product_code: string,
     *     product_name: string,
     *     current_stock: float,
     *     outbound_qty_period: float,
     *     outbound_value_period: float,
     *     stock_value: float,
     * }>
     */
    public function getFastMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection;

    /**
     * Top slow-moving products (positive stock, outbound at or below threshold).
     *
     * KPI purpose: Movement intelligence — products with low turnover despite on-hand stock.
     *
     * Formula (LOCKED): `S(P) > 0` AND `OUT(P) ≤ slow_moving_threshold` in period;
     * ordered by stock DESC then outbound ASC.
     *
     * Source tables: `trx_inventory_movements`, `inv_products`.
     *
     * Governance: Reuses Sprint 15.5 slow-moving definition. Single-branch scope. Default `$days = 90`.
     *
     * Return type: `Collection` — ordered list capped at `$limit`.
     * Each row shape:
     * `{product_id, product_code, product_name, current_stock, outbound_qty_period, outbound_value_period, stock_value}`.
     *
     * @return Collection<int, array{
     *     product_id: int,
     *     product_code: string,
     *     product_name: string,
     *     current_stock: float,
     *     outbound_qty_period: float,
     *     outbound_value_period: float,
     *     stock_value: float,
     * }>
     */
    public function getSlowMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection;

    /**
     * Top dead-stock products (positive stock, stale outbound activity).
     *
     * KPI purpose: Movement intelligence — idle inventory candidates for write-off or promotion.
     *
     * Formula (LOCKED): `S(P) > 0` AND (`last_out_date IS NULL` OR `last_out_date < today - $days`);
     * ordered by days-since-last-out DESC.
     *
     * Source tables: `trx_inventory_movements`, `inv_products`.
     *
     * Governance: Default `$days = 90`. Single-branch scope.
     *
     * Return type: `Collection` — ordered list capped at `$limit`.
     * Each row shape:
     * `{product_id, product_code, product_name, current_stock, stock_value, last_out_date, days_since_last_out}`.
     *
     * @return Collection<int, array{
     *     product_id: int,
     *     product_code: string,
     *     product_name: string,
     *     current_stock: float,
     *     stock_value: float,
     *     last_out_date: string|null,
     *     days_since_last_out: int|null,
     * }>
     */
    public function getDeadStockItems(int $branchId, int $days = 90, int $limit = 10): Collection;

    /**
     * Stock aging buckets and line items for the branch.
     *
     * KPI purpose: Valuation & aging section — rotation health by age since last inbound/batch receipt.
     *
     * Formula (LOCKED): Bucket by days since `received_date` (batch) or `last_in_date` (product proxy):
     * fresh (0–30), aging (31–60), stale (61–90), old (91–180), very_old (>180).
     *
     * Source tables: `trx_inventory_movements`, `inv_products`, `inv_inventory_batches`.
     *
     * Governance: Reuses Sprint 15.5 aging buckets. Product-level granularity for repository contract;
     * service may expose batch granularity via filters. Single-branch scope.
     *
     * Return type: `array` — structured bucket summary plus item list (fixed keys for chart/table consumers).
     *
     * @return array{
     *     granularity: string,
     *     buckets: array<string, array{
     *         label: string,
     *         product_count: int,
     *         total_qty: float,
     *         total_value: float,
     *     }>,
     *     items: Collection<int, array<string, mixed>>,
     * }
     */
    public function getStockAging(int $branchId): array;

    /**
     * Purchase activity time series (PO created, GR posted, ledger PURCHASE).
     *
     * KPI purpose: Executive trend chart "Tren Pembelian" — procurement spending velocity.
     *
     * Formula (LOCKED): Monthly aggregates of PO count/value, GR posted count/received value,
     * and ledger `PURCHASE` inbound value grouped by `DATE_TRUNC` on respective date fields.
     *
     * Source tables: `trx_purchase_orders`, `trx_goods_receipts`, `trx_inventory_movements` (`PURCHASE`).
     *
     * Governance: `$period = 'monthly'` is the MVP default. Cross-branch allowed only when service
     * passes an authorized multi-branch scope (opt-in `view_inventory_cross_branch`).
     *
     * Return type: `array` — chronologically ordered period rows (not paginated).
     *
     * @return array<int, array{
     *     period: string,
     *     po_count: int,
     *     po_value: float,
     *     gr_count: int,
     *     gr_received_value: float,
     *     ledger_purchase_value: float,
     * }>
     */
    public function getPurchaseTrend(int $branchId, string $period = 'monthly'): array;

    /**
     * Outbound consumption time series.
     *
     * KPI purpose: Executive trend chart "Tren Konsumsi" — material usage velocity.
     *
     * Formula (LOCKED): `Consumption = SUM(quantity_out)` per period, including `ADJUSTMENT_OUT`,
     * `TRANSFER_OUT`, and all other outbound movement types. Value = `quantity_out × average_cost`.
     *
     * Source tables: `trx_inventory_movements`, `inv_products` (`average_cost`).
     *
     * Governance: Consumption is total outbound inventory — NOT pure production usage alone.
     * Consistent with Sprint 15.5 `getMonthlyOutboundValueTrend`. Single-branch default.
     *
     * Return type: `array` — chronologically ordered period rows.
     *
     * @return array<int, array{
     *     period: string,
     *     outbound_qty: float,
     *     outbound_value: float,
     * }>
     */
    public function getConsumptionTrend(int $branchId, string $period = 'monthly'): array;

    /**
     * Supplier performance metrics ranked for the branch.
     *
     * KPI purpose: Supplier performance table — vendor reliability and spend concentration.
     *
     * Metrics (LOCKED): order count, order value, received value, fulfillment rate,
     * on-time delivery % (PO with `expected_delivery_date` only), coverage %,
     * average lead time, purchase ledger share, cancelled PO rate.
     *
     * Source tables: `trx_purchase_orders`, `trx_goods_receipts`, `trx_inventory_movements` (`PURCHASE`),
     * `inv_suppliers`.
     *
     * Governance: Active suppliers (`is_active = true`, `branch_id = $branchId`). PO without
     * `expected_delivery_date` excluded from on-time denominator but included in coverage calculation.
     * Single-branch scope.
     *
     * Return type: `Collection` — ranked by received value DESC.
     * Each row shape documented for service/view mapping.
     *
     * @return Collection<int, array{
     *     supplier_id: int,
     *     supplier_name: string,
     *     order_count: int,
     *     order_value: float,
     *     received_value: float,
     *     fulfillment_rate: float|null,
     *     on_time_delivery_rate: float|null,
     *     coverage_percentage: float,
     *     avg_lead_time_days: float|null,
     *     purchase_ledger_share: float,
     *     cancelled_po_rate: float|null,
     * }>
     */
    public function getSupplierPerformance(int $branchId): Collection;

    /**
     * Actionable reorder candidates at or below effective reorder point.
     *
     * KPI purpose: Reorder recommendation table — purchasing action list with suggested quantities.
     *
     * Formula (LOCKED): `is_active = true` AND `alert_enabled = true` AND `S(P) ≤ effective_reorder_point(P)`.
     * Suggested qty derived from avg daily consumption, lead time, and safety gap (service-layer constants).
     *
     * Source tables: `trx_inventory_movements`, `inv_products` (`reorder_point`, `minimum_stock`,
     * `alert_enabled`), latest `PURCHASE` movement per product for preferred supplier.
     *
     * Governance: Does not auto-create PR — returns data for service to build prefill links.
     * Severity (`critical`, `low`, `watch`) computed in service from `minimum_stock` proximity.
     * Single-branch scope.
     *
     * Return type: `Collection` — priority-sorted candidates (critical first).
     * Each row shape:
     * `{product_id, product_code, product_name, current_stock, reorder_point, minimum_stock,
     *   safety_stock_gap, avg_daily_consumption, suggested_order_qty, est_days_until_stockout,
     *   preferred_supplier_id, severity}`.
     *
     * @return Collection<int, array{
     *     product_id: int,
     *     product_code: string,
     *     product_name: string,
     *     current_stock: float,
     *     reorder_point: float,
     *     minimum_stock: float,
     *     safety_stock_gap: float,
     *     avg_daily_consumption: float,
     *     suggested_order_qty: float,
     *     est_days_until_stockout: float|null,
     *     preferred_supplier_id: int|null,
     *     severity: string,
     * }>
     */
    public function getReorderRecommendations(int $branchId): Collection;
}
