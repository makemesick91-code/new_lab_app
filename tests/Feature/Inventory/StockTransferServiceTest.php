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

it('completes a submitted transfer with paired ledger movements and derived stock balances', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 15000]);

    $this->stock->createOpeningStock($product->id, $source->id, 10, 15000);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 4],
    ]);
    $this->service->submitTransfer($transfer->id);

    $completed = $this->service->completeTransfer($transfer->id);
    $movements = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_transfers')
        ->where('reference_id', $transfer->id)
        ->orderBy('id')
        ->get();

    expect($completed->status)->toBe(StockTransfer::STATUS_COMPLETED)
        ->and($completed->approved_by)->toBe($this->user->id)
        ->and($completed->completed_at)->not->toBeNull()
        ->and($movements)->toHaveCount(2)
        ->and($movements->pluck('movement_type')->all())->toBe([
            InventoryMovement::TYPE_TRANSFER_OUT,
            InventoryMovement::TYPE_TRANSFER_IN,
        ])
        ->and((float) $movements[0]->quantity_out)->toBe(4.0)
        ->and((float) $movements[1]->quantity_in)->toBe(4.0)
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(6.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(4.0);
});

it('rejects completion when source location stock is insufficient and rolls back ledger writes', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $source->id, 2);

    $transfer = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 5],
    ]);
    $this->service->submitTransfer($transfer->id);

    expect(fn () => $this->service->completeTransfer($transfer->id))
        ->toThrow(ValidationException::class)
        ->and($transfer->refresh()->status)->toBe(StockTransfer::STATUS_SUBMITTED)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_transfers')
            ->where('reference_id', $transfer->id)
            ->count())->toBe(0)
        ->and($this->stock->getCurrentStock($product->id, $source->id))->toBe(2.0)
        ->and($this->stock->getCurrentStock($product->id, $destination->id))->toBe(0.0);
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
        ->toThrow(ValidationException::class);
});

it('cancels draft or submitted transfers and blocks completed transfer cancellation', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $cancelled = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);
    $this->service->submitTransfer($cancelled->id);

    expect($this->service->cancelTransfer($cancelled->id, 'No longer needed')->status)
        ->toBe(StockTransfer::STATUS_CANCELLED);

    $this->stock->createOpeningStock($product->id, $source->id, 3);
    $completed = $this->service->createTransfer($source->id, $destination->id, [
        ['product_id' => $product->id, 'quantity' => 1],
    ]);
    $this->service->submitTransfer($completed->id);
    $this->service->completeTransfer($completed->id);

    expect(fn () => $this->service->cancelTransfer($completed->id))
        ->toThrow(ValidationException::class);
});
