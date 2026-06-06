<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->actingAs($this->manager);
});

function grBatchPayload(
    int $purchaseOrderId,
    int $poItemId,
    int $productId,
    int $locationId,
    float $acceptedQty = 5,
    array $batchOverrides = [],
    array $overrides = [],
): array {
    return array_merge([
        'purchase_order_id' => $purchaseOrderId,
        'receipt_date' => now()->toDateString(),
        'items' => [
            array_merge([
                'purchase_order_item_id' => $poItemId,
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'received_qty' => $acceptedQty,
                'accepted_qty' => $acceptedQty,
                'rejected_qty' => 0,
                'batch_mode' => 'new',
                'batch_number' => 'B-GR-001',
                'lot_number' => 'LOT-GR-01',
                'batch_received_date' => now()->toDateString(),
                'expiry_date' => now()->addYear()->toDateString(),
            ], $batchOverrides),
        ],
    ], $overrides);
}

function createSentPurchaseOrderWithBatchProduct(object $test, float $quantity = 10): array
{
    $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $test->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $test->branch->id]);

    $purchaseOrder = $test->purchaseOrderService->createDraft([
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'items' => [[
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'quantity_ordered' => $quantity,
            'unit_price' => 2500,
        ]],
    ], $test->manager);

    $submitted = $test->purchaseOrderService->submit($purchaseOrder, $test->manager);
    $approved = $test->purchaseOrderService->approve($submitted, $test->manager);
    $sent = $test->purchaseOrderService->markAsSent($approved, $test->manager);
    $poItem = $sent->items()->first();

    return compact('supplier', 'product', 'location', 'sent', 'poItem');
}

it('rejects goods receipt store when batch-tracked product has no batch_number', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $payload = grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'batch_number' => '',
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)
        ->assertSessionHasErrors('items.0.batch_number');
});

it('creates goods receipt when batch-tracked product has valid batch_number', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $response = $this->post(route('inventory.goods-receipts.store'), grBatchPayload(
        $po->id,
        $poItem->id,
        $product->id,
        $location->id,
    ));

    $goodsReceipt = GoodsReceipt::query()->latest('id')->first();

    $response->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt));

    $item = $goodsReceipt->items()->first();

    expect($item->batch_number)->toBe('B-GR-001')
        ->and($item->lot_number)->toBe('LOT-GR-01')
        ->and($item->batch_received_date)->not->toBeNull();
});

it('allows goods receipt for non-batch product without batch fields', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $response = $this->post(route('inventory.goods-receipts.store'), goodsReceiptPayload(
        $po->id,
        $poItem->id,
        $product->id,
        $location->id,
        5,
    ));

    $goodsReceipt = GoodsReceipt::query()->latest('id')->first();

    $response->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt));

    $item = $goodsReceipt->items()->first();

    expect($item->batch_number)->toBeNull()
        ->and($item->inventory_batch_id)->toBeNull();
});

it('posts purchase movement with inventory_batch_id for batch-tracked goods receipt', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        grBatchPayload($po->id, $poItem->id, $product->id, $location->id),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();
    $movement = InventoryMovement::query()->find($item->inventory_movement_id);

    expect($movement)->not->toBeNull()
        ->and($movement->inventory_batch_id)->not->toBeNull()
        ->and($item->inventory_batch_id)->toBe($movement->inventory_batch_id)
        ->and($movement->inventoryBatch->batch_number)->toBe('B-GR-001');
});

it('rejects batch-tracked goods receipt using batch from another branch', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $otherProduct = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->otherBranch->id]);
    $foreignBatch = InventoryBatch::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'product_id' => $otherProduct->id,
        'batch_number' => 'B-FOREIGN',
    ]);

    $payload = grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'batch_mode' => 'existing',
        'inventory_batch_id' => $foreignBatch->id,
        'batch_number' => null,
    ]);

    expect(fn () => $this->service->createFromPurchaseOrder($payload, $this->manager))
        ->toThrow(ValidationException::class);
});

it('service rejects batch-tracked product without batch_number on create', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $payload = grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'batch_number' => '',
    ]);

    expect(fn () => $this->service->createFromPurchaseOrder($payload, $this->manager))
        ->toThrow(ValidationException::class);
});

it('schema includes batch fields on goods receipt items and requires_batch_tracking on products', function () {
    expect(Schema::hasColumn('inv_products', 'requires_batch_tracking'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'inventory_batch_id'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'batch_number'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'lot_number'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'batch_received_date'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'expiry_date'))->toBeTrue();
});
