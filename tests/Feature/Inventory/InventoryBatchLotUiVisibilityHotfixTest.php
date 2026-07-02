<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
});

it('renders operator guidance text on the Batch & Lot index page', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('Batch/Lot tidak dibuat manual dari halaman ini. Batch/Lot akan muncul otomatis setelah stok masuk melalui Goods Receipt, Opening Stock, atau Adjustment In.');
});

it('does not expose a manual create or store route for inventory batches', function () {
    expect(Route::has('inventory.batches.create'))->toBeFalse()
        ->and(Route::has('inventory.batches.store'))->toBeFalse();
});

it('does not render a manual batch create button on the Batch & Lot index page', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertDontSee('Tambah Batch')
        ->assertDontSee('Buat Batch Baru');
});

it('shows a Goods Receipt creation shortcut on the Batch & Lot index page when route exists', function () {
    expect(Route::has('inventory.goods-receipts.create'))->toBeTrue();

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('Input via Goods Receipt')
        ->assertSee(route('inventory.goods-receipts.create'));
});

it('renders batch_number, lot_number, and expiry_date inputs plus helper text on the Goods Receipt create form', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('batch_number')
        ->assertSee('lot_number')
        ->assertSee('expiry_date')
        ->assertSee('Untuk barang dilacak batch, centang', false)
        ->assertSee('Buat nomor batch otomatis', false);
});

it('renders batch_number, lot_number, and expiry_date inputs plus helper text on the Opening Stock form', function () {
    InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.products.opening-stock.create', $product))
        ->assertOk()
        ->assertSee('name="batch_number"', false)
        ->assertSee('name="lot_number"', false)
        ->assertSee('name="expiry_date"', false)
        ->assertSee('Untuk stok awal barang yang memiliki batch/expired, isi nomor batch/lot dan tanggal kedaluwarsa di sini. Batch akan otomatis tampil di halaman Batch & Lot setelah stok awal disimpan.');
});

it('persists batch identity captured from Opening Stock into the Batch & Lot directory', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->post(route('inventory.products.opening-stock.store', $product), [
            'inventory_location_id' => $location->id,
            'quantity' => 12,
            'unit_cost' => 1000,
            'batch_mode' => 'new',
            'batch_number' => 'B-OPENING-001',
            'lot_number' => 'LOT-OPEN-01',
            'received_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ])
        ->assertRedirect(route('inventory.products.stock-card', $product));

    $this->actingAs($this->viewer)
        ->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('B-OPENING-001')
        ->assertSee('LOT-OPEN-01');
});

it('rejects new-batch Opening Stock without a batch number (validation intact)', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->post(route('inventory.products.opening-stock.store', $product), [
            'inventory_location_id' => $location->id,
            'quantity' => 5,
            'batch_mode' => 'new',
            'batch_number' => '',
        ])
        ->assertSessionHasErrors('batch_number');
});

it('keeps existing backend validation for batch-tracked products intact on Goods Receipt store', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $po = $this->purchaseOrderService->createDraft([
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'items' => [[
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'quantity_ordered' => 10,
            'unit_price' => 2500,
        ]],
    ], $this->manager);

    $po = $this->purchaseOrderService->markAsSent(
        $this->purchaseOrderService->approve(
            $this->purchaseOrderService->submit($po, $this->manager),
            $this->manager
        ),
        $this->manager
    );
    $poItem = $po->items()->first();

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.store'), [
            'purchase_order_id' => $po->id,
            'receipt_date' => now()->toDateString(),
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'received_qty' => 5,
                'accepted_qty' => 5,
                'rejected_qty' => 0,
                'batch_mode' => 'new',
                'auto_batch' => true,
                'batch_number' => '',
                'expiry_date' => '',
            ]],
        ])
        ->assertSessionHasErrors('items.0.expiry_date');
});

it('introduces no new inventory_batches create/store route', function () {
    $batchRoutes = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name) => str_starts_with($name, 'inventory.batches.'))
        ->sort()
        ->values()
        ->all();

    expect($batchRoutes)->toEqual(['inventory.batches.index', 'inventory.batches.show']);
});

it('introduces no duplicate inventory_batches table or migration', function () {
    expect(Schema::hasTable('inv_inventory_batches'))->toBeTrue();

    $createMigrations = collect(glob(database_path('migrations/*.php')))
        ->filter(function (string $path) {
            $contents = file_get_contents($path);

            return str_contains($contents, "Schema::create('inv_inventory_batches'");
        })
        ->values();

    expect($createMigrations)->toHaveCount(1);
});
