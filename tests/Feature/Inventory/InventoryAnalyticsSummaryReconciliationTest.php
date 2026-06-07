<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Repositories\InventoryAnalyticsRepository;
use App\Modules\Inventory\Repositories\InventorySummaryAnalyticsRepository;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

const RECON_DECIMAL_TOLERANCE = 0.01;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branchA = Branch::factory()->create(['code' => 'REC-A', 'name' => 'Reconciliation Branch A']);
    $this->branchB = Branch::factory()->create(['code' => 'REC-B', 'name' => 'Reconciliation Branch B']);
    $this->today = now()->toDateString();
    $this->refreshService = app(InventoryAnalyticsSummaryRefreshService::class);
    $this->liveRepository = app(InventoryAnalyticsRepository::class);
    $this->summaryRepository = app(InventorySummaryAnalyticsRepository::class);
});

function reconciliationMovement(
    Branch $branch,
    Product $product,
    InventoryLocation $location,
    float $qtyIn = 0,
    float $qtyOut = 0,
    array $extra = [],
): InventoryMovement {
    return InventoryMovement::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => $qtyOut > 0 ? InventoryMovement::TYPE_ADJUSTMENT_OUT : InventoryMovement::TYPE_PURCHASE,
        'movement_date' => now()->toDateString(),
        'quantity_in' => $qtyIn,
        'quantity_out' => $qtyOut,
    ], $extra));
}

function buildReconciliationBranchDataset(Branch $branch): array
{
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang '.$branch->code]);
    $supplier = Supplier::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);

    $fastMover = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'FAST-'.uniqid(),
        'average_cost' => 100_000,
        'reorder_point' => 20,
        'minimum_stock' => 10,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $lowStockSku = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'LOW-'.uniqid(),
        'average_cost' => 50_000,
        'reorder_point' => 15,
        'minimum_stock' => 5,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $deadStockSku = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'DEAD-'.uniqid(),
        'average_cost' => 25_000,
        'reorder_point' => 5,
        'minimum_stock' => 1,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $inactiveSku = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'INACT-'.uniqid(),
        'average_cost' => 10_000,
        'is_active' => false,
    ]);

    reconciliationMovement($branch, $fastMover, $location, qtyIn: 40);
    reconciliationMovement($branch, $fastMover, $location, qtyOut: 25, extra: [
        'movement_date' => now()->subDays(10)->toDateString(),
    ]);
    reconciliationMovement($branch, $fastMover, $location, qtyOut: 5, extra: [
        'movement_date' => now()->subDays(2)->toDateString(),
    ]);

    reconciliationMovement($branch, $lowStockSku, $location, qtyIn: 8);

    reconciliationMovement($branch, $deadStockSku, $location, qtyIn: 12, extra: [
        'movement_date' => now()->subDays(120)->toDateString(),
    ]);

    reconciliationMovement($branch, $inactiveSku, $location, qtyIn: 50);

    PurchaseRequest::factory()->submitted()->create(['branch_id' => $branch->id]);
    PurchaseRequest::factory()->approved()->create(['branch_id' => $branch->id]);

    $openPo = PurchaseOrder::factory()->approved()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
    ]);
    PurchaseOrder::factory()->sent()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
    ]);
    PurchaseOrder::factory()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        'order_date' => now()->toDateString(),
    ]);
    PurchaseOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => PurchaseOrder::STATUS_DRAFT,
    ]);

    $closedPo = PurchaseOrder::factory()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
    ]);

    GoodsReceipt::factory()->draft()->forPurchaseOrder($openPo)->create(['branch_id' => $branch->id]);
    GoodsReceipt::factory()->submitted()->forPurchaseOrder($closedPo)->create(['branch_id' => $branch->id]);

    StockTransfer::factory()->inTransit()->create(['branch_id' => $branch->id]);

    return compact('location', 'supplier', 'fastMover', 'lowStockSku', 'deadStockSku', 'inactiveSku');
}

function refreshAllSummariesForReconciliation(
    InventoryAnalyticsSummaryRefreshService $service,
    Branch $branch,
    string $date,
): void {
    $movementDates = DB::table('trx_inventory_movements')
        ->where('branch_id', $branch->id)
        ->selectRaw('DATE(movement_date) as movement_day')
        ->distinct()
        ->pluck('movement_day');

    foreach ($movementDates as $movementDate) {
        $service->refreshDailySummaries($branch->id, (string) $movementDate);
    }

    $procurementDates = collect()
        ->merge(
            PurchaseOrder::query()
                ->where('branch_id', $branch->id)
                ->pluck('order_date')
                ->map(fn ($value) => Carbon::parse($value)->toDateString())
        )
        ->merge(
            GoodsReceipt::query()
                ->where('branch_id', $branch->id)
                ->whereNotNull('posted_at')
                ->pluck('posted_at')
                ->map(fn ($value) => Carbon::parse($value)->toDateString())
        )
        ->merge(
            InventoryMovement::query()
                ->where('branch_id', $branch->id)
                ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
                ->pluck('movement_date')
                ->map(fn ($value) => Carbon::parse($value)->toDateString())
        )
        ->push($date)
        ->unique()
        ->values();

    foreach ($procurementDates as $procurementDate) {
        $service->refreshProcurementDailySummaries($branch->id, (string) $procurementDate);
    }

    $service->refreshBranchSummaries($branch->id, $date);
    $service->refreshProductSummaries($branch->id, $date);
}

function assertFloatClose(float $live, float $summary, float $tolerance = RECON_DECIMAL_TOLERANCE): void
{
    expect(abs($live - $summary))->toBeLessThanOrEqual($tolerance);
}

function assertTrendRowsMatch(array $liveTrend, array $summaryTrend): void
{
    expect(count($summaryTrend))->toBe(count($liveTrend));

    foreach ($liveTrend as $index => $liveRow) {
        $summaryRow = $summaryTrend[$index];
        expect($summaryRow['period'])->toBe($liveRow['period']);

        foreach (array_keys($liveRow) as $key) {
            if ($key === 'period') {
                continue;
            }

            if (is_float($liveRow[$key]) || is_int($liveRow[$key])) {
                assertFloatClose((float) $liveRow[$key], (float) $summaryRow[$key]);
            } else {
                expect($summaryRow[$key])->toBe($liveRow[$key]);
            }
        }
    }
}

function assertCollectionShapeCompatible($live, $summary, array $requiredKeys): void
{
    expect($summary)->toHaveCount($live->count());

    if ($live->isEmpty()) {
        return;
    }

    foreach ($live->first() as $key => $value) {
        expect($summary->first())->toHaveKey($key);
    }

    foreach ($requiredKeys as $key) {
        expect($summary->first())->toHaveKey($key);
    }
}

it('reconciles KPI strip scalars between live ledger and summary repository', function () {
    buildReconciliationBranchDataset($this->branchA);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);

    $branchId = $this->branchA->id;

    assertFloatClose(
        $this->liveRepository->getInventoryValue($branchId),
        $this->summaryRepository->getInventoryValue($branchId),
    );

    expect($this->summaryRepository->getActiveSkuCount($branchId))
        ->toBe($this->liveRepository->getActiveSkuCount($branchId))
        ->and($this->summaryRepository->getLowStockCount($branchId))
        ->toBe($this->liveRepository->getLowStockCount($branchId))
        ->and($this->summaryRepository->getDeadStockCount($branchId))
        ->toBe($this->liveRepository->getDeadStockCount($branchId))
        ->and($this->summaryRepository->getOpenPurchaseOrderCount($branchId))
        ->toBe($this->liveRepository->getOpenPurchaseOrderCount($branchId))
        ->and($this->summaryRepository->getPendingGoodsReceiptCount($branchId))
        ->toBe($this->liveRepository->getPendingGoodsReceiptCount($branchId))
        ->and($this->summaryRepository->getOpenPurchaseRequestCount($branchId))
        ->toBe($this->liveRepository->getOpenPurchaseRequestCount($branchId))
        ->and($this->summaryRepository->getInTransitTransferCount($branchId))
        ->toBe($this->liveRepository->getInTransitTransferCount($branchId))
        ->and($this->summaryRepository->getInventoryAccuracy($branchId))
        ->toBe($this->liveRepository->getInventoryAccuracy($branchId));
});

it('reconciles consumption trend between live and summary', function () {
    buildReconciliationBranchDataset($this->branchA);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);

    assertTrendRowsMatch(
        $this->liveRepository->getConsumptionTrend($this->branchA->id),
        $this->summaryRepository->getConsumptionTrend($this->branchA->id),
    );
});

it('reconciles purchase trend between live and summary', function () {
    buildReconciliationBranchDataset($this->branchA);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);

    $branchId = $this->branchA->id;
    $currentMonth = now()->format('Y-m');
    $liveTrend = $this->liveRepository->getPurchaseTrend($branchId);
    $summaryTrend = $this->summaryRepository->getPurchaseTrend($branchId);

    assertTrendRowsMatch($liveTrend, $summaryTrend);

    $liveRow = collect($liveTrend)->firstWhere('period', $currentMonth);
    $summaryRow = collect($summaryTrend)->firstWhere('period', $currentMonth);

    expect($liveRow['po_count'])->toBe($summaryRow['po_count'])
        ->and($liveRow['gr_count'])->toBe($summaryRow['gr_count']);
    assertFloatClose((float) $liveRow['po_value'], (float) $summaryRow['po_value']);
    assertFloatClose((float) $liveRow['gr_received_value'], (float) $summaryRow['gr_received_value']);
    assertFloatClose((float) $liveRow['ledger_purchase_value'], (float) $summaryRow['ledger_purchase_value']);
});

it('reconciles movement intelligence lists with compatible shapes', function () {
    buildReconciliationBranchDataset($this->branchA);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);

    $branchId = $this->branchA->id;

    $liveFast = $this->liveRepository->getFastMovingItems($branchId, days: 90, limit: 10);
    $summaryFast = $this->summaryRepository->getFastMovingItems($branchId, days: 90, limit: 10);

    assertCollectionShapeCompatible($liveFast, $summaryFast, [
        'product_id', 'product_code', 'product_name', 'current_stock',
        'outbound_qty_period', 'outbound_value_period', 'stock_value',
    ]);
    expect($summaryFast->pluck('product_id')->all())->toBe($liveFast->pluck('product_id')->all());

    $liveDead = $this->liveRepository->getDeadStockItems($branchId, days: 90, limit: 10);
    $summaryDead = $this->summaryRepository->getDeadStockItems($branchId, days: 90, limit: 10);

    assertCollectionShapeCompatible($liveDead, $summaryDead, [
        'product_id', 'product_code', 'product_name', 'current_stock',
        'stock_value', 'last_out_date', 'days_since_last_out',
    ]);
    expect($summaryDead->count())->toBe($liveDead->count());

    $liveSlow = $this->liveRepository->getSlowMovingItems($branchId, days: 90, limit: 10);
    $summarySlow = $this->summaryRepository->getSlowMovingItems($branchId, days: 90, limit: 10);

    assertCollectionShapeCompatible($liveSlow, $summarySlow, [
        'product_id', 'outbound_qty_period', 'stock_value',
    ]);
});

it('reconciles stock aging bucket totals when product summary snapshot exists', function () {
    buildReconciliationBranchDataset($this->branchA);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);

    $branchId = $this->branchA->id;
    $liveAging = $this->liveRepository->getStockAging($branchId);
    $summaryAging = $this->summaryRepository->getStockAging($branchId);

    expect($summaryAging['granularity'])->toBe($liveAging['granularity'])
        ->and(array_keys($summaryAging['buckets']))->toBe(array_keys($liveAging['buckets']));

    foreach ($liveAging['buckets'] as $bucket => $liveBucket) {
        expect($summaryAging['buckets'][$bucket]['product_count'])
            ->toBe($liveBucket['product_count']);
        assertFloatClose(
            (float) $liveBucket['total_qty'],
            (float) $summaryAging['buckets'][$bucket]['total_qty'],
        );
        assertFloatClose(
            (float) $liveBucket['total_value'],
            (float) $summaryAging['buckets'][$bucket]['total_value'],
        );
    }
});

it('isolates branch A reconciliation from branch B data', function () {
    buildReconciliationBranchDataset($this->branchA);

    $locationB = InventoryLocation::factory()->create(['branch_id' => $this->branchB->id]);
    $productB = Product::factory()->create([
        'branch_id' => $this->branchB->id,
        'average_cost' => 75_000,
        'is_active' => true,
    ]);
    reconciliationMovement($this->branchB, $productB, $locationB, qtyIn: 4);

    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchB, $this->today);

    $liveA = $this->liveRepository->getInventoryValue($this->branchA->id);
    $liveB = $this->liveRepository->getInventoryValue($this->branchB->id);
    $summaryA = $this->summaryRepository->getInventoryValue($this->branchA->id);
    $summaryB = $this->summaryRepository->getInventoryValue($this->branchB->id);

    assertFloatClose($liveA, $summaryA);
    assertFloatClose($liveB, $summaryB);
    expect($liveA)->toBeGreaterThan($liveB)
        ->and($summaryB)->toBe(300_000.0);

    $branchAProductIds = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchA->id)
        ->pluck('product_id')
        ->all();

    $branchBProductIds = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchB->id)
        ->pluck('product_id')
        ->all();

    expect($branchAProductIds)->not->toContain($productB->id)
        ->and($branchBProductIds)->toContain($productB->id)
        ->and($branchBProductIds)->not->toEqual($branchAProductIds);
});

it('returns safe empty summary values without error before refresh', function () {
    expect($this->summaryRepository->getInventoryValue($this->branchA->id))->toBe(0.0)
        ->and($this->summaryRepository->getActiveSkuCount($this->branchA->id))->toBe(0)
        ->and($this->summaryRepository->getLowStockCount($this->branchA->id))->toBe(0)
        ->and($this->summaryRepository->getDeadStockCount($this->branchA->id))->toBe(0)
        ->and($this->summaryRepository->getInventoryAccuracy($this->branchA->id))->toBeNull()
        ->and($this->summaryRepository->getFastMovingItems($this->branchA->id))->toBeEmpty()
        ->and($this->summaryRepository->getConsumptionTrend($this->branchA->id))->toBeArray();
});

it('stores product current_stock as ledger sum quantity_in minus quantity_out', function () {
    $data = buildReconciliationBranchDataset($this->branchA);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);

    $ledgerStock = (float) DB::table('trx_inventory_movements')
        ->where('branch_id', $this->branchA->id)
        ->where('product_id', $data['fastMover']->id)
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');

    $summaryRow = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('product_id', $data['fastMover']->id)
        ->where('snapshot_date', $this->today)
        ->first();

    expect($summaryRow)->not->toBeNull();
    assertFloatClose($ledgerStock, (float) $summaryRow->current_stock);
    assertFloatClose(
        $ledgerStock,
        (float) $this->liveRepository->getFastMovingItems($this->branchA->id, days: 90, limit: 1)
            ->firstWhere('product_id', $data['fastMover']->id)['current_stock'],
    );
});

it('documents supplier performance as live fallback with identical output', function () {
    $data = buildReconciliationBranchDataset($this->branchA);
    refreshAllSummariesForReconciliation($this->refreshService, $this->branchA, $this->today);

    $live = $this->liveRepository->getSupplierPerformance($this->branchA->id);
    $summary = $this->summaryRepository->getSupplierPerformance($this->branchA->id);

    expect($summary->pluck('supplier_id')->all())->toBe($live->pluck('supplier_id')->all());

    $liveRow = $live->firstWhere('supplier_id', $data['supplier']->id);
    $summaryRow = $summary->firstWhere('supplier_id', $data['supplier']->id);

    expect($liveRow)->not->toBeNull()
        ->and($summaryRow)->not->toBeNull()
        ->and($summaryRow['order_count'])->toBe($liveRow['order_count'])
        ->and($summaryRow['received_value'])->toBe($liveRow['received_value']);
});
