<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
});

it('allows an authorized user to open the product unit index', function () {
    ProductUnit::factory()->create([
        'name' => 'Gram UI',
        'symbol' => 'gr-ui',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.product-units.index'))
        ->assertOk()
        ->assertSee('Satuan Produk Persediaan')
        ->assertSee('Gram UI')
        ->assertSee('gr-ui');
});

it('allows an authorized user to open the product unit create page', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.product-units.create'))
        ->assertOk()
        ->assertSee('Tambah Satuan Produk')
        ->assertSee('Simpan Satuan');
});

it('stores a valid product unit', function () {
    $this->actingAs($this->manager)
        ->post(route('inventory.product-units.store'), [
            'name' => 'Box',
            'symbol' => 'box',
            'description' => 'Kemasan box',
            'is_active' => '1',
        ])
        ->assertRedirect(route('inventory.product-units.index'));

    $this->assertDatabaseHas('inv_product_units', [
        'name' => 'Box',
        'symbol' => 'box',
        'is_active' => true,
    ]);
});

it('fails validation when product unit name is empty', function () {
    $this->actingAs($this->manager)
        ->from(route('inventory.product-units.create'))
        ->post(route('inventory.product-units.store'), [
            'name' => '',
            'symbol' => 'empty',
        ])
        ->assertRedirect(route('inventory.product-units.create'))
        ->assertSessionHasErrors('name');
});

it('allows an authorized user to edit and update a product unit', function () {
    $unit = ProductUnit::factory()->create([
        'name' => 'Unit Lama',
        'symbol' => 'old-unit',
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.product-units.edit', $unit))
        ->assertOk()
        ->assertSee('Ubah Satuan Produk')
        ->assertSee('Unit Lama');

    $this->actingAs($this->manager)
        ->put(route('inventory.product-units.update', $unit), [
            'name' => 'Unit Baru',
            'symbol' => 'new-unit',
            'description' => 'Updated',
            'is_active' => '1',
        ])
        ->assertRedirect(route('inventory.product-units.index'));

    expect($unit->refresh()->name)->toBe('Unit Baru')
        ->and($unit->symbol)->toBe('new-unit');
});

it('deactivates a product unit instead of hard deleting it', function () {
    $unit = ProductUnit::factory()->create(['is_active' => true]);

    $this->actingAs($this->manager)
        ->delete(route('inventory.product-units.destroy', $unit))
        ->assertRedirect(route('inventory.product-units.index'));

    expect($unit->refresh()->is_active)->toBeFalse();
});

it('denies unauthorized users from product unit routes', function () {
    $unit = ProductUnit::factory()->create();
    $user = userWith([]);

    $this->actingAs($user)
        ->get(route('inventory.product-units.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('inventory.product-units.create'))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('inventory.product-units.update', $unit), [
            'name' => 'Nope',
            'symbol' => 'nope',
            'is_active' => '1',
        ])
        ->assertForbidden();
});
