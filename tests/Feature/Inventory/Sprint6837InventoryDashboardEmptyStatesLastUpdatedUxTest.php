<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-07-02 13:52:00');

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->branch->update([
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
        'name' => 'Klinik Gigi Daengtisia Pusat',
    ]);

    test()->user = userWith(['view_inventory']);
    test()->stockService = app(InventoryStockService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renders last updated label on inventory dashboard', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Terakhir diperbarui:', false)
        ->assertSee('data-testid="inventory-dashboard-last-updated"', false)
        ->assertSee(format_datetime_id(now()), false);
});

it('shows low stock empty state when no stock alerts exist', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Tidak ada item low stock untuk cabang ini.')
        ->assertSee('data-testid="inventory-dashboard-low-stock-empty"', false);
});

it('shows recent movement empty state when no movements exist', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Belum ada mutasi stok terbaru untuk cabang ini.')
        ->assertSee('data-testid="inventory-dashboard-recent-movements-empty"', false);
});

it('hides low stock empty state when stock alerts exist', function () {
    $product = Product::factory()->create([
        'branch_id' => test()->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'alert_enabled' => true,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => test()->branch->id]);

    test()->stockService->createOpeningStock($product->id, $location->id, 5);

    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee($product->name)
        ->assertDontSee('data-testid="inventory-dashboard-low-stock-empty"', false);
});

it('hides recent movement empty state when movements exist', function () {
    $product = Product::factory()->create(['branch_id' => test()->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => test()->branch->id]);

    test()->stockService->createOpeningStock($product->id, $location->id, 3);

    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Pergerakan Terbaru')
        ->assertDontSee('data-testid="inventory-dashboard-recent-movements-empty"', false);
});

it('shows polished dashboard subtitle and breadcrumb hint', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Inventory — Klinik Gigi Daengtisia Pusat')
        ->assertSee('Ringkasan operasional gudang berdasarkan cabang terpilih.')
        ->assertSee('Inventory / Dashboard');
});

it('keeps admin warehouse quick actions from sprint 68.35', function () {
    $html = $this->actingAs(userInRole('Admin Warehouse'))
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Aksi Cepat Harian Gudang')
        ->getContent();

    $panel = inventoryQuickActionsPanelHtml($html);

    expect($panel)
        ->toContain('Buat Permintaan Pembelian')
        ->toContain('Buat Pesanan Pembelian')
        ->toContain('Terima Barang')
        ->toContain('Transfer Stok')
        ->toContain('Mulai Stok Opname')
        ->toContain('Buka Laporan Inventory');
});

it('keeps branch selector with actual branch name from sprint 68.34', function () {
    $this->actingAs(userWith(['view_inventory', 'view_inventory_cross_branch_analytics']))
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Klinik Gigi Daengtisia Pusat')
        ->assertSee('name="branch_id"', false);
});

it('hides restricted quick actions from unauthorized users', function () {
    $html = $this->actingAs(User::factory()->create())
        ->get(route('inventory.dashboard'))
        ->assertForbidden()
        ->getContent();

    expect($html)->not->toContain('data-testid="inventory-quick-actions"');
});

it('loads inventory dashboard without server error', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Kartu KPI Persediaan');
});
