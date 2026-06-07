<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryBatch;
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

/**
 * Sprint 16.8.3 — populates read-only rpt_* summary tables from ledger and procurement data.
 *
 * Source of truth remains trx_inventory_movements. These tables are derived caches only.
 */
class InventoryAnalyticsSummaryRefreshService
{
    public const DEAD_STOCK_DAYS = 90;

    public const EXPIRING_SOON_DAYS = 30;

    private const BUCKET_FRESH = 'fresh';

    private const BUCKET_AGING = 'aging';

    private const BUCKET_STALE = 'stale';

    private const BUCKET_OLD = 'old';

    private const BUCKET_VERY_OLD = 'very_old';

    public function refreshDailySummaries(?int $branchId = null, ?string $date = null): void
    {
        $targetDate = $this->resolveDate($date);
        $now = now();

        foreach ($this->resolveBranchIds($branchId) as $resolvedBranchId) {
            $row = InventoryMovement::query()
                ->leftJoin('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
                ->where('trx_inventory_movements.branch_id', $resolvedBranchId)
                ->whereDate('trx_inventory_movements.movement_date', $targetDate)
                ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_in), 0) as quantity_in_total')
                ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_out), 0) as quantity_out_total')
                ->selectRaw('COALESCE(SUM(trx_inventory_movements.quantity_in * trx_inventory_movements.unit_cost), 0) as inbound_value')
                ->selectRaw(
                    'COALESCE(SUM(trx_inventory_movements.quantity_out * COALESCE(trx_inventory_movements.unit_cost, inv_products.average_cost)), 0) as outbound_value'
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_type = ? THEN trx_inventory_movements.quantity_in * trx_inventory_movements.unit_cost ELSE 0 END), 0) as purchase_inbound_value',
                    [InventoryMovement::TYPE_PURCHASE]
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_type = ? THEN trx_inventory_movements.quantity_in ELSE 0 END), 0) as adjustment_in_qty',
                    [InventoryMovement::TYPE_ADJUSTMENT_IN]
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_type = ? THEN trx_inventory_movements.quantity_out ELSE 0 END), 0) as adjustment_out_qty',
                    [InventoryMovement::TYPE_ADJUSTMENT_OUT]
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_type = ? THEN trx_inventory_movements.quantity_in ELSE 0 END), 0) as transfer_in_qty',
                    [InventoryMovement::TYPE_TRANSFER_IN]
                )
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_type = ? THEN trx_inventory_movements.quantity_out ELSE 0 END), 0) as transfer_out_qty',
                    [InventoryMovement::TYPE_TRANSFER_OUT]
                )
                ->selectRaw('COUNT(*) as movement_count')
                ->first();

            $this->upsertDailySummary($resolvedBranchId, $targetDate, [
                'quantity_in_total' => (float) ($row->quantity_in_total ?? 0),
                'quantity_out_total' => (float) ($row->quantity_out_total ?? 0),
                'inbound_value' => (float) ($row->inbound_value ?? 0),
                'outbound_value' => (float) ($row->outbound_value ?? 0),
                'purchase_inbound_value' => (float) ($row->purchase_inbound_value ?? 0),
                'adjustment_in_qty' => (float) ($row->adjustment_in_qty ?? 0),
                'adjustment_out_qty' => (float) ($row->adjustment_out_qty ?? 0),
                'transfer_in_qty' => (float) ($row->transfer_in_qty ?? 0),
                'transfer_out_qty' => (float) ($row->transfer_out_qty ?? 0),
                'movement_count' => (int) ($row->movement_count ?? 0),
                'refreshed_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function refreshBranchSummaries(?int $branchId = null, ?string $snapshotDate = null): void
    {
        $targetDate = $this->resolveDate($snapshotDate);
        $now = now();

        foreach ($this->resolveBranchIds($branchId) as $resolvedBranchId) {
            $stock = $this->stockSubquery($resolvedBranchId);
            $lastOut = $this->lastOutboundSubquery($resolvedBranchId);
            $deadCutoff = Carbon::parse($targetDate)->subDays(self::DEAD_STOCK_DAYS)->toDateString();

            $inventoryValue = (float) Product::query()
                ->where('inv_products.branch_id', $resolvedBranchId)
                ->where('inv_products.is_active', true)
                ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
                ->selectRaw('COALESCE(SUM(COALESCE(stock.current_stock, 0) * inv_products.average_cost), 0) as inventory_value')
                ->value('inventory_value');

            $activeSkuCount = (int) Product::query()
                ->where('inv_products.branch_id', $resolvedBranchId)
                ->where('inv_products.is_active', true)
                ->joinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
                ->where('stock.current_stock', '>', 0)
                ->count();

            $lowStockCount = (int) Product::query()
                ->where('inv_products.branch_id', $resolvedBranchId)
                ->where('inv_products.is_active', true)
                ->where('inv_products.alert_enabled', true)
                ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
                ->whereRaw(
                    'COALESCE(stock.current_stock, 0) <= '.$this->effectiveReorderPointSql('inv_products')
                )
                ->count();

            $deadStockCount = (int) Product::query()
                ->where('inv_products.branch_id', $resolvedBranchId)
                ->where('inv_products.is_active', true)
                ->joinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
                ->where('stock.current_stock', '>', 0)
                ->leftJoinSub($lastOut, 'last_out', fn ($join) => $join->on('last_out.product_id', '=', 'inv_products.id'))
                ->where(function ($query) use ($deadCutoff) {
                    $query->whereNull('last_out.last_out_date')
                        ->orWhereDate('last_out.last_out_date', '<', $deadCutoff);
                })
                ->count();

            $deadStockValue = (float) Product::query()
                ->where('inv_products.branch_id', $resolvedBranchId)
                ->where('inv_products.is_active', true)
                ->joinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
                ->where('stock.current_stock', '>', 0)
                ->leftJoinSub($lastOut, 'last_out', fn ($join) => $join->on('last_out.product_id', '=', 'inv_products.id'))
                ->where(function ($query) use ($deadCutoff) {
                    $query->whereNull('last_out.last_out_date')
                        ->orWhereDate('last_out.last_out_date', '<', $deadCutoff);
                })
                ->selectRaw('COALESCE(SUM(stock.current_stock * inv_products.average_cost), 0) as dead_stock_value')
                ->value('dead_stock_value');

            $outOfStockCount = (int) Product::query()
                ->where('inv_products.branch_id', $resolvedBranchId)
                ->where('inv_products.is_active', true)
                ->where('inv_products.alert_enabled', true)
                ->leftJoinSub($stock, 'stock', fn ($join) => $join->on('stock.product_id', '=', 'inv_products.id'))
                ->whereRaw('COALESCE(stock.current_stock, 0) <= 0')
                ->count();

            $batchCounts = $this->batchExpiryCounts($resolvedBranchId, $targetDate);
            $totalQuantityOnHand = (float) DB::table('trx_inventory_movements')
                ->where('branch_id', $resolvedBranchId)
                ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as total_qty')
                ->value('total_qty');

            $this->upsertBranchSummary($resolvedBranchId, $targetDate, [
                'inventory_value' => $inventoryValue,
                'active_sku_count' => $activeSkuCount,
                'low_stock_count' => $lowStockCount,
                'dead_stock_count' => $deadStockCount,
                'dead_stock_value' => $deadStockValue,
                'out_of_stock_count' => $outOfStockCount,
                'batch_expiring_soon_count' => $batchCounts['expiring_soon'],
                'batch_expired_count' => $batchCounts['expired'],
                'inventory_accuracy_pct' => $this->inventoryAccuracyPct($resolvedBranchId),
                'open_pr_count' => $this->openPurchaseRequestCount($resolvedBranchId),
                'open_po_count' => $this->openPurchaseOrderCount($resolvedBranchId),
                'open_po_outstanding_value' => $this->openPurchaseOrderOutstandingValue($resolvedBranchId),
                'pending_gr_count' => $this->pendingGoodsReceiptCount($resolvedBranchId),
                'in_transit_transfer_count' => $this->inTransitTransferCount($resolvedBranchId),
                'total_quantity_on_hand' => $totalQuantityOnHand,
                'refreshed_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function refreshProductSummaries(?int $branchId = null, ?string $snapshotDate = null): void
    {
        $targetDate = $this->resolveDate($snapshotDate);
        $now = now();
        $snapshotCarbon = Carbon::parse($targetDate)->startOfDay();
        $deadCutoff = $snapshotCarbon->copy()->subDays(self::DEAD_STOCK_DAYS)->toDateString();
        $window7 = $snapshotCarbon->copy()->subDays(7)->toDateString();
        $window30 = $snapshotCarbon->copy()->subDays(30)->toDateString();
        $window90 = $snapshotCarbon->copy()->subDays(90)->toDateString();

        foreach ($this->resolveBranchIds($branchId) as $resolvedBranchId) {
            $stockByProduct = $this->stockByProductMap($resolvedBranchId);
            $lastInByProduct = $this->lastMovementDateByProduct($resolvedBranchId, 'in', $targetDate);
            $lastOutByProduct = $this->lastMovementDateByProduct($resolvedBranchId, 'out', $targetDate);
            $outboundWindows = $this->outboundWindowByProduct($resolvedBranchId, $targetDate, $window7, $window30, $window90);
            $batchAnchors = $this->batchAgeAnchorMap($resolvedBranchId);
            $preferredSuppliers = $this->preferredSupplierByProduct($resolvedBranchId);

            $products = Product::query()
                ->where('branch_id', $resolvedBranchId)
                ->get(['id', 'product_category_id', 'is_active', 'alert_enabled', 'reorder_point', 'minimum_stock', 'average_cost']);

            $rankCandidates = [];

            foreach ($products as $product) {
                $currentStock = (float) ($stockByProduct[$product->id] ?? 0);
                $averageCost = (float) $product->average_cost;
                $effectiveReorder = $this->effectiveReorderPoint(
                    (float) ($product->reorder_point ?? 0),
                    (float) ($product->minimum_stock ?? 0),
                );
                $isLowStock = $product->is_active
                    && $product->alert_enabled
                    && $effectiveReorder > 0
                    && $currentStock <= $effectiveReorder;

                $lastOutDate = $lastOutByProduct[$product->id] ?? null;
                $isDeadStock = $product->is_active
                    && $currentStock > 0
                    && ($lastOutDate === null || $lastOutDate < $deadCutoff);

                $batchAnchor = $batchAnchors[$product->id] ?? null;
                $lastInDate = $lastInByProduct[$product->id] ?? null;
                $anchorDate = $batchAnchor ?? $lastInDate;
                $ageDays = $anchorDate !== null
                    ? (int) Carbon::parse($anchorDate)->startOfDay()->diffInDays($snapshotCarbon)
                    : null;
                $ageBucket = $ageDays !== null ? $this->resolveAgeBucket($ageDays) : null;

                $windows = $outboundWindows[$product->id] ?? null;
                $outboundQty7d = (float) ($windows?->outbound_qty_7d ?? 0);
                $outboundQty30d = (float) ($windows?->outbound_qty_30d ?? 0);
                $outboundQty90d = (float) ($windows?->outbound_qty_90d ?? 0);
                $outboundValue30d = (float) ($windows?->outbound_value_30d ?? 0);

                $this->upsertProductSummary($resolvedBranchId, $product->id, $targetDate, [
                    'current_stock' => $currentStock,
                    'stock_value' => round($currentStock * $averageCost, 2),
                    'average_cost' => $averageCost,
                    'product_category_id' => $product->product_category_id,
                    'is_active' => $product->is_active,
                    'alert_enabled' => $product->alert_enabled,
                    'effective_reorder_point' => $effectiveReorder,
                    'is_low_stock' => $isLowStock,
                    'is_dead_stock' => $isDeadStock,
                    'last_in_date' => $lastInDate,
                    'last_out_date' => $lastOutDate,
                    'age_days' => $ageDays,
                    'age_bucket' => $ageBucket,
                    'outbound_qty_7d' => $outboundQty7d,
                    'outbound_qty_30d' => $outboundQty30d,
                    'outbound_qty_90d' => $outboundQty90d,
                    'outbound_value_30d' => $outboundValue30d,
                    'avg_daily_consumption_30d' => round($outboundQty30d / 30, 4),
                    'preferred_supplier_id' => $preferredSuppliers[$product->id] ?? null,
                    'fast_moving_rank' => null,
                    'refreshed_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($outboundQty90d > 0) {
                    $rankCandidates[] = [
                        'product_id' => $product->id,
                        'outbound_qty_90d' => $outboundQty90d,
                    ];
                }
            }

            $this->applyFastMovingRanks($resolvedBranchId, $targetDate, $rankCandidates, $now);
        }
    }

    public function refreshProcurementDailySummaries(?int $branchId = null, ?string $date = null): void
    {
        $targetDate = $this->resolveDate($date);
        $now = now();

        foreach ($this->resolveBranchIds($branchId) as $resolvedBranchId) {
            $poCreated = $this->purchaseOrderCreatedMetrics($resolvedBranchId, $targetDate);
            $grPosted = $this->goodsReceiptPostedMetrics($resolvedBranchId, $targetDate);
            $ledgerPurchaseValue = (float) InventoryMovement::query()
                ->where('branch_id', $resolvedBranchId)
                ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
                ->whereDate('movement_date', $targetDate)
                ->selectRaw('COALESCE(SUM(quantity_in * unit_cost), 0) as total')
                ->value('total');

            $prSubmittedCount = (int) PurchaseRequest::query()
                ->where('branch_id', $resolvedBranchId)
                ->where('status', PurchaseRequest::STATUS_SUBMITTED)
                ->whereDate('request_date', $targetDate)
                ->count();

            $this->upsertProcurementSummary($resolvedBranchId, $targetDate, null, [
                'po_created_count' => $poCreated['count'],
                'po_created_value' => $poCreated['value'],
                'po_open_count' => $this->openPurchaseOrderCount($resolvedBranchId),
                'po_open_outstanding_value' => $this->openPurchaseOrderOutstandingValue($resolvedBranchId),
                'gr_posted_count' => $grPosted['count'],
                'gr_received_value' => $grPosted['value'],
                'ledger_purchase_value' => $ledgerPurchaseValue,
                'pr_submitted_count' => $prSubmittedCount,
                'supplier_order_count' => 0,
                'supplier_received_value' => 0,
                'supplier_on_time_count' => 0,
                'supplier_dated_po_count' => 0,
                'supplier_fulfilled_qty' => 0,
                'supplier_ordered_qty' => 0,
                'refreshed_at' => $now,
                'updated_at' => $now,
            ]);

            $suppliers = Supplier::query()
                ->where('branch_id', $resolvedBranchId)
                ->where('is_active', true)
                ->get(['id']);

            foreach ($suppliers as $supplier) {
                $supplierMetrics = $this->supplierDailyMetrics($resolvedBranchId, $supplier->id, $targetDate);

                $this->upsertProcurementSummary($resolvedBranchId, $targetDate, $supplier->id, [
                    'po_created_count' => $supplierMetrics['po_created_count'],
                    'po_created_value' => $supplierMetrics['po_created_value'],
                    'po_open_count' => 0,
                    'po_open_outstanding_value' => 0,
                    'gr_posted_count' => $supplierMetrics['gr_posted_count'],
                    'gr_received_value' => $supplierMetrics['gr_received_value'],
                    'ledger_purchase_value' => $supplierMetrics['ledger_purchase_value'],
                    'pr_submitted_count' => 0,
                    'supplier_order_count' => $supplierMetrics['supplier_order_count'],
                    'supplier_received_value' => $supplierMetrics['supplier_received_value'],
                    'supplier_on_time_count' => $supplierMetrics['supplier_on_time_count'],
                    'supplier_dated_po_count' => $supplierMetrics['supplier_dated_po_count'],
                    'supplier_fulfilled_qty' => $supplierMetrics['supplier_fulfilled_qty'],
                    'supplier_ordered_qty' => $supplierMetrics['supplier_ordered_qty'],
                    'refreshed_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function refreshAll(?int $branchId = null, ?string $date = null): void
    {
        $this->refreshDailySummaries($branchId, $date);
        $this->refreshBranchSummaries($branchId, $date);
        $this->refreshProductSummaries($branchId, $date);
        $this->refreshProcurementDailySummaries($branchId, $date);
    }

    /**
     * @return array<int>
     */
    private function resolveBranchIds(?int $branchId): array
    {
        if ($branchId !== null) {
            return [$branchId];
        }

        return Branch::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function resolveDate(?string $date): string
    {
        return $date !== null
            ? Carbon::parse($date)->toDateString()
            : now()->toDateString();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsertDailySummary(int $branchId, string $summaryDate, array $values): void
    {
        $existing = DB::table('rpt_inventory_daily_summaries')
            ->where('branch_id', $branchId)
            ->where('summary_date', $summaryDate)
            ->first();

        if ($existing) {
            DB::table('rpt_inventory_daily_summaries')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('rpt_inventory_daily_summaries')->insert(array_merge([
            'branch_id' => $branchId,
            'summary_date' => $summaryDate,
            'created_at' => $values['updated_at'] ?? now(),
        ], $values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsertBranchSummary(int $branchId, string $snapshotDate, array $values): void
    {
        $existing = DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $branchId)
            ->where('snapshot_date', $snapshotDate)
            ->first();

        if ($existing) {
            DB::table('rpt_inventory_branch_summaries')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('rpt_inventory_branch_summaries')->insert(array_merge([
            'branch_id' => $branchId,
            'snapshot_date' => $snapshotDate,
            'created_at' => $values['updated_at'] ?? now(),
        ], $values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsertProductSummary(int $branchId, int $productId, string $snapshotDate, array $values): void
    {
        $existing = DB::table('rpt_inventory_product_summaries')
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('snapshot_date', $snapshotDate)
            ->first();

        if ($existing) {
            DB::table('rpt_inventory_product_summaries')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('rpt_inventory_product_summaries')->insert(array_merge([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'snapshot_date' => $snapshotDate,
            'created_at' => $values['updated_at'] ?? now(),
        ], $values));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function upsertProcurementSummary(int $branchId, string $summaryDate, ?int $supplierId, array $values): void
    {
        $query = DB::table('rpt_procurement_daily_summaries')
            ->where('branch_id', $branchId)
            ->where('summary_date', $summaryDate);

        if ($supplierId === null) {
            $query->whereNull('supplier_id');
        } else {
            $query->where('supplier_id', $supplierId);
        }

        $existing = $query->first();

        if ($existing) {
            DB::table('rpt_procurement_daily_summaries')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('rpt_procurement_daily_summaries')->insert(array_merge([
            'branch_id' => $branchId,
            'supplier_id' => $supplierId,
            'summary_date' => $summaryDate,
            'created_at' => $values['updated_at'] ?? now(),
        ], $values));
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

    /**
     * @return array<string, float>
     */
    private function stockByProductMap(int $branchId): array
    {
        return DB::table('trx_inventory_movements')
            ->select('product_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
            ->where('branch_id', $branchId)
            ->groupBy('product_id')
            ->pluck('current_stock', 'product_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function lastMovementDateByProduct(int $branchId, string $direction, string $asOfDate): array
    {
        $query = DB::table('trx_inventory_movements')
            ->select('product_id')
            ->where('branch_id', $branchId)
            ->whereDate('movement_date', '<=', $asOfDate);

        if ($direction === 'in') {
            $query->where('quantity_in', '>', 0)
                ->selectRaw('MAX(movement_date) as movement_date');
        } else {
            $query->where('quantity_out', '>', 0)
                ->selectRaw('MAX(movement_date) as movement_date');
        }

        return $query
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->product_id => Carbon::parse($row->movement_date)->toDateString(),
            ])
            ->all();
    }

    /**
     * @return array<int, object>
     */
    private function outboundWindowByProduct(
        int $branchId,
        string $snapshotDate,
        string $window7,
        string $window30,
        string $window90,
    ): array {
        $rows = DB::table('trx_inventory_movements')
            ->leftJoin('inv_products', 'inv_products.id', '=', 'trx_inventory_movements.product_id')
            ->select('trx_inventory_movements.product_id')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_date >= ? THEN trx_inventory_movements.quantity_out ELSE 0 END), 0) as outbound_qty_7d',
                [$window7]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_date >= ? THEN trx_inventory_movements.quantity_out ELSE 0 END), 0) as outbound_qty_30d',
                [$window30]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_date >= ? THEN trx_inventory_movements.quantity_out ELSE 0 END), 0) as outbound_qty_90d',
                [$window90]
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN trx_inventory_movements.movement_date >= ? THEN trx_inventory_movements.quantity_out * COALESCE(trx_inventory_movements.unit_cost, inv_products.average_cost) ELSE 0 END), 0) as outbound_value_30d',
                [$window30]
            )
            ->where('trx_inventory_movements.branch_id', $branchId)
            ->where('trx_inventory_movements.quantity_out', '>', 0)
            ->whereDate('trx_inventory_movements.movement_date', '<=', $snapshotDate)
            ->groupBy('trx_inventory_movements.product_id')
            ->get();

        return $rows->keyBy('product_id')->all();
    }

    /**
     * @return array<int, string>
     */
    private function batchAgeAnchorMap(int $branchId): array
    {
        $batchStock = DB::table('trx_inventory_movements')
            ->select('product_id', 'inventory_batch_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as batch_stock')
            ->where('branch_id', $branchId)
            ->whereNotNull('inventory_batch_id')
            ->groupBy('product_id', 'inventory_batch_id')
            ->havingRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) > 0');

        return DB::table('inv_inventory_batches')
            ->joinSub($batchStock, 'batch_stock', function ($join) {
                $join->on('batch_stock.inventory_batch_id', '=', 'inv_inventory_batches.id');
            })
            ->where('inv_inventory_batches.branch_id', $branchId)
            ->select('batch_stock.product_id', 'inv_inventory_batches.received_date')
            ->orderBy('inv_inventory_batches.received_date')
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $group) => Carbon::parse($group->first()->received_date)->toDateString())
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function preferredSupplierByProduct(int $branchId): array
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

        return $rows->pluck('supplier_id', 'product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array{expiring_soon: int, expired: int}
     */
    private function batchExpiryCounts(int $branchId, string $snapshotDate): array
    {
        $expiringThreshold = Carbon::parse($snapshotDate)->addDays(self::EXPIRING_SOON_DAYS)->toDateString();

        $stockSub = InventoryMovement::query()
            ->select('inventory_batch_id')
            ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as derived_stock')
            ->where('branch_id', $branchId)
            ->whereNotNull('inventory_batch_id')
            ->groupBy('inventory_batch_id');

        $base = InventoryBatch::query()
            ->select('inv_inventory_batches.id')
            ->joinSub($stockSub, 'batch_stock', function ($join) {
                $join->on('inv_inventory_batches.id', '=', 'batch_stock.inventory_batch_id');
            })
            ->where('inv_inventory_batches.branch_id', $branchId)
            ->where('inv_inventory_batches.is_active', true)
            ->whereNotNull('inv_inventory_batches.expiry_date')
            ->where('batch_stock.derived_stock', '>', 0);

        $expiredCount = (int) (clone $base)
            ->whereDate('inv_inventory_batches.expiry_date', '<', $snapshotDate)
            ->count();

        $expiringSoonCount = (int) (clone $base)
            ->whereDate('inv_inventory_batches.expiry_date', '>=', $snapshotDate)
            ->whereDate('inv_inventory_batches.expiry_date', '<=', $expiringThreshold)
            ->count();

        return [
            'expiring_soon' => $expiringSoonCount,
            'expired' => $expiredCount,
        ];
    }

    private function inventoryAccuracyPct(int $branchId): ?float
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

    private function openPurchaseRequestCount(int $branchId): int
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

    private function openPurchaseOrderCount(int $branchId): int
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

    private function openPurchaseOrderOutstandingValue(int $branchId): float
    {
        return (float) PurchaseOrderItem::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_purchase_order_items.purchase_order_id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->whereIn('trx_purchase_orders.status', [
                PurchaseOrder::STATUS_APPROVED,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            ])
            ->whereRaw('trx_purchase_order_items.quantity_ordered > trx_purchase_order_items.quantity_received')
            ->selectRaw(
                'COALESCE(SUM((trx_purchase_order_items.quantity_ordered - trx_purchase_order_items.quantity_received) * trx_purchase_order_items.unit_price), 0) as outstanding_value'
            )
            ->value('outstanding_value');
    }

    private function pendingGoodsReceiptCount(int $branchId): int
    {
        return (int) GoodsReceipt::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                GoodsReceipt::STATUS_DRAFT,
                GoodsReceipt::STATUS_SUBMITTED,
            ])
            ->count();
    }

    private function inTransitTransferCount(int $branchId): int
    {
        return (int) StockTransfer::query()
            ->where('branch_id', $branchId)
            ->where('status', StockTransfer::STATUS_IN_TRANSIT)
            ->count();
    }

    /**
     * @return array{count: int, value: float}
     */
    private function purchaseOrderCreatedMetrics(int $branchId, string $targetDate): array
    {
        $row = PurchaseOrder::query()
            ->leftJoin('trx_purchase_order_items', 'trx_purchase_order_items.purchase_order_id', '=', 'trx_purchase_orders.id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereDate('trx_purchase_orders.order_date', $targetDate)
            ->selectRaw('COUNT(DISTINCT trx_purchase_orders.id) as po_count')
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_ordered * trx_purchase_order_items.unit_price), 0) as po_value')
            ->first();

        return [
            'count' => (int) ($row->po_count ?? 0),
            'value' => (float) ($row->po_value ?? 0),
        ];
    }

    /**
     * @return array{count: int, value: float}
     */
    private function goodsReceiptPostedMetrics(int $branchId, string $targetDate): array
    {
        $row = GoodsReceipt::query()
            ->leftJoin('trx_goods_receipt_items', 'trx_goods_receipt_items.goods_receipt_id', '=', 'trx_goods_receipts.id')
            ->where('trx_goods_receipts.branch_id', $branchId)
            ->where('trx_goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->whereNotNull('trx_goods_receipts.posted_at')
            ->whereDate('trx_goods_receipts.posted_at', $targetDate)
            ->selectRaw('COUNT(DISTINCT trx_goods_receipts.id) as gr_count')
            ->selectRaw('COALESCE(SUM(trx_goods_receipt_items.line_total), 0) as gr_value')
            ->first();

        return [
            'count' => (int) ($row->gr_count ?? 0),
            'value' => (float) ($row->gr_value ?? 0),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function supplierDailyMetrics(int $branchId, int $supplierId, string $targetDate): array
    {
        $poCreated = PurchaseOrder::query()
            ->leftJoin('trx_purchase_order_items', 'trx_purchase_order_items.purchase_order_id', '=', 'trx_purchase_orders.id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplierId)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereDate('trx_purchase_orders.order_date', $targetDate)
            ->selectRaw('COUNT(DISTINCT trx_purchase_orders.id) as po_count')
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_ordered * trx_purchase_order_items.unit_price), 0) as po_value')
            ->first();

        $grPosted = GoodsReceipt::query()
            ->leftJoin('trx_goods_receipt_items', 'trx_goods_receipt_items.goods_receipt_id', '=', 'trx_goods_receipts.id')
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_goods_receipts.purchase_order_id')
            ->where('trx_goods_receipts.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplierId)
            ->where('trx_goods_receipts.status', GoodsReceipt::STATUS_POSTED)
            ->whereNotNull('trx_goods_receipts.posted_at')
            ->whereDate('trx_goods_receipts.posted_at', $targetDate)
            ->selectRaw('COUNT(DISTINCT trx_goods_receipts.id) as gr_count')
            ->selectRaw('COALESCE(SUM(trx_goods_receipt_items.line_total), 0) as gr_value')
            ->first();

        $ledgerPurchaseValue = (float) InventoryMovement::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplierId)
            ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
            ->whereDate('movement_date', $targetDate)
            ->selectRaw('COALESCE(SUM(quantity_in * unit_cost), 0) as total')
            ->value('total');

        $orderedQty = (float) PurchaseOrderItem::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_purchase_order_items.purchase_order_id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplierId)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereDate('trx_purchase_orders.order_date', $targetDate)
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_ordered), 0) as total')
            ->value('total');

        $fulfilledQty = (float) PurchaseOrderItem::query()
            ->join('trx_purchase_orders', 'trx_purchase_orders.id', '=', 'trx_purchase_order_items.purchase_order_id')
            ->where('trx_purchase_orders.branch_id', $branchId)
            ->where('trx_purchase_orders.supplier_id', $supplierId)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('trx_purchase_orders.status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereDate('trx_purchase_orders.order_date', $targetDate)
            ->selectRaw('COALESCE(SUM(trx_purchase_order_items.quantity_received), 0) as total')
            ->value('total');

        $onTimeStats = $this->supplierOnTimeStatsForDate($branchId, $supplierId, $targetDate);

        return [
            'po_created_count' => (int) ($poCreated->po_count ?? 0),
            'po_created_value' => (float) ($poCreated->po_value ?? 0),
            'gr_posted_count' => (int) ($grPosted->gr_count ?? 0),
            'gr_received_value' => (float) ($grPosted->gr_value ?? 0),
            'ledger_purchase_value' => $ledgerPurchaseValue,
            'supplier_order_count' => (int) ($poCreated->po_count ?? 0),
            'supplier_received_value' => (float) ($grPosted->gr_value ?? 0) > 0
                ? (float) ($grPosted->gr_value ?? 0)
                : $ledgerPurchaseValue,
            'supplier_on_time_count' => $onTimeStats['on_time_count'],
            'supplier_dated_po_count' => $onTimeStats['dated_count'],
            'supplier_fulfilled_qty' => $fulfilledQty,
            'supplier_ordered_qty' => $orderedQty,
        ];
    }

    /**
     * @return array{on_time_count: int, dated_count: int}
     */
    private function supplierOnTimeStatsForDate(int $branchId, int $supplierId, string $targetDate): array
    {
        $datedPos = PurchaseOrder::query()
            ->where('branch_id', $branchId)
            ->where('supplier_id', $supplierId)
            ->where('status', '!=', PurchaseOrder::STATUS_DRAFT)
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->whereNotNull('expected_delivery_date')
            ->whereDate('order_date', $targetDate)
            ->get(['id', 'expected_delivery_date']);

        if ($datedPos->isEmpty()) {
            return ['on_time_count' => 0, 'dated_count' => 0];
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
            'on_time_count' => $onTimeCount,
            'dated_count' => $datedPos->count(),
        ];
    }

    /**
     * @param  array<int, array{product_id: int, outbound_qty_90d: float}>  $rankCandidates
     */
    private function applyFastMovingRanks(int $branchId, string $snapshotDate, array $rankCandidates, Carbon $now): void
    {
        usort($rankCandidates, fn (array $a, array $b) => $b['outbound_qty_90d'] <=> $a['outbound_qty_90d']);

        $rank = 1;

        foreach ($rankCandidates as $candidate) {
            DB::table('rpt_inventory_product_summaries')
                ->where('branch_id', $branchId)
                ->where('product_id', $candidate['product_id'])
                ->where('snapshot_date', $snapshotDate)
                ->update([
                    'fast_moving_rank' => $rank,
                    'updated_at' => $now,
                ]);

            $rank++;
        }
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
}
