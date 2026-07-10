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
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->approver = userWith(['approve_inventory_purchase_order', 'view_inventory']);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->purchaseRequestService = app(PurchaseRequestService::class);
});

function poControllerPayload(
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

function createPoBranchFixtures(object $test): array
{
    $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);
    $product = Product::factory()->create(['branch_id' => $test->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $test->branch->id]);

    return compact('supplier', 'product', 'location');
}

function createDraftPurchaseOrder(object $test, ?Supplier $supplier = null, ?Product $product = null): PurchaseOrder
{
    $supplier ??= Supplier::factory()->create(['branch_id' => $test->branch->id]);
    $product ??= Product::factory()->create(['branch_id' => $test->branch->id]);

    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $test->branch->id,
        'status' => PurchaseOrder::STATUS_DRAFT,
        'supplier_id' => $supplier->id,
        'supplier_snapshot_name' => $supplier->name,
        'created_by' => $test->manager->id,
    ]);

    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $purchaseOrder->id,
        'product_id' => $product->id,
        'supplier_id' => $supplier->id,
    ]);

    return $purchaseOrder->refresh();
}

function createApprovedPurchaseRequestForPo(object $test, int $productId, ?int $locationId = null): PurchaseRequest
{
    $purchaseRequest = $test->purchaseRequestService->createDraft([
        'request_date' => now()->toDateString(),
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_requested' => 10,
                'estimated_unit_price' => 5000,
            ],
        ],
    ], $test->manager);

    $submitted = $test->purchaseRequestService->submit($purchaseRequest, $test->manager);

    return $test->purchaseRequestService->approve($submitted, $test->manager);
}

it('redirects guest from purchase order index', function () {
    $this->get(route('inventory.purchase-orders.index'))
        ->assertRedirect(route('login'));
});

it('registers purchase order route names', function () {
    $routes = [
        'inventory.purchase-orders.index',
        'inventory.purchase-orders.create',
        'inventory.purchase-orders.store',
        'inventory.purchase-orders.show',
        'inventory.purchase-orders.edit',
        'inventory.purchase-orders.update',
        'inventory.purchase-orders.submit',
        'inventory.purchase-orders.approve',
        'inventory.purchase-orders.send',
        'inventory.purchase-orders.cancel',
    ];

    foreach ($routes as $routeName) {
        expect(Route::has($routeName))->toBeTrue();
    }
});

it('allows view_inventory to access index and show for same branch', function () {
    $purchaseOrder = createDraftPurchaseOrder($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.index'))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk();
});

it('denies view_inventory from mutation routes', function () {
    ['supplier' => $supplier, 'product' => $product] = createPoBranchFixtures($this);
    $purchaseOrder = createDraftPurchaseOrder($this, $supplier, $product);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.purchase-orders.store'), poControllerPayload($supplier->id, $product->id))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.edit', $purchaseOrder))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->put(route('inventory.purchase-orders.update', $purchaseOrder), poControllerPayload($supplier->id, $product->id))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.purchase-orders.submit', $purchaseOrder))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.purchase-orders.approve', $purchaseOrder))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.purchase-orders.send', $purchaseOrder))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.purchase-orders.cancel', $purchaseOrder))
        ->assertForbidden();
});

it('allows manage_inventory to access create and store manual draft purchase order', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createPoBranchFixtures($this);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create'))
        ->assertOk();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), poControllerPayload($supplier->id, $product->id, $location->id))
        ->assertRedirect();

    $purchaseOrder = PurchaseOrder::query()->where('branch_id', $this->branch->id)->first();

    expect($purchaseOrder)->not->toBeNull()
        ->and($purchaseOrder->purchase_request_id)->toBeNull()
        ->and($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_DRAFT);
});

it('allows manage_inventory to create purchase order from approved purchase request', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createPoBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequestForPo($this, $product->id, $location->id);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), poControllerPayload(
            $supplier->id,
            $product->id,
            $location->id,
            overrides: [
                'purchase_request_id' => $purchaseRequest->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'supplier_id' => $supplier->id,
                        'inventory_location_id' => $location->id,
                        'purchase_request_item_id' => $purchaseRequest->items->first()->id,
                        'quantity_ordered' => 10,
                        'unit_price' => 5000,
                        'estimated_arrival_date' => now()->toDateString(),
                    ],
                ],
            ],
        ))
        ->assertRedirect();

    $purchaseOrder = PurchaseOrder::query()->where('purchase_request_id', $purchaseRequest->id)->first();

    expect($purchaseOrder)->not->toBeNull()
        ->and($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_DRAFT);
});

it('blocks create and store from non approved purchase request statuses', function (string $status) {
    ['supplier' => $supplier, 'product' => $product] = createPoBranchFixtures($this);
    $purchaseRequest = PurchaseRequest::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => $status,
    ]);
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
        'quantity_requested' => 5,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create', ['purchase_request_id' => $purchaseRequest->id]))
        ->assertRedirect(route('inventory.purchase-orders.create'))
        ->assertSessionHasErrors('purchase_request');

    expect(PurchaseOrder::count())->toBe(0);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), poControllerPayload(
            $supplier->id,
            $product->id,
            overrides: ['purchase_request_id' => $purchaseRequest->id],
        ))
        ->assertSessionHasErrors('purchase_request');

    expect(PurchaseOrder::count())->toBe(0);
})->with([
    PurchaseRequest::STATUS_DRAFT,
    PurchaseRequest::STATUS_SUBMITTED,
    PurchaseRequest::STATUS_REJECTED,
    PurchaseRequest::STATUS_CANCELLED,
]);

it('prefills create page from approved purchase request without creating purchase order', function () {
    ['product' => $product, 'location' => $location] = createPoBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequestForPo($this, $product->id, $location->id);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.create', ['purchase_request_id' => $purchaseRequest->id]))
        ->assertOk()
        ->assertSessionHasNoErrors();

    expect(PurchaseOrder::count())->toBe(0);
});

it('denies cross branch purchase order access', function () {
    $otherPurchaseOrder = createDraftPurchaseOrder((object) ['branch' => $this->otherBranch, 'manager' => $this->manager]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $otherPurchaseOrder))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.show', $otherPurchaseOrder))
        ->assertForbidden();
});

it('allows manage_inventory to edit and update draft only', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createPoBranchFixtures($this);
    $purchaseOrder = createDraftPurchaseOrder($this, $supplier, $product);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.edit', $purchaseOrder))
        ->assertOk();

    $this->actingAs($this->manager)
        ->put(route('inventory.purchase-orders.update', $purchaseOrder), poControllerPayload($supplier->id, $product->id, $location->id, 7))
        ->assertRedirect(route('inventory.purchase-orders.show', $purchaseOrder));

    $purchaseOrder->refresh();
    expect((float) $purchaseOrder->items->first()->quantity_ordered)->toBe(7.0);

    $submitted = PurchaseOrder::factory()->submitted()->create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.edit', $submitted))
        ->assertForbidden();
});

it('submits approves sends and cancels purchase orders through workflow', function () {
    ['supplier' => $supplier, 'product' => $product] = createPoBranchFixtures($this);
    $purchaseOrder = createDraftPurchaseOrder($this, $supplier, $product);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.submit', $purchaseOrder))
        ->assertRedirect(route('inventory.purchase-orders.show', $purchaseOrder));

    $purchaseOrder->refresh();
    expect($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_SUBMITTED);

    $this->actingAs($this->approver)
        ->post(route('inventory.purchase-orders.approve', $purchaseOrder))
        ->assertRedirect(route('inventory.purchase-orders.show', $purchaseOrder));

    $purchaseOrder->refresh();
    expect($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_APPROVED);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.send', $purchaseOrder))
        ->assertRedirect(route('inventory.purchase-orders.show', $purchaseOrder));

    $purchaseOrder->refresh();
    expect($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_SENT);
});

it('allows cancel for draft and submitted purchase orders only', function () {
    ['supplier' => $supplier, 'product' => $product] = createPoBranchFixtures($this);

    $draft = createDraftPurchaseOrder($this, $supplier, $product);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.cancel', $draft))
        ->assertRedirect(route('inventory.purchase-orders.show', $draft));

    $draft->refresh();
    expect($draft->status)->toBe(PurchaseOrder::STATUS_CANCELLED);

    $submitted = createDraftPurchaseOrder($this, $supplier, $product);
    $this->actingAs($this->manager)->post(route('inventory.purchase-orders.submit', $submitted));

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.cancel', $submitted))
        ->assertRedirect(route('inventory.purchase-orders.show', $submitted));

    $submitted->refresh();
    expect($submitted->status)->toBe(PurchaseOrder::STATUS_CANCELLED);
});

it('denies cancel for approved and sent purchase orders', function () {
    ['supplier' => $supplier, 'product' => $product] = createPoBranchFixtures($this);
    $purchaseOrder = createDraftPurchaseOrder($this, $supplier, $product);

    $this->actingAs($this->manager)->post(route('inventory.purchase-orders.submit', $purchaseOrder));
    $this->actingAs($this->approver)->post(route('inventory.purchase-orders.approve', $purchaseOrder));

    $purchaseOrder->refresh();
    expect($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_APPROVED);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.cancel', $purchaseOrder))
        ->assertForbidden();

    $this->actingAs($this->manager)->post(route('inventory.purchase-orders.send', $purchaseOrder));

    $purchaseOrder->refresh();
    expect($purchaseOrder->status)->toBe(PurchaseOrder::STATUS_SENT);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.cancel', $purchaseOrder))
        ->assertForbidden();
});

it('blocks duplicate store from approved purchase request with active purchase order', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createPoBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequestForPo($this, $product->id, $location->id);
    $prItem = $purchaseRequest->items->first();

    $payload = poControllerPayload(
        $supplier->id,
        $product->id,
        $location->id,
        overrides: [
            'purchase_request_id' => $purchaseRequest->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'supplier_id' => $supplier->id,
                    'inventory_location_id' => $location->id,
                    'purchase_request_item_id' => $prItem->id,
                    'quantity_ordered' => 10,
                    'unit_price' => 5000,
                    'estimated_arrival_date' => now()->toDateString(),
                ],
            ],
        ],
    );

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), $payload)
        ->assertRedirect();

    expect(PurchaseOrder::query()->where('purchase_request_id', $purchaseRequest->id)->count())->toBe(1);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), $payload)
        ->assertSessionHasErrors('purchase_request');

    expect(PurchaseOrder::query()->where('purchase_request_id', $purchaseRequest->id)->count())->toBe(1);
});

it('does not create inventory movements from controller actions', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = createPoBranchFixtures($this);
    $purchaseRequest = createApprovedPurchaseRequestForPo($this, $product->id, $location->id);

    expect(InventoryMovement::count())->toBe(0);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), poControllerPayload($supplier->id, $product->id, $location->id))
        ->assertRedirect();

    $purchaseOrder = PurchaseOrder::query()->where('branch_id', $this->branch->id)->latest('id')->first();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-orders.store'), poControllerPayload(
            $supplier->id,
            $product->id,
            $location->id,
            overrides: [
                'purchase_request_id' => $purchaseRequest->id,
                'items' => [
                    [
                        'product_id' => $product->id,
                        'supplier_id' => $supplier->id,
                        'inventory_location_id' => $location->id,
                        'purchase_request_item_id' => $purchaseRequest->items->first()->id,
                        'quantity_ordered' => 10,
                        'unit_price' => 5000,
                        'estimated_arrival_date' => now()->toDateString(),
                    ],
                ],
            ],
        ))
        ->assertRedirect();

    $this->actingAs($this->manager)->post(route('inventory.purchase-orders.submit', $purchaseOrder));
    $this->actingAs($this->approver)->post(route('inventory.purchase-orders.approve', $purchaseOrder));
    $this->actingAs($this->manager)->post(route('inventory.purchase-orders.send', $purchaseOrder));

    $draftToCancel = createDraftPurchaseOrder($this, $supplier, $product);
    $this->actingAs($this->manager)->post(route('inventory.purchase-orders.cancel', $draftToCancel));

    expect(InventoryMovement::count())->toBe(0);
});
