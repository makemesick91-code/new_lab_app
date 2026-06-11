<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\LocationProductMinimum;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\InventoryReportService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('registers the inventory reports route', function () {
    expect(route('inventory.reports.index'))->toContain('/inventory/reports');
});

it('allows authorized inventory users to access the reports page', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Laporan Inventory')
        ->assertSee('Semua laporan persediaan berbasis ledger');
});

it('blocks unauthorized users from the reports page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.reports.index'))
        ->assertForbidden();
});

it('renders the report filter form', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Report Filter Product',
    ]);
    InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Report Filter Room',
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Cabang')
        ->assertSee('Tanggal Dari')
        ->assertSee('Tanggal Sampai')
        ->assertSee('Produk')
        ->assertSee('Kategori')
        ->assertSee('Lokasi/Ruangan')
        ->assertSee('Status Stok')
        ->assertSee('Tipe Movement')
        ->assertSee('Report Filter Product')
        ->assertSee('Report Filter Room')
        ->assertSee('name="product_id"', false)
        ->assertSee('name="inventory_location_id"', false);
});

it('renders all six inventory report labels and notes', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Stok Saat Ini')
        ->assertSee('Kartu Stok')
        ->assertSee('Low Stock')
        ->assertSee('Mutasi Stok')
        ->assertSee('Nilai Persediaan')
        ->assertSee('Stok per Ruangan')
        ->assertSee('Pilih produk dan periode tanggal untuk melihat kartu stok secara detail.')
        ->assertSee('Ruangan menggunakan data Lokasi Inventory.');
});

it('shows reports sidebar link for authorized inventory users', function () {
    $this->actingAs(userWith(['view dashboard', 'view_inventory']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Laporan Inventory')
        ->assertSee(route('inventory.reports.index'), false);
});

it('hides reports sidebar link for unauthorized users', function () {
    $this->actingAs(userWith(['view dashboard']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Laporan Inventory')
        ->assertDontSee(route('inventory.reports.index'), false);
});

it('renders current stock report with real ledger data', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'CUR-001',
        'product_name' => 'Current Stock Material',
        'category_name' => 'Report Ceramic',
        'unit_symbol' => 'pcs',
        'location_name' => 'Ruang Report Utama',
        'minimum_stock' => 5,
        'quantity_in' => 12,
        'quantity_out' => 3,
        'movement_date' => '2026-06-08',
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee($this->branch->name)
        ->assertSee($product->code)
        ->assertSee($product->name)
        ->assertSee('Report Ceramic')
        ->assertSee('pcs')
        ->assertSee($location->name)
        ->assertSee('9.0000')
        ->assertSee('5.0000')
        ->assertSee('Normal')
        ->assertSee('08 Jun 2026');
});

it('calculates current stock as sum quantity in minus quantity out', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'CALC-001',
        'product_name' => 'Calculated Stock',
        'minimum_stock' => 1,
        'quantity_in' => 10,
        'quantity_out' => 4,
    ]);

    InventoryMovement::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => Product::where('code', 'CALC-001')->firstOrFail()->id,
        'inventory_location_id' => InventoryLocation::where('name', 'Report Room CALC-001')->firstOrFail()->id,
        'supplier_id' => null,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
        'movement_date' => '2026-06-07',
        'quantity_in' => 2,
        'quantity_out' => 0,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('8.0000');
});

it('groups current stock by branch product and inventory location', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'GRP-001',
        'name' => 'Grouped Product',
        'minimum_stock' => 1,
    ]);
    $roomA = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Grouped Room A',
    ]);
    $roomB = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Grouped Room B',
    ]);

    createReportMovement($this->branch, $product, $roomA, 5, 1);
    createReportMovement($this->branch, $product, $roomB, 8, 2);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('Grouped Room A')
        ->assertSee('4.0000')
        ->assertSee('Grouped Room B')
        ->assertSee('6.0000');
});

it('respects branch isolation for current stock report', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'SAFE-001',
        'product_name' => 'Visible Branch Product',
        'quantity_in' => 4,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-REPORT', 'name' => 'Other Report Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'LEAK-001',
        'product_name' => 'Hidden Branch Product',
        'quantity_in' => 99,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['branch_id' => $otherBranch->id]))
        ->assertOk()
        ->assertSee('Visible Branch Product')
        ->assertDontSee('Hidden Branch Product')
        ->assertDontSee('99.0000');
});

it('filters current stock report by product', function () {
    [$target] = createReportStockRow($this->branch, [
        'product_code' => 'PROD-001',
        'product_name' => 'Filtered Product Target',
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'PROD-002',
        'product_name' => 'Filtered Product Hidden',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['product_id' => $target->id]))
        ->assertOk()
        ->assertSee('Filtered Product Target');

    $section = currentStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Filtered Product Target', $section);
    $this->assertStringNotContainsString('Filtered Product Hidden', $section);
});

it('filters current stock report by category', function () {
    $targetCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Target Report Category',
    ]);
    $otherCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Other Report Category',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'CAT-001',
        'product_name' => 'Category Target Product',
        'category_id' => $targetCategory->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'CAT-002',
        'product_name' => 'Category Hidden Product',
        'category_id' => $otherCategory->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['category_id' => $targetCategory->id]))
        ->assertOk()
        ->assertSee('Category Target Product');

    $section = currentStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Category Target Product', $section);
    $this->assertStringNotContainsString('Category Hidden Product', $section);
});

it('filters current stock report by inventory location', function () {
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Target Report Location',
    ]);
    $otherLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Other Report Location',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'LOC-001',
        'product_name' => 'Location Target Product',
        'location_id' => $targetLocation->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'LOC-002',
        'product_name' => 'Location Hidden Product',
        'location_id' => $otherLocation->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['inventory_location_id' => $targetLocation->id]))
        ->assertOk()
        ->assertSee('Location Target Product');

    $section = currentStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Location Target Product', $section);
    $this->assertStringNotContainsString('Location Hidden Product', $section);
});

it('shows empty status when current stock is zero or below', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'EMPTY-001',
        'product_name' => 'Empty Stock Product',
        'minimum_stock' => 3,
        'quantity_in' => 2,
        'quantity_out' => 2,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['stock_status' => 'empty']))
        ->assertOk()
        ->assertSee('Empty Stock Product')
        ->assertSee('Kosong')
        ->assertSee('0.0000');
});

it('shows low stock status when current stock is below minimum stock', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'LOW-001',
        'product_name' => 'Low Stock Product',
        'minimum_stock' => 10,
        'quantity_in' => 7,
        'quantity_out' => 0,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['stock_status' => 'low']))
        ->assertOk()
        ->assertSee('Low Stock Product')
        ->assertSee('Low Stock')
        ->assertSee('7.0000');
});

it('shows normal status when current stock is at or above minimum stock', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'NORMAL-001',
        'product_name' => 'Normal Stock Product',
        'minimum_stock' => 10,
        'quantity_in' => 10,
        'quantity_out' => 0,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['stock_status' => 'normal']))
        ->assertOk()
        ->assertSee('Normal Stock Product')
        ->assertSee('Normal')
        ->assertSee('10.0000');
});

it('paginates the current stock report', function () {
    for ($i = 1; $i <= 16; $i++) {
        createReportStockRow($this->branch, [
            'product_code' => sprintf('PAGE-%03d', $i),
            'product_name' => sprintf('Paginated Product %03d', $i),
            'quantity_in' => $i,
        ]);
    }

    $report = app(InventoryReportService::class)->getCurrentStockReport(['per_page' => 15]);

    expect($report->total())->toBe(16)
        ->and($report->perPage())->toBe(15)
        ->and($report->count())->toBe(15);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index'))
        ->assertOk()
        ->assertSee('current_stock_page=2', false);
});

it('renders the stock card tab shell', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'stock_card']))
        ->assertOk()
        ->assertSee('Kartu Stok')
        ->assertSee('Saldo Awal')
        ->assertSee('Periode Aktif');
});

it('requires product selection before loading stock card movements', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'SC-REQ',
        'product_name' => 'Unselected Stock Card Product',
        'quantity_in' => 14,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'stock_card']))
        ->assertOk()
        ->assertSee('Pilih produk untuk melihat kartu stok.')
        ->assertSee('Export kartu stok membutuhkan filter produk.');

    $section = stockCardReportSectionHtml($response->getContent());

    $this->assertStringNotContainsString('Unselected Stock Card Product', $section);
    $this->assertStringNotContainsString('14.0000', $section);
});

it('shows stock card movement rows when product is selected', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'SC-ROW',
        'product_name' => 'Stock Card Row Product',
        'location_name' => 'Stock Card Room',
        'quantity_in' => 5,
        'movement_date' => '2026-06-05',
        'notes' => 'Initial row note',
        'reference_type' => 'manual',
        'reference_id' => 77,
        'creator_name' => 'Stock Clerk',
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee($product->code)
        ->assertSee($product->name)
        ->assertSee($location->name)
        ->assertSee('OPENING')
        ->assertSee('5.0000')
        ->assertSee('manual #77')
        ->assertSee('Stock Clerk')
        ->assertSee('Initial row note');
});

it('keeps stock card scoped to the active branch', function () {
    [$visibleProduct] = createReportStockRow($this->branch, [
        'product_code' => 'SC-SAFE',
        'product_name' => 'Visible Stock Card Product',
        'quantity_in' => 3,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-SC', 'name' => 'Other Stock Card Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'SC-LEAK',
        'product_name' => 'Hidden Stock Card Product',
        'quantity_in' => 88,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $visibleProduct->id,
            'branch_id' => $otherBranch->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('Visible Stock Card Product');

    $section = stockCardReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Visible Stock Card Product', $section);
    $this->assertStringNotContainsString('Hidden Stock Card Product', $section);
    $this->assertStringNotContainsString('88.0000', $section);
});

it('filters stock card by inventory location', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'SC-LOC',
        'name' => 'Stock Card Location Product',
    ]);
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Stock Card Target Room',
    ]);
    $otherLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Stock Card Hidden Room',
    ]);
    createReportMovement($this->branch, $product, $targetLocation, 6, 0, '2026-06-05');
    createReportMovement($this->branch, $product, $otherLocation, 9, 0, '2026-06-06');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'inventory_location_id' => $targetLocation->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $section = stockCardReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Stock Card Target Room', $section);
    $this->assertStringContainsString('6.0000', $section);
    $this->assertStringNotContainsString('Stock Card Hidden Room', $section);
    $this->assertStringNotContainsString('9.0000', $section);
});

it('filters stock card by date range', function () {
    [$product] = createReportStockRow($this->branch, [
        'product_code' => 'SC-DATE',
        'product_name' => 'Stock Card Date Product',
        'quantity_in' => 5,
        'movement_date' => '2026-06-10',
        'notes' => 'Inside period movement',
    ]);
    createReportMovement(
        $this->branch,
        $product,
        InventoryLocation::where('name', 'Report Room SC-DATE')->firstOrFail(),
        7,
        0,
        '2026-05-10',
        ['notes' => 'Before period movement'],
    );

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $section = stockCardReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Inside period movement', $section);
    $this->assertStringNotContainsString('Before period movement', $section);
});

it('filters stock card by movement type', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'SC-TYPE',
        'name' => 'Stock Card Type Product',
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    createReportMovement($this->branch, $product, $location, 10, 0, '2026-06-05', [
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'notes' => 'Purchase movement note',
    ]);
    createReportMovement($this->branch, $product, $location, 0, 2, '2026-06-06', [
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'notes' => 'Adjustment out movement note',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'movement_type' => InventoryMovement::TYPE_PURCHASE,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $section = stockCardReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Purchase movement note', $section);
    $this->assertStringContainsString(InventoryMovement::TYPE_PURCHASE, $section);
    $this->assertStringNotContainsString('Adjustment out movement note', $section);
});

it('calculates stock card opening balance before date from', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'SC-OPEN',
        'name' => 'Stock Card Opening Product',
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    createReportMovement($this->branch, $product, $location, 10, 0, '2026-05-01');
    createReportMovement($this->branch, $product, $location, 0, 3, '2026-05-20');
    createReportMovement($this->branch, $product, $location, 5, 0, '2026-06-05');

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('7.0000')
        ->assertSee('12.0000');
});

it('calculates stock card running balance chronologically', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'SC-RUN',
        'name' => 'Stock Card Running Product',
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    createReportMovement($this->branch, $product, $location, 10, 0, '2026-05-01');
    createReportMovement($this->branch, $product, $location, 0, 2, '2026-06-02', ['notes' => 'First period movement']);
    createReportMovement($this->branch, $product, $location, 5, 0, '2026-06-03', ['notes' => 'Second period movement']);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $section = stockCardReportSectionHtml($response->getContent());

    $this->assertStringContainsString('8.0000', $section);
    $this->assertStringContainsString('13.0000', $section);
    $this->assertLessThan(
        strpos($section, 'Second period movement'),
        strpos($section, 'First period movement'),
    );
});

it('paginates stock card with stock card page parameter', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'SC-PAGE',
        'name' => 'Stock Card Paginated Product',
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    for ($i = 1; $i <= 16; $i++) {
        createReportMovement($this->branch, $product, $location, 1, 0, sprintf('2026-06-%02d', $i));
    }

    $report = app(InventoryReportService::class)->getStockCardReport([
        'product_id' => $product->id,
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        'per_page' => 15,
    ]);

    expect($report['rows']->total())->toBe(16)
        ->and($report['rows']->perPage())->toBe(15)
        ->and($report['rows']->count())->toBe(15);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('stock_card_page=2', false);
});

it('shows stock card empty state when selected period has no movement', function () {
    [$product] = createReportStockRow($this->branch, [
        'product_code' => 'SC-EMPTY',
        'product_name' => 'Stock Card Empty Product',
        'quantity_in' => 4,
        'movement_date' => '2026-05-01',
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'stock_card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('Tidak ada pergerakan stok pada periode yang dipilih.');
});

it('renders the low stock report section', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'low_stock']))
        ->assertOk()
        ->assertSee('Low Stock')
        ->assertSee('Kekurangan')
        ->assertSee('Rekomendasi')
        ->assertSee('Tidak ada barang low stock untuk filter yang dipilih.');
});

it('renders low stock report with real ledger data', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'LS-REAL',
        'product_name' => 'Low Stock Real Product',
        'category_name' => 'Low Stock Category',
        'unit_symbol' => 'box',
        'location_name' => 'Low Stock Room',
        'minimum_stock' => 10,
        'quantity_in' => 4,
        'movement_date' => '2026-06-08',
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'low_stock']))
        ->assertOk()
        ->assertSee($this->branch->name)
        ->assertSee($product->code)
        ->assertSee($product->name)
        ->assertSee('Low Stock Category')
        ->assertSee('box')
        ->assertSee($location->name)
        ->assertSee('4.0000')
        ->assertSee('10.0000')
        ->assertSee('6.0000')
        ->assertSee('Low Stock')
        ->assertSee('Perlu restock')
        ->assertSee('08 Jun 2026');
});

it('includes products below minimum in low stock report', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'LS-BELOW',
        'product_name' => 'Below Minimum Product',
        'minimum_stock' => 12,
        'quantity_in' => 5,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'low_stock']))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Below Minimum Product', $section);
    $this->assertStringContainsString('Low Stock', $section);
});

it('excludes products with normal stock from low stock report', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'LS-NORMAL',
        'product_name' => 'Normal Low Report Hidden Product',
        'minimum_stock' => 5,
        'quantity_in' => 8,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'low_stock']))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringNotContainsString('Normal Low Report Hidden Product', $section);
});

it('includes zero stock products as kosong in low stock report', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'LS-ZERO',
        'product_name' => 'Zero Stock Product',
        'minimum_stock' => 7,
        'quantity_in' => 3,
    ]);
    createReportMovement($this->branch, $product, $location, 0, 3, '2026-06-07');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'stock_status' => 'empty',
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Zero Stock Product', $section);
    $this->assertStringContainsString('Kosong', $section);
    $this->assertStringContainsString('7.0000', $section);
});

it('includes negative stock products as kosong in low stock report', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'LS-NEG',
        'product_name' => 'Negative Stock Product',
        'minimum_stock' => 6,
        'quantity_in' => 2,
    ]);
    createReportMovement($this->branch, $product, $location, 0, 5, '2026-06-07');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'stock_status' => 'empty',
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Negative Stock Product', $section);
    $this->assertStringContainsString('Kosong', $section);
    $this->assertStringContainsString('-3.0000', $section);
    $this->assertStringContainsString('9.0000', $section);
});

it('calculates low stock shortage quantity correctly', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'LS-SHORT',
        'product_name' => 'Shortage Product',
        'minimum_stock' => 15,
        'quantity_in' => 4,
    ]);

    $report = app(InventoryReportService::class)->getLowStockReport(['per_page' => 15]);
    $row = $report->getCollection()->firstWhere('product_code', 'LS-SHORT');

    expect((float) $row->shortage_qty)->toBe(11.0);
});

it('keeps low stock report scoped to the active branch', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'LS-SAFE',
        'product_name' => 'Visible Low Branch Product',
        'minimum_stock' => 10,
        'quantity_in' => 1,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-LS', 'name' => 'Other Low Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'LS-LEAK',
        'product_name' => 'Hidden Low Branch Product',
        'minimum_stock' => 100,
        'quantity_in' => 1,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'branch_id' => $otherBranch->id,
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Visible Low Branch Product', $section);
    $this->assertStringNotContainsString('Hidden Low Branch Product', $section);
    $this->assertStringNotContainsString('99.0000', $section);
});

it('does not show another branch product in low stock report', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTHER-LSP', 'name' => 'Other Product Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'LS-OTHER',
        'product_name' => 'Other Branch Low Product',
        'minimum_stock' => 50,
        'quantity_in' => 1,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'low_stock']))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringNotContainsString('Other Branch Low Product', $section);
});

it('filters low stock report by product', function () {
    [$target] = createReportStockRow($this->branch, [
        'product_code' => 'LS-PROD-1',
        'product_name' => 'Low Product Target',
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'LS-PROD-2',
        'product_name' => 'Low Product Hidden',
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'product_id' => $target->id,
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Low Product Target', $section);
    $this->assertStringNotContainsString('Low Product Hidden', $section);
});

it('filters low stock report by category', function () {
    $targetCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Low Target Category',
    ]);
    $otherCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Low Other Category',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'LS-CAT-1',
        'product_name' => 'Low Category Target',
        'category_id' => $targetCategory->id,
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'LS-CAT-2',
        'product_name' => 'Low Category Hidden',
        'category_id' => $otherCategory->id,
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'category_id' => $targetCategory->id,
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Low Category Target', $section);
    $this->assertStringNotContainsString('Low Category Hidden', $section);
});

it('filters low stock report by inventory location', function () {
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Low Target Location',
    ]);
    $otherLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Low Hidden Location',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'LS-LOC-1',
        'product_name' => 'Low Location Target',
        'location_id' => $targetLocation->id,
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'LS-LOC-2',
        'product_name' => 'Low Location Hidden',
        'location_id' => $otherLocation->id,
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'inventory_location_id' => $targetLocation->id,
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Low Location Target', $section);
    $this->assertStringNotContainsString('Low Location Hidden', $section);
});

it('filters low stock report to empty rows only', function () {
    [$emptyProduct, $emptyLocation] = createReportStockRow($this->branch, [
        'product_code' => 'LS-EMPTY-ONLY',
        'product_name' => 'Empty Only Product',
        'minimum_stock' => 10,
        'quantity_in' => 3,
    ]);
    createReportMovement($this->branch, $emptyProduct, $emptyLocation, 0, 3, '2026-06-07');

    createReportStockRow($this->branch, [
        'product_code' => 'LS-LOW-HIDDEN',
        'product_name' => 'Low Hidden By Empty Filter',
        'minimum_stock' => 10,
        'quantity_in' => 4,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'stock_status' => 'empty',
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Empty Only Product', $section);
    $this->assertStringNotContainsString('Low Hidden By Empty Filter', $section);
});

it('filters low stock report to low rows only', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'LS-LOW-ONLY',
        'product_name' => 'Low Only Product',
        'minimum_stock' => 10,
        'quantity_in' => 4,
    ]);
    [$emptyProduct, $emptyLocation] = createReportStockRow($this->branch, [
        'product_code' => 'LS-EMPTY-HIDDEN',
        'product_name' => 'Empty Hidden By Low Filter',
        'minimum_stock' => 10,
        'quantity_in' => 3,
    ]);
    createReportMovement($this->branch, $emptyProduct, $emptyLocation, 0, 3, '2026-06-07');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'stock_status' => 'low',
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Low Only Product', $section);
    $this->assertStringNotContainsString('Empty Hidden By Low Filter', $section);
});

it('shows low stock recommendation from same branch source locations', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'LS-REC',
        'name' => 'Recommendation Product',
        'minimum_stock' => 10,
    ]);
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Recommendation Chairside Room',
    ]);
    $warehouse = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Gudang Utama',
    ]);

    createReportMovement($this->branch, $product, $targetLocation, 3, 0);
    createReportMovement($this->branch, $product, $warehouse, 20, 0);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'inventory_location_id' => $targetLocation->id,
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Recommendation Product', $section);
    $this->assertStringContainsString('Perlu restock - Refill dari Gudang Utama', $section);
});

it('recommends transfer when another same branch location has surplus stock', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'LS-XFER',
        'name' => 'Same Branch Transfer Product',
        'minimum_stock' => 10,
    ]);
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Transfer Target Room',
    ]);
    $sourceLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Sterile Storage Room',
    ]);

    createReportMovement($this->branch, $product, $targetLocation, 2, 0);
    createReportMovement($this->branch, $product, $sourceLocation, 15, 0);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'low_stock',
            'inventory_location_id' => $targetLocation->id,
        ]))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Same Branch Transfer Product', $section);
    $this->assertStringContainsString('Perlu restock - Pertimbangkan transfer dari lokasi lain', $section);
});

it('does not recommend transfer from another branch', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'LS-NOXFER',
        'product_name' => 'No Cross Branch Transfer Product',
        'minimum_stock' => 10,
        'quantity_in' => 1,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-XFER', 'name' => 'Other Transfer Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'LS-NOXFER',
        'product_name' => 'Other Source Product',
        'location_name' => 'Gudang Utama',
        'unit_symbol' => 'lsnoxferother',
        'minimum_stock' => 10,
        'quantity_in' => 50,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'low_stock']))
        ->assertOk();

    $section = lowStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('No Cross Branch Transfer Product', $section);
    $this->assertStringContainsString('Buat permintaan pembelian', $section);
    $this->assertStringNotContainsString('Refill dari Gudang Utama', $section);
    $this->assertStringNotContainsString('Pertimbangkan transfer dari lokasi lain', $section);
});

it('paginates low stock report with low stock page parameter', function () {
    for ($i = 1; $i <= 16; $i++) {
        createReportStockRow($this->branch, [
            'product_code' => sprintf('LS-PAGE-%03d', $i),
            'product_name' => sprintf('Low Paginated Product %03d', $i),
            'minimum_stock' => 10,
            'quantity_in' => 1,
        ]);
    }

    $report = app(InventoryReportService::class)->getLowStockReport(['per_page' => 15]);

    expect($report->total())->toBe(16)
        ->and($report->perPage())->toBe(15)
        ->and($report->count())->toBe(15);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'low_stock']))
        ->assertOk()
        ->assertSee('low_stock_page=2', false);
});

it('renders the stock mutation report section', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'mutation']))
        ->assertOk()
        ->assertSee('Mutasi Stok')
        ->assertSee('Saldo Awal')
        ->assertSee('Saldo Akhir')
        ->assertSee('Tidak ada mutasi stok pada periode yang dipilih.');
});

it('renders stock mutation report with real ledger data', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'MUT-REAL',
        'product_name' => 'Mutation Real Product',
        'category_name' => 'Mutation Category',
        'unit_symbol' => 'mut',
        'location_name' => 'Mutation Room',
        'quantity_in' => 12,
        'quantity_out' => 0,
        'movement_date' => '2026-06-04',
    ]);
    createReportMovement($this->branch, $product, $location, 0, 2, '2026-06-05');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'mutation',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString($this->branch->name, $section);
    $this->assertStringContainsString('MUT-REAL', $section);
    $this->assertStringContainsString('Mutation Real Product', $section);
    $this->assertStringContainsString('Mutation Category', $section);
    $this->assertStringContainsString('mut', $section);
    $this->assertStringContainsString('Mutation Room', $section);
    $this->assertStringContainsString('12.0000', $section);
    $this->assertStringContainsString('2.0000', $section);
    $this->assertStringContainsString('10.0000', $section);
    $this->assertStringContainsString('2026-06-01 - 2026-06-30', $section);
});

it('applies default current month dates to stock mutation report', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'MUT-DEFAULT',
        'product_name' => 'Default Month Mutation Product',
        'quantity_in' => 5,
        'movement_date' => '2026-06-03',
    ]);
    createReportMovement($this->branch, $product, $location, 9, 0, '2026-05-20');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'mutation']))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('01 Jun 2026', $section);
    $this->assertStringContainsString('08 Jun 2026', $section);
    $this->assertStringContainsString('Default Month Mutation Product', $section);
    $this->assertStringContainsString('9.0000', $section);
    $this->assertStringContainsString('5.0000', $section);
    $this->assertStringContainsString('14.0000', $section);
});

it('calculates stock mutation opening in out and ending balances', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'MUT-CALC',
        'name' => 'Mutation Calculation Product',
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    createReportMovement($this->branch, $product, $location, 20, 0, '2026-05-01');
    createReportMovement($this->branch, $product, $location, 0, 4, '2026-05-20');
    createReportMovement($this->branch, $product, $location, 8, 0, '2026-06-03');
    createReportMovement($this->branch, $product, $location, 0, 5, '2026-06-04');

    $report = app(InventoryReportService::class)->getStockMutationReport([
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        'per_page' => 15,
    ]);
    $row = $report->getCollection()->firstWhere('product_code', 'MUT-CALC');

    expect((float) $row->opening_balance)->toBe(16.0)
        ->and((float) $row->total_in)->toBe(8.0)
        ->and((float) $row->total_out)->toBe(5.0)
        ->and((float) $row->ending_balance)->toBe(19.0);
});

it('groups stock mutation report by branch product and inventory location', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'MUT-GROUP',
        'name' => 'Mutation Group Product',
    ]);
    $roomA = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Mutation Group Room A',
    ]);
    $roomB = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Mutation Group Room B',
    ]);
    createReportMovement($this->branch, $product, $roomA, 4, 0, '2026-06-02');
    createReportMovement($this->branch, $product, $roomB, 7, 0, '2026-06-02');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'mutation',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Mutation Group Room A', $section);
    $this->assertStringContainsString('4.0000', $section);
    $this->assertStringContainsString('Mutation Group Room B', $section);
    $this->assertStringContainsString('7.0000', $section);
});

it('keeps stock mutation report scoped to the active branch', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'MUT-SAFE',
        'product_name' => 'Visible Mutation Product',
        'quantity_in' => 4,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-MUT', 'name' => 'Other Mutation Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'MUT-LEAK',
        'product_name' => 'Hidden Mutation Product',
        'quantity_in' => 99,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'mutation',
            'branch_id' => $otherBranch->id,
        ]))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Visible Mutation Product', $section);
    $this->assertStringNotContainsString('Hidden Mutation Product', $section);
    $this->assertStringNotContainsString('99.0000', $section);
});

it('does not show another branch product in stock mutation report', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTHER-MUT-P', 'name' => 'Other Mutation Product Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'MUT-OTHER',
        'product_name' => 'Other Branch Mutation Product',
        'quantity_in' => 44,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'mutation']))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringNotContainsString('Other Branch Mutation Product', $section);
    $this->assertStringContainsString('Tidak ada mutasi stok pada periode yang dipilih.', $section);
});

it('filters stock mutation report by product', function () {
    [$target] = createReportStockRow($this->branch, [
        'product_code' => 'MUT-PROD-1',
        'product_name' => 'Mutation Product Target',
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'MUT-PROD-2',
        'product_name' => 'Mutation Product Hidden',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'mutation',
            'product_id' => $target->id,
        ]))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Mutation Product Target', $section);
    $this->assertStringNotContainsString('Mutation Product Hidden', $section);
});

it('filters stock mutation report by category', function () {
    $targetCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Mutation Target Category',
    ]);
    $otherCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Mutation Other Category',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'MUT-CAT-1',
        'product_name' => 'Mutation Category Target',
        'category_id' => $targetCategory->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'MUT-CAT-2',
        'product_name' => 'Mutation Category Hidden',
        'category_id' => $otherCategory->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'mutation',
            'category_id' => $targetCategory->id,
        ]))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Mutation Category Target', $section);
    $this->assertStringNotContainsString('Mutation Category Hidden', $section);
});

it('filters stock mutation report by inventory location', function () {
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Mutation Target Location',
    ]);
    $otherLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Mutation Hidden Location',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'MUT-LOC-1',
        'product_name' => 'Mutation Location Target',
        'location_id' => $targetLocation->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'MUT-LOC-2',
        'product_name' => 'Mutation Location Hidden',
        'location_id' => $otherLocation->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'mutation',
            'inventory_location_id' => $targetLocation->id,
        ]))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Mutation Location Target', $section);
    $this->assertStringNotContainsString('Mutation Location Hidden', $section);
});

it('filters stock mutation period totals by movement type', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'MUT-TYPE',
        'name' => 'Mutation Type Product',
    ]);
    $location = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Mutation Type Room',
    ]);
    createReportMovement($this->branch, $product, $location, 10, 0, '2026-05-01');
    createReportMovement($this->branch, $product, $location, 5, 0, '2026-06-03', [
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
    ]);
    createReportMovement($this->branch, $product, $location, 0, 2, '2026-06-04', [
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
    ]);

    $report = app(InventoryReportService::class)->getStockMutationReport([
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'per_page' => 15,
    ]);
    $row = $report->getCollection()->firstWhere('product_code', 'MUT-TYPE');

    expect((float) $row->opening_balance)->toBe(10.0)
        ->and((float) $row->total_in)->toBe(5.0)
        ->and((float) $row->total_out)->toBe(0.0)
        ->and((float) $row->ending_balance)->toBe(15.0);
});

it('keeps stock mutation opening balance true when movement type is selected', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'MUT-OPEN-TYPE',
        'name' => 'Mutation Opening Type Product',
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    createReportMovement($this->branch, $product, $location, 10, 0, '2026-05-01', [
        'movement_type' => InventoryMovement::TYPE_OPENING,
    ]);
    createReportMovement($this->branch, $product, $location, 0, 3, '2026-05-20', [
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
    ]);
    createReportMovement($this->branch, $product, $location, 4, 0, '2026-06-03', [
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
    ]);

    $report = app(InventoryReportService::class)->getStockMutationReport([
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'per_page' => 15,
    ]);
    $row = $report->getCollection()->firstWhere('product_code', 'MUT-OPEN-TYPE');

    expect((float) $row->opening_balance)->toBe(7.0)
        ->and((float) $row->ending_balance)->toBe(11.0);
});

it('shows stock mutation empty state when no period movement exists', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'MUT-EMPTY',
        'product_name' => 'Mutation Empty Product',
        'quantity_in' => 4,
        'movement_date' => '2026-05-01',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'mutation',
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $section = stockMutationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Tidak ada mutasi stok pada periode yang dipilih.', $section);
    $this->assertStringNotContainsString('Mutation Empty Product', $section);
});

it('paginates stock mutation report with mutation page parameter', function () {
    for ($i = 1; $i <= 16; $i++) {
        createReportStockRow($this->branch, [
            'product_code' => sprintf('MUT-PAGE-%03d', $i),
            'product_name' => sprintf('Mutation Paginated Product %03d', $i),
            'quantity_in' => $i,
        ]);
    }

    $report = app(InventoryReportService::class)->getStockMutationReport(['per_page' => 15]);

    expect($report->total())->toBe(16)
        ->and($report->perPage())->toBe(15)
        ->and($report->count())->toBe(15);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'mutation']))
        ->assertOk()
        ->assertSee('mutation_page=2', false);
});

it('renders the inventory valuation report section', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk()
        ->assertSee('Nilai Persediaan')
        ->assertSee('Nilai persediaan bersifat estimasi operasional berdasarkan harga/cost produk yang tersedia.')
        ->assertSee('Tidak ada data nilai persediaan untuk filter yang dipilih.');
});

it('renders inventory valuation report with real ledger data', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'VAL-REAL',
        'product_name' => 'Valuation Real Product',
        'category_name' => 'Valuation Category',
        'unit_symbol' => 'val',
        'location_name' => 'Valuation Room',
        'quantity_in' => 10,
        'quantity_out' => 2,
        'average_cost' => 1250,
        'movement_date' => '2026-06-08',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString($this->branch->name, $section);
    $this->assertStringContainsString($product->code, $section);
    $this->assertStringContainsString($product->name, $section);
    $this->assertStringContainsString('Valuation Category', $section);
    $this->assertStringContainsString('val', $section);
    $this->assertStringContainsString($location->name, $section);
    $this->assertStringContainsString('8.0000', $section);
    $this->assertStringContainsString(format_currency_id(1250), $section);
    $this->assertStringContainsString(format_currency_id(10000), $section);
    $this->assertStringContainsString('average_cost produk', $section);
    $this->assertStringContainsString('08 Jun 2026', $section);
});

it('calculates valuation current stock as sum quantity in minus quantity out', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'VAL-STOCK',
        'product_name' => 'Valuation Stock Product',
        'quantity_in' => 9,
        'quantity_out' => 4,
        'average_cost' => 100,
    ]);
    createReportMovement($this->branch, $product, $location, 3, 1, '2026-06-07');

    $report = app(InventoryReportService::class)->getInventoryValuationReport(['per_page' => 15]);
    $row = $report->getCollection()->firstWhere('product_code', 'VAL-STOCK');

    expect((float) $row->current_stock)->toBe(7.0);
});

it('calculates valuation total value from current stock and average cost', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-TOTAL',
        'product_name' => 'Valuation Total Product',
        'quantity_in' => 12,
        'quantity_out' => 5,
        'average_cost' => 2500,
    ]);

    $report = app(InventoryReportService::class)->getInventoryValuationReport(['per_page' => 15]);
    $row = $report->getCollection()->firstWhere('product_code', 'VAL-TOTAL');

    expect((float) $row->current_stock)->toBe(7.0)
        ->and((float) $row->unit_cost)->toBe(2500.0)
        ->and((float) $row->total_value)->toBe(17500.0);
});

it('handles missing valuation unit cost safely', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-NOCOST',
        'product_name' => 'Valuation No Cost Product',
        'quantity_in' => 6,
        'quantity_out' => 0,
        'average_cost' => 0,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Valuation No Cost Product', $section);
    $this->assertStringContainsString('Cost produk tidak tersedia', $section);
    $this->assertStringContainsString(format_currency_id(0), $section);
});

it('displays valuation source from product average cost', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-SOURCE',
        'product_name' => 'Valuation Source Product',
        'average_cost' => 300,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Sumber', $section);
    $this->assertStringContainsString('average_cost produk', $section);
});

it('keeps inventory valuation report scoped to the active branch', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-SAFE',
        'product_name' => 'Visible Valuation Product',
        'quantity_in' => 4,
        'average_cost' => 100,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-VAL', 'name' => 'Other Valuation Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'VAL-LEAK',
        'product_name' => 'Hidden Valuation Product',
        'quantity_in' => 99,
        'average_cost' => 1000,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'valuation',
            'branch_id' => $otherBranch->id,
        ]))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Visible Valuation Product', $section);
    $this->assertStringNotContainsString('Hidden Valuation Product', $section);
    $this->assertStringNotContainsString('99.0000', $section);
});

it('does not show another branch product in inventory valuation report', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTHER-VAL-P', 'name' => 'Other Valuation Product Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'VAL-OTHER',
        'product_name' => 'Other Branch Valuation Product',
        'quantity_in' => 44,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringNotContainsString('Other Branch Valuation Product', $section);
    $this->assertStringContainsString('Tidak ada data nilai persediaan untuk filter yang dipilih.', $section);
});

it('filters inventory valuation report by product', function () {
    [$target] = createReportStockRow($this->branch, [
        'product_code' => 'VAL-PROD-1',
        'product_name' => 'Valuation Product Target',
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-PROD-2',
        'product_name' => 'Valuation Product Hidden',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'valuation',
            'product_id' => $target->id,
        ]))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Valuation Product Target', $section);
    $this->assertStringNotContainsString('Valuation Product Hidden', $section);
});

it('filters inventory valuation report by category', function () {
    $targetCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Valuation Target Category',
    ]);
    $otherCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Valuation Other Category',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'VAL-CAT-1',
        'product_name' => 'Valuation Category Target',
        'category_id' => $targetCategory->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-CAT-2',
        'product_name' => 'Valuation Category Hidden',
        'category_id' => $otherCategory->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'valuation',
            'category_id' => $targetCategory->id,
        ]))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Valuation Category Target', $section);
    $this->assertStringNotContainsString('Valuation Category Hidden', $section);
});

it('filters inventory valuation report by inventory location', function () {
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Valuation Target Location',
    ]);
    $otherLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Valuation Hidden Location',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'VAL-LOC-1',
        'product_name' => 'Valuation Location Target',
        'location_id' => $targetLocation->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-LOC-2',
        'product_name' => 'Valuation Location Hidden',
        'location_id' => $otherLocation->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'valuation',
            'inventory_location_id' => $targetLocation->id,
        ]))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Valuation Location Target', $section);
    $this->assertStringNotContainsString('Valuation Location Hidden', $section);
});

it('handles negative stock in inventory valuation report', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'VAL-NEG',
        'product_name' => 'Negative Valuation Product',
        'quantity_in' => 2,
        'quantity_out' => 5,
        'average_cost' => 1000,
    ]);

    $report = app(InventoryReportService::class)->getInventoryValuationReport(['per_page' => 15]);
    $row = $report->getCollection()->firstWhere('product_code', 'VAL-NEG');

    expect((float) $row->current_stock)->toBe(-3.0)
        ->and((float) $row->total_value)->toBe(-3000.0);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('-3.0000', $section);
    $this->assertStringContainsString(format_currency_id(-3000), $section);
});

it('shows inventory valuation empty state when no data exists', function () {
    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk();

    $section = inventoryValuationReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Tidak ada data nilai persediaan untuk filter yang dipilih.', $section);
});

it('paginates inventory valuation report with valuation page parameter', function () {
    for ($i = 1; $i <= 16; $i++) {
        createReportStockRow($this->branch, [
            'product_code' => sprintf('VAL-PAGE-%03d', $i),
            'product_name' => sprintf('Valuation Paginated Product %03d', $i),
            'quantity_in' => $i,
            'average_cost' => 100,
        ]);
    }

    $report = app(InventoryReportService::class)->getInventoryValuationReport(['per_page' => 15]);

    expect($report->total())->toBe(16)
        ->and($report->perPage())->toBe(15)
        ->and($report->count())->toBe(15);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'valuation']))
        ->assertOk()
        ->assertSee('valuation_page=2', false);
});

it('renders the room stock report section', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk()
        ->assertSee('Stok per Ruangan')
        ->assertSee('Ruangan menggunakan data Lokasi Inventory.')
        ->assertSee('Minimum/maksimum per ruangan diambil dari konfigurasi Minimum Stok Ruangan.')
        ->assertSee('Tidak ada stok pada ruangan yang dipilih.');
});

it('renders room stock report with real ledger data', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'ROOM-REAL',
        'product_name' => 'Room Real Product',
        'category_name' => 'Room Category',
        'unit_symbol' => 'rm',
        'location_name' => 'Ruang Dokter 1',
        'minimum_stock' => 5,
        'quantity_in' => 12,
        'quantity_out' => 3,
        'movement_date' => '2026-06-08',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString($this->branch->name, $section);
    $this->assertStringContainsString($location->name, $section);
    $this->assertStringContainsString($product->code, $section);
    $this->assertStringContainsString($product->name, $section);
    $this->assertStringContainsString('Room Category', $section);
    $this->assertStringContainsString('rm', $section);
    $this->assertStringContainsString('9.0000', $section);
    $this->assertStringContainsString('5.0000', $section);
    $this->assertStringContainsString('Normal', $section);
    $this->assertStringContainsString('Stok ruangan cukup', $section);
    $this->assertStringContainsString('08 Jun 2026', $section);
});

it('groups room stock by branch location and product', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'ROOM-GROUP',
        'name' => 'Room Group Product',
        'minimum_stock' => 1,
    ]);
    $roomA = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Room Group A',
    ]);
    $roomB = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Room Group B',
    ]);

    createReportMovement($this->branch, $product, $roomA, 5, 1);
    createReportMovement($this->branch, $product, $roomB, 8, 2);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Group A', $section);
    $this->assertStringContainsString('4.0000', $section);
    $this->assertStringContainsString('Room Group B', $section);
    $this->assertStringContainsString('6.0000', $section);
});

it('calculates room stock as sum quantity in minus quantity out', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'ROOM-CALC',
        'product_name' => 'Room Calculation Product',
        'quantity_in' => 10,
        'quantity_out' => 4,
    ]);
    createReportMovement($this->branch, $product, $location, 2, 1);

    $report = app(InventoryReportService::class)->getRoomStockReport(['per_page' => 15]);
    $row = $report->getCollection()->firstWhere('product_code', 'ROOM-CALC');

    expect((float) $row->current_stock)->toBe(7.0);
});

it('keeps room stock report scoped to the active branch', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-SAFE',
        'product_name' => 'Visible Room Product',
        'quantity_in' => 4,
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-ROOM', 'name' => 'Other Room Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'ROOM-LEAK',
        'product_name' => 'Hidden Room Product',
        'location_name' => 'Hidden Room Location',
        'quantity_in' => 99,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'branch_id' => $otherBranch->id,
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Visible Room Product', $section);
    $this->assertStringNotContainsString('Hidden Room Product', $section);
    $this->assertStringNotContainsString('Hidden Room Location', $section);
});

it('does not show another branch product or location in room stock report', function () {
    $otherBranch = Branch::factory()->create(['code' => 'OTHER-ROOM-P', 'name' => 'Other Room Product Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'ROOM-OTHER',
        'product_name' => 'Other Branch Room Product',
        'location_name' => 'Other Branch Room',
        'quantity_in' => 44,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringNotContainsString('Other Branch Room Product', $section);
    $this->assertStringNotContainsString('Other Branch Room', $section);
    $this->assertStringContainsString('Tidak ada stok pada ruangan yang dipilih.', $section);
});

it('filters room stock report by inventory location', function () {
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Room Target Location',
    ]);
    $otherLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Room Hidden Location',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-LOC-1',
        'product_name' => 'Room Location Target',
        'location_id' => $targetLocation->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-LOC-2',
        'product_name' => 'Room Location Hidden',
        'location_id' => $otherLocation->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'inventory_location_id' => $targetLocation->id,
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Location Target', $section);
    $this->assertStringNotContainsString('Room Location Hidden', $section);
});

it('filters room stock report by product', function () {
    [$target] = createReportStockRow($this->branch, [
        'product_code' => 'ROOM-PROD-1',
        'product_name' => 'Room Product Target',
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-PROD-2',
        'product_name' => 'Room Product Hidden',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'product_id' => $target->id,
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Product Target', $section);
    $this->assertStringNotContainsString('Room Product Hidden', $section);
});

it('filters room stock report by category', function () {
    $targetCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Room Target Category',
    ]);
    $otherCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Room Other Category',
    ]);

    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-CAT-1',
        'product_name' => 'Room Category Target',
        'category_id' => $targetCategory->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-CAT-2',
        'product_name' => 'Room Category Hidden',
        'category_id' => $otherCategory->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'category_id' => $targetCategory->id,
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Category Target', $section);
    $this->assertStringNotContainsString('Room Category Hidden', $section);
});

it('filters room stock report to empty rows only', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-EMPTY-ONLY',
        'product_name' => 'Room Empty Only Product',
        'minimum_stock' => 3,
        'quantity_in' => 2,
        'quantity_out' => 2,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-LOW-HIDDEN',
        'product_name' => 'Room Low Hidden Product',
        'minimum_stock' => 10,
        'quantity_in' => 7,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'stock_status' => 'empty',
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Empty Only Product', $section);
    $this->assertStringContainsString('Kosong', $section);
    $this->assertStringNotContainsString('Room Low Hidden Product', $section);
});

it('filters room stock report to low rows only', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-LOW-ONLY',
        'product_name' => 'Room Low Only Product',
        'minimum_stock' => 10,
        'quantity_in' => 7,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-NORMAL-HIDDEN',
        'product_name' => 'Room Normal Hidden Product',
        'minimum_stock' => 10,
        'quantity_in' => 10,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'stock_status' => 'low',
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Low Only Product', $section);
    $this->assertStringContainsString('Low Stock', $section);
    $this->assertStringNotContainsString('Room Normal Hidden Product', $section);
});

it('filters room stock report to normal rows only', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-NORMAL-ONLY',
        'product_name' => 'Room Normal Only Product',
        'minimum_stock' => 10,
        'quantity_in' => 10,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-EMPTY-HIDDEN',
        'product_name' => 'Room Empty Hidden Product',
        'minimum_stock' => 3,
        'quantity_in' => 1,
        'quantity_out' => 1,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'stock_status' => 'normal',
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Normal Only Product', $section);
    $this->assertStringContainsString('Normal', $section);
    $this->assertStringNotContainsString('Room Empty Hidden Product', $section);
});

it('returns empty room stock report for overstock filter when no maximum stock field exists', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-OVERSTOCK',
        'product_name' => 'Room Overstock Filter Product',
        'quantity_in' => 99,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'stock_status' => 'overstock',
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Tidak ada stok pada ruangan yang dipilih.', $section);
    $this->assertStringNotContainsString('Room Overstock Filter Product', $section);
});

it('shows room stock status labels for low empty and normal stock', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-STATUS-LOW',
        'product_name' => 'Room Status Low Product',
        'minimum_stock' => 10,
        'quantity_in' => 7,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-STATUS-EMPTY',
        'product_name' => 'Room Status Empty Product',
        'minimum_stock' => 3,
        'quantity_in' => 2,
        'quantity_out' => 2,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-STATUS-NORMAL',
        'product_name' => 'Room Status Normal Product',
        'minimum_stock' => 10,
        'quantity_in' => 10,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Status Low Product', $section);
    $this->assertStringContainsString('Low Stock', $section);
    $this->assertStringContainsString('Room Status Empty Product', $section);
    $this->assertStringContainsString('Kosong', $section);
    $this->assertStringContainsString('Room Status Normal Product', $section);
    $this->assertStringContainsString('Normal', $section);
});

it('shows room stock refill recommendation', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-RECO',
        'product_name' => 'Room Recommendation Product',
        'minimum_stock' => 10,
        'quantity_in' => 4,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Perlu refill - Buat permintaan pembelian', $section);
});

it('prefers same branch main warehouse for room stock refill recommendation', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'ROOM-WH',
        'name' => 'Room Warehouse Recommendation Product',
        'minimum_stock' => 10,
    ]);
    $room = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Tindakan',
    ]);
    $warehouse = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Gudang Utama',
    ]);
    createReportMovement($this->branch, $product, $room, 2, 0);
    createReportMovement($this->branch, $product, $warehouse, 30, 0);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'inventory_location_id' => $room->id,
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Warehouse Recommendation Product', $section);
    $this->assertStringContainsString('Perlu refill - Refill dari Gudang Utama', $section);
});

it('suggests same branch transfer when another room has surplus stock', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'ROOM-XFER',
        'name' => 'Room Transfer Recommendation Product',
        'minimum_stock' => 10,
    ]);
    $room = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Radiologi',
    ]);
    $source = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Lab',
    ]);
    createReportMovement($this->branch, $product, $room, 3, 0);
    createReportMovement($this->branch, $product, $source, 15, 0);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'inventory_location_id' => $room->id,
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room Transfer Recommendation Product', $section);
    $this->assertStringContainsString('Perlu refill - Pertimbangkan transfer dari lokasi lain', $section);
});

it('does not recommend cross branch refill source for room stock', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'ROOM-NOCROSS',
        'name' => 'Room No Cross Branch Product',
        'minimum_stock' => 10,
    ]);
    $room = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Sterilisasi',
    ]);
    createReportMovement($this->branch, $product, $room, 2, 0);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-ROOM-SRC', 'name' => 'Other Room Source Branch']);
    $otherProduct = Product::factory()->create([
        'branch_id' => $otherBranch->id,
        'code' => 'ROOM-NOCROSS',
        'name' => 'Room No Cross Branch Product',
        'minimum_stock' => 10,
    ]);
    $otherWarehouse = InventoryLocation::factory()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Gudang Utama',
    ]);
    createReportMovement($otherBranch, $otherProduct, $otherWarehouse, 99, 0);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Room No Cross Branch Product', $section);
    $this->assertStringContainsString('Perlu refill - Buat permintaan pembelian', $section);
    $this->assertStringNotContainsString('Refill dari Gudang Utama', $section);
});

it('shows room stock empty state for selected room with no data', function () {
    $emptyRoom = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Kosong Report',
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-NOT-EMPTY',
        'product_name' => 'Room Not Empty Product',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'report_tab' => 'room_stock',
            'inventory_location_id' => $emptyRoom->id,
        ]))
        ->assertOk();

    $section = roomStockReportSectionHtml($response->getContent());

    $this->assertStringContainsString('Tidak ada stok pada ruangan yang dipilih.', $section);
    $this->assertStringNotContainsString('Room Not Empty Product', $section);
});

it('paginates room stock report with room stock page parameter', function () {
    for ($i = 1; $i <= 16; $i++) {
        createReportStockRow($this->branch, [
            'product_code' => sprintf('ROOM-PAGE-%03d', $i),
            'product_name' => sprintf('Room Paginated Product %03d', $i),
            'quantity_in' => $i,
        ]);
    }

    $report = app(InventoryReportService::class)->getRoomStockReport(['per_page' => 15]);

    expect($report->total())->toBe(16)
        ->and($report->perPage())->toBe(15)
        ->and($report->count())->toBe(15);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', ['report_tab' => 'room_stock']))
        ->assertOk()
        ->assertSee('room_stock_page=2', false);
});

it('registers the room stock refill checklist route', function () {
    expect(route('inventory.reports.room-stock.refill-checklist'))
        ->toContain('/inventory/reports/room-stock/refill-checklist');
});

it('builds the room stock refill checklist with only below minimum and zero stock threshold items', function () {
    // Below minimum (movement netting to 4, room minimum 10) -> needs refill.
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-CHK-LOW',
        'product_name' => 'Room Checklist Low Product',
        'minimum_stock' => 10,
        'quantity_in' => 4,
    ]);
    // Normal (at minimum) -> excluded.
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-CHK-NORMAL',
        'product_name' => 'Room Checklist Normal Product',
        'minimum_stock' => 10,
        'quantity_in' => 10,
    ]);

    // Zero-stock row with a configured room threshold and NO movement -> must appear.
    $zeroLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Threshold Kosong',
    ]);
    $zeroProduct = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'ROOM-CHK-ZERO',
        'name' => 'Room Checklist Zero Threshold Product',
        'minimum_stock' => 1,
    ]);
    LocationProductMinimum::factory()->withoutMaximum()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $zeroLocation->id,
        'product_id' => $zeroProduct->id,
        'minimum_stock' => 8,
        'created_by' => null,
    ]);

    // Overstock against a configured maximum -> current stock is above minimum, excluded.
    $overLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Overstock',
    ]);
    $overProduct = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'ROOM-CHK-OVER',
        'name' => 'Room Checklist Overstock Product',
        'minimum_stock' => 5,
    ]);
    createReportMovement($this->branch, $overProduct, $overLocation, 30, 0);
    LocationProductMinimum::factory()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $overLocation->id,
        'product_id' => $overProduct->id,
        'minimum_stock' => 5,
        'maximum_stock' => 20,
        'created_by' => null,
    ]);

    $checklist = app(InventoryReportService::class)->getRoomStockRefillChecklist([]);
    $codes = $checklist->pluck('product_code')->all();

    expect($codes)->toContain('ROOM-CHK-LOW')
        ->and($codes)->toContain('ROOM-CHK-ZERO')
        ->and($codes)->not->toContain('ROOM-CHK-NORMAL')
        ->and($codes)->not->toContain('ROOM-CHK-OVER');
});

it('suggests a maximum based refill quantity in the room stock checklist', function () {
    $location = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Ruang Saran Maksimum',
    ]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'code' => 'ROOM-CHK-MAX',
        'name' => 'Room Checklist Max Refill Product',
        'minimum_stock' => 1,
    ]);
    createReportMovement($this->branch, $product, $location, 4, 0);
    LocationProductMinimum::factory()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'minimum_stock' => 10,
        'maximum_stock' => 25,
        'created_by' => null,
    ]);

    $row = app(InventoryReportService::class)
        ->getRoomStockRefillChecklist([])
        ->firstWhere('product_code', 'ROOM-CHK-MAX');

    // Refill tops the room back up to its configured maximum: 25 - 4 = 21.
    expect((float) $row->suggested_refill_qty)->toBe(21.0)
        ->and((string) $row->recommendation)->not->toBe('Stok ruangan cukup');
});

it('allows authorized users to download the room stock refill checklist pdf', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'ROOM-CHK-PDF',
        'product_name' => 'Room Checklist Pdf Product',
        'minimum_stock' => 10,
        'quantity_in' => 2,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.room-stock.refill-checklist'))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('blocks unauthorized users from the room stock refill checklist', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('inventory.reports.room-stock.refill-checklist'))
        ->assertForbidden();
});

it('registers the inventory reports export route', function () {
    expect(route('inventory.reports.export', ['report_type' => 'current_stock']))->toContain('/inventory/reports/export');
});

it('allows authorized inventory users to export current stock csv', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'EXP-CUR',
        'product_name' => 'Export Current Stock Product',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'current_stock']));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->streamedContent())->toContain('Export Current Stock Product');
});

it('blocks unauthorized users from inventory report csv export', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('inventory.reports.export', ['report_type' => 'current_stock']))
        ->assertForbidden();
});

it('rejects invalid inventory report export type', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'not_a_report']))
        ->assertInvalid('report_type');
});

it('keeps inventory report export scoped to the active branch', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'EXP-SAFE',
        'product_name' => 'Visible Export Product',
    ]);

    $otherBranch = Branch::factory()->create(['code' => 'OTHER-EXP', 'name' => 'Other Export Branch']);
    createReportStockRow($otherBranch, [
        'product_code' => 'EXP-LEAK',
        'product_name' => 'Hidden Export Product',
        'quantity_in' => 99,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', [
            'report_type' => 'current_stock',
            'branch_id' => $otherBranch->id,
        ]))
        ->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('Visible Export Product')
        ->not->toContain('Hidden Export Product')
        ->not->toContain('99.0000');
});

it('exports current stock csv with expected indonesian headers', function () {
    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'current_stock']))
        ->assertOk();

    expect($response->streamedContent())->toContain('Cabang,"Kode Produk",Produk,Kategori,Satuan,Lokasi/Ruangan,"Stok Saat Ini",Minimum,Status,"Movement Terakhir"');
});

it('exports low stock csv with recommendation column', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'EXP-LOW',
        'product_name' => 'Export Low Product',
        'minimum_stock' => 10,
        'quantity_in' => 3,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'low_stock']))
        ->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('Rekomendasi')
        ->and($content)->toContain('Export Low Product')
        ->and($content)->toContain('Perlu restock - Buat permintaan pembelian');
});

it('exports mutation csv with safe default date range', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'EXP-MUT',
        'product_name' => 'Export Mutation Product',
        'quantity_in' => 5,
        'movement_date' => '2026-06-03',
    ]);
    createReportMovement($this->branch, $product, $location, 9, 0, '2026-05-20');

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'mutation']))
        ->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('Export Mutation Product')
        ->and($content)->toContain('2026-06-01 - 2026-06-08')
        ->and($content)->toContain('9.0000')
        ->and($content)->toContain('14.0000');
});

it('exports valuation csv with total value and source columns', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'EXP-VAL',
        'product_name' => 'Export Valuation Product',
        'quantity_in' => 4,
        'average_cost' => 1000,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'valuation']))
        ->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('Total Nilai')
        ->and($content)->toContain('Sumber')
        ->and($content)->toContain('Export Valuation Product')
        ->and($content)->toContain(format_currency_id(4000))
        ->and($content)->toContain('average_cost produk');
});

it('exports room stock csv with room and refill recommendation columns', function () {
    createReportStockRow($this->branch, [
        'product_code' => 'EXP-ROOM',
        'product_name' => 'Export Room Stock Product',
        'location_name' => 'Ruang Export',
        'minimum_stock' => 10,
        'quantity_in' => 3,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'room_stock']))
        ->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('Ruangan/Lokasi')
        ->and($content)->toContain('Rekomendasi Refill')
        ->and($content)->toContain('Ruang Export')
        ->and($content)->toContain('Perlu refill - Buat permintaan pembelian');
});

it('requires product selection for stock card csv export', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', ['report_type' => 'stock_card']))
        ->assertInvalid('product_id');
});

it('exports stock card csv rows with running balance when product is selected', function () {
    [$product, $location] = createReportStockRow($this->branch, [
        'product_code' => 'EXP-SC',
        'product_name' => 'Export Stock Card Product',
        'location_name' => 'Export Stock Card Room',
        'quantity_in' => 5,
        'movement_date' => '2026-06-03',
        'reference_type' => 'manual',
        'reference_id' => 100,
        'creator_name' => 'Export Clerk',
        'notes' => 'Opening export note',
    ]);
    createReportMovement($this->branch, $product, $location, 0, 2, '2026-06-04', [
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'notes' => 'Out export note',
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', [
            'report_type' => 'stock_card',
            'product_id' => $product->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('Tanggal,Cabang,"Kode Produk",Produk,Lokasi/Ruangan,"Tipe Movement",Masuk,Keluar,Saldo,Referensi,"Dibuat Oleh",Catatan')
        ->and($content)->toContain('Export Stock Card Product')
        ->and($content)->toContain('5.0000')
        ->and($content)->toContain('3.0000')
        ->and($content)->toContain('manual #100')
        ->and($content)->toContain('Export Clerk')
        ->and($content)->toContain('Out export note');
});

it('preserves selected product location and category filters in csv export', function () {
    $targetCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Export Target Category',
    ]);
    $otherCategory = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Export Other Category',
    ]);
    $targetLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Export Target Location',
    ]);
    $otherLocation = InventoryLocation::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Export Other Location',
    ]);

    [$target] = createReportStockRow($this->branch, [
        'product_code' => 'EXP-FILTER-1',
        'product_name' => 'Export Filter Target',
        'category_id' => $targetCategory->id,
        'location_id' => $targetLocation->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'EXP-FILTER-2',
        'product_name' => 'Export Filter Hidden Location',
        'category_id' => $targetCategory->id,
        'location_id' => $otherLocation->id,
    ]);
    createReportStockRow($this->branch, [
        'product_code' => 'EXP-FILTER-3',
        'product_name' => 'Export Filter Hidden Category',
        'category_id' => $otherCategory->id,
        'location_id' => $targetLocation->id,
    ]);

    $response = $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.export', [
            'report_type' => 'current_stock',
            'product_id' => $target->id,
            'category_id' => $targetCategory->id,
            'inventory_location_id' => $targetLocation->id,
        ]))
        ->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('Export Filter Target')
        ->not->toContain('Export Filter Hidden Location')
        ->not->toContain('Export Filter Hidden Category');
});

function createReportStockRow(Branch $branch, array $overrides = []): array
{
    $category = isset($overrides['category_id'])
        ? ProductCategory::findOrFail($overrides['category_id'])
        : ProductCategory::factory()->create([
            'branch_id' => $branch->id,
            'name' => $overrides['category_name'] ?? 'Report Category',
        ]);
    $productCode = $overrides['product_code'] ?? 'RPT-'.fake()->unique()->numerify('###');
    $unit = ProductUnit::factory()->create([
        'name' => $overrides['unit_name'] ?? 'Report Unit',
        'symbol' => $overrides['unit_symbol'] ?? strtolower(str_replace('-', '', $productCode)),
    ]);
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'product_category_id' => $category->id,
        'product_unit_id' => $unit->id,
        'code' => $productCode,
        'name' => $overrides['product_name'] ?? 'Report Product '.$productCode,
        'minimum_stock' => $overrides['minimum_stock'] ?? 1,
        'average_cost' => $overrides['average_cost'] ?? 100,
    ]);
    $location = isset($overrides['location_id'])
        ? InventoryLocation::findOrFail($overrides['location_id'])
        : InventoryLocation::factory()->create([
            'branch_id' => $branch->id,
            'name' => $overrides['location_name'] ?? 'Report Room '.$productCode,
        ]);

    createReportMovement(
        $branch,
        $product,
        $location,
        $overrides['quantity_in'] ?? 5,
        $overrides['quantity_out'] ?? 0,
        $overrides['movement_date'] ?? '2026-06-06',
        $overrides,
    );

    return [$product, $location, $category, $unit];
}

function createReportMovement(
    Branch $branch,
    Product $product,
    InventoryLocation $location,
    float|int $quantityIn,
    float|int $quantityOut,
    string $movementDate = '2026-06-06',
    array $overrides = [],
): InventoryMovement {
    return InventoryMovement::factory()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
        'supplier_id' => null,
        'movement_type' => $overrides['movement_type'] ?? ($quantityOut > 0 ? InventoryMovement::TYPE_ADJUSTMENT_OUT : InventoryMovement::TYPE_OPENING),
        'movement_date' => $movementDate,
        'quantity_in' => $quantityIn,
        'quantity_out' => $quantityOut,
        'reference_type' => $overrides['reference_type'] ?? null,
        'reference_id' => $overrides['reference_id'] ?? null,
        'notes' => $overrides['notes'] ?? null,
        'created_by' => isset($overrides['creator_name'])
            ? User::factory()->create(['name' => $overrides['creator_name']])->id
            : ($overrides['created_by'] ?? null),
    ]);
}

function currentStockReportSectionHtml(string $html): string
{
    if (! preg_match('/<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Stok Saat Ini<\/h3>[\s\S]*?<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Kartu Stok<\/h3>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}

function stockCardReportSectionHtml(string $html): string
{
    if (! preg_match('/<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Kartu Stok<\/h3>[\s\S]*?<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Low Stock<\/h3>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}

function lowStockReportSectionHtml(string $html): string
{
    if (! preg_match('/<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Low Stock<\/h3>[\s\S]*?<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Mutasi Stok<\/h3>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}

function stockMutationReportSectionHtml(string $html): string
{
    if (! preg_match('/<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Mutasi Stok<\/h3>[\s\S]*?<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Nilai Persediaan<\/h3>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}

function inventoryValuationReportSectionHtml(string $html): string
{
    if (! preg_match('/<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Nilai Persediaan<\/h3>[\s\S]*?<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Stok per Ruangan<\/h3>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}

function roomStockReportSectionHtml(string $html): string
{
    if (! preg_match('/<section class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">\s*<h3 class="text-base font-semibold text-gray-900">Stok per Ruangan<\/h3>[\s\S]*?<\/section>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}
