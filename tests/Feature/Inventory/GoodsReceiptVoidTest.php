<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Repositories\InventoryMovementRepository;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'TST', 'name' => 'Test Branch']);
    $this->manager = userWith(['manage_inventory']);
    $this->viewer = userWith(['view_inventory']);
    $this->service = app(GoodsReceiptService::class);
    $this->purchaseOrderService = app(PurchaseOrderService::class);
    $this->movements = app(InventoryMovementRepository::class);
    $this->actingAs($this->manager);
});

function voidLedgerStock(object $test, int $productId, int $locationId): float
{
    return $test->movements->currentStock($test->branch->id, $productId, $locationId);
}

function createPostedGoodsReceipt(object $test, float $acceptedQty = 6): array
{
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($test, 10);

    $goodsReceipt = $test->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, $acceptedQty),
        $test->manager,
    );

    $posted = $test->service->post($goodsReceipt, $test->manager);

    return compact('po', 'poItem', 'product', 'location', 'goodsReceipt', 'posted');
}

it('cancels submitted goods receipt without ledger writes for authorized user', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);
    $beforeMovements = InventoryMovement::count();

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );
    $submitted = $this->service->submit($goodsReceipt, $this->manager);

    $cancelled = $this->service->cancel($submitted, $this->manager, 'Salah submit');

    expect($cancelled->status)->toBe(GoodsReceipt::STATUS_CANCELLED)
        ->and($cancelled->cancellation_reason)->toBe('Salah submit')
        ->and(InventoryMovement::count())->toBe($beforeMovements)
        ->and((float) $poItem->refresh()->quantity_received)->toBe(0.0);
});

it('voids posted goods receipt and creates reversal ledger movements', function () {
    $fixtures = createPostedGoodsReceipt($this, 6);
    extract($fixtures);
    $beforeStock = voidLedgerStock($this, $product->id, $location->id);

    expect($beforeStock)->toBe(6.0)
        ->and((float) $poItem->refresh()->quantity_received)->toBe(6.0);

    $voided = $this->service->void($posted, $this->manager, 'Barang salah terima');

    $item = $voided->items()->first();
    $purchaseMovement = InventoryMovement::find($item->inventory_movement_id);
    $reversalMovement = InventoryMovement::find($item->reversal_movement_id);

    expect($voided->status)->toBe(GoodsReceipt::STATUS_VOID)
        ->and($voided->voided_by)->toBe($this->manager->id)
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->cancellation_reason)->toBe('Barang salah terima')
        ->and($purchaseMovement)->not->toBeNull()
        ->and($reversalMovement)->not->toBeNull()
        ->and($reversalMovement->movement_type)->toBe(InventoryMovement::TYPE_ADJUSTMENT_OUT)
        ->and((float) $reversalMovement->quantity_out)->toBe(6.0)
        ->and($reversalMovement->reference_type)->toBe($posted->getTable())
        ->and($reversalMovement->reference_id)->toBe($posted->id)
        ->and(InventoryMovement::find($item->inventory_movement_id))->not->toBeNull();
});

it('reduces derived stock after void reversal', function () {
    $fixtures = createPostedGoodsReceipt($this, 6);
    extract($fixtures);
    $beforeStock = voidLedgerStock($this, $product->id, $location->id);

    $this->service->void($posted, $this->manager, 'Koreksi penerimaan');

    expect(voidLedgerStock($this, $product->id, $location->id))->toBe($beforeStock - 6.0);
});

it('restores purchase order quantity_received and status after void', function () {
    $fixtures = createPostedGoodsReceipt($this, 6);
    extract($fixtures);

    expect($po->refresh()->status)->toBe(PurchaseOrder::STATUS_PARTIALLY_RECEIVED)
        ->and((float) $poItem->refresh()->quantity_received)->toBe(6.0);

    $this->service->void($posted, $this->manager, 'Koreksi penerimaan');

    expect((float) $poItem->refresh()->quantity_received)->toBe(0.0)
        ->and($po->refresh()->status)->toBe(PurchaseOrder::STATUS_SENT);
});

it('prevents voiding an already void goods receipt', function () {
    $fixtures = createPostedGoodsReceipt($this, 4);
    $posted = $fixtures['posted'];

    $this->service->void($posted, $this->manager, 'Pertama');

    expect(fn () => $this->service->void($posted->refresh(), $this->manager, 'Kedua'))
        ->toThrow(ValidationException::class);
});

it('denies void for unauthorized user', function () {
    $fixtures = createPostedGoodsReceipt($this, 4);
    $posted = $fixtures['posted'];

    $this->actingAs($this->viewer)
        ->post(route('inventory.goods-receipts.void', $posted), ['reason' => 'Tidak berhak'])
        ->assertForbidden();
});

it('denies cross branch void through route', function () {
    $fixtures = createPostedGoodsReceipt($this, 4);
    $posted = $fixtures['posted'];

    $otherPo = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->otherBranch->id]);
    $otherPosted = GoodsReceipt::factory()->forPurchaseOrder($otherPo)->posted()->create([
        'branch_id' => $this->otherBranch->id,
    ]);

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.void', $otherPosted), ['reason' => 'Cross branch'])
        ->assertRedirect()
        ->assertSessionHasErrors('goods_receipt');
});

it('void route requires reason and succeeds for posted goods receipt', function () {
    $fixtures = createPostedGoodsReceipt($this, 5);
    extract($fixtures);

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.void', $posted), ['reason' => 'Salah supplier'])
        ->assertRedirect(route('inventory.goods-receipts.show', $posted))
        ->assertSessionHas('status');

    expect($posted->refresh()->status)->toBe(GoodsReceipt::STATUS_VOID)
        ->and(voidLedgerStock($this, $product->id, $location->id))->toBe(0.0);
});

it('cancel route accepts submitted goods receipt', function () {
    ['sent' => $po, 'poItem' => $poItem, 'product' => $product, 'location' => $location] = createSentPurchaseOrderWithItem($this);

    $goodsReceipt = $this->service->createFromPurchaseOrder(
        goodsReceiptPayload($po->id, $poItem->id, $product->id, $location->id, 5),
        $this->manager,
    );
    $submitted = $this->service->submit($goodsReceipt, $this->manager);

    $this->actingAs($this->manager)
        ->post(route('inventory.goods-receipts.cancel', $submitted), ['notes' => 'Batal submit'])
        ->assertRedirect(route('inventory.goods-receipts.show', $submitted))
        ->assertSessionHas('status');

    expect($submitted->refresh()->status)->toBe(GoodsReceipt::STATUS_CANCELLED);
});
