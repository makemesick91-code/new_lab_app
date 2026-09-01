<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Controllers\InventoryAnalyticsController;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->analytics = app(InventoryAnalyticsService::class);
    $this->stockService = app(InventoryStockService::class);
});

function createMovementWithDate(
    Branch $branch,
    Product $product,
    InventoryLocation $location,
    string $movementDate,
    float $qtyIn = 0,
    float $qtyOut = 0,
    ?int $batchId = null,
): InventoryMovement {
    return InventoryMovement::factory()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batchId,
        'movement_type' => $qtyOut > 0 ? InventoryMovement::TYPE_ADJUSTMENT_OUT : InventoryMovement::TYPE_PURCHASE,
        'movement_date' => $movementDate,
        'quantity_in' => $qtyIn,
        'quantity_out' => $qtyOut,
    ]);
}

it('ranks fast moving products by outbound quantity in the selected period', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $fastProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);
    $slowProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);

    $periodStart = now()->subDays(10)->toDateString();
    $periodEnd = now()->toDateString();

    createMovementWithDate($this->branch, $fastProduct, $location, $periodStart, qtyIn: 100);
    createMovementWithDate($this->branch, $slowProduct, $location, $periodStart, qtyIn: 100);
    createMovementWithDate($this->branch, $fastProduct, $location, now()->subDays(5)->toDateString(), qtyOut: 40);
    createMovementWithDate($this->branch, $slowProduct, $location, now()->subDays(5)->toDateString(), qtyOut: 5);

    $results = $this->analytics->getFastMovingProducts($this->branch->id, [
        'date_from' => $periodStart,
        'date_to' => $periodEnd,
    ]);

    expect($results)->toHaveCount(2)
        ->and($results->first()['product_id'])->toBe($fastProduct->id)
        ->and($results->first()['outbound_qty_period'])->toBe(40.0)
        ->and($results->last()['outbound_qty_period'])->toBe(5.0);
});

it('returns slow moving products with positive stock and low outbound quantity', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $slowProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $zeroStockProduct = Product::factory()->create(['branch_id' => $this->branch->id]);

    $periodStart = now()->subDays(10)->toDateString();

    createMovementWithDate($this->branch, $slowProduct, $location, $periodStart, qtyIn: 20);
    createMovementWithDate($this->branch, $slowProduct, $location, now()->subDays(3)->toDateString(), qtyOut: 1);
    createMovementWithDate($this->branch, $zeroStockProduct, $location, now()->subDays(3)->toDateString(), qtyOut: 5);

    $results = $this->analytics->getSlowMovingProducts($this->branch->id, [
        'date_from' => $periodStart,
        'date_to' => now()->toDateString(),
        'slow_moving_threshold' => 1,
    ]);

    expect($results->pluck('product_id')->all())->toBe([$slowProduct->id])
        ->and($results->first()['current_stock'])->toBe(19.0)
        ->and($results->first()['outbound_qty_period'])->toBe(1.0);
});

it('returns dead stock products with positive stock and no recent outbound movement', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $deadProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 50]);

    createMovementWithDate($this->branch, $deadProduct, $location, now()->subDays(120)->toDateString(), qtyIn: 15);
    createMovementWithDate($this->branch, $deadProduct, $location, now()->subDays(100)->toDateString(), qtyOut: 2);

    $results = $this->analytics->getDeadStockProducts($this->branch->id, [
        'dead_stock_days' => 90,
    ]);

    expect($results)->toHaveCount(1)
        ->and($results->first()['product_id'])->toBe($deadProduct->id)
        ->and($results->first()['current_stock'])->toBe(13.0)
        ->and($results->first()['last_out_date'])->toBe(now()->subDays(100)->toDateString());
});

it('excludes dead stock products with recent outbound movement', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $activeProduct = Product::factory()->create(['branch_id' => $this->branch->id]);

    createMovementWithDate($this->branch, $activeProduct, $location, now()->subDays(120)->toDateString(), qtyIn: 20);
    createMovementWithDate($this->branch, $activeProduct, $location, now()->subDays(10)->toDateString(), qtyOut: 3);

    $results = $this->analytics->getDeadStockProducts($this->branch->id, [
        'dead_stock_days' => 90,
    ]);

    expect($results->pluck('product_id'))->not->toContain($activeProduct->id);
});

it('groups batch stock into aging buckets using received date', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);

    $freshBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'received_date' => now()->subDays(15)->toDateString(),
    ]);
    $oldBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'received_date' => now()->subDays(200)->toDateString(),
    ]);

    createMovementWithDate($this->branch, $product, $location, now()->subDays(15)->toDateString(), qtyIn: 5, batchId: $freshBatch->id);
    createMovementWithDate($this->branch, $product, $location, now()->subDays(200)->toDateString(), qtyIn: 8, batchId: $oldBatch->id);

    $aging = $this->analytics->getInventoryAging($this->branch->id, [
        'aging_granularity' => 'batch',
    ]);

    expect($aging['granularity'])->toBe('batch')
        ->and($aging['items'])->toHaveCount(2)
        ->and($aging['items']->firstWhere('inventory_batch_id', $freshBatch->id)['age_bucket'])
        ->toBe(InventoryAnalyticsService::BUCKET_FRESH)
        ->and($aging['items']->firstWhere('inventory_batch_id', $oldBatch->id)['age_bucket'])
        ->toBe(InventoryAnalyticsService::BUCKET_VERY_OLD)
        ->and($aging['buckets'][InventoryAnalyticsService::BUCKET_FRESH]['product_count'])->toBe(1)
        ->and($aging['buckets'][InventoryAnalyticsService::BUCKET_VERY_OLD]['product_count'])->toBe(1);
});

it('handles null batch product movements using last inbound date proxy for aging', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 75]);

    createMovementWithDate($this->branch, $product, $location, now()->subDays(45)->toDateString(), qtyIn: 12);

    $aging = $this->analytics->getInventoryAging($this->branch->id, [
        'aging_granularity' => 'product',
    ]);

    $item = $aging['items']->firstWhere('product_id', $product->id);

    expect($aging['granularity'])->toBe('product')
        ->and($item['age_bucket'])->toBe(InventoryAnalyticsService::BUCKET_AGING)
        ->and($item['last_in_date'])->toBe(now()->subDays(45)->toDateString())
        ->and($item['age_days'])->toBe(45);
});

it('calculates turnover from outbound quantity and average stock in period', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);

    $dateFrom = now()->subDays(30)->toDateString();
    $dateTo = now()->toDateString();

    createMovementWithDate($this->branch, $product, $location, now()->subDays(40)->toDateString(), qtyIn: 10);
    createMovementWithDate($this->branch, $product, $location, now()->subDays(15)->toDateString(), qtyOut: 6);
    createMovementWithDate($this->branch, $product, $location, now()->subDays(5)->toDateString(), qtyIn: 4);

    $results = $this->analytics->getInventoryTurnover($this->branch->id, [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);

    $row = $results->firstWhere('product_id', $product->id);

    expect($row)->not->toBeNull()
        ->and($row['outbound_qty_period'])->toBe(6.0)
        ->and($row['avg_stock_period'])->toBe(9.0)
        ->and($row['turnover_ratio_qty'])->toBe(0.67);
});

it('returns ledger derived inventory value by category', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $categoryA = ProductCategory::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Zirconia']);
    $categoryB = ProductCategory::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Acrylic']);

    $productA = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'product_category_id' => $categoryA->id,
        'average_cost' => 100,
    ]);
    $productB = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'product_category_id' => $categoryB->id,
        'average_cost' => 50,
    ]);

    createMovementWithDate($this->branch, $productA, $location, now()->subDays(5)->toDateString(), qtyIn: 10);
    createMovementWithDate($this->branch, $productB, $location, now()->subDays(5)->toDateString(), qtyIn: 4);

    $results = $this->analytics->getInventoryValueByCategory($this->branch->id);

    expect((float) $results->firstWhere('category_id', $categoryA->id)->inventory_value)->toBe(1000.0)
        ->and((float) $results->firstWhere('category_id', $categoryB->id)->inventory_value)->toBe(200.0);
});

it('returns ledger derived inventory value by location', function () {
    $warehouse = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Gudang A']);
    $qcRoom = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'QC Room']);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 200]);

    createMovementWithDate($this->branch, $product, $warehouse, now()->subDays(2)->toDateString(), qtyIn: 5);
    createMovementWithDate($this->branch, $product, $qcRoom, now()->subDays(2)->toDateString(), qtyIn: 3);

    $results = $this->analytics->getInventoryValueByLocation($this->branch->id);

    expect((float) $results->firstWhere('id', $warehouse->id)->inventory_value)->toBe(1000.0)
        ->and((float) $results->firstWhere('id', $qcRoom->id)->inventory_value)->toBe(600.0);
});

it('groups monthly outbound value trend by month', function () {
    // The current-month outbound is dated TODAY, not `startOfMonth()->addDays(2)`.
    //
    // The query window this test uses ends at `date_to = now()`, so a movement two
    // days into the month sits in the FUTURE on the 1st and 2nd — the current-month
    // row then does not exist and `$currentRow` is null. That made this a calendar
    // flake that failed on the first two days of every month and passed on the
    // other twenty-eight. Today is still in the current month, so the grouping this
    // test is actually about is unchanged, and the expected 500.0 is unchanged.
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);

    createMovementWithDate($this->branch, $product, $location, now()->startOfMonth()->toDateString(), qtyIn: 20);
    createMovementWithDate($this->branch, $product, $location, now()->toDateString(), qtyOut: 5);
    createMovementWithDate($this->branch, $product, $location, now()->subMonth()->startOfMonth()->addDays(3)->toDateString(), qtyOut: 3);

    $trend = $this->analytics->getMonthlyOutboundValueTrend($this->branch->id, [
        'date_from' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'date_to' => now()->toDateString(),
    ]);

    $currentMonth = now()->format('Y-m');
    $previousMonth = now()->subMonth()->format('Y-m');

    $currentRow = collect($trend)->firstWhere('month', $currentMonth);
    $previousRow = collect($trend)->firstWhere('month', $previousMonth);

    expect($currentRow['outbound_value'])->toBe(500.0)
        ->and($previousRow['outbound_value'])->toBe(300.0);
});

it('applies date filters to period based analytics', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    createMovementWithDate($this->branch, $product, $location, now()->subDays(60)->toDateString(), qtyIn: 10);
    createMovementWithDate($this->branch, $product, $location, now()->subDays(50)->toDateString(), qtyOut: 8);
    createMovementWithDate($this->branch, $product, $location, now()->subDays(5)->toDateString(), qtyOut: 2);

    $narrowPeriod = $this->analytics->getFastMovingProducts($this->branch->id, [
        'date_from' => now()->subDays(10)->toDateString(),
        'date_to' => now()->toDateString(),
    ]);

    $widePeriod = $this->analytics->getFastMovingProducts($this->branch->id, [
        'date_from' => now()->subDays(70)->toDateString(),
        'date_to' => now()->toDateString(),
    ]);

    expect($narrowPeriod->first()['outbound_qty_period'])->toBe(2.0)
        ->and($widePeriod->first()['outbound_qty_period'])->toBe(10.0);
});

it('prevents another branch data from leaking into analytics', function () {
    $otherBranch = Branch::factory()->create();

    $branchLocation = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $branchProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'code' => 'BR-A-001']);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id, 'code' => 'BR-B-001']);

    createMovementWithDate($this->branch, $branchProduct, $branchLocation, now()->subDays(3)->toDateString(), qtyIn: 10, qtyOut: 0);
    createMovementWithDate($this->branch, $branchProduct, $branchLocation, now()->subDays(2)->toDateString(), qtyOut: 4);
    createMovementWithDate($otherBranch, $otherProduct, $otherLocation, now()->subDays(2)->toDateString(), qtyIn: 50);
    createMovementWithDate($otherBranch, $otherProduct, $otherLocation, now()->subDays(1)->toDateString(), qtyOut: 20);

    $fastMoving = $this->analytics->getFastMovingProducts($this->branch->id);
    $otherFastMoving = $this->analytics->getFastMovingProducts($otherBranch->id);

    expect($fastMoving->pluck('product_id'))->toContain($branchProduct->id)
        ->and($fastMoving->pluck('product_id'))->not->toContain($otherProduct->id)
        ->and($otherFastMoving->pluck('product_id'))->toContain($otherProduct->id)
        ->and($otherFastMoving->pluck('product_id'))->not->toContain($branchProduct->id);
});

it('excludes inactive products from analytics when consistent with inventory behavior', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $activeProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $inactiveProduct = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);

    createMovementWithDate($this->branch, $activeProduct, $location, now()->subDays(5)->toDateString(), qtyIn: 10);
    createMovementWithDate($this->branch, $activeProduct, $location, now()->subDays(3)->toDateString(), qtyOut: 3);
    createMovementWithDate($this->branch, $inactiveProduct, $location, now()->subDays(5)->toDateString(), qtyIn: 10);
    createMovementWithDate($this->branch, $inactiveProduct, $location, now()->subDays(3)->toDateString(), qtyOut: 8);

    $results = $this->analytics->getFastMovingProducts($this->branch->id);

    expect($results->pluck('product_id'))->toContain($activeProduct->id)
        ->and($results->pluck('product_id'))->not->toContain($inactiveProduct->id);
});

it('does not introduce mutable stock columns on products', function () {
    $columns = Schema::getColumnListing('inv_products');

    expect($columns)->not->toContain('current_stock')
        ->and($columns)->not->toContain('stock')
        ->and($columns)->not->toContain('qty_on_hand')
        ->and($columns)->not->toContain('available_stock');
});

it('builds analytics summary from ledger derived metrics', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'average_cost' => 100,
    ]);

    createMovementWithDate($this->branch, $product, $location, now()->subDays(120)->toDateString(), qtyIn: 10);
    createMovementWithDate($this->branch, $product, $location, now()->subDays(5)->toDateString(), qtyOut: 2);

    $summary = $this->analytics->getAnalyticsSummary($this->branch->id);

    expect($summary)->toHaveKeys([
        'fast_moving_count',
        'slow_moving_count',
        'dead_stock_count',
        'inventory_value',
        'period_from',
        'period_to',
    ])
        ->and($summary['inventory_value'])->toBe(800.0)
        ->and($summary['fast_moving_count'])->toBeGreaterThanOrEqual(1);
});

it('resolves inventory analytics service from the container', function () {
    expect(app(InventoryAnalyticsService::class))->toBeInstanceOf(InventoryAnalyticsService::class);
});

it('delegates getKpiSummary values to the analytics repository', function () {
    $branch = Branch::factory()->create(['code' => 'KPI-'.uniqid(), 'name' => 'KPI Test Branch']);
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'average_cost' => 100,
        'is_active' => true,
    ]);

    createMovementWithDate($branch, $product, $location, now()->subDays(5)->toDateString(), qtyIn: 8);

    $approvedPo = PurchaseOrder::factory()->approved()->create(['branch_id' => $branch->id]);
    PurchaseOrder::factory()->sent()->create(['branch_id' => $branch->id]);
    PurchaseRequest::factory()->approved()->create(['branch_id' => $branch->id]);
    GoodsReceipt::factory()->draft()->forPurchaseOrder($approvedPo)->create(['branch_id' => $branch->id]);

    $summary = $this->analytics->getKpiSummary($branch->id);

    expect($summary)->toHaveKeys([
        'inventory_value',
        'active_sku',
        'low_stock_count',
        'dead_stock_count',
        'open_pr',
        'open_po',
        'pending_gr',
        'in_transit_transfer',
        'inventory_accuracy',
    ])
        ->and($summary['inventory_value'])->toBe(800.0)
        ->and($summary['active_sku'])->toBe(1)
        ->and($summary['open_po'])->toBe(2)
        ->and($summary['open_pr'])->toBe(1)
        ->and($summary['pending_gr'])->toBe(1)
        ->and($summary['inventory_accuracy'])->toBeNull();
});

it('returns operational valuation note from getStockValuation', function () {
    $valuation = $this->analytics->getStockValuation($this->branch->id);

    expect($valuation)->toHaveKeys(['total_value', 'valuation_type', 'valuation_note', 'generated_at'])
        ->and($valuation['valuation_type'])->toBe('operational')
        ->and($valuation['valuation_note'])->toContain('Operational inventory value')
        ->and($valuation['valuation_note'])->toContain('Not accounting valuation');
});

it('delegates getFastMovingItems to the analytics repository', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $fastProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 50]);
    $slowProduct = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 50]);

    createMovementWithDate($this->branch, $fastProduct, $location, now()->subDays(20)->toDateString(), qtyIn: 50);
    createMovementWithDate($this->branch, $slowProduct, $location, now()->subDays(20)->toDateString(), qtyIn: 50);
    createMovementWithDate($this->branch, $fastProduct, $location, now()->subDays(5)->toDateString(), qtyOut: 20);
    createMovementWithDate($this->branch, $slowProduct, $location, now()->subDays(5)->toDateString(), qtyOut: 2);

    $items = $this->analytics->getFastMovingItems($this->branch->id, days: 90, limit: 5);

    expect($items)->toHaveCount(2)
        ->and($items->first()['product_id'])->toBe($fastProduct->id)
        ->and($items->first()['outbound_qty_period'])->toBe(20.0);
});

it('delegates getSupplierPerformance to the analytics repository', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

    PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'expected_delivery_date' => now()->addDays(5)->toDateString(),
    ]);

    $performance = $this->analytics->getSupplierPerformance($this->branch->id);
    $row = $performance->firstWhere('supplier_id', $supplier->id);

    expect($row)->not->toBeNull()
        ->and($row)->toHaveKeys([
            'supplier_id',
            'supplier_name',
            'order_count',
            'coverage_percentage',
        ]);
});

it('delegates getReorderRecommendations to the analytics repository', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $lowProduct = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'reorder_point' => 20,
        'minimum_stock' => 10,
        'alert_enabled' => true,
        'is_active' => true,
    ]);

    createMovementWithDate($this->branch, $lowProduct, $location, now()->subDays(3)->toDateString(), qtyIn: 12);

    $recommendations = $this->analytics->getReorderRecommendations($this->branch->id);

    expect($recommendations->pluck('product_id')->all())->toBe([$lowProduct->id]);
});

it('preserves sprint 15.5 backward compatible analytics methods', function () {
    $service = app(InventoryAnalyticsService::class);

    expect(method_exists($service, 'getFastMovingProducts'))->toBeTrue()
        ->and(method_exists($service, 'getSlowMovingProducts'))->toBeTrue()
        ->and(method_exists($service, 'getDeadStockProducts'))->toBeTrue()
        ->and(method_exists($service, 'getInventoryAging'))->toBeTrue()
        ->and(method_exists($service, 'getStockAgingAnalysis'))->toBeTrue()
        ->and(method_exists($service, 'getInventoryTurnover'))->toBeTrue()
        ->and(method_exists($service, 'getInventoryValueByCategory'))->toBeTrue()
        ->and(method_exists($service, 'getInventoryValueByLocation'))->toBeTrue()
        ->and(method_exists($service, 'getMonthlyOutboundValueTrend'))->toBeTrue()
        ->and(method_exists($service, 'getAnalyticsSummary'))->toBeTrue();
});

it('does not require controller or ui to expose analytics service methods', function () {
    expect(class_exists(InventoryAnalyticsController::class))->toBeTrue();

    $reflection = new ReflectionClass(InventoryAnalyticsService::class);

    expect($reflection->getNamespaceName())->toBe('App\\Modules\\Inventory\\Services');
});

it('forwards mocked repository values in getKpiSummary without recalculating scalars', function () {
    $mockRepository = Mockery::mock(InventoryAnalyticsRepositoryInterface::class, function (MockInterface $mock) {
        $mock->shouldReceive('getInventoryValue')->once()->with(99)->andReturn(12345.67);
        $mock->shouldReceive('getActiveSkuCount')->once()->with(99)->andReturn(7);
        $mock->shouldReceive('getLowStockCount')->once()->with(99)->andReturn(3);
        $mock->shouldReceive('getDeadStockCount')->once()->with(99)->andReturn(2);
        $mock->shouldReceive('getOpenPurchaseRequestCount')->once()->with(99)->andReturn(4);
        $mock->shouldReceive('getOpenPurchaseOrderCount')->once()->with(99)->andReturn(5);
        $mock->shouldReceive('getPendingGoodsReceiptCount')->once()->with(99)->andReturn(1);
        $mock->shouldReceive('getInTransitTransferCount')->once()->with(99)->andReturn(0);
        $mock->shouldReceive('getInventoryAccuracy')->once()->with(99)->andReturn(null);
    });

    $service = new InventoryAnalyticsService(
        app(InventoryMovementRepositoryInterface::class),
        app(InventoryBatchRepositoryInterface::class),
        $mockRepository,
    );

    expect($service->getKpiSummary(99))->toBe([
        'inventory_value' => 12345.67,
        'active_sku' => 7,
        'low_stock_count' => 3,
        'dead_stock_count' => 2,
        'open_pr' => 4,
        'open_po' => 5,
        'pending_gr' => 1,
        'in_transit_transfer' => 0,
        'inventory_accuracy' => null,
    ]);
});

it('delegates getFastMovingItems through mocked repository contract', function () {
    $expected = collect([
        ['product_id' => 1, 'product_code' => 'SKU-1', 'product_name' => 'Resin A', 'current_stock' => 10.0, 'outbound_qty_period' => 5.0, 'outbound_value_period' => 50.0, 'stock_value' => 100.0],
    ]);

    $mockRepository = Mockery::mock(InventoryAnalyticsRepositoryInterface::class, function (MockInterface $mock) use ($expected) {
        $mock->shouldReceive('getFastMovingItems')->once()->with(42, 30, 3)->andReturn($expected);
    });

    $service = new InventoryAnalyticsService(
        app(InventoryMovementRepositoryInterface::class),
        app(InventoryBatchRepositoryInterface::class),
        $mockRepository,
    );

    expect($service->getFastMovingItems(42, days: 30, limit: 3))->toBe($expected);
});
