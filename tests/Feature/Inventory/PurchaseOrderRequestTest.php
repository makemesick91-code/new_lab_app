<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Requests\StorePurchaseOrderRequest;
use App\Modules\Inventory\Requests\UpdatePurchaseOrderRequest;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->product = Product::factory()->create(['branch_id' => $this->branch->id]);
});

function makePurchaseOrderRequest(FormRequest $request, array $data): FormRequest
{
    $request->merge($data);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    return $request;
}

function runPurchaseOrderValidation(FormRequest $request): Illuminate\Contracts\Validation\Validator
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

function validPurchaseOrderPayload(int $productId, array $overrides = []): array
{
    return array_merge([
        'order_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $productId,
                'quantity_ordered' => 2,
            ],
        ],
    ], $overrides);
}

it('requires order_date on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        [
            'items' => [
                ['product_id' => $this->product->id, 'quantity_ordered' => 1],
            ],
        ],
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('order_date'))->toBeTrue();
});

it('requires at least one item on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        [
            'order_date' => now()->toDateString(),
            'items' => [],
        ],
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items'))->toBeTrue();
});

it('requires item product_id on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        [
            'order_date' => now()->toDateString(),
            'items' => [
                ['quantity_ordered' => 1],
            ],
        ],
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.product_id'))->toBeTrue();
});

it('requires quantity_ordered greater than zero on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'items' => [
                ['product_id' => $this->product->id, 'quantity_ordered' => 0],
            ],
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.quantity_ordered'))->toBeTrue();
});

it('rejects negative unit_price on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity_ordered' => 1,
                    'unit_price' => -1,
                ],
            ],
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.unit_price'))->toBeTrue();
});

it('rejects expected_delivery_date before order_date on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->subDay()->toDateString(),
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('expected_delivery_date'))->toBeTrue();
});

it('enforces supplier_reference_number max length on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'supplier_reference_number' => str_repeat('A', 101),
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('supplier_reference_number'))->toBeTrue();
});

it('enforces notes max length on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'notes' => str_repeat('A', 2001),
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('notes'))->toBeTrue();
});

it('enforces item notes max length on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity_ordered' => 1,
                    'notes' => str_repeat('A', 501),
                ],
            ],
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.notes'))->toBeTrue();
});

it('defaults currency to IDR when omitted on store purchase order', function () {
    $request = makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id),
    );

    runPurchaseOrderValidation($request);

    expect($request->input('currency'))->toBe('IDR');
});

it('defaults empty currency to IDR on store purchase order', function () {
    $request = makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, ['currency' => '']),
    );

    runPurchaseOrderValidation($request);

    expect($request->input('currency'))->toBe('IDR');
});

it('enforces currency max length on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'currency' => str_repeat('A', 11),
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('currency'))->toBeTrue();
});

it('requires purchase_request_id to exist when provided on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'purchase_request_id' => 999999,
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('purchase_request_id'))->toBeTrue();
});

it('requires purchase_request_item_id to exist when provided on store purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity_ordered' => 1,
                    'purchase_request_item_id' => 999999,
                ],
            ],
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.purchase_request_item_id'))->toBeTrue();
});

it('accepts existing purchase_request_id and purchase_request_item_id on store purchase order', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseRequestItem = PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $this->product->id,
    ]);

    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new StorePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'purchase_request_id' => $purchaseRequest->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity_ordered' => 1,
                    'purchase_request_item_id' => $purchaseRequestItem->id,
                ],
            ],
        ]),
    ));

    expect($validator->passes())->toBeTrue();
});

it('validates update purchase order request with the same input rules as store', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new UpdatePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id),
    ));

    expect($validator->passes())->toBeTrue();
});

it('requires order_date on update purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new UpdatePurchaseOrderRequest,
        [
            'items' => [
                ['product_id' => $this->product->id, 'quantity_ordered' => 1],
            ],
        ],
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('order_date'))->toBeTrue();
});

it('rejects negative unit_price on update purchase order', function () {
    $validator = runPurchaseOrderValidation(makePurchaseOrderRequest(
        new UpdatePurchaseOrderRequest,
        validPurchaseOrderPayload($this->product->id, [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity_ordered' => 1,
                    'unit_price' => -5,
                ],
            ],
        ]),
    ));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.unit_price'))->toBeTrue();
});
