<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\ProductCategory;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
});

it('allows an authorized user to open the product category index', function () {
    ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Kategori UI',
        'code' => 'CAT-UI',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.product-categories.index'))
        ->assertOk()
        ->assertSee('Kategori Produk Persediaan')
        ->assertSee('Kategori UI')
        ->assertSee('CAT-UI');
});

it('allows an authorized user to open the product category create page', function () {
    $this->actingAs($this->manager)
        ->get(route('inventory.product-categories.create'))
        ->assertOk()
        ->assertSee('Tambah Kategori Produk')
        ->assertSee('Simpan Kategori');
});

it('stores a valid product category in the active branch', function () {
    $this->actingAs($this->manager)
        ->post(route('inventory.product-categories.store'), [
            'name' => 'Bahan Keramik',
            'code' => 'CER',
            'description' => 'Material keramik',
            'is_active' => '1',
        ])
        ->assertRedirect(route('inventory.product-categories.index'));

    $this->assertDatabaseHas('inv_product_categories', [
        'branch_id' => $this->branch->id,
        'name' => 'Bahan Keramik',
        'code' => 'CER',
        'is_active' => true,
    ]);
});

it('fails validation when product category name is empty', function () {
    $this->actingAs($this->manager)
        ->from(route('inventory.product-categories.create'))
        ->post(route('inventory.product-categories.store'), [
            'name' => '',
            'code' => 'EMPTY',
        ])
        ->assertRedirect(route('inventory.product-categories.create'))
        ->assertSessionHasErrors('name');
});

it('allows an authorized user to edit and update a product category', function () {
    $category = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Kategori Lama',
        'code' => 'OLD',
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.product-categories.edit', $category))
        ->assertOk()
        ->assertSee('Ubah Kategori Produk')
        ->assertSee('Kategori Lama');

    $this->actingAs($this->manager)
        ->put(route('inventory.product-categories.update', $category), [
            'name' => 'Kategori Baru',
            'code' => 'NEW',
            'description' => 'Updated',
            'is_active' => '1',
        ])
        ->assertRedirect(route('inventory.product-categories.index'));

    expect($category->refresh()->name)->toBe('Kategori Baru')
        ->and($category->code)->toBe('NEW');
});

it('deactivates a product category instead of hard deleting it', function () {
    $category = ProductCategory::factory()->create([
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->delete(route('inventory.product-categories.destroy', $category))
        ->assertRedirect(route('inventory.product-categories.index'));

    expect($category->refresh()->is_active)->toBeFalse();
});

it('denies unauthorized users from product category routes', function () {
    $category = ProductCategory::factory()->create(['branch_id' => $this->branch->id]);
    $user = userWith([]);

    $this->actingAs($user)
        ->get(route('inventory.product-categories.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('inventory.product-categories.create'))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('inventory.product-categories.update', $category), [
            'name' => 'Nope',
            'code' => 'NOPE',
            'is_active' => '1',
        ])
        ->assertForbidden();
});

it('denies updating and deactivating product categories from another branch', function () {
    $otherBranch = Branch::factory()->create(['code' => 'CAT2']);
    $category = ProductCategory::factory()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Kategori Cabang Lain',
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->put(route('inventory.product-categories.update', $category), [
            'name' => 'Tidak Boleh',
            'code' => $category->code,
            'is_active' => '1',
        ])
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->delete(route('inventory.product-categories.destroy', $category))
        ->assertForbidden();

    expect($category->refresh()->name)->toBe('Kategori Cabang Lain')
        ->and($category->is_active)->toBeTrue();
});

it('shows product category and unit sidebar menus only for authorized inventory users', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Kategori Produk')
        ->assertSee('Satuan Produk');

    $this->actingAs(userWith(['view dashboard']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Kategori Produk')
        ->assertDontSee('Satuan Produk');
});
