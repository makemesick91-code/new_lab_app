<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\StockOpnameService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = userWith(['manage master data']);
    $this->actingAs($this->user);
    $this->stock = app(InventoryStockService::class);
    $this->service = app(StockOpnameService::class);
});

it('creates a draft opname with ledger-derived snapshot items', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 12500]);

    $this->stock->createOpeningStock($product->id, $location->id, 10, 12500);

    $opname = $this->service->createDraftOpname($location->id, [$product->id], 'Cycle count');
    $item = $opname->items->first();

    expect($opname->status)->toBe(StockOpname::STATUS_DRAFT)
        ->and($opname->branch_id)->toBe($this->branch->id)
        ->and($opname->inventory_location_id)->toBe($location->id)
        ->and($opname->created_by)->toBe($this->user->id)
        ->and((float) $item->system_quantity)->toBe(10.0)
        ->and((float) $item->counted_quantity)->toBe(10.0)
        ->and((float) $item->variance_quantity)->toBe(0.0)
        ->and((float) $item->unit_cost)->toBe(12500.0);
});

it('updates counted quantity and variance while opname is editable', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $location->id, 10);
    $opname = $this->service->createDraftOpname($location->id, [$product->id]);

    $item = $this->service->updateCountedQuantity($opname->id, $product->id, 7, 'Physical count');

    expect((float) $item->system_quantity)->toBe(10.0)
        ->and((float) $item->counted_quantity)->toBe(7.0)
        ->and((float) $item->variance_quantity)->toBe(-3.0)
        ->and($item->notes)->toBe('Physical count');
});

it('reviews a draft opname and marks it ready for finalization', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $opname = $this->service->createDraftOpname($location->id, [$product->id]);
    $reviewed = $this->service->reviewOpname($opname->id);

    expect($reviewed->status)->toBe(StockOpname::STATUS_COUNTING)
        ->and($reviewed->counted_by)->toBe($this->user->id);
});

it('finalizes reviewed opname and creates adjustment movements only for non-zero variance', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $plus = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);
    $minus = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 200]);
    $zero = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 300]);

    $this->stock->createOpeningStock($plus->id, $location->id, 10, 100);
    $this->stock->createOpeningStock($minus->id, $location->id, 10, 200);
    $this->stock->createOpeningStock($zero->id, $location->id, 10, 300);

    $opname = $this->service->createDraftOpname($location->id, [$plus->id, $minus->id, $zero->id]);
    $this->service->updateCountedQuantity($opname->id, $plus->id, 12);
    $this->service->updateCountedQuantity($opname->id, $minus->id, 7);
    $this->service->updateCountedQuantity($opname->id, $zero->id, 10);
    $this->service->reviewOpname($opname->id);

    $finalized = $this->service->finalizeOpname($opname->id);
    $generated = InventoryMovement::query()
        ->where('reference_type', 'trx_stock_opnames')
        ->where('reference_id', $opname->id)
        ->orderBy('id')
        ->get();

    expect($finalized->status)->toBe(StockOpname::STATUS_COMPLETED)
        ->and($finalized->completed_at)->not->toBeNull()
        ->and($generated)->toHaveCount(2)
        ->and($generated->pluck('movement_type')->all())->toBe([
            InventoryMovement::TYPE_ADJUSTMENT_IN,
            InventoryMovement::TYPE_ADJUSTMENT_OUT,
        ])
        ->and($this->stock->getCurrentStock($plus->id, $location->id))->toBe(12.0)
        ->and($this->stock->getCurrentStock($minus->id, $location->id))->toBe(7.0)
        ->and($this->stock->getCurrentStock($zero->id, $location->id))->toBe(10.0);
});

it('creates no movement for a zero variance opname', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $location->id, 5);
    $opname = $this->service->createDraftOpname($location->id, [$product->id]);
    $this->service->reviewOpname($opname->id);
    $this->service->finalizeOpname($opname->id);

    expect(InventoryMovement::query()
        ->where('reference_type', 'trx_stock_opnames')
        ->where('reference_id', $opname->id)
        ->count())->toBe(0);
});

it('blocks duplicate finalization', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($product->id, $location->id, 5);
    $opname = $this->service->createDraftOpname($location->id, [$product->id]);
    $this->service->updateCountedQuantity($opname->id, $product->id, 6);
    $this->service->reviewOpname($opname->id);
    $this->service->finalizeOpname($opname->id);

    expect(fn () => $this->service->finalizeOpname($opname->id))
        ->toThrow(ValidationException::class)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_opnames')
            ->where('reference_id', $opname->id)
            ->count())->toBe(1);
});

it('does not allow editing counted quantities after finalization or cancellation', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $completedProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $cancelledProduct = Product::factory()->create(['branch_id' => $this->branch->id]);

    $completed = $this->service->createDraftOpname($location->id, [$completedProduct->id]);
    $this->service->reviewOpname($completed->id);
    $this->service->finalizeOpname($completed->id);

    $cancelled = $this->service->createDraftOpname($location->id, [$cancelledProduct->id]);
    $this->service->cancelOpname($cancelled->id);

    expect(fn () => $this->service->updateCountedQuantity($completed->id, $completedProduct->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->updateCountedQuantity($cancelled->id, $cancelledProduct->id, 1))
        ->toThrow(ValidationException::class);
});

it('enforces branch isolation for locations products and opnames', function () {
    $otherBranch = Branch::factory()->create();
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id]);
    $otherOpname = StockOpname::factory()->create(['branch_id' => $otherBranch->id, 'inventory_location_id' => $otherLocation->id]);

    $opname = $this->service->createDraftOpname($location->id, [$product->id]);

    expect(fn () => $this->service->createDraftOpname($otherLocation->id, [$product->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->updateCountedQuantity($opname->id, $otherProduct->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->reviewOpname($otherOpname->id))
        ->toThrow(ValidationException::class);
});

it('cancels a draft or reviewed opname and blocks finalization afterward', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $opname = $this->service->createDraftOpname($location->id, [$product->id]);
    $this->service->reviewOpname($opname->id);
    $cancelled = $this->service->cancelOpname($opname->id, 'Count aborted');

    expect($cancelled->status)->toBe(StockOpname::STATUS_CANCELLED)
        ->and($cancelled->notes)->toBe('Count aborted')
        ->and(fn () => $this->service->finalizeOpname($opname->id))
        ->toThrow(ValidationException::class);
});

it('rolls back generated movements when finalization fails', function () {
    $otherBranch = Branch::factory()->create();
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $validProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id]);

    $this->stock->createOpeningStock($validProduct->id, $location->id, 5);
    $opname = $this->service->createDraftOpname($location->id, [$validProduct->id]);
    $this->service->updateCountedQuantity($opname->id, $validProduct->id, 6);
    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => $otherProduct->id,
        'system_quantity' => 1,
        'counted_quantity' => 2,
        'variance_quantity' => 1,
    ]);
    $this->service->reviewOpname($opname->id);

    expect(fn () => $this->service->finalizeOpname($opname->id))
        ->toThrow(ValidationException::class)
        ->and($opname->refresh()->status)->toBe(StockOpname::STATUS_COUNTING)
        ->and(InventoryMovement::query()
            ->where('reference_type', 'trx_stock_opnames')
            ->where('reference_id', $opname->id)
            ->count())->toBe(0);
});

it('shows variance review screen with correct summary', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $plus = Product::factory()->create(['branch_id' => $this->branch->id]);
    $minus = Product::factory()->create(['branch_id' => $this->branch->id]);
    $zero = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->stock->createOpeningStock($plus->id, $location->id, 10);
    $this->stock->createOpeningStock($minus->id, $location->id, 10);
    $this->stock->createOpeningStock($zero->id, $location->id, 10);

    $opname = $this->service->createDraftOpname($location->id, [$plus->id, $minus->id, $zero->id]);
    $this->service->updateCountedQuantity($opname->id, $plus->id, 12);
    $this->service->updateCountedQuantity($opname->id, $minus->id, 7);
    $this->service->updateCountedQuantity($opname->id, $zero->id, 10);

    $this->actingAs($this->user)
        ->get(route('inventory.stock-opnames.review-screen', $opname))
        ->assertOk()
        ->assertSee('Review Stock Opname')
        ->assertSee('Total Products')
        ->assertSee('3')
        ->assertSee('Total Variances')
        ->assertSee('2')
        ->assertSee('Overages')
        ->assertSee('1')
        ->assertSee('Shortages')
        ->assertSee('1')
        ->assertSee($plus->name)
        ->assertSee($minus->name)
        ->assertSee($zero->name);
});
