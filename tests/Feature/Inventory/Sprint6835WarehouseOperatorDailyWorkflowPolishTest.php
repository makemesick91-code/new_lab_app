<?php

use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

function sprint6835RenderSidebarFor(User $user): string
{
    test()->actingAs($user);

    return view('layouts.partials.sidebar')->render();
}

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    test()->withoutMiddleware(ValidateCsrfToken::class);
});

it('shows quick actions for permitted admin warehouse workflows', function () {
    $html = test()->actingAs(userInRole('Admin Warehouse'))
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

it('points quick action links to existing route names', function () {
    $html = test()->actingAs(userInRole('Admin Warehouse'))
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->getContent();

    $panel = inventoryQuickActionsPanelHtml($html);

    expect($panel)
        ->toContain(route('inventory.purchase-requests.create'))
        ->toContain(route('inventory.purchase-orders.create'))
        ->toContain(route('inventory.goods-receipts.create'))
        ->toContain(route('inventory.stock-transfers.create'))
        ->toContain(route('inventory.stock-opnames.create'))
        ->toContain(route('inventory.reports.index'));
});

it('hides restricted quick actions from unauthorized users', function () {
    $html = test()->actingAs(User::factory()->create())
        ->get(route('inventory.dashboard'))
        ->assertForbidden()
        ->getContent();

    expect($html)->not->toContain('data-testid="inventory-quick-actions"');
});

it('keeps sidebar routes available for admin warehouse navigation', function () {
    $html = sprint6835RenderSidebarFor(userInRole('Admin Warehouse'));

    expect($html)
        ->toContain(route('inventory.dashboard'))
        ->toContain(route('inventory.reports.index'));
});

it('loads inventory dashboard without server error for admin warehouse', function () {
    test()->actingAs(userInRole('Admin Warehouse'))
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Kartu KPI Persediaan');
});

it('keeps sprint 68.34 dashboard branch filter behavior', function () {
    test()->actingAs(userWith(['view_inventory', 'view_inventory_cross_branch_analytics']))
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Inventory —')
        ->assertSee('name="branch_id"', false);
});

it('keeps sprint 68.28 sidebar grouping for admin warehouse', function () {
    $html = sprint6835RenderSidebarFor(userInRole('Admin Warehouse'));

    expect($html)
        ->toContain('>Master Data<')
        ->toContain('>Pembelian<')
        ->toContain('>Operasional Stok<')
        ->toContain('>Laporan & Analitik<');
});
