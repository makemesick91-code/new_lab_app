<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Requests\CancelStockOpnameRequest;
use App\Modules\Inventory\Requests\FinalizeStockOpnameRequest;
use App\Modules\Inventory\Requests\ReviewStockOpnameRequest;
use App\Modules\Inventory\Requests\StoreStockOpnameRequest;
use App\Modules\Inventory\Requests\UpdateStockOpnameItemRequest;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('validates store stock opname request', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $requestClass = new StoreStockOpnameRequest;

    // Valid data
    $validData = [
        'inventory_location_id' => $location->id,
        'opname_date' => now()->toDateString(),
        'notes' => 'Cycle count',
    ];
    $validator = Validator::make($validData, $requestClass->rules());
    expect($validator->passes())->toBeTrue();

    // Test invalid data
    $validator = Validator::make([], $requestClass->rules());
    expect($validator->fails())->toBeTrue();
    $validator = Validator::make(['opname_date' => 'invalid'], $requestClass->rules());
    expect($validator->fails())->toBeTrue();
    $validator = Validator::make(['inventory_location_id' => 9999], $requestClass->rules());
    expect($validator->fails())->toBeTrue();
});

it('validates update stock opname item request', function () {
    $requestClass = new UpdateStockOpnameItemRequest;

    // Valid data
    $validData = [
        'counted_quantity' => 10,
        'notes' => 'Counted correctly',
    ];
    $validator = Validator::make($validData, $requestClass->rules());
    expect($validator->passes())->toBeTrue();

    // Test negative quantity
    $validator = Validator::make(['counted_quantity' => -5], $requestClass->rules());
    expect($validator->fails())->toBeTrue();
    // Test missing quantity
    $validator = Validator::make([], $requestClass->rules());
    expect($validator->fails())->toBeTrue();
});

it('validates review stock opname request', function () {
    $requestClass = new ReviewStockOpnameRequest;

    // Valid data with notes
    $validData = ['notes' => 'Reviewed and ready'];
    $validator = Validator::make($validData, $requestClass->rules());
    expect($validator->passes())->toBeTrue();

    // Test without notes (allowed)
    $validator = Validator::make([], $requestClass->rules());
    expect($validator->passes())->toBeTrue();
});

it('validates finalize stock opname request', function () {
    $requestClass = new FinalizeStockOpnameRequest;

    // Valid data with notes
    $validData = ['notes' => 'Finalized'];
    $validator = Validator::make($validData, $requestClass->rules());
    expect($validator->passes())->toBeTrue();

    // Test without notes (allowed)
    $validator = Validator::make([], $requestClass->rules());
    expect($validator->passes())->toBeTrue();
});

it('validates cancel stock opname request requires notes', function () {
    $requestClass = new CancelStockOpnameRequest;

    // Valid data
    $validData = ['notes' => 'Cancelled due to mistake'];
    $validator = Validator::make($validData, $requestClass->rules());
    expect($validator->passes())->toBeTrue();

    // Test missing notes
    $validator = Validator::make([], $requestClass->rules());
    expect($validator->fails())->toBeTrue();
});
