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
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory', 'view_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->actingAs($this->manager);
});

function sprint6838AutoBatchPayload(
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
                'auto_batch' => true,
                'expiry_date' => now()->addYear()->toDateString(),
            ], $batchOverrides),
        ],
    ], $overrides);
}

function sprint6838CreateSentPoWithBatchProduct(object $test, ?string $productCode = 'LIDO', float $quantity = 10): array
{
    $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
    $productAttributes = [
        'branch_id' => $test->branch->id,
        'requires_batch_tracking' => true,
    ];

    if ($productCode !== null) {
        $productAttributes['code'] = $productCode;
    } else {
        $productAttributes['code'] = null;
    }

    $product = Product::factory()->create($productAttributes);
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

it('renders auto batch checkbox for batch-tracked product on goods receipt create form', function () {
    ['sent' => $po] = sprint6838CreateSentPoWithBatchProduct($this);

    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('Buat nomor batch otomatis', false)
        ->assertSee('Nomor batch akan dibuat otomatis dari produk dan tanggal expired.', false);
});

it('defaults auto batch checkbox to checked for batch-tracked products in form state', function () {
    ['sent' => $po] = sprint6838CreateSentPoWithBatchProduct($this);

    $response = $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]));

    $response->assertOk();
    expect(str_contains($response->getContent(), '\u0022auto_batch\u0022:true'))->toBeTrue();
});

it('does not enable auto batch in form state for non-batch product', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $response = $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]));

    $response->assertOk();
    expect(str_contains($response->getContent(), '\u0022requires_batch_tracking\u0022:false'))->toBeTrue();
    expect(str_contains($response->getContent(), '\u0022auto_batch\u0022:true'))->toBeFalse();
});

it('allows non-batch product goods receipt without expiry date', function () {
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

    expect($goodsReceipt->items()->first()->expiry_date)->toBeNull();
});

it('creates purchase movement with null batch_id for non-batch product', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $movement = InventoryMovement::query()->find($posted->items()->first()->inventory_movement_id);

    expect($movement)->not->toBeNull()
        ->and($movement->inventory_batch_id)->toBeNull();
});

it('requires expiry date for batch-tracked product with auto batch enabled', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this);

    $payload = sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'expiry_date' => '',
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)
        ->assertSessionHasErrors('items.0.expiry_date');
});

it('does not require manual batch number when auto batch is enabled', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this);

    $response = $this->post(route('inventory.goods-receipts.store'), sprint6838AutoBatchPayload(
        $po->id,
        $poItem->id,
        $product->id,
        $location->id,
    ));

    $goodsReceipt = GoodsReceipt::query()->latest('id')->first();

    $response->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt));

    expect($goodsReceipt->items()->first()->batch_number)->toBeNull();
});

it('auto-creates inventory batch when posting goods receipt with auto batch and expiry date', function () {
    $expiryDate = now()->addMonths(18)->toDateString();
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this, 'LIDO');

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
            'expiry_date' => $expiryDate,
        ]),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();
    $batch = InventoryBatch::query()->find($item->inventory_batch_id);

    expect($batch)->not->toBeNull()
        ->and($batch->batch_number)->toStartWith('AUTO-LIDO-')
        ->and($batch->batch_number)->toContain(Carbon::parse($expiryDate)->format('Ymd'))
        ->and($batch->expiry_date?->toDateString())->toBe($expiryDate);
});

it('uses product id fallback in generated batch number when product code is unavailable', function () {
    $expiryDate = now()->addYear()->toDateString();
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this, '@@@');

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
            'expiry_date' => $expiryDate,
        ]),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $batch = InventoryBatch::query()->find($posted->items()->first()->inventory_batch_id);

    expect($batch)->not->toBeNull()
        ->and($batch->batch_number)->toBe('AUTO-P'.$product->id.'-'.Carbon::parse($expiryDate)->format('Ymd').'-001');
});

it('populates purchase movement batch_id for auto-generated batch', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();
    $movement = InventoryMovement::query()->find($item->inventory_movement_id);

    expect($movement)->not->toBeNull()
        ->and($movement->inventory_batch_id)->not->toBeNull()
        ->and($item->inventory_batch_id)->toBe($movement->inventory_batch_id);
});

it('generates unique batch numbers for same product and expiry on multiple receipts', function () {
    $expiryDate = now()->addYear()->toDateString();
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this, 'COMP', 20);

    $first = $this->service->post(
        $this->service->createFromPurchaseOrder(
            sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, ['expiry_date' => $expiryDate]),
            $this->manager,
        ),
        $this->manager,
    );

    $second = $this->service->post(
        $this->service->createFromPurchaseOrder(
            sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, ['expiry_date' => $expiryDate]),
            $this->manager,
        ),
        $this->manager,
    );

    $firstBatch = InventoryBatch::query()->find($first->items()->first()->inventory_batch_id);
    $secondBatch = InventoryBatch::query()->find($second->items()->first()->inventory_batch_id);

    expect($firstBatch)->not->toBeNull()
        ->and($secondBatch)->not->toBeNull()
        ->and($firstBatch->id)->not->toBe($secondBatch->id)
        ->and($firstBatch->batch_number)->toEndWith('-001')
        ->and($secondBatch->batch_number)->toEndWith('-002');
});

it('displays auto-generated batch on batch index and show pages', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this, 'ANES');

    $posted = $this->service->post(
        $this->service->createFromPurchaseOrder(
            sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id),
            $this->manager,
        ),
        $this->manager,
    );

    $batch = InventoryBatch::query()->findOrFail($posted->items()->first()->inventory_batch_id);

    $this->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee($batch->batch_number, false)
        ->assertSee('Auto', false);

    $this->get(route('inventory.batches.show', $batch))
        ->assertOk()
        ->assertSee($batch->batch_number, false)
        ->assertSee('Auto', false);
});

it('requires manual batch number when auto batch is disabled', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this);

    $payload = sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'auto_batch' => false,
        'batch_number' => '',
        'batch_received_date' => now()->toDateString(),
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)
        ->assertSessionHasErrors('items.0.batch_number');
});

it('still supports manual batch number workflow when auto batch is disabled', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
            'auto_batch' => false,
            'batch_number' => 'MANUAL-B-001',
            'batch_received_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $batch = InventoryBatch::query()->find($posted->items()->first()->inventory_batch_id);

    expect($batch)->not->toBeNull()
        ->and($batch->batch_number)->toBe('MANUAL-B-001');
});

it('service rejects batch-tracked product without expiry date on create when auto batch is default', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = sprint6838CreateSentPoWithBatchProduct($this);

    $payload = sprint6838AutoBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'expiry_date' => '',
    ]);

    expect(fn () => $this->service->createFromPurchaseOrder($payload, $this->manager))
        ->toThrow(ValidationException::class);
});
