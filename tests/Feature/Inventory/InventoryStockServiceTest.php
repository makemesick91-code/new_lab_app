<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->service = app(InventoryStockService::class);
});

it('creates opening stock and calculates stock per location and branch', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'average_cost' => 12500,
    ]);
    $warehouse = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $qcRoom = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $warehouse->id, 10, 12500);
    $this->service->adjustIn($product->id, $warehouse->id, 3);
    $this->service->createOpeningStock($product->id, $qcRoom->id, 7, 12500);

    expect($this->service->getCurrentStock($product->id, $warehouse->id))->toBe(13.0)
        ->and($this->service->getCurrentStock($product->id, $qcRoom->id))->toBe(7.0)
        ->and($this->service->getCurrentStock($product->id))->toBe(20.0);
});

it('receives stock with an optional supplier from the active branch', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

    $movement = $this->service->receiveStock($product->id, $location->id, 5, 25000, $supplier->id, 'received in test');

    expect($movement->movement_type)->toBe(InventoryMovement::TYPE_PURCHASE)
        ->and($movement->branch_id)->toBe($this->branch->id)
        ->and($movement->inventory_location_id)->toBe($location->id)
        ->and($movement->product_id)->toBe($product->id)
        ->and($movement->supplier_id)->toBe($supplier->id)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(5.0);
});

it('rejects zero and negative quantities', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->createOpeningStock($product->id, $location->id, 0))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->adjustIn($product->id, $location->id, -1))
        ->toThrow(ValidationException::class);
});

it('rejects adjustment out when location stock is insufficient', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $warehouse = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $qcRoom = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $warehouse->id, 10);

    expect(fn () => $this->service->adjustOut($product->id, $qcRoom->id, 1))
        ->toThrow(ValidationException::class)
        ->and($this->service->getCurrentStock($product->id, $warehouse->id))->toBe(10.0)
        ->and($this->service->getCurrentStock($product->id, $qcRoom->id))->toBe(0.0);
});

it('rejects product location and supplier records outside the active branch', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);
    $otherSupplier = Supplier::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => $this->service->createOpeningStock($otherProduct->id, $location->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createOpeningStock($product->id, $otherLocation->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->receiveStock($product->id, $location->id, 1, 0, $otherSupplier->id))
        ->toThrow(ValidationException::class);
});

it('returns stock card ordered by movement date and id', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 10);
    $this->service->adjustIn($product->id, $location->id, 2);
    $this->service->adjustOut($product->id, $location->id, 3);

    expect($this->service->getStockCard($product->id, $location->id)->pluck('movement_type')->all())
        ->toBe([
            InventoryMovement::TYPE_OPENING,
            InventoryMovement::TYPE_ADJUSTMENT_IN,
            InventoryMovement::TYPE_ADJUSTMENT_OUT,
        ]);
});

it('reports low stock products and inventory value from the ledger', function () {
    $lowProduct = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'average_cost' => 100,
    ]);
    $healthyProduct = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'average_cost' => 50,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($lowProduct->id, $location->id, 4, 100);
    $this->service->createOpeningStock($healthyProduct->id, $location->id, 20, 50);

    expect($this->service->getLowStockProducts($location->id)->pluck('id')->all())
        ->toBe([$lowProduct->id])
        ->and($this->service->getInventoryValue($location->id))->toBe(1400.0);
});

it('respects the location filter when reporting low stock products', function () {
    $product = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'average_cost' => 100,
    ]);
    $healthyLocation = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $lowLocation = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $healthyLocation->id, 20, 100);
    $this->service->createOpeningStock($product->id, $lowLocation->id, 4, 100);

    expect($this->service->getLowStockProducts($healthyLocation->id)->pluck('id')->all())
        ->toBe([])
        ->and($this->service->getLowStockProducts($lowLocation->id)->pluck('id')->all())
        ->toBe([$product->id]);
});

it('rejects stock writes for inactive products', function () {
    $product = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->createOpeningStock($product->id, $location->id, 1))
        ->toThrow(ValidationException::class);
});

it('receives stock and creates a movement with inventory_batch_id when batch metadata is provided', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $movement = $this->service->receiveStock(
        $product->id,
        $location->id,
        5,
        25000,
        null,
        'batch receive',
        [
            'batch_number' => 'B-2026-001',
            'lot_number' => 'LOT-A1',
            'received_date' => '2026-06-01',
            'expiry_date' => '2027-06-01',
        ],
    );

    expect($movement->inventory_batch_id)->not->toBeNull()
        ->and($movement->inventoryBatch->batch_number)->toBe('B-2026-001')
        ->and($movement->inventoryBatch->lot_number)->toBe('LOT-A1')
        ->and($movement->movement_type)->toBe(InventoryMovement::TYPE_PURCHASE)
        ->and($this->service->getCurrentStockByBatch($product->id, $location->id, $movement->inventory_batch_id))->toBe(5.0);
});

it('receives stock using an existing active batch in the same branch', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B-EXIST-001',
    ]);

    $this->service->receiveStock($product->id, $location->id, 4, 0, null, null, [
        'inventory_batch_id' => $batch->id,
    ]);

    $movement = InventoryMovement::query()->latest('id')->first();

    expect($movement->inventory_batch_id)->toBe($batch->id)
        ->and($this->service->getCurrentStockByBatch($product->id, $location->id, $batch->id))->toBe(4.0);
});

it('rejects receive stock when batch belongs to another branch', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id]);
    $foreignBatch = InventoryBatch::factory()->create([
        'branch_id' => $otherBranch->id,
        'product_id' => $otherProduct->id,
    ]);

    expect(fn () => $this->service->receiveStock($product->id, $location->id, 2, 0, null, null, [
        'inventory_batch_id' => $foreignBatch->id,
    ]))->toThrow(ValidationException::class);
});

it('rejects receive stock when batch is inactive', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->inactive()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    expect(fn () => $this->service->receiveStock($product->id, $location->id, 2, 0, null, null, [
        'inventory_batch_id' => $batch->id,
    ]))->toThrow(ValidationException::class);
});

it('rejects receive stock when batch product does not match', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $otherProduct->id,
    ]);

    expect(fn () => $this->service->receiveStock($product->id, $location->id, 2, 0, null, null, [
        'inventory_batch_id' => $batch->id,
    ]))->toThrow(ValidationException::class);
});

it('supports adjustment in with inventory_batch_id', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    $movement = $this->service->adjustIn($product->id, $location->id, 3, 'adjust in batch', [
        'inventory_batch_id' => $batch->id,
    ]);

    expect($movement->inventory_batch_id)->toBe($batch->id)
        ->and($this->service->getCurrentStockByBatch($product->id, $location->id, $batch->id))->toBe(3.0);
});

it('supports adjustment in that creates a new batch', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $movement = $this->service->adjustIn($product->id, $location->id, 2, 'new batch adjust in', [
        'batch_number' => 'B-ADJ-001',
        'received_date' => '2026-06-06',
    ]);

    expect($movement->inventory_batch_id)->not->toBeNull()
        ->and(InventoryBatch::query()->where('batch_number', 'B-ADJ-001')->exists())->toBeTrue();
});

it('supports adjustment out with inventory_batch_id', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    $this->service->receiveStock($product->id, $location->id, 10, 0, null, null, [
        'inventory_batch_id' => $batch->id,
    ]);

    $movement = $this->service->adjustOut($product->id, $location->id, 4, 'batch out', $batch->id);

    expect($movement->inventory_batch_id)->toBe($batch->id)
        ->and($this->service->getCurrentStockByBatch($product->id, $location->id, $batch->id))->toBe(6.0)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(6.0);
});

it('rejects adjustment out when batch stock at the selected location is insufficient', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $warehouse = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $qcRoom = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
    ]);

    $this->service->receiveStock($product->id, $warehouse->id, 5, 0, null, null, [
        'inventory_batch_id' => $batch->id,
    ]);

    expect(fn () => $this->service->adjustOut($product->id, $qcRoom->id, 1, null, $batch->id))
        ->toThrow(ValidationException::class)
        ->and($this->service->getCurrentStockByBatch($product->id, $warehouse->id, $batch->id))->toBe(5.0);
});

it('derives batch stock from movements only', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $receive = $this->service->receiveStock($product->id, $location->id, 8, 0, null, null, [
        'batch_number' => 'B-LEDGER-001',
        'received_date' => '2026-06-06',
    ]);
    $batchId = $receive->inventory_batch_id;

    $this->service->adjustOut($product->id, $location->id, 3, null, $batchId);

    $ledgerStock = InventoryMovement::query()
        ->where('branch_id', $this->branch->id)
        ->where('product_id', $product->id)
        ->where('inventory_location_id', $location->id)
        ->where('inventory_batch_id', $batchId)
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');

    expect((float) $ledgerStock)->toBe(5.0)
        ->and($this->service->getCurrentStockByBatch($product->id, $location->id, $batchId))->toBe(5.0);
});

it('does not introduce mutable stock columns on products locations or batches', function () {
    $productColumns = Schema::getColumnListing('inv_products');
    $locationColumns = Schema::getColumnListing('inv_inventory_locations');
    $batchColumns = Schema::getColumnListing('inv_inventory_batches');

    $forbidden = [
        'current_stock',
        'quantity_on_hand',
        'available_quantity',
        'stock',
        'qty_on_hand',
    ];

    foreach ($forbidden as $column) {
        expect($productColumns)->not->toContain($column)
            ->and($locationColumns)->not->toContain($column)
            ->and($batchColumns)->not->toContain($column);
    }
});
