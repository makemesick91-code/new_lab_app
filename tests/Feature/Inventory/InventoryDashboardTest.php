<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = userWith(['view_inventory']);
    $this->stockService = app(InventoryStockService::class);
    $this->alertService = app(InventoryAlertService::class);
});

it('displays alert KPIs on inventory dashboard', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'alert_enabled' => true,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->stockService->createOpeningStock($product->id, $location->id, 8);

    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Kartu KPI Persediaan')
        ->assertSee('Stok Kritis')
        ->assertSee('Stok Habis')
        ->assertSee('Stok Rendah')
        ->assertSee('Batch Kedaluwarsa')
        ->assertSee('Segera Kedaluwarsa')
        ->assertSee('Peringatan Stok')
        ->assertDontSee('Ringkasan Peringatan');
});

it('matches dashboard alert counts with InventoryAlertService summary', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'alert_enabled' => true,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->stockService->createOpeningStock($product->id, $location->id, 8);

    $expiredBatch = InventoryBatch::factory()->expired()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    $this->stockService->receiveStock(
        $product->id,
        $location->id,
        2,
        100,
        null,
        'dashboard expired batch',
        ['inventory_batch_id' => $expiredBatch->id],
    );

    $summary = $this->alertService->getAlertSummary();

    $response = $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk();

    $response->assertSee(format_number_id($summary['critical_stock_count']), false);
    $response->assertSee(format_number_id($summary['batch_expired_count']), false);
});

it('does not duplicate alert summary sections on dashboard', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'alert_enabled' => true,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->stockService->createOpeningStock($product->id, $location->id, 8);

    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Kartu KPI Persediaan')
        ->assertDontSee('Ringkasan Peringatan')
        ->assertDontSee('Ringkasan Nilai Persediaan')
        ->assertDontSee('Stok Menipis')
        ->assertSee('Di bawah stok minimum, di atas nol', false);
});

it('still shows inventory value on dashboard', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'average_cost' => 100,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->stockService->createOpeningStock($product->id, $location->id, 5, 100);

    $branchSummary = $this->stockService->getBranchSummary();

    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Total Nilai Persediaan')
        ->assertSee(format_currency_id($branchSummary['inventory_value']), false);
});

it('denies unauthorized users from inventory dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.dashboard'))
        ->assertForbidden();
});

it('allows manage_inventory users to view inventory dashboard alerts', function () {
    $user = userWith(['manage_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Kartu KPI Persediaan');
});

it('allows view_inventory users to view inventory dashboard alerts', function () {
    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Kartu KPI Persediaan');
});

it('shows view quick actions for view_inventory users', function () {
    $html = $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Aksi Cepat Harian Gudang')
        ->getContent();

    $panel = inventoryQuickActionsPanelHtml($html);

    expect($panel)
        ->toContain('Peringatan Stok')
        ->toContain('Analitik Persediaan')
        ->toContain('Buka Laporan Inventory')
        ->toContain(route('inventory.alerts.index'))
        ->toContain(route('inventory.analytics.index'))
        ->toContain(route('inventory.reports.index'))
        ->not->toContain('Mulai Stok Opname')
        ->not->toContain('Transfer Stok')
        ->not->toContain(route('inventory.stock-opnames.create'))
        ->not->toContain(route('inventory.stock-transfers.create'));
});

it('shows manage quick actions for manage_inventory users', function () {
    $user = userWith(['manage_inventory']);

    $html = $this->actingAs($user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Aksi Cepat Harian Gudang')
        ->getContent();

    $panel = inventoryQuickActionsPanelHtml($html);

    expect($panel)
        ->toContain('Peringatan Stok')
        ->toContain('Analitik Persediaan')
        ->toContain('Mulai Stok Opname')
        ->toContain('Transfer Stok')
        ->toContain('Terima Barang')
        ->toContain(route('inventory.stock-opnames.create'))
        ->toContain(route('inventory.stock-transfers.create'))
        ->toContain(route('inventory.goods-receipts.create'));
});

it('enforces branch isolation on dashboard alert counts', function () {
    $otherBranch = Branch::factory()->create();

    [$product, $location] = [Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'alert_enabled' => true,
    ]), InventoryLocation::factory()->create(['branch_id' => $this->branch->id])];

    $this->stockService->createOpeningStock($product->id, $location->id, 5);

    $otherProduct = Product::factory()->create([
        'branch_id' => $otherBranch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'alert_enabled' => true,
    ]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    InventoryMovement::factory()->opening()->create([
        'branch_id' => $otherBranch->id,
        'inventory_location_id' => $otherLocation->id,
        'product_id' => $otherProduct->id,
        'quantity_in' => 5,
        'quantity_out' => 0,
    ]);

    $summary = $this->alertService->getAlertSummary();

    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee(format_number_id($summary['critical_stock_count']), false);

    expect($summary['critical_stock_count'])->toBe(1)
        ->and($this->alertService->getStockAlerts()->pluck('product_id'))->toContain($product->id)
        ->and($this->alertService->getStockAlerts()->pluck('product_id'))->not->toContain($otherProduct->id);
});

it('does not introduce mutable stock columns on products', function () {
    $columns = Schema::getColumnListing('inv_products');

    expect($columns)->not->toContain('current_stock')
        ->and($columns)->not->toContain('stock')
        ->and($columns)->not->toContain('qty_on_hand')
        ->and($columns)->not->toContain('available_stock');
});

it('returns only inventory value from getBranchSummary', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 20,
        'average_cost' => 50,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->stockService->createOpeningStock($product->id, $location->id, 5, 50);

    $summary = $this->stockService->getBranchSummary();

    expect($summary)->toHaveKeys(['inventory_value'])
        ->and($summary)->not->toHaveKey('low_stock_count')
        ->and($summary)->not->toHaveKey('out_of_stock_count')
        ->and($summary['inventory_value'])->toBeGreaterThan(0);
});
