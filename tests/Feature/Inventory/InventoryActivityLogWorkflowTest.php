<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\InventoryActivityLogService;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use App\Modules\Inventory\Services\PurchaseRequestService;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Modules\Inventory\Services\StockTransferService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->actingAs($this->manager);
});

function workflowPrPayload(int $productId, ?int $locationId = null): array
{
    return [
        'request_date' => now()->toDateString(),
        'notes' => 'Activity log workflow test',
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_requested' => 5,
                'estimated_unit_price' => 10000,
            ],
        ],
    ];
}

function workflowPoPayload(int $supplierId, int $productId, int $locationId): array
{
    return [
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplierId,
        'items' => [
            [
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'quantity_ordered' => 10,
                'unit_price' => 2500,
            ],
        ],
    ];
}

function workflowAdvancePoToSent(PurchaseOrderService $service, PurchaseOrder $po, $user): PurchaseOrder
{
    $submitted = $service->submit($po, $user);
    $approved = $service->approve($submitted, $user);

    return $service->markAsSent($approved, $user);
}

function workflowGrPayload(int $poId, int $poItemId, int $productId, int $locationId, float $qty = 5): array
{
    return [
        'purchase_order_id' => $poId,
        'receipt_date' => now()->toDateString(),
        'items' => [
            [
                'purchase_order_item_id' => $poItemId,
                'product_id' => $productId,
                'inventory_location_id' => $locationId,
                'accepted_qty' => $qty,
                'rejected_qty' => 0,
            ],
        ],
    ];
}

function assertActivityLogExists(
    int $branchId,
    string $action,
    string $subjectTable,
    int $subjectId,
    ?int $userId = null,
): InventoryActivityLog {
    $log = InventoryActivityLog::query()
        ->where('branch_id', $branchId)
        ->where('action', $action)
        ->where('subject_type', $subjectTable)
        ->where('subject_id', $subjectId)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->branch_id)->toBe($branchId);

    if ($userId !== null) {
        expect($log->user_id)->toBe($userId);
    }

    return $log;
}

describe('Purchase Request activity logging', function () {
    it('logs created submitted approved rejected and cancelled actions', function () {
        $service = app(PurchaseRequestService::class);
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);

        $created = $service->createDraft(workflowPrPayload($product->id), $this->manager);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_CREATED,
            $created->getTable(),
            $created->id,
            $this->manager->id,
        );

        $submitted = $service->submit($created, $this->manager);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_SUBMITTED,
            $submitted->getTable(),
            $submitted->id,
        );

        $approved = $service->approve($submitted, $this->manager);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_APPROVED,
            $approved->getTable(),
            $approved->id,
        );

        $draftForReject = $service->createDraft(workflowPrPayload($product->id), $this->manager);
        $submittedForReject = $service->submit($draftForReject, $this->manager);
        $rejected = $service->reject($submittedForReject, $this->manager, 'Not needed');
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_REJECTED,
            $rejected->getTable(),
            $rejected->id,
        );

        $draftForCancel = $service->createDraft(workflowPrPayload($product->id), $this->manager);
        $cancelled = $service->cancel($draftForCancel, $this->manager);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_CANCELLED,
            $cancelled->getTable(),
            $cancelled->id,
        );
    });

    it('does not log when unauthorized workflow action fails validation', function () {
        $service = app(PurchaseRequestService::class);
        $approved = PurchaseRequest::factory()->approved()->create(['branch_id' => $this->branch->id]);
        $before = InventoryActivityLog::count();

        expect(fn () => $service->cancel($approved, $this->manager))
            ->toThrow(ValidationException::class)
            ->and(InventoryActivityLog::count())->toBe($before);
    });
});

describe('Purchase Order activity logging', function () {
    it('logs created approved and cancelled actions', function () {
        $service = app(PurchaseOrderService::class);
        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);
        $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

        $created = $service->createDraft(
            workflowPoPayload($supplier->id, $product->id, $location->id),
            $this->manager,
        );
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_ORDER_CREATED,
            $created->getTable(),
            $created->id,
        );

        $submitted = $service->submit($created, $this->manager);
        $approved = $service->approve($submitted, $this->manager);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_ORDER_APPROVED,
            $approved->getTable(),
            $approved->id,
        );

        $draftForCancel = $service->createDraft(
            workflowPoPayload($supplier->id, $product->id, $location->id),
            $this->manager,
        );
        $submittedForCancel = $service->submit($draftForCancel, $this->manager);
        $cancelled = $service->cancel($submittedForCancel, $this->manager);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_ORDER_CANCELLED,
            $cancelled->getTable(),
            $cancelled->id,
        );
    });
});

describe('Goods Receipt activity logging', function () {
    it('logs created completed and inventory movement created', function () {
        $poService = app(PurchaseOrderService::class);
        $grService = app(GoodsReceiptService::class);
        $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);
        $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

        $po = $poService->createDraft(
            workflowPoPayload($supplier->id, $product->id, $location->id),
            $this->manager,
        );
        $sent = workflowAdvancePoToSent($poService, $po, $this->manager);
        $poItem = $sent->items()->first();

        $created = $grService->createFromPurchaseOrder(
            workflowGrPayload($sent->id, $poItem->id, $product->id, $location->id),
            $this->manager,
        );
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::GOODS_RECEIPT_CREATED,
            $created->getTable(),
            $created->id,
        );

        $posted = $grService->post($created, $this->manager);
        $completedLog = assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::GOODS_RECEIPT_COMPLETED,
            $posted->getTable(),
            $posted->id,
        );

        expect($completedLog->metadata['movement_ids'] ?? null)->not->toBeEmpty();

        $movementId = $posted->items()->first()->inventory_movement_id;
        expect($movementId)->not->toBeNull();

        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            (new InventoryMovement)->getTable(),
            $movementId,
        );
    });
});

describe('Stock Transfer activity logging', function () {
    it('logs created approved received cancelled and movement created', function () {
        $stock = app(InventoryStockService::class);
        $service = app(StockTransferService::class);
        $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
        $destination = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);

        $stock->createOpeningStock($product->id, $source->id, 20);

        $created = $service->createTransfer($source->id, $destination->id, [
            ['product_id' => $product->id, 'quantity' => 5],
        ]);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::STOCK_TRANSFER_CREATED,
            $created->getTable(),
            $created->id,
        );

        $service->submitTransfer($created->id);
        $shipped = $service->shipTransfer($created->id);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::STOCK_TRANSFER_APPROVED,
            $shipped->getTable(),
            $shipped->id,
        );

        $outMovement = InventoryMovement::query()
            ->where('reference_type', $shipped->getTable())
            ->where('reference_id', $shipped->id)
            ->where('movement_type', InventoryMovement::TYPE_TRANSFER_OUT)
            ->first();
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            $outMovement->getTable(),
            $outMovement->id,
        );

        $received = $service->receiveTransfer($shipped->id);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::STOCK_TRANSFER_RECEIVED,
            $received->getTable(),
            $received->id,
        );

        $draftForCancel = $service->createTransfer($source->id, $destination->id, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);
        $cancelled = $service->cancelTransfer($draftForCancel->id);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::STOCK_TRANSFER_CANCELLED,
            $cancelled->getTable(),
            $cancelled->id,
        );
    });
});

describe('Stock Opname activity logging', function () {
    it('logs created and completed actions', function () {
        $stock = app(InventoryStockService::class);
        $service = app(StockOpnameService::class);
        $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
        $product = Product::factory()->create(['branch_id' => $this->branch->id, 'average_cost' => 100]);

        $stock->createOpeningStock($product->id, $location->id, 10, 100);

        $created = $service->createDraftOpname($location->id, [$product->id]);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::STOCK_OPNAME_CREATED,
            $created->getTable(),
            $created->id,
        );

        $service->updateCountedQuantity($created->id, $product->id, 12);
        $service->reviewOpname($created->id);
        $completed = $service->finalizeOpname($created->id);

        $completedLog = assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::STOCK_OPNAME_COMPLETED,
            $completed->getTable(),
            $completed->id,
        );

        expect($completedLog->metadata['movement_ids'] ?? null)->not->toBeEmpty();
    });
});

describe('Inventory Movement activity logging', function () {
    it('logs opening adjustment in and adjustment out movements', function () {
        $service = app(InventoryStockService::class);
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);
        $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

        $opening = $service->createOpeningStock($product->id, $location->id, 10, 5000);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            $opening->getTable(),
            $opening->id,
        );

        $adjustIn = $service->adjustIn($product->id, $location->id, 2);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            $adjustIn->getTable(),
            $adjustIn->id,
        );

        $adjustOut = $service->adjustOut($product->id, $location->id, 3);
        assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::INVENTORY_MOVEMENT_CREATED,
            $adjustOut->getTable(),
            $adjustOut->id,
        );
    });
});

describe('non-blocking activity logging', function () {
    it('keeps workflow successful when activity logger throws', function () {
        $mock = Mockery::mock(InventoryActivityLogService::class);
        $mock->shouldReceive('log')->andThrow(new RuntimeException('Logging failed'));
        app()->instance(InventoryActivityLogService::class, $mock);

        $service = app(PurchaseRequestService::class);
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);

        $purchaseRequest = $service->createDraft(workflowPrPayload($product->id), $this->manager);

        expect($purchaseRequest->exists)->toBeTrue()
            ->and($purchaseRequest->status)->toBe(PurchaseRequest::STATUS_DRAFT)
            ->and(InventoryActivityLog::count())->toBe(0);
    });
});

describe('branch isolation for activity logs', function () {
    it('stores branch_id matching subject branch_id', function () {
        $service = app(PurchaseRequestService::class);
        $product = Product::factory()->create(['branch_id' => $this->branch->id]);

        $purchaseRequest = $service->createDraft(workflowPrPayload($product->id), $this->manager);
        $log = assertActivityLogExists(
            $this->branch->id,
            InventoryActivityAction::PURCHASE_REQUEST_CREATED,
            $purchaseRequest->getTable(),
            $purchaseRequest->id,
        );

        expect($log->branch_id)->toBe($purchaseRequest->branch_id);
    });

    it('does not create logs when cross branch workflow fails', function () {
        $service = app(PurchaseRequestService::class);
        $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
        $before = InventoryActivityLog::count();

        expect(fn () => $service->createDraft(workflowPrPayload($otherProduct->id), $this->manager))
            ->toThrow(ValidationException::class)
            ->and(InventoryActivityLog::count())->toBe($before);
    });
});
