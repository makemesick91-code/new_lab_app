<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\Supplier;
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
    $this->viewer = userWith(['view_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
});

it('redirects guest from goods receipt index', function () {
    $this->get(route('inventory.goods-receipts.index'))
        ->assertRedirect(route('login'));
});

it('registers goods receipt route names', function () {
    $routes = [
        'inventory.goods-receipts.index',
        'inventory.goods-receipts.create',
        'inventory.goods-receipts.store',
        'inventory.goods-receipts.show',
        'inventory.goods-receipts.edit',
        'inventory.goods-receipts.update',
        'inventory.goods-receipts.submit',
        'inventory.goods-receipts.post',
        'inventory.goods-receipts.cancel',
        'inventory.goods-receipts.void',
    ];

    foreach ($routes as $routeName) {
        expect(Route::has($routeName))->toBeTrue();
    }
});

it('allows view_inventory to access index for authorized user', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);
    GoodsReceipt::factory()->forPurchaseOrder($po)->draft()->create([
        'branch_id' => $this->branch->id,
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.index'))
        ->assertOk();
});

it('allows create route and filters receivable purchase orders', function () {
    ['sent' => $sentPo, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $draftPo = $this->purchaseOrderService->createDraft(
        grPurchaseOrderPayload(
            Supplier::factory()->create(['branch_id' => $this->branch->id])->id,
            Product::factory()->create(['branch_id' => $this->branch->id])->id,
            InventoryLocation::factory()->create(['branch_id' => $this->branch->id])->id,
        ),
        $this->manager,
    );

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create'))
        ->assertOk();

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $sentPo->id]))
        ->assertOk()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $draftPo->id]))
        ->assertRedirect(route('inventory.goods-receipts.create'))
        ->assertSessionHasErrors('purchase_order_id');
});

it('allows manage_inventory to store draft goods receipt', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.store'), goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5))
        ->assertRedirect();

    $goodsReceipt = GoodsReceipt::query()->where('branch_id', $this->branch->id)->first();

    expect($goodsReceipt)->not->toBeNull()
        ->and($goodsReceipt->status)->toBe(GoodsReceipt::STATUS_DRAFT)
        ->and($goodsReceipt->purchase_order_id)->toBe($po->id);
});

it('rejects store for invalid purchase order status', function () {
    ['supplier' => $supplier, 'product' => $product, 'location' => $location] = grBranchFixtures($this);

    $draftPo = $this->purchaseOrderService->createDraft(
        grPurchaseOrderPayload($supplier->id, $product->id, $location->id),
        $this->manager,
    );
    $poItem = $draftPo->items()->first();

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.store'), goodsReceiptPayload($draftPo->id, $poItem->id, $product->id, $location->id, 5))
        ->assertSessionHasErrors('purchase_order_id');

    expect(GoodsReceipt::count())->toBe(0);
});

it('allows view_inventory to show same branch goods receipt', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk();
});

it('blocks edit route for posted goods receipt', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $posted = GoodsReceipt::factory()->forPurchaseOrder($po)->posted()->create([
        'branch_id' => $this->branch->id,
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.edit', $posted))
        ->assertForbidden();
});

it('allows manage_inventory to update draft goods receipt only', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.edit', $goodsReceipt))
        ->assertOk();

    $this->actingAs($this->manager)
        ->put(route('inventory.goods-receipts.update', $goodsReceipt), goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 7))
        ->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt));

    expect((float) $goodsReceipt->refresh()->items->first()->accepted_qty)->toBe(7.0);

    $posted = GoodsReceipt::factory()->forPurchaseOrder($po)->posted()->create([
        'branch_id' => $this->branch->id,
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->put(route('inventory.goods-receipts.update', $posted), goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 1))
        ->assertSessionHasErrors('goods_receipt');
});

it('submits draft goods receipt through submit route', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.submit', $goodsReceipt))
        ->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt));

    expect($goodsReceipt->refresh()->status)->toBe(GoodsReceipt::STATUS_SUBMITTED);
});

it('posts goods receipt through post route and redirects with ledger message', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);
    $beforeMovements = InventoryMovement::count();

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.post', $goodsReceipt))
        ->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertSessionHas('status');

    $goodsReceipt->refresh();

    expect($goodsReceipt->status)->toBe(GoodsReceipt::STATUS_POSTED)
        ->and($goodsReceipt->posted_at)->not->toBeNull()
        ->and(InventoryMovement::count())->toBe($beforeMovements + 1);
});

it('cancels draft goods receipt through cancel route', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.cancel', $goodsReceipt))
        ->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertSessionHas('status');

    expect($goodsReceipt->refresh()->status)->toBe(GoodsReceipt::STATUS_CANCELLED);
});

it('cancels submitted goods receipt through cancel route', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );
    $submitted = $this->service->submit($goodsReceipt, $this->manager);

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.cancel', $submitted), ['notes' => 'Salah ajukan'])
        ->assertRedirect(route('inventory.goods-receipts.show', $submitted))
        ->assertSessionHas('status');

    expect($submitted->refresh()->status)->toBe(GoodsReceipt::STATUS_CANCELLED);
});

it('denies cross branch goods receipt access', function () {
    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $otherPo = PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->otherBranch->id,
        'supplier_id' => $otherSupplier->id,
    ]);
    PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $otherPo->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
    ]);

    $otherGoodsReceipt = GoodsReceipt::factory()->forPurchaseOrder($otherPo)->draft()->create([
        'branch_id' => $this->otherBranch->id,
        'created_by' => $this->manager->id,
    ]);
    GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $otherGoodsReceipt->id,
        'purchase_order_item_id' => $otherPo->items()->first()->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.show', $otherGoodsReceipt))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $otherGoodsReceipt))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.edit', $otherGoodsReceipt))
        ->assertForbidden();
});

it('denies unauthorized user without inventory permissions', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.goods-receipts.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('inventory.goods-receipts.store'), goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5))
        ->assertForbidden();
});

it('posts draft goods receipt directly without submit route', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.post', $goodsReceipt))
        ->assertRedirect(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertSessionHas('status');

    expect($goodsReceipt->refresh()->status)->toBe(GoodsReceipt::STATUS_POSTED);
});

it('denies double post through post route', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.post', $goodsReceipt))
        ->assertRedirect();

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.post', $goodsReceipt->fresh()))
        ->assertSessionHasErrors('goods_receipt');
});

it('denies view_inventory from mutation routes', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.create'))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.goods-receipts.store'), goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.edit', $goodsReceipt))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->put(route('inventory.goods-receipts.update', $goodsReceipt), goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 3))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.goods-receipts.submit', $goodsReceipt))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.goods-receipts.post', $goodsReceipt))
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->post(route('inventory.goods-receipts.cancel', $goodsReceipt))
        ->assertForbidden();
});
