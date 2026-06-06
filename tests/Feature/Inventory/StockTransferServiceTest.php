<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = userWith(['manage_inventory']);
    $this->actingAs($this->user);
    $this->stock = app(InventoryStockService::class);
    $this->service = app(StockTransferService::class);
});

it('creates a draft transfer with active same branch locations and products', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 3, 'notes' => 'For QC'],
        ['product_id' => $product->id, 'quantity' => 2],
    ], 'Move to QC');

    expect($transfer->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and($transfer->branch_id)->toBe($this->branch->id)
        ->and($transfer->source_inventory_location_id)->toBe($source->id)
        ->and($transfer->destination_inventory_location_id)->toBe($destination->id)
        ->and($transfer->requested_by)->toBe($this->user->id)
        ->and($transfer->created_by)->toBe($this->user->id)
        ->and($transfer->items)->toHaveCount(1)
        ->and((float) $transfer->items->first()->quantity)->toBe(5.0);
});

it('updates only draft transfers and replaces item lines', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $oldDestination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $newDestination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $oldProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $newProduct = Product::factory()->create(['branch_id' => $this->branch->id]);

    $transfer = $this->service->createTransfer($source->id, $oldDestination->id, [
        ['product_id' => $oldProduct->id, 'quantity' => 2],
    ]);

    $updated = $this->service->updateTransfer($transfer->id, $source->id, $newDestination->id, [
        ['product_id' => $newProduct->id, 'quantity' => 7],
    ], 'Updated transfer');

    expect($updated->destination_inventory_location_id)->toBe($newDestination->id)
        ->and($updated->notes)->toBe('Updated transfer')
        ->and($updated->items)->toHaveCount(1)
        ->and($updated->items->first()->product_id)->toBe($newProduct->id)
        ->and((float) $updated->items->first()->quantity)->toBe(7.0);

    $this->service->submitTransfer($updated->id);

    expect(fn () => $this->service->updateTransfer($updated->id, $source->id, $oldDestination->id, [
        ['product_id' => $oldProduct->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class);
});

it('submits a draft transfer after validating transfer details', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);

    $submitted = $this->service->submitTransfer($transfer->id);

    expect($submitted->status)->toBe(StockTransfer::STATUS_SUBMITTED)
        ->and(fn () => $this->service->submitTransfer($submitted->id))
        ->toThrow(ValidationException::class);
});

it('ships a submitted transfer with transfer out movements at source only', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 15000]);

    $this->stock->createOpeningStock($product->id, $source->id, 10, 15000);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);

    $shipped = $this->service->shipTransfer($transfer->id);
    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->orderBy('id')
        ->get();

    expect($shipped->status)->toBe(StockTransfer::STATUS_IN_TRANSIT)
        ->and($shipped->shipped_by)->toBe($this->user->id)
        ->and($shipped->shipped_at)->not->toBeNull()
        ->and($movements)->toHaveCount(1)
        ->and($movements->first()->movement_type)->toBe(InventoryMovement::TYPE_TRANSFER_OUT)
        ->and($movements->first()->inventory_location_id)->toBe($source->id)
        ->and((float) $movements->first()->quantity_out)->toBe(4.0)
        ->and((float) $movements->first()->quantity_in)->toBe(0.0)
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(6.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(0.0);
});

it('rejects shipping draft transfers', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);

    expect(fn () => $this->service->shipTransfer($transfer->id))
        ->toThrow(ValidationException::class)
        ->and($transfer->refresh()->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->count())->toBe(0);
});

it('rejects shipping received or cancelled transfers', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $received = StockTransfer::factory()->received()->create([
        'branch_id' => $this->branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);

    $cancelled = StockTransfer::factory()->cancelled()->create([
        'branch_id' => $this->branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);

    expect(fn () => $this->service->shipTransfer($received->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->shipTransfer($cancelled->id))
        ->toThrow(ValidationException::class);
});

it('rejects shipping when source location stock is insufficient and rolls back ledger writes', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 2);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 5],
    ]);
    $this->service->submitTransfer($transfer->id);

    expect(fn () => $this->service->shipTransfer($transfer->id))
        ->toThrow(ValidationException::class)
        ->and($transfer->refresh()->status)->toBe(StockTransfer::STATUS_SUBMITTED)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->count())->toBe(0)
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(2.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(0.0);
});

it('receives an in transit transfer with transfer in movements at destination only', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 15000]);

    $this->stock->createOpeningStock($product->id, $source->id, 10, 15000);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);

    $received = $this->service->receiveTransfer($transfer->id);
    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->orderBy('id')
        ->get();

    expect($received->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and($received->approved_by)->toBe($this->user->id)
        ->and($received->completed_at)->not->toBeNull()
        ->and($movements)->toHaveCount(2)
        ->and($movements->pluck('movement_type')->all())->toBe([
            InventoryMovement::TYPE_TRANSFER_OUT,
            InventoryMovement::TYPE_TRANSFER_IN,
        ])
        ->and($movements[0]->inventory_location_id)->toBe($source->id)
        ->and((float) $movements[0]->quantity_out)->toBe(4.0)
        ->and((float) $movements[0]->quantity_in)->toBe(0.0)
        ->and($movements[1]->inventory_location_id)->toBe($destination->id)
        ->and((float) $movements[1]->quantity_in)->toBe(4.0)
        ->and((float) $movements[1]->quantity_out)->toBe(0.0)
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(6.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(4.0);
});

it('rejects receiving draft submitted received or cancelled transfers', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $draft = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);

    $submitted = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);
    $this->service->submitTransfer($submitted->id);

    $received = StockTransfer::factory()->received()->create([
        'branch_id' => $this->branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);

    $cancelled = StockTransfer::factory()->cancelled()->create([
        'branch_id' => $this->branch->id,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
    ]);

    expect(fn () => $this->service->receiveTransfer($draft->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->receiveTransfer($submitted->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->receiveTransfer($received->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->receiveTransfer($cancelled->id))
        ->toThrow(ValidationException::class);
});

it('prevents duplicate receive on the same transfer', function () {
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

    expect(fn () => $this->service->receiveTransfer($transfer->id))
        ->toThrow(ValidationException::class)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_IN)
            ->count())->toBe(1);
});

it('blocks terminal received transfers from edit cancel and ship', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 2],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);
    $this->service->receiveTransfer($transfer->id);

    expect(fn () => $this->service->updateTransfer($transfer->id, $source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->cancelTransfer($transfer->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->shipTransfer($transfer->id))
        ->toThrow(ValidationException::class);
});

it('runs the full ship and receive workflow with derived stock balances', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 15000]);

    $this->stock->createOpeningStock($product->id, $source->id, 10, 15000);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);
    $this->service->shipTransfer($transfer->id);
    $received = $this->service->receiveTransfer($transfer->id);

    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->orderBy('id')
        ->get();

    expect($received->status)->toBe(StockTransfer::STATUS_RECEIVED)
        ->and($received->approved_by)->toBe($this->user->id)
        ->and($received->completed_at)->not->toBeNull()
        ->and($movements)->toHaveCount(2)
        ->and($movements->pluck('movement_type')->all())->toBe([
            InventoryMovement::TYPE_TRANSFER_OUT,
            InventoryMovement::TYPE_TRANSFER_IN,
        ])
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(6.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(4.0);
});

it('prevents duplicate ship on the same transfer', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 3],
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

it('rejects invalid branch inactive same location and non positive transfer data', function () {
    $otherBranch = Branch::factory()->create();
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $inactiveSource = InventoryLocation::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $inactiveDestination = InventoryLocation::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $otherDestination = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $inactiveProduct = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => $this->service->createTransfer($source->id, $source->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createTransfer($inactiveSource->id, $destination->id, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createTransfer($source->id, $inactiveDestination->id, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createTransfer($source->id, $otherDestination->id, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createTransfer($source->id, $destination->id, [
            ['product_id' => $inactiveProduct->id, 'quantity' => 1],
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createTransfer($source->id, $destination->id, [
            ['product_id' => $otherProduct->id, 'quantity' => 1],
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createTransfer($source->id, $destination->id, [
            ['product_id' => $product->id, 'quantity' => 0],
        ]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createTransfer($source->id, $destination->id, []))
        ->toThrow(ValidationException::class);
});

it('blocks cross branch transfer lookup and workflow actions', function () {
    $otherBranch = Branch::factory()->create();
    $otherSource = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);
    $otherDestination = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);
    $otherTransfer = StockTransfer::factory()->create([
        'branch_id' => $otherBranch->id,
        'source_inventory_location_id' => $otherSource->id,
        'destination_inventory_location_id' => $otherDestination->id,
    ]);

    expect(fn () => $this->service->getTransferDetails($otherTransfer->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->submitTransfer($otherTransfer->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->cancelTransfer($otherTransfer->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->shipTransfer($otherTransfer->id))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->receiveTransfer($otherTransfer->id))
        ->toThrow(ValidationException::class);
});

it('cancels draft or submitted transfers and blocks received or in transit cancellation', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $cancelled = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);
    $this->service->submitTransfer($cancelled->id);

    expect($this->service->cancelTransfer($cancelled->id, 'No longer needed')->status)
        ->toBe(StockTransfer::STATUS_CANCELLED);

    $this->stock->createOpeningStock($product->id, $source->id, 10);

    $inTransit = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);
    $this->service->submitTransfer($inTransit->id);
    $this->service->shipTransfer($inTransit->id);

    expect(fn () => $this->service->cancelTransfer($inTransit->id))
        ->toThrow(ValidationException::class);

    $received = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);
    $this->service->submitTransfer($received->id);
    $this->service->shipTransfer($received->id);
    $this->service->receiveTransfer($received->id);

    expect(fn () => $this->service->cancelTransfer($received->id))
        ->toThrow(ValidationException::class);
});
