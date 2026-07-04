<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Support\SourceDocumentBatchGuard;
use App\Services\Inventory\BatchGovernanceAuditService;
use App\Services\Inventory\SourceDocumentBatchAuditService;
use App\Services\Inventory\SourceDocumentBatchBackfillService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses()->group('Inventory', 'Dq3', 'SourceDocumentBatch');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->location = InventoryLocation::factory()->create(['branch_id' => test()->branch->id]);
});

it('registers dq3 source document governance commands', function () {
    expect(Artisan::all())->toHaveKeys([
        'inventory:source-document-batch-audit',
        'inventory:backfill-source-document-batches',
    ]);
});

it('returns GO on clean source document batch data', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);

    $transfer = StockTransfer::factory()->create([
        'branch_id' => test()->branch->id,
        'source_inventory_location_id' => test()->location->id,
        'destination_inventory_location_id' => InventoryLocation::factory()->create(['branch_id' => test()->branch->id])->id,
    ]);

    StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity' => 1,
    ]);

    $report = app(SourceDocumentBatchAuditService::class)->audit();

    expect($report['summary']['decision'])->toBe('GO');
});

it('detects goods receipt item missing inventory_batch_id as WARN', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);

    $gr = GoodsReceipt::factory()->create(['branch_id' => test()->branch->id]);
    $movementId = DB::table('trx_inventory_movements')->insertGetId([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 5,
        'quantity_out' => 0,
        'reference_type' => $gr->getTable(),
        'reference_id' => $gr->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $gr->id,
        'product_id' => $product->id,
        'inventory_location_id' => test()->location->id,
        'inventory_batch_id' => null,
        'inventory_movement_id' => $movementId,
        'accepted_qty' => 5,
    ]);

    Artisan::call('inventory:source-document-batch-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['decision'])->toBe('WATCH')
        ->and(collect($payload['checks'])->firstWhere('check_id', 'DQ3-SRC-001')['status'])->toBe('WARN');
});

it('detects transfer item missing inventory_batch_id as WARN', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $transfer = StockTransfer::factory()->create([
        'branch_id' => test()->branch->id,
        'source_inventory_location_id' => test()->location->id,
        'destination_inventory_location_id' => InventoryLocation::factory()->create(['branch_id' => test()->branch->id])->id,
    ]);

    StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'inventory_batch_id' => null,
        'quantity' => 2,
    ]);

    Artisan::call('inventory:source-document-batch-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($payload['checks'])->firstWhere('check_id', 'DQ3-SRC-002')['status'])->toBe('WARN');
});

it('detects opname item missing inventory_batch_id as WARN', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $opname = StockOpname::factory()->create([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
    ]);

    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => $product->id,
        'inventory_batch_id' => null,
        'variance_quantity' => 3,
    ]);

    Artisan::call('inventory:source-document-batch-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($payload['checks'])->firstWhere('check_id', 'DQ3-SRC-003')['status'])->toBe('WARN');
});

it('supports source document batch audit json output', function () {
    $exitCode = Artisan::call('inventory:source-document-batch-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['generated_at', 'summary', 'checks', 'backfill_preview'])
        ->and(collect($payload['checks'])->pluck('check_id'))->toContain('DQ3-SRC-009');
});

it('defaults source document backfill to dry-run without mutation', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $gr = GoodsReceipt::factory()->create(['branch_id' => test()->branch->id]);
    $movementId = DB::table('trx_inventory_movements')->insertGetId([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 2,
        'quantity_out' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $item = GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $gr->id,
        'product_id' => $product->id,
        'inventory_location_id' => test()->location->id,
        'inventory_movement_id' => $movementId,
        'accepted_qty' => 2,
        'inventory_batch_id' => null,
    ]);

    Artisan::call('inventory:backfill-source-document-batches', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['mode'])->toBe('dry-run')
        ->and(GoodsReceiptItem::query()->find($item->id)?->inventory_batch_id)->toBeNull();
});

it('executes backfill linking source item from mapped movement', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $gr = GoodsReceipt::factory()->create(['branch_id' => test()->branch->id]);
    $movementId = DB::table('trx_inventory_movements')->insertGetId([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 4,
        'quantity_out' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $item = GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $gr->id,
        'product_id' => $product->id,
        'inventory_location_id' => test()->location->id,
        'inventory_movement_id' => $movementId,
        'accepted_qty' => 4,
        'inventory_batch_id' => null,
    ]);

    Artisan::call('inventory:backfill-source-document-batches', [
        '--execute' => true,
        '--source' => 'goods-receipt',
        '--item-id' => $item->id,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['linked_from_movement'])->toBe(1)
        ->and(GoodsReceiptItem::query()->find($item->id)?->inventory_batch_id)->toBe($batch->id);
});

it('is idempotent on source document backfill execute', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $gr = GoodsReceipt::factory()->create(['branch_id' => test()->branch->id]);
    $movementId = DB::table('trx_inventory_movements')->insertGetId([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 1,
        'quantity_out' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $item = GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $gr->id,
        'product_id' => $product->id,
        'inventory_location_id' => test()->location->id,
        'inventory_movement_id' => $movementId,
        'accepted_qty' => 1,
        'inventory_batch_id' => null,
    ]);

    Artisan::call('inventory:backfill-source-document-batches', ['--execute' => true, '--item-id' => $item->id, '--source' => 'goods-receipt']);
    $first = GoodsReceiptItem::query()->find($item->id)?->inventory_batch_id;

    Artisan::call('inventory:backfill-source-document-batches', ['--execute' => true, '--item-id' => $item->id, '--source' => 'goods-receipt']);
    $second = GoodsReceiptItem::query()->find($item->id)?->inventory_batch_id;

    expect($first)->toBe($batch->id)
        ->and($second)->toBe($batch->id);
});

it('skips ambiguous source item and reports it', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $transfer = StockTransfer::factory()->create([
        'branch_id' => test()->branch->id,
        'source_inventory_location_id' => test()->location->id,
        'destination_inventory_location_id' => InventoryLocation::factory()->create(['branch_id' => test()->branch->id])->id,
    ]);
    $item = StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'inventory_batch_id' => null,
        'quantity' => 1,
    ]);

    $preview = app(SourceDocumentBatchBackfillService::class)->preview([
        'source' => 'transfer',
        'item_id' => $item->id,
        'no_legacy_placeholder' => true,
    ]);

    expect($preview['ambiguous_skipped'])->toBe(1);
});

it('rejects opname finalize for batch-tracked item without inventory_batch_id', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $opname = StockOpname::factory()->create([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'status' => StockOpname::STATUS_COUNTING,
    ]);

    StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => $product->id,
        'inventory_batch_id' => null,
        'variance_quantity' => 2,
        'unit_cost' => 0,
    ]);

    expect(fn () => SourceDocumentBatchGuard::assertItem($product, null))
        ->toThrow(ValidationException::class);
});

it('allows non-batch product source item without inventory_batch_id', function () {
    $product = Product::factory()->create(['branch_id' => test()->branch->id, 'requires_batch_tracking' => false]);

    SourceDocumentBatchGuard::assertItem($product, null);

    expect(true)->toBeTrue();
});

it('dq2 audit becomes GO after dq3 backfill on clean fixture', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $gr = GoodsReceipt::factory()->create(['branch_id' => test()->branch->id]);
    $movementId = DB::table('trx_inventory_movements')->insertGetId([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'movement_type' => InventoryMovement::TYPE_PURCHASE,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 3,
        'quantity_out' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $item = GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $gr->id,
        'product_id' => $product->id,
        'inventory_location_id' => test()->location->id,
        'inventory_movement_id' => $movementId,
        'accepted_qty' => 3,
        'inventory_batch_id' => null,
    ]);

    Artisan::call('inventory:backfill-source-document-batches', [
        '--execute' => true,
        '--source' => 'goods-receipt',
        '--item-id' => $item->id,
    ]);

    $dq2 = app(BatchGovernanceAuditService::class)->audit();
    $grCheck = collect($dq2['checks'])->firstWhere('check_id', 'DQ2-BATCH-008');

    expect(GoodsReceiptItem::query()->find($item->id)?->inventory_batch_id)->toBe($batch->id)
        ->and($grCheck['status'])->toBe('PASS');
});

it('dq1 remains GO after dq3 changes on clean fixture', function () {
    Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['decision'])->toBe('GO');
});

it('movement guard still rejects batch-tracked movement without inventory_batch_id', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $repo = app(InventoryMovementRepositoryInterface::class);

    expect(fn () => $repo->create([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 1,
        'quantity_out' => 0,
        'unit_cost' => 0,
    ]))->toThrow(ValidationException::class);
});
