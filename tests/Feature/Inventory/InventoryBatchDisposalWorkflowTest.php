<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryBatchDisposalWorkflowService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Carbon::setTestNow('2026-07-03 12:00:00');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'T6842', 'name' => 'Branch 6842 Other']);
    $this->operator = userWith(['manage_inventory_batch_lot', 'view_stock_alert']);
    $this->approver = userWith(['manage_inventory', 'view_inventory_batch_lot']);
    $this->viewer = userWith(['view_inventory_batch_lot']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sprint6842BatchWithStock(
    Branch $branch,
    array $batchOverrides = [],
    float $quantity = 10,
): array {
    $product = $batchOverrides['product'] ?? Product::factory()->create(['branch_id' => $branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);

    unset($batchOverrides['product']);

    $batch = InventoryBatch::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'expiry_date' => now()->subDays(3)->toDateString(),
    ], $batchOverrides));

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity_in' => $quantity,
        'quantity_out' => 0,
    ]);

    return compact('batch', 'product', 'location');
}

function sprint6842SubmitPayload(InventoryLocation $location, array $overrides = []): array
{
    return array_merge([
        'inventory_location_id' => $location->id,
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
        'quantity_requested' => 5,
        'evidence_note' => 'Batch kedaluwarsa, bukti inspeksi gudang tersedia.',
        'evidence_reference' => 'BA-6842-001',
    ], $overrides);
}

it('allows authorized inventory user to submit disposal request for branch batch', function () {
    $data = sprint6842BatchWithStock($this->branch, ['batch_number' => 'B6842-SUBMIT']);
    $this->actingAs($this->operator);

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']))
        ->assertRedirect()
        ->assertSessionHas('status');

    $this->assertDatabaseHas('trx_inventory_batch_disposal_requests', [
        'branch_id' => $this->branch->id,
        'inventory_batch_id' => $data['batch']->id,
        'inventory_location_id' => $data['location']->id,
        'status' => InventoryBatchDisposalRequestStatus::SUBMITTED,
        'submitted_by' => $this->operator->id,
    ]);
});

it('uses branch context not request branch_id when creating disposal request', function () {
    $data = sprint6842BatchWithStock($this->branch, ['batch_number' => 'B6842-CTX']);
    $this->actingAs($this->operator);

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), array_merge(
        sprint6842SubmitPayload($data['location']),
        ['branch_id' => $this->otherBranch->id],
    ))->assertRedirect();

    $request = InventoryBatchDisposalRequest::query()->latest('id')->first();

    expect($request)->not->toBeNull()
        ->and($request->branch_id)->toBe($this->branch->id);
});

it('stores available quantity snapshot as audit only on submit', function () {
    $data = sprint6842BatchWithStock($this->branch, quantity: 12);
    $this->actingAs($this->operator);

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location'], [
        'quantity_requested' => 4,
    ]))->assertRedirect();

    $request = InventoryBatchDisposalRequest::query()->latest('id')->first();

    expect((float) $request->available_quantity_snapshot)->toBe(12.0);
});

it('does not create movements when disposal request is submitted', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $this->actingAs($this->operator);
    $countBefore = InventoryMovement::query()->count();

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']));

    expect(InventoryMovement::query()->count())->toBe($countBefore);
});

it('does not change ledger stock when disposal request is submitted', function () {
    $data = sprint6842BatchWithStock($this->branch, quantity: 8);
    $this->actingAs($this->operator);
    $stockBefore = app(InventoryStockService::class)->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']));

    $stockAfter = app(InventoryStockService::class)->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    expect($stockAfter)->toBe($stockBefore);
});

it('rejects invalid request type', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $this->actingAs($this->operator);

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location'], [
        'request_type' => 'invalid_type',
    ]))->assertSessionHasErrors('request_type');
});

it('rejects quantity greater than batch location available stock', function () {
    $data = sprint6842BatchWithStock($this->branch, quantity: 3);
    $this->actingAs($this->operator);

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location'], [
        'quantity_requested' => 5,
    ]))->assertSessionHasErrors('quantity_requested');
});

it('blocks unauthorized user from submitting disposal request', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $this->actingAs($this->viewer);

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']))
        ->assertForbidden();
});

it('blocks other branch user from submitting disposal request', function () {
    $data = sprint6842BatchWithStock($this->otherBranch, ['batch_number' => 'B6842-OTHER']);
    $this->actingAs($this->operator);

    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']))
        ->assertForbidden();
});

it('allows authorized approver to approve submitted request', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $this->actingAs($this->operator);
    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']));
    $request = InventoryBatchDisposalRequest::query()->latest('id')->firstOrFail();

    $this->actingAs($this->approver);
    $movementCountBefore = InventoryMovement::query()->count();

    $this->post(route('inventory.batch-disposal-requests.approve', $request))
        ->assertRedirect()
        ->assertSessionHas('status');

    $request->refresh();
    expect($request->status)->toBe(InventoryBatchDisposalRequestStatus::APPROVED)
        ->and($request->approved_by)->toBe($this->approver->id)
        ->and(InventoryMovement::query()->count())->toBe($movementCountBefore);
});

it('allows authorized approver to reject submitted request with reason', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $this->actingAs($this->operator);
    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']));
    $request = InventoryBatchDisposalRequest::query()->latest('id')->firstOrFail();

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.reject', $request), [
        'rejection_reason' => 'Bukti tidak lengkap.',
    ])->assertRedirect();

    $request->refresh();
    expect($request->status)->toBe(InventoryBatchDisposalRequestStatus::REJECTED)
        ->and($request->rejection_reason)->toBe('Bukti tidak lengkap.');
});

it('blocks finalization of rejected request', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $this->actingAs($this->operator);
    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']));
    $request = InventoryBatchDisposalRequest::query()->latest('id')->firstOrFail();

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.reject', $request), ['rejection_reason' => 'Ditolak.']);

    $this->post(route('inventory.batch-disposal-requests.finalize-adjustment', $request))
        ->assertForbidden();
});

it('finalizes approved request into exactly one ADJUSTMENT_OUT movement', function () {
    $data = sprint6842BatchWithStock($this->branch, quantity: 10);
    $this->actingAs($this->operator);
    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location'], [
        'quantity_requested' => 6,
    ]));
    $request = InventoryBatchDisposalRequest::query()->latest('id')->firstOrFail();

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.approve', $request));

    $adjustmentCountBefore = InventoryMovement::query()
        ->where('movement_type', InventoryMovement::TYPE_ADJUSTMENT_OUT)
        ->count();

    $this->post(route('inventory.batch-disposal-requests.finalize-adjustment', $request))
        ->assertRedirect()
        ->assertSessionHas('status');

    $request->refresh();
    $movement = $request->movement;

    expect(InventoryMovement::query()->where('movement_type', InventoryMovement::TYPE_ADJUSTMENT_OUT)->count())
        ->toBe($adjustmentCountBefore + 1)
        ->and($request->status)->toBe(InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED)
        ->and($movement)->not->toBeNull()
        ->and($movement->movement_type)->toBe(InventoryMovement::TYPE_ADJUSTMENT_OUT);
});

it('preserves inventory_batch_id on finalized movement', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $request = sprint6842ApprovedRequest($this->operator, $this->approver, $data);

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.finalize-adjustment', $request));

    expect($request->fresh()->movement->inventory_batch_id)->toBe($data['batch']->id);
});

it('preserves inventory location and product on finalized movement', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $request = sprint6842ApprovedRequest($this->operator, $this->approver, $data);

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.finalize-adjustment', $request));

    $movement = $request->fresh()->movement;
    expect($movement->inventory_location_id)->toBe($data['location']->id)
        ->and($movement->product_id)->toBe($data['product']->id);
});

it('uses quantity_out not quantity_in on finalized movement', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $request = sprint6842ApprovedRequest($this->operator, $this->approver, $data, ['quantity_requested' => 3]);

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.finalize-adjustment', $request));

    $movement = $request->fresh()->movement;
    expect((float) $movement->quantity_out)->toBe(3.0)
        ->and((float) $movement->quantity_in)->toBe(0.0);
});

it('reduces ledger stock by requested quantity on finalization', function () {
    $data = sprint6842BatchWithStock($this->branch, quantity: 10);
    $request = sprint6842ApprovedRequest($this->operator, $this->approver, $data, ['quantity_requested' => 4]);

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.finalize-adjustment', $request));

    $stockAfter = app(InventoryStockService::class)->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    expect($stockAfter)->toBe(6.0);
});

it('does not create duplicate movement when finalization is retried', function () {
    $data = sprint6842BatchWithStock($this->branch);
    $request = sprint6842ApprovedRequest($this->operator, $this->approver, $data);

    $this->actingAs($this->approver);
    $this->post(route('inventory.batch-disposal-requests.finalize-adjustment', $request));
    $countAfterFirst = InventoryMovement::query()->count();

    app(InventoryBatchDisposalWorkflowService::class)->finalizeAdjustment($request->fresh());

    expect(InventoryMovement::query()->count())->toBe($countAfterFirst);
});

it('fails finalization when current stock became insufficient after approval', function () {
    $data = sprint6842BatchWithStock($this->branch, quantity: 10);
    $request = sprint6842ApprovedRequest($this->operator, $this->approver, $data, ['quantity_requested' => 5]);

    InventoryMovement::factory()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'inventory_batch_id' => $data['batch']->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'quantity_in' => 0,
        'quantity_out' => 8,
    ]);

    $this->actingAs($this->approver);

    expect(fn () => app(InventoryBatchDisposalWorkflowService::class)->finalizeAdjustment($request->fresh()))
        ->toThrow(ValidationException::class);
});

it('scopes index and show to active branch', function () {
    $otherData = sprint6842BatchWithStock($this->otherBranch, ['batch_number' => 'B6842-OTHER-SCOPE']);
    $otherRequest = InventoryBatchDisposalRequest::query()->create([
        'branch_id' => $this->otherBranch->id,
        'inventory_batch_id' => $otherData['batch']->id,
        'inventory_location_id' => $otherData['location']->id,
        'product_id' => $otherData['product']->id,
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
        'status' => InventoryBatchDisposalRequestStatus::SUBMITTED,
        'quantity_requested' => 2,
        'available_quantity_snapshot' => 10,
        'evidence_note' => 'Permintaan cabang lain untuk uji isolasi.',
        'submitted_by' => $this->operator->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs($this->operator);
    $this->get(route('inventory.batch-disposal-requests.show', $otherRequest))->assertForbidden();

    $local = sprint6842BatchWithStock($this->branch, ['batch_number' => 'B6842-SCOPE']);
    $this->post(route('inventory.batches.disposal-requests.store', $local['batch']), sprint6842SubmitPayload($local['location']));

    $this->actingAs($this->viewer);
    $this->get(route('inventory.batch-disposal-requests.index'))->assertOk()
        ->assertSee($local['batch']->batch_number)
        ->assertDontSee($otherData['batch']->batch_number);
});

it('exposes linked disposal requests on batch detail', function () {
    $data = sprint6842BatchWithStock($this->branch, ['batch_number' => 'B6842-LINK']);
    $this->actingAs($this->operator);
    $this->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location']));

    $this->actingAs($this->viewer);
    $this->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('Permintaan Disposal/Adjustment')
        ->assertSee('Pemusnahan');
});

function sprint6842ApprovedRequest($operator, $approver, array $data, array $payloadOverrides = []): InventoryBatchDisposalRequest
{
    test()->actingAs($operator);
    test()->post(route('inventory.batches.disposal-requests.store', $data['batch']), sprint6842SubmitPayload($data['location'], $payloadOverrides));
    $request = InventoryBatchDisposalRequest::query()->latest('id')->firstOrFail();

    test()->actingAs($approver);
    test()->post(route('inventory.batch-disposal-requests.approve', $request));

    return $request->fresh();
}
