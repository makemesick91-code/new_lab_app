<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use App\Modules\Inventory\Services\PurchaseRequestService;
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
    $this->approver = userWith(['approve_inventory_purchase_request', 'view_inventory']);
    $this->service = app(PurchaseRequestService::class);
    $this->actingAs($this->manager);
});

function prBranchIsolationPayload(int $productId, ?int $locationId = null, float $quantity = 3): array
{
    return [
        'request_date' => now()->toDateString(),
        'notes' => 'Branch isolation PR note',
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

function createOtherBranchPurchaseRequest(
    Branch $branch,
    string $status = PurchaseRequest::STATUS_DRAFT,
    ?string $number = null,
): PurchaseRequest {
    $product = Product::factory()->create(['branch_id' => $branch->id]);
    $purchaseRequest = PurchaseRequest::factory()->create([
        'branch_id' => $branch->id,
        'status' => $status,
        'purchase_request_number' => $number ?? 'PR-OTHER-BRANCH-'.fake()->unique()->numerify('######'),
    ]);

    PurchaseRequestItem::factory()->create([
        'purchase_request_id' => $purchaseRequest->id,
        'product_id' => $product->id,
    ]);

    return $purchaseRequest->refresh();
}

it('denies branch A user from viewing purchase request detail in branch B', function () {
    $otherPurchaseRequest = createOtherBranchPurchaseRequest($this->otherBranch);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.show', $otherPurchaseRequest))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.show', $otherPurchaseRequest))
        ->assertForbidden();
});

it('denies branch A user from editing purchase request in branch B', function () {
    $otherPurchaseRequest = createOtherBranchPurchaseRequest($this->otherBranch);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-requests.edit', $otherPurchaseRequest))
        ->assertForbidden();
});

it('denies branch A user from updating purchase request in branch B', function () {
    $otherPurchaseRequest = createOtherBranchPurchaseRequest($this->otherBranch);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->manager)
        ->put(
            route('inventory.purchase-requests.update', $otherPurchaseRequest),
            prBranchIsolationPayload($product->id),
        )
        ->assertForbidden();

    $otherPurchaseRequest->refresh();
    expect($otherPurchaseRequest->status)->toBe(PurchaseRequest::STATUS_DRAFT);
});

it('denies branch A user from submitting purchase request in branch B', function () {
    $otherPurchaseRequest = createOtherBranchPurchaseRequest($this->otherBranch);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-requests.submit', $otherPurchaseRequest))
        ->assertForbidden();

    $otherPurchaseRequest->refresh();
    expect($otherPurchaseRequest->status)->toBe(PurchaseRequest::STATUS_DRAFT);
});

it('denies branch A user from approving purchase request in branch B', function () {
    $otherPurchaseRequest = createOtherBranchPurchaseRequest($this->otherBranch, PurchaseRequest::STATUS_SUBMITTED);

    $this->actingAs($this->approver)
        ->post(route('inventory.purchase-requests.approve', $otherPurchaseRequest))
        ->assertForbidden();

    $otherPurchaseRequest->refresh();
    expect($otherPurchaseRequest->status)->toBe(PurchaseRequest::STATUS_SUBMITTED);
});

it('denies branch A user from rejecting purchase request in branch B', function () {
    $otherPurchaseRequest = createOtherBranchPurchaseRequest($this->otherBranch, PurchaseRequest::STATUS_SUBMITTED);

    $this->actingAs($this->approver)
        ->post(route('inventory.purchase-requests.reject', $otherPurchaseRequest), [
            'rejection_reason' => 'Tidak diperlukan',
        ])
        ->assertForbidden();

    $otherPurchaseRequest->refresh();
    expect($otherPurchaseRequest->status)->toBe(PurchaseRequest::STATUS_SUBMITTED);
});

it('denies branch A user from cancelling purchase request in branch B', function () {
    $otherDraft = createOtherBranchPurchaseRequest($this->otherBranch);
    $otherSubmitted = createOtherBranchPurchaseRequest($this->otherBranch, PurchaseRequest::STATUS_SUBMITTED);

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-requests.cancel', $otherDraft))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('inventory.purchase-requests.cancel', $otherSubmitted))
        ->assertForbidden();

    expect($otherDraft->refresh()->status)->toBe(PurchaseRequest::STATUS_DRAFT)
        ->and($otherSubmitted->refresh()->status)->toBe(PurchaseRequest::STATUS_SUBMITTED);
});

it('lists only purchase requests from the active branch on index', function () {
    $branchPurchaseRequest = createOtherBranchPurchaseRequest(
        $this->branch,
        PurchaseRequest::STATUS_DRAFT,
        'PR-ACTIVE-BRANCH-ONLY',
    );
    $otherBranchPurchaseRequest = createOtherBranchPurchaseRequest(
        $this->otherBranch,
        PurchaseRequest::STATUS_DRAFT,
        'PR-CROSS-BRANCH-ISOLATION-LEAK',
    );

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-requests.index'))
        ->assertOk()
        ->assertSee($branchPurchaseRequest->purchase_request_number)
        ->assertDontSee($otherBranchPurchaseRequest->purchase_request_number);
});

it('rejects cross branch purchase request operations through service branch guard', function () {
    $otherDraft = createOtherBranchPurchaseRequest($this->otherBranch);
    $otherSubmitted = createOtherBranchPurchaseRequest($this->otherBranch, PurchaseRequest::STATUS_SUBMITTED);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->updateDraft(
        $otherDraft,
        prBranchIsolationPayload($product->id),
        $this->manager,
    ))->toThrow(ValidationException::class);

    expect(fn () => $this->service->submit($otherDraft, $this->manager))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->approve($otherSubmitted, $this->approver))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->reject($otherSubmitted, $this->approver, 'Alasan penolakan'))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->cancel($otherDraft, $this->manager))
        ->toThrow(ValidationException::class);

    expect(fn () => $this->service->cancel($otherSubmitted, $this->manager))
        ->toThrow(ValidationException::class);
});

it('scopes listForBranch service results to the requested branch only', function () {
    createOtherBranchPurchaseRequest(
        $this->branch,
        PurchaseRequest::STATUS_DRAFT,
        'PR-SERVICE-LIST-ACTIVE',
    );
    createOtherBranchPurchaseRequest(
        $this->otherBranch,
        PurchaseRequest::STATUS_DRAFT,
        'PR-SERVICE-LIST-OTHER',
    );

    $results = $this->service->listForBranch($this->branch->id);

    expect($results->total())->toBe(1)
        ->and($results->first()->purchase_request_number)->toBe('PR-SERVICE-LIST-ACTIVE');
});

it('denies unauthorized users without inventory permissions', function () {
    $purchaseRequest = createOtherBranchPurchaseRequest($this->branch);
    $submitted = createOtherBranchPurchaseRequest($this->branch, PurchaseRequest::STATUS_SUBMITTED);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $unauthorizedUser = User::factory()->create();

    $this->actingAs($unauthorizedUser)
        ->get(route('inventory.purchase-requests.index'))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->get(route('inventory.purchase-requests.show', $purchaseRequest))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->get(route('inventory.purchase-requests.create'))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->post(route('inventory.purchase-requests.store'), prBranchIsolationPayload($product->id))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->get(route('inventory.purchase-requests.edit', $purchaseRequest))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->put(route('inventory.purchase-requests.update', $purchaseRequest), prBranchIsolationPayload($product->id))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->post(route('inventory.purchase-requests.submit', $purchaseRequest))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->post(route('inventory.purchase-requests.approve', $submitted))
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->post(route('inventory.purchase-requests.reject', $submitted), ['rejection_reason' => 'Tidak diperlukan'])
        ->assertForbidden();

    $this->actingAs($unauthorizedUser)
        ->post(route('inventory.purchase-requests.cancel', $purchaseRequest))
        ->assertForbidden();
});
