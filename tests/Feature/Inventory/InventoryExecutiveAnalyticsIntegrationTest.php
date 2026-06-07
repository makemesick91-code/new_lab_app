<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\DTOs\InventoryExecutiveSnapshot;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Repositories\InventoryAnalyticsRepository;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryExecutiveDashboardService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branchA = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branchB = Branch::factory()->create(['code' => 'INT-B', 'name' => 'Integration Branch B']);
    $this->analytics = app(InventoryAnalyticsService::class);
    $this->dashboard = app(InventoryExecutiveDashboardService::class);
});

function integrationMovement(
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

function buildRealisticBranchADataset(Branch $branch): array
{
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang Utama']);
    $supplier = Supplier::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'name' => 'PT Resin Dental']);

    $activeResin = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'RES-A3-'.uniqid(),
        'name' => 'Resin A3 Shade',
        'average_cost' => 150_000,
        'reorder_point' => 20,
        'minimum_stock' => 10,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $activeZirconia = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'ZIR-PREM-'.uniqid(),
        'name' => 'Zirconia Premium',
        'average_cost' => 500_000,
        'reorder_point' => 5,
        'minimum_stock' => 2,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $deadWax = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'WAX-OLD-'.uniqid(),
        'name' => 'Wax Legacy',
        'average_cost' => 25_000,
        'reorder_point' => 5,
        'minimum_stock' => 1,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $zeroStockSku = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'SKU-ZERO-'.uniqid(),
        'name' => 'Consumable Zero',
        'average_cost' => 10_000,
        'is_active' => true,
    ]);

    integrationMovement($branch, $activeResin, $location, qtyIn: 30);
    integrationMovement($branch, $activeZirconia, $location, qtyIn: 8);
    integrationMovement($branch, $activeZirconia, $location, qtyOut: 1, extra: [
        'movement_date' => now()->subDays(5)->toDateString(),
    ]);
    integrationMovement($branch, $deadWax, $location, qtyIn: 12, extra: [
        'movement_date' => now()->subDays(120)->toDateString(),
    ]);
    integrationMovement($branch, $activeResin, $location, qtyOut: 18, extra: [
        'movement_date' => now()->subDays(3)->toDateString(),
    ]);

    PurchaseRequest::factory()->submitted()->create(['branch_id' => $branch->id]);
    PurchaseRequest::factory()->approved()->create(['branch_id' => $branch->id]);
    PurchaseRequest::factory()->create(['branch_id' => $branch->id, 'status' => PurchaseRequest::STATUS_DRAFT]);

    $approvedPo = PurchaseOrder::factory()->approved()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'expected_delivery_date' => now()->addDays(10)->toDateString(),
    ]);
    PurchaseOrder::factory()->sent()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'expected_delivery_date' => null,
    ]);
    PurchaseOrder::factory()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ]);
    PurchaseOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => PurchaseOrder::STATUS_DRAFT,
    ]);
    PurchaseOrder::factory()->submitted()->create(['branch_id' => $branch->id]);
    PurchaseOrder::factory()->cancelled()->create(['branch_id' => $branch->id]);
    PurchaseOrder::factory()->create([
        'branch_id' => $branch->id,
        'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
    ]);

    $closedPo = PurchaseOrder::factory()->create([
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
    ]);

    GoodsReceipt::factory()->draft()->forPurchaseOrder($approvedPo)->create(['branch_id' => $branch->id]);
    GoodsReceipt::factory()->submitted()->forPurchaseOrder($closedPo)->create(['branch_id' => $branch->id]);
    GoodsReceipt::factory()->posted()->forPurchaseOrder($closedPo)->create(['branch_id' => $branch->id]);

    StockTransfer::factory()->inTransit()->create(['branch_id' => $branch->id]);
    StockTransfer::factory()->received()->create(['branch_id' => $branch->id]);

    return compact(
        'location',
        'supplier',
        'activeResin',
        'activeZirconia',
        'deadWax',
        'zeroStockSku',
    );
}

it('validates all executive KPIs from a realistic integrated dataset', function () {
    $data = buildRealisticBranchADataset($this->branchA);

    $kpi = $this->analytics->getKpiSummary($this->branchA->id);

    expect($kpi)->toHaveKeys([
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
        ->and($kpi['inventory_value'])->toBe(5_600_000.0)
        ->and($kpi['active_sku'])->toBe(3)
        ->and($kpi['low_stock_count'])->toBeGreaterThanOrEqual(1)
        ->and($kpi['dead_stock_count'])->toBe(1)
        ->and($kpi['open_pr'])->toBe(2)
        ->and($kpi['open_po'])->toBe(3)
        ->and($kpi['pending_gr'])->toBe(2)
        ->and($kpi['in_transit_transfer'])->toBe(1)
        ->and($kpi['inventory_accuracy'])->toBeNull();

    $snapshot = InventoryExecutiveSnapshot::fromArray($kpi);

    expect($snapshot->inventoryValue)->toBe(5_600_000.0)
        ->and($snapshot->activeSku)->toBe(3)
        ->and($snapshot->deadStockCount)->toBe(1)
        ->and($snapshot->openPo)->toBe(3)
        ->and($snapshot->inventoryAccuracy)->toBeNull();
});

it('governs inventory value as current stock multiplied by average cost not movement costing', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);

    $product = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 100,
        'is_active' => true,
    ]);

    integrationMovement($this->branchA, $product, $location, qtyIn: 10, extra: [
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'unit_cost' => 250,
    ]);
    integrationMovement($this->branchA, $product, $location, qtyIn: 5, extra: [
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'unit_cost' => 50,
    ]);

    $derivedStock = 15.0;
    $expectedOperationalValue = $derivedStock * 100;

    $fifoValue = (10 * 250) + (5 * 50);
    $lifoValue = (5 * 50) + (10 * 250);
    $weightedAverageValue = $derivedStock * ((10 * 250 + 5 * 50) / 15);

    $actualValue = $this->analytics->getKpiSummary($this->branchA->id)['inventory_value'];

    expect($actualValue)->toBe($expectedOperationalValue)
        ->and($actualValue)->not->toBe((float) $fifoValue)
        ->and($actualValue)->not->toBe((float) $lifoValue)
        ->and($actualValue)->not->toBe((float) $weightedAverageValue);

    $valuation = $this->analytics->getStockValuation($this->branchA->id);

    expect($valuation['valuation_type'])->toBe('operational')
        ->and($valuation['total_value'])->toBe(1500.0)
        ->and($valuation['valuation_note'])->toContain('average cost')
        ->and($valuation['valuation_note'])->toContain('Not accounting valuation');
});

it('governs open purchase order count with locked status inclusion rules', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branchA->id]);

    PurchaseOrder::factory()->approved()->create(['branch_id' => $this->branchA->id, 'supplier_id' => $supplier->id]);
    PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branchA->id, 'supplier_id' => $supplier->id]);
    PurchaseOrder::factory()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $supplier->id,
        'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ]);

    PurchaseOrder::factory()->create(['branch_id' => $this->branchA->id, 'status' => PurchaseOrder::STATUS_DRAFT]);
    PurchaseOrder::factory()->submitted()->create(['branch_id' => $this->branchA->id]);
    PurchaseOrder::factory()->cancelled()->create(['branch_id' => $this->branchA->id]);
    PurchaseOrder::factory()->create([
        'branch_id' => $this->branchA->id,
        'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
    ]);

    expect($this->analytics->getKpiSummary($this->branchA->id)['open_po'])->toBe(3);
});

it('governs inventory accuracy as null without completed opname and formula when completed', function () {
    StockOpname::factory()->counting()->create(['branch_id' => $this->branchA->id]);

    expect($this->analytics->getKpiSummary($this->branchA->id)['inventory_accuracy'])->toBeNull();

    $product = Product::factory()->create(['branch_id' => $this->branchA->id]);
    $opname = StockOpname::factory()->completed()->create(['branch_id' => $this->branchA->id]);

    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => $product->id,
        'system_quantity' => 80,
        'counted_quantity' => 76,
        'variance_quantity' => -4,
    ]);
    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branchA->id])->id,
        'system_quantity' => 20,
        'counted_quantity' => 19,
        'variance_quantity' => -1,
    ]);

    expect($this->analytics->getKpiSummary($this->branchA->id)['inventory_accuracy'])->toBe(95.0);
});

it('returns null inventory accuracy when completed opname has zero total system quantity', function () {
    $opname = StockOpname::factory()->completed()->create(['branch_id' => $this->branchA->id]);

    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branchA->id])->id,
        'system_quantity' => 0,
        'counted_quantity' => 0,
        'variance_quantity' => 0,
    ]);

    expect($this->analytics->getKpiSummary($this->branchA->id)['inventory_accuracy'])->toBeNull();
});

it('governs supplier performance coverage and excludes undated POs from on-time denominator', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);

    $datedOnTimePo = PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $supplier->id,
        'expected_delivery_date' => now()->addDays(7)->toDateString(),
    ]);
    GoodsReceipt::factory()->posted()->forPurchaseOrder($datedOnTimePo)->create([
        'branch_id' => $this->branchA->id,
        'receipt_date' => now()->toDateString(),
    ]);

    PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $supplier->id,
        'expected_delivery_date' => null,
    ]);

    $row = $this->analytics->getSupplierPerformance($this->branchA->id)
        ->firstWhere('supplier_id', $supplier->id);

    expect($row)->not->toBeNull()
        ->and($row['order_count'])->toBe(2)
        ->and($row['coverage_percentage'])->toBe(50.0)
        ->and($row['on_time_delivery_rate'])->toBe(100.0);

    $undatedOnlySupplier = Supplier::factory()->create([
        'branch_id' => $this->branchA->id,
        'is_active' => true,
        'name' => 'Undated Vendor',
    ]);
    PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $undatedOnlySupplier->id,
        'expected_delivery_date' => null,
    ]);

    $undatedRow = $this->analytics->getSupplierPerformance($this->branchA->id)
        ->firstWhere('supplier_id', $undatedOnlySupplier->id);

    expect($undatedRow['coverage_percentage'])->toBe(0.0)
        ->and($undatedRow['on_time_delivery_rate'])->toBeNull();
});

it('calculates reorder recommendation severity for critical high medium and low cases', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);

    $criticalProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'code' => 'REO-CRIT-'.uniqid(),
        'reorder_point' => 30,
        'minimum_stock' => 15,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $highProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'code' => 'REO-HIGH-'.uniqid(),
        'reorder_point' => 40,
        'minimum_stock' => 5,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $mediumProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'code' => 'REO-MED-'.uniqid(),
        'reorder_point' => 25,
        'minimum_stock' => 5,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    integrationMovement($this->branchA, $criticalProduct, $location, qtyIn: 10);
    integrationMovement($this->branchA, $highProduct, $location, qtyIn: 42);
    integrationMovement($this->branchA, $mediumProduct, $location, qtyIn: 20);

    foreach (range(1, 30) as $dayOffset) {
        integrationMovement($this->branchA, $highProduct, $location, qtyOut: 1, extra: [
            'movement_date' => now()->subDays($dayOffset)->toDateString(),
        ]);
    }

    $recommendations = $this->analytics->getReorderRecommendations($this->branchA->id)
        ->keyBy('product_id');

    expect($recommendations->get($criticalProduct->id)['severity'])->toBe('critical')
        ->and($recommendations->get($highProduct->id)['severity'])->toBe('high')
        ->and($recommendations->get($mediumProduct->id)['severity'])->toBe('medium');

    $resolveSeverity = new ReflectionMethod(InventoryAnalyticsRepository::class, 'resolveReorderSeverity');
    $resolveSeverity->setAccessible(true);
    $repository = app(InventoryAnalyticsRepository::class);

    expect($resolveSeverity->invoke($repository, 25.0, 0.0, 20.0, null))->toBe('low');
});

it('isolates all executive KPIs between branches', function () {
    $locationA = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $locationB = InventoryLocation::factory()->create(['branch_id' => $this->branchB->id]);

    $productA = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 100,
        'reorder_point' => 5,
        'minimum_stock' => 2,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $productB = Product::factory()->create([
        'branch_id' => $this->branchB->id,
        'average_cost' => 300,
        'reorder_point' => 5,
        'minimum_stock' => 2,
        'alert_enabled' => true,
        'is_active' => true,
    ]);

    integrationMovement($this->branchA, $productA, $locationA, qtyIn: 10);
    integrationMovement($this->branchB, $productB, $locationB, qtyIn: 20);

    integrationMovement($this->branchB, $productB, $locationB, qtyIn: 8, extra: [
        'movement_date' => now()->subDays(120)->toDateString(),
    ]);

    PurchaseRequest::factory()->approved()->create(['branch_id' => $this->branchB->id]);
    $branchBClosedPo = PurchaseOrder::factory()->create([
        'branch_id' => $this->branchB->id,
        'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
    ]);
    PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branchB->id]);
    PurchaseOrder::factory()->approved()->create(['branch_id' => $this->branchA->id]);
    GoodsReceipt::factory()->draft()->forPurchaseOrder($branchBClosedPo)->create(['branch_id' => $this->branchB->id]);
    StockTransfer::factory()->inTransit()->create(['branch_id' => $this->branchB->id]);

    $kpiA = $this->analytics->getKpiSummary($this->branchA->id);
    $kpiB = $this->analytics->getKpiSummary($this->branchB->id);

    expect($kpiA['inventory_value'])->toBe(1000.0)
        ->and($kpiB['inventory_value'])->toBe(8400.0)
        ->and($kpiA['active_sku'])->toBe(1)
        ->and($kpiB['active_sku'])->toBe(1)
        ->and($kpiA['open_pr'])->toBe(0)
        ->and($kpiB['open_pr'])->toBe(1)
        ->and($kpiA['open_po'])->toBe(1)
        ->and($kpiB['open_po'])->toBe(1)
        ->and($kpiA['pending_gr'])->toBe(0)
        ->and($kpiB['pending_gr'])->toBe(1)
        ->and($kpiA['in_transit_transfer'])->toBe(0)
        ->and($kpiB['in_transit_transfer'])->toBe(1);

    $dashboardA = $this->dashboard->getExecutiveDashboard($this->branchA->id);
    $dashboardB = $this->dashboard->getExecutiveDashboard($this->branchB->id);

    expect($dashboardA['snapshot']->inventoryValue)->toBe(1000.0)
        ->and($dashboardB['snapshot']->inventoryValue)->toBe(8400.0)
        ->and($dashboardA['meta']['branch_id'])->toBe($this->branchA->id)
        ->and($dashboardB['meta']['branch_id'])->toBe($this->branchB->id);
});

it('produces complete executive dashboard payload with snapshot cards sections and meta', function () {
    buildRealisticBranchADataset($this->branchA);

    $payload = $this->dashboard->getExecutiveDashboard($this->branchA->id);

    expect($payload)->toHaveKeys(['snapshot', 'cards', 'sections', 'meta'])
        ->and($payload['snapshot'])->toBeInstanceOf(InventoryExecutiveSnapshot::class)
        ->and($payload['cards'])->toHaveCount(9)
        ->and(collect($payload['cards'])->pluck('key')->all())->toContain(
            'inventory_value',
            'active_sku',
            'low_stock_count',
            'dead_stock_count',
            'open_pr',
            'open_po',
            'pending_gr',
            'in_transit_transfer',
            'inventory_accuracy',
        )
        ->and($payload['sections'])->toHaveKeys(['trends', 'movement', 'valuation', 'supplier', 'reorder'])
        ->and($payload['sections']['trends'])->toHaveKeys(['purchase_trend', 'consumption_trend'])
        ->and($payload['sections']['movement'])->toHaveKeys(['fast_moving', 'slow_moving', 'dead_stock'])
        ->and($payload['sections']['valuation'])->toHaveKeys(['stock_aging'])
        ->and($payload['sections']['supplier'])->toHaveKeys(['supplier_performance'])
        ->and($payload['sections']['reorder'])->toHaveKeys(['reorder_recommendations'])
        ->and($payload['meta'])->toHaveKeys([
            'branch_id',
            'generated_at',
            'valuation_note',
            'accuracy_note',
            'consumption_note',
        ])
        ->and($payload['meta']['branch_id'])->toBe($this->branchA->id)
        ->and($payload['snapshot']->openPo)->toBe(3)
        ->and($payload['snapshot']->inventoryAccuracy)->toBeNull();
});
