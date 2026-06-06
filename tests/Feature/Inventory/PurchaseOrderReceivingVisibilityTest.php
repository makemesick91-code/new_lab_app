<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->goodsReceiptService = app(GoodsReceiptService::class);
});

it('shows ordered received and remaining quantities on purchase order show', function () {
    ['sent' => $purchaseOrder, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $goodsReceipt = $this->goodsReceiptService->createFromPurchaseOrder(
        goodsReceiptPayload($purchaseOrder->id, $poItem->id, $product->id, $location->id, 4),
        $this->manager,
    );

    $this->goodsReceiptService->post($goodsReceipt, $this->manager);

    $poItem->refresh();

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee('Dipesan')
        ->assertSee('Diterima')
        ->assertSee('Sisa')
        ->assertSee('Status Penerimaan')
        ->assertSee(format_quantity_id($poItem->quantity_ordered))
        ->assertSee(format_quantity_id($poItem->quantity_received))
        ->assertSee(format_quantity_id($poItem->quantityRemaining()))
        ->assertSee('Sebagian');
});

it('shows linked goods receipts on purchase order show', function () {
    ['sent' => $purchaseOrder, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $goodsReceipt = $this->goodsReceiptService->createFromPurchaseOrder(
        goodsReceiptPayload($purchaseOrder->id, $poItem->id, $product->id, $location->id, 3),
        $this->manager,
    );

    $this->goodsReceiptService->post($goodsReceipt, $this->manager);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee('Penerimaan Barang Terkait')
        ->assertSee($goodsReceipt->receipt_number)
        ->assertSee(route('inventory.goods-receipts.show', $goodsReceipt), false)
        ->assertSee('Diposting');
});

it('does not expose goods receipts from other branches on purchase order show', function () {
    ['sent' => $purchaseOrder, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this, 10);

    $sameBranchReceipt = $this->goodsReceiptService->createFromPurchaseOrder(
        goodsReceiptPayload($purchaseOrder->id, $poItem->id, $product->id, $location->id, 2),
        $this->manager,
    );

    $crossBranchReceipt = GoodsReceipt::factory()->create([
        'branch_id' => $this->otherBranch->id,
        'purchase_order_id' => $purchaseOrder->id,
        'receipt_number' => 'GR-CROSS-BRANCH-LEAK-TEST',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee($sameBranchReceipt->receipt_number)
        ->assertDontSee($crossBranchReceipt->receipt_number)
        ->assertDontSee('GR-CROSS-BRANCH-LEAK-TEST');
});

it('denies purchase order show for user without view permission', function () {
    ['sent' => $purchaseOrder] = createSentPurchaseOrderWithItem($this, 10);
    $unauthorizedUser = User::factory()->create();

    $this->actingAs($unauthorizedUser)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertForbidden();
});

it('shows empty goods receipt state on purchase order show without receipts', function () {
    ['sent' => $purchaseOrder] = createSentPurchaseOrderWithItem($this, 10);

    $this->actingAs($this->viewer)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee('Belum ada penerimaan barang untuk pesanan ini.')
        ->assertSee('Belum Diterima');
});
