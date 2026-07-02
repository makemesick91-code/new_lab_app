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

    test()->user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('exports current stock csv for the selected branch only', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-EXP',
        'product_name' => 'Main Export Product',
        'quantity_in' => 3,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-EXP',
        'product_name' => 'Antang Export Product',
        'quantity_in' => 8,
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'current_stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang Export Product')
        ->not->toContain('Main Export Product');
});

it('requires product selection for stock card export and exports only selected product', function () {
    [$product] = createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-CARD-EXP',
        'product_name' => 'Antang Card Export Product',
        'quantity_in' => 5,
        'movement_date' => '2026-06-05',
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-OTHER',
        'product_name' => 'Antang Other Card Product',
        'quantity_in' => 9,
        'movement_date' => '2026-06-05',
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'stock_card',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertInvalid('product_id');

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'stock_card',
            'branch_id' => test()->rmeBranch->id,
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang Card Export Product')
        ->not->toContain('Antang Other Card Product');
});

it('exports low stock csv for the selected branch', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-LOW-EXP',
        'product_name' => 'Main Low Export',
        'minimum_stock' => 5,
        'quantity_in' => 0,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-LOW-EXP',
        'product_name' => 'Antang Low Export',
        'minimum_stock' => 5,
        'quantity_in' => 0,
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'low_stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang Low Export')
        ->not->toContain('Main Low Export');
});

it('exports mutation csv with branch and date filters', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-MUT-EXP',
        'product_name' => 'Main Mutation Export',
        'quantity_in' => 2,
        'movement_date' => '2026-06-07',
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-MUT-EXP',
        'product_name' => 'Antang Mutation Export',
        'quantity_in' => 6,
        'movement_date' => '2026-06-07',
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'mutation',
            'branch_id' => test()->rmeBranch->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang Mutation Export')
        ->toContain('2026-06-01 - 2026-06-30')
        ->not->toContain('Main Mutation Export');
});

it('exports valuation csv for the selected branch', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-VAL-EXP',
        'product_name' => 'Main Valuation Export',
        'quantity_in' => 2,
        'average_cost' => 100,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-VAL-EXP',
        'product_name' => 'Antang Valuation Export',
        'quantity_in' => 4,
        'average_cost' => 200,
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'valuation',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang Valuation Export')
        ->not->toContain('Main Valuation Export');
});

it('exports room stock csv for branch and location filters', function () {
    [$product, $location] = createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-ROOM-EXP',
        'product_name' => 'Antang Room Export',
        'location_name' => 'Antang Room A',
        'minimum_stock' => 5,
        'quantity_in' => 1,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-ROOM-OTHER',
        'product_name' => 'Antang Room Other',
        'location_name' => 'Antang Room B',
        'minimum_stock' => 5,
        'quantity_in' => 1,
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'room_stock',
            'branch_id' => test()->rmeBranch->id,
            'inventory_location_id' => $location->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang Room Export')
        ->toContain('Antang Room A')
        ->not->toContain('Antang Room Other');
});

it('does not allow unauthorized branch_id to export another branch', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'SAFE-MAIN-EXP',
        'product_name' => 'Safe Main Export Product',
        'quantity_in' => 4,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'LEAK-ATG-EXP',
        'product_name' => 'Leaked Antang Export Product',
        'quantity_in' => 99,
    ]);

    $content = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', [
            'report_type' => 'current_stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Safe Main Export Product')
        ->not->toContain('Leaked Antang Export Product')
        ->not->toContain('99.0000');
});

it('generates export link from report page with selected branch and filters', function () {
    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-LINK',
        'product_name' => 'Antang Link Product',
        'quantity_in' => 2,
    ]);

    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
            'stock_status' => 'normal',
        ]))
        ->assertOk();

    expect($response->getContent())
        ->toContain('branch_id='.test()->rmeBranch->id)
        ->toContain('report_type=current_stock')
        ->toContain('stock_status=normal');
});

it('supports legacy tab query on export route for backward compatibility', function () {
    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-LEGACY',
        'product_name' => 'Antang Legacy Export Product',
        'quantity_in' => 3,
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang Legacy Export Product');
});

it('keeps sprint 68.31 dependent filter behavior on export', function () {
    [$product, $mainLocation] = createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-6831',
        'product_name' => 'Main 6831 Product',
        'location_name' => 'Main 6831 Room',
        'quantity_in' => 4,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-6831',
        'product_name' => 'Antang 6831 Product',
        'quantity_in' => 7,
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'current_stock',
            'branch_id' => test()->rmeBranch->id,
            'inventory_location_id' => $mainLocation->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Antang 6831 Product')
        ->not->toContain('Main 6831 Product');
});
