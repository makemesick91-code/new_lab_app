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
        'code' => 'ATG4',
        'name' => 'Cabang Antang',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows actual rme branch names in the dashboard branch filter', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Klinik Gigi Daengtisia Pusat')
        ->assertSee('Cabang Antang')
        ->assertDontSee('>Cabang aktif<', false);
});

it('does not use generic cabang aktif as the only selected label', function () {
    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Inventory — Klinik Gigi Daengtisia Pusat')
        ->assertDontSee('Visibilitas stok untuk cabang aktif');
});

it('uses selected branch data for dashboard kpis', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-DASH',
        'product_name' => 'Main Dashboard Product',
        'quantity_in' => 2,
        'average_cost' => 100,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-DASH',
        'product_name' => 'Antang Dashboard Product',
        'quantity_in' => 4,
        'average_cost' => 500,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.dashboard', ['branch_id' => test()->rmeBranch->id]))
        ->assertOk()
        ->assertSee('Dashboard Inventory — Cabang Antang')
        ->assertSee(format_currency_id(2000))
        ->assertDontSee(format_currency_id(200));
});

it('does not leak unauthorized branch data on dashboard', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'SAFE-MAIN',
        'product_name' => 'Safe Main Dashboard Product',
        'quantity_in' => 3,
        'average_cost' => 100,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'LEAK-ATG',
        'product_name' => 'Leaked Antang Dashboard Product',
        'quantity_in' => 10,
        'average_cost' => 900,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.dashboard', ['branch_id' => test()->rmeBranch->id]))
        ->assertOk()
        ->assertSee(format_currency_id(300))
        ->assertDontSee('Leaked Antang Dashboard Product')
        ->assertDontSee(format_currency_id(9000));
});

it('preserves branch_id in dashboard report links', function () {
    $response = $this->actingAs(test()->user)
        ->get(route('inventory.dashboard', ['branch_id' => test()->rmeBranch->id]))
        ->assertOk();

    expect($response->getContent())
        ->toContain('branch_id='.test()->rmeBranch->id)
        ->toContain('tab=valuation')
        ->toContain('tab=low-stock');
});

it('shows actual branch name for single-branch users', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Inventory — Klinik Gigi Daengtisia Pusat')
        ->assertDontSee('>Cabang aktif<', false);
});

it('keeps sprint 68.30 report branch tests compatible', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'S6830-MAIN',
        'product_name' => 'S6830 Main Product',
        'quantity_in' => 2,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'S6830-ATG',
        'product_name' => 'S6830 Antang Product',
        'quantity_in' => 7,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('S6830 Antang Product')
        ->assertDontSee('S6830 Main Product');
});
