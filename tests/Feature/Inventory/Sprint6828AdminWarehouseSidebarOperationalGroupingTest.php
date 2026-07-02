<?php

use App\Models\User;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

function sprint6828RenderSidebarFor(User $user): string
{
    test()->actingAs($user);

    return view('layouts.partials.sidebar')->render();
}

function sprint6828ExtractPanel(string $html, string $panel): string
{
    $start = strpos($html, 'data-sidebar-panel="'.$panel.'"');
    expect($start)->not->toBeFalse("Panel {$panel} not found");

    $nextPanel = strpos($html, 'data-sidebar-panel="', $start + 1);
    if ($nextPanel === false) {
        return substr($html, $start);
    }

    return substr($html, $start, $nextPanel - $start);
}

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    test()->withoutMiddleware(ValidateCsrfToken::class);
});

it('shows operational inventory groups for admin warehouse', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    expect($html)
        ->toContain('>Master Data<')
        ->toContain('>Pembelian<')
        ->toContain('>Operasional Stok<')
        ->toContain('>Laporan & Analitik<');
});

it('places master data items only inside master data group', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    $masterPanel = sprint6828ExtractPanel($html, 'inventory-master-data');

    expect($masterPanel)
        ->toContain('>Produk<')
        ->toContain('>Kategori Produk<')
        ->toContain('>Satuan Produk<')
        ->toContain('>Lokasi Persediaan<')
        ->toContain('>Pemasok<')
        ->toContain('>Batch & Lot<');
});

it('places procurement items inside pembelian group', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    $procurementPanel = sprint6828ExtractPanel($html, 'procurement');

    expect($procurementPanel)
        ->toContain('>Permintaan Pembelian<')
        ->toContain('>Pesanan Pembelian<')
        ->toContain('>Penerimaan Barang<');
});

it('places stock operations inside operasional stok group', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    $operationsPanel = sprint6828ExtractPanel($html, 'inventory');

    expect($operationsPanel)
        ->toContain('>Stok Saat Ini<')
        ->toContain('>Transfer Stok<')
        ->toContain('>Stok Opname<')
        ->toContain('>Minimum Stok Ruangan<');
});

it('places reports and analytics inside laporan and analitik group', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    $reportsPanel = sprint6828ExtractPanel($html, 'inventory-reports-analytics');

    expect($reportsPanel)
        ->toContain('>Laporan Inventory<')
        ->toContain('>Kartu Stok<')
        ->toContain('>Low Stock<')
        ->toContain('>Mutasi Stok<')
        ->toContain('>Nilai Persediaan<')
        ->toContain('>Analitik Persediaan<');
});

it('does not duplicate master data labels outside master data group', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    $operationsPanel = sprint6828ExtractPanel($html, 'inventory');
    $reportsPanel = sprint6828ExtractPanel($html, 'inventory-reports-analytics');
    $procurementPanel = sprint6828ExtractPanel($html, 'procurement');

    foreach (['>Produk<', '>Kategori Produk<', '>Satuan Produk<', '>Lokasi Persediaan<', '>Pemasok<', '>Batch & Lot<'] as $label) {
        expect($operationsPanel)->not->toContain($label)
            ->and($reportsPanel)->not->toContain($label)
            ->and($procurementPanel)->not->toContain($label);
    }
});

it('does not duplicate procurement labels outside pembelian group', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    $operationsPanel = sprint6828ExtractPanel($html, 'inventory');
    $reportsPanel = sprint6828ExtractPanel($html, 'inventory-reports-analytics');

    foreach (['>Permintaan Pembelian<', '>Pesanan Pembelian<', '>Penerimaan Barang<'] as $label) {
        expect($operationsPanel)->not->toContain($label)
            ->and($reportsPanel)->not->toContain($label);
    }
});

it('does not show duplicate dashboard inventory entry for admin warehouse', function () {
    $user = userInRole('Admin Warehouse');
    $html = sprint6828RenderSidebarFor($user);

    expect($html)
        ->not->toContain('Dashboard Inventory')
        ->and(substr_count($html, '<span>Dashboard</span>'))->toBe(1);
});
