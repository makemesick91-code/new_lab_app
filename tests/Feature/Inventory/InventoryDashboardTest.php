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

it('displays alert summary on inventory dashboard', function () {
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
        ->assertSee('Ringkasan Peringatan')
        ->assertSee('Stok Kritis')
        ->assertSee('Stok Habis')
        ->assertSee('Stok Rendah')
        ->assertSee('Batch Kedaluwarsa')
        ->assertSee('Segera Kedaluwarsa')
        ->assertSee('Peringatan Stok');
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

it('does not show legacy Stok Menipis label on stock value card', function () {
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
        ->assertSee('Ringkasan Nilai Persediaan')
        ->assertSee('Stok Rendah')
        ->assertDontSee('Stok Menipis');
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
        ->assertSee('Ringkasan Peringatan');
});

it('allows view_inventory users to view inventory dashboard alerts', function () {
    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Ringkasan Peringatan');
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
