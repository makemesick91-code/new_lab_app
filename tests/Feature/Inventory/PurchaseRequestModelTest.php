<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

it('defines purchase request status constants', function () {
    expect(PurchaseRequest::STATUS_DRAFT)->toBe('draft')
        ->and(PurchaseRequest::STATUS_SUBMITTED)->toBe('submitted')
        ->and(PurchaseRequest::STATUS_APPROVED)->toBe('approved')
        ->and(PurchaseRequest::STATUS_REJECTED)->toBe('rejected')
        ->and(PurchaseRequest::STATUS_CANCELLED)->toBe('cancelled')
        ->and(PurchaseRequest::STATUSES)->toHaveCount(5);
});

it('casts purchase request dates and timestamps', function () {
    $purchaseRequest = PurchaseRequest::factory()->create([
        'branch_id' => $this->branch->id,
        'request_date' => '2026-06-06',
        'approved_at' => now(),
    ]);

    expect($purchaseRequest->request_date->toDateString())->toBe('2026-06-06')
        ->and($purchaseRequest->approved_at)->not->toBeNull();
});

it('resolves purchase request relationships', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $item = PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
    ]);

    $purchaseRequest->refresh()->load(['branch', 'items.product', 'items.inventoryLocation']);

    expect($purchaseRequest->branch)->not->toBeNull()
        ->and($purchaseRequest->items)->toHaveCount(1)
        ->and($item->purchaseRequest->is($purchaseRequest))->toBeTrue()
        ->and($purchaseRequest->items->first()->product->is($product))->toBeTrue()
        ->and($purchaseRequest->items->first()->inventoryLocation->is($location))->toBeTrue();
});

it('creates branch-consistent purchase request factories', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $item = PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
    ]);

    expect($purchaseRequest->branch_id)->toBe($this->branch->id)
        ->and($product->branch_id)->toBe($this->branch->id)
        ->and($item->purchase_request_id)->toBe($purchaseRequest->id);
});

it('does not create inventory movements when purchase request is created', function () {
    $before = InventoryMovement::count();

    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);

    expect(InventoryMovement::count())->toBe($before);
});
