<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
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
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->actingAs($this->manager);
});

it('blocks cross branch purchase order on service create', function () {
    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $otherPo = PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->otherBranch->id,
        'supplier_id' => $otherSupplier->id,
    ]);

    $otherPoItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $otherPo->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
        'quantity_ordered' => 10,
    ]);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($otherPo->id, $otherPoItem->id, $otherProduct->id, $otherLocation->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks cross branch inventory location on service create', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product] = createSentPurchaseOrderWithItem($this);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $otherLocation->id),
        $this->manager,
    );
})->throws(ValidationException::class);

it('blocks cross branch goods receipt post through service branch guard', function () {
    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $otherPo = PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->otherBranch->id,
        'supplier_id' => $otherSupplier->id,
    ]);

    $otherPoItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $otherPo->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
        'quantity_ordered' => 10,
    ]);

    $otherGoodsReceipt = GoodsReceipt::factory()->forPurchaseOrder($otherPo)->draft()->create([
        'branch_id' => $this->otherBranch->id,
        'created_by' => $this->manager->id,
    ]);

    GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $otherGoodsReceipt->id,
        'purchase_order_item_id' => $otherPoItem->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
        'accepted_qty' => 5,
        'received_qty' => 5,
    ]);

    expect(fn () => $this->service->post($otherGoodsReceipt, $this->manager))
        ->toThrow(ValidationException::class);
});

it('blocks cross branch goods receipt update through service branch guard', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );

    $goodsReceipt->update(['branch_id' => $this->otherBranch->id]);

    expect(fn () => $this->service->updateDraft($goodsReceipt, [
        'receipt_date' => now()->toDateString(),
        'items' => goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 6)['items'],
    ], $this->manager))->toThrow(ValidationException::class);
});

it('denies cross branch goods receipt routes', function () {
    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $otherPo = PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->otherBranch->id,
        'supplier_id' => $otherSupplier->id,
    ]);

    $otherPoItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $otherPo->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
        'quantity_ordered' => 10,
    ]);

    $otherGoodsReceipt = GoodsReceipt::factory()->forPurchaseOrder($otherPo)->draft()->create([
        'branch_id' => $this->otherBranch->id,
        'created_by' => $this->manager->id,
    ]);

    GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $otherGoodsReceipt->id,
        'purchase_order_item_id' => $otherPoItem->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $otherGoodsReceipt))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.edit', $otherGoodsReceipt))
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->put(route('inventory.goods-receipts.update', $otherGoodsReceipt), goodsReceiptPayload(
            $otherPo->id,
            $otherPoItem->id,
            $otherProduct->id,
            $otherLocation->id,
        ))
        ->assertRedirect()
        ->assertSessionHasErrors('goods_receipt');

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.submit', $otherGoodsReceipt))
        ->assertRedirect()
        ->assertSessionHasErrors('goods_receipt');

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.post', $otherGoodsReceipt))
        ->assertRedirect()
        ->assertSessionHasErrors('goods_receipt');

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.cancel', $otherGoodsReceipt))
        ->assertRedirect()
        ->assertSessionHasErrors('goods_receipt');

    $otherPosted = GoodsReceipt::factory()->forPurchaseOrder($otherPo)->posted()->create([
        'branch_id' => $this->otherBranch->id,
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.void', $otherPosted), ['reason' => 'Cross branch'])
        ->assertRedirect()
        ->assertSessionHasErrors('goods_receipt');
});

it('lists only goods receipts from the active branch on index', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $branchReceipt = GoodsReceipt::factory()->forPurchaseOrder($po)->draft()->create([
        'branch_id' => $this->branch->id,
        'receipt_number' => 'GR-ACTIVE-BRANCH-ONLY',
        'created_by' => $this->manager->id,
    ]);

    $otherPo = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->otherBranch->id]);
    $otherBranchReceipt = GoodsReceipt::factory()->forPurchaseOrder($otherPo)->draft()->create([
        'branch_id' => $this->otherBranch->id,
        'receipt_number' => 'GR-CROSS-BRANCH-ISOLATION-LEAK',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.goods-receipts.index'))
        ->assertOk()
        ->assertSee($branchReceipt->receipt_number)
        ->assertDontSee($otherBranchReceipt->receipt_number)
        ->assertDontSee('GR-CROSS-BRANCH-ISOLATION-LEAK');
});

it('rejects cross branch purchase order on store route', function () {
    $otherSupplier = Supplier::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);

    $otherPo = PurchaseOrder::factory()->sent()->create([
        'branch_id' => $this->otherBranch->id,
        'supplier_id' => $otherSupplier->id,
    ]);

    $otherPoItem = PurchaseOrderItem::factory()->create([
        'purchase_order_id' => $otherPo->id,
        'product_id' => $otherProduct->id,
        'inventory_location_id' => $otherLocation->id,
        'quantity_ordered' => 10,
    ]);

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.store'), goodsReceiptPayload(
            $otherPo->id,
            $otherPoItem->id,
            $otherProduct->id,
            $otherLocation->id,
        ))
        ->assertSessionHasErrors('purchase_order_id');

    expect(GoodsReceipt::count())->toBe(0);
});
