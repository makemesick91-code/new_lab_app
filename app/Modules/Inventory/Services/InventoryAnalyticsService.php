<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Official analytics entry point for inventory KPIs (Sprint 16.7).
 *
 * ## Data source guardrails (LOCKED)
 *
 * - KPI aggregates MUST use ledger, procurement, and opname tables via {@see InventoryAnalyticsRepositoryInterface}.
 * - `inv_inventory_activity_logs` is FORBIDDEN as a KPI source — audit/drill-down only.
 * - Inventory value is operational only (`average_cost` × derived stock) — not FIFO/LIFO/WAC accounting valuation.
 * - Inventory accuracy returns `null` when no completed stock opname exists (never fake 0%).
 * - Open PO count includes `approved`, `sent`, and `partially_received` statuses.
 * - Consumption = SUM(quantity_out) including TRANSFER_OUT and ADJUSTMENT_OUT.
 */
class InventoryAnalyticsService
{
    public const OPERATIONAL_VALUATION_NOTE = 'Operational inventory value based on current stock × average cost. Not accounting valuation.';

    public const VALUATION_TYPE_OPERATIONAL = 'operational';

    public const DEFAULT_PERIOD_DAYS = 30;

    public const DEFAULT_FAST_LIMIT = 25;

    public const DEFAULT_SLOW_THRESHOLD = 1.0;

    public const DEFAULT_DEAD_STOCK_DAYS = 90;

    public const AGING_BUCKET_FRESH_MAX = 30;

    public const AGING_BUCKET_AGING_MAX = 60;

    public const AGING_BUCKET_STALE_MAX = 90;

    public const AGING_BUCKET_OLD_MAX = 180;

    public const BUCKET_FRESH = 'fresh';

    public const BUCKET_AGING = 'aging';

    public const BUCKET_STALE = 'stale';

    public const BUCKET_OLD = 'old';

    public const BUCKET_VERY_OLD = 'very_old';

    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly InventoryBatchRepositoryInterface $batches,
        private readonly InventoryAnalyticsRepositoryInterface $analyticsRepository,
    ) {}

    /**
     * Executive overview — repository KPIs plus movement intelligence from Sprint 15.5.
     *
     * @return array<string, mixed>
     */
    public function getInventoryOverview(int $branchId): array
    {
        $movementSummary = $this->getAnalyticsSummary($branchId);

        return [
            'inventory_value' => $this->analyticsRepository->getInventoryValue($branchId),
            'active_sku' => $this->analyticsRepository->getActiveSkuCount($branchId),
            'low_stock_count' => $this->analyticsRepository->getLowStockCount($branchId),
            'dead_stock_count' => $this->analyticsRepository->getDeadStockCount($branchId),
            'open_pr' => $this->analyticsRepository->getOpenPurchaseRequestCount($branchId),
            'open_po' => $this->analyticsRepository->getOpenPurchaseOrderCount($branchId),
            'pending_gr' => $this->analyticsRepository->getPendingGoodsReceiptCount($branchId),
            'in_transit_transfer' => $this->analyticsRepository->getInTransitTransferCount($branchId),
            'inventory_accuracy' => $this->analyticsRepository->getInventoryAccuracy($branchId),
            'fast_moving_count' => $movementSummary['fast_moving_count'],
            'slow_moving_count' => $movementSummary['slow_moving_count'],
            'branch_turnover_ratio' => $movementSummary['branch_turnover_ratio'],
            'period_outbound_value' => $movementSummary['period_outbound_value'],
            'period_from' => $movementSummary['period_from'],
            'period_to' => $movementSummary['period_to'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Operational stock valuation snapshot — not accounting-grade costing.
     *
     * @return array{
     *     total_value: float,
     *     valuation_type: string,
     *     valuation_note: string,
     *     generated_at: string,
     * }
     */
    public function getStockValuation(int $branchId): array
    {
        return [
            'total_value' => $this->analyticsRepository->getInventoryValue($branchId),
            'valuation_type' => self::VALUATION_TYPE_OPERATIONAL,
            'valuation_note' => self::OPERATIONAL_VALUATION_NOTE,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function getFastMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        return $this->analyticsRepository->getFastMovingItems($branchId, $days, $limit);
    }

    public function getSlowMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        return $this->analyticsRepository->getSlowMovingItems($branchId, $days, $limit);
    }

    public function getDeadStockItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        return $this->analyticsRepository->getDeadStockItems($branchId, $days, $limit);
    }

    /**
     * @return array{
     *     granularity: string,
     *     buckets: array<string, array{label: string, product_count: int, total_qty: float, total_value: float}>,
     *     items: Collection<int, array<string, mixed>>,
     * }
     */
    public function getStockAging(int $branchId): array
    {
        return $this->analyticsRepository->getStockAging($branchId);
    }

    /**
     * @return array<int, array{
     *     period: string,
     *     po_count: int,
     *     po_value: float,
     *     gr_count: int,
     *     gr_received_value: float,
     *     ledger_purchase_value: float,
     * }>
     */
    public function getPurchaseTrend(int $branchId, string $period = 'monthly'): array
    {
        return $this->analyticsRepository->getPurchaseTrend($branchId, $period);
    }

    /**
     * @return array<int, array{period: string, outbound_qty: float, outbound_value: float}>
     */
    public function getConsumptionTrend(int $branchId, string $period = 'monthly'): array
    {
        return $this->analyticsRepository->getConsumptionTrend($branchId, $period);
    }

    public function getSupplierPerformance(int $branchId): Collection
    {
        return $this->analyticsRepository->getSupplierPerformance($branchId);
    }

    public function getReorderRecommendations(int $branchId): Collection
    {
        return $this->analyticsRepository->getReorderRecommendations($branchId);
    }

    /**
     * Executive KPI strip — delegates all scalar counts to the analytics repository.
     *
     * @return array{
     *     inventory_value: float,
     *     active_sku: int,
     *     low_stock_count: int,
     *     dead_stock_count: int,
     *     open_pr: int,
     *     open_po: int,
     *     pending_gr: int,
     *     in_transit_transfer: int,
     *     inventory_accuracy: float|null,
     * }
     */
    public function getKpiSummary(int $branchId): array
    {
        return [
            'inventory_value' => $this->analyticsRepository->getInventoryValue($branchId),
            'active_sku' => $this->analyticsRepository->getActiveSkuCount($branchId),
            'low_stock_count' => $this->analyticsRepository->getLowStockCount($branchId),
            'dead_stock_count' => $this->analyticsRepository->getDeadStockCount($branchId),
            'open_pr' => $this->analyticsRepository->getOpenPurchaseRequestCount($branchId),
            'open_po' => $this->analyticsRepository->getOpenPurchaseOrderCount($branchId),
            'pending_gr' => $this->analyticsRepository->getPendingGoodsReceiptCount($branchId),
            'in_transit_transfer' => $this->analyticsRepository->getInTransitTransferCount($branchId),
            'inventory_accuracy' => $this->analyticsRepository->getInventoryAccuracy($branchId),
        ];
    }

    /**
     * Sprint 15.5 alias — preserves backward compatibility for consumers expecting this name.
     *
     * @return array{
     *     granularity: string,
     *     buckets: array<string, array{label: string, product_count: int, total_qty: float, total_value: float}>,
     *     items: Collection<int, array<string, mixed>>,
     * }
     */
    public function getStockAgingAnalysis(int $branchId, array $filters = []): array
    {
        return $this->getInventoryAging($branchId, $filters);
    }

    public function getFastMovingProducts(int $branchId, array $filters = []): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $outbound = $this->movements->outboundByProductInPeriod($branchId, $filters)
            ->keyBy('product_id');

        if ($outbound->isEmpty()) {
            return collect();
        }

        $products = $this->loadActiveProducts($branchId, $filters, $outbound->keys()->all())
            ->filter(fn (Product $product) => (float) ($outbound[$product->id]->outbound_qty ?? 0) > 0);

        return $products
            ->map(function (Product $product) use ($outbound) {
                $outQty = (float) $outbound[$product->id]->outbound_qty;
                $currentStock = (float) $product->current_stock;

                return $this->productRow($product, [
                    'current_stock' => $currentStock,
                    'outbound_qty_period' => $outQty,
                    'outbound_value_period' => $outQty * (float) $product->average_cost,
                    'stock_value' => $currentStock * (float) $product->average_cost,
                ]);
            })
            ->sort(function (array $a, array $b) {
                $qtyCompare = $b['outbound_qty_period'] <=> $a['outbound_qty_period'];

                if ($qtyCompare !== 0) {
                    return $qtyCompare;
                }

                return strcasecmp($a['product_name'], $b['product_name']);
            })
            ->values()
            ->take($filters['limit']);
    }

    public function getSlowMovingProducts(int $branchId, array $filters = []): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $threshold = (float) $filters['slow_moving_threshold'];

        $outbound = $this->movements->outboundByProductInPeriod($branchId, $filters)
            ->keyBy('product_id');

        $products = $this->loadActiveProducts($branchId, $filters)
            ->filter(function (Product $product) use ($outbound, $threshold) {
                $currentStock = (float) $product->current_stock;

                if ($currentStock <= 0) {
                    return false;
                }

                $outQty = (float) ($outbound[$product->id]->outbound_qty ?? 0);

                return $outQty <= $threshold;
            });

        return $products
            ->map(function (Product $product) use ($outbound) {
                $outQty = (float) ($outbound[$product->id]->outbound_qty ?? 0);
                $currentStock = (float) $product->current_stock;

                return $this->productRow($product, [
                    'current_stock' => $currentStock,
                    'outbound_qty_period' => $outQty,
                    'outbound_value_period' => $outQty * (float) $product->average_cost,
                    'stock_value' => $currentStock * (float) $product->average_cost,
                ]);
            })
            ->sort(function (array $a, array $b) {
                $outCompare = $a['outbound_qty_period'] <=> $b['outbound_qty_period'];

                if ($outCompare !== 0) {
                    return $outCompare;
                }

                $stockCompare = $b['current_stock'] <=> $a['current_stock'];

                if ($stockCompare !== 0) {
                    return $stockCompare;
                }

                return strcasecmp($a['product_name'], $b['product_name']);
            })
            ->values()
            ->take($filters['limit']);
    }

    public function getDeadStockProducts(int $branchId, array $filters = []): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $deadStockDays = (int) $filters['dead_stock_days'];
        $cutoffDate = now()->subDays($deadStockDays)->toDateString();

        $lastOutByProduct = $this->movements
            ->lastOutboundDateByProduct($branchId, $filters['inventory_location_id'] ?? null)
            ->keyBy('product_id');

        $products = $this->loadActiveProducts($branchId, $filters)
            ->filter(function (Product $product) use ($lastOutByProduct, $cutoffDate) {
                $currentStock = (float) $product->current_stock;

                if ($currentStock <= 0) {
                    return false;
                }

                $lastOut = $lastOutByProduct[$product->id]->last_out_date ?? null;

                if ($lastOut === null) {
                    return true;
                }

                return Carbon::parse($lastOut)->toDateString() < $cutoffDate;
            });

        return $products
            ->map(function (Product $product) use ($lastOutByProduct) {
                $currentStock = (float) $product->current_stock;
                $lastOut = $lastOutByProduct[$product->id]->last_out_date ?? null;
                $daysSinceLastOut = $lastOut !== null
                    ? (int) Carbon::parse($lastOut)->startOfDay()->diffInDays(now()->startOfDay())
                    : null;

                return $this->productRow($product, [
                    'current_stock' => $currentStock,
                    'stock_value' => $currentStock * (float) $product->average_cost,
                    'last_out_date' => $lastOut !== null ? Carbon::parse($lastOut)->toDateString() : null,
                    'days_since_last_out' => $daysSinceLastOut,
                ]);
            })
            ->sort(function (array $a, array $b) {
                $daysA = $a['days_since_last_out'] ?? PHP_INT_MAX;
                $daysB = $b['days_since_last_out'] ?? PHP_INT_MAX;
                $daysCompare = $daysB <=> $daysA;

                if ($daysCompare !== 0) {
                    return $daysCompare;
                }

                return $b['current_stock'] <=> $a['current_stock'];
            })
            ->values()
            ->take($filters['limit']);
    }

    /**
     * @return array{
     *     granularity: string,
     *     buckets: array<string, array{label: string, product_count: int, total_qty: float, total_value: float}>,
     *     items: Collection<int, array<string, mixed>>,
     * }
     */
    public function getInventoryAging(int $branchId, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $granularity = $filters['aging_granularity'] ?? 'product';

        if ($granularity === 'batch') {
            return $this->getBatchAging($branchId, $filters);
        }

        return $this->getProductAging($branchId, $filters);
    }

    public function getInventoryTurnover(int $branchId, array $filters = []): Collection
    {
        $filters = $this->normalizeFilters($filters);
        $dateFrom = $filters['date_from'];
        $dateTo = $filters['date_to'];

        $outbound = $this->movements->outboundByProductInPeriod($branchId, $filters)
            ->keyBy('product_id')
            ->filter(fn ($row) => (float) $row->outbound_qty > 0);

        if ($outbound->isEmpty()) {
            return collect();
        }

        $stockAtStart = $this->movements
            ->stockAtDate($branchId, Carbon::parse($dateFrom)->subDay()->toDateString(), $filters['inventory_location_id'] ?? null)
            ->keyBy('product_id');

        $stockAtEnd = $this->movements
            ->stockAtDate($branchId, $dateTo, $filters['inventory_location_id'] ?? null)
            ->keyBy('product_id');

        $products = $this->loadActiveProducts($branchId, $filters, $outbound->keys()->all());

        return $products
            ->map(function (Product $product) use ($outbound, $stockAtStart, $stockAtEnd) {
                $outQty = (float) $outbound[$product->id]->outbound_qty;
                $startStock = (float) ($stockAtStart[$product->id]->stock_at_date ?? 0);
                $endStock = (float) ($stockAtEnd[$product->id]->stock_at_date ?? 0);
                $avgStock = ($startStock + $endStock) / 2;
                $averageCost = (float) $product->average_cost;
                $outValue = $outQty * $averageCost;
                $avgStockValue = $avgStock * $averageCost;

                return $this->productRow($product, [
                    'current_stock' => (float) $product->current_stock,
                    'outbound_qty_period' => $outQty,
                    'outbound_value_period' => $outValue,
                    'avg_stock_period' => $avgStock,
                    'turnover_ratio_qty' => $avgStock > 0 ? round($outQty / $avgStock, 2) : null,
                    'turnover_ratio_value' => $avgStockValue > 0 ? round($outValue / $avgStockValue, 2) : null,
                    'stock_value' => (float) $product->current_stock * $averageCost,
                ]);
            })
            ->sortByDesc(fn (array $row) => $row['turnover_ratio_qty'] ?? 0)
            ->values()
            ->take($filters['limit']);
    }

    public function getInventoryValueByCategory(int $branchId, array $filters = []): Collection
    {
        $filters = $this->normalizeFilters($filters);

        return $this->movements->inventoryValueByCategory(
            $branchId,
            $filters['inventory_location_id'] ?? null,
            $filters['product_category_id'] ?? null,
        );
    }

    public function getInventoryValueByLocation(int $branchId, array $filters = []): Collection
    {
        return $this->movements->stockByLocationSummary($branchId);
    }

    /**
     * @return array<int, array{month: string, outbound_value: float}>
     */
    public function getMonthlyOutboundValueTrend(int $branchId, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);

        return $this->movements
            ->monthlyOutboundValue($branchId, $filters)
            ->map(fn ($row) => [
                'month' => Carbon::parse($row->month)->format('Y-m'),
                'outbound_value' => (float) $row->outbound_value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     fast_moving_count: int,
     *     slow_moving_count: int,
     *     dead_stock_count: int,
     *     inventory_value: float,
     *     period_outbound_value: float,
     *     branch_turnover_ratio: float|null,
     *     period_from: string,
     *     period_to: string,
     * }
     */
    public function getAnalyticsSummary(int $branchId, array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $monthlyTrend = $this->getMonthlyOutboundValueTrend($branchId, $filters);
        $turnoverRows = $this->getInventoryTurnover($branchId, array_merge($filters, ['limit' => 100]));

        $totalOutQty = (float) $turnoverRows->sum('outbound_qty_period');
        $totalAvgStock = (float) $turnoverRows->sum('avg_stock_period');
        $branchTurnover = $totalAvgStock > 0 ? round($totalOutQty / $totalAvgStock, 2) : null;

        return [
            'fast_moving_count' => $this->getFastMovingProducts($branchId, array_merge($filters, ['limit' => 100]))->count(),
            'slow_moving_count' => $this->getSlowMovingProducts($branchId, array_merge($filters, ['limit' => 100]))->count(),
            'dead_stock_count' => $this->getDeadStockProducts($branchId, array_merge($filters, ['limit' => 100]))->count(),
            'inventory_value' => $this->movements->inventoryValue($branchId, $filters['inventory_location_id'] ?? null),
            'period_outbound_value' => (float) collect($monthlyTrend)->sum('outbound_value'),
            'branch_turnover_ratio' => $branchTurnover,
            'period_from' => $filters['date_from'],
            'period_to' => $filters['date_to'],
        ];
    }

    public function resolveAgeBucket(int $ageDays): string
    {
        if ($ageDays <= self::AGING_BUCKET_FRESH_MAX) {
            return self::BUCKET_FRESH;
        }

        if ($ageDays <= self::AGING_BUCKET_AGING_MAX) {
            return self::BUCKET_AGING;
        }

        if ($ageDays <= self::AGING_BUCKET_STALE_MAX) {
            return self::BUCKET_STALE;
        }

        if ($ageDays <= self::AGING_BUCKET_OLD_MAX) {
            return self::BUCKET_OLD;
        }

        return self::BUCKET_VERY_OLD;
    }

    public function ageBucketLabel(string $bucket): string
    {
        return match ($bucket) {
            self::BUCKET_FRESH => '0–30 Hari',
            self::BUCKET_AGING => '31–60 Hari',
            self::BUCKET_STALE => '61–90 Hari',
            self::BUCKET_OLD => '91–180 Hari',
            self::BUCKET_VERY_OLD => '>180 Hari',
            default => $bucket,
        };
    }

    /**
     * @return array{
     *     granularity: string,
     *     buckets: array<string, array{label: string, product_count: int, total_qty: float, total_value: float}>,
     *     items: Collection<int, array<string, mixed>>,
     * }
     */
    private function getProductAging(int $branchId, array $filters): array
    {
        $lastInByProduct = $this->movements
            ->lastInboundDateByProduct($branchId, $filters['inventory_location_id'] ?? null)
            ->keyBy('product_id');

        $products = $this->loadActiveProducts($branchId, $filters)
            ->filter(fn (Product $product) => (float) $product->current_stock > 0);

        $items = $products
            ->map(function (Product $product) use ($lastInByProduct) {
                $currentStock = (float) $product->current_stock;
                $lastIn = $lastInByProduct[$product->id]->last_in_date ?? null;
                $ageDays = $lastIn !== null
                    ? (int) Carbon::parse($lastIn)->startOfDay()->diffInDays(now()->startOfDay())
                    : 0;
                $bucket = $this->resolveAgeBucket($ageDays);

                return $this->productRow($product, [
                    'current_stock' => $currentStock,
                    'stock_value' => $currentStock * (float) $product->average_cost,
                    'last_in_date' => $lastIn !== null ? Carbon::parse($lastIn)->toDateString() : null,
                    'age_days' => $ageDays,
                    'age_bucket' => $bucket,
                ]);
            })
            ->sortByDesc('age_days')
            ->values();

        return [
            'granularity' => 'product',
            'buckets' => $this->summarizeAgingBuckets($items),
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *     granularity: string,
     *     buckets: array<string, array{label: string, product_count: int, total_qty: float, total_value: float}>,
     *     items: Collection<int, array<string, mixed>>,
     * }
     */
    private function getBatchAging(int $branchId, array $filters): array
    {
        $batches = $this->batches->batchStockWithAge($branchId, $filters['inventory_location_id'] ?? null);

        if (($filters['product_category_id'] ?? null) !== null) {
            $categoryId = (int) $filters['product_category_id'];
            $productIds = Product::query()
                ->where('branch_id', $branchId)
                ->where('product_category_id', $categoryId)
                ->pluck('id');

            $batches = $batches->whereIn('product_id', $productIds);
        }

        $items = $batches
            ->map(function ($batch) {
                $ageAnchor = $batch->age_anchor_date ?? $batch->received_date;
                $ageDays = $ageAnchor !== null
                    ? (int) Carbon::parse($ageAnchor)->startOfDay()->diffInDays(now()->startOfDay())
                    : 0;
                $bucket = $this->resolveAgeBucket($ageDays);
                $batchStock = (float) $batch->batch_stock;
                $averageCost = (float) $batch->average_cost;

                return [
                    'inventory_batch_id' => (int) $batch->inventory_batch_id,
                    'batch_number' => $batch->batch_number,
                    'lot_number' => $batch->lot_number,
                    'product_id' => (int) $batch->product_id,
                    'product_code' => $batch->product_code,
                    'product_name' => $batch->product_name,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'received_date' => $batch->received_date?->format('Y-m-d'),
                    'age_days' => $ageDays,
                    'age_bucket' => $bucket,
                    'batch_stock' => $batchStock,
                    'batch_value' => $batchStock * $averageCost,
                    'inventory_location_id' => (int) $batch->inventory_location_id,
                    'inventory_location_name' => $batch->inventory_location_name,
                ];
            })
            ->sortByDesc('age_days')
            ->values();

        return [
            'granularity' => 'batch',
            'buckets' => $this->summarizeAgingBuckets($items, qtyKey: 'batch_stock', valueKey: 'batch_value'),
            'items' => $items,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, array{label: string, product_count: int, total_qty: float, total_value: float}>
     */
    private function summarizeAgingBuckets(Collection $items, string $qtyKey = 'current_stock', string $valueKey = 'stock_value'): array
    {
        $bucketKeys = [
            self::BUCKET_FRESH,
            self::BUCKET_AGING,
            self::BUCKET_STALE,
            self::BUCKET_OLD,
            self::BUCKET_VERY_OLD,
        ];

        $summary = [];

        foreach ($bucketKeys as $bucket) {
            $bucketItems = $items->where('age_bucket', $bucket);
            $summary[$bucket] = [
                'label' => $this->ageBucketLabel($bucket),
                'product_count' => $bucketItems->count(),
                'total_qty' => (float) $bucketItems->sum($qtyKey),
                'total_value' => (float) $bucketItems->sum($valueKey),
            ];
        }

        return $summary;
    }

    /**
     * @param  array<int>  $productIds
     */
    private function loadActiveProducts(int $branchId, array $filters, ?array $productIds = null): Collection
    {
        $products = $this->movements->productsWithDerivedStock(
            $branchId,
            $filters['inventory_location_id'] ?? null,
        );

        if ($productIds !== null) {
            $products = $products->whereIn('id', $productIds);
        }

        if (($filters['product_category_id'] ?? null) !== null) {
            $products = $products->where('product_category_id', (int) $filters['product_category_id']);
        }

        if (! ($filters['include_inactive'] ?? false)) {
            $products = $products->where('is_active', true);
        }

        return $products->values();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function productRow(Product $product, array $extra = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'product_code' => $product->code,
            'product_name' => $product->name,
            'category_name' => $product->category?->name,
            'unit_symbol' => $product->unit?->symbol,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $dateTo = $filters['date_to'] ?? now()->toDateString();
        $dateFrom = $filters['date_from'] ?? now()->subDays(self::DEFAULT_PERIOD_DAYS)->toDateString();

        return array_merge([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'limit' => self::DEFAULT_FAST_LIMIT,
            'slow_moving_threshold' => self::DEFAULT_SLOW_THRESHOLD,
            'dead_stock_days' => self::DEFAULT_DEAD_STOCK_DAYS,
            'aging_granularity' => 'product',
            'include_inactive' => false,
            'inventory_location_id' => null,
            'product_category_id' => null,
        ], $filters);
    }
}
