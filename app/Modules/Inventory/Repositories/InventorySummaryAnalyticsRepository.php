<?php

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 16.8.4 — summary-table backed analytics repository.
 *
 * Reads pre-aggregated rpt_* tables. Ledger remains source of truth; summaries are read-only caches.
 *
 * Fallback to {@see InventoryAnalyticsRepository}:
 * - getSupplierPerformance — avg lead time, coverage %, cancelled PO rate not fully in daily supplier slice
 * - getFastMovingItems / getSlowMovingItems — when $days is not 7, 30, or 90
 * - getStockAging — when no product summary snapshot exists
 */
class InventorySummaryAnalyticsRepository implements InventoryAnalyticsRepositoryInterface
{
    public const TREND_MONTH_COUNT = 6;

    private const SLOW_MOVING_THRESHOLD = 1.0;

    private const DEFAULT_LEAD_TIME_DAYS = 14;

    private const BUCKET_FRESH = 'fresh';

    private const BUCKET_AGING = 'aging';

    private const BUCKET_STALE = 'stale';

    private const BUCKET_OLD = 'old';

    private const BUCKET_VERY_OLD = 'very_old';

    public function __construct(
        private readonly InventoryAnalyticsRepository $fallback,
    ) {}

    public function getInventoryValue(int $branchId): float
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        return $snapshot !== null ? (float) $snapshot->inventory_value : 0.0;
    }

    public function getActiveSkuCount(int $branchId): int
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        return $snapshot !== null ? (int) $snapshot->active_sku_count : 0;
    }

    public function getLowStockCount(int $branchId): int
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        return $snapshot !== null ? (int) $snapshot->low_stock_count : 0;
    }

    public function getDeadStockCount(int $branchId, int $days = 90): int
    {
        $snapshotDate = $this->latestProductSnapshotDate($branchId);

        if ($snapshotDate === null) {
            return 0;
        }

        if ($days === 90) {
            return (int) DB::table('rpt_inventory_product_summaries')
                ->where('branch_id', $branchId)
                ->where('snapshot_date', $snapshotDate)
                ->where('is_dead_stock', true)
                ->count();
        }

        $cutoffDate = now()->subDays($days)->toDateString();

        return (int) DB::table('rpt_inventory_product_summaries')
            ->where('branch_id', $branchId)
            ->where('snapshot_date', $snapshotDate)
            ->where('is_active', true)
            ->where('current_stock', '>', 0)
            ->where(function ($query) use ($cutoffDate) {
                $query->whereNull('last_out_date')
                    ->orWhereDate('last_out_date', '<', $cutoffDate);
            })
            ->count();
    }

    public function getOpenPurchaseRequestCount(int $branchId): int
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        return $snapshot !== null ? (int) $snapshot->open_pr_count : 0;
    }

    public function getOpenPurchaseOrderCount(int $branchId): int
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        return $snapshot !== null ? (int) $snapshot->open_po_count : 0;
    }

    public function getPendingGoodsReceiptCount(int $branchId): int
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        return $snapshot !== null ? (int) $snapshot->pending_gr_count : 0;
    }

    public function getInTransitTransferCount(int $branchId): int
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        return $snapshot !== null ? (int) $snapshot->in_transit_transfer_count : 0;
    }

    public function getInventoryAccuracy(int $branchId): ?float
    {
        $snapshot = $this->latestBranchSnapshot($branchId);

        if ($snapshot === null) {
            return null;
        }

        return $snapshot->inventory_accuracy_pct !== null
            ? (float) $snapshot->inventory_accuracy_pct
            : null;
    }

    public function getFastMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        $outboundColumn = $this->outboundQtyColumn($days);

        if ($outboundColumn === null) {
            return $this->fallback->getFastMovingItems($branchId, $days, $limit);
        }

        $snapshotDate = $this->latestProductSnapshotDate($branchId);

        if ($snapshotDate === null) {
            return collect();
        }

        return DB::table('rpt_inventory_product_summaries as ps')
            ->join('inv_products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.branch_id', $branchId)
            ->where('p.branch_id', $branchId)
            ->where('ps.snapshot_date', $snapshotDate)
            ->where('ps.is_active', true)
            ->where("ps.{$outboundColumn}", '>', 0)
            ->orderByDesc("ps.{$outboundColumn}")
            ->limit($limit)
            ->get([
                'ps.product_id',
                'p.code as product_code',
                'p.name as product_name',
                'ps.current_stock',
                "ps.{$outboundColumn} as outbound_qty_period",
                'ps.average_cost',
                'ps.stock_value',
                'ps.outbound_value_30d',
            ])
            ->map(function ($row) use ($days) {
                $outQty = (float) $row->outbound_qty_period;
                $averageCost = (float) $row->average_cost;
                $outboundValue = $days === 30
                    ? (float) $row->outbound_value_30d
                    : $outQty * $averageCost;

                return [
                    'product_id' => (int) $row->product_id,
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) $row->product_name,
                    'current_stock' => (float) $row->current_stock,
                    'outbound_qty_period' => $outQty,
                    'outbound_value_period' => $outboundValue,
                    'stock_value' => (float) $row->stock_value,
                ];
            })
            ->values();
    }

    public function getSlowMovingItems(int $branchId, int $days = 90, int $limit = 10): Collection
    {
        $outboundColumn = $this->outboundQtyColumn($days);

        if ($outboundColumn === null) {
            return $this->fallback->getSlowMovingItems($branchId, $days, $limit);
        }

        $snapshotDate = $this->latestProductSnapshotDate($branchId);

        if ($snapshotDate === null) {
            return collect();
        }

        $rows = DB::table('rpt_inventory_product_summaries as ps')
            ->join('inv_products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.branch_id', $branchId)
            ->where('p.branch_id', $branchId)
            ->where('ps.snapshot_date', $snapshotDate)
            ->where('ps.is_active', true)
            ->where('ps.current_stock', '>', 0)
            ->where("ps.{$outboundColumn}", '<=', self::SLOW_MOVING_THRESHOLD)
            ->get([
                'ps.product_id',
                'p.code as product_code',
                'p.name as product_name',
                'ps.current_stock',
                "ps.{$outboundColumn} as outbound_qty_period",
                'ps.average_cost',
                'ps.stock_value',
                'ps.outbound_value_30d',
            ]);

        return $rows
            ->map(function ($row) use ($days) {
                $outQty = (float) $row->outbound_qty_period;
                $averageCost = (float) $row->average_cost;
                $outboundValue = $days === 30
                    ? (float) $row->outbound_value_30d
                    : $outQty * $averageCost;

                return [
                    'product_id' => (int) $row->product_id,
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) $row->product_name,
                    'current_stock' => (float) $row->current_stock,
                    'outbound_qty_period' => $outQty,
                    'outbound_value_period' => $outboundValue,
                    'stock_value' => (float) $row->stock_value,
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
        $snapshotDate = $this->latestProductSnapshotDate($branchId);

        if ($snapshotDate === null) {
            return collect();
        }

        $query = DB::table('rpt_inventory_product_summaries as ps')
            ->join('inv_products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.branch_id', $branchId)
            ->where('p.branch_id', $branchId)
            ->where('ps.snapshot_date', $snapshotDate)
            ->where('ps.is_active', true)
            ->where('ps.current_stock', '>', 0);

        if ($days === 90) {
            $query->where('ps.is_dead_stock', true);
        } else {
            $cutoffDate = now()->subDays($days)->toDateString();
            $query->where(function ($builder) use ($cutoffDate) {
                $builder->whereNull('ps.last_out_date')
                    ->orWhereDate('ps.last_out_date', '<', $cutoffDate);
            });
        }

        $rows = $query->get([
            'ps.product_id',
            'p.code as product_code',
            'p.name as product_name',
            'ps.current_stock',
            'ps.stock_value',
            'ps.last_out_date',
        ]);

        return $rows
            ->map(function ($row) {
                $lastOut = $row->last_out_date !== null
                    ? Carbon::parse($row->last_out_date)->toDateString()
                    : null;
                $daysSinceLastOut = $lastOut !== null
                    ? (int) Carbon::parse($lastOut)->startOfDay()->diffInDays(now()->startOfDay())
                    : null;

                return [
                    'product_id' => (int) $row->product_id,
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) $row->product_name,
                    'current_stock' => (float) $row->current_stock,
                    'stock_value' => (float) $row->stock_value,
                    'last_out_date' => $lastOut,
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
        $snapshotDate = $this->latestProductSnapshotDate($branchId);

        if ($snapshotDate === null) {
            return $this->fallback->getStockAging($branchId);
        }

        $rows = DB::table('rpt_inventory_product_summaries as ps')
            ->join('inv_products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.branch_id', $branchId)
            ->where('p.branch_id', $branchId)
            ->where('ps.snapshot_date', $snapshotDate)
            ->where('ps.is_active', true)
            ->where('ps.current_stock', '>', 0)
            ->get([
                'ps.product_id',
                'p.code as product_code',
                'p.name as product_name',
                'ps.current_stock',
                'ps.stock_value',
                'ps.last_in_date',
                'ps.age_days',
                'ps.age_bucket',
            ]);

        $items = $rows
            ->map(function ($row) {
                $anchorDate = $row->last_in_date !== null
                    ? Carbon::parse($row->last_in_date)->toDateString()
                    : null;

                return [
                    'product_id' => (int) $row->product_id,
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) $row->product_name,
                    'current_stock' => (float) $row->current_stock,
                    'stock_value' => (float) $row->stock_value,
                    'age_anchor_date' => $anchorDate,
                    'age_days' => (int) ($row->age_days ?? 0),
                    'age_bucket' => (string) ($row->age_bucket ?? self::BUCKET_FRESH),
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
        $dateFrom = now()->subMonths(self::TREND_MONTH_COUNT - 1)->startOfMonth()->toDateString();
        $monthExpression = $this->monthTruncExpression('summary_date');

        $rows = DB::table('rpt_procurement_daily_summaries')
            ->selectRaw("{$monthExpression} as period_month")
            ->selectRaw('COALESCE(SUM(po_created_count), 0) as po_count')
            ->selectRaw('COALESCE(SUM(po_created_value), 0) as po_value')
            ->selectRaw('COALESCE(SUM(gr_posted_count), 0) as gr_count')
            ->selectRaw('COALESCE(SUM(gr_received_value), 0) as gr_received_value')
            ->selectRaw('COALESCE(SUM(ledger_purchase_value), 0) as ledger_purchase_value')
            ->where('branch_id', $branchId)
            ->whereNull('supplier_id')
            ->whereDate('summary_date', '>=', $dateFrom)
            ->groupByRaw($monthExpression)
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->period_month)->format('Y-m'));

        return collect($months)
            ->map(function (string $month) use ($rows) {
                $row = $rows[$month] ?? null;

                return [
                    'period' => $month,
                    'po_count' => (int) ($row->po_count ?? 0),
                    'po_value' => (float) ($row->po_value ?? 0),
                    'gr_count' => (int) ($row->gr_count ?? 0),
                    'gr_received_value' => (float) ($row->gr_received_value ?? 0),
                    'ledger_purchase_value' => (float) ($row->ledger_purchase_value ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    public function getConsumptionTrend(int $branchId, string $period = 'monthly'): array
    {
        $months = $this->trendMonthKeys();
        $dateFrom = now()->subMonths(self::TREND_MONTH_COUNT - 1)->startOfMonth()->toDateString();
        $monthExpression = $this->monthTruncExpression('summary_date');

        $rows = DB::table('rpt_inventory_daily_summaries')
            ->selectRaw("{$monthExpression} as period_month")
            ->selectRaw('COALESCE(SUM(quantity_out_total), 0) as outbound_qty')
            ->selectRaw('COALESCE(SUM(outbound_value), 0) as outbound_value')
            ->where('branch_id', $branchId)
            ->whereDate('summary_date', '>=', $dateFrom)
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
        return $this->fallback->getSupplierPerformance($branchId);
    }

    public function getReorderRecommendations(int $branchId): Collection
    {
        $snapshotDate = $this->latestProductSnapshotDate($branchId);

        if ($snapshotDate === null) {
            return collect();
        }

        $rows = DB::table('rpt_inventory_product_summaries as ps')
            ->join('inv_products as p', 'p.id', '=', 'ps.product_id')
            ->where('ps.branch_id', $branchId)
            ->where('p.branch_id', $branchId)
            ->where('ps.snapshot_date', $snapshotDate)
            ->where('ps.is_active', true)
            ->where('ps.alert_enabled', true)
            ->where('ps.is_low_stock', true)
            ->get([
                'ps.product_id',
                'p.code as product_code',
                'p.name as product_name',
                'ps.current_stock',
                'p.reorder_point',
                'p.minimum_stock',
                'p.reorder_quantity',
                'ps.effective_reorder_point',
                'ps.avg_daily_consumption_30d',
                'ps.preferred_supplier_id',
            ]);

        return $rows
            ->map(function ($row) {
                $currentStock = (float) $row->current_stock;
                $minimumStock = (float) ($row->minimum_stock ?? 0);
                $reorderPoint = (float) ($row->reorder_point ?? 0);
                $effectiveReorder = (float) $row->effective_reorder_point;
                $avgDailyConsumption = (float) $row->avg_daily_consumption_30d;
                $safetyGap = max(0, $effectiveReorder - $currentStock);
                $reorderQuantity = (float) ($row->reorder_quantity ?? 0);
                $suggestedQty = max(
                    $reorderQuantity > 0 ? $reorderQuantity : InventoryAnalyticsRepository::DEFAULT_MIN_ORDER_QTY,
                    ($avgDailyConsumption * InventoryAnalyticsRepository::DEFAULT_LEAD_TIME_DAYS) + $safetyGap,
                );
                $estDaysUntilStockout = $avgDailyConsumption > 0
                    ? round($currentStock / $avgDailyConsumption, 1)
                    : null;

                return [
                    'product_id' => (int) $row->product_id,
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) $row->product_name,
                    'current_stock' => $currentStock,
                    'reorder_point' => $reorderPoint,
                    'minimum_stock' => $minimumStock,
                    'safety_stock_gap' => $safetyGap,
                    'avg_daily_consumption' => round($avgDailyConsumption, 4),
                    'suggested_order_qty' => round($suggestedQty, 2),
                    'est_days_until_stockout' => $estDaysUntilStockout,
                    'preferred_supplier_id' => $row->preferred_supplier_id !== null
                        ? (int) $row->preferred_supplier_id
                        : null,
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

    private function latestBranchSnapshot(int $branchId): ?object
    {
        return DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $branchId)
            ->orderByDesc('snapshot_date')
            ->first();
    }

    private function latestProductSnapshotDate(int $branchId): ?string
    {
        $date = DB::table('rpt_inventory_product_summaries')
            ->where('branch_id', $branchId)
            ->max('snapshot_date');

        return $date !== null ? (string) $date : null;
    }

    private function outboundQtyColumn(int $days): ?string
    {
        return match ($days) {
            7 => 'outbound_qty_7d',
            30 => 'outbound_qty_30d',
            90 => 'outbound_qty_90d',
            default => null,
        };
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

    private function monthTruncExpression(string $column): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "DATE_TRUNC('month', {$column})";
        }

        return "strftime('%Y-%m-01', {$column})";
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
}
