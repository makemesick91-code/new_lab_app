<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\PurchaseRequestService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->purchaseRequestService = app(PurchaseRequestService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->goodsReceiptService = app(GoodsReceiptService::class);
    $this->movements = app(InventoryMovementRepository::class);
    $this->actingAs($this->manager);
});

it('completes purchase request to purchase order to goods receipt to ledger flow', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($this);
    $beforeStock = $this->movements->currentStock($this->branch->id, $product->id, $location->id);

    $purchaseRequest = $this->purchaseRequestService->createDraft([
        'request_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'quantity_requested' => 8,
                'estimated_unit_price' => 5000,
            ],
        ],
    ], $this->manager);

    $approvedRequest = $this->purchaseRequestService->approve(
        $this->purchaseRequestService->submit($purchaseRequest, $this->manager),
        $this->manager,
    );

    expect($approvedRequest->status)->toBe(PurchaseRequest::STATUS_APPROVED);

    $purchaseOrder = $this->purchaseOrderService->createDraftFromPurchaseRequest(
        $approvedRequest,
        ['supplier_id' => $supplier->id],
        $this->manager,
    );

    $sentPo = advancePoToSent($this, $purchaseOrder);
    $poItem = $sentPo->items()->first();

    expect($sentPo->status)->toBe(PurchaseOrder::STATUS_SENT)
        ->and($sentPo->purchase_request_id)->toBe($approvedRequest->id);

    $goodsReceipt = $this->goodsReceiptService->createFromPurchaseOrder(
        goodsReceiptPayload($sentPo->id, $poItem->id, $product->id, $location->id, 8),
        $this->manager,
    );

    expect($goodsReceipt->status)->toBe(GoodsReceipt::STATUS_DRAFT);

    $posted = $this->goodsReceiptService->post($goodsReceipt, $this->manager);

    expect($posted->status)->toBe(GoodsReceipt::STATUS_POSTED)
        ->and($sentPo->refresh()->status)->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED)
        ->and((float) $poItem->refresh()->quantity_received)->toBe(8.0)
        ->and($this->movements->currentStock($this->branch->id, $product->id, $location->id))->toBe($beforeStock + 8.0);

    $movement = InventoryMovement::query()
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
        ->first();

    $grItem = $posted->items()->first();

    expect($movement)->not->toBeNull()
        ->and($movement->id)->toBe($grItem->inventory_movement_id)
        ->and($grItem->purchase_order_item_id)->toBe($poItem->id)
        ->and($poItem->purchase_order_id)->toBe($sentPo->id)
        ->and(PurchaseOrder::find($sentPo->id)?->status)->toBe(PurchaseOrder::STATUS_FULLY_RECEIVED)
        ->and(GoodsReceipt::find($posted->id)?->status)->toBe(GoodsReceipt::STATUS_POSTED);
});
