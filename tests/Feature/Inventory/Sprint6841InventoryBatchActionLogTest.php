<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryBatchActionType;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchActionLog;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryBatchActionLogService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Carbon::setTestNow('2026-07-03 10:00:00');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'T6841', 'name' => 'Branch 6841 Other']);
    $this->manager = userWith(['manage_inventory_batch_lot', 'view_stock_alert']);
    $this->viewer = userWith(['view_inventory_batch_lot']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sprint6841BatchWithStock(
    Branch $branch,
    array $batchOverrides = [],
    float $quantity = 10,
): array {
    $product = $batchOverrides['product'] ?? Product::factory()->create(['branch_id' => $branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);

    unset($batchOverrides['product']);

    $batch = InventoryBatch::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'expiry_date' => now()->subDays(5)->toDateString(),
    ], $batchOverrides));

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity_in' => $quantity,
        'quantity_out' => 0,
    ]);

    return compact('batch', 'product', 'location');
}

it('allows authorized inventory user to create action log for branch batch', function () {
    $data = sprint6841BatchWithStock($this->branch, ['batch_number' => 'B6841-ACTION']);
    $this->actingAs($this->manager);

    $movementCountBefore = InventoryMovement::query()->count();

    $this->post(route('inventory.batches.action-logs.store', $data['batch']), [
        'action_type' => InventoryBatchActionType::QUARANTINE,
        'note' => 'Batch dikarantina menunggu inspeksi.',
    ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $this->assertDatabaseHas('trx_inventory_batch_action_logs', [
        'branch_id' => $this->branch->id,
        'inventory_batch_id' => $data['batch']->id,
        'action_type' => InventoryBatchActionType::QUARANTINE,
        'note' => 'Batch dikarantina menunggu inspeksi.',
        'acted_by' => $this->manager->id,
    ]);

    expect(InventoryMovement::query()->count())->toBe($movementCountBefore);
});

it('uses branch context not request branch_id when creating action log', function () {
    $data = sprint6841BatchWithStock($this->branch, ['batch_number' => 'B6841-CTX']);
    $this->actingAs($this->manager);

    $this->post(route('inventory.batches.action-logs.store', $data['batch']), [
        'action_type' => InventoryBatchActionType::NOTE,
        'note' => 'Catatan cabang aktif.',
        'branch_id' => $this->otherBranch->id,
    ])->assertRedirect();

    $log = InventoryBatchActionLog::query()->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->branch_id)->toBe($this->branch->id)
        ->and($log->branch_id)->not->toBe($this->otherBranch->id);
});

it('denies unauthorized user from creating action log', function () {
    $data = sprint6841BatchWithStock($this->branch);
    $this->actingAs($this->viewer);

    $this->post(route('inventory.batches.action-logs.store', $data['batch']), [
        'action_type' => InventoryBatchActionType::USE_SOON,
    ])->assertForbidden();

    $this->assertDatabaseCount('trx_inventory_batch_action_logs', 0);
});

it('denies action log for batch in another branch', function () {
    $data = sprint6841BatchWithStock($this->otherBranch, ['batch_number' => 'B6841-OTHER']);
    $this->actingAs($this->manager);

    $this->post(route('inventory.batches.action-logs.store', $data['batch']), [
        'action_type' => InventoryBatchActionType::DISPOSAL_PLANNED,
    ])->assertForbidden();
});

it('does not change ledger stock when creating action log', function () {
    $data = sprint6841BatchWithStock($this->branch, [], 25);
    $this->actingAs($this->manager);

    $stockBefore = app(InventoryStockService::class)->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    app(InventoryBatchActionLogService::class)->record(
        $data['batch'],
        InventoryBatchActionType::RETURN_SUPPLIER,
        'Rencana retur supplier minggu depan.',
        $this->manager,
    );

    $stockAfter = app(InventoryStockService::class)->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    expect($stockAfter)->toBe($stockBefore)->toBe(25.0);
});

it('shows latest action on batch show page', function () {
    $data = sprint6841BatchWithStock($this->branch, ['batch_number' => 'B6841-SHOW']);
    $this->actingAs($this->manager);

    app(InventoryBatchActionLogService::class)->record(
        $data['batch'],
        InventoryBatchActionType::USE_SOON,
        null,
        $this->manager,
    );

    $this->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('Tindakan terakhir')
        ->assertSee('Perlu Digunakan Segera')
        ->assertSee('Catat Tindakan')
        ->assertSee('Riwayat Tindakan Operasional');
});

it('shows latest action on inventory alerts expiry section', function () {
    $data = sprint6841BatchWithStock($this->branch, ['batch_number' => 'B6841-ALERT']);
    $this->actingAs($this->manager);

    app(InventoryBatchActionLogService::class)->record(
        $data['batch'],
        InventoryBatchActionType::DISPOSAL_PLANNED,
        'Menunggu jadwal pemusnahan.',
        $this->manager,
    );

    $this->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('B6841-ALERT')
        ->assertSee('Rencana Pemusnahan')
        ->assertSee('Catat Tindakan');
});

it('rejects invalid action_type', function () {
    $data = sprint6841BatchWithStock($this->branch);
    $this->actingAs($this->manager);

    $this->post(route('inventory.batches.action-logs.store', $data['batch']), [
        'action_type' => 'invalid_action',
        'note' => 'Should fail',
    ])->assertSessionHasErrors('action_type');
});

it('registers inventory batches action log store route', function () {
    expect(Route::has('inventory.batches.action-logs.store'))->toBeTrue();
});
