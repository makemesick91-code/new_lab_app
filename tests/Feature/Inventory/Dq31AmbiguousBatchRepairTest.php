<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Services\Architecture\FoundationGovernanceSummaryService;
use App\Services\Inventory\AmbiguousBatchReviewPackService;
use App\Services\Inventory\BatchGovernanceAuditService;
use App\Services\Inventory\SourceDocumentBatchAuditService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses()->group('Inventory', 'Dq31', 'AmbiguousBatch');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->location = InventoryLocation::factory()->create(['branch_id' => test()->branch->id]);
    test()->destLocation = InventoryLocation::factory()->create(['branch_id' => test()->branch->id]);
});

function createAmbiguousTransferItem(): array
{
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batchOut = InventoryBatch::factory()->create(['branch_id' => test()->branch->id, 'product_id' => $product->id]);
    $batchIn = InventoryBatch::factory()->create(['branch_id' => test()->branch->id, 'product_id' => $product->id]);

    $transfer = StockTransfer::factory()->create([
        'branch_id' => test()->branch->id,
        'source_inventory_location_id' => test()->location->id,
        'destination_inventory_location_id' => test()->destLocation->id,
    ]);

    $item = StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'inventory_batch_id' => null,
        'quantity' => 2,
    ]);

    $transferTable = $transfer->getTable();
    foreach ([
        [InventoryMovement::TYPE_TRANSFER_OUT, test()->location->id, $batchOut->id, 0, 2],
        [InventoryMovement::TYPE_TRANSFER_IN, test()->destLocation->id, $batchIn->id, 2, 0],
    ] as [$type, $locId, $batchId, $in, $out]) {
        DB::table('trx_inventory_movements')->insert([
            'branch_id' => test()->branch->id,
            'inventory_location_id' => $locId,
            'product_id' => $product->id,
            'inventory_batch_id' => $batchId,
            'movement_type' => $type,
            'movement_date' => now()->toDateString(),
            'quantity_in' => $in,
            'quantity_out' => $out,
            'reference_type' => $transferTable,
            'reference_id' => $transfer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return compact('product', 'transfer', 'item', 'batchOut', 'batchIn');
}

function createAmbiguousOpnameItem(): array
{
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batchA = InventoryBatch::factory()->create(['branch_id' => test()->branch->id, 'product_id' => $product->id]);
    $batchB = InventoryBatch::factory()->create(['branch_id' => test()->branch->id, 'product_id' => $product->id]);

    $opname = StockOpname::factory()->create([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
    ]);

    $item = StockOpnameItem::factory()->create([
        'stock_opname_id' => $opname->id,
        'product_id' => $product->id,
        'inventory_batch_id' => null,
        'variance_quantity' => 3,
    ]);

    $opnameTable = $opname->getTable();
    foreach ([$batchA, $batchB] as $batch) {
        DB::table('trx_inventory_movements')->insert([
            'branch_id' => test()->branch->id,
            'inventory_location_id' => test()->location->id,
            'product_id' => $product->id,
            'inventory_batch_id' => $batch->id,
            'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
            'movement_date' => now()->toDateString(),
            'quantity_in' => 3,
            'quantity_out' => 0,
            'reference_type' => $opnameTable,
            'reference_id' => $opname->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return compact('product', 'opname', 'item', 'batchA', 'batchB');
}

it('registers dq31 governance commands', function () {
    expect(Artisan::all())->toHaveKeys([
        'inventory:ambiguous-batch-review-pack',
        'inventory:repair-ambiguous-batch-links',
    ]);
});

it('exports transfer ambiguous row in review pack', function () {
    ['item' => $item] = createAmbiguousTransferItem();

    $report = app(AmbiguousBatchReviewPackService::class)->generate(['source' => 'transfer']);

    expect($report['summary']['transfer_ambiguous_count'])->toBe(1)
        ->and(collect($report['rows'])->firstWhere('source_item_id', $item->id))->not->toBeNull()
        ->and(collect($report['rows'])->first()['ambiguity_reason'])->toContain('OUT/IN');
});

it('exports opname ambiguous row in review pack', function () {
    ['item' => $item] = createAmbiguousOpnameItem();

    $report = app(AmbiguousBatchReviewPackService::class)->generate(['source' => 'opname']);

    expect($report['summary']['opname_ambiguous_count'])->toBe(1)
        ->and(collect($report['rows'])->firstWhere('source_item_id', $item->id))->not->toBeNull();
});

it('review pack command is read-only', function () {
    ['item' => $item] = createAmbiguousTransferItem();
    $before = StockTransferItem::query()->find($item->id)?->inventory_batch_id;

    Artisan::call('inventory:ambiguous-batch-review-pack', ['--json' => true]);
    $after = StockTransferItem::query()->find($item->id)?->inventory_batch_id;

    expect($before)->toBeNull()->and($after)->toBeNull();
});

it('repair dry-run with valid mapping does not mutate', function () {
    ['item' => $item, 'batchOut' => $batch] = createAmbiguousTransferItem();
    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval for dry-run test'),
    ]);

    Artisan::call('inventory:repair-ambiguous-batch-links', [
        '--mapping' => $path,
        '--dry-run' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['validation']['valid'])->toBeTrue()
        ->and($payload['mode'])->toBe('dry-run')
        ->and(StockTransferItem::query()->find($item->id)?->inventory_batch_id)->toBeNull();
});

it('repair execute updates transfer source item inventory_batch_id', function () {
    ['item' => $item, 'batchOut' => $batch] = createAmbiguousTransferItem();
    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval for transfer repair'),
    ]);

    Artisan::call('inventory:repair-ambiguous-batch-links', [
        '--mapping' => $path,
        '--execute' => true,
    ]);

    expect(StockTransferItem::query()->find($item->id)?->inventory_batch_id)->toBe($batch->id);
});

it('repair execute updates opname source item inventory_batch_id', function () {
    ['item' => $item, 'batchA' => $batch] = createAmbiguousOpnameItem();
    $path = writeTestMapping([
        mappingRow('opname', $item->id, $batch->id, 'Manual opname mapping owner approval'),
    ]);

    Artisan::call('inventory:repair-ambiguous-batch-links', [
        '--mapping' => $path,
        '--execute' => true,
    ]);

    expect(StockOpnameItem::query()->find($item->id)?->inventory_batch_id)->toBe($batch->id);
});

it('repair execute is idempotent', function () {
    ['item' => $item, 'batchOut' => $batch] = createAmbiguousTransferItem();
    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval idempotent test'),
    ]);

    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--execute' => true]);
    $first = StockTransferItem::query()->find($item->id)?->inventory_batch_id;

    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--execute' => true]);
    $second = StockTransferItem::query()->find($item->id)?->inventory_batch_id;

    expect($first)->toBe($batch->id)->and($second)->toBe($batch->id);
});

it('rejects missing mapping file', function () {
    $exit = Artisan::call('inventory:repair-ambiguous-batch-links', [
        '--mapping' => storage_path('app/testing/missing-dq31-mapping.csv'),
        '--dry-run' => true,
    ]);

    expect($exit)->toBe(1);
});

it('rejects missing approval fields', function () {
    ['item' => $item, 'batchOut' => $batch] = createAmbiguousTransferItem();
    $path = storage_path('app/testing/dq31-invalid-approval.csv');
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, implode("\n", [
        'source_type,source_item_id,approved_inventory_batch_id,approval_reference,approved_by,approved_at,reason',
        "transfer,{$item->id},{$batch->id},,,,",
    ]));

    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Validation FAILED');
});

it('rejects batch product mismatch', function () {
    ['item' => $item] = createAmbiguousTransferItem();
    $otherProduct = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $wrongBatch = InventoryBatch::factory()->create(['branch_id' => test()->branch->id, 'product_id' => $otherProduct->id]);

    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $wrongBatch->id, 'Manual OUT/IN owner approval mismatch test'),
    ]);

    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--dry-run' => true]);

    expect(Artisan::output())->toContain('product mismatch');
});

it('rejects unrelated non-ambiguous row', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create(['branch_id' => test()->branch->id, 'product_id' => $product->id]);
    $transfer = StockTransfer::factory()->create([
        'branch_id' => test()->branch->id,
        'source_inventory_location_id' => test()->location->id,
        'destination_inventory_location_id' => test()->destLocation->id,
    ]);
    $item = StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity' => 1,
    ]);

    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval unrelated test'),
    ]);

    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--dry-run' => true]);

    expect(Artisan::output())->toContain('not a current ambiguous row');
});

it('rejects duplicate conflicting mapping rows', function () {
    ['item' => $item, 'batchOut' => $batch, 'batchIn' => $batchIn] = createAmbiguousTransferItem();
    $path = storage_path('app/testing/dq31-duplicate.csv');
    @mkdir(dirname($path), 0775, true);
    $row = mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval duplicate test');
    file_put_contents($path, implode("\n", [
        'source_type,source_item_id,approved_inventory_batch_id,approval_reference,approved_by,approved_at,reason',
        csvRow($row),
        csvRow(array_merge($row, ['approved_inventory_batch_id' => $batchIn->id])),
    ]));

    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--dry-run' => true]);

    expect(Artisan::output())->toContain('duplicate mapping');
});

it('does not change movement quantities on repair execute', function () {
    ['item' => $item, 'batchOut' => $batch] = createAmbiguousTransferItem();
    $netBefore = (float) DB::table('trx_inventory_movements')
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as net')->value('net');

    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval movement qty test'),
    ]);
    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--execute' => true]);

    $netAfter = (float) DB::table('trx_inventory_movements')
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as net')->value('net');

    expect($netAfter)->toBe($netBefore);
});

it('post-repair dq3 audit becomes GO on clean repaired fixture', function () {
    ['item' => $item, 'batchOut' => $batch] = createAmbiguousTransferItem();
    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval dq3 go test'),
    ]);
    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--execute' => true]);

    $dq3 = app(SourceDocumentBatchAuditService::class)->audit();

    expect($dq3['summary']['decision'])->toBe('GO');
});

it('post-repair dq2 audit becomes GO on clean repaired fixture', function () {
    ['item' => $item, 'batchOut' => $batch] = createAmbiguousTransferItem();
    $path = writeTestMapping([
        mappingRow('transfer', $item->id, $batch->id, 'Manual OUT/IN owner approval dq2 go test'),
    ]);
    Artisan::call('inventory:repair-ambiguous-batch-links', ['--mapping' => $path, '--execute' => true]);

    $dq2 = app(BatchGovernanceAuditService::class)->audit();

    expect($dq2['summary']['decision'])->toBe('GO');
});

it('dq1 remains GO after dq31 repair', function () {
    Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['decision'])->toBe('GO');
});

it('foundation governance summary includes dq31 status', function () {
    $summary = app(FoundationGovernanceSummaryService::class)->collect();

    expect($summary['summary'])->toHaveKey('dq31_decision')
        ->and($summary)->toHaveKey('dq31_governance')
        ->and($summary['commands_available']['inventory:ambiguous-batch-review-pack'])->toBeTrue();
});

it('mapping template file exists', function () {
    expect(base_path('docs/templates/dq-3-1-ambiguous-batch-repair-mapping-template.csv'))->toBeFile();
});

function mappingRow(string $type, int $itemId, int $batchId, string $reason): array
{
    return [
        'source_type' => $type,
        'source_item_id' => $itemId,
        'approved_inventory_batch_id' => $batchId,
        'approval_reference' => 'DQ31-TEST-REF',
        'approved_by' => 'test.owner',
        'approved_at' => '2026-07-04T10:00:00+08:00',
        'reason' => $reason,
    ];
}

function csvRow(array $row): string
{
    return implode(',', [
        $row['source_type'],
        $row['source_item_id'],
        $row['approved_inventory_batch_id'],
        $row['approval_reference'],
        $row['approved_by'],
        $row['approved_at'],
        '"'.$row['reason'].'"',
    ]);
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function writeTestMapping(array $rows): string
{
    $path = storage_path('app/testing/dq31-mapping-'.uniqid().'.csv');
    @mkdir(dirname($path), 0775, true);
    $lines = ['source_type,source_item_id,approved_inventory_batch_id,approval_reference,approved_by,approved_at,reason'];
    foreach ($rows as $row) {
        $lines[] = csvRow($row);
    }
    file_put_contents($path, implode("\n", $lines)."\n");

    return $path;
}
