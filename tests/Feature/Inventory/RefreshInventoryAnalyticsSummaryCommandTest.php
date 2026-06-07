<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
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

it('runs inventory analytics summary refresh successfully without options', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('completed');
});

it('runs with --all successfully', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', ['--all' => true]);

    expect($exitCode)->toBe(0);
});

it('runs with valid --date successfully', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--date' => '2026-06-07',
        '--all' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('2026-06-07');
});

it('fails with invalid --date format', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--date' => '07-06-2026',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid date format');
});

it('runs with valid --branch successfully', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--all' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain((string) $this->branch->id);
});

it('fails with invalid --branch', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => '999999',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Invalid branch_id');
});

it('runs --product-summary and creates product summary when data exists', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    InventoryMovement::factory()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => $this->today,
        'quantity_in' => 12,
        'quantity_out' => 0,
    ]);

    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--product-summary' => true,
        '--date' => $this->today,
    ]);

    $row = DB::table('rpt_inventory_product_summaries')
        ->where('branch_id', $this->branch->id)
        ->where('product_id', $product->id)
        ->where('snapshot_date', $this->today)
        ->first();

    expect($exitCode)->toBe(0)
        ->and($row)->not->toBeNull()
        ->and((float) $row->current_stock)->toBe(12.0);
});

it('runs --daily only and populates rpt_inventory_daily_summaries', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    InventoryMovement::factory()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => $this->today,
        'quantity_in' => 3,
        'quantity_out' => 0,
    ]);

    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--date' => $this->today,
        '--daily' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('rpt_inventory_daily_summaries')
            ->where('branch_id', $this->branch->id)
            ->where('summary_date', $this->today)
            ->count())->toBe(1)
        ->and(DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0)
        ->and(DB::table('rpt_inventory_product_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0)
        ->and(DB::table('rpt_procurement_daily_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0);
});

it('runs --branch-summary only and populates rpt_inventory_branch_summaries', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--date' => $this->today,
        '--branch-summary' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $this->branch->id)
            ->where('snapshot_date', $this->today)
            ->count())->toBe(1)
        ->and(DB::table('rpt_inventory_daily_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0)
        ->and(DB::table('rpt_inventory_product_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0)
        ->and(DB::table('rpt_procurement_daily_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0);
});

it('runs --procurement only and populates rpt_procurement_daily_summaries', function () {
    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--date' => $this->today,
        '--procurement' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(DB::table('rpt_procurement_daily_summaries')
            ->where('branch_id', $this->branch->id)
            ->where('summary_date', $this->today)
            ->whereNull('supplier_id')
            ->count())->toBe(1)
        ->and(DB::table('rpt_inventory_daily_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0)
        ->and(DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $this->branch->id)
            ->count())->toBe(0);
});

it('limits refresh to specified branch with --branch and --date', function () {
    $branchB = Branch::factory()->create(['code' => 'CMD-B', 'name' => 'Command Branch B']);
    $targetDate = '2026-06-07';

    Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--date' => $targetDate,
        '--branch-summary' => true,
    ]);

    expect(DB::table('rpt_inventory_branch_summaries')
        ->where('branch_id', $this->branch->id)
        ->where('snapshot_date', $targetDate)
        ->count())->toBe(1)
        ->and(DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $branchB->id)
            ->count())->toBe(0);
});

it('fails invalid branch without creating new summary rows', function () {
    $beforeCount = DB::table('rpt_inventory_branch_summaries')->count();

    $exitCode = Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => '999999',
        '--all' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(DB::table('rpt_inventory_branch_summaries')->count())->toBe($beforeCount);
});

it('is idempotent when command is run twice', function () {
    Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--all' => true,
        '--date' => $this->today,
    ]);

    Artisan::call('inventory:analytics-summary:refresh', [
        '--branch' => (string) $this->branch->id,
        '--all' => true,
        '--date' => $this->today,
    ]);

    expect(DB::table('rpt_inventory_daily_summaries')
        ->where('branch_id', $this->branch->id)
        ->where('summary_date', $this->today)
        ->count())->toBe(1)
        ->and(DB::table('rpt_inventory_branch_summaries')
            ->where('branch_id', $this->branch->id)
            ->where('snapshot_date', $this->today)
            ->count())->toBe(1)
        ->and(DB::table('rpt_procurement_daily_summaries')
            ->where('branch_id', $this->branch->id)
            ->where('summary_date', $this->today)
            ->whereNull('supplier_id')
            ->count())->toBe(1);
});
