<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

it('defines goods receipt status constants', function () {
    expect(GoodsReceipt::STATUS_DRAFT)->toBe('draft')
        ->and(GoodsReceipt::STATUS_SUBMITTED)->toBe('submitted')
        ->and(GoodsReceipt::STATUS_POSTED)->toBe('posted')
        ->and(GoodsReceipt::STATUS_CANCELLED)->toBe('cancelled');
});

it('resolves goods receipt relationships', function () {
    $purchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($purchaseOrder)
        ->create(['branch_id' => $this->branch->id]);

    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
    ]);

    $item = GoodsReceiptItem::factory()
        ->forGoodsReceipt($goodsReceipt, $poItem)
        ->create();

    $goodsReceipt->refresh()->load([
        'branch',
        'purchaseOrder',
        'items.product',
        'items.inventoryLocation',
        'items.purchaseOrderItem',
        'createdBy',
    ]);

    expect($goodsReceipt->branch->is($this->branch))->toBeTrue()
        ->and($goodsReceipt->purchaseOrder->is($purchaseOrder))->toBeTrue()
        ->and($goodsReceipt->items)->toHaveCount(1)
        ->and($purchaseOrder->goodsReceipts->contains($goodsReceipt))->toBeTrue()
        ->and($item->goodsReceipt->is($goodsReceipt))->toBeTrue()
        ->and($item->product->is($product))->toBeTrue()
        ->and($item->inventoryLocation->is($location))->toBeTrue()
        ->and($item->purchaseOrderItem->is($poItem))->toBeTrue()
        ->and($poItem->goodsReceiptItems->contains($item))->toBeTrue()
        ->and($goodsReceipt->createdBy)->not->toBeNull();
});

it('resolves goods receipt item inventory movement relationship when unset', function () {
    $purchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($purchaseOrder)
        ->create(['branch_id' => $this->branch->id]);
    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
        'inventory_location_id' => InventoryLocation::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);

    $item = GoodsReceiptItem::factory()
        ->forGoodsReceipt($goodsReceipt, $poItem)
        ->create();

    expect($item->inventory_movement_id)->toBeNull()
        ->and($item->inventoryMovement)->toBeNull();
});

it('evaluates goods receipt status helper methods', function () {
    $draft = GoodsReceipt::factory()->draft()->make();
    $submitted = GoodsReceipt::factory()->submitted()->make();
    $posted = GoodsReceipt::factory()->posted()->make();
    $cancelled = GoodsReceipt::factory()->cancelled()->make();

    expect($draft->isDraft())->toBeTrue()
        ->and($draft->canBeEdited())->toBeTrue()
        ->and($draft->canBePosted())->toBeTrue()
        ->and($draft->canBeCancelled())->toBeTrue()
        ->and($submitted->isSubmitted())->toBeTrue()
        ->and($submitted->canBeEdited())->toBeFalse()
        ->and($submitted->canBePosted())->toBeTrue()
        ->and($submitted->canBeCancelled())->toBeFalse()
        ->and($posted->isPosted())->toBeTrue()
        ->and($posted->isTerminal())->toBeTrue()
        ->and($posted->canBeEdited())->toBeFalse()
        ->and($posted->canBePosted())->toBeFalse()
        ->and($posted->canBeCancelled())->toBeFalse()
        ->and($cancelled->isCancelled())->toBeTrue()
        ->and($cancelled->isTerminal())->toBeTrue();
});

it('creates branch-consistent draft goods receipt with items via factories', function () {
    $purchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
        'inventory_location_id' => InventoryLocation::factory()->create(['branch_id' => $this->branch->id])->id,
        'quantity_ordered' => 10,
        'unit_price' => 2500,
    ]);

    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($purchaseOrder)
        ->create(['branch_id' => $this->branch->id]);

    $item = GoodsReceiptItem::factory()
        ->forGoodsReceipt($goodsReceipt, $poItem)
        ->create();

    expect($goodsReceipt->branch_id)->toBe($this->branch->id)
        ->and($goodsReceipt->purchaseOrder->branch_id)->toBe($this->branch->id)
        ->and($goodsReceipt->status)->toBe(GoodsReceipt::STATUS_DRAFT)
        ->and($item->product->branch_id)->toBe($this->branch->id)
        ->and($item->inventoryLocation->branch_id)->toBe($this->branch->id)
        ->and((float) $item->received_qty)->toBe((float) $item->accepted_qty + (float) $item->rejected_qty);
});

it('does not create inventory movements when goods receipt factories run', function () {
    $before = InventoryMovement::count();

    $purchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($purchaseOrder)
        ->create(['branch_id' => $this->branch->id]);
    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
        'inventory_location_id' => InventoryLocation::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);

    GoodsReceiptItem::factory()
        ->forGoodsReceipt($goodsReceipt, $poItem)
        ->create();

    expect(InventoryMovement::count())->toBe($before);
});

it('casts purchase order item quantity_received and computes quantityRemaining', function () {
    $item = PurchaseOrderItem::factory()->make([
        'quantity_ordered' => 10,
        'quantity_received' => 3,
    ]);

    expect($item->quantityRemaining())->toBe(7.0);
});

it('includes receiving statuses on purchase order model constants', function () {
    expect(PurchaseOrder::STATUS_PARTIALLY_RECEIVED)->toBe('partially_received')
        ->and(PurchaseOrder::STATUS_FULLY_RECEIVED)->toBe('fully_received')
        ->and(PurchaseOrder::STATUSES)->toContain('partially_received', 'fully_received')
        ->and(PurchaseOrder::TERMINAL_STATUSES)->toContain('fully_received');
});

it('does not mass assign quantity_received on purchase order items', function () {
    expect((new PurchaseOrderItem)->getFillable())->not->toContain('quantity_received');
});
