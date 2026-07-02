<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branch->update(['is_rme_enabled' => true, 'is_inventory_enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('defaults to the current-stock tab', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('data-report-panel="current-stock"', false)
        ->assertSee('Ringkasan stok produk dari saldo ledger aktif.')
        ->assertDontSee('data-report-panel="stock-card"', false)
        ->assertDontSee('data-report-panel="mutation"', false)
        ->assertDontSee('data-report-panel="valuation"', false)
        ->assertDontSee('data-report-panel="room-stock"', false);
});

it('renders only current stock content for tab=current-stock', function () {
    [$product] = createReportStockRow($this->branch, [
        'product_code' => 'TAB-CUR',
        'product_name' => 'Tab Current Stock Product',
        'quantity_in' => 7,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['tab' => 'current-stock']))
        ->assertOk()
        ->assertSee('Tab Current Stock Product')
        ->assertSee('data-report-panel="current-stock"', false)
        ->assertDontSee('data-report-panel="stock-card"', false)
        ->assertDontSee('data-report-panel="low-stock"', false)
        ->assertDontSee('Mutasi Stok</h3>', false)
        ->assertDontSee('Nilai Persediaan</h3>', false)
        ->assertDontSee('Stok per Ruangan</h3>', false);
});

it('shows stock card empty state without product_id', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'TAB-SC-HIDDEN',
        'product_name' => 'Hidden Stock Card Product',
        'quantity_in' => 12,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['tab' => 'stock-card']))
        ->assertOk()
        ->assertSee('Pilih produk terlebih dahulu untuk melihat Kartu Stok.')
        ->assertSee('data-report-panel="stock-card"', false)
        ->assertDontSee('data-report-panel="current-stock"', false)
        ->assertDontSee('12.0000');

    expect(stockCardReportSectionHtml($response->getContent()))->not->toContain('Hidden Stock Card Product');
});

it('renders stock card rows when product is selected', function () {
    [$product] = createReportStockRow($this->branch, [
        'product_code' => 'TAB-SC-ROW',
        'product_name' => 'Tab Stock Card Product',
        'quantity_in' => 4,
        'movement_date' => '2026-06-05',
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'tab' => 'stock-card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('Tab Stock Card Product')
        ->assertSee('4.0000')
        ->assertSee('data-report-panel="stock-card"', false)
        ->assertDontSee('data-report-panel="mutation"', false);
});

it('renders only low stock content for tab=low-stock', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'TAB-LOW',
        'product_name' => 'Tab Low Stock Product',
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['tab' => 'low-stock']))
        ->assertOk()
        ->assertSee('Tab Low Stock Product')
        ->assertSee('data-report-panel="low-stock"', false)
        ->assertDontSee('data-report-panel="current-stock"', false)
        ->assertDontSee('data-report-panel="valuation"', false);
});

it('renders only mutation content with date filters for tab=mutation', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'TAB-MUT',
        'product_name' => 'Tab Mutation Product',
        'quantity_in' => 6,
        'movement_date' => '2026-06-07',
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'tab' => 'mutation',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('name="date_from"', false)
        ->assertSee('name="date_to"', false)
        ->assertSee('Tab Mutation Product')
        ->assertSee('data-report-panel="mutation"', false)
        ->assertDontSee('data-report-panel="stock-card"', false);
});

it('renders only valuation content for tab=valuation', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'TAB-VAL',
        'product_name' => 'Tab Valuation Product',
        'quantity_in' => 3,
        'average_cost' => 2500,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['tab' => 'valuation']))
        ->assertOk()
        ->assertSee('Tab Valuation Product')
        ->assertSee('data-report-panel="valuation"', false)
        ->assertDontSee('data-report-panel="mutation"', false);
});

it('renders only room stock content for tab=room-stock', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'TAB-ROOM',
        'product_name' => 'Tab Room Stock Product',
        'location_name' => 'Tab Room A',
        'quantity_in' => 5,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['tab' => 'room-stock']))
        ->assertOk()
        ->assertSee('Tab Room Stock Product')
        ->assertSee('Ruangan menggunakan data Lokasi Inventory.')
        ->assertSee('data-report-panel="room-stock"', false)
        ->assertDontSee('data-report-panel="current-stock"', false);
});

it('falls back to current-stock for invalid tab values', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['tab' => 'invalid-tab']))
        ->assertOk()
        ->assertSee('data-report-panel="current-stock"', false)
        ->assertDontSee('data-report-panel="stock-card"', false);
});

it('keeps inventory report permission unchanged', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('inventory.reports.index', ['tab' => 'mutation']))
        ->assertForbidden();

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['tab' => 'mutation']))
        ->assertOk();
});

it('preserves backward-compatible report_tab links and export query', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'mutation']))
        ->assertOk()
        ->assertSee('data-report-panel="mutation"', false)
        ->assertSee('inventory/reports/export', false)
        ->assertSee('report_type=mutation', false);
});

it('accepts legacy report_tab for stock card without product', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'stock_card']))
        ->assertOk()
        ->assertSee('Pilih produk terlebih dahulu untuk melihat Kartu Stok.')
        ->assertSee('data-report-panel="stock-card"', false);
});
