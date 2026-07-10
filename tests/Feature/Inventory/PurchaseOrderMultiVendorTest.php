<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(PurchaseOrderService::class);
    $this->goodsReceiptService = app(GoodsReceiptService::class);
    $this->actingAs($this->manager);
});

function multiVendorItem(int $productId, int $supplierId, array $overrides = []): array
{
    return array_merge([
        'product_id' => $productId,
        'supplier_id' => $supplierId,
        'quantity_ordered' => 5,
        'unit_price' => 10000,
        'estimated_arrival_date' => now()->addDays(7)->toDateString(),
    ], $overrides);
}

it('creates a single-supplier purchase order storing supplier and arrival on the item', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [multiVendorItem($product->id, $supplier->id, [
            'estimated_arrival_date' => now()->addDays(3)->toDateString(),
        ])],
    ], $this->manager);

    $item = $purchaseOrder->items->first();

    expect($item->supplier_id)->toBe($supplier->id)
        ->and($item->estimated_arrival_date->toDateString())->toBe(now()->addDays(3)->toDateString())
        // Single vendor → header snapshot mirrors the sole supplier.
        ->and($purchaseOrder->supplier_id)->toBe($supplier->id);
});

it('creates a multi-vendor purchase order with items from different suppliers', function () {
    $supplierA = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor A']);
    $supplierB = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor B']);
    $productA = Product::factory()->create(['branch_id' => $this->branch->id]);
    $productB = Product::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [
            multiVendorItem($productA->id, $supplierA->id, ['quantity_ordered' => 2, 'unit_price' => 1000]),
            multiVendorItem($productB->id, $supplierB->id, ['quantity_ordered' => 3, 'unit_price' => 2000]),
        ],
    ], $this->manager);

    $bySupplier = $purchaseOrder->items->keyBy('supplier_id');

    expect($purchaseOrder->items)->toHaveCount(2)
        ->and($bySupplier[$supplierA->id]->product_id)->toBe($productA->id)
        ->and($bySupplier[$supplierB->id]->product_id)->toBe($productB->id)
        // Multi-vendor → deprecated header snapshot is null (no single supplier).
        ->and($purchaseOrder->supplier_id)->toBeNull()
        ->and($purchaseOrder->supplier_snapshot_name)->toBeNull();
});

it('computes server-side per-supplier subtotals and grand total', function () {
    $supplierA = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Alpha']);
    $supplierB = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Beta']);
    $productA = Product::factory()->create(['branch_id' => $this->branch->id]);
    $productB = Product::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [
            multiVendorItem($productA->id, $supplierA->id, ['quantity_ordered' => 2, 'unit_price' => 1500]),
            multiVendorItem($productB->id, $supplierB->id, ['quantity_ordered' => 4, 'unit_price' => 2500]),
        ],
    ], $this->manager);

    expect($purchaseOrder->subtotalForSupplier($supplierA->id))->toBe(3000.0)
        ->and($purchaseOrder->subtotalForSupplier($supplierB->id))->toBe(10000.0)
        ->and($purchaseOrder->total_amount)->toBe(13000.0)
        ->and($purchaseOrder->suppliersInvolved())->toHaveCount(2);
});

it('rejects an item without a supplier when no header default is provided', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [[
            'product_id' => $product->id,
            'quantity_ordered' => 5,
            'unit_price' => 1000,
            'estimated_arrival_date' => now()->toDateString(),
        ]],
    ], $this->manager);
})->throws(ValidationException::class, 'Supplier wajib dipilih untuk setiap item.');

it('rejects an item without an estimated arrival date at the HTTP form boundary', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), [
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
                'quantity_ordered' => 5,
                'unit_price' => 1000,
            ]],
        ])
        ->assertSessionHasErrors('items.0.estimated_arrival_date');
});

it('rejects an item without a supplier at the HTTP form boundary', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), [
            'order_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $product->id,
                'quantity_ordered' => 5,
                'unit_price' => 1000,
                'estimated_arrival_date' => now()->toDateString(),
            ]],
        ])
        ->assertSessionHasErrors('items.0.supplier_id');
});

it('rejects an estimated arrival date before the order date', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [multiVendorItem($product->id, $supplier->id, [
            'estimated_arrival_date' => now()->subDay()->toDateString(),
        ])],
    ], $this->manager);
})->throws(ValidationException::class);

it('rejects a supplier from another branch on a purchase order item', function () {
    $foreignSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [multiVendorItem($product->id, $foreignSupplier->id)],
    ], $this->manager);
})->throws(ValidationException::class);

it('rejects an inactive supplier on a purchase order item', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id, 'is_active' => false]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [multiVendorItem($product->id, $supplier->id)],
    ], $this->manager);
})->throws(ValidationException::class);

it('does not trust total_amount from the request', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'total_amount' => 999999999,
        'grand_total' => 999999999,
        'items' => [multiVendorItem($product->id, $supplier->id, ['quantity_ordered' => 2, 'unit_price' => 500])],
    ], $this->manager);

    expect($purchaseOrder->total_amount)->toBe(1000.0);
});

it('reads legacy purchase orders whose items were backfilled with the header supplier', function () {
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'supplier_snapshot_name' => $supplier->name,
    ]);
    $item = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
    ]);

    expect($item->fresh()->supplier_id)->toBe($supplier->id)
        ->and($purchaseOrder->fresh()->suppliersInvolved())->toHaveCount(1);
});

it('records the per-item supplier on the PURCHASE movement when goods are received', function () {
    $supplierA = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor A']);
    $supplierB = Supplier::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Vendor B']);
    $productA = Product::factory()->create(['branch_id' => $this->branch->id, 'requires_batch_tracking' => false]);
    $productB = Product::factory()->create(['branch_id' => $this->branch->id, 'requires_batch_tracking' => false]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseOrder = $this->service->createDraft([
        'order_date' => now()->toDateString(),
        'items' => [
            multiVendorItem($productA->id, $supplierA->id, ['inventory_location_id' => $location->id]),
            multiVendorItem($productB->id, $supplierB->id, ['inventory_location_id' => $location->id]),
        ],
    ], $this->manager);

    $sent = $this->service->markAsSent(
        $this->service->approve($this->service->submit($purchaseOrder, $this->manager), $this->manager),
        $this->manager,
    );

    $poItemA = $sent->items->firstWhere('supplier_id', $supplierA->id);
    $poItemB = $sent->items->firstWhere('supplier_id', $supplierB->id);

    $goodsReceipt = $this->goodsReceiptService->createFromPurchaseOrder([
        'purchase_order_id' => $sent->id,
        'receipt_date' => now()->toDateString(),
        'items' => [
            ['purchase_order_item_id' => $poItemA->id, 'product_id' => $productA->id, 'inventory_location_id' => $location->id, 'received_qty' => 5, 'accepted_qty' => 5, 'rejected_qty' => 0],
            ['purchase_order_item_id' => $poItemB->id, 'product_id' => $productB->id, 'inventory_location_id' => $location->id, 'received_qty' => 5, 'accepted_qty' => 5, 'rejected_qty' => 0],
        ],
    ], $this->manager);

    $this->goodsReceiptService->post($goodsReceipt, $this->manager);

    $movementA = InventoryMovement::query()->where('product_id', $productA->id)->where('movement_type', 'PURCHASE')->first();
    $movementB = InventoryMovement::query()->where('product_id', $productB->id)->where('movement_type', 'PURCHASE')->first();

    expect($movementA->supplier_id)->toBe($supplierA->id)
        ->and($movementB->supplier_id)->toBe($supplierB->id);
});
