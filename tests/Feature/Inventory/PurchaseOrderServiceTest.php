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
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\PurchaseRequestService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(PurchaseOrderService::class);
    $this->purchaseRequestService = app(PurchaseRequestService::class);
    $this->actingAs($this->manager);
});

function purchaseOrderPayload(
    int $supplierId,
    int $productId,
    ?int $locationId = null,
    float $quantity = 5,
    ?float $unitPrice = 10000,
    array $overrides = [],
): array {
    return array_merge([
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplierId,
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_ordered' => $quantity,
                'unit_price' => $unitPrice,
            ],
        ],
    ], $overrides);
}

function createBranchFixtures(object $test): array
{
    $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
    $product = Product::factory()->create(['branch_id' => $test->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $test->branch->id]);

    return compact('supplier', 'product', 'location');
}

function createApprovedPurchaseRequest(object $test, int $productId, ?int $locationId = null, float $quantity = 10): PurchaseRequest
{
    $purchaseRequest = $test->purchaseRequestService->createDraft([
        'request_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_requested' => $quantity,
                'estimated_unit_price' => 5000,
            ],
        ],
    ], $test->manager);

    $submitted = $test->purchaseRequestService->submit($purchaseRequest, $test->manager);

    return $test->purchaseRequestService->approve($submitted, $test->manager);
}

function advancePurchaseOrderToSent(object $test, PurchaseOrder $purchaseOrder): PurchaseOrder
{
    $submitted = $test->service->submit($purchaseOrder, $test->manager);
    $approved = $test->service->approve($submitted, $test->manager);

    return $test->service->markAsSent($approved, $test->manager);
}

it('creates manual draft purchase order with null purchase_request_id', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createBranchFixtures($this);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id, $location->id),
        $this->manager,
    );

    expect($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_DRAFT)
        ->and($purchaseOrder->branch_id)->toBe($this->branch->id)
        ->and($purchaseOrder->purchase_request_id)->toBeNull()
        ->and($purchaseOrder->purchase_order_number)->toStartWith('PO-')
        ->and($purchaseOrder->items)->toHaveCount(1);
});

it('creates purchase order draft from approved purchase request', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequest($this, $product->id, $location->id);

    $purchaseOrder = $this->service->createDraftFromPurchaseRequest(
        $purchaseRequest,
        ['supplier_id' => $supplier->id],
        $this->manager,
    );

    expect($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_DRAFT)
        ->and($purchaseOrder->purchase_request_id)->toBe($purchaseRequest->id)
        ->and($purchaseOrder->items)->toHaveCount(1)
        ->and($purchaseOrder->items->first()->purchase_request_item_id)->not->toBeNull();
});

it('blocks purchase order from non approved purchase request statuses', function (string $status) {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseRequest = PurchaseRequest::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => $status,
    ]);
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
        'quantity_requested' => 5,
    ]);

    $this->service->createDraftFromPurchaseRequest(
        $purchaseRequest,
        ['supplier_id' => $supplier->id],
        $this->manager,
    );
})->with([
    PurchaseRequest::STATUS_DRAFT,
    PurchaseRequest::STATUS_SUBMITTED,
    PurchaseRequest::STATUS_REJECTED,
    PurchaseRequest::STATUS_CANCELLED,
])->throws(ValidationException::class);

it('blocks duplicate active purchase order for same purchase request', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequest($this, $product->id);

    $this->service->createDraftFromPurchaseRequest(
        $purchaseRequest,
        ['supplier_id' => $supplier->id],
        $this->manager,
    );

    $this->service->createDraftFromPurchaseRequest(
        $purchaseRequest,
        ['supplier_id' => $supplier->id],
        $this->manager,
    );
})->throws(ValidationException::class);

it('allows new purchase order for same purchase request when previous is cancelled', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequest($this, $product->id);

    $first = $this->service->createDraftFromPurchaseRequest(
        $purchaseRequest,
        ['supplier_id' => $supplier->id],
        $this->manager,
    );
    $this->service->cancel($first, $this->manager);

    $second = $this->service->createDraftFromPurchaseRequest(
        $purchaseRequest,
        ['supplier_id' => $supplier->id],
        $this->manager,
    );

    expect($second->purchase_request_id)->toBe($purchaseRequest->id)
        ->and($second->status)->toBe(PurchaseOrder::STATUS_DRAFT);
});

it('captures supplier snapshot name at creation', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $supplier->update(['name' => 'PT Supplier Jaya']);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    expect($purchaseOrder->supplier_snapshot_name)->toBe('PT Supplier Jaya');
});

it('refreshes supplier snapshot name when supplier changes during draft update', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $newSupplier = Supplier::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Supplier Pengganti',
    ]);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    $updated = $this->service->updateDraft(
        $purchaseOrder,
        purchaseOrderPayload($newSupplier->id, $product->id, overrides: ['supplier_id' => $newSupplier->id]),
        $this->manager,
    );

    expect($updated->supplier_id)->toBe($newSupplier->id)
        ->and($updated->supplier_snapshot_name)->toBe('Supplier Pengganti');
});

it('keeps supplier snapshot name when supplier does not change during draft update', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $supplier->update(['name' => 'Nama Awal Supplier']);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    $supplier->update(['name' => 'Nama Supplier Berubah']);

    $updated = $this->service->updateDraft(
        $purchaseOrder,
        purchaseOrderPayload($supplier->id, $product->id, overrides: ['notes' => 'Catatan diperbarui']),
        $this->manager,
    );

    expect($updated->supplier_id)->toBe($supplier->id)
        ->and($updated->supplier_snapshot_name)->toBe('Nama Awal Supplier')
        ->and($updated->notes)->toBe('Catatan diperbarui');
});

it('defaults currency to IDR', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    expect($purchaseOrder->currency)->toBe('IDR');
});

it('accepts provided currency', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id, overrides: ['currency' => 'USD']),
        $this->manager,
    );

    expect($purchaseOrder->currency)->toBe('USD');
});

it('calculates total amount from model after service creates items', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id, null, 2, 1500),
        $this->manager,
    );

    expect($purchaseOrder->totalAmount())->toBe(3000.0);
});

it('blocks cross branch supplier on create', function () {
    ['product' => $product] = createBranchFixtures($this);
    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->service->createDraft(
        purchaseOrderPayload($otherSupplier->id, $product->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks cross branch product on create', function () {
    ['supplier' => $supplier] = createBranchFixtures($this);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $otherProduct->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks cross branch inventory location on create', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id, $otherLocation->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks inactive supplier on submit', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $supplier->update(['is_active' => false]);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    $this->service->submit($purchaseOrder, $this->manager);
})->throws(ValidationException::class);

it('blocks inactive product on submit', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $product->update(['is_active' => false]);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    $this->service->submit($purchaseOrder, $this->manager);
})->throws(ValidationException::class);

it('submits draft purchase order with submitted metadata', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    $submitted = $this->service->submit($purchaseOrder, $this->manager);

    expect($submitted->status)->toBe(PurchaseOrder::STATUS_SUBMITTED)
        ->and($submitted->submitted_by)->toBe($this->manager->id)
        ->and($submitted->submitted_at)->not->toBeNull();
});

it('approves submitted purchase order with approved metadata', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseOrder, $this->manager);

    $approved = $this->service->approve($submitted, $this->manager);

    expect($approved->status)->toBe(PurchaseOrder::STATUS_APPROVED)
        ->and($approved->approved_by)->toBe($this->manager->id)
        ->and($approved->approved_at)->not->toBeNull();
});

it('marks approved purchase order as sent with sent metadata', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseOrder, $this->manager);
    $approved = $this->service->approve($submitted, $this->manager);

    $sent = $this->service->markAsSent($approved, $this->manager);

    expect($sent->status)->toBe(PurchaseOrder::STATUS_SENT)
        ->and($sent->sent_by)->toBe($this->manager->id)
        ->and($sent->sent_at)->not->toBeNull();
});

it('cancels draft and submitted purchase orders', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);

    $draft = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
    expect($this->service->cancel($draft, $this->manager)->status)->toBe(PurchaseOrder::STATUS_CANCELLED);

    $submitted = $this->service->submit(
        $this->service->createDraft(purchaseOrderPayload($supplier->id, $product->id), $this->manager),
        $this->manager,
    );
    expect($this->service->cancel($submitted, $this->manager)->status)->toBe(PurchaseOrder::STATUS_CANCELLED);
});

it('denies update when purchase order is not draft', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseOrder, $this->manager);

    $this->service->updateDraft(
        $submitted,
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('denies submit when purchase order is not draft', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseOrder, $this->manager);

    $this->service->submit($submitted, $this->manager);
})->throws(ValidationException::class);

it('denies approve when purchase order is not submitted', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );

    $this->service->approve($purchaseOrder, $this->manager);
})->throws(ValidationException::class);

it('denies send when purchase order is not approved', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseOrder, $this->manager);

    $this->service->markAsSent($submitted, $this->manager);
})->throws(ValidationException::class);

it('denies cancel when purchase order is approved sent or cancelled', function (string $status) {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'status' => $status,
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
    ]);

    $this->service->cancel($purchaseOrder, $this->manager);
})->with([
    PurchaseOrder::STATUS_APPROVED,
    PurchaseOrder::STATUS_SENT,
    PurchaseOrder::STATUS_CANCELLED,
])->throws(ValidationException::class);

it('blocks purchase order item quantity above purchase request requested quantity', function () {
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequest($this, $product->id, null, 5);
    $purchaseRequestItem = $purchaseRequest->items->first();

    $this->service->createDraftFromPurchaseRequest(
        $purchaseRequest,
        [
            'supplier_id' => $supplier->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'purchase_request_item_id' => $purchaseRequestItem->id,
                    'quantity_ordered' => 6,
                    'unit_price' => 1000,
                ],
            ],
        ],
        $this->manager,
    );
})->throws(ValidationException::class);

it('does not create inventory movements across purchase order lifecycle', function () {
    $before = InventoryMovement::count();
    ['supplier' => $supplier, 'product' => $product] = createBranchFixtures($this);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseOrder, $this->manager);
    $approved = $this->service->approve($submitted, $this->manager);
    $sent = $this->service->markAsSent($approved, $this->manager);
    $this->service->cancel(
        $this->service->createDraft(purchaseOrderPayload($supplier->id, $product->id), $this->manager),
        $this->manager,
    );

    expect(InventoryMovement::count())->toBe($before)
        ->and($sent->status)->toBe(PurchaseOrder::STATUS_SENT);
});

it('does not mutate stock directly during purchase order lifecycle', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createBranchFixtures($this);

    InventoryMovement::factory()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'quantity_in' => 20,
        'quantity_out' => 0,
    ]);

    $ledgerStockBefore = (float) InventoryMovement::query()
        ->where('branch_id', $this->branch->id)
        ->where('product_id', $product->id)
        ->where('inventory_location_id', $location->id)
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');

    $productSnapshot = $product->fresh()->only(['minimum_stock', 'average_cost', 'is_active']);

    $purchaseOrder = $this->service->createDraft(
        purchaseOrderPayload($supplier->id, $product->id, $location->id),
        $this->manager,
    );
    advancePurchaseOrderToSent($this, $purchaseOrder);

    $ledgerStockAfter = (float) InventoryMovement::query()
        ->where('branch_id', $this->branch->id)
        ->where('product_id', $product->id)
        ->where('inventory_location_id', $location->id)
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');

    expect($ledgerStockAfter)->toBe($ledgerStockBefore)
        ->and($product->fresh()->only(['minimum_stock', 'average_cost', 'is_active']))->toBe($productSnapshot);
});
