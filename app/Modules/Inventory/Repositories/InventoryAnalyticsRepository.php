<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsRepository implements InventoryAnalyticsRepositoryInterface
{
    public const DEFAULT_LEAD_TIME_DAYS = 14;

    public const REORDER_LOOKBACK_DAYS = 30;

    public const DEFAULT_MIN_ORDER_QTY = 1;

    public const TREND_MONTH_COUNT = 6;

    public const SLOW_MOVING_THRESHOLD = 1.0;

    private const BUCKET_FRESH = 'fresh';

    private const BUCKET_AGING = 'aging';

    private const BUCKET_STALE = 'stale';

    private const BUCKET_OLD = 'old';

    private const BUCKET_VERY_OLD = 'very_old';

    public function __construct(
        private readonly InventoryMovementRepositoryInterface $movements,
    ) {}

    public function getInventoryValue(int $branchId): float
    {
        $stock = $this->stockSubquery($branchId);

        $value = Product::query()
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_products.is_active', true)
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->selectRaw('COALESCE(SUM(COALESCE(stock.current_stock, 0) * inv_products.average_cost), 0) as inventory_value')
            ->value('inventory_value');

        return (float) $value;
    }

    public function getActiveSkuCount(int $branchId): int
    {
        $stock = $this->stockSubquery($branchId);

        return (int) Product::query()
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_products.is_active', true)
            ->joinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->where('stock.current_stock', '>', 0)
            ->count();
    }

    public function getLowStockCount(int $branchId): int
    {
        $stock = $this->stockSubquery($branchId);

        return (int) Product::query()
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_products.is_active', true)
            ->where('inv_products.alert_enabled', true)
            ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->whereRaw(
                'COALESCE(stock.current_stock, 0) <= '.$this->effectiveReorderPointSql('inv_products')
            )
            ->count();
    }

    public function getDeadStockCount(int $branchId, int $days = 90): int
    {
        $cutoffDate = now()->subDays($days)->toDateString();
        $stock = $this->stockSubquery($branchId);
        $lastOut = $this->lastOutboundSubquery($branchId);

        return (int) Product::query()
            ->where('inv_products.branch_id', $branchId)
            ->where('inv_products.is_active', true)
            ->joinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
            ->where('stock.current_stock', '>', 0)
            ->leftJoinSub($lastOut, 'last_out', fn ($join) => $join->on('last_out.product_id', '=', 'inv_products.id'))
            ->where(function ($query) use ($cutoffDate) {
                $query->whereNull('last_out.last_out_date')
                    ->orWhereDate('last_out.last_out_date', '<', $cutoffDate);
            })
            ->count();
    }

    public function getOpenPurchaseRequestCount(int $branchId): int
    {
        return (int) PurchaseRequest::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                PurchaseRequest::STATUS_SUBMITTED,
                PurchaseRequest::STATUS_APPROVED,
            ])
            ->whereDoesntHave('purchaseOrders', function ($query) {
                $query->where('status', '!=', PurchaseOrder::STATUS_CANCELLED);
            })
            ->count();
    }

    public function getOpenPurchaseOrderCount(int $branchId): int
    {
        return (int) PurchaseOrder::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->count();
    }

    public function getPendingGoodsReceiptCount(int $branchId): int
    {
        return (int) GoodsReceipt::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                GoodsReceipt::STATUS_DRAFT,
                GoodsReceipt::STATUS_SUBMITTED,
            ])
            ->count();
    }

    public function getInTransitTransferCount(int $branchId): int
    {
        return (int) StockTransfer::query()
            ->where('branch_id', $branchId)
            ->where('status', StockTransfer::STATUS_IN_TRANSIT)
            ->count();
    }

    public function getInventoryAccuracy(int $branchId): ?float
    {
        $hasCompleted = StockOpname::query()
            ->where('branch_id', $branchId)
            ->where('status', StockOpname::STATUS_COMPLETED)
            ->exists();

        if (! $hasCompleted) {
            return null;
        }

        $totals = StockOpnameItem::query()
            ->join('trx_stock_opnames', 'trx_stock_opnames.id', '=', 'trx_stock_opname_items.stock_opname_id')
            ->where('trx_stock_opnames.branch_id', $branchId)
            ->where('trx_stock_opnames.status', StockOpname::STATUS_COMPLETED)
            ->selectRaw('COALESCE(SUM(ABS(trx_stock_opname_items.variance_quantity)), 0) as total_variance')
            ->selectRaw('COALESCE(SUM(trx_stock_opname_items.system_quantity), 0) as total_system')
            ->first();

        $totalSystem = (float) ($totals->total_system ?? 0);

        if ($totalSystem <= 0) {
            return null;
        }

        $totalVariance = (float) ($totals->total_variance ?? 0);
        $accuracy = (1 - ($totalVariance / $totalSystem)) * 100;

        return round(max(0, min(100, $accuracy)), 2);
    }

    public function getFastMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        $dateFrom = now()->subDays($days)->toDateString();
        $dateTo = now()->toDateString();

        $outbound = $this->movements
            ->outboundByProductInPeriod($branchId, [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ])
            ->keyBy('product_id');

        if ($outbound->isEmpty()) {
            return collect();
        }

        $products = $this->movements
            ->productsWithDerivedStock($branchId)
            ->whereIn('id', $outbound->keys())
            ->where('is_active', true);

        return $products
            ->map(function (Product $product) use ($outbound) {
                $outQty = (float) ($outbound[$product->id]->outbound_qty ?? 0);
                $currentStock = (float) $product->current_stock;
                $averageCost = (float) $product->average_cost;

                return [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'current_stock' => $currentStock,
                    'outbound_qty_period' => $outQty,
                    'outbound_value_period' => $outQty * $averageCost,
                    'stock_value' => $currentStock * $averageCost,
                ];
            })
            ->filter(fn (array $row) => $row['outbound_qty_period'] > 0)
            ->sortByDesc('outbound_qty_period')
            ->take($limit)
            ->values();
    }

    public function getSlowMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        $dateFrom = now()->subDays($days)->toDateString();
        $dateTo = now()->toDateString();

        $outbound = $this->movements
            ->outboundByProductInPeriod($branchId, [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ])
            ->keyBy('product_id');

        return $this->movements
            ->productsWithDerivedStock($branchId)
            ->where('is_active', true)
            ->filter(function (Product $product) use ($outbound) {
                $currentStock = (float) $product->current_stock;

                if ($currentStock <= 0) {
                    return false;
                }

                $outQty = (float) ($outbound[$product->id]->outbound_qty ?? 0);

                return $outQty <= self::SLOW_MOVING_THRESHOLD;
            })
            ->map(function (Product $product) use ($outbound) {
                $outQty = (float) ($outbound[$product->id]->outbound_qty ?? 0);
                $currentStock = (float) $product->current_stock;
                $averageCost = (float) $product->average_cost;

                return [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'current_stock' => $currentStock,
                    'outbound_qty_period' => $outQty,
                    'outbound_value_period' => $outQty * $averageCost,
                    'stock_value' => $currentStock * $averageCost,
                ];
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
            ->take($limit)
            ->values();
    }

    public function getDeadStockItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        $cutoffDate = now()->subDays($days)->toDateString();

        $lastOutByProduct = $this->movements
            ->lastOutboundDateByProduct($branchId)
            ->keyBy('product_id');

        return $this->movements
            ->productsWithDerivedStock($branchId)
            ->where('is_active', true)
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
            })
            ->map(function (Product $product) use ($lastOutByProduct) {
                $currentStock = (float) $product->current_stock;
                $averageCost = (float) $product->average_cost;
                $lastOut = $lastOutByProduct[$product->id]->last_out_date ?? null;
                $daysSinceLastOut = $lastOut !== null
                    ? (int) Carbon::parse($lastOut)->startOfDay()->diffInDays(now()->startOfDay())
                    : null;

                return [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'current_stock' => $currentStock,
                    'stock_value' => $currentStock * $averageCost,
                    'last_out_date' => $lastOut !== null ? Carbon::parse($lastOut)->toDateString() : null,
                    'days_since_last_out' => $daysSinceLastOut,
                ];
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
            ->take($limit)
            ->values();
    }

    public function getStockAging(int $branchId): array
    {
        $batchAnchor = $this->batchAgeAnchorSubquery($branchId);
        $lastIn = $this->lastInboundSubquery($branchId);

        $products = $this->movements
            ->productsWithDerivedStock($branchId)
            ->where('is_active', true)
            ->filter(fn (Product $product) => (float) $product->current_stock > 0);

        $items = $products
            ->map(function (Product $product) use ($batchAnchor, $lastIn) {
                $currentStock = (float) $product->current_stock;
                $averageCost = (float) $product->average_cost;
                $batchDate = $batchAnchor[$product->id] ?? null;
                $lastInDate = $lastIn[$product->id]->last_in_date ?? null;
                $anchorDate = $batchDate ?? $lastInDate;
                $ageDays = $anchorDate !== null
                    ? (int) Carbon::parse($anchorDate)->startOfDay()->diffInDays(now()->startOfDay())
                    : 0;
                $bucket = $this->resolveAgeBucket($ageDays);

                return [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'current_stock' => $currentStock,
                    'stock_value' => $currentStock * $averageCost,
                    'age_anchor_date' => $anchorDate !== null ? Carbon::parse($anchorDate)->toDateString() : null,
                    'age_days' => $ageDays,
                    'age_bucket' => $bucket,
                ];
            })
            ->sortByDesc('age_days')
            ->values();

        return [
            'granularity' => 'product',
            'buckets' => $this->summarizeAgingBuckets($items),
            'items' => $items,
        ];
    }

    public function getPurchaseTrend(int $branchId, string $period = 'monthly'): array
    {
        $months = $this->trendMonthKeys();

        $poStats = $this->purchaseOrderTrendRows($branchId, $months);
        $grStats = $this->goodsReceiptTrendRows($branchId, $months);
        $ledgerStats = $this->ledgerPurchaseTrendRows($branchId, $months);

        return collect($months)
            ->map(function (string $month) use ($poStats, $grStats, $ledgerStats) {
                $po = $poStats[$month] ?? ['po_count' => 0, 'po_value' => 0.0];
                $gr = $grStats[$month] ?? ['gr_count' => 0, 'gr_received_value' => 0.0];
                $ledger = $ledgerStats[$month] ?? ['ledger_purchase_value' => 0.0];

                return [
                    'period' => $month,
                    'po_count' => (int) $po['po_count'],
                    'po_value' => (float) $po['po_value'],
                    'gr_count' => (int) $gr['gr_count'],
                    'gr_received_value' => (float) $gr['gr_received_value'],
                    'ledger_purchase_value' => (float) $ledger['ledger_purchase_value'],
                ];
            })
            ->values()
            ->all();
    }

    public function getConsumptionTrend(int $branchId, string $period = 'monthly'): array
    {
        $months = $this->trendMonthKeys();
        $monthExpression = $this->monthTruncExpression('trx_inventory_movements.movement_date');
        $dateFrom = now()->subMonths(self::TREND_MONTH_COUNT - 1)->startOfMonth()->toDateString();

        $rows = InventoryMovement::query()
            ->join('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
            ->selectRaw("{$monthExpression} as period_month")
            ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_out), 0) as outbound_qty')
            ->selectRaw(
                'COALESCE(SUM(trx_inventory_movements.quantity_out * COALESCE(trx_inventory_movements.unit_cost, inv_products.average_cost)), 0) as outbound_value'
            )
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->where('inv_products.branch_id', $branchId)
            ->where('trx_inventory_movements.quantity_out', '>', 0)
            ->whereDate('trx_inventory_movements.movement_date', '>=', $dateFrom)
            ->groupByRaw($monthExpression)
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->period_month)->format('Y-m'));

        return collect($months)
            ->map(function (string $month) use ($rows) {
                $row = $rows[$month] ?? null;

                return [
                    'period' => $month,
                    'outbound_qty' => (float) ($row->outbound_qty ?? 0),
                    'outbound_value' => (float) ($row->outbound_value ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    public function getSupplierPerformance(int $branchId): Collection
    {
        $suppliers = Supplier::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($suppliers->isEmpty()) {
            return collect();
        }

        $totalLedgerPurchase = (float) InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
            ->selectRaw('COALESCE(SUM(quantity_in * unit_cost), 0) as total_value')
            ->value('total_value');

        return $suppliers
            ->map(function (Supplier $supplier) use ($branchId, $totalLedgerPurchase) {
                return $this->buildSupplierPerformanceRow($branchId, $supplier, $totalLedgerPurchase);
            })
            ->sortByDesc('received_value')
            ->values();
    }

    public function getReorderRecommendations(int $branchId): Collection
    {
        $lookbackStart = now()->subDays(self::REORDER_LOOKBACK_DAYS)->toDateString();
        $outbound = $this->movements
            ->outboundByProductInPeriod($branchId, [
                'date_from' => $lookbackStart,
                'date_to' => now()->toDateString(),
            ])
            ->keyBy('product_id');

        $preferredSuppliers = $this->latestPurchaseSupplierByProduct($branchId);

        return $this->movements
            ->productsWithDerivedStock($branchId)
            ->where('is_active', true)
            ->where('alert_enabled', true)
            ->filter(function (Product $product) {
                $currentStock = (float) $product->current_stock;
                $effectiveReorder = $this->effectiveReorderPoint(
                    (float) ($product->reorder_point ?? 0),
                    (float) ($product->minimum_stock ?? 0),
                );

                return $effectiveReorder > 0 && $currentStock <= $effectiveReorder;
            })
            ->map(function (Product $product) use ($outbound, $preferredSuppliers) {
                $currentStock = (float) $product->current_stock;
                $minimumStock = (float) ($product->minimum_stock ?? 0);
                $reorderPoint = (float) ($product->reorder_point ?? 0);
                $effectiveReorder = $this->effectiveReorderPoint($reorderPoint, $minimumStock);
                $avgDailyConsumption = (float) ($outbound[$product->id]->outbound_qty ?? 0) / self::REORDER_LOOKBACK_DAYS;
                $safetyGap = max(0, $effectiveReorder - $currentStock);
                $suggestedQty = max(
                    (float) ($product->reorder_quantity ?? 0) > 0 ? (float) $product->reorder_quantity : self::DEFAULT_MIN_ORDER_QTY,
                    ($avgDailyConsumption * self::DEFAULT_LEAD_TIME_DAYS) + $safetyGap,
                );
                $estDaysUntilStockout = $avgDailyConsumption > 0
                    ? round($currentStock / $avgDailyConsumption, 1)
                    : null;

                return [
                    'product_id' => $product->id,
                    'product_code' => $product->code,
                    'product_name' => $product->name,
                    'current_stock' => $currentStock,
                    'reorder_point' => $reorderPoint,
                    'minimum_stock' => $minimumStock,
                    'safety_stock_gap' => $safetyGap,
                    'avg_daily_consumption' => round($avgDailyConsumption, 4),
                    'suggested_order_qty' => round($suggestedQty, 2),
                    'est_days_until_stockout' => $estDaysUntilStockout,
                    'preferred_supplier_id' => $preferredSuppliers[$product->id] ?? null,
                    'severity' => $this->resolveReorderSeverity(
                        $currentStock,
                        $minimumStock,
                        $effectiveReorder,
                        $estDaysUntilStockout,
                    ),
                ];
            })
            ->sortBy(fn (array $row) => $this->reorderSeveritySortKey($row['severity']))
            ->values();
    }

    /**
     * @return array<string, float|int|null>
     */
    private function buildSupplierPerformanceRow(int $branchId, Supplier $supplier, float $totalLedgerPurchase): array
    {
        $poQuery = PurchaseOrder::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplier->id);

        $totalPoCount = (int) (clone $poQuery)
            ->where('status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->count();

        $cancelledPoCount = (int) (clone $poQuery)
            ->where('status', PurchaseOrder::STATUS_CANCELLED)
            ->count();

        $nonDraftPoCount = (int) (clone $poQuery)
            ->where('status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->count();

        $datedPoCount = (int) (clone $poQuery)
            ->where('status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereNotNull('expected_delivery_date')
            ->count();

        $orderValue = (float) PurchaseOrderItem::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_purchase_order_items.purchase_order_id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplier->id)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_ordered * trx_purchase_order_items.unit_price), 0) as total')
            ->value('total');

        $receivedValue = (float) GoodsReceiptItem::query()
            ->join('trx_goods_receipts', 'trx_goods_receipts.id', '=', 'trx_goods_receipt_items.goods_receipt_id')
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_goods_receipts.purchase_order_id')
            ->where('trx_goods_receipts.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplier->id)
            ->where('trx_goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->selectRaw('COALESCE(SUM(trx_goods_receipt_items.line_total), 0) as total')
            ->value('total');

        $orderedQty = (float) PurchaseOrderItem::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_purchase_order_items.purchase_order_id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplier->id)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_ordered), 0) as total')
            ->value('total');

        $receivedQty = (float) PurchaseOrderItem::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_purchase_order_items.purchase_order_id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplier->id)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_received), 0) as total')
            ->value('total');

        $ledgerValue = (float) InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplier->id)
            ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
            ->selectRaw('COALESCE(SUM(quantity_in * unit_cost), 0) as total')
            ->value('total');

        $onTimeStats = $this->supplierOnTimeStats($branchId, $supplier->id);
        $leadTimeDays = $this->supplierAverageLeadTimeDays($branchId, $supplier->id);

        $fulfillmentRate = $orderedQty > 0 ? round(($receivedQty / $orderedQty) * 100, 2) : null;
        $coveragePercentage = $totalPoCount > 0 ? round(($datedPoCount / $totalPoCount) * 100, 2) : 0.0;
        $purchaseLedgerShare = $totalLedgerPurchase > 0
            ? round(($ledgerValue / $totalLedgerPurchase) * 100, 2)
            : 0.0;
        $cancelledPoRate = $nonDraftPoCount > 0
            ? round(($cancelledPoCount / $nonDraftPoCount) * 100, 2)
            : null;

        return [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->name,
            'order_count' => $totalPoCount,
            'order_value' => $orderValue,
            'received_value' => $receivedValue > 0 ? $receivedValue : $ledgerValue,
            'fulfillment_rate' => $fulfillmentRate,
            'on_time_delivery_rate' => $onTimeStats['rate'],
            'coverage_percentage' => $coveragePercentage,
            'avg_lead_time_days' => $leadTimeDays,
            'purchase_ledger_share' => $purchaseLedgerShare,
            'cancelled_po_rate' => $cancelledPoRate,
        ];
    }

    /**
     * @return array{rate: float|null, dated_count: int}
     */
    private function supplierOnTimeStats(int $branchId, int $supplierId): array
    {
        $datedPos = PurchaseOrder::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplierId)
            ->where('status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereNotNull('expected_delivery_date')
            ->get(['id', 'expected_delivery_date']);

        if ($datedPos->isEmpty()) {
            return ['rate' => null, 'dated_count' => 0];
        }

        $firstReceiptDates = GoodsReceipt::query()
            ->where('branch_id', $branchId)
            ->whereIn('purchase_order_id', $datedPos->pluck('id'))
            ->where('status', GoodsReceipt::STATUS_POSTED)
            ->select('purchase_order_id')
            ->selectRaw('MIN(receipt_date) as first_receipt_date')
            ->groupBy('purchase_order_id')
            ->pluck('first_receipt_date', 'purchase_order_id');

        $onTimeCount = $datedPos
            ->filter(function (PurchaseOrder $po) use ($firstReceiptDates) {
                $firstReceiptDate = $firstReceiptDates[$po->id] ?? null;

                return $firstReceiptDate !== null
                    && Carbon::parse($firstReceiptDate)->lte(Carbon::parse($po->expected_delivery_date));
            })
            ->count();

        return [
            'rate' => round(($onTimeCount / $datedPos->count()) * 100, 2),
            'dated_count' => $datedPos->count(),
        ];
    }

    private function supplierAverageLeadTimeDays(int $branchId, int $supplierId): ?float
    {
        $rows = GoodsReceipt::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_goods_receipts.purchase_order_id')
            ->where('trx_goods_receipts.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplierId)
            ->where('trx_goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->select('trx_purchase_orders.order_date', 'trx_goods_receipts.receipt_date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $totalDays = $rows->sum(function ($row) {
            return Carbon::parse($row->order_date)->diffInDays(Carbon::parse($row->receipt_date));
        });

        return round($totalDays / $rows->count(), 1);
    }

    /**
     * @param  array<int, string>  $months
     * @return array<string, array{po_count: int, po_value: float}>
     */
    private function purchaseOrderTrendRows(int $branchId, array $months): array
    {
        $monthExpression = $this->monthTruncExpression('trx_purchase_orders.order_date');
        $dateFrom = now()->subMonths(self::TREND_MONTH_COUNT - 1)->startOfMonth()->toDateString();

        $rows = PurchaseOrder::query()
            ->leftJoin('trx_purchase_order_items', 'trx_purchase_order_items.purchase_order_id', '=', 'trx_purchase_orders.id')
            ->selectRaw("{$monthExpression} as period_month")
            ->selectRaw('COUNT(DISTINCT trx_purchase_orders.id) as po_count')
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_ordered * trx_purchase_order_items.unit_price), 0) as po_value')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereDate('trx_purchase_orders.order_date', '>=', $dateFrom)
            ->groupByRaw($monthExpression)
            ->get();

        $mapped = [];

        foreach ($rows as $row) {
            $key = Carbon::parse($row->period_month)->format('Y-m');
            $mapped[$key] = [
                'po_count' => (int) $row->po_count,
                'po_value' => (float) $row->po_value,
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<int, string>  $months
     * @return array<string, array{gr_count: int, gr_received_value: float}>
     */
    private function goodsReceiptTrendRows(int $branchId, array $months): array
    {
        $monthExpression = $this->monthTruncExpression('trx_goods_receipts.posted_at');
        $dateFrom = now()->subMonths(self::TREND_MONTH_COUNT - 1)->startOfMonth()->toDateString();

        $rows = GoodsReceipt::query()
            ->leftJoin('trx_goods_receipt_items', 'trx_goods_receipt_items.goods_receipt_id', '=', 'trx_goods_receipts.id')
            ->selectRaw("{$monthExpression} as period_month")
            ->selectRaw('COUNT(DISTINCT trx_goods_receipts.id) as gr_count')
            ->selectRaw('COALESCE(SUM(trx_goods_receipt_items.line_total), 0) as gr_received_value')
            ->where('trx_goods_receipts.branch_id', $branchId)
            ->where('trx_goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->whereNotNull('trx_goods_receipts.posted_at')
            ->whereDate('trx_goods_receipts.posted_at', '>=', $dateFrom)
            ->groupByRaw($monthExpression)
            ->get();

        $mapped = [];

        foreach ($rows as $row) {
            $key = Carbon::parse($row->period_month)->format('Y-m');
            $mapped[$key] = [
                'gr_count' => (int) $row->gr_count,
                'gr_received_value' => (float) $row->gr_received_value,
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<int, string>  $months
     * @return array<string, array{ledger_purchase_value: float}>
     */
    private function ledgerPurchaseTrendRows(int $branchId, array $months): array
    {
        $monthExpression = $this->monthTruncExpression('trx_inventory_movements.movement_date');
        $dateFrom = now()->subMonths(self::TREND_MONTH_COUNT - 1)->startOfMonth()->toDateString();

        $rows = InventoryMovement::query()
            ->selectRaw("{$monthExpression} as period_month")
            ->selectRaw('COALESCE(SUM(quantity_in * unit_cost), 0) as ledger_purchase_value')
            ->where('branch_id', $branchId)
            ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
            ->whereDate('movement_date', '>=', $dateFrom)
            ->groupByRaw($monthExpression)
            ->get();

        $mapped = [];

        foreach ($rows as $row) {
            $key = Carbon::parse($row->period_month)->format('Y-m');
            $mapped[$key] = [
                'ledger_purchase_value' => (float) $row->ledger_purchase_value,
            ];
        }

        return $mapped;
    }

    /**
     * @return array<int, string>
     */
    private function trendMonthKeys(): array
    {
        $months = [];

        for ($i = self::TREND_MONTH_COUNT - 1; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->format('Y-m');
        }

        return $months;
    }

    private function stockSubquery(int $branchId): Builder
    {
        return DB::table('trx_inventory_movements')
            ->select('product_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
            ->where('branch_id', $branchId)
            ->groupBy('product_id');
    }

    private function lastOutboundSubquery(int $branchId): Builder
    {
        return DB::table('trx_inventory_movements')
            ->select('product_id')
            ->selectRaw('MAX(movement_date) as last_out_date')
            ->where('branch_id', $branchId)
            ->where('quantity_out', '>', 0)
            ->groupBy('product_id');
    }

    private function lastInboundSubquery(int $branchId): Collection
    {
        return $this->movements
            ->lastInboundDateByProduct($branchId)
            ->keyBy('product_id');
    }

    /**
     * @return Collection<int, string|null>
     */
    private function batchAgeAnchorSubquery(int $branchId): Collection
    {
        $batchStock = DB::table('trx_inventory_movements')
            ->select('product_id', 'inventory_batch_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as batch_stock')
            ->where('branch_id', $branchId)
            ->whereNotNull('inventory_batch_id')
            ->groupBy('product_id', 'inventory_batch_id')
            ->havingRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) > 0');

        $rows = DB::table('inv_inventory_batches')
            ->joinSub($batchStock, 'batch_stock', function ($join) {
                $join->on('batch_stock.inventory_batch_id', '=', 'inv_inventory_batches.id');
            })
            ->where('inv_inventory_batches.branch_id', $branchId)
            ->select('batch_stock.product_id', 'inv_inventory_batches.received_date')
            ->orderBy('inv_inventory_batches.received_date')
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $group) => $group->first()->received_date);

        return $rows;
    }

    /**
     * @return array<int, int|null>
     */
    private function latestPurchaseSupplierByProduct(int $branchId): array
    {
        $rows = InventoryMovement::query()
            ->select('product_id', 'supplier_id')
            ->where('branch_id', $branchId)
            ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
            ->whereNotNull('supplier_id')
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get()
            ->unique('product_id');

        return $rows->pluck('supplier_id', 'product_id')->all();
    }

    private function effectiveReorderPoint(float $reorderPoint, float $minimumStock): float
    {
        if ($reorderPoint > 0) {
            return $reorderPoint;
        }

        return $minimumStock;
    }

    private function effectiveReorderPointSql(string $tableAlias): string
    {
        return "CASE WHEN {$tableAlias}.reorder_point > 0 THEN {$tableAlias}.reorder_point ELSE {$tableAlias}.minimum_stock END";
    }

    private function resolveAgeBucket(int $ageDays): string
    {
        if ($ageDays <= 30) {
            return self::BUCKET_FRESH;
        }

        if ($ageDays <= 60) {
            return self::BUCKET_AGING;
        }

        if ($ageDays <= 90) {
            return self::BUCKET_STALE;
        }

        if ($ageDays <= 180) {
            return self::BUCKET_OLD;
        }

        return self::BUCKET_VERY_OLD;
    }

    private function ageBucketLabel(string $bucket): string
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
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, array{label: string, product_count: int, total_qty: float, total_value: float}>
     */
    private function summarizeAgingBuckets(Collection $items): array
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
                'total_qty' => (float) $bucketItems->sum('current_stock'),
                'total_value' => (float) $bucketItems->sum('stock_value'),
            ];
        }

        return $summary;
    }

    private function resolveReorderSeverity(
        float $currentStock,
        float $minimumStock,
        float $effectiveReorder,
        ?float $estDaysUntilStockout,
    ): string {
        if ($minimumStock > 0 && $currentStock <= $minimumStock) {
            return 'critical';
        }

        if ($estDaysUntilStockout !== null && $estDaysUntilStockout <= self::DEFAULT_LEAD_TIME_DAYS) {
            return 'high';
        }

        if ($currentStock <= $effectiveReorder) {
            return 'medium';
        }

        return 'low';
    }

    private function reorderSeveritySortKey(string $severity): string
    {
        return match ($severity) {
            'critical' => '1',
            'high' => '2',
            'medium' => '3',
            default => '4',
        };
    }

    private function monthTruncExpression(string $column): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "DATE_TRUNC('month', {$column})";
        }

        return "strftime('%Y-%m-01', {$column})";
    }
}
