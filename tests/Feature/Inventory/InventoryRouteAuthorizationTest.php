<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

it('requires authentication for inventory routes', function () {
    $this->get(route('inventory.dashboard'))->assertRedirect(route('login'));
    $this->get(route('inventory.products.index'))->assertRedirect(route('login'));
    $this->post(route('inventory.products.store'))->assertRedirect(route('login'));
});

it('allows a permitted user to access a product in the active branch', function () {
    $user = userWith(['manage master data']);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($user)
        ->get(route('inventory.products.show', $product))
        ->assertOk();
});

it('denies access to a product from another branch', function () {
    $user = userWith(['manage master data']);
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($user)
        ->get(route('inventory.products.show', $product))
        ->assertForbidden();
});

it('creates opening stock into an active branch location', function () {
    $user = userWith(['manage master data']);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($user)
        ->post(route('inventory.products.opening-stock.store', $product), [
            'inventory_location_id' => $location->id,
            'quantity' => 12,
            'unit_cost' => 15000,
            'notes' => 'opening via route',
        ])
        ->assertRedirect(route('inventory.products.stock-card', $product));

    $this->assertDatabaseHas('trx_inventory_movements', [
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_OPENING,
        'quantity_in' => 12,
        'quantity_out' => 0,
        'unit_cost' => 15000,
    ]);
});

it('rejects stock movement using a location from another branch', function () {
    $user = userWith(['manage master data']);
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($user)
        ->from(route('inventory.products.opening-stock.create', $product))
        ->post(route('inventory.products.opening-stock.store', $product), [
            'inventory_location_id' => $otherLocation->id,
            'quantity' => 12,
            'unit_cost' => 15000,
        ])
        ->assertRedirect(route('inventory.products.opening-stock.create', $product))
        ->assertSessionHasErrors('inventory_location_id');

    $this->assertDatabaseMissing('trx_inventory_movements', [
        'inventory_location_id' => $otherLocation->id,
        'product_id' => $product->id,
    ]);
});

it('rejects receiving stock with a supplier from another branch', function () {
    $user = userWith(['manage master data']);
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherSupplier = Supplier::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs($user)
        ->from(route('inventory.products.receive-stock.create', $product))
        ->post(route('inventory.products.receive-stock.store', $product), [
            'inventory_location_id' => $location->id,
            'quantity' => 5,
            'unit_cost' => 15000,
            'supplier_id' => $otherSupplier->id,
        ])
        ->assertRedirect(route('inventory.products.receive-stock.create', $product))
        ->assertSessionHasErrors('supplier_id');

    $this->assertDatabaseMissing('trx_inventory_movements', [
        'supplier_id' => $otherSupplier->id,
        'product_id' => $product->id,
    ]);
});
