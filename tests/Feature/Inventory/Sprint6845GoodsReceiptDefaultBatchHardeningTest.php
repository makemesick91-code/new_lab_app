<?php

/**
 * SPRINT-68.45 Scope A — GR header-level default batch/lot HARDENING.
 *
 * Extends the FIX-PRE-68-45 Scope E coverage with: default applied to ALL
 * batch-tracked items, item override, non-batch item, missing-default block,
 * distinct per-product batch + correct ledger batch_id, default expiry retained
 * (FEFO), posting transaction rollback (no partial movement/batch writes), and
 * branch isolation of the created batch.
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
use Illuminate\Validation\ValidationException;

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
 * @param  array<int, array{product: Product, price: float}>  $specs
 */
function s6845SentPo(object $test, array $specs, InventoryLocation $location): object
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

function s6845GrPayload(object $po, InventoryLocation $location, array $header): array
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

it('applies the GR default batch to ALL batch-tracked items', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $p2 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $p3 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = s6845SentPo($this, [
        ['product' => $p1, 'price' => 1000],
        ['product' => $p2, 'price' => 2000],
        ['product' => $p3, 'price' => 3000],
    ], $location);

    $this->post(route('inventory.goods-receipts.store'), s6845GrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'HDR-2026-777',
        'default_expiry_date' => now()->addYear()->toDateString(),
        'default_batch_received_date' => now()->toDateString(),
    ]))->assertRedirect();

    $gr = GoodsReceipt::latest('id')->firstOrFail();
    expect($gr->items)->toHaveCount(3);
    expect($gr->items->pluck('batch_number')->unique()->values()->all())->toBe(['HDR-2026-777']);
});

it('lets an item-level batch override the GR default', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $p2 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = s6845SentPo($this, [['product' => $p1, 'price' => 1000], ['product' => $p2, 'price' => 2000]], $location);

    $payload = s6845GrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'HDR-2026-777',
        'default_expiry_date' => now()->addYear()->toDateString(),
        'default_batch_received_date' => now()->toDateString(),
    ]);
    $payload['items'][0]['batch_mode'] = 'new';
    $payload['items'][0]['batch_number'] = 'ITEM-OWN-1';
    $payload['items'][0]['expiry_date'] = now()->addYear()->toDateString();
    $payload['items'][0]['batch_received_date'] = now()->toDateString();

    $this->post(route('inventory.goods-receipts.store'), $payload)->assertRedirect();

    $numbers = GoodsReceipt::latest('id')->firstOrFail()->items->pluck('batch_number')->all();
    expect($numbers)->toContain('ITEM-OWN-1');
    expect($numbers)->toContain('HDR-2026-777');
});

it('does not require a batch for a non-batch-tracked product', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $plain = Product::factory()->create(['branch_id' => $this->branch->id]);
    $po = s6845SentPo($this, [['product' => $plain, 'price' => 1000]], $location);

    $this->post(route('inventory.goods-receipts.store'), s6845GrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'HDR-2026-777',
        'default_expiry_date' => now()->addYear()->toDateString(),
    ]))->assertRedirect();

    expect(GoodsReceipt::latest('id')->firstOrFail()->items->first()->batch_number)->toBeNull();
});

it('blocks a batch-tracked item with no item batch and no usable default', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = s6845SentPo($this, [['product' => $p1, 'price' => 1000]], $location);

    $this->post(route('inventory.goods-receipts.store'), s6845GrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => '',
        'default_expiry_date' => '',
    ]))->assertSessionHasErrors();

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('creates a DISTINCT batch per product, writes the ledger batch_id, and retains the default expiry (FEFO)', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $p2 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = s6845SentPo($this, [['product' => $p1, 'price' => 1000], ['product' => $p2, 'price' => 2000]], $location);
    $expiry = now()->addYear()->toDateString();

    $this->post(route('inventory.goods-receipts.store'), s6845GrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'HDR-2026-777',
        'default_expiry_date' => $expiry,
        'default_batch_received_date' => now()->toDateString(),
    ]))->assertRedirect();

    $this->grService->post(GoodsReceipt::latest('id')->firstOrFail(), $this->manager);

    $batches = InventoryBatch::where('batch_number', 'HDR-2026-777')->get();
    expect($batches)->toHaveCount(2);
    expect($batches->pluck('product_id')->unique()->all())->toEqualCanonicalizing([$p1->id, $p2->id]);
    // FEFO — the default expiry is retained on each per-product batch.
    expect($batches->pluck('expiry_date')->map(fn ($d) => $d?->toDateString())->unique()->all())->toBe([$expiry]);

    $movements = InventoryMovement::where('movement_type', InventoryMovement::TYPE_PURCHASE)
        ->whereNotNull('inventory_batch_id')->get();
    expect($movements)->toHaveCount(2);
    expect($movements->pluck('inventory_batch_id')->unique())->toHaveCount(2);
});

it('rolls back the whole posting when one item batch collides (no partial movement/batch writes)', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $p2 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = s6845SentPo($this, [['product' => $p1, 'price' => 1000], ['product' => $p2, 'price' => 2000]], $location);

    $this->post(route('inventory.goods-receipts.store'), s6845GrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'HDR-DUP-999',
        'default_expiry_date' => now()->addYear()->toDateString(),
        'default_batch_received_date' => now()->toDateString(),
    ]))->assertRedirect();

    $gr = GoodsReceipt::latest('id')->firstOrFail();

    // Simulate a pre-existing/concurrent batch that collides for p2 → post() throws
    // on the duplicate and the outer transaction must roll back everything.
    InventoryBatch::create([
        'branch_id' => $this->branch->id,
        'product_id' => $p2->id,
        'batch_number' => 'HDR-DUP-999',
        'lot_number' => null,
        'expiry_date' => now()->addYear()->toDateString(),
        'received_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    expect(fn () => $this->grService->post($gr->fresh(), $this->manager))
        ->toThrow(ValidationException::class);

    // No purchase movements persisted, and p1's batch was NOT created (only the
    // single pre-seeded p2 collision batch remains).
    expect(InventoryMovement::where('movement_type', InventoryMovement::TYPE_PURCHASE)->count())->toBe(0);
    expect(InventoryBatch::where('batch_number', 'HDR-DUP-999')->count())->toBe(1);
});

it('scopes the created default batch to the acting branch (branch isolation)', function () {
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $p1 = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = s6845SentPo($this, [['product' => $p1, 'price' => 1000]], $location);

    $this->post(route('inventory.goods-receipts.store'), s6845GrPayload($po, $location, [
        'apply_default_batch_to_all' => '1',
        'default_batch_number' => 'HDR-BR-001',
        'default_expiry_date' => now()->addYear()->toDateString(),
        'default_batch_received_date' => now()->toDateString(),
    ]))->assertRedirect();

    $this->grService->post(GoodsReceipt::latest('id')->firstOrFail(), $this->manager);

    $batch = InventoryBatch::where('batch_number', 'HDR-BR-001')->firstOrFail();
    expect($batch->branch_id)->toBe($this->branch->id);
});
