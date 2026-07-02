<?php

use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->mainBranch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->mainBranch->update([
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
        'name' => 'Klinik Gigi Daengtisia Pusat',
    ]);

    test()->rmeBranch = Branch::factory()->create([
        'code' => 'ATG3',
        'name' => 'Cabang Antang',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->nonRmeBranch = Branch::factory()->create([
        'code' => 'INV9',
        'name' => 'Cabang Gudang Non-RME',
        'is_active' => true,
        'is_rme_enabled' => false,
        'is_inventory_enabled' => true,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows actual rme-enabled branch names in the cabang filter', function () {
    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Klinik Gigi Daengtisia Pusat')
        ->assertSee('Cabang Antang')
        ->assertDontSee('>Cabang aktif<', false);
});

it('does not show non-rme-enabled branches in the cabang filter', function () {
    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertDontSee('Cabang Gudang Non-RME');
});

it('persists selected branch_id in the filter form', function () {
    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', ['branch_id' => test()->rmeBranch->id]))
        ->assertOk()
        ->assertSee('value="'.test()->rmeBranch->id.'"', false)
        ->assertSee('Cabang Antang', false);
});

it('preserves selected branch_id in tab links', function () {
    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $response = $this->actingAs($user)
        ->get(route('inventory.reports.index', [
            'branch_id' => test()->rmeBranch->id,
            'tab' => 'current-stock',
        ]))
        ->assertOk();

    expect($response->getContent())
        ->toContain('branch_id='.test()->rmeBranch->id)
        ->toContain('tab=stock-card');
});

it('uses selected branch for current stock report', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-STOCK',
        'product_name' => 'Main Branch Stock Product',
        'quantity_in' => 3,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-STOCK',
        'product_name' => 'Antang Branch Stock Product',
        'quantity_in' => 8,
    ]);

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Branch Stock Product')
        ->assertSee('8.0000')
        ->assertDontSee('Main Branch Stock Product');
});

it('uses selected branch for stock card report and still requires product_id', function () {
    [$product] = createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-CARD',
        'product_name' => 'Antang Stock Card Product',
        'quantity_in' => 5,
        'movement_date' => '2026-06-05',
    ]);

    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-CARD',
        'product_name' => 'Main Stock Card Product',
        'quantity_in' => 9,
        'movement_date' => '2026-06-05',
    ]);

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', ['tab' => 'stock-card', 'branch_id' => test()->rmeBranch->id]))
        ->assertOk()
        ->assertSee('Pilih produk terlebih dahulu untuk melihat Kartu Stok.');

    $this->actingAs($user)
        ->get(route('inventory.reports.index', [
            'tab' => 'stock-card',
            'branch_id' => test()->rmeBranch->id,
            'product_id' => $product->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Stock Card Product')
        ->assertDontSee('Main Stock Card Product');
});

it('uses selected branch for low stock report', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-LOW',
        'product_name' => 'Main Low Stock Product',
        'quantity_in' => 0,
        'minimum_stock' => 5,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-LOW',
        'product_name' => 'Antang Low Stock Product',
        'quantity_in' => 0,
        'minimum_stock' => 5,
    ]);

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', [
            'tab' => 'low-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Low Stock Product')
        ->assertDontSee('Main Low Stock Product');
});

it('uses selected branch for mutation report', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-MUT',
        'product_name' => 'Main Mutation Product',
        'quantity_in' => 2,
        'movement_date' => '2026-06-07',
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-MUT',
        'product_name' => 'Antang Mutation Product',
        'quantity_in' => 6,
        'movement_date' => '2026-06-07',
    ]);

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', [
            'tab' => 'mutation',
            'branch_id' => test()->rmeBranch->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('Antang Mutation Product')
        ->assertDontSee('Main Mutation Product');
});

it('uses selected branch for valuation report', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-VAL',
        'product_name' => 'Main Valuation Product',
        'quantity_in' => 2,
        'average_cost' => 100,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-VAL',
        'product_name' => 'Antang Valuation Product',
        'quantity_in' => 4,
        'average_cost' => 200,
    ]);

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', [
            'tab' => 'valuation',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Valuation Product')
        ->assertDontSee('Main Valuation Product');
});

it('uses selected branch for room stock report', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-ROOM',
        'product_name' => 'Main Room Stock Product',
        'location_name' => 'Main Treatment Room',
        'quantity_in' => 2,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-ROOM',
        'product_name' => 'Antang Room Stock Product',
        'location_name' => 'Antang Treatment Room',
        'quantity_in' => 7,
    ]);

    $user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index', [
            'tab' => 'room-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Room Stock Product')
        ->assertSee('Antang Treatment Room')
        ->assertDontSee('Main Room Stock Product');
});

it('falls back safely when branch_id is unauthorized', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'SAFE-MAIN',
        'product_name' => 'Safe Main Product',
        'quantity_in' => 4,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'LEAK-ATG',
        'product_name' => 'Leaked Antang Product',
        'quantity_in' => 99,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['branch_id' => test()->rmeBranch->id]))
        ->assertOk()
        ->assertSee('Safe Main Product')
        ->assertDontSee('Leaked Antang Product')
        ->assertDontSee('99.0000');
});

it('does not leak data for invalid branch_id', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'SAFE-ONLY',
        'product_name' => 'Only Main Product',
        'quantity_in' => 3,
    ]);

    createReportStockRow(test()->nonRmeBranch, [
        'product_code' => 'NON-RME',
        'product_name' => 'Non RME Hidden Product',
        'quantity_in' => 50,
    ]);

    $this->actingAs(userWith(['view_inventory', 'view_inventory_cross_branch_analytics']))
        ->get(route('inventory.reports.index', ['branch_id' => test()->nonRmeBranch->id]))
        ->assertOk()
        ->assertSee('Only Main Product')
        ->assertDontSee('Non RME Hidden Product')
        ->assertDontSee('50.0000');
});

it('keeps sprint 68.29 tab-scoped loading with branch filter', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'TAB-SCOPE',
        'product_name' => 'Tab Scope Product',
        'quantity_in' => 2,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->mainBranch->id,
        ]))
        ->assertOk()
        ->assertSee('data-report-panel="current-stock"', false)
        ->assertDontSee('data-report-panel="stock-card"', false)
        ->assertDontSee('data-report-panel="mutation"', false)
        ->assertDontSee('data-report-panel="valuation"', false)
        ->assertDontSee('data-report-panel="room-stock"', false);
});

it('shows the actual branch name when user has only one allowed branch', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Klinik Gigi Daengtisia Pusat')
        ->assertDontSee('>Cabang aktif<', false);
});
