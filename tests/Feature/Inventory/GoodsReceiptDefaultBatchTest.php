<?php

/**
 * FIX-PRE-68-45 Scope E — GR header-level default batch/lot that expands PER
 * product/item (distinct batch per product, never one shared global batch).
 * Covers: default expansion + distinct batch per product + correct ledger
 * batch_id; item-level override; missing-default validation; non-batch product
 * keeps batch empty.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\GoodsReceiptService;
use App\Modules\Inventory\Services\PurchaseOrderService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    seedAccessControl();
    test()->seed(BranchSeeder::class);
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->manager = userWith(['manage_inventory']);
    $this->poService = app(PurchaseOrderService::class);
    $this->grService = app(GoodsReceiptService::class);
    $this->actingAs($this->manager);
});

/**
 * A SENT PO for the given [product, unit_price] specs, all in MAIN branch.
 *
 * @param  array<int, array{product: Product, price: float}>  $specs
 */
function dbSentPo(object $test, array $specs, InventoryLocation $location): object
{
    $supplier = Supplier::factory()->create(['branch_id' => $test->branch->id]);

    $draft = $test->poService->createDraft([
        'order_date' => now()->toDateString(),
        'supplier_id' => $supplier->id,
        'items' => collect($specs)->map(fn ($s) => [
            'product_id' => $s['product']->id,
            'inventory_location_id' => $location->id,
            'quantity_ordered' => 10,
            'unit_price' => $s['price'],
        ])->all(),
    ], $test->manager);

    return $test->poService->markAsSent(
        $test->poService->approve($test->poService->submit($draft, $test->manager), $test->manager),
        $test->manager,
    );
}

/**
 * GR store payload: one item per PO item, batch fields left BLANK (rely on the
 * header default), plus the header default fields.
 */
function dbGrPayload(object $po, InventoryLocation $location, array $header): array
{
    $items = $po->items->map(fn ($poItem) => [
        'purchase_order_item_id' => $poItem->id,
        'product_id' => $poItem->product_id,
        'inventory_location_id' => $location->id,
        'received_qty' => 5,
        'accepted_qty' => 5,
        'rejected_qty' => 0,
    ])->all();

    return array_merge([
        'purchase_order_id' => $po->id,
        'receipt_date' => now()->toDateString(),
        'items' => $items,
    ], $header);
}

it('expands a GR-level default batch into a DISTINCT batch per product and writes the ledger batch_id', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id, 'name' => 'Batch Prod Satu']);
    $p2 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id, 'name' => 'Batch Prod Dua']);
    $po = dbSentPo($this, [['product' => $p1, 'price' => 2500], ['product' => $p2, 'price' => 3000]], $location);

    $payload = dbGrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'DEF-2026-001',
        'default_expiry_date' => now()->addYear()->toDateString(),
        'default_batch_received_date' => now()->toDateString(),
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)->assertRedirect();

    $gr = GoodsReceipt::latest('id')->firstOrFail();
    // Both items received the same default batch number.
    expect($gr->items->pluck('batch_number')->unique()->values()->all())->toBe(['DEF-2026-001']);

    // Post → distinct batch row per product (same batch_number, different product).
    $this->grService->post($gr->fresh(), $this->manager);

    $batches = InventoryBatch::query()->where('batch_number', 'DEF-2026-001')->get();
    expect($batches)->toHaveCount(2);
    expect($batches->pluck('product_id')->unique()->all())->toEqualCanonicalizing([$p1->id, $p2->id]);

    // Ledger movements carry the correct per-product batch_id.
    $movements = InventoryMovement::query()
        ->where('movement_type', InventoryMovement::TYPE_PURCHASE)
        ->whereNotNull('inventory_batch_id')
        ->get();
    expect($movements)->toHaveCount(2);
    expect($movements->pluck('inventory_batch_id')->unique())->toHaveCount(2);
});

it('lets an item-level batch override the GR-level default', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $p2 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = dbSentPo($this, [['product' => $p1, 'price' => 2500], ['product' => $p2, 'price' => 3000]], $location);

    $payload = dbGrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'DEF-2026-001',
        'default_expiry_date' => now()->addYear()->toDateString(),
        'default_batch_received_date' => now()->toDateString(),
    ]);
    // Give the FIRST item its own explicit batch — it must NOT be overwritten.
    $payload['items'][0]['batch_mode'] = 'new';
    $payload['items'][0]['batch_number'] = 'OWN-XYZ';
    $payload['items'][0]['lot_number'] = 'LOT-OWN';
    $payload['items'][0]['expiry_date'] = now()->addYear()->toDateString();
    $payload['items'][0]['batch_received_date'] = now()->toDateString();

    $this->post(route('inventory.goods-receipts.store'), $payload)->assertRedirect();

    $gr = GoodsReceipt::latest('id')->firstOrFail();
    $numbers = $gr->items->sortBy('id')->pluck('batch_number')->values()->all();
    expect($numbers)->toContain('OWN-XYZ');   // item override preserved
    expect($numbers)->toContain('DEF-2026-001'); // other item got the default
});

it('blocks a batch-tracked item that has neither its own batch nor a usable default', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = dbSentPo($this, [['product' => $p1, 'price' => 2500]], $location);

    // apply flag on, but NO default batch/expiry provided → must fail validation.
    $payload = dbGrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => '',
        'default_expiry_date' => '',
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)
        ->assertSessionHasErrors();

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('does not apply the default to a product that does not require batch tracking', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $plain = Product::factory()->create(['branch_id' => $this->branch->id]); // no batch tracking
    $po = dbSentPo($this, [['product' => $plain, 'price' => 2500]], $location);

    $payload = dbGrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'DEF-2026-001',
        'default_expiry_date' => now()->addYear()->toDateString(),
        'default_batch_received_date' => now()->toDateString(),
    ]);

    $this->post(route('inventory.goods-receipts.store'), $payload)->assertRedirect();

    $gr = GoodsReceipt::latest('id')->firstOrFail();
    // Non-batch product keeps its batch empty.
    expect($gr->items->first()->batch_number)->toBeNull();

    $this->grService->post($gr->fresh(), $this->manager);
    expect(InventoryBatch::query()->count())->toBe(0);
});
