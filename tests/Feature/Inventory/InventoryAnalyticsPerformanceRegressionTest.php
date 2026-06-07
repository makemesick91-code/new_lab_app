<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryAnalyticsSummaryRefreshService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->stockService = app(InventoryStockService::class);
});

it('default summary tab does not render heavy supplier reorder or procurement sections', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Ringkasan analitik cabang aktif')
        ->assertDontSee('id="section-supplier"', false)
        ->assertDontSee('id="section-reorder"', false)
        ->assertDontSee('id="section-procurement"', false)
        ->assertDontSee('id="section-branch-comparison"', false)
        ->assertDontSee('id="section-fast"', false);
});

it('supplier tab loads only supplier section', function () {
    $user = userWith(['view_inventory']);

    $response = $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'supplier']))
        ->assertOk()
        ->assertSee('id="section-supplier"', false)
        ->assertSee('Kinerja Supplier');

    expect($response->getContent())
        ->not->toContain('id="section-reorder"')
        ->not->toContain('id="section-procurement"');
});

it('reorder tab loads only reorder section', function () {
    $user = userWith(['view_inventory']);

    $response = $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'reorder']))
        ->assertOk()
        ->assertSee('id="section-reorder"', false)
        ->assertSee('Rekomendasi Reorder');

    expect($response->getContent())
        ->not->toContain('id="section-supplier"')
        ->not->toContain('id="section-procurement"');
});

it('procurement tab loads only procurement section', function () {
    $user = userWith(['view_inventory']);

    $response = $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'procurement']))
        ->assertOk()
        ->assertSee('id="section-procurement"', false)
        ->assertSee('Tren Procurement');

    expect($response->getContent())
        ->not->toContain('id="section-supplier"')
        ->not->toContain('id="section-reorder"');
});

it('hides branch comparison nav for users without cross branch permission', function () {
    $regularUser = userWith(['view_inventory']);
    $crossBranchUser = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($regularUser)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertDontSee('Perbandingan Cabang');

    $this->actingAs($crossBranchUser)
        ->get(route('inventory.analytics.index', ['tab' => 'branch-comparison']))
        ->assertOk()
        ->assertSee('id="section-branch-comparison"', false)
        ->assertSee('Perbandingan Cabang');
});

it('renders summary tab without error when feature flag is true and summaries refreshed', function () {
    config(['inventory.analytics_summary_enabled' => true]);

    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $this->stockService->createOpeningStock($product->id, $location->id, 15);

    app(InventoryAnalyticsSummaryRefreshService::class)->refreshAll();

    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Analytics summary mode aktif')
        ->assertSee('Ringkasan analitik cabang aktif')
        ->assertDontSee('id="section-supplier"', false);
});

it('renders summary tab without error when feature flag is false using live ledger', function () {
    config(['inventory.analytics_summary_enabled' => false]);

    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $this->stockService->createOpeningStock($product->id, $location->id, 20);

    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Live ledger mode')
        ->assertSee('Ringkasan analitik cabang aktif');
});

it('default summary tab avoids supplier performance query pattern in response body', function () {
    $user = userWith(['view_inventory']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Perf Regression Unique Product',
    ]);
    $this->stockService->createOpeningStock($product->id, $location->id, 5);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk();

    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');

    expect($response->getContent())
        ->not->toContain('Perf Regression Unique Product')
        ->not->toContain('id="section-fast"', false);

    // Coarse guard: default summary should not run supplier-tab-only heavy table markers.
    expect(strtolower($response->getContent()))
        ->not->toContain('id="section-supplier"');

    DB::disableQueryLog();
});

it('denies unauthorized users from analytics index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertForbidden();
});
