<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\AutoBatchNumberService;
use App\Modules\Inventory\Services\BatchExpiryStatusService;
use App\Modules\Inventory\Services\BatchStockOptionService;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Carbon::setTestNow('2026-07-03 10:00:00');

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'T6840', 'name' => 'Branch 6840 Other']);
    $this->viewer = userWith(['view_inventory']);
    $this->actingAs($this->viewer);

    $this->expiryStatus = app(BatchExpiryStatusService::class);
    $this->alertService = app(InventoryAlertService::class);
    $this->stockService = app(InventoryStockService::class);
    $this->batchOptions = app(BatchStockOptionService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sprint6840BatchWithStock(
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

it('classifies expired batch in expiry status service', function () {
    expect($this->expiryStatus->status(now()->subDay()))
        ->toBe(BatchExpiryStatusService::STATUS_EXPIRED)
        ->and($this->expiryStatus->label(now()->subDay()))->toBe('Kedaluwarsa');
});

it('classifies near-expiry batch within 90 days in expiry status service', function () {
    expect($this->expiryStatus->status(now()->addDays(45)))
        ->toBe(BatchExpiryStatusService::STATUS_NEAR_EXPIRY)
        ->and($this->expiryStatus->label(now()->addDays(45)))->toBe('Akan Kedaluwarsa');
});

it('classifies active batch after 90 days in expiry status service', function () {
    expect($this->expiryStatus->status(now()->addDays(120)))
        ->toBe(BatchExpiryStatusService::STATUS_ACTIVE)
        ->and($this->expiryStatus->label(now()->addDays(120)))->toBe('Aktif');
});

it('classifies null expiry as no expiry in expiry status service', function () {
    expect($this->expiryStatus->status(null))
        ->toBe(BatchExpiryStatusService::STATUS_NO_EXPIRY)
        ->and($this->expiryStatus->label(null))->toBe('Tanpa Expired')
        ->and($this->expiryStatus->daysText(null))->toBe('Tanpa tanggal kedaluwarsa');
});

it('renders expiry status badge on batch index', function () {
    sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-NEAR',
        'expiry_date' => now()->addDays(20)->toDateString(),
    ]);

    $this->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('B6840-NEAR')
        ->assertSee('Akan Kedaluwarsa');
});

it('renders expiry status and days text on batch show', function () {
    $data = sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-SHOW',
        'expiry_date' => now()->subDays(3)->toDateString(),
    ]);

    $this->get(route('inventory.batches.show', $data['batch']))
        ->assertOk()
        ->assertSee('Kedaluwarsa')
        ->assertSee('Kedaluwarsa 3 hari lalu');
});

it('filters batch index by expired status only', function () {
    sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-EXPIRED-ONLY',
        'expiry_date' => now()->subDays(2)->toDateString(),
    ]);
    sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-ACTIVE-ONLY',
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $this->get(route('inventory.batches.index', ['expiry_status' => 'expired']))
        ->assertOk()
        ->assertSee('B6840-EXPIRED-ONLY')
        ->assertDontSee('B6840-ACTIVE-ONLY');
});

it('filters batch index by near expiry status only', function () {
    sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-NEAR-FILTER',
        'expiry_date' => now()->addDays(30)->toDateString(),
    ]);
    sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-FAR-FILTER',
        'expiry_date' => now()->addDays(120)->toDateString(),
    ]);

    $this->get(route('inventory.batches.index', ['expiry_status' => 'near_expiry']))
        ->assertOk()
        ->assertSee('B6840-NEAR-FILTER')
        ->assertDontSee('B6840-FAR-FILTER');
});

it('keeps auto badge visible for auto batch on index', function () {
    sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'AUTO-LIDO-20270830-001',
        'expiry_date' => '2027-08-30',
    ]);

    $this->get(route('inventory.batches.index'))
        ->assertOk()
        ->assertSee('AUTO-LIDO-20270830-001')
        ->assertSee('Auto');
});

it('shows expired batch with positive stock in inventory alerts', function () {
    $data = sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-ALERT-EXPIRED',
        'expiry_date' => now()->subDays(1)->toDateString(),
    ], 6);

    $this->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('Peringatan Kedaluwarsa Batch')
        ->assertSee('B6840-ALERT-EXPIRED')
        ->assertSee('Batch Kedaluwarsa');
});

it('shows near-expiry batch with positive stock in inventory alerts', function () {
    sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-ALERT-NEAR',
        'expiry_date' => now()->addDays(25)->toDateString(),
    ], 4);

    $this->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('B6840-ALERT-NEAR')
        ->assertSee('Akan Kedaluwarsa');
});

it('does not show zero stock expired batch in inventory alerts', function () {
    $data = sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-ZERO-STOCK',
        'expiry_date' => now()->subDays(2)->toDateString(),
    ], 5);

    InventoryMovement::factory()->adjustmentOut()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'inventory_batch_id' => $data['batch']->id,
        'quantity_in' => 0,
        'quantity_out' => 5,
    ]);

    expect($this->alertService->getBatchExpiryAlerts()->pluck('batch_number'))
        ->not->toContain('B6840-ZERO-STOCK');
});

it('respects active branch context for inventory alerts', function () {
    $local = sprint6840BatchWithStock($this->branch, [
        'batch_number' => 'B6840-BRANCH-LOCAL',
        'expiry_date' => now()->subDay()->toDateString(),
    ]);

    $otherProduct = Product::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $otherBatch = InventoryBatch::factory()->expired()->create([
        'branch_id' => $this->otherBranch->id,
        'product_id' => $otherProduct->id,
        'batch_number' => 'B6840-BRANCH-OTHER',
    ]);

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $this->otherBranch->id,
        'inventory_location_id' => $otherLocation->id,
        'product_id' => $otherProduct->id,
        'inventory_batch_id' => $otherBatch->id,
        'quantity_in' => 3,
        'quantity_out' => 0,
    ]);

    $alerts = $this->alertService->getBatchExpiryAlerts();

    expect($alerts->pluck('batch_number'))->toContain($local['batch']->batch_number)
        ->and($alerts->pluck('batch_number'))->not->toContain('B6840-BRANCH-OTHER');
});

it('shows batch expiry alert empty state when none exist', function () {
    $this->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('Tidak ada batch yang kedaluwarsa atau mendekati kedaluwarsa.');
});

it('keeps transfer batch options fefo ordered', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);

    $later = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B6840-LATER',
        'expiry_date' => '2027-12-31',
    ]);
    $earlier = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B6840-EARLIER',
        'expiry_date' => '2027-06-30',
    ]);

    foreach ([$later, $earlier] as $batch) {
        $this->stockService->receiveStock($product->id, $source->id, 5, 100, null, null, [
            'inventory_batch_id' => $batch->id,
        ]);
    }

    $orderedIds = $this->batchOptions
        ->availableForProductLocation($product->id, $this->branch->id, $source->id)
        ->pluck('batch_id')
        ->all();

    expect($orderedIds)->toBe([$earlier->id, $later->id]);
});

it('marks first fefo transfer batch option as disarankan fefo', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'AUTO-LIDO-20270830-001',
        'expiry_date' => '2027-08-30',
    ]);

    $this->stockService->receiveStock($product->id, $source->id, 12, 100, null, null, [
        'inventory_batch_id' => $batch->id,
    ]);

    $label = $this->batchOptions
        ->availableForProductLocation($product->id, $this->branch->id, $source->id)
        ->first()['label'];

    expect($label)->toContain('Disarankan FEFO');
});

it('shows expired warning text on visible transfer batch option label', function () {
    $source = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $batch = InventoryBatch::factory()->expired()->create([
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'batch_number' => 'B6840-EXPIRED-LABEL',
    ]);

    $this->stockService->receiveStock($product->id, $source->id, 3, 100, null, null, [
        'inventory_batch_id' => $batch->id,
    ]);

    $label = $this->batchOptions
        ->availableForProductLocation($product->id, $this->branch->id, $source->id)
        ->first()['label'];

    expect($label)->toContain('Kedaluwarsa');
});

it('keeps sprint 6838 auto batch regression green', function () {
    $manager = userWith(['manage_inventory', 'view_inventory']);
    $this->actingAs($manager);

    expect(class_exists(AutoBatchNumberService::class))->toBeTrue();

    $this->get(route('inventory.goods-receipts.create'))
        ->assertOk()
        ->assertSee('Buat nomor batch otomatis', false);
});

it('keeps sprint 6839 transfer batch selector regression green', function () {
    $manager = userWith(['manage_inventory', 'view_inventory']);
    $this->actingAs($manager);

    $this->get(route('inventory.stock-transfers.create'))
        ->assertOk()
        ->assertSee('Batch / Expired', false)
        ->assertSee('Pilih batch dari lokasi asal. Urutan mengikuti expired terdekat.', false);
});

it('keeps inventory report stock card tab accessible', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);

    $this->get(route('inventory.reports.index', ['tab' => 'stock-card', 'product_id' => $product->id]))
        ->assertOk()
        ->assertSee('Kartu Stok')
        ->assertSee('data-report-panel="stock-card"', false);
});
