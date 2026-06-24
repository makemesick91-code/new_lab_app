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

/**
 * Hotfix: Goods Receipt Create PO Batch Panel Rendering.
 *
 * Root cause: the desktop items table used a single <tbody> whose <template x-for>
 * held TWO sibling <tr> roots (data row + batch row). Alpine's x-for requires a
 * single root element per iteration, so the batch row — carrying the "Produk ini
 * wajib batch" notice and the batch_number/lot_number/expiry inputs — was never
 * cloned into the DOM on desktop viewports. The fix makes the per-item <tbody> the
 * single x-for root (a <table> may hold multiple <tbody> elements).
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->actingAs($this->manager);
});

it('renders the mandatory batch notice on the create-from-PO page for a batch-tracked product', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('Produk ini wajib batch. Isi Nomor Batch sebelum Submit/Post Goods Receipt.');
});

it('renders the batch/lot input markup on the create-from-PO page', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    $response = $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk();

    $response->assertSee("'items[' + index + '][batch_number]'", false);
    $response->assertSee("'items[' + index + '][lot_number]'", false);
    $response->assertSee("'items[' + index + '][batch_received_date]'", false);
    $response->assertSee("'items[' + index + '][expiry_date]'", false);
});

it('embeds the requires_batch_tracking flag as true in the Alpine item payload', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    // The Alpine x-data payload is rendered via @js, which encodes JSON quote characters in the attribute payload.
    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('requires_batch_tracking\u0022:true', false);
});

it('makes the per-item <tbody> the single x-for root so the batch row is not dropped on desktop', function () {
    ['sent' => $po] = createSentPurchaseOrderWithBatchProduct($this);

    $html = $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->getContent();

    $templatePos = strpos($html, '<template x-for="(item, index) in items"');
    $tbodyPos = strpos($html, '<tbody class="divide-y divide-gray-100 bg-white">');

    expect($templatePos)->not->toBeFalse()
        ->and($tbodyPos)->not->toBeFalse()
        // The <tbody> must live INSIDE the template (template opens first), proving
        // each iteration has a single <tbody> root rather than two sibling <tr> roots.
        ->and($tbodyPos)->toBeGreaterThan($templatePos);
});

it('does not require batch_number for a non-batch-tracked product on submit', function () {
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

    expect($goodsReceipt->items()->first()->batch_number)->toBeNull();
});

it('cannot submit a batch-tracked product with accepted_qty > 0 and missing batch_number', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithBatchProduct($this);

    $payload = grBatchPayload($po->id, $poItem->id, $product->id, $location->id, 5, [
        'batch_number' => '',
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)
        ->assertSessionHasErrors('items.0.batch_number');

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('posts a batch-tracked goods receipt with batch_number and links an InventoryBatch', function () {
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
        ->and($item->inventory_batch_id)->toBe($batch->id)
        ->and($movement)->not->toBeNull()
        ->and($movement->inventory_batch_id)->toBe($batch->id);
});

it('does not expose a manual inventory batch create or store route', function () {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values();

    expect($names)->not->toContain('inventory.batches.create')
        ->and($names)->not->toContain('inventory.batches.store');
});
