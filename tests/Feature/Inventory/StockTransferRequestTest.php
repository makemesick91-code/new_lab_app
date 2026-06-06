<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Requests\CancelStockTransferRequest;
use App\Modules\Inventory\Requests\CompleteStockTransferRequest;
use App\Modules\Inventory\Requests\StoreStockTransferRequest;
use App\Modules\Inventory\Requests\SubmitStockTransferRequest;
use App\Modules\Inventory\Requests\UpdateStockTransferRequest;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function validateInventoryRequest(FormRequest $request, array $data): Illuminate\Contracts\Validation\Validator
{
    $request->merge($data);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    $validator = Validator::make($request->all(), $request->rules(), $request->messages(), $request->attributes());

    if (method_exists($request, 'withValidator')) {
        $request->withValidator($validator);
    }

    return $validator;
}

function validStockTransferPayload(int $sourceId, int $destinationId, int $productId): array
{
    return [
        'source_inventory_location_id' => $sourceId,
        'destination_inventory_location_id' => $destinationId,
        'transfer_date' => now()->toDateString(),
        'notes' => 'Move stock to QC',
        'items' => [
            [
                'product_id' => $productId,
                'quantity' => 2.5,
                'notes' => 'Line note',
            ],
        ],
    ];
}

it('validates store stock transfer request with branch-safe active locations and products', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $validator = validateInventoryRequest(
        new StoreStockTransferRequest,
        validStockTransferPayload($source->id, $destination->id, $product->id),
    );

    expect($validator->passes())->toBeTrue();
});

it('validates update stock transfer request with the same input rules as store', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $validator = validateInventoryRequest(
        new UpdateStockTransferRequest,
        validStockTransferPayload($source->id, $destination->id, $product->id),
    );

    expect($validator->passes())->toBeTrue();
});

it('rejects stock transfer requests when source and destination are the same', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $validator = validateInventoryRequest(
        new StoreStockTransferRequest,
        validStockTransferPayload($location->id, $location->id, $product->id),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('destination_inventory_location_id'))->toBeTrue();
});

it('rejects stock transfer requests without items', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $validator = validateInventoryRequest(new StoreStockTransferRequest, [
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
        'items' => [],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items'))->toBeTrue();
});

it('rejects stock transfer requests with invalid quantities', function (mixed $quantity) {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $payload = validStockTransferPayload($source->id, $destination->id, $product->id);
    $payload['items'][0]['quantity'] = $quantity;

    $validator = validateInventoryRequest(new StoreStockTransferRequest, $payload);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('items.0.quantity'))->toBeTrue();
})->with([
    'zero' => [0],
    'negative' => [-1],
    'non numeric' => ['abc'],
]);

it('rejects stock transfer requests for locations outside the active branch', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $validator = validateInventoryRequest(
        new StoreStockTransferRequest,
        validStockTransferPayload($source->id, $destination->id, $product->id),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('source_inventory_location_id'))->toBeTrue();
});

it('rejects stock transfer requests for inactive or cross-branch products', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $inactiveProduct = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $otherBranchProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);

    $inactiveValidator = validateInventoryRequest(
        new StoreStockTransferRequest,
        validStockTransferPayload($source->id, $destination->id, $inactiveProduct->id),
    );

    $crossBranchValidator = validateInventoryRequest(
        new StoreStockTransferRequest,
        validStockTransferPayload($source->id, $destination->id, $otherBranchProduct->id),
    );

    expect($inactiveValidator->fails())->toBeTrue()
        ->and($inactiveValidator->errors()->has('items.0.product_id'))->toBeTrue()
        ->and($crossBranchValidator->fails())->toBeTrue()
        ->and($crossBranchValidator->errors()->has('items.0.product_id'))->toBeTrue();
});

it('validates submit and complete stock transfer requests without body fields', function () {
    $submitValidator = validateInventoryRequest(new SubmitStockTransferRequest, []);
    $completeValidator = validateInventoryRequest(new CompleteStockTransferRequest, []);

    expect($submitValidator->passes())->toBeTrue()
        ->and($completeValidator->passes())->toBeTrue();
});

it('validates cancel stock transfer request with nullable notes', function () {
    $withNotes = validateInventoryRequest(new CancelStockTransferRequest, ['notes' => 'Cancelled by requester']);
    $withoutNotes = validateInventoryRequest(new CancelStockTransferRequest, []);

    expect($withNotes->passes())->toBeTrue()
        ->and($withoutNotes->passes())->toBeTrue();
});
