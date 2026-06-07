<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branchA = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branchB = Branch::factory()->create(['code' => 'TST-RFR', 'name' => 'Refresh Test Branch B']);
    $this->service = app(InventoryAnalyticsSummaryRefreshService::class);
    $this->today = now()->toDateString();
});

function refreshMovement(
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

it('refreshDailySummaries creates rpt_inventory_daily_summaries row', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create(['branch_id' => $this->branchA->id, 'average_cost' => 10]);

    refreshMovement($this->branchA, $product, $location, qtyIn: 5, qtyOut: 2);

    $this->service->refreshDailySummaries($this->branchA->id, $this->today);

    $row = DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->today)
        ->first();

    expect($row)->not->toBeNull()
        ->and((float) $row->quantity_in_total)->toBe(5.0)
        ->and((float) $row->quantity_out_total)->toBe(2.0)
        ->and((int) $row->movement_count)->toBe(1)
        ->and($row->refreshed_at)->not->toBeNull();
});

it('refreshDailySummaries is idempotent and does not duplicate rows', function () {
    $this->service->refreshDailySummaries($this->branchA->id, $this->today);
    $this->service->refreshDailySummaries($this->branchA->id, $this->today);

    $count = DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->today)
        ->count();

    expect($count)->toBe(1);
});

it('refreshBranchSummaries creates rpt_inventory_branch_summaries row', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 100,
        'is_active' => true,
    ]);

    refreshMovement($this->branchA, $product, $location, qtyIn: 10);

    $this->service->refreshBranchSummaries($this->branchA->id, $this->today);

    $row = DB::table('rpt_inventory_branch_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('snapshot_date', $this->today)
        ->first();

    expect($row)->not->toBeNull()
        ->and((float) $row->inventory_value)->toBe(1000.0)
        ->and((int) $row->active_sku_count)->toBe(1)
        ->and($row->refreshed_at)->not->toBeNull();
});

it('refreshProductSummaries creates rpt_inventory_product_summaries row', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'average_cost' => 50,
        'is_active' => true,
        'alert_enabled' => true,
        'reorder_point' => 20,
    ]);

    refreshMovement($this->branchA, $product, $location, qtyIn: 15, qtyOut: 3);

    $this->service->refreshProductSummaries($this->branchA->id, $this->today);

    $row = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('product_id', $product->id)
        ->where('snapshot_date', $this->today)
        ->first();

    expect($row)->not->toBeNull()
        ->and((float) $row->current_stock)->toBe(12.0)
        ->and((float) $row->stock_value)->toBe(600.0)
        ->and($row->refreshed_at)->not->toBeNull();
});

it('computes product current_stock from ledger quantity_in minus quantity_out', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create(['branch_id' => $this->branchA->id]);

    refreshMovement($this->branchA, $product, $location, qtyIn: 40);
    refreshMovement($this->branchA, $product, $location, qtyOut: 7);

    $this->service->refreshProductSummaries($this->branchA->id, $this->today);

    $row = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('product_id', $product->id)
        ->where('snapshot_date', $this->today)
        ->first();

    expect((float) $row->current_stock)->toBe(33.0);
});

it('sets low stock flag based on existing reorder point field', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $lowStockProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'alert_enabled' => true,
        'reorder_point' => 10,
        'minimum_stock' => 0,
    ]);
    $healthyProduct = Product::factory()->create([
        'branch_id' => $this->branchA->id,
        'alert_enabled' => true,
        'reorder_point' => 5,
        'minimum_stock' => 0,
    ]);

    refreshMovement($this->branchA, $lowStockProduct, $location, qtyIn: 8);
    refreshMovement($this->branchA, $healthyProduct, $location, qtyIn: 20);

    $this->service->refreshProductSummaries($this->branchA->id, $this->today);

    $lowRow = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('product_id', $lowStockProduct->id)
        ->where('snapshot_date', $this->today)
        ->first();

    $healthyRow = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('product_id', $healthyProduct->id)
        ->where('snapshot_date', $this->today)
        ->first();

    expect((bool) $lowRow->is_low_stock)->toBeTrue()
        ->and((bool) $healthyRow->is_low_stock)->toBeFalse();
});

it('refreshProcurementDailySummaries creates branch rollup row', function () {
    PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branchA->id,
        'order_date' => $this->today,
    ]);

    $this->service->refreshProcurementDailySummaries($this->branchA->id, $this->today);

    $row = DB::table('rpt_procurement_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->today)
        ->whereNull('supplier_id')
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->po_created_count)->toBe(1)
        ->and($row->refreshed_at)->not->toBeNull();
});

it('isolates branch summaries so branch A does not mix branch B movements', function () {
    $locationA = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $locationB = InventoryLocation::factory()->create(['branch_id' => $this->branchB->id]);
    $productA = Product::factory()->create(['branch_id' => $this->branchA->id, 'average_cost' => 10]);
    $productB = Product::factory()->create(['branch_id' => $this->branchB->id, 'average_cost' => 20]);

    refreshMovement($this->branchA, $productA, $locationA, qtyIn: 5);
    refreshMovement($this->branchB, $productB, $locationB, qtyIn: 9);

    $this->service->refreshDailySummaries(null, $this->today);

    $rowA = DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->today)
        ->first();

    $rowB = DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchB->id)
        ->where('summary_date', $this->today)
        ->first();

    expect((float) $rowA->quantity_in_total)->toBe(5.0)
        ->and((float) $rowB->quantity_in_total)->toBe(9.0);
});

it('does not error when ledger and procurement data are empty', function () {
    $this->service->refreshAll($this->branchA->id, $this->today);

    expect(DB::table('rpt_inventory_daily_summaries')->where('branch_id', $this->branchA->id)->count())->toBe(1)
        ->and(DB::table('rpt_inventory_branch_summaries')->where('branch_id', $this->branchA->id)->count())->toBe(1)
        ->and(DB::table('rpt_procurement_daily_summaries')->where('branch_id', $this->branchA->id)->whereNull('supplier_id')->count())->toBe(1);
});
