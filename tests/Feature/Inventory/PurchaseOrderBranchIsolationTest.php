<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->approver = userWith(['approve_inventory_purchase_order', 'view_inventory']);
    $this->service = app(PurchaseOrderService::class);
    $this->purchaseOrderService = $this->service;
    $this->goodsReceiptService = app(GoodsReceiptService::class);
    $this->actingAs($this->manager);
});

function poBranchIsolationPayload(
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
                'supplier_id' => $supplierId,
                'inventory_location_id' => $locationId,
                'quantity_ordered' => $quantity,
                'unit_price' => $unitPrice,
                'estimated_arrival_date' => now()->toDateString(),
            ],
        ],
    ], $overrides);
}

function createOtherBranchPurchaseOrder(
    Branch $branch,
    string $status = PurchaseOrder::STATUS_DRAFT,
    ?string $number = null,
): PurchaseOrder {
    $supplier = Supplier::factory()->create(['branch_id' => $branch->id]);
    $product = Product::factory()->create(['branch_id' => $branch->id]);

    $factory = PurchaseOrder::factory();

    $stateFactory = match ($status) {
        PurchaseOrder::STATUS_SUBMITTED => $factory->submitted(),
        PurchaseOrder::STATUS_APPROVED => $factory->approved(),
        PurchaseOrder::STATUS_SENT => $factory->sent(),
        PurchaseOrder::STATUS_CANCELLED => $factory->cancelled(),
        default => $factory,
    };

    $purchaseOrder = $stateFactory->create([
        'branch_id' => $branch->id,
        'status' => $status,
        'supplier_id' => $supplier->id,
        'supplier_snapshot_name' => $supplier->name,
        'purchase_order_number' => $number ?? 'PO-OTHER-BRANCH-'.fake()->unique()->numerify('######'),
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
    ]);

    return $purchaseOrder->refresh();
}

function createOtherBranchApprovedPurchaseRequest(Branch $branch): PurchaseRequest
{
    $product = Product::factory()->create(['branch_id' => $branch->id]);
    $purchaseRequest = PurchaseRequest::factory()->approved()->create([
        'branch_id' => $branch->id,
        'purchase_request_number' => 'PR-OTHER-BRANCH-'.fake()->unique()->numerify('######'),
    ]);

    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
        'quantity_requested' => 10,
    ]);

    return $purchaseRequest->refresh();
}

it('denies branch A user from viewing purchase order detail in branch B', function () {
    $otherPurchaseOrder = createOtherBranchPurchaseOrder($this->otherBranch);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $otherPurchaseOrder))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.show', $otherPurchaseOrder))
        ->assertForbidden();
});

it('denies branch A user from editing purchase order in branch B', function () {
    $otherPurchaseOrder = createOtherBranchPurchaseOrder($this->otherBranch);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.edit', $otherPurchaseOrder))
        ->assertForbidden();
});

it('denies branch A user from updating purchase order in branch B', function () {
    $otherPurchaseOrder = createOtherBranchPurchaseOrder($this->otherBranch);
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->put(
            route('inventory.purchase-orders.update', $otherPurchaseOrder),
            poBranchIsolationPayload($supplier->id, $product->id),
        )
        ->assertForbidden();

    $otherPurchaseOrder->refresh();
    expect($otherPurchaseOrder->status)->toBe(PurchaseOrder::STATUS_DRAFT);
});

it('denies branch A user from submitting purchase order in branch B', function () {
    $otherPurchaseOrder = createOtherBranchPurchaseOrder($this->otherBranch);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.submit', $otherPurchaseOrder))
        ->assertForbidden();

    $otherPurchaseOrder->refresh();
    expect($otherPurchaseOrder->status)->toBe(PurchaseOrder::STATUS_DRAFT);
});

it('denies branch A user from approving purchase order in branch B', function () {
    $otherPurchaseOrder = createOtherBranchPurchaseOrder($this->otherBranch, PurchaseOrder::STATUS_SUBMITTED);

    $this->actingAs($this->approver)
        ->post(route('inventory.purchase-orders.approve', $otherPurchaseOrder))
        ->assertForbidden();

    $otherPurchaseOrder->refresh();
    expect($otherPurchaseOrder->status)->toBe(PurchaseOrder::STATUS_SUBMITTED);
});

it('denies branch A user from sending purchase order in branch B', function () {
    $otherPurchaseOrder = createOtherBranchPurchaseOrder($this->otherBranch, PurchaseOrder::STATUS_APPROVED);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.send', $otherPurchaseOrder))
        ->assertForbidden();

    $otherPurchaseOrder->refresh();
    expect($otherPurchaseOrder->status)->toBe(PurchaseOrder::STATUS_APPROVED);
});

it('denies branch A user from cancelling purchase order in branch B', function () {
    $otherDraft = createOtherBranchPurchaseOrder($this->otherBranch);
    $otherSubmitted = createOtherBranchPurchaseOrder($this->otherBranch, PurchaseOrder::STATUS_SUBMITTED);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.cancel', $otherDraft))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.cancel', $otherSubmitted))
        ->assertForbidden();

    expect($otherDraft->refresh()->status)->toBe(PurchaseOrder::STATUS_DRAFT)
        ->and($otherSubmitted->refresh()->status)->toBe(PurchaseOrder::STATUS_SUBMITTED);
});

it('denies branch A user from creating purchase order from purchase request in branch B', function () {
    $otherPurchaseRequest = createOtherBranchApprovedPurchaseRequest($this->otherBranch);
    $otherPrItem = $otherPurchaseRequest->items->first();
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create', ['purchase_request_id' => $otherPurchaseRequest->id]))
        ->assertRedirect(route('inventory.purchase-orders.create'))
        ->assertSessionHasErrors('purchase_request_id');

    expect(PurchaseOrder::count())->toBe(0);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), poBranchIsolationPayload(
            $supplier->id,
            $product->id,
            overrides: [
                'purchase_request_id' => $otherPurchaseRequest->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'supplier_id' => $supplier->id,
                        'purchase_request_item_id' => $otherPrItem->id,
                        'quantity_ordered' => 10,
                        'unit_price' => 5000,
                        'estimated_arrival_date' => now()->toDateString(),
                    ],
                ],
            ],
        ))
        ->assertSessionHasErrors('purchase_request_id');

    expect(PurchaseOrder::count())->toBe(0);
});

it('lists only purchase orders from the active branch on index', function () {
    $branchPurchaseOrder = createOtherBranchPurchaseOrder(
        $this->branch,
        PurchaseOrder::STATUS_DRAFT,
        'PO-ACTIVE-BRANCH-ONLY',
    );
    $otherBranchPurchaseOrder = createOtherBranchPurchaseOrder(
        $this->otherBranch,
        PurchaseOrder::STATUS_DRAFT,
        'PO-CROSS-BRANCH-ISOLATION-LEAK',
    );

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.index'))
        ->assertOk()
        ->assertSee($branchPurchaseOrder->purchase_order_number)
        ->assertDontSee($otherBranchPurchaseOrder->purchase_order_number);
});

it('does not expose goods receipts from other branches on purchase order show', function () {
    ['sent' => $purchaseOrder, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $sameBranchReceipt = $this->goodsReceiptService->createFromPurchaseOrder(
        goodsReceiptPayload($purchaseOrder->id, $poItem->id, $product->id, $location->id, 2),
        $this->manager,
    );

    $crossBranchReceipt = GoodsReceipt::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'purchase_order_id' => $purchaseOrder->id,
        'receipt_number' => 'GR-CROSS-BRANCH-PO-ISOLATION',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee($sameBranchReceipt->receipt_number)
        ->assertDontSee($crossBranchReceipt->receipt_number)
        ->assertDontSee('GR-CROSS-BRANCH-PO-ISOLATION');
});

it('rejects cross branch purchase order operations through service branch guard', function () {
    $otherDraft = createOtherBranchPurchaseOrder($this->otherBranch);
    $otherSubmitted = createOtherBranchPurchaseOrder($this->otherBranch, PurchaseOrder::STATUS_SUBMITTED);
    $otherApproved = createOtherBranchPurchaseOrder($this->otherBranch, PurchaseOrder::STATUS_APPROVED);
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->updateDraft(
        $otherDraft,
        poBranchIsolationPayload($supplier->id, $product->id),
        $this->manager,
    ))->toThrow(ValidationException::class);

    expect(fn () => $this->service->submit($otherDraft, $this->manager))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->approve($otherSubmitted, $this->approver))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->markAsSent($otherApproved, $this->manager))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->cancel($otherDraft, $this->manager))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->cancel($otherSubmitted, $this->manager))
        ->toThrow(ValidationException::class);
});

it('rejects cross branch supplier product and location through service create guard', function () {
    $branchSupplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $branchProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $branchLocation = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    expect(fn () => $this->service->createDraft(
        poBranchIsolationPayload($otherSupplier->id, $branchProduct->id),
        $this->manager,
    ))->toThrow(ValidationException::class);

    expect(fn () => $this->service->createDraft(
        poBranchIsolationPayload($branchSupplier->id, $otherProduct->id),
        $this->manager,
    ))->toThrow(ValidationException::class);

    expect(fn () => $this->service->createDraft(
        poBranchIsolationPayload($branchSupplier->id, $branchProduct->id, $otherLocation->id),
        $this->manager,
    ))->toThrow(ValidationException::class);
});

it('scopes listForBranch service results to the requested branch only', function () {
    createOtherBranchPurchaseOrder(
        $this->branch,
        PurchaseOrder::STATUS_DRAFT,
        'PO-SERVICE-LIST-ACTIVE',
    );
    createOtherBranchPurchaseOrder(
        $this->otherBranch,
        PurchaseOrder::STATUS_DRAFT,
        'PO-SERVICE-LIST-OTHER',
    );

    $results = $this->service->listForBranch($this->branch->id);

    expect($results->total())->toBe(1)
        ->and($results->first()->purchase_order_number)->toBe('PO-SERVICE-LIST-ACTIVE');
});

it('does not create inventory movements from cross branch purchase order attempts', function () {
    $before = InventoryMovement::count();

    $otherDraft = createOtherBranchPurchaseOrder($this->otherBranch);
    $otherSubmitted = createOtherBranchPurchaseOrder($this->otherBranch, PurchaseOrder::STATUS_SUBMITTED);
    $otherApproved = createOtherBranchPurchaseOrder($this->otherBranch, PurchaseOrder::STATUS_APPROVED);
    $otherPurchaseRequest = createOtherBranchApprovedPurchaseRequest($this->otherBranch);

    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.show', $otherDraft))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->put(route('inventory.purchase-orders.update', $otherDraft), poBranchIsolationPayload($supplier->id, $product->id))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.submit', $otherDraft))
        ->assertForbidden();

    $this->actingAs($this->approver)
        ->post(route('inventory.purchase-orders.approve', $otherSubmitted))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.send', $otherApproved))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.cancel', $otherDraft))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), poBranchIsolationPayload(
            $supplier->id,
            $product->id,
            overrides: ['purchase_request_id' => $otherPurchaseRequest->id],
        ))
        ->assertSessionHasErrors('purchase_request_id');

    try {
        $this->service->createDraft(
            poBranchIsolationPayload($otherSupplier->id, $product->id),
            $this->manager,
        );
    } catch (ValidationException) {
        // expected cross-branch rejection
    }

    expect(InventoryMovement::count())->toBe($before);
});
