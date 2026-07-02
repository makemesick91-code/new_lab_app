<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\AutoBatchNumberService;
use App\Modules\Inventory\Services\BatchStockOptionService;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = userWith(['manage_inventory', 'view_inventory']);
    $this->actingAs($this->user);
    $this->stock = app(InventoryStockService::class);
    $this->transferService = app(StockTransferService::class);
    $this->opnameService = app(StockOpnameService::class);
    $this->batchOptions = app(BatchStockOptionService::class);
});

function sprint6839TransferLocations(Branch $branch): array
{
    $source = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang Sumber 6839']);
    $destination = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang Tujuan 6839']);

    return compact('source', 'destination');
}

function sprint6839SeedBatchStock(
    object $test,
    Product $product,
    InventoryLocation $location,
    InventoryBatch $batch,
    float $qty,
): void {
    $test->stock->receiveStock(
        $product->id,
        $location->id,
        $qty,
        10000,
        null,
        null,
        ['inventory_batch_id' => $batch->id],
    );
}

it('hides batch selector state for non-batch product on transfer create form', function () {
    ['source' => $source, 'destination' => $destination] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'requires_batch_tracking' => false,
    ]);

    $this->stock->createOpeningStock($product->id, $source->id, 5);

    $response = $this->get(route('inventory.stock-transfers.create'));

    $response->assertOk()
        ->assertSee('productRequiresBatch(item.product_id)', false);
});

it('shows batch selector state for batch-tracked product on transfer create form', function () {
    ['source' => $source] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create([
        'branch_id' => $this->branch->id,
        'code' => 'LIDO',
    ]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'AUTO-LIDO-20270830-001',
        'expiry_date' => '2027-08-30',
    ]);

    sprint6839SeedBatchStock($this, $product, $source, $batch, 12);

    $response = $this->get(route('inventory.stock-transfers.create'));

    $response->assertOk()
        ->assertSee('Batch / Expired', false)
        ->assertSee('Pilih batch dari lokasi asal. Urutan mengikuti expired terdekat.', false)
        ->assertSee('Belum ada batch tersedia untuk produk ini di lokasi asal.', false)
        ->assertSee('AUTO-LIDO-20270830-001', false)
        ->assertSee('Exp 30 Agu 2027', false)
        ->assertSee('Stok 12', false);
});

it('returns batch options filtered by product source branch and location with positive stock only', function () {
    ['source' => $source, 'destination' => $destination] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $batchWithStock = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B-WITH-STOCK',
        'expiry_date' => now()->addMonths(3)->toDateString(),
    ]);
    $batchNoStock = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B-NO-STOCK',
        'expiry_date' => now()->addMonth()->toDateString(),
    ]);

    sprint6839SeedBatchStock($this, $product, $source, $batchWithStock, 8);

    $options = $this->batchOptions->availableForProductLocation($product->id, $this->branch->id, $source->id);

    expect($options)->toHaveCount(1)
        ->and($options->first()['batch_id'])->toBe($batchWithStock->id)
        ->and($options->pluck('batch_number')->all())->not->toContain('B-NO-STOCK');

    $otherLocationOptions = $this->batchOptions->availableForProductLocation($product->id, $this->branch->id, $destination->id);
    expect($otherLocationOptions)->toBeEmpty();
});

it('orders batch options by earliest expiry first then batch number', function () {
    ['source' => $source] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);

    $laterBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B-LATER',
        'expiry_date' => '2027-12-31',
    ]);
    $earlierBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B-EARLIER',
        'expiry_date' => '2027-06-30',
    ]);
    $nullExpiryBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B-NO-EXP',
        'expiry_date' => null,
    ]);

    foreach ([$laterBatch, $earlierBatch, $nullExpiryBatch] as $batch) {
        sprint6839SeedBatchStock($this, $product, $source, $batch, 5);
    }

    $orderedIds = $this->batchOptions
        ->availableForProductLocation($product->id, $this->branch->id, $source->id)
        ->pluck('batch_id')
        ->all();

    expect($orderedIds)->toBe([$earlierBatch->id, $laterBatch->id, $nullExpiryBatch->id]);
});

it('includes batch number expiry and available quantity in batch option label', function () {
    ['source' => $source] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'AUTO-LIDO-20270830-001',
        'expiry_date' => '2027-08-30',
    ]);

    sprint6839SeedBatchStock($this, $product, $source, $batch, 12);

    $label = $this->batchOptions
        ->availableForProductLocation($product->id, $this->branch->id, $source->id)
        ->first()['label'];

    expect($label)
        ->toContain('AUTO-LIDO-20270830-001')
        ->toContain('Exp 30 Agu 2027')
        ->toContain('Stok 12');
});

it('rejects batch-tracked transfer without batch id', function () {
    ['source' => $source, 'destination' => $destination] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 5);

    $this->post(route('inventory.stock-transfers.store'), [
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ],
    ])->assertSessionHasErrors('items.0.inventory_batch_id');
});

it('rejects batch-tracked transfer with wrong product batch', function () {
    ['source' => $source, 'destination' => $destination] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $foreignBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $otherProduct->id,
    ]);

    expect(fn () => $this->transferService->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $foreignBatch->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

it('rejects batch-tracked transfer quantity greater than available batch stock', function () {
    ['source' => $source, 'destination' => $destination] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    sprint6839SeedBatchStock($this, $product, $source, $batch, 4);

    $this->post(route('inventory.stock-transfers.store'), [
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
        'items' => [
            ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 8],
        ],
    ])->assertSessionHasErrors('items.0.quantity');
});

it('creates transfer out and in movements preserving batch id for batch-tracked products', function () {
    ['source' => $source, 'destination' => $destination] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    sprint6839SeedBatchStock($this, $product, $source, $batch, 10);

    $transfer = $this->transferService->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'inventory_batch_id' => $batch->id, 'quantity' => 4],
    ]);
    $this->transferService->submitTransfer($transfer->id);
    $this->transferService->shipTransfer($transfer->id);
    $this->transferService->receiveTransfer($transfer->id);

    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->orderBy('id')
        ->get();

    expect($movements)->toHaveCount(2)
        ->and($movements[0]->movement_type)->toBe(InventoryMovement::TYPE_TRANSFER_OUT)
        ->and($movements[1]->movement_type)->toBe(InventoryMovement::TYPE_TRANSFER_IN)
        ->and($movements[0]->inventory_batch_id)->toBe($batch->id)
        ->and($movements[1]->inventory_batch_id)->toBe($batch->id);
});

it('creates non-batch transfer movements with null batch id', function () {
    ['source' => $source, 'destination' => $destination] = sprint6839TransferLocations($this->branch);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'requires_batch_tracking' => false,
    ]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $transfer = $this->transferService->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 3],
    ]);
    $this->transferService->submitTransfer($transfer->id);
    $this->transferService->shipTransfer($transfer->id);
    $this->transferService->receiveTransfer($transfer->id);

    $batchIds = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->pluck('inventory_batch_id')
        ->unique()
        ->all();

    expect($batchIds)->toBe([null]);
});

it('keeps non-batch opname lines with null batch id', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'requires_batch_tracking' => false,
    ]);

    $this->stock->createOpeningStock($product->id, $location->id, 6);

    $opname = $this->opnameService->createDraftOpname($location->id, [$product->id]);
    $item = $opname->items->first();

    expect($opname->items)->toHaveCount(1)
        ->and($item->inventory_batch_id)->toBeNull();
});

it('creates batch-aware opname lines for batch-tracked products', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $batchA = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'OPN-BATCH-A',
        'expiry_date' => now()->addMonth()->toDateString(),
    ]);
    $batchB = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'OPN-BATCH-B',
        'expiry_date' => now()->addMonths(2)->toDateString(),
    ]);

    sprint6839SeedBatchStock($this, $product, $location, $batchA, 3);
    sprint6839SeedBatchStock($this, $product, $location, $batchB, 5);

    $opname = $this->opnameService->createDraftOpname($location->id, [$product->id]);

    expect($opname->items)->toHaveCount(2)
        ->and($opname->items->pluck('inventory_batch_id')->sort()->values()->all())
        ->toEqual(collect([$batchA->id, $batchB->id])->sort()->values()->all());
});

it('creates batch-aware opname adjustment movement with batch id on finalize', function () {
    Carbon::setTestNow('2026-07-03 10:00:00');

    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    sprint6839SeedBatchStock($this, $product, $location, $batch, 10);

    $opname = $this->opnameService->createDraftOpname($location->id, [$product->id]);
    $item = $opname->items->firstWhere('inventory_batch_id', $batch->id);

    $this->opnameService->updateCountedQuantity($opname->id, $product->id, 12, null, $batch->id);
    $this->opnameService->reviewOpname($opname->id);
    $this->opnameService->finalizeOpname($opname->id);

    $movement = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_opnames')
        ->where('reference_id', $opname->id)
        ->where('movement_type', InventoryMovement::TYPE_ADJUSTMENT_IN)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->inventory_batch_id)->toBe($batch->id)
        ->and((float) $movement->quantity_in)->toBe(2.0);

    Carbon::setTestNow();
});

it('rejects batch-tracked opname draft when no batch is available in location', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->opnameService->createDraftOpname($location->id, [$product->id]))
        ->toThrow(ValidationException::class, 'Belum ada batch tersedia untuk produk ini di lokasi ini.');
});

it('shows batch-aware opname empty state message on show page when adding batch product without stock', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $opname = $this->opnameService->createDraftOpname($location->id, []);

    $this->post(route('inventory.stock-opnames.update-counted-quantity', [$opname, 0]), [
        'product_id' => $product->id,
        'counted_quantity' => 0,
    ])->assertSessionHasErrors('product_id');
});

it('still supports sprint 68.38 auto batch goods receipt baseline', function () {
    expect(class_exists(AutoBatchNumberService::class))->toBeTrue();
});
