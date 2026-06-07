<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\Supplier;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branchA = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branchB = Branch::factory()->create(['code' => 'TST-B', 'name' => 'Test Branch B']);
    $this->repository = app(InventoryAnalyticsRepositoryInterface::class);
});

function analyticsRepoMovement(
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

it('calculates inventory value from derived stock multiplied by average cost', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 100,
        'is_active' => true,
    ]);

    analyticsRepoMovement($this->branchA, $product, $location, qtyIn: 25);

    expect($this->repository->getInventoryValue($this->branchA->id))->toBe(2500.0);
});

it('counts active skus only when derived stock is greater than zero', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $inStock = Product::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);
    $zeroStock = Product::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);

    analyticsRepoMovement($this->branchA, $inStock, $location, qtyIn: 10);

    expect($this->repository->getActiveSkuCount($this->branchA->id))->toBe(1);
});

it('includes sent purchase orders in open purchase order count', function () {
    PurchaseOrder::factory()->approved()->create(['branch_id' => $this->branchA->id]);
    PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branchA->id]);
    PurchaseOrder::factory()->create([
        'branch_id' => $this->branchA->id,
        'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
    ]);

    expect($this->repository->getOpenPurchaseOrderCount($this->branchA->id))->toBe(2);
});

it('counts pending goods receipts with draft and submitted statuses', function () {
    GoodsReceipt::factory()->draft()->create(['branch_id' => $this->branchA->id]);
    GoodsReceipt::factory()->submitted()->create(['branch_id' => $this->branchA->id]);
    GoodsReceipt::factory()->posted()->create(['branch_id' => $this->branchA->id]);

    expect($this->repository->getPendingGoodsReceiptCount($this->branchA->id))->toBe(2);
});

it('returns null inventory accuracy when no completed stock opname exists', function () {
    StockOpname::factory()->counting()->create(['branch_id' => $this->branchA->id]);

    expect($this->repository->getInventoryAccuracy($this->branchA->id))->toBeNull();
});

it('computes inventory accuracy from completed stock opname variance', function () {
    $product = Product::factory()->create(['branch_id' => $this->branchA->id]);
    $opname = StockOpname::factory()->completed()->create(['branch_id' => $this->branchA->id]);

    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => $product->id,
        'system_quantity' => 100,
        'counted_quantity' => 95,
        'variance_quantity' => -5,
    ]);
    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branchA->id])->id,
        'system_quantity' => 50,
        'counted_quantity' => 48,
        'variance_quantity' => -2,
    ]);

    expect($this->repository->getInventoryAccuracy($this->branchA->id))->toBe(95.33);
});

it('includes quantity_out in consumption trend monthly series', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 50,
    ]);

    $month = now()->startOfMonth()->toDateString();

    analyticsRepoMovement($this->branchA, $product, $location, qtyIn: 100, extra: [
        'movement_date' => $month,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
    ]);
    analyticsRepoMovement($this->branchA, $product, $location, qtyOut: 12, extra: [
        'movement_date' => $month,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'unit_cost' => 50,
    ]);
    analyticsRepoMovement($this->branchA, $product, $location, qtyOut: 3, extra: [
        'movement_date' => $month,
        'movement_type' => InventoryMovement::TYPE_TRANSFER_OUT,
        'unit_cost' => 50,
    ]);

    $trend = $this->repository->getConsumptionTrend($this->branchA->id);
    $currentMonth = collect($trend)->firstWhere('period', now()->format('Y-m'));

    expect($currentMonth)->not->toBeNull()
        ->and($currentMonth['outbound_qty'])->toBe(15.0)
        ->and($currentMonth['outbound_value'])->toBe(750.0);
});

it('includes coverage percentage in supplier performance rows', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branchA->id, 'is_active' => true]);

    $datedPo = PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $supplier->id,
        'expected_delivery_date' => now()->addDays(7)->toDateString(),
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $datedPo->id,
        'quantity_ordered' => 10,
        'unit_price' => 1000,
    ]);

    $undatedPo = PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->branchA->id,
        'supplier_id' => $supplier->id,
        'expected_delivery_date' => null,
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $undatedPo->id,
        'quantity_ordered' => 5,
        'unit_price' => 2000,
    ]);

    $performance = $this->repository->getSupplierPerformance($this->branchA->id);
    $row = $performance->firstWhere('supplier_id', $supplier->id);

    expect($row)->not->toBeNull()
        ->and($row['order_count'])->toBe(2)
        ->and($row['coverage_percentage'])->toBe(50.0);
});

it('returns reorder recommendations for products at or below reorder point', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $lowProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'reorder_point' => 20,
        'minimum_stock' => 10,
        'alert_enabled' => true,
        'is_active' => true,
    ]);
    $healthyProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'reorder_point' => 5,
        'minimum_stock' => 2,
        'alert_enabled' => true,
        'is_active' => true,
    ]);

    analyticsRepoMovement($this->branchA, $lowProduct, $location, qtyIn: 15);
    analyticsRepoMovement($this->branchA, $healthyProduct, $location, qtyIn: 20);

    $recommendations = $this->repository->getReorderRecommendations($this->branchA->id);

    expect($recommendations->pluck('product_id')->all())->toBe([$lowProduct->id])
        ->and($recommendations->first()['current_stock'])->toBe(15.0)
        ->and($recommendations->first()['severity'])->toBeIn(['critical', 'high', 'medium', 'low']);
});

it('isolates analytics data by branch', function () {
    $locationA = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $locationB = InventoryLocation::factory()->create(['branch_id' => $this->branchB->id]);
    $productA = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 200,
        'is_active' => true,
    ]);
    $productB = Product::factory()->create([
        'branch_id' => $this->branchB->id,
        'average_cost' => 500,
        'is_active' => true,
    ]);

    analyticsRepoMovement($this->branchA, $productA, $locationA, qtyIn: 10);
    analyticsRepoMovement($this->branchB, $productB, $locationB, qtyIn: 4);

    PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branchB->id]);
    PurchaseRequest::factory()->approved()->create(['branch_id' => $this->branchB->id]);

    expect($this->repository->getInventoryValue($this->branchA->id))->toBe(2000.0)
        ->and($this->repository->getInventoryValue($this->branchB->id))->toBe(2000.0)
        ->and($this->repository->getActiveSkuCount($this->branchA->id))->toBe(1)
        ->and($this->repository->getActiveSkuCount($this->branchB->id))->toBe(1)
        ->and($this->repository->getOpenPurchaseOrderCount($this->branchA->id))->toBe(0)
        ->and($this->repository->getOpenPurchaseOrderCount($this->branchB->id))->toBe(1)
        ->and($this->repository->getOpenPurchaseRequestCount($this->branchA->id))->toBe(0)
        ->and($this->repository->getOpenPurchaseRequestCount($this->branchB->id))->toBe(1);
});
