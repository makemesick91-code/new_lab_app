<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->stockService = app(InventoryStockService::class);
});

it('does not render heavy movement sections on default analytics index', function () {
    $user = userWith(['view_inventory']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Deferred Default Product',
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 40);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Ringkasan Analitik')
        ->assertSee('Ringkasan analitik cabang aktif')
        ->assertDontSee('id="section-fast"', false)
        ->assertDontSee('id="section-supplier"', false)
        ->assertDontSee('Deferred Default Product');
});

it('renders movement tab sections when tab query is movement', function () {
    $user = userWith(['view_inventory']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Movement Tab Product',
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 25);
    $this->stockService->adjustOut($product->id, $location->id, 5);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'movement']))
        ->assertOk()
        ->assertSee('id="section-fast"', false)
        ->assertSee('id="section-slow"', false)
        ->assertSee('id="section-dead"', false)
        ->assertSee('Movement Tab Product');
});

it('renders supplier tab when tab query is supplier', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'supplier']))
        ->assertOk()
        ->assertSee('id="section-supplier"', false)
        ->assertSee('Kinerja Supplier');
});

it('renders aging tab when tab query is aging', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'aging']))
        ->assertOk()
        ->assertSee('id="section-aging"', false)
        ->assertSee('Umur Persediaan');
});

it('renders reorder tab when tab query is reorder', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'reorder']))
        ->assertOk()
        ->assertSee('id="section-reorder"', false)
        ->assertSee('Rekomendasi Reorder');
});

it('renders procurement tab when tab query is procurement', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'procurement']))
        ->assertOk()
        ->assertSee('id="section-procurement"', false)
        ->assertSee('Tren Procurement');
});

it('falls back to summary tab for invalid tab without error', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'not-a-real-tab']))
        ->assertOk()
        ->assertSee('Ringkasan analitik cabang aktif')
        ->assertDontSee('id="section-fast"', false);
});

it('shows analytics mode hint on analytics index', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Live ledger mode')
        ->assertSee('Summary belum di-refresh');
});

it('still requires view_inventory permission for deferred tabs', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'movement']))
        ->assertForbidden();
});
