<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->actingAs($this->manager);
});

it('renders the batch_number input on the create form for a batch-tracked product', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee("'items[' + index + '][batch_number]'", false);
});

it('renders the lot_number input on the create form for a batch-tracked product', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee("'items[' + index + '][lot_number]'", false);
});

it('renders the expiry_date input on the create form for a batch-tracked product', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee("'items[' + index + '][expiry_date]'", false);
});

it('shows the batch section based only on requires_batch_tracking, not accepted_qty', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('x-show="item.requires_batch_tracking"', false)
        ->assertSee('Produk ini wajib batch. Isi Nomor Batch sebelum Submit/Post Goods Receipt.')
        ->assertDontSee('item.requires_batch_tracking && Number(item.accepted_qty || 0) > 0', false);
});

it('cannot post a batch-tracked goods receipt with accepted_qty > 0 and missing batch_number', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $payload = grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'batch_number' => '',
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)
        ->assertSessionHasErrors('items.0.batch_number');

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('posts a batch-tracked goods receipt with batch_number and creates an InventoryBatch with linked ids', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);
    $item = $posted->items()->first();
    $movement = InventoryMovement::query()->find($item->inventory_movement_id);
    $batch = InventoryBatch::query()->where('product_id', $product->id)->first();

    expect($batch)->not->toBeNull()
        ->and($batch->batch_number)->toBe('B-GR-001')
        ->and($item->inventory_batch_id)->toBe($batch->id)
        ->and($movement)->not->toBeNull()
        ->and($movement->inventory_batch_id)->toBe($batch->id);
});

it('does not require batch_number for a non-batch-tracked product', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $response = $this->post(route('inventory.goods-receipts.store'), goodsReceiptPayload(
        $po->id,
        $poItem->id,
        $product->id,
        $location->id,
        5,
    ));

    $goodsReceipt = GoodsReceipt::query()->latest('id')->first();

    $response->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt));

    $item = $goodsReceipt->items()->first();

    expect($item->batch_number)->toBeNull()
        ->and($item->inventory_batch_id)->toBeNull();
});

it('does not introduce any manual inventory batch create or store route', function () {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values();

    expect($names)->not->toContain('inventory.batches.create')
        ->and($names)->not->toContain('inventory.batches.store');
});
