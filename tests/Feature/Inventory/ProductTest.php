<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = userWith(['manage master data']);
});

it('supports reorder configuration fields on products', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'reorder_point' => 15,
        'reorder_quantity' => 50,
        'alert_enabled' => true,
    ]);

    $product->refresh();

    expect((float) $product->minimum_stock)->toBe(10.0)
        ->and((float) $product->reorder_point)->toBe(15.0)
        ->and((float) $product->reorder_quantity)->toBe(50.0)
        ->and($product->alert_enabled)->toBeTrue();
});

it('defaults alert_enabled to true for new products', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    expect($product->alert_enabled)->toBeTrue()
        ->and($product->fresh()->alert_enabled)->toBeTrue();
});

it('rejects negative reorder values on product create', function () {
    $category = ProductCategory::factory()->create(['branch_id' => $this->branch->id]);
    $unit = ProductUnit::factory()->create();

    $this->actingAs($this->user)
        ->from(route('inventory.products.create'))
        ->post(route('inventory.products.store'), [
            'product_category_id' => $category->id,
            'product_unit_id' => $unit->id,
            'name' => 'Negative Reorder Product',
            'code' => 'NEG-REORDER-001',
            'minimum_stock' => -1,
            'reorder_point' => -5,
            'reorder_quantity' => -10,
            'alert_enabled' => 1,
            'is_active' => 1,
        ])
        ->assertSessionHasErrors(['minimum_stock', 'reorder_point', 'reorder_quantity']);
});

it('rejects negative reorder values on product update', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->from(route('inventory.products.edit', $product))
        ->put(route('inventory.products.update', $product), [
            'product_category_id' => $product->product_category_id,
            'product_unit_id' => $product->product_unit_id,
            'name' => $product->name,
            'code' => $product->code,
            'minimum_stock' => -2,
            'reorder_point' => -3,
            'reorder_quantity' => -4,
            'alert_enabled' => 0,
            'is_active' => 1,
        ])
        ->assertSessionHasErrors(['minimum_stock', 'reorder_point', 'reorder_quantity']);
});

it('persists reorder configuration through product update', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'reorder_point' => 0,
        'reorder_quantity' => 0,
        'alert_enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->put(route('inventory.products.update', $product), [
            'product_category_id' => $product->product_category_id,
            'product_unit_id' => $product->product_unit_id,
            'name' => $product->name,
            'code' => $product->code,
            'minimum_stock' => 8,
            'reorder_point' => 12,
            'reorder_quantity' => 40,
            'alert_enabled' => 0,
            'is_active' => 1,
        ])
        ->assertRedirect(route('inventory.products.show', $product));

    $product->refresh();

    expect((float) $product->minimum_stock)->toBe(8.0)
        ->and((float) $product->reorder_point)->toBe(12.0)
        ->and((float) $product->reorder_quantity)->toBe(40.0)
        ->and($product->alert_enabled)->toBeFalse();
});

it('does not introduce mutable stock columns on inv_products', function () {
    $columns = Schema::getColumnListing('inv_products');

    $forbiddenColumns = [
        'current_stock',
        'stock',
        'qty_on_hand',
        'available_stock',
        'stock_quantity',
        'alert_count',
        'last_alert_at',
    ];

    foreach ($forbiddenColumns as $column) {
        expect($columns)->not->toContain($column);
    }

    expect($columns)->toContain('minimum_stock')
        ->and($columns)->toContain('reorder_point')
        ->and($columns)->toContain('reorder_quantity')
        ->and($columns)->toContain('alert_enabled');
});

it('shows reorder configuration on product detail page', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'reorder_point' => 20,
        'reorder_quantity' => 100,
        'alert_enabled' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('inventory.products.show', $product))
        ->assertOk()
        ->assertSee('Pengaturan Pesanan Ulang')
        ->assertSee('Titik Pesan Ulang')
        ->assertSee('Jumlah Pesan Ulang')
        ->assertSee('Peringatan Aktif');
});
