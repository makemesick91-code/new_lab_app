<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
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
    $this->approver = userWith(['approve_inventory_purchase_request', 'view_inventory']);
});

function prControllerPayload(int $productId, ?int $locationId = null, float $quantity = 3): array
{
    return [
        'request_date' => now()->toDateString(),
        'notes' => 'Controller PR note',
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_requested' => $quantity,
                'estimated_unit_price' => 5000,
            ],
        ],
    ];
}

function createDraftPurchaseRequest(Branch $branch): PurchaseRequest
{
    $product = Product::factory()->create(['branch_id' => $branch->id]);
    $purchaseRequest = PurchaseRequest::factory()->create([
        'branch_id' => $branch->id,
        'status' => PurchaseRequest::STATUS_DRAFT,
    ]);

    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
    ]);

    return $purchaseRequest->refresh();
}

it('registers purchase request route names', function () {
    $routes = [
        'inventory.purchase-requests.index',
        'inventory.purchase-requests.create',
        'inventory.purchase-requests.store',
        'inventory.purchase-requests.show',
        'inventory.purchase-requests.edit',
        'inventory.purchase-requests.update',
        'inventory.purchase-requests.submit',
        'inventory.purchase-requests.approve',
        'inventory.purchase-requests.reject',
        'inventory.purchase-requests.cancel',
    ];

    foreach ($routes as $routeName) {
        expect(Route::has($routeName))->toBeTrue();
    }
});

it('allows view_inventory to access index and show for same branch', function () {
    $purchaseRequest = createDraftPurchaseRequest($this->branch);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.index'))
        ->assertOk();

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.show', $purchaseRequest))
        ->assertOk();
});

it('denies view_inventory from mutation routes', function () {
    $purchaseRequest = createDraftPurchaseRequest($this->branch);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.purchase-requests.store'), prControllerPayload($product->id))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.edit', $purchaseRequest))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->put(route('inventory.purchase-requests.update', $purchaseRequest), prControllerPayload($product->id))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.purchase-requests.submit', $purchaseRequest))
        ->assertForbidden();
});

it('allows manage_inventory to create and store purchase requests', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.create'))
        ->assertOk();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-requests.store'), prControllerPayload($product->id))
        ->assertRedirect();

    expect(PurchaseRequest::query()->where('branch_id', $this->branch->id)->count())->toBe(1);
});

it('allows manage_inventory to edit and update draft only', function () {
    $purchaseRequest = createDraftPurchaseRequest($this->branch);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.edit', $purchaseRequest))
        ->assertOk();

    $this->actingAs($this->manager)
        ->put(route('inventory.purchase-requests.update', $purchaseRequest), prControllerPayload($product->id))
        ->assertRedirect(route('inventory.purchase-requests.show', $purchaseRequest));

    $submitted = PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.edit', $submitted))
        ->assertForbidden();
});

it('submits approves rejects and cancels purchase requests', function () {
    $purchaseRequest = createDraftPurchaseRequest($this->branch);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-requests.submit', $purchaseRequest))
        ->assertRedirect();

    $purchaseRequest->refresh();
    expect($purchaseRequest->status)->toBe(PurchaseRequest::STATUS_SUBMITTED);

    $this->actingAs($this->approver)
        ->post(route('inventory.purchase-requests.approve', $purchaseRequest))
        ->assertRedirect();

    $purchaseRequest->refresh();
    expect($purchaseRequest->status)->toBe(PurchaseRequest::STATUS_APPROVED);

    $submitted = PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id]);
    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $submitted->id,
        'product_id' => Product::factory()->create(['branch_id' => $this->branch->id])->id,
    ]);

    $this->actingAs($this->approver)
        ->post(route('inventory.purchase-requests.reject', $submitted), ['rejection_reason' => 'Tidak diperlukan'])
        ->assertRedirect();

    $submitted->refresh();
    expect($submitted->status)->toBe(PurchaseRequest::STATUS_REJECTED);

    $draft = createDraftPurchaseRequest($this->branch);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-requests.cancel', $draft))
        ->assertRedirect();

    $draft->refresh();
    expect($draft->status)->toBe(PurchaseRequest::STATUS_CANCELLED);
});

it('denies cross branch purchase request access', function () {
    $otherPurchaseRequest = createDraftPurchaseRequest($this->otherBranch);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.show', $otherPurchaseRequest))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $otherPurchaseRequest))
        ->assertForbidden();
});

it('prefills create page from query params without creating purchase request', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.create', [
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'suggested_quantity' => 12,
        ]))
        ->assertOk()
        ->assertSee((string) $product->id, false);

    expect(PurchaseRequest::count())->toBe(0);
});
