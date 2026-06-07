<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->today = now()->toDateString();
});

function insertDailySummary(int $branchId, string $summaryDate): void
{
    DB::table('rpt_inventory_daily_summaries')->insert([
        'branch_id' => $branchId,
        'summary_date' => $summaryDate,
        'quantity_in_total' => 1,
        'quantity_out_total' => 0,
        'inbound_value' => 100,
        'outbound_value' => 0,
        'purchase_inbound_value' => 100,
        'adjustment_in_qty' => 0,
        'adjustment_out_qty' => 0,
        'transfer_in_qty' => 0,
        'transfer_out_qty' => 0,
        'movement_count' => 1,
        'refreshed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertProcurementSummary(int $branchId, string $summaryDate): void
{
    DB::table('rpt_procurement_daily_summaries')->insert([
        'branch_id' => $branchId,
        'supplier_id' => null,
        'summary_date' => $summaryDate,
        'po_created_count' => 0,
        'po_created_value' => 0,
        'po_open_count' => 0,
        'po_open_outstanding_value' => 0,
        'gr_posted_count' => 0,
        'gr_received_value' => 0,
        'ledger_purchase_value' => 0,
        'pr_submitted_count' => 0,
        'supplier_order_count' => 0,
        'supplier_received_value' => 0,
        'supplier_on_time_count' => 0,
        'supplier_dated_po_count' => 0,
        'supplier_fulfilled_qty' => 0,
        'supplier_ordered_qty' => 0,
        'refreshed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertBranchSnapshot(int $branchId, string $snapshotDate): void
{
    DB::table('rpt_inventory_branch_summaries')->insert([
        'branch_id' => $branchId,
        'snapshot_date' => $snapshotDate,
        'inventory_value' => 500,
        'active_sku_count' => 1,
        'low_stock_count' => 0,
        'dead_stock_count' => 0,
        'dead_stock_value' => 0,
        'out_of_stock_count' => 0,
        'batch_expiring_soon_count' => 0,
        'batch_expired_count' => 0,
        'open_pr_count' => 0,
        'open_po_count' => 0,
        'open_po_outstanding_value' => 0,
        'pending_gr_count' => 0,
        'in_transit_transfer_count' => 0,
        'total_quantity_on_hand' => 10,
        'refreshed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertProductSnapshot(int $branchId, int $productId, string $snapshotDate): void
{
    DB::table('rpt_inventory_product_summaries')->insert([
        'branch_id' => $branchId,
        'product_id' => $productId,
        'snapshot_date' => $snapshotDate,
        'current_stock' => 10,
        'stock_value' => 500,
        'average_cost' => 50,
        'is_active' => true,
        'alert_enabled' => true,
        'effective_reorder_point' => 5,
        'is_low_stock' => false,
        'is_dead_stock' => false,
        'outbound_qty_7d' => 0,
        'outbound_qty_30d' => 0,
        'outbound_qty_90d' => 0,
        'outbound_value_30d' => 0,
        'avg_daily_consumption_30d' => 0,
        'refreshed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('dry run does not delete summary rows', function () {
    $oldDate = now()->subDays(800)->toDateString();

    insertDailySummary($this->branch->id, $oldDate);
    insertProcurementSummary($this->branch->id, $oldDate);

    $exitCode = Artisan::call('inventory:analytics-summary:prune', ['--dry-run' => true]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('rpt_inventory_daily_summaries')->count())->toBe(1)
        ->and(DB::table('rpt_procurement_daily_summaries')->count())->toBe(1)
        ->and(Artisan::output())->toContain('Dry run');
});

it('prunes old daily and procurement summaries', function () {
    $oldDate = now()->subDays(800)->toDateString();
    $recentDate = now()->subDays(10)->toDateString();

    insertDailySummary($this->branch->id, $oldDate);
    insertDailySummary($this->branch->id, $recentDate);
    insertProcurementSummary($this->branch->id, $oldDate);
    insertProcurementSummary($this->branch->id, $recentDate);

    $exitCode = Artisan::call('inventory:analytics-summary:prune', ['--days' => 730]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('rpt_inventory_daily_summaries')->count())->toBe(1)
        ->and(DB::table('rpt_procurement_daily_summaries')->count())->toBe(1)
        ->and(DB::table('rpt_inventory_daily_summaries')->value('summary_date'))->toBe($recentDate);
});

it('does not prune branch snapshot summaries', function () {
    $oldDate = now()->subDays(800)->toDateString();

    insertBranchSnapshot($this->branch->id, $oldDate);

    Artisan::call('inventory:analytics-summary:prune', ['--days' => 30]);

    expect(DB::table('rpt_inventory_branch_summaries')->count())->toBe(1);
});

it('does not prune product snapshot summaries', function () {
    $oldDate = now()->subDays(800)->toDateString();
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    insertProductSnapshot($this->branch->id, $product->id, $oldDate);

    Artisan::call('inventory:analytics-summary:prune', ['--days' => 30]);

    expect(DB::table('rpt_inventory_product_summaries')->count())->toBe(1);
});

it('fails when days is below 30', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:prune', ['--days' => 7]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('at least 30');
});

it('does not touch trx_inventory_movements', function () {
    $oldDate = now()->subDays(800)->toDateString();

    insertDailySummary($this->branch->id, $oldDate);

    $movementCountBefore = InventoryMovement::query()->count();

    Artisan::call('inventory:analytics-summary:prune', ['--days' => 30]);

    expect(InventoryMovement::query()->count())->toBe($movementCountBefore);
});

it('is idempotent when run twice', function () {
    $oldDate = now()->subDays(800)->toDateString();

    insertDailySummary($this->branch->id, $oldDate);
    insertProcurementSummary($this->branch->id, $oldDate);

    Artisan::call('inventory:analytics-summary:prune', ['--days' => 30]);
    Artisan::call('inventory:analytics-summary:prune', ['--days' => 30]);

    expect(DB::table('rpt_inventory_daily_summaries')->count())->toBe(0)
        ->and(DB::table('rpt_procurement_daily_summaries')->count())->toBe(0)
        ->and(Artisan::output())->toContain('Nothing to prune');
});

it('defaults retention days from config', function () {
    expect(config('inventory.analytics_summary_retention_days'))->toBe(730);
});
