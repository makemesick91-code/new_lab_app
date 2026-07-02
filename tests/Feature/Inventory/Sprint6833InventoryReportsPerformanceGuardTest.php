<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Services\InventoryReportService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->branch->update([
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->otherBranch = Branch::factory()->create([
        'code' => 'PERF2',
        'name' => 'Performance Branch B',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('does not render stock card movement rows when product is not selected', function () {
    createReportStockRow(test()->branch, [
        'product_code' => 'CARD-NO-PRODUCT',
        'product_name' => 'Should Not Appear Without Product Filter',
        'movement_date' => '2026-06-05',
    ]);

    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', ['tab' => 'stock-card']))
        ->assertOk()
        ->assertSee('Pilih produk terlebih dahulu untuk melihat Kartu Stok.');

    $panel = reportPanelHtml($response->getContent(), 'stock-card');

    expect($panel)
        ->not->toContain('Should Not Appear Without Product Filter')
        ->not->toContain('OPENING')
        ->not->toContain('quantity_in');
});

it('paginates mutation report results', function () {
    foreach (range(1, 18) as $index) {
        createReportStockRow(test()->branch, [
            'product_code' => 'MUT-PAGE-'.$index,
            'product_name' => 'Mutation Page Product '.$index,
            'quantity_in' => 1,
            'movement_date' => '2026-06-07',
        ]);
    }

    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'mutation',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
            'per_page' => 10,
        ]))
        ->assertOk();

    expect($response->getContent())->toContain('mutation_page=2');
});

it('keeps current stock report branch scoped after performance guard changes', function () {
    createReportStockRow(test()->branch, [
        'product_code' => 'PERF-MAIN',
        'product_name' => 'Performance Main Product',
        'quantity_in' => 3,
    ]);

    createReportStockRow(test()->otherBranch, [
        'product_code' => 'PERF-OTHER',
        'product_name' => 'Performance Other Product',
        'quantity_in' => 9,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->branch->id,
        ]))
        ->assertOk()
        ->assertSee('Performance Main Product')
        ->assertDontSee('Performance Other Product');
});

it('keeps valuation report branch scoped after performance guard changes', function () {
    createReportStockRow(test()->branch, [
        'product_code' => 'VAL-MAIN',
        'product_name' => 'Valuation Main Product',
        'quantity_in' => 2,
    ]);

    createReportStockRow(test()->otherBranch, [
        'product_code' => 'VAL-OTHER',
        'product_name' => 'Valuation Other Product',
        'quantity_in' => 8,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'valuation',
            'branch_id' => test()->branch->id,
        ]))
        ->assertOk()
        ->assertSee('Valuation Main Product')
        ->assertDontSee('Valuation Other Product');
});

it('keeps room stock report branch scoped after performance guard changes', function () {
    createReportStockRow(test()->branch, [
        'product_code' => 'ROOM-MAIN',
        'product_name' => 'Room Main Product',
        'location_name' => 'Main Room Perf',
        'quantity_in' => 2,
    ]);

    createReportStockRow(test()->otherBranch, [
        'product_code' => 'ROOM-OTHER',
        'product_name' => 'Room Other Product',
        'location_name' => 'Other Room Perf',
        'quantity_in' => 8,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'room-stock',
            'branch_id' => test()->branch->id,
        ]))
        ->assertOk()
        ->assertSee('Room Main Product')
        ->assertDontSee('Room Other Product');
});

it('keeps sprint 68.32 export parity behavior', function () {
    createReportStockRow(test()->branch, [
        'product_code' => 'EXP-PERF',
        'product_name' => 'Export Perf Product',
        'quantity_in' => 4,
    ]);

    $content = $this->actingAs(test()->user)
        ->get(route('inventory.reports.export', [
            'report_type' => 'current_stock',
            'branch_id' => test()->branch->id,
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('Export Perf Product');
});

it('does not load all six report datasets on the index page', function () {
    createReportStockRow(test()->branch, [
        'product_code' => 'TAB-ONE',
        'product_name' => 'Single Tab Product',
        'quantity_in' => 2,
    ]);

    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', ['tab' => 'current-stock']))
        ->assertOk();

    expect($response->getContent())
        ->toContain('data-report-panel="current-stock"', false)
        ->not->toContain('data-report-panel="stock-card"', false)
        ->not->toContain('data-report-panel="low-stock"', false)
        ->not->toContain('data-report-panel="mutation"', false)
        ->not->toContain('data-report-panel="valuation"', false)
        ->not->toContain('data-report-panel="room-stock"', false);
});

it('caps report per page requests at the service guard maximum', function () {
    foreach (range(1, 5) as $index) {
        createReportStockRow(test()->branch, [
            'product_code' => 'CAP-'.$index,
            'product_name' => 'Cap Product '.$index,
            'quantity_in' => 1,
        ]);
    }

    $service = app(InventoryReportService::class);

    expect($service->resolveReportPerPage(['per_page' => 500]))->toBe(100)
        ->and($service->resolveReportPerPage(['per_page' => 0]))->toBe(1)
        ->and($service->resolveReportPerPage([]))->toBe(15);
});
