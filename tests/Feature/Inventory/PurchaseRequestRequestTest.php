<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Requests\RejectPurchaseRequestRequest;
use App\Modules\Inventory\Requests\StorePurchaseRequestRequest;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->product = Product::factory()->create(['branch_id' => $this->branch->id]);
});

it('requires items on store purchase request', function () {
    $request = new StorePurchaseRequestRequest;
    $validator = Validator::make([
        'request_date' => now()->toDateString(),
        'items' => [],
    ], $request->rules(), $request->messages());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items'))->toBeTrue();
});

it('requires quantity greater than zero', function () {
    $request = new StorePurchaseRequestRequest;
    $validator = Validator::make([
        'request_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $this->product->id, 'quantity_requested' => 0],
        ],
    ], $request->rules(), $request->messages());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.quantity_requested'))->toBeTrue();
});

it('requires rejection reason on reject request', function () {
    $request = new RejectPurchaseRequestRequest;
    $validator = Validator::make([], $request->rules(), $request->messages());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('rejection_reason'))->toBeTrue();
});

it('denies view only user from mutating purchase requests via controller', function () {
    $viewer = userWith(['view_inventory']);
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($viewer)
        ->post(route('inventory.purchase-requests.store'), [
            'request_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $this->product->id, 'quantity_requested' => 1],
            ],
        ])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('inventory.purchase-requests.submit', $purchaseRequest))
        ->assertForbidden();
});
