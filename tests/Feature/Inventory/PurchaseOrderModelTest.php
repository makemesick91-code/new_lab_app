<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Models\Supplier;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

it('defines purchase order status constants', function () {
    expect(PurchaseOrder::STATUS_DRAFT)->toBe('draft')
        ->and(PurchaseOrder::STATUS_SUBMITTED)->toBe('submitted')
        ->and(PurchaseOrder::STATUS_APPROVED)->toBe('approved')
        ->and(PurchaseOrder::STATUS_SENT)->toBe('sent')
        ->and(PurchaseOrder::STATUS_CANCELLED)->toBe('cancelled');
});

it('limits purchase order statuses to workflow states only', function () {
    expect(PurchaseOrder::STATUSES)->toBe([
        'draft',
        'submitted',
        'approved',
        'sent',
        'partially_received',
        'fully_received',
        'cancelled',
    ]);
});

it('includes receiving statuses reserved in sprint 16.2 design', function () {
    foreach (['partially_received', 'fully_received'] as $status) {
        expect(PurchaseOrder::STATUSES)->toContain($status);
    }

    expect(PurchaseOrder::STATUSES)->not->toContain('closed');
});

it('resolves purchase order relationships', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'purchase_request_id' => $purchaseRequest->id,
    ]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $item = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
    ]);

    $purchaseOrder->refresh()->load([
        'branch',
        'supplier',
        'purchaseRequest',
        'items.product',
        'items.inventoryLocation',
    ]);

    expect($purchaseOrder->branch)->not->toBeNull()
        ->and($purchaseOrder->supplier->is($supplier))->toBeTrue()
        ->and($purchaseOrder->purchaseRequest->is($purchaseRequest))->toBeTrue()
        ->and($purchaseOrder->items)->toHaveCount(1)
        ->and($item->purchaseOrder->is($purchaseOrder))->toBeTrue()
        ->and($purchaseRequest->purchaseOrders->contains($purchaseOrder))->toBeTrue()
        ->and($supplier->purchaseOrders->contains($purchaseOrder))->toBeTrue();
});

it('resolves purchase order item relationships', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseRequestItem = PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);
    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'purchase_request_id' => $purchaseRequest->id,
    ]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $item = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'inventory_location_id' => $location->id,
        'purchase_request_item_id' => $purchaseRequestItem->id,
    ]);

    $item->load(['purchaseOrder', 'product', 'inventoryLocation', 'purchaseRequestItem']);

    expect($item->purchaseOrder->is($purchaseOrder))->toBeTrue()
        ->and($item->product->is($product))->toBeTrue()
        ->and($item->inventoryLocation->is($location))->toBeTrue()
        ->and($item->purchaseRequestItem->is($purchaseRequestItem))->toBeTrue()
        ->and($purchaseRequestItem->purchaseOrderItems->contains($item))->toBeTrue();
});

it('creates branch-consistent manual purchase order factories', function () {
    $purchaseOrder = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $item = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
    ]);

    $purchaseOrder->load(['supplier']);

    expect($purchaseOrder->branch_id)->toBe($this->branch->id)
        ->and($purchaseOrder->purchase_request_id)->toBeNull()
        ->and($purchaseOrder->supplier->branch_id)->toBe($this->branch->id)
        ->and($product->branch_id)->toBe($this->branch->id)
        ->and($item->purchase_order_id)->toBe($purchaseOrder->id);
});

it('defaults purchase order factory to manual PO with null purchase_request_id', function () {
    $purchaseOrder = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);

    expect($purchaseOrder->purchase_request_id)->toBeNull()
        ->and($purchaseOrder->purchaseRequest)->toBeNull();
});

it('links purchase order factory to purchase request via forPurchaseRequest state', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = PurchaseOrder::factory()
        ->forPurchaseRequest($purchaseRequest)
        ->create();

    $purchaseOrder->load(['purchaseRequest', 'supplier']);

    expect($purchaseOrder->purchase_request_id)->toBe($purchaseRequest->id)
        ->and($purchaseOrder->purchaseRequest->is($purchaseRequest))->toBeTrue();
});

it('uses purchase request branch in forPurchaseRequest factory state', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = PurchaseOrder::factory()
        ->forPurchaseRequest($purchaseRequest)
        ->create();

    expect($purchaseOrder->branch_id)->toBe($purchaseRequest->branch_id)
        ->and($purchaseOrder->branch_id)->toBe($this->branch->id);
});

it('creates same-branch supplier in forPurchaseRequest factory state', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = PurchaseOrder::factory()
        ->forPurchaseRequest($purchaseRequest)
        ->create()
        ->load('supplier');

    expect($purchaseOrder->supplier->branch_id)->toBe($purchaseOrder->branch_id)
        ->and($purchaseOrder->supplier->branch_id)->toBe($purchaseRequest->branch_id);
});

it('captures supplier snapshot name in forPurchaseRequest factory state', function () {
    $purchaseRequest = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = PurchaseOrder::factory()
        ->forPurchaseRequest($purchaseRequest)
        ->create()
        ->load('supplier');

    expect($purchaseOrder->supplier_snapshot_name)->toBe($purchaseOrder->supplier->name)
        ->and($purchaseOrder->displaySupplierName())->toBe($purchaseOrder->supplier->name);
});

it('captures supplier snapshot name in default manual purchase order factory', function () {
    $purchaseOrder = PurchaseOrder::factory()
        ->create(['branch_id' => $this->branch->id])
        ->load('supplier');

    expect($purchaseOrder->supplier_snapshot_name)->toBe($purchaseOrder->supplier->name);
});

it('defaults purchase order currency to IDR', function () {
    $purchaseOrder = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);

    expect($purchaseOrder->currency)->toBe('IDR');
});

it('uses supplier snapshot name first in displaySupplierName', function () {
    $supplier = Supplier::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Live Supplier Name',
    ]);

    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'supplier_snapshot_name' => 'Snapshot Supplier Name',
    ]);

    expect($purchaseOrder->displaySupplierName())->toBe('Snapshot Supplier Name');
});

it('falls back to supplier relation name in displaySupplierName', function () {
    $supplier = Supplier::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Relation Supplier Name',
    ]);

    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'supplier_snapshot_name' => null,
    ]);

    expect($purchaseOrder->displaySupplierName())->toBe('Relation Supplier Name');
});

it('returns em dash in displaySupplierName when supplier info is missing', function () {
    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => null,
        'supplier_snapshot_name' => null,
    ]);

    expect($purchaseOrder->displaySupplierName())->toBe('—');
});

it('sums purchase order totalAmount from line quantities and prices', function () {
    $purchaseOrder = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity_ordered' => 2,
        'unit_price' => 1000,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity_ordered' => 3,
        'unit_price' => null,
    ]);

    expect($purchaseOrder->fresh()->totalAmount())->toBe(2000.0);
});

it('matches total_amount accessor to totalAmount', function () {
    $purchaseOrder = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'quantity_ordered' => 4,
        'unit_price' => 2500,
    ]);

    $purchaseOrder = $purchaseOrder->fresh();

    expect($purchaseOrder->total_amount)->toBe($purchaseOrder->totalAmount())
        ->and($purchaseOrder->total_amount)->toBe(10000.0);
});

it('calculates purchase order item lineTotal treating null unit price as zero', function () {
    $item = PurchaseOrderItem::factory()->make([
        'quantity_ordered' => 5,
        'unit_price' => 1500,
    ]);

    expect($item->lineTotal())->toBe(7500.0);

    $itemWithoutPrice = PurchaseOrderItem::factory()->make([
        'quantity_ordered' => 5,
        'unit_price' => null,
    ]);

    expect($itemWithoutPrice->lineTotal())->toBe(0.0);
});

it('does not create inventory movements when purchase order models are created', function () {
    $before = InventoryMovement::count();

    $purchaseOrder = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);

    expect(InventoryMovement::count())->toBe($before);
});
