<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

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

it('rejects goods receipt store when batch-tracked product has no expiry date with auto batch', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $payload = grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'auto_batch' => true,
        'batch_number' => '',
        'expiry_date' => '',
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)
        ->assertSessionHasErrors('items.0.expiry_date');
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

it('service rejects batch-tracked product without expiry date on create when auto batch is enabled', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $payload = grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'auto_batch' => true,
        'batch_number' => '',
        'expiry_date' => '',
    ]);

    expect(fn () => $this->service->createFromPurchaseOrder($payload, $this->manager))
        ->toThrow(ValidationException::class);
});

it('voids batch tracked goods receipt and preserves batch on reversal movement', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();
    $purchaseMovement = InventoryMovement::query()->find($item->inventory_movement_id);
    $batchId = $item->inventory_batch_id;

    expect($purchaseMovement)->not->toBeNull()
        ->and($purchaseMovement->inventory_batch_id)->toBe($batchId);

    $voided = $this->service->void($posted, $this->manager, 'Koreksi batch penerimaan');
    $reversalMovement = InventoryMovement::query()->find($voided->items()->first()->reversal_movement_id);

    expect($voided->status)->toBe(GoodsReceipt::STATUS_VOID)
        ->and($reversalMovement)->not->toBeNull()
        ->and($reversalMovement->branch_id)->toBe($this->branch->id)
        ->and($reversalMovement->inventory_batch_id)->toBe($batchId)
        ->and((float) $reversalMovement->quantity_out)->toBe(5.0);
});

it('schema includes batch fields on goods receipt items and requires_batch_tracking on products', function () {
    expect(Schema::hasColumn('inv_products', 'requires_batch_tracking'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'inventory_batch_id'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'batch_number'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'lot_number'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'batch_received_date'))->toBeTrue()
        ->and(Schema::hasColumn('trx_goods_receipt_items', 'expiry_date'))->toBeTrue();
});
