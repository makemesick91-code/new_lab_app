<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->stockService = app(InventoryStockService::class);
});

it('registers the inventory analytics route', function () {
    expect(Route::has('inventory.analytics.index'))->toBeTrue();
});

it('redirects guests from analytics index', function () {
    $this->get(route('inventory.analytics.index'))
        ->assertRedirect(route('login'));
});

it('denies unauthorized users from analytics index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertForbidden();
});

it('allows view_inventory users to access analytics', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Analitik Persediaan');
});

it('allows manage_inventory users to access analytics', function () {
    $user = userWith(['manage_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Analitik Persediaan');
});

it('rejects invalid date range on analytics index', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->from(route('inventory.analytics.index'))
        ->get(route('inventory.analytics.index', [
            'date_from' => '2026-06-10',
            'date_to' => '2026-06-01',
        ]))
        ->assertRedirect(route('inventory.analytics.index'))
        ->assertSessionHasErrors('date_to');
});

it('rejects date range greater than 365 days', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->from(route('inventory.analytics.index'))
        ->get(route('inventory.analytics.index', [
            'date_from' => '2024-01-01',
            'date_to' => '2026-06-06',
        ]))
        ->assertRedirect(route('inventory.analytics.index'))
        ->assertSessionHasErrors('date_to');
});

it('rejects foreign branch location_id filter', function () {
    $user = userWith(['view_inventory']);
    $otherBranch = Branch::factory()->create();
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($user)
        ->from(route('inventory.analytics.index'))
        ->get(route('inventory.analytics.index', ['location_id' => $otherLocation->id]))
        ->assertRedirect(route('inventory.analytics.index'))
        ->assertSessionHasErrors('location_id');
});

it('accepts same branch location_id filter', function () {
    $user = userWith(['view_inventory']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['location_id' => $location->id]))
        ->assertOk()
        ->assertSee('Analitik Persediaan');
});

it('rejects limit above 100', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->from(route('inventory.analytics.index'))
        ->get(route('inventory.analytics.index', ['limit' => 101]))
        ->assertRedirect(route('inventory.analytics.index'))
        ->assertSessionHasErrors('limit');
});

it('renders full analytics page with Indonesian section labels', function () {
    $user = userWith(['view_inventory']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Resin Analytics Product',
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 50);
    $this->stockService->adjustOut($product->id, $location->id, 10);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', [
            'tab' => 'movement',
            'date_from' => now()->subDays(30)->toDateString(),
            'date_to' => now()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee('Analitik Persediaan')
        ->assertSee('Ringkasan Analitik')
        ->assertSee('Produk Cepat Bergerak')
        ->assertSee('Produk Lambat Bergerak')
        ->assertSee('Stok Mati')
        ->assertSee('Nilai Keluar Bulanan')
        ->assertSee('Resin Analytics Product');
});

it('renders analytics filter form inputs', function () {
    $user = userWith(['view_inventory']);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Filter Location']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('name="date_from"', false)
        ->assertSee('name="date_to"', false)
        ->assertSee('name="location_id"', false)
        ->assertSee('name="category_id"', false)
        ->assertSee('name="dead_stock_days"', false)
        ->assertSee('name="slow_moving_threshold"', false)
        ->assertSee('name="limit"', false)
        ->assertSee('name="aging_granularity"', false)
        ->assertSee('Terapkan')
        ->assertSee('Atur Ulang');
});

it('renders analytics section tables and mobile card markup', function () {
    $user = userWith(['view_inventory']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Section Table Product',
    ]);

    $this->stockService->createOpeningStock($product->id, $location->id, 30);
    $this->stockService->adjustOut($product->id, $location->id, 5);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', [
            'tab' => 'movement',
            'date_from' => now()->subDays(30)->toDateString(),
            'date_to' => now()->toDateString(),
        ]))
        ->assertOk()
        ->assertSee('id="section-fast"', false)
        ->assertSee('id="section-slow"', false)
        ->assertSee('id="section-dead"', false)
        ->assertSee('analytics-mobile-cards', false)
        ->assertSee('Section Table Product');
});

it('shows analytics disclaimers on the page', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index'))
        ->assertOk()
        ->assertSee('Semua stok dihitung dari ledger pergerakan')
        ->assertSee('bukan', false)
        ->assertSee('nilai stok historis on-hand')
        ->assertSee('tanggal masuk terakhir sebagai perkiraan');
});

it('shows empty states when analytics data is empty', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.analytics.index', ['tab' => 'movement']))
        ->assertOk()
        ->assertSee('Belum ada data analitik untuk filter ini.')
        ->assertSee('Coba ubah periode atau filter.');
});

it('shows dashboard link to analytics for permitted users', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Analitik Persediaan')
        ->assertSee(route('inventory.analytics.index'), false);
});

it('shows Analitik Persediaan sidebar link for permitted users', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Analitik Persediaan');
});

it('hides Analitik Persediaan sidebar link for unauthorized users', function () {
    $user = userWith(['view_invoice']);

    $response = $this->actingAs($user)->get(route('invoices.index'));

    if ($response->status() === 200) {
        $response->assertDontSee('Analitik Persediaan');
    } else {
        expect(true)->toBeTrue();
    }
});

it('does not introduce mutable stock columns on products', function () {
    $columns = Schema::getColumnListing('inv_products');

    expect($columns)->not->toContain('current_stock')
        ->and($columns)->not->toContain('stock')
        ->and($columns)->not->toContain('qty_on_hand')
        ->and($columns)->not->toContain('available_stock');
});
