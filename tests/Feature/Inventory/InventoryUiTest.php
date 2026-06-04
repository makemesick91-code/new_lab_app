<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = userWith(['manage master data']);
});

it('opens the inventory dashboard for an authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Inventory Dashboard')
        ->assertSee('Inventory KPI Cards')
        ->assertSee('Total Inventory Value')
        ->assertSee('Inventory Value Summary')
        ->assertSee('Stock by Location')
        ->assertSee('Recent Movements')
        ->assertSee('Top Consumed Materials');
});

it('opens inventory product location supplier and stock indexes', function () {
    Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Zirconia UI Block']);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Gudang UI']);
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'PT UI Supplier']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.index'))
        ->assertOk()
        ->assertSee('Inventory Products')
        ->assertSee('Zirconia UI Block')
        ->assertSee('Branch Total Stock')
        ->assertSee('Current Stock - Branch Total')
        ->assertSee('Stock Status');

    $this->actingAs($this->user)
        ->get(route('inventory.locations.index'))
        ->assertOk()
        ->assertSee('Inventory Locations')
        ->assertSee('Gudang UI');

    $this->actingAs($this->user)
        ->get(route('inventory.suppliers.index'))
        ->assertOk()
        ->assertSee('Inventory Suppliers')
        ->assertSee('PT UI Supplier');

    $this->actingAs($this->user)
        ->get(route('inventory.stock.index'))
        ->assertOk()
        ->assertSee('Inventory Stock');
});

it('shows product detail stock summary and safe action context', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Detail Summary Product',
        'minimum_stock' => 10,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    app(InventoryStockService::class)->createOpeningStock($product->id, $location->id, 5, 100, 'detail summary');

    $this->actingAs($this->user)
        ->get(route('inventory.products.show', $product))
        ->assertOk()
        ->assertSee('Product Summary Card')
        ->assertSee('Current Stock - Branch Total')
        ->assertSee('Branch / Location Stock Clarity')
        ->assertSee('Inventory Value')
        ->assertSee('Every stock operation requires a selected Inventory Location.')
        ->assertSee('This product is below minimum stock.');
});

it('shows a required location selector on the opening stock form', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Opening Selector Location']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.opening-stock.create', $product))
        ->assertOk()
        ->assertSee('Opening Stock')
        ->assertSee('Product Summary Panel')
        ->assertSee('Create Initial Ledger Entry')
        ->assertSee('Opening Stock creates an initial ledger movement.')
        ->assertSee('Ledger-derived stock')
        ->assertSee('Inventory Location')
        ->assertSee('name="inventory_location_id"', false)
        ->assertSee('required', false)
        ->assertSee('Opening Selector Location');
});

it('shows receive stock supplier and cost guidance', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Receive Location']);
    Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Receive Supplier']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.receive-stock.create', $product))
        ->assertOk()
        ->assertSee('Receive Stock Into Location')
        ->assertSee('Receive Stock increases ledger quantity.')
        ->assertSee('Supplier')
        ->assertSee('Unit Cost')
        ->assertSee('Receive Supplier')
        ->assertSee('Capture supplier unit cost when known.');
});

it('shows adjustment out safety warning and no location disabled state', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('inventory.products.adjust-out.create', $product))
        ->assertOk()
        ->assertSee('Reduce Stock By Correction')
        ->assertSee('Adjustment Out reduces stock and cannot be treated casually.')
        ->assertSee('insufficient stock')
        ->assertSee('No active Inventory Location is available.')
        ->assertDontSee('Create Adjustment Out');
});

it('does not show inactive locations in stock operation selectors', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Selectable Active Location']);
    InventoryLocation::factory()->inactive()->create(['branch_id' => $this->branch->id, 'name' => 'Hidden Inactive Location']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.opening-stock.create', $product))
        ->assertOk()
        ->assertSee('Selectable Active Location')
        ->assertDontSee('Hidden Inactive Location');
});

it('does not allow stock operation forms for inactive products', function () {
    $product = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('inventory.products.opening-stock.create', $product))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get(route('inventory.products.show', $product))
        ->assertOk()
        ->assertDontSee('Opening Stock')
        ->assertDontSee('Receive Stock');
});

it('shows running balance on the stock card', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Balance Product']);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Balance Location']);
    $stock = app(InventoryStockService::class);

    $stock->createOpeningStock($product->id, $location->id, 10, 100, 'opening balance');
    $stock->adjustOut($product->id, $location->id, 3, 'out balance');

    $this->actingAs($this->user)
        ->get(route('inventory.products.stock-card', $product))
        ->assertOk()
        ->assertSee('Stock Card')
        ->assertSee('Ledger-derived Stock Card')
        ->assertSee('Stock is calculated from inventory movements. No mutable stock column is used.')
        ->assertSee('Inventory Location')
        ->assertSee('Movement Type')
        ->assertSee('Movement Timeline')
        ->assertSee('Running Balance')
        ->assertSee('Manual inventory movement')
        ->assertSee('No cost captured')
        ->assertSee('10.00')
        ->assertSee('7.00')
        ->assertSee('Balance Location');
});

it('shows an empty state on the stock card when no movement matches filters', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Empty Card Product']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.stock-card', $product))
        ->assertOk()
        ->assertSee('Ledger-derived Stock Card')
        ->assertSee('No stock movements match these filters.')
        ->assertSee('Opening stock, receive stock, and adjustments will appear here after they are recorded.');
});

it('does not show products or locations from another branch', function () {
    $otherBranch = Branch::factory()->create();

    Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Visible Branch Product']);
    Product::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Branch Product']);
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Visible Branch Location']);
    InventoryLocation::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Branch Location']);

    $this->actingAs($this->user)
        ->get(route('inventory.products.index'))
        ->assertOk()
        ->assertSee('Visible Branch Product')
        ->assertDontSee('Hidden Branch Product');

    $this->actingAs($this->user)
        ->get(route('inventory.locations.index'))
        ->assertOk()
        ->assertSee('Visible Branch Location')
        ->assertDontSee('Hidden Branch Location');
});

it('does not leak another branch location through the stock card filter', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($this->user)
        ->from(route('inventory.products.show', $product))
        ->get(route('inventory.products.stock-card', [
            'product' => $product,
            'inventory_location_id' => $otherLocation->id,
        ]))
        ->assertRedirect(route('inventory.products.show', $product))
        ->assertSessionHasErrors('inventory_location_id');
});

it('does not show another branch movement on the inventory dashboard', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Movement Product']);
    $location = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id, 'name' => 'Hidden Movement Location']);

    InventoryMovement::factory()->opening()->create([
        'branch_id' => $otherBranch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'supplier_id' => null,
        'quantity_in' => 5,
        'quantity_out' => 0,
    ]);

    $this->actingAs($this->user)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertDontSee('Hidden Movement Product')
        ->assertDontSee('Hidden Movement Location');
});
