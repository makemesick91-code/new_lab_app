<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->movements = app(InventoryMovementRepository::class);
    $this->actingAs($this->manager);
});

function ledgerStock(object $test, int $productId, int $locationId): float
{
    return $test->movements->currentStock($test->branch->id, $productId, $locationId);
}

it('does not affect stock while goods receipt is draft', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);
    $beforeStock = ledgerStock($this, $product->id, $location->id);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    expect(ledgerStock($this, $product->id, $location->id))->toBe($beforeStock)
        ->and(InventoryMovement::query()->where('reference_type', 'trx_goods_receipts')->count())->toBe(0);
});

it('does not affect stock while goods receipt is submitted', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);
    $beforeStock = ledgerStock($this, $product->id, $location->id);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->service->submit($goodsReceipt, $this->manager);

    expect(ledgerStock($this, $product->id, $location->id))->toBe($beforeStock)
        ->and((float) $poItem->refresh()->quantity_received)->toBe(0.0);
});

it('does not affect stock when draft goods receipt is cancelled', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);
    $beforeStock = ledgerStock($this, $product->id, $location->id);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->service->cancel($goodsReceipt, $this->manager);

    expect(ledgerStock($this, $product->id, $location->id))->toBe($beforeStock)
        ->and((float) $poItem->refresh()->quantity_received)->toBe(0.0);
});

it('increases derived stock by accepted quantity only after post', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);
    $beforeStock = ledgerStock($this, $product->id, $location->id);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 6, 2),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);

    expect(ledgerStock($this, $product->id, $location->id))->toBe($beforeStock + 6.0);

    $movement = InventoryMovement::query()
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->first();

    expect($movement)->not->toBeNull()
        ->and($movement->branch_id)->toBe($this->branch->id)
        ->and($movement->product_id)->toBe($product->id)
        ->and($movement->inventory_location_id)->toBe($location->id)
        ->and($movement->movement_type)->toBe(InventoryMovement::TYPE_PURCHASE);
});

it('accumulates quantity_received across multiple posted goods receipts', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $first = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 4),
        $this->manager,
    );
    $this->service->post($first, $this->manager);

    $second = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 3),
        $this->manager,
    );
    $this->service->post($second, $this->manager);

    expect((float) $poItem->refresh()->quantity_received)->toBe(7.0)
        ->and($po->refresh()->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED);

    $sumAccepted = (float) GoodsReceiptItem::query()
        ->whereHas('goodsReceipt', fn ($q) => $q
            ->where('purchase_order_id', $po->id)
            ->where('status', GoodsReceipt::STATUS_POSTED))
        ->sum('accepted_qty');

    expect($sumAccepted)->toBe(7.0)
        ->and((float) $poItem->quantity_received)->toBe($sumAccepted);
});

it('sets approved purchase order to partially received after first post', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($this);

    $purchaseOrder = $this->purchaseOrderService->createDraft(
        grPurchaseOrderPayload($supplier->id, $product->id, $location->id, 10),
        $this->manager,
    );
    $approved = $this->purchaseOrderService->approve(
        $this->purchaseOrderService->submit($purchaseOrder, $this->manager),
        $this->manager,
    );
    $poItem = $approved->items()->first();

    expect($approved->status)->toBe(PurchaseOrder::STATUS_APPROVED);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($approved->id, $poItem->id, $product->id, $location->id, 4),
        $this->manager,
    );

    $this->service->post($goodsReceipt, $this->manager);

    expect($approved->refresh()->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED);
});

it('does not add mutable stock columns to inventory tables during goods receipt hardening', function () {
    $forbiddenColumns = [
        'current_stock',
        'stock_qty',
        'qty_on_hand',
        'available_stock',
    ];

    foreach (['inv_products', 'inv_inventory_locations', 'trx_goods_receipts', 'trx_goods_receipt_items'] as $table) {
        foreach ($forbiddenColumns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeFalse("{$table} must not have {$column}");
        }
    }
});

it('maintains audit chain from movement to purchase order', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();
    $movement = InventoryMovement::find($item->inventory_movement_id);

    expect($movement)->not->toBeNull()
        ->and($movement->reference_type)->toBe($posted->getTable())
        ->and($movement->reference_id)->toBe($posted->id)
        ->and($item->goods_receipt_id)->toBe($posted->id)
        ->and($item->purchase_order_item_id)->toBe($poItem->id)
        ->and($poItem->purchase_order_id)->toBe($po->id);
});
