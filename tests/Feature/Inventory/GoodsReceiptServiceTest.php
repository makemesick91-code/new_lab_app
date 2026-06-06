<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->actingAs($this->manager);
});

function grBranchFixtures(object $test): array
{
    $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
    $product = Product::factory()->create(['branch_id' => $test->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $test->branch->id]);

    return compact('supplier', 'product', 'location');
}

function grPurchaseOrderPayload(
    int $supplierId,
    int $productId,
    int $locationId,
    float $quantity = 10,
    float $unitPrice = 2500,
): array {
    return [
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplierId,
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_ordered' => $quantity,
                'unit_price' => $unitPrice,
            ],
        ],
    ];
}

function advancePoToSent(object $test, PurchaseOrder $purchaseOrder): PurchaseOrder
{
    $submitted = $test->purchaseOrderService->submit($purchaseOrder, $test->manager);
    $approved = $test->purchaseOrderService->approve($submitted, $test->manager);

    return $test->purchaseOrderService->markAsSent($approved, $test->manager);
}

function createSentPurchaseOrderWithItem(
    object $test,
    float $quantity = 10,
    float $unitPrice = 2500,
): array {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($test);

    $purchaseOrder = $test->purchaseOrderService->createDraft(
        grPurchaseOrderPayload($supplier->id, $product->id, $location->id, $quantity, $unitPrice),
        $test->manager,
    );

    $sent = advancePoToSent($test, $purchaseOrder);
    $poItem = $sent->items()->first();

    return compact('supplier', 'product', 'location', 'purchaseOrder', 'sent', 'poItem');
}

function goodsReceiptPayload(
    int $purchaseOrderId,
    int $poItemId,
    int $productId,
    int $locationId,
    float $acceptedQty = 5,
    float $rejectedQty = 0,
    array $overrides = [],
): array {
    return array_merge([
        'purchase_order_id' => $purchaseOrderId,
        'receipt_date' => now()->toDateString(),
        'items' => [
            [
                'purchase_order_item_id' => $poItemId,
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'received_qty' => $acceptedQty + $rejectedQty,
                'accepted_qty' => $acceptedQty,
                'rejected_qty' => $rejectedQty,
            ],
        ],
    ], $overrides);
}

it('creates goods receipt from receivable purchase order', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    expect($goodsReceipt->status)->toBe(GoodsReceipt::STATUS_DRAFT)
        ->and($goodsReceipt->branch_id)->toBe($this->branch->id)
        ->and($goodsReceipt->purchase_order_id)->toBe($po->id)
        ->and($goodsReceipt->receipt_number)->toStartWith('GR-')
        ->and($goodsReceipt->items)->toHaveCount(1)
        ->and((float) $goodsReceipt->items->first()->accepted_qty)->toBe(5.0);
});

it('blocks goods receipt from non receivable purchase order statuses', function (string $status) {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($this);

    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'status' => $status,
    ]);

    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
        'quantity_ordered' => 10,
    ]);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($purchaseOrder->id, $poItem->id, $product->id, $location->id),
        $this->manager,
    );
})->with([
    PurchaseOrder::STATUS_DRAFT,
    PurchaseOrder::STATUS_SUBMITTED,
    PurchaseOrder::STATUS_CANCELLED,
    PurchaseOrder::STATUS_FULLY_RECEIVED,
])->throws(ValidationException::class);

it('blocks goods receipt from purchase order in another branch', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($this);

    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $otherPo = PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->otherBranch->id,
        'supplier_id' => $otherSupplier->id,
    ]);

    $otherPoItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $otherPo->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
        'quantity_ordered' => 10,
    ]);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($otherPo->id, $otherPoItem->id, $otherProduct->id, $otherLocation->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks goods receipt with inventory location from another branch', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product] = createSentPurchaseOrderWithItem($this);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $otherLocation->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('submits draft goods receipt', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id),
        $this->manager,
    );

    $submitted = $this->service->submit($goodsReceipt, $this->manager);

    expect($submitted->status)->toBe(GoodsReceipt::STATUS_SUBMITTED)
        ->and($submitted->submitted_by)->toBe($this->manager->id)
        ->and($submitted->submitted_at)->not->toBeNull();
});

it('posts goods receipt and creates PURCHASE inventory movements', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);
    $beforeMovements = InventoryMovement::count();

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);

    expect($posted->status)->toBe(GoodsReceipt::STATUS_POSTED)
        ->and($posted->posted_at)->not->toBeNull()
        ->and($posted->posted_by)->toBe($this->manager->id)
        ->and(InventoryMovement::count())->toBe($beforeMovements + 1);

    $movement = InventoryMovement::query()
        ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and((float) $movement->quantity_in)->toBe(5.0)
        ->and((float) $movement->quantity_out)->toBe(0.0);
});

it('includes only accepted quantity in stock movement', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 6, 2),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $movement = InventoryMovement::query()
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->first();

    expect((float) $movement->quantity_in)->toBe(6.0);
});

it('does not create movement for rejected only lines', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 0, 3),
        $this->manager,
    );

    expect(fn () => $this->service->post($goodsReceipt, $this->manager))
        ->toThrow(ValidationException::class);
});

it('snapshots unit cost and line total on post', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10, 3500);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 4),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();

    expect((float) $item->unit_cost)->toBe(3500.0)
        ->and((float) $item->line_total)->toBe(14000.0);

    $movement = InventoryMovement::find($item->inventory_movement_id);
    expect((float) $movement->unit_cost)->toBe(3500.0);
});

it('increases purchase order item quantity_received only by accepted quantity', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 4, 2),
        $this->manager,
    );

    $this->service->post($goodsReceipt, $this->manager);

    expect((float) $poItem->refresh()->quantity_received)->toBe(4.0);
});

it('sets purchase order to partially received after partial post', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 4),
        $this->manager,
    );

    $this->service->post($goodsReceipt, $this->manager);

    expect($po->refresh()->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED);
});

it('sets purchase order to fully received when all quantity is received', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 10),
        $this->manager,
    );

    $this->service->post($goodsReceipt, $this->manager);

    expect($po->refresh()->status)->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED)
        ->and((float) $poItem->refresh()->quantity_received)->toBe(10.0);
});

it('blocks new goods receipt when purchase order is fully received', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $first = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 10),
        $this->manager,
    );
    $this->service->post($first, $this->manager);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 1),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks over receiving on create and post', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 11),
        $this->manager,
    );
})->throws(ValidationException::class);

it('does not duplicate movements when posting twice', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $movementCount = InventoryMovement::query()
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->count();

    expect(fn () => $this->service->post($posted, $this->manager))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->count())->toBe($movementCount);
});

it('blocks double post via posted_at and status guard', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);

    expect($posted->posted_at)->not->toBeNull()
        ->and($posted->status)->toBe(GoodsReceipt::STATUS_POSTED);

    expect(fn () => $this->service->post($posted->fresh(), $this->manager))
        ->toThrow(DomainException::class, 'Penerimaan barang sudah diposting.');
});

it('links movement reference_type and reference_id to goods receipt', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $movement = InventoryMovement::query()
        ->where('reference_type', 'trx_goods_receipts')
        ->where('reference_id', $posted->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->reference_type)->toBe($posted->getTable());
});

it('stores inventory_movement_id on goods receipt item after post', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();

    expect($item->inventory_movement_id)->not->toBeNull()
        ->and(InventoryMovement::find($item->inventory_movement_id))->not->toBeNull();
});

it('rolls back purchase order cache and goods receipt status when movement creation fails', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $product->update(['is_active' => false]);

    expect(fn () => $this->service->post($goodsReceipt, $this->manager))
        ->toThrow(ValidationException::class);

    expect($goodsReceipt->refresh()->status)->toBe(GoodsReceipt::STATUS_DRAFT)
        ->and($goodsReceipt->posted_at)->toBeNull()
        ->and((float) $poItem->refresh()->quantity_received)->toBe(0.0)
        ->and($po->refresh()->status)->toBe(PurchaseOrder::STATUS_SENT)
        ->and(InventoryMovement::query()->where('reference_id', $goodsReceipt->id)->count())->toBe(0);
});

it('cancels draft goods receipt without ledger writes', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);
    $beforeMovements = InventoryMovement::count();

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $cancelled = $this->service->cancel($goodsReceipt, $this->manager);

    expect($cancelled->status)->toBe(GoodsReceipt::STATUS_CANCELLED)
        ->and(InventoryMovement::count())->toBe($beforeMovements);
});

it('creates goods receipt from approved purchase order', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($this);

    $purchaseOrder = $this->purchaseOrderService->createDraft(
        grPurchaseOrderPayload($supplier->id, $product->id, $location->id),
        $this->manager,
    );
    $approved = $this->purchaseOrderService->approve(
        $this->purchaseOrderService->submit($purchaseOrder, $this->manager),
        $this->manager,
    );
    $poItem = $approved->items()->first();

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($approved->id, $poItem->id, $product->id, $location->id, 3),
        $this->manager,
    );

    expect($goodsReceipt->status)->toBe(GoodsReceipt::STATUS_DRAFT)
        ->and($goodsReceipt->purchase_order_id)->toBe($approved->id);
});
