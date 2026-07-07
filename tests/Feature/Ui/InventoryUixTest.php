<?php

/**
 * UIX-6 — Inventory polish. Presentation-only; the inventory scan surfaces
 * (dashboard, product list, current stock, stock card, low-stock/expiry alerts,
 * batch/lot, and the procurement/transfer/opname list headers) adopt the
 * DaengtisiaMS design system. No inventory ledger / stock-calculation /
 * procurement / transfer / stock-opname / valuation / batch logic changes, and
 * no controller/service/query/permission/BranchContext/route/schema change.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;

uses()->group('Ui', 'UiFoundation', 'Inventory');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->branch->update([
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->user = userWith(['view_inventory']);
});

// ---------------------------------------------------------------------------
// Pages still render / authorize (no logic regression)
// ---------------------------------------------------------------------------

it('redirects guests to login for inventory pages', function () {
    $this->get(route('inventory.dashboard'))->assertRedirect(route('login'));
    $this->get(route('inventory.products.index'))->assertRedirect(route('login'));
    $this->get(route('inventory.stock.index'))->assertRedirect(route('login'));
});

it('renders the polished inventory dashboard for an authorized user', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Inventory', false);
});

it('renders the polished product list for an authorized user', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.products.index'))
        ->assertOk()
        ->assertSee('Direktori Stok Produk', false);
});

it('renders the polished current stock list', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.stock.index'))
        ->assertOk()
        ->assertSee('Saldo Stok Berbasis Ledger', false);
});

it('renders the polished low stock / expiry alerts list', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('Peringatan stok dan batch cabang aktif', false);
});

it('renders the polished batch / lot list', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('Direktori Batch', false);
});

it('renders the polished ledger-derived stock card with running balance intact', function () {
    $product = Product::factory()->create(['branch_id' => test()->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => test()->branch->id]);
    app(InventoryStockService::class)->createOpeningStock($product->id, $location->id, 12);

    $this->actingAs(test()->user)
        ->get(route('inventory.products.stock-card', $product))
        ->assertOk()
        // Running-balance presentation preserved.
        ->assertSee('Saldo Berjalan', false)
        ->assertSee('Riwayat Pergerakan Stok', false);
});

// ---------------------------------------------------------------------------
// Design-system markers present; legacy brand color / gold-CTA gone
// ---------------------------------------------------------------------------

it('uses x-ui foundation components in the reference product list view', function () {
    $view = file_get_contents(resource_path('views/inventory/products/index.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.filter-bar');
    expect($view)->toContain('x-ui.table');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.button');
    expect($view)->toContain('x-ui.empty-state');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
});

it('uses x-ui foundation components in the ledger-derived stock card view', function () {
    $view = file_get_contents(resource_path('views/inventory/stock/card.blade.php'));

    expect($view)->toContain('x-ui.page-header');
    expect($view)->toContain('x-ui.card');
    expect($view)->toContain('x-ui.badge');
    expect($view)->toContain('x-ui.table');
    expect($view)->not->toMatch('/\b(?:bg|text|border|ring)-teal-\d/');
});

it('routes inventory status through the x-ui.badge design-system pattern', function () {
    // Low-stock badge partial and procurement status badges emit x-ui.badge.
    expect(file_get_contents(resource_path('views/inventory/_low-stock-badge.blade.php')))->toContain('x-ui.badge');
    expect(file_get_contents(resource_path('views/inventory/purchase-requests/_status-badge.blade.php')))->toContain('x-ui.badge');
    expect(file_get_contents(resource_path('views/inventory/goods-receipts/_status-badge.blade.php')))->toContain('x-ui.badge');
});

it('never reintroduces legacy teal, gold CTAs, or a mutable stock write in polished inventory views', function () {
    $files = [
        'dashboard.blade.php',
        'products/index.blade.php',
        'stock/index.blade.php',
        'stock/card.blade.php',
        'alerts/index.blade.php',
        'batches/index.blade.php',
        'purchase-requests/index.blade.php',
        'purchase-orders/index.blade.php',
        'goods-receipts/index.blade.php',
        'stock-transfers/index.blade.php',
        'stock-opnames/index.blade.php',
    ];

    foreach ($files as $file) {
        $view = file_get_contents(resource_path('views/inventory/'.$file));
        expect($view)->not->toMatch('/\b(?:bg|text|border|ring|divide)-teal-\d/');
        expect($view)->not->toContain('variant="gold"');
        // Stock stays ledger-derived — no mutable stock attribute assignment.
        expect($view)->not->toMatch('/->(?:current_stock|derived_stock|stock_quantity|quantity_on_hand|stock_on_hand)\s*=(?!=)/');
    }
});

// ---------------------------------------------------------------------------
// Governance command still GO with the added UIX-6 rules
// ---------------------------------------------------------------------------

it('passes the UI governance check with GO including UIX-6 rules', function () {
    $exit = Artisan::call('architecture:ui-governance-check', ['--json' => true, '--strict' => true]);

    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('"decision": "GO"');
});
