<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Services\Inventory\BatchGovernanceAuditService;
use App\Services\Inventory\MissingBatchBackfillService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses()->group('Inventory', 'Dq2', 'BatchGovernance');

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->location = InventoryLocation::factory()->create(['branch_id' => test()->branch->id]);
});

function dq2BatchTrackedMovementWithoutBatch(int $branchId, int $locationId, int $productId): int
{
    return (int) DB::table('trx_inventory_movements')->insertGetId([
        'branch_id' => $branchId,
        'inventory_location_id' => $locationId,
        'product_id' => $productId,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 3,
        'quantity_out' => 0,
        'unit_cost' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('registers dq2 inventory governance commands', function () {
    expect(Artisan::all())->toHaveKeys([
        'inventory:batch-governance-audit',
        'inventory:backfill-missing-batches',
    ]);
});

it('returns GO on clean batch-tracked movement data', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);

    DB::table('trx_inventory_movements')->insert([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 2,
        'quantity_out' => 0,
        'unit_cost' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(BatchGovernanceAuditService::class)->audit();

    expect($report['summary']['decision'])->toBe('GO')
        ->and(collect($report['checks'])->firstWhere('check_id', 'DQ2-BATCH-002')['status'])->toBe('PASS');
});

it('detects missing inventory_batch_id as WARN', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    dq2BatchTrackedMovementWithoutBatch(test()->branch->id, test()->location->id, $product->id);

    Artisan::call('inventory:batch-governance-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['summary']['decision'])->toBe('WATCH')
        ->and(collect($payload['checks'])->firstWhere('check_id', 'DQ2-BATCH-002')['status'])->toBe('WARN')
        ->and($payload['summary']['missing_inventory_batch_id'])->toBe(1);
});

it('supports batch governance audit json output', function () {
    $exitCode = Artisan::call('inventory:batch-governance-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checkIds = collect($payload['checks'])->pluck('check_id')->all();

    expect($exitCode)->toBe(0)
        ->and($payload)->toHaveKeys(['generated_at', 'summary', 'checks', 'backfill_preview'])
        ->and($checkIds)->toContain('DQ2-BATCH-010');
});

it('defaults backfill to dry-run without mutation', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $movementId = dq2BatchTrackedMovementWithoutBatch(test()->branch->id, test()->location->id, $product->id);

    Artisan::call('inventory:backfill-missing-batches', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['mode'])->toBe('dry-run')
        ->and(InventoryMovement::query()->find($movementId)?->inventory_batch_id)->toBeNull();
});

it('executes deterministic backfill for single batch candidate', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $movementId = dq2BatchTrackedMovementWithoutBatch(test()->branch->id, test()->location->id, $product->id);

    Artisan::call('inventory:backfill-missing-batches', [
        '--execute' => true,
        '--movement-id' => $movementId,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['linked_existing_batch'])->toBe(1)
        ->and(InventoryMovement::query()->find($movementId)?->inventory_batch_id)->toBe($batch->id);
});

it('is idempotent on execute', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $movementId = dq2BatchTrackedMovementWithoutBatch(test()->branch->id, test()->location->id, $product->id);

    Artisan::call('inventory:backfill-missing-batches', ['--execute' => true, '--movement-id' => $movementId]);
    $firstBatchId = InventoryMovement::query()->find($movementId)?->inventory_batch_id;

    Artisan::call('inventory:backfill-missing-batches', ['--execute' => true, '--movement-id' => $movementId]);
    $secondBatchId = InventoryMovement::query()->find($movementId)?->inventory_batch_id;

    expect($firstBatchId)->not->toBeNull()
        ->and($secondBatchId)->toBe($firstBatchId)
        ->and(InventoryBatch::query()->where('product_id', $product->id)->count())->toBe(1);
});

it('skips ambiguous movement when multiple batch candidates exist', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    InventoryBatch::factory()->count(2)->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $movementId = dq2BatchTrackedMovementWithoutBatch(test()->branch->id, test()->location->id, $product->id);

    $preview = app(MissingBatchBackfillService::class)->preview([
        'movement_id' => $movementId,
        'no_legacy_placeholder' => true,
    ]);

    expect($preview['ambiguous_manual'])->toBe(1);

    Artisan::call('inventory:backfill-missing-batches', [
        '--execute' => true,
        '--movement-id' => $movementId,
        '--no-legacy-placeholder' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['ambiguous_skipped'])->toBe(1)
        ->and(InventoryMovement::query()->find($movementId)?->inventory_batch_id)->toBeNull();
});

it('rejects batch-tracked movement creation without inventory_batch_id', function () {
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

it('allows non-batch product movement without inventory_batch_id', function () {
    $product = Product::factory()->create(['branch_id' => test()->branch->id, 'requires_batch_tracking' => false]);
    $repo = app(InventoryMovementRepositoryInterface::class);

    $movement = $repo->create([
        'branch_id' => test()->branch->id,
        'inventory_location_id' => test()->location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
        'movement_date' => now()->toDateString(),
        'quantity_in' => 1,
        'quantity_out' => 0,
        'unit_cost' => 0,
    ]);

    expect($movement->inventory_batch_id)->toBeNull();
});

it('clears dq1-data-006 warning after successful backfill fixture', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => test()->branch->id]);
    InventoryBatch::factory()->create([
        'branch_id' => test()->branch->id,
        'product_id' => $product->id,
    ]);
    $movementId = dq2BatchTrackedMovementWithoutBatch(test()->branch->id, test()->location->id, $product->id);

    Artisan::call('inventory:backfill-missing-batches', ['--execute' => true, '--movement-id' => $movementId]);

    Artisan::call('data-quality:dq1-audit', ['--json' => true]);
    $dq1 = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $dq1Check = collect($dq1['checks'])->firstWhere('check_id', 'DQ1-DATA-006');

    expect($dq1Check['status'])->toBe('PASS')
        ->and($dq1Check['details']['batch_missing'])->toBe(0);
});
