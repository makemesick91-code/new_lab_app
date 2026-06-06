<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Requests\CancelGoodsReceiptRequest;
use App\Modules\Inventory\Requests\PostGoodsReceiptRequest;
use App\Modules\Inventory\Requests\StoreGoodsReceiptRequest;
use App\Modules\Inventory\Requests\UpdateGoodsReceiptRequest;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->actingAs($this->manager);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->goodsReceiptService = app(GoodsReceiptService::class);
});

function makeGoodsReceiptRequest(FormRequest $request, array $data, ?GoodsReceipt $goodsReceipt = null): FormRequest
{
    $request->merge($data);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    if ($goodsReceipt !== null) {
        $route = new Route('PUT', '/inventory/goods-receipts/{goods_receipt}', []);
        $route->parameters = ['goods_receipt' => $goodsReceipt];
        $request->setRouteResolver(fn () => $route);
    }

    return $request;
}

function runGoodsReceiptValidation(FormRequest $request): Illuminate\Contracts\Validation\Validator
{
    $reflection = new ReflectionClass($request);

    if ($reflection->hasMethod('prepareForValidation')) {
        $method = $reflection->getMethod('prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);
    }

    $validator = Validator::make(
        $request->all(),
        $request->rules(),
        method_exists($request, 'messages') ? $request->messages() : [],
        $request->attributes(),
    );

    if (method_exists($request, 'withValidator')) {
        $request->withValidator($validator);
    }

    return $validator;
}

function grRequestFixtures(object $test): array
{
    $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
    $product = Product::factory()->create(['branch_id' => $test->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $test->branch->id]);

    return compact('supplier', 'product', 'location');
}

function grSentPurchaseOrder(object $test, float $quantity = 10): array
{
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grRequestFixtures($test);

    $purchaseOrder = $test->purchaseOrderService->createDraft([
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'items' => [
            [
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'quantity_ordered' => $quantity,
                'unit_price' => 2500,
            ],
        ],
    ], $test->manager);

    $submitted = $test->purchaseOrderService->submit($purchaseOrder, $test->manager);
    $approved = $test->purchaseOrderService->approve($submitted, $test->manager);
    $sent = $test->purchaseOrderService->markAsSent($approved, $test->manager);
    $poItem = $sent->items()->first();

    return compact('supplier', 'product', 'location', 'sent', 'poItem');
}

function validGoodsReceiptStorePayload(
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

it('store rejects cross branch purchase order', function () {
    ['product' => $product, 'location' => $location] = grRequestFixtures($this);

    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherPo = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->otherBranch->id]);
    $poItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $otherPo->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
        'quantity_ordered' => 10,
    ]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($otherPo->id, $poItem->id, $product->id, $location->id),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('purchase_order_id'))->toBeTrue();
});

it('store rejects fully received purchase order', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this, 5);

    $sent->update(['status' => PurchaseOrder::STATUS_FULLY_RECEIVED]);
    $poItem->update(['quantity_received' => 5]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('purchase_order_id'))->toBeTrue();
});

it('store rejects over receiving', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this, 5);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id, 6),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.accepted_qty'))->toBeTrue();
});

it('store rejects accepted_qty and rejected_qty mismatch with received_qty', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id, 5, 0, [
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'received_qty' => 10,
                    'accepted_qty' => 5,
                    'rejected_qty' => 0,
                ],
            ],
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.received_qty'))->toBeTrue();
});

it('store rejects cross branch inventory location', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product] = grSentPurchaseOrder($this);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $otherLocation->id),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.inventory_location_id'))->toBeTrue();
});

it('store does not allow quantity_received input', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $request = makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id, 5, 0, [
            'quantity_received' => 99,
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'received_qty' => 5,
                    'accepted_qty' => 5,
                    'rejected_qty' => 0,
                    'quantity_received' => 50,
                ],
            ],
        ]),
    );

    runGoodsReceiptValidation($request);

    expect($request->input('quantity_received'))->toBeNull()
        ->and($request->input('items.0.quantity_received'))->toBeNull();
});

it('store does not allow inventory_movement_id input', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $request = makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id, 5, 0, [
            'inventory_movement_id' => 123,
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'received_qty' => 5,
                    'accepted_qty' => 5,
                    'rejected_qty' => 0,
                    'inventory_movement_id' => 456,
                ],
            ],
        ]),
    );

    runGoodsReceiptValidation($request);

    expect($request->input('inventory_movement_id'))->toBeNull()
        ->and($request->input('items.0.inventory_movement_id'))->toBeNull();
});

it('update rejects posted goods receipt', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $goodsReceipt = $this->goodsReceiptService->createFromPurchaseOrder(
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id),
        $this->manager,
    );

    $goodsReceipt->update([
        'status' => GoodsReceipt::STATUS_POSTED,
        'posted_at' => now(),
        'posted_by' => $this->manager->id,
    ]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new UpdateGoodsReceiptRequest,
        [
            'receipt_date' => now()->toDateString(),
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'received_qty' => 3,
                    'accepted_qty' => 3,
                    'rejected_qty' => 0,
                ],
            ],
        ],
        $goodsReceipt,
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('goods_receipt'))->toBeTrue();
});

it('post rejects already posted goods receipt', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($sent)
        ->posted()
        ->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

    GoodsReceiptItem::factory()->forGoodsReceipt($goodsReceipt, $poItem)->create();

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new PostGoodsReceiptRequest,
        [],
        $goodsReceipt,
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('goods_receipt'))->toBeTrue();
});

it('post rejects cancelled goods receipt', function () {
    ['sent' => $sent, 'poItem' => $poItem] = grSentPurchaseOrder($this);

    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($sent)
        ->cancelled()
        ->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

    GoodsReceiptItem::factory()->forGoodsReceipt($goodsReceipt, $poItem)->create();

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new PostGoodsReceiptRequest,
        [],
        $goodsReceipt,
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('goods_receipt'))->toBeTrue();
});

it('post rejects goods receipt that already has posted movements', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($sent)
        ->draft()
        ->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

    $movement = InventoryMovement::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
        'reference_type' => $goodsReceipt->getTable(),
        'reference_id' => $goodsReceipt->id,
    ]);

    GoodsReceiptItem::factory()->forGoodsReceipt($goodsReceipt, $poItem)->create([
        'inventory_movement_id' => $movement->id,
    ]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new PostGoodsReceiptRequest,
        [],
        $goodsReceipt,
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('goods_receipt'))->toBeTrue();
});

it('cancel accepts draft goods receipt', function () {
    ['sent' => $sent] = grSentPurchaseOrder($this);

    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($sent)
        ->draft()
        ->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new CancelGoodsReceiptRequest,
        ['notes' => 'Batalkan draft'],
        $goodsReceipt,
    ));

    expect($validator->passes())->toBeTrue();
});

it('cancel accepts submitted goods receipt', function () {
    ['sent' => $sent] = grSentPurchaseOrder($this);

    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($sent)
        ->submitted()
        ->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new CancelGoodsReceiptRequest,
        ['notes' => 'Batalkan submit'],
        $goodsReceipt,
    ));

    expect($validator->passes())->toBeTrue();
});

it('cancel rejects posted goods receipt', function () {
    ['sent' => $sent] = grSentPurchaseOrder($this);

    $goodsReceipt = GoodsReceipt::factory()
        ->forPurchaseOrder($sent)
        ->posted()
        ->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new CancelGoodsReceiptRequest,
        [],
        $goodsReceipt,
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('goods_receipt'))->toBeTrue();
});

it('store does not allow unit_cost or line_total input', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $request = makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id, 5, 0, [
            'unit_cost' => 1,
            'line_total' => 999,
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'received_qty' => 5,
                    'accepted_qty' => 5,
                    'rejected_qty' => 0,
                    'unit_cost' => 1,
                    'line_total' => 999,
                ],
            ],
        ]),
    );

    runGoodsReceiptValidation($request);

    expect($request->input('unit_cost'))->toBeNull()
        ->and($request->input('line_total'))->toBeNull()
        ->and($request->input('items.0.unit_cost'))->toBeNull()
        ->and($request->input('items.0.line_total'))->toBeNull();
});

it('accepts valid store goods receipt payload', function () {
    ['sent' => $sent, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = grSentPurchaseOrder($this);

    $validator = runGoodsReceiptValidation(makeGoodsReceiptRequest(
        new StoreGoodsReceiptRequest,
        validGoodsReceiptStorePayload($sent->id, $poItem->id, $product->id, $location->id),
    ));

    expect($validator->passes())->toBeTrue();
});
