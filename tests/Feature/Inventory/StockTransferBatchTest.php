<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->user = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->actingAs($this->user);
    $this->stock = app(InventoryStockService::class);
    $this->service = app(StockTransferService::class);
});

function batchTransferSetup(Branch $branch, float $batchStock = 10): array
{
    $source = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang Batch Sumber']);
    $destination = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang Batch Tujuan']);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $branch->id, 'name' => 'Produk Batch Transfer']);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B-TRF-001',
        'lot_number' => 'LOT-TRF-01',
    ]);

    app(InventoryStockService::class)->receiveStock(
        $product->id,
        $source->id,
        $batchStock,
        10000,
        null,
        null,
        ['inventory_batch_id' => $batch->id],
    );

    return compact('source', 'destination', 'product', 'batch');
}

it('stores inventory_batch_id on stock transfer items', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 3],
    ]);

    $item = $transfer->items->first();

    expect(Schema::hasColumn('trx_stock_transfer_items', 'inventory_batch_id'))->toBeTrue()
        ->and($item->inventory_batch_id)->toBe($batch->id);
});

it('relates stock transfer items to inventory batches', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 2],
    ]);

    $item = StockTransferItem::query()->with('inventoryBatch')->find($transfer->items->first()->id);

    expect($item->inventoryBatch)->toBeInstanceOf(InventoryBatch::class)
        ->and($item->inventoryBatch->id)->toBe($batch->id)
        ->and($item->inventoryBatch->batch_number)->toBe('B-TRF-001');
});

it('accepts same-branch active batch when creating a transfer', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 2],
    ]);

    expect($transfer->items->first()->inventory_batch_id)->toBe($batch->id);
});

it('rejects cross-branch batch when creating a transfer', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product] = batchTransferSetup($this->branch);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $foreignBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'product_id' => $otherProduct->id,
    ]);

    expect(fn () => $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $foreignBatch->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

it('rejects product-mismatch batch when creating a transfer', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product] = batchTransferSetup($this->branch);
    $otherProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $mismatchBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $otherProduct->id,
    ]);

    expect(fn () => $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $mismatchBatch->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

it('rejects inactive batch when creating a transfer', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product] = batchTransferSetup($this->branch);
    $inactiveBatch = InventoryBatch::factory()->inactive()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    expect(fn () => $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $inactiveBatch->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

it('validates sufficient batch stock at source location when creating transfer', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch, 5);

    expect(fn () => $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 8],
    ]))->toThrow(ValidationException::class);
});

it('creates transfer out movements with inventory_batch_id when shipping', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);

    $movement = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->inventory_batch_id)->toBe($batch->id)
        ->and($this->stock->getCurrentStockByBatch($product->id, $source->id, $batch->id))->toBe(6.0);
});

it('creates transfer in movements with the same inventory_batch_id when receiving', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);
    $this->service->receiveTransfer($transfer->id);

    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->orderBy('id')
        ->get();

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->inventory_batch_id)->toBe($batch->id)
        ->and($movements[1]->inventory_batch_id)->toBe($batch->id)
        ->and($this->stock->getCurrentStockByBatch($product->id, $source->id, $batch->id))->toBe(6.0)
        ->and($this->stock->getCurrentStockByBatch($product->id, $destination->id, $batch->id))->toBe(4.0);
});

it('still supports non-batch stock transfers', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 3],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);
    $this->service->receiveTransfer($transfer->id);

    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->get();

    expect($transfer->items->first()->inventory_batch_id)->toBeNull()
        ->and($movements->pluck('inventory_batch_id')->unique()->all())->toBe([null])
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(7.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(3.0);
});

it('still blocks duplicate ship on the same transfer', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 3],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);

    expect(fn () => $this->service->shipTransfer($transfer->id))
        ->toThrow(ValidationException::class)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->count())->toBe(1);
});

it('still blocks duplicate receive on the same transfer', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 3],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);
    $this->service->receiveTransfer($transfer->id);

    expect(fn () => $this->service->receiveTransfer($transfer->id))
        ->toThrow(ValidationException::class)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_IN)
            ->count())->toBe(1);
});

it('still denies cross-branch transfer route access', function () {
    $otherSource = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherDestination = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'product_id' => $otherProduct->id,
    ]);

    $otherTransfer = StockTransfer::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'source_inventory_location_id' => $otherSource->id,
        'destination_inventory_location_id' => $otherDestination->id,
    ]);

    StockTransferItem::factory()->create([
        'stock_transfer_id' => $otherTransfer->id,
        'product_id' => $otherProduct->id,
        'inventory_batch_id' => $otherBatch->id,
        'quantity' => 2,
    ]);

    expect(fn () => $this->service->getTransferDetails($otherTransfer->id))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $otherTransfer))
        ->assertForbidden();
});

it('does not introduce mutable stock columns on products locations batches or transfer items', function () {
    $forbiddenColumns = [
        'current_stock',
        'stock',
        'qty_on_hand',
        'available_stock',
        'quantity_on_hand',
    ];

    foreach ($forbiddenColumns as $column) {
        expect(Schema::hasColumn('inv_products', $column))->toBeFalse()
            ->and(Schema::hasColumn('inv_inventory_locations', $column))->toBeFalse()
            ->and(Schema::hasColumn('inv_inventory_batches', $column))->toBeFalse()
            ->and(Schema::hasColumn('trx_stock_transfer_items', $column))->toBeFalse();
    }

    expect((new Product)->getFillable())->not->toContain('current_stock', 'stock', 'qty_on_hand')
        ->and((new InventoryBatch)->getFillable())->not->toContain('current_stock', 'stock', 'qty_on_hand')
        ->and((new StockTransferItem)->getFillable())->not->toContain('current_stock', 'stock', 'qty_on_hand');
});

it('shows batch selector on transfer create form with Indonesian labels', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch);

    $this->actingAs($this->user)
        ->get(route('inventory.stock-transfers.create'))
        ->assertOk()
        ->assertSee('Batch / Expired')
        ->assertSee('Pilih batch')
        ->assertSee('Belum ada batch tersedia untuk produk ini di lokasi asal.')
        ->assertSee($batch->batch_number);
});

it('shows batch information on transfer detail page', function () {
    ['source' => $source, 'destination' => $destination, 'product' => $product, 'batch' => $batch] = batchTransferSetup($this->branch, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);
    $this->service->receiveTransfer($transfer->id);

    $this->actingAs($this->viewer)
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('B-TRF-001')
        ->assertSee('Lot LOT-TRF-01')
        ->assertSee('Diterima')
        ->assertSee('Referensi Pergerakan Ledger');
});
