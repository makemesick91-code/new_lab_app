<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->stock = app(InventoryStockService::class);
});

function stockTransferPayload(int $sourceId, int $destinationId, int $productId, float $quantity = 2): array
{
    return [
        'source_inventory_location_id' => $sourceId,
        'destination_inventory_location_id' => $destinationId,
        'transfer_date' => now()->toDateString(),
        'notes' => 'Controller transfer note',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => $quantity,
                'notes' => 'Line note',
            ],
        ],
    ];
}

function createDraftTransferWithItem(Branch $branch, float $quantity = 2): StockTransfer
{
    $source = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create(['branch_id' => $branch->id]);

    $transfer = StockTransfer::factory()->create([
        'branch_id' => $branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
        'status' => StockTransfer::STATUS_DRAFT,
    ]);

    StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
    ]);

    return $transfer->refresh();
}

it('registers stock transfer route names', function () {
    $routes = [
        'inventory.stock-transfers.index',
        'inventory.stock-transfers.create',
        'inventory.stock-transfers.store',
        'inventory.stock-transfers.show',
        'inventory.stock-transfers.edit',
        'inventory.stock-transfers.update',
        'inventory.stock-transfers.submit',
        'inventory.stock-transfers.complete',
        'inventory.stock-transfers.cancel',
    ];

    foreach ($routes as $routeName) {
        expect(Route::has($routeName))->toBeTrue();
    }
});

it('allows view_inventory to access index and show for same branch transfers', function () {
    $transfer = createDraftTransferWithItem($this->branch);

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.index'))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk();
});

it('denies view_inventory from mutation routes', function () {
    $transfer = createDraftTransferWithItem($this->branch);
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.stock-transfers.store'), stockTransferPayload($source->id, $destination->id, $product->id))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.edit', $transfer))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->put(route('inventory.stock-transfers.update', $transfer), stockTransferPayload($source->id, $destination->id, $product->id))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.stock-transfers.submit', $transfer))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.stock-transfers.complete', $transfer))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.stock-transfers.cancel', $transfer))
        ->assertForbidden();
});

it('denies cross branch access to stock transfer routes', function () {
    $transfer = createDraftTransferWithItem($this->otherBranch);
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.stock-transfers.edit', $transfer))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->put(route('inventory.stock-transfers.update', $transfer), stockTransferPayload($source->id, $destination->id, $product->id))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.submit', $transfer))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.complete', $transfer))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.cancel', $transfer))
        ->assertForbidden();
});

it('stores a draft stock transfer through the controller', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.store'), stockTransferPayload($source->id, $destination->id, $product->id))
        ->assertRedirect()
        ->assertSessionHas('status', 'Transfer stok berhasil dibuat.');

    $transfer = StockTransfer::query()->latest('id')->first();

    expect($transfer)->not->toBeNull()
        ->and($transfer->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and($transfer->branch_id)->toBe($this->branch->id)
        ->and($transfer->items)->toHaveCount(1);
});

it('updates a draft stock transfer through the controller', function () {
    $transfer = createDraftTransferWithItem($this->branch, 2);
    $newDestination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $newProduct = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->put(
            route('inventory.stock-transfers.update', $transfer),
            stockTransferPayload($transfer->source_inventory_location_id, $newDestination->id, $newProduct->id, 5),
        )
        ->assertRedirect(route('inventory.stock-transfers.show', $transfer))
        ->assertSessionHas('status', 'Transfer stok berhasil diperbarui.');

    $transfer->refresh();

    expect($transfer->destination_inventory_location_id)->toBe($newDestination->id)
        ->and($transfer->items)->toHaveCount(1)
        ->and($transfer->items->first()->product_id)->toBe($newProduct->id)
        ->and((float) $transfer->items->first()->quantity)->toBe(5.0);
});

it('submits a draft stock transfer through the controller', function () {
    $transfer = createDraftTransferWithItem($this->branch);

    $this->actingAs($this->manager)
        ->from(route('inventory.stock-transfers.show', $transfer))
        ->post(route('inventory.stock-transfers.submit', $transfer))
        ->assertRedirect(route('inventory.stock-transfers.show', $transfer))
        ->assertSessionHas('status', 'Transfer stok berhasil diajukan.');

    expect($transfer->refresh()->status)->toBe(StockTransfer::STATUS_SUBMITTED);
});

it('completes a submitted stock transfer through the controller', function () {
    $transfer = createDraftTransferWithItem($this->branch, 3);
    $productId = $transfer->items->first()->product_id;

    $this->stock->createOpeningStock($productId, $transfer->source_inventory_location_id, 10, 10000);

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.submit', $transfer));

    $this->actingAs($this->manager)
        ->from(route('inventory.stock-transfers.show', $transfer))
        ->post(route('inventory.stock-transfers.complete', $transfer))
        ->assertRedirect(route('inventory.stock-transfers.show', $transfer))
        ->assertSessionHas('status', 'Transfer stok berhasil diselesaikan.');

    expect($transfer->refresh()->status)->toBe(StockTransfer::STATUS_COMPLETED);
});

it('cancels a draft stock transfer through the controller', function () {
    $transfer = createDraftTransferWithItem($this->branch);

    $this->actingAs($this->manager)
        ->post(route('inventory.stock-transfers.cancel', $transfer), ['notes' => 'Cancelled from controller'])
        ->assertRedirect(route('inventory.stock-transfers.index'))
        ->assertSessionHas('status', 'Transfer stok berhasil dibatalkan.');

    expect($transfer->refresh()->status)->toBe(StockTransfer::STATUS_CANCELLED)
        ->and($transfer->notes)->toBe('Cancelled from controller');
});
