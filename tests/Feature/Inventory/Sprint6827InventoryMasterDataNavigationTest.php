<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\Product;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

function sprint6827RenderSidebarFor(User $user): string
{
    test()->actingAs($user);

    return view('layouts.partials.sidebar')->render();
}

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    test()->withoutMiddleware(ValidateCsrfToken::class);
});

it('shows inventory master data group with required labels for admin warehouse', function () {
    $user = userInRole('Admin Warehouse');

    expect($user->can('viewAny', InventoryBatch::class))->toBeTrue();

    $html = sprint6827RenderSidebarFor($user);

    expect($html)
        ->toContain('data-sidebar-panel="inventory-master-data"')
        ->toContain('>Master Data<')
        ->toContain('>Produk<')
        ->toContain('>Kategori Produk<')
        ->toContain('>Satuan Produk<')
        ->toContain('>Lokasi Persediaan<')
        ->toContain('>Pemasok<')
        ->toContain(route('inventory.batches.index'));
});

it('does not duplicate inventory master data items inside persediaan group', function () {
    $user = userInRole('Admin Warehouse');

    $html = sprint6827RenderSidebarFor($user);

    $inventoryPanelStart = strpos($html, 'data-sidebar-panel="inventory"');
    $inventoryPanelEnd = strpos($html, 'data-sidebar-panel="procurement"');

    expect($inventoryPanelStart)->not->toBeFalse()
        ->and($inventoryPanelEnd)->not->toBeFalse();

    $inventoryPanel = substr($html, $inventoryPanelStart, $inventoryPanelEnd - $inventoryPanelStart);

    expect($inventoryPanel)
        ->not->toContain(route('inventory.products.index'))
        ->not->toContain(route('inventory.product-categories.index'))
        ->not->toContain(route('inventory.product-units.index'))
        ->not->toContain(route('inventory.locations.index'))
        ->not->toContain(route('inventory.suppliers.index'))
        ->not->toContain(route('inventory.batches.index'));
});

it('renders searchable product select with hidden product_id field and selected value', function () {
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $product = Product::factory()->create([
        'branch_id' => $branch->id,
        'code' => 'SRCH-001',
        'name' => 'Produk Pencarian',
    ]);

    $html = view('components.inventory.searchable-product-select', [
        'name' => 'product_id',
        'products' => collect([$product]),
        'selected' => $product->id,
    ])->render();

    expect($html)
        ->toContain('searchableProductSelect')
        ->toContain('name="product_id"')
        ->toContain('SRCH-001 - Produk Pencarian')
        ->toContain('selected')
        ->toContain((string) $product->id);
});

it('purchase request create still submits product_id through searchable select', function () {
    $manager = userWith(['manage_inventory']);
    $branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $product = Product::factory()->create(['branch_id' => $branch->id]);

    test()->actingAs($manager)
        ->get(route('inventory.purchase-requests.create'))
        ->assertOk()
        ->assertSee('searchableProductSelect', false)
        ->assertSee('items[', false);

    test()->actingAs($manager)
        ->post(route('inventory.purchase-requests.store'), [
            'request_date' => now()->toDateString(),
            'notes' => 'Sprint 68.27 searchable select',
            'items' => [
                [
                    'product_id' => $product->id,
                    'inventory_location_id' => null,
                    'quantity_requested' => 2,
                    'estimated_unit_price' => 1000,
                ],
            ],
        ])
        ->assertRedirect();
});
