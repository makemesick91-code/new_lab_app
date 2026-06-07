<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Repositories\InventoryAnalyticsRepository;
use App\Modules\Inventory\Repositories\InventorySummaryAnalyticsRepository;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    config(['inventory.analytics_summary_enabled' => true]);
    app()->forgetInstance(InventoryAnalyticsRepositoryInterface::class);
    app()->forgetInstance(InventorySummaryAnalyticsRepository::class);
    app()->forgetInstance(InventoryAnalyticsRepository::class);

    $this->branchA = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branchB = Branch::factory()->create(['code' => 'TST-SUM', 'name' => 'Summary Test Branch B']);
    $this->today = now()->toDateString();
    $this->refreshService = app(InventoryAnalyticsSummaryRefreshService::class);
    $this->summaryRepository = app(InventorySummaryAnalyticsRepository::class);
    $this->liveRepository = app(InventoryAnalyticsRepository::class);
});

function summaryRepoMovement(
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

function refreshAllSummariesForBranch(InventoryAnalyticsSummaryRefreshService $service, Branch $branch, string $date): void
{
    $service->refreshDailySummaries($branch->id, $date);
    $service->refreshBranchSummaries($branch->id, $date);
    $service->refreshProductSummaries($branch->id, $date);
    $service->refreshProcurementDailySummaries($branch->id, $date);
}

it('implements InventoryAnalyticsRepositoryInterface', function () {
    expect($this->summaryRepository)->toBeInstanceOf(InventoryAnalyticsRepositoryInterface::class);
});

it('reads KPI strip metrics from rpt_inventory_branch_summaries', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 100,
        'is_active' => true,
        'alert_enabled' => true,
        'reorder_point' => 50,
    ]);

    summaryRepoMovement($this->branchA, $product, $location, qtyIn: 10);

    refreshAllSummariesForBranch($this->refreshService, $this->branchA, $this->today);

    expect($this->summaryRepository->getInventoryValue($this->branchA->id))->toBe(1000.0)
        ->and($this->summaryRepository->getActiveSkuCount($this->branchA->id))->toBe(1)
        ->and($this->summaryRepository->getLowStockCount($this->branchA->id))->toBe(1);
});

it('reads consumption trend from rpt_inventory_daily_summaries', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create(['branch_id' => $this->branchA->id, 'average_cost' => 10]);

    summaryRepoMovement($this->branchA, $product, $location, qtyOut: 4);

    $this->refreshService->refreshDailySummaries($this->branchA->id, $this->today);

    $trend = $this->summaryRepository->getConsumptionTrend($this->branchA->id);
    $currentMonth = now()->format('Y-m');
    $currentRow = collect($trend)->firstWhere('period', $currentMonth);

    expect($currentRow)->not->toBeNull()
        ->and($currentRow['outbound_qty'])->toBe(4.0);
});

it('reads fast moving items from rpt_inventory_product_summaries', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $fastProduct = Product::factory()->create(['branch_id' => $this->branchA->id, 'code' => 'FAST-01']);
    $slowProduct = Product::factory()->create(['branch_id' => $this->branchA->id, 'code' => 'SLOW-01']);

    summaryRepoMovement($this->branchA, $fastProduct, $location, qtyIn: 20);
    summaryRepoMovement($this->branchA, $fastProduct, $location, qtyOut: 15);
    summaryRepoMovement($this->branchA, $slowProduct, $location, qtyIn: 10);
    summaryRepoMovement($this->branchA, $slowProduct, $location, qtyOut: 1);

    $this->refreshService->refreshProductSummaries($this->branchA->id, $this->today);

    $items = $this->summaryRepository->getFastMovingItems($this->branchA->id, days: 90, limit: 5);

    expect($items)->toHaveCount(2)
        ->and($items->first()['product_code'])->toBe('FAST-01')
        ->and($items->first()['outbound_qty_period'])->toBeGreaterThan($items->last()['outbound_qty_period']);
});

it('reads low stock reorder candidates from rpt_inventory_product_summaries', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $lowProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'alert_enabled' => true,
        'reorder_point' => 20,
        'minimum_stock' => 5,
    ]);

    summaryRepoMovement($this->branchA, $lowProduct, $location, qtyIn: 8);

    $this->refreshService->refreshProductSummaries($this->branchA->id, $this->today);

    $recommendations = $this->summaryRepository->getReorderRecommendations($this->branchA->id);

    expect($recommendations)->toHaveCount(1)
        ->and($recommendations->first()['product_id'])->toBe($lowProduct->id)
        ->and($recommendations->first()['current_stock'])->toBe(8.0);
});

it('reads purchase trend from rpt_procurement_daily_summaries branch rollup', function () {
    PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branchA->id,
        'order_date' => $this->today,
    ]);

    $this->refreshService->refreshProcurementDailySummaries($this->branchA->id, $this->today);

    $trend = $this->summaryRepository->getPurchaseTrend($this->branchA->id);
    $currentMonth = now()->format('Y-m');
    $currentRow = collect($trend)->firstWhere('period', $currentMonth);

    expect($currentRow)->not->toBeNull()
        ->and($currentRow['po_count'])->toBe(1);
});

it('does not mix supplier slice rows into branch rollup procurement trend', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);

    PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $supplier->id,
        'order_date' => $this->today,
    ]);

    $this->refreshService->refreshProcurementDailySummaries($this->branchA->id, $this->today);

    $branchRollup = DB::table('rpt_procurement_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->today)
        ->whereNull('supplier_id')
        ->first();

    $supplierSlice = DB::table('rpt_procurement_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->today)
        ->where('supplier_id', $supplier->id)
        ->first();

    expect($branchRollup)->not->toBeNull()
        ->and($supplierSlice)->not->toBeNull()
        ->and((int) $branchRollup->po_created_count)->toBe(1)
        ->and((int) $supplierSlice->supplier_order_count)->toBe(1);

    $trend = $this->summaryRepository->getPurchaseTrend($this->branchA->id);
    $currentRow = collect($trend)->firstWhere('period', now()->format('Y-m'));

    expect($currentRow['po_count'])->toBe(1);
});

it('returns safe empty values when summary tables are empty', function () {
    expect($this->summaryRepository->getInventoryValue($this->branchA->id))->toBe(0.0)
        ->and($this->summaryRepository->getActiveSkuCount($this->branchA->id))->toBe(0)
        ->and($this->summaryRepository->getInventoryAccuracy($this->branchA->id))->toBeNull()
        ->and($this->summaryRepository->getFastMovingItems($this->branchA->id))->toBeEmpty()
        ->and($this->summaryRepository->getConsumptionTrend($this->branchA->id))->toBeArray();
});

it('isolates branch summaries so branch A does not see branch B data', function () {
    $locationA = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $locationB = InventoryLocation::factory()->create(['branch_id' => $this->branchB->id]);
    $productA = Product::factory()->create(['branch_id' => $this->branchA->id, 'average_cost' => 10]);
    $productB = Product::factory()->create(['branch_id' => $this->branchB->id, 'average_cost' => 50]);

    summaryRepoMovement($this->branchA, $productA, $locationA, qtyIn: 5);
    summaryRepoMovement($this->branchB, $productB, $locationB, qtyIn: 20);

    refreshAllSummariesForBranch($this->refreshService, $this->branchA, $this->today);
    refreshAllSummariesForBranch($this->refreshService, $this->branchB, $this->today);

    expect($this->summaryRepository->getInventoryValue($this->branchA->id))->toBe(50.0)
        ->and($this->summaryRepository->getInventoryValue($this->branchB->id))->toBe(1000.0)
        ->and($this->summaryRepository->getActiveSkuCount($this->branchA->id))->toBe(1)
        ->and($this->summaryRepository->getActiveSkuCount($this->branchB->id))->toBe(1);
});

it('returns KPI shapes compatible with live repository after refresh', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 25,
        'is_active' => true,
    ]);

    summaryRepoMovement($this->branchA, $product, $location, qtyIn: 12, qtyOut: 2);

    refreshAllSummariesForBranch($this->refreshService, $this->branchA, $this->today);

    expect($this->summaryRepository->getInventoryValue($this->branchA->id))
        ->toBe($this->liveRepository->getInventoryValue($this->branchA->id))
        ->and($this->summaryRepository->getActiveSkuCount($this->branchA->id))
        ->toBe($this->liveRepository->getActiveSkuCount($this->branchA->id));
});

it('falls back to live repository for supplier performance', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);

    PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $supplier->id,
    ]);

    $summaryResult = $this->summaryRepository->getSupplierPerformance($this->branchA->id);
    $liveResult = $this->liveRepository->getSupplierPerformance($this->branchA->id);

    expect($summaryResult)->toHaveCount(1)
        ->and($summaryResult->first()['supplier_id'])->toBe($supplier->id)
        ->and($summaryResult->first()['order_count'])->toBe($liveResult->first()['order_count']);
});

it('falls back to live repository for fast moving when days window is unsupported', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create(['branch_id' => $this->branchA->id]);

    summaryRepoMovement($this->branchA, $product, $location, qtyIn: 10, qtyOut: 3);

    $this->refreshService->refreshProductSummaries($this->branchA->id, $this->today);

    $summaryViaFallback = $this->summaryRepository->getFastMovingItems($this->branchA->id, days: 45, limit: 5);
    $live = $this->liveRepository->getFastMovingItems($this->branchA->id, days: 45, limit: 5);

    expect($summaryViaFallback->pluck('product_id')->all())->toBe($live->pluck('product_id')->all());
});
