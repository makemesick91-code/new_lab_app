<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Services\PurchaseRequestService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(PurchaseRequestService::class);
    $this->actingAs($this->manager);
});

function purchaseRequestPayload(int $productId, ?int $locationId = null, float $quantity = 5): array
{
    return [
        'request_date' => now()->toDateString(),
        'notes' => 'Service test note',
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_requested' => $quantity,
                'estimated_unit_price' => 10000,
                'notes' => 'Line note',
            ],
        ],
    ];
}

it('creates draft purchase request with generated number', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseRequest = $this->service->createDraft(
        purchaseRequestPayload($product->id, $location->id),
        $this->manager,
    );

    expect($purchaseRequest->status)->toBe(PurchaseRequest::STATUS_DRAFT)
        ->and($purchaseRequest->branch_id)->toBe($this->branch->id)
        ->and($purchaseRequest->purchase_request_number)->toStartWith('PR-')
        ->and($purchaseRequest->items)->toHaveCount(1);
});

it('updates draft purchase request', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseRequest = $this->service->createDraft(
        purchaseRequestPayload($product->id),
        $this->manager,
    );

    $updated = $this->service->updateDraft(
        $purchaseRequest,
        array_merge(purchaseRequestPayload($product->id), ['notes' => 'Updated note']),
        $this->manager,
    );

    expect($updated->notes)->toBe('Updated note');
});

it('submits draft purchase request with items', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseRequest = $this->service->createDraft(
        purchaseRequestPayload($product->id),
        $this->manager,
    );

    $submitted = $this->service->submit($purchaseRequest, $this->manager);

    expect($submitted->status)->toBe(PurchaseRequest::STATUS_SUBMITTED);
});

it('cannot submit purchase request without items', function () {
    $purchaseRequest = PurchaseRequest::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => PurchaseRequest::STATUS_DRAFT,
    ]);

    $this->service->submit($purchaseRequest, $this->manager);
})->throws(ValidationException::class);

it('approves submitted purchase request', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseRequest = $this->service->createDraft(
        purchaseRequestPayload($product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseRequest, $this->manager);

    $approved = $this->service->approve($submitted, $this->manager);

    expect($approved->status)->toBe(PurchaseRequest::STATUS_APPROVED)
        ->and($approved->approved_by)->toBe($this->manager->id);
});

it('rejects submitted purchase request with reason', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $purchaseRequest = $this->service->createDraft(
        purchaseRequestPayload($product->id),
        $this->manager,
    );
    $submitted = $this->service->submit($purchaseRequest, $this->manager);

    $rejected = $this->service->reject($submitted, $this->manager, 'Stok masih cukup');

    expect($rejected->status)->toBe(PurchaseRequest::STATUS_REJECTED)
        ->and($rejected->rejection_reason)->toBe('Stok masih cukup');
});

it('cancels draft and submitted purchase requests', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $draft = $this->service->createDraft(purchaseRequestPayload($product->id), $this->manager);
    $cancelledDraft = $this->service->cancel($draft, $this->manager);
    expect($cancelledDraft->status)->toBe(PurchaseRequest::STATUS_CANCELLED);

    $submitted = $this->service->submit(
        $this->service->createDraft(purchaseRequestPayload($product->id), $this->manager),
        $this->manager,
    );
    $cancelledSubmitted = $this->service->cancel($submitted, $this->manager);
    expect($cancelledSubmitted->status)->toBe(PurchaseRequest::STATUS_CANCELLED);
});

it('blocks invalid status transitions', function () {
    $approved = PurchaseRequest::factory()->approved()->create(['branch_id' => $this->branch->id]);

    $this->service->cancel($approved, $this->manager);
})->throws(ValidationException::class);

it('blocks cross branch product on create', function () {
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->service->createDraft(
        purchaseRequestPayload($otherProduct->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks cross branch location on create', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->service->createDraft(
        purchaseRequestPayload($product->id, $otherLocation->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('does not create inventory movements during purchase request workflow', function () {
    $before = InventoryMovement::count();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $purchaseRequest = $this->service->createDraft(purchaseRequestPayload($product->id), $this->manager);
    $submitted = $this->service->submit($purchaseRequest, $this->manager);
    $this->service->approve($submitted, $this->manager);

    expect(InventoryMovement::count())->toBe($before);
});
