<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branchA = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branchB = Branch::factory()->create(['code' => 'INC-B', 'name' => 'Incremental Branch B']);
    $this->service = app(InventoryAnalyticsSummaryRefreshService::class);
    $this->dateA = '2026-06-07';
    $this->dateB = '2026-06-08';
});

function incrementalMovement(
    Branch $branch,
    Product $product,
    InventoryLocation $location,
    string $movementDate,
    float $qtyIn = 0,
    float $qtyOut = 0,
): InventoryMovement {
    return InventoryMovement::factory()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => $qtyOut > 0 ? InventoryMovement::TYPE_ADJUSTMENT_OUT : InventoryMovement::TYPE_PURCHASE,
        'movement_date' => $movementDate,
        'quantity_in' => $qtyIn,
        'quantity_out' => $qtyOut,
    ]);
}

it('preserves earlier snapshot when refreshing only a later date', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create(['branch_id' => $this->branchA->id, 'average_cost' => 100]);

    incrementalMovement($this->branchA, $product, $location, $this->dateA, qtyIn: 10);

    $this->service->refreshDailySummaries($this->branchA->id, $this->dateA);

    $rowBefore = DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->dateA)
        ->first();

    incrementalMovement($this->branchA, $product, $location, $this->dateB, qtyIn: 5);

    $this->service->refreshDailySummaries($this->branchA->id, $this->dateB);

    $rowAfter = DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->dateA)
        ->first();

    $rowB = DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->dateB)
        ->first();

    expect((float) $rowAfter->quantity_in_total)->toBe((float) $rowBefore->quantity_in_total)
        ->and((float) $rowAfter->quantity_out_total)->toBe((float) $rowBefore->quantity_out_total)
        ->and($rowB)->not->toBeNull()
        ->and((float) $rowB->quantity_in_total)->toBe(5.0);
});

it('does not alter branch B summaries when refreshing branch A only', function () {
    $locationA = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $locationB = InventoryLocation::factory()->create(['branch_id' => $this->branchB->id]);
    $productA = Product::factory()->create(['branch_id' => $this->branchA->id, 'average_cost' => 10]);
    $productB = Product::factory()->create(['branch_id' => $this->branchB->id, 'average_cost' => 20]);

    incrementalMovement($this->branchA, $productA, $locationA, $this->dateA, qtyIn: 7);
    incrementalMovement($this->branchB, $productB, $locationB, $this->dateA, qtyIn: 11);

    $this->service->refreshBranchSummaries($this->branchA->id, $this->dateA);
    $this->service->refreshBranchSummaries($this->branchB->id, $this->dateA);

    $branchBBefore = DB::table('rpt_inventory_branch_summaries')
        ->where('branch_id', $this->branchB->id)
        ->where('snapshot_date', $this->dateA)
        ->first();

    incrementalMovement($this->branchA, $productA, $locationA, $this->dateB, qtyIn: 99);

    $this->service->refreshBranchSummaries($this->branchA->id, $this->dateB);

    $branchBAfter = DB::table('rpt_inventory_branch_summaries')
        ->where('branch_id', $this->branchB->id)
        ->where('snapshot_date', $this->dateA)
        ->first();

    expect((float) $branchBAfter->inventory_value)->toBe((float) $branchBBefore->inventory_value)
        ->and((int) $branchBAfter->active_sku_count)->toBe((int) $branchBBefore->active_sku_count);
});

it('does not remove branch B product summaries when refreshing branch A product summary', function () {
    $locationA = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $locationB = InventoryLocation::factory()->create(['branch_id' => $this->branchB->id]);
    $productA = Product::factory()->create(['branch_id' => $this->branchA->id]);
    $productB = Product::factory()->create(['branch_id' => $this->branchB->id]);

    incrementalMovement($this->branchA, $productA, $locationA, $this->dateA, qtyIn: 4);
    incrementalMovement($this->branchB, $productB, $locationB, $this->dateA, qtyIn: 9);

    $this->service->refreshProductSummaries($this->branchA->id, $this->dateA);
    $this->service->refreshProductSummaries($this->branchB->id, $this->dateA);

    $branchBRowBefore = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchB->id)
        ->where('product_id', $productB->id)
        ->where('snapshot_date', $this->dateA)
        ->first();

    incrementalMovement($this->branchA, $productA, $locationA, $this->dateB, qtyOut: 1);

    $this->service->refreshProductSummaries($this->branchA->id, $this->dateB);

    $branchBRowAfter = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branchB->id)
        ->where('product_id', $productB->id)
        ->where('snapshot_date', $this->dateA)
        ->first();

    expect((float) $branchBRowAfter->current_stock)->toBe((float) $branchBRowBefore->current_stock)
        ->and(DB::table('rpt_inventory_product_summaries')
            ->where('branch_id', $this->branchA->id)
            ->where('snapshot_date', $this->dateB)
            ->count())->toBe(1);
});

it('is idempotent when re-running refresh for the same date', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branchA->id]);
    $product = Product::factory()->create(['branch_id' => $this->branchA->id]);

    incrementalMovement($this->branchA, $product, $location, $this->dateA, qtyIn: 6);

    Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branchA->id,
        '--date' => $this->dateA,
        '--all' => true,
    ]);

    Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branchA->id,
        '--date' => $this->dateA,
        '--all' => true,
    ]);

    expect(DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->dateA)
        ->count())->toBe(1)
        ->and(DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $this->branchA->id)
            ->where('snapshot_date', $this->dateA)
            ->count())->toBe(1)
        ->and(DB::table('rpt_inventory_product_summaries')
            ->where('branch_id', $this->branchA->id)
            ->where('snapshot_date', $this->dateA)
            ->count())->toBe(1);
});

it('does not duplicate supplier procurement slice rows on re-refresh', function () {
    $supplier = Supplier::factory()->create([
        'branch_id' => $this->branchA->id,
        'is_active' => true,
    ]);

    $this->service->refreshProcurementDailySummaries($this->branchA->id, $this->dateA);
    $this->service->refreshProcurementDailySummaries($this->branchA->id, $this->dateA);

    $branchRollupCount = DB::table('rpt_procurement_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->dateA)
        ->whereNull('supplier_id')
        ->count();

    $supplierSliceCount = DB::table('rpt_procurement_daily_summaries')
        ->where('branch_id', $this->branchA->id)
        ->where('summary_date', $this->dateA)
        ->where('supplier_id', $supplier->id)
        ->count();

    expect($branchRollupCount)->toBe(1)
        ->and($supplierSliceCount)->toBe(1);
});
