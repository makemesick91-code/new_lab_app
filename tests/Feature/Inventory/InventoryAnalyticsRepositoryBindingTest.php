<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\InventoryAnalyticsRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Repositories\InventoryAnalyticsRepository;
use App\Modules\Inventory\Repositories\InventorySummaryAnalyticsRepository;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use App\Modules\Inventory\Services\InventoryExecutiveDashboardService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;

function forgetAnalyticsBindings(): void
{
    app()->forgetInstance(InventoryAnalyticsRepositoryInterface::class);
    app()->forgetInstance(InventorySummaryAnalyticsRepository::class);
    app()->forgetInstance(InventoryAnalyticsRepository::class);
    app()->forgetInstance(InventoryExecutiveDashboardService::class);
}

it('defaults analytics summary feature flag to false', function () {
    expect(config('inventory.analytics_summary_enabled'))->toBeFalse();
});

it('resolves InventoryAnalyticsRepository when feature flag is false', function () {
    config(['inventory.analytics_summary_enabled' => false]);
    forgetAnalyticsBindings();

    $resolved = app(InventoryAnalyticsRepositoryInterface::class);

    expect($resolved)->toBeInstanceOf(InventoryAnalyticsRepository::class)
        ->and($resolved)->not->toBeInstanceOf(InventorySummaryAnalyticsRepository::class);
});

it('resolves InventorySummaryAnalyticsRepository when feature flag is true', function () {
    config(['inventory.analytics_summary_enabled' => true]);
    forgetAnalyticsBindings();

    $resolved = app(InventoryAnalyticsRepositoryInterface::class);

    expect($resolved)->toBeInstanceOf(InventorySummaryAnalyticsRepository::class);
});

it('resolves InventoryExecutiveDashboardService when feature flag is false', function () {
    config(['inventory.analytics_summary_enabled' => false]);
    forgetAnalyticsBindings();

    expect(app(InventoryExecutiveDashboardService::class))
        ->toBeInstanceOf(InventoryExecutiveDashboardService::class);
});

it('resolves InventoryExecutiveDashboardService when feature flag is true', function () {
    config(['inventory.analytics_summary_enabled' => true]);
    forgetAnalyticsBindings();

    expect(app(InventoryExecutiveDashboardService::class))
        ->toBeInstanceOf(InventoryExecutiveDashboardService::class);
});

it('loads executive dashboard when flag is true and summaries are refreshed', function () {
    test()->seed(BranchSeeder::class);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $today = now()->toDateString();
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'average_cost' => 100,
        'is_active' => true,
    ]);

    InventoryMovement::factory()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => $today,
        'quantity_in' => 10,
        'quantity_out' => 0,
    ]);

    app(InventoryAnalyticsSummaryRefreshService::class)->refreshAll($branch->id, $today);

    config(['inventory.analytics_summary_enabled' => true]);
    forgetAnalyticsBindings();

    $resolved = app(InventoryAnalyticsRepositoryInterface::class);
    $dashboard = app(InventoryExecutiveDashboardService::class);
    $payload = $dashboard->getExecutiveDashboard($branch->id);

    expect($resolved)->toBeInstanceOf(InventorySummaryAnalyticsRepository::class)
        ->and($payload)->toHaveKeys(['snapshot', 'cards', 'sections', 'meta'])
        ->and($payload['snapshot']->inventoryValue)->toBe(1000.0)
        ->and($payload['meta']['branch_id'])->toBe($branch->id);
});

it('loads executive dashboard when flag is true and summary tables are empty', function () {
    test()->seed(BranchSeeder::class);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    config(['inventory.analytics_summary_enabled' => true]);
    forgetAnalyticsBindings();

    expect(DB::table('rpt_inventory_branch_summaries')->count())->toBe(0);

    $dashboard = app(InventoryExecutiveDashboardService::class);
    $payload = $dashboard->getExecutiveDashboard($branch->id);

    expect($payload)->toHaveKeys(['snapshot', 'cards', 'sections', 'meta'])
        ->and($payload['snapshot']->inventoryValue)->toBe(0.0)
        ->and($payload['snapshot']->activeSku)->toBe(0)
        ->and($payload['cards'])->toHaveCount(9);
});

it('uses live ledger repository when flag is false without requiring rpt tables', function () {
    test()->seed(BranchSeeder::class);

    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'average_cost' => 50,
        'is_active' => true,
    ]);

    InventoryMovement::factory()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 6,
        'quantity_out' => 0,
    ]);

    config(['inventory.analytics_summary_enabled' => false]);
    forgetAnalyticsBindings();

    expect(DB::table('rpt_inventory_branch_summaries')->count())->toBe(0);

    $resolved = app(InventoryAnalyticsRepositoryInterface::class);

    expect($resolved)->toBeInstanceOf(InventoryAnalyticsRepository::class)
        ->and($resolved)->not->toBeInstanceOf(InventorySummaryAnalyticsRepository::class)
        ->and($resolved->getInventoryValue($branch->id))->toBe(300.0);
});
