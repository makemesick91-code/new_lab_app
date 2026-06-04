<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
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
