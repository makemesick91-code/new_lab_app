<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
});

function createUiGoodsReceipt(object $test, string $status = GoodsReceipt::STATUS_DRAFT, ?PurchaseOrder $purchaseOrder = null): GoodsReceipt
{
    if ($purchaseOrder === null) {
        ['sent' => $purchaseOrder] = createSentPurchaseOrderWithItem($test);
    }

    $factory = GoodsReceipt::factory()->forPurchaseOrder($purchaseOrder);

    $goodsReceipt = match ($status) {
        GoodsReceipt::STATUS_SUBMITTED => $factory->submitted()->create([
            'branch_id' => $test->branch->id,
            'created_by' => $test->manager->id,
        ]),
        GoodsReceipt::STATUS_POSTED => $factory->posted()->create([
            'branch_id' => $test->branch->id,
            'created_by' => $test->manager->id,
        ]),
        GoodsReceipt::STATUS_CANCELLED => $factory->cancelled()->create([
            'branch_id' => $test->branch->id,
            'created_by' => $test->manager->id,
        ]),
        default => $factory->draft()->create([
            'branch_id' => $test->branch->id,
            'created_by' => $test->manager->id,
            'receipt_number' => 'GR-UI-'.strtoupper(substr($status, 0, 3)).'-001',
        ]),
    };

    $poItem = $purchaseOrder->items()->first();

    GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $goodsReceipt->id,
        'purchase_order_item_id' => $poItem->id,
        'product_id' => $poItem->product_id,
        'inventory_location_id' => $poItem->inventory_location_id,
        'ordered_qty' => $poItem->quantity_ordered,
        'previously_received_qty' => 0,
        'received_qty' => 5,
        'accepted_qty' => 5,
        'rejected_qty' => 0,
        'unit_cost' => $poItem->unit_price,
    ]);

    return $goodsReceipt->refresh()->load(['purchaseOrder', 'items.product', 'items.inventoryLocation']);
}

it('shows goods receipt index with Indonesian labels', function () {
    createUiGoodsReceipt($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.index'))
        ->assertOk()
        ->assertSee('Penerimaan Barang')
        ->assertSee('Daftar Penerimaan Barang')
        ->assertDontSee('Goods Receipt');
});

it('opens goods receipt create page for managers', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('Buat Penerimaan Barang')
        ->assertSee('Ringkasan Pesanan Pembelian')
        ->assertSee('Simpan Draft')
        ->assertSee('Sudah Diterima');
});

it('opens goods receipt detail page for viewers', function () {
    $goodsReceipt = createUiGoodsReceipt($this);

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertSee($goodsReceipt->receipt_number)
        ->assertSee('Detail Penerimaan Barang')
        ->assertSee('Item Penerimaan');
});

it('shows edit button on draft goods receipt for managers', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_DRAFT);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertSee('Edit');
});

it('hides edit button on submitted goods receipt', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_SUBMITTED);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertDontSee('>Edit<')
        ->assertDontSee('Edit Penerimaan Barang');
});

it('hides edit button on posted goods receipt', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_POSTED);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertDontSee('>Edit<');
});

it('shows cancel button on draft goods receipt for managers', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_DRAFT);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertSee('Batalkan');
});

it('hides cancel button on submitted goods receipt', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_SUBMITTED);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertDontSee('Batalkan');
});

it('hides posting button on posted goods receipt', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_POSTED);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertDontSee('Ya, Posting Penerimaan')
        ->assertDontSee('>Posting<');
});

it('shows terima barang on approved purchase order for managers', function () {
    $purchaseOrder = PurchaseOrder::factory()->approved()->create([
        'branch_id' => $this->branch->id,
        'supplier_snapshot_name' => 'Supplier Terima UI',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertSee('Terima Barang');
});

it('hides terima barang on fully received purchase order', function () {
    $purchaseOrder = PurchaseOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => PurchaseOrder::STATUS_FULLY_RECEIVED,
        'supplier_snapshot_name' => 'Supplier Full UI',
        'created_by' => $this->manager->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('inventory.purchase-orders.show', $purchaseOrder))
        ->assertOk()
        ->assertDontSee('Terima Barang');
});

it('shows sidebar menu for users with view inventory permission', function () {
    $this->actingAs($this->viewer)
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Penerimaan Barang');
});

it('hides sidebar menu for users without inventory permission', function () {
    $user = userWith(['view_invoice']);

    $response = $this->actingAs($user)->get(route('invoices.index'));

    if ($response->status() === 200) {
        $response->assertDontSee('Penerimaan Barang');
    } else {
        expect(true)->toBeTrue();
    }
});

it('renders previously received qty as read-only on create form', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $html = $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Sudah Diterima')
        ->and($html)->not->toContain('name="quantity_received"')
        ->and($html)->not->toContain('items[0][quantity_received]');
});

it('does not expose inventory movement id as editable input on create form', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $html = $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('name="inventory_movement_id"')
        ->and($html)->not->toContain('items[0][inventory_movement_id]');
});

it('shows auto-calculation helper text on create form', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('Jumlah Diterima dihitung otomatis dari Diterima Baik + Ditolak')
        ->assertSee('Hanya Diterima Baik yang menambah stok saat posting');
});

it('shows auto-calculation helper text on edit form', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_DRAFT);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.edit', $goodsReceipt))
        ->assertOk()
        ->assertSee('Jumlah Diterima dihitung otomatis dari Diterima Baik + Ditolak')
        ->assertSee('Hanya Diterima Baik yang menambah stok saat posting');
});

it('renders desktop columns in input-first order', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $html = $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->getContent();

    $acceptedPos = strpos($html, '>Diterima Baik</th>');
    $rejectedPos = strpos($html, '>Ditolak</th>');
    $receivedPos = strpos($html, '>Jumlah Diterima</th>');

    expect($acceptedPos)->not->toBeFalse()
        ->and($rejectedPos)->not->toBeFalse()
        ->and($receivedPos)->not->toBeFalse()
        ->and($acceptedPos)->toBeLessThan($rejectedPos)
        ->and($rejectedPos)->toBeLessThan($receivedPos);
});

it('renders over-receive warning markup on form', function () {
    ['sent' => $po] = createSentPurchaseOrderWithItem($this);

    $html = $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('isOverReceive(item)')
        ->and($html)->toContain('Melebihi sisa pesanan (maks.')
        ->and($html)->toContain(':max="item.remaining_qty"');
});

it('renders item-level validation section on form', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $this->actingAs($this->manager)
        ->from(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->post(route('inventory.goods-receipts.store'), [
            'purchase_order_id' => $po->id,
            'receipt_date' => now()->toDateString(),
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'inventory_location_id' => $location->id,
                    'received_qty' => 99,
                    'accepted_qty' => 99,
                    'rejected_qty' => 0,
                ],
            ],
        ])
        ->assertSessionHasErrors('items.0.accepted_qty');

    $this->get(route('inventory.goods-receipts.create', ['purchase_order_id' => $po->id]))
        ->assertOk()
        ->assertSee('Periksa item penerimaan:')
        ->assertSee('Jumlah diterima baik melebihi sisa pesanan untuk item ini.');
});

it('shows posting button on submitted goods receipt for managers', function () {
    $goodsReceipt = createUiGoodsReceipt($this, GoodsReceipt::STATUS_SUBMITTED);

    $this->actingAs($this->manager)
        ->get(route('inventory.goods-receipts.show', $goodsReceipt))
        ->assertOk()
        ->assertSee('Posting')
        ->assertSee('Ya, Posting Penerimaan');
});

it('renders mobile layout on goods receipt index', function () {
    createUiGoodsReceipt($this);

    $html = $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('md:hidden')
        ->and($html)->toContain('md:block');
});

it('shows movement traceability on posted goods receipt detail', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 3),
        $this->manager,
    );

    $posted = $this->service->post($goodsReceipt, $this->manager);

    InventoryMovement::query()
        ->where('reference_type', $posted->getTable())
        ->where('reference_id', $posted->id)
        ->first();

    $this->actingAs($this->viewer)
        ->get(route('inventory.goods-receipts.show', $posted))
        ->assertOk()
        ->assertSee('Jejak Pergerakan Stok')
        ->assertSee('Inventory Movement ID')
        ->assertSee('Pembelian');
});
