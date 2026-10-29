<?php

/**
 * SECURITY-F4B2-CLINIC-CALENDAR-INVENTORY-EXPIRY-1 — part of SECURITY-F4B, owner decision D15: inventory business dates use the
 * clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is still yesterday,
 * so stock movements and documents were dated the previous clinic day, an expired batch could
 * still leave stock, and every "days since" figure was one short.
 *
 * This file covers expiry status, the expiry alert and the not-expired scope.
 *
 * Every case freezes the clock at a UTC instant chosen so that the UTC date and the clinic date
 * differ (or, for the control, agree):
 *
 *   2026-10-27 15:59 UTC = 2026-10-27 23:59 WITA  (same date)
 *   2026-10-27 16:01 UTC = 2026-10-28 00:01 WITA  (UTC is still the 27th)
 *   2026-10-27 23:59 UTC = 2026-10-28 07:59 WITA  (UTC is still the 27th)
 *
 * D15 also says what must NOT change: event timestamps stay UTC instants, an explicitly entered
 * business date is kept exactly as entered, and stored historical dates are never rewritten.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\BatchExpiryStatusService;
use App\Modules\Inventory\Services\InventoryAlertService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->actingAs(userWith(['view_inventory', 'manage_inventory']));
    $this->product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $this->location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
});

afterEach(function () {
    freeTestClock();
});

dataset('instants', [
    '23:59 WITA' => ['2026-10-27 15:59:00', '2026-10-27'],
    '00:01 WITA' => ['2026-10-27 16:01:00', '2026-10-28'],
    '07:59 WITA' => ['2026-10-27 23:59:00', '2026-10-28'],
]);

if (! function_exists('f4bBatchWithStock')) {
    /** A batch of $this->product in $this->location holding stock, expiring on $expiry. */
    function f4bBatchWithStock(string $expiry): InventoryBatch
    {
        $batch = InventoryBatch::factory()->create([
            'branch_id' => test()->branch->id,
            'product_id' => test()->product->id,
            'expiry_date' => $expiry,
        ]);

        InventoryMovement::factory()->purchase()->create([
            'branch_id' => test()->branch->id,
            'inventory_location_id' => test()->location->id,
            'product_id' => test()->product->id,
            'inventory_batch_id' => $batch->id,
            'movement_date' => '2026-10-01',
            'quantity_in' => 10,
            'quantity_out' => 0,
        ]);

        return $batch;
    }
}

it('classifies a batch that expired on the previous clinic day as expired after clinic midnight', function () {
    $status = app(BatchExpiryStatusService::class);

    pinTestClock('2026-10-27 15:59:00'); // clinic 2026-10-27 23:59 — expiry day itself
    expect($status->status('2026-10-27'))->toBe(BatchExpiryStatusService::STATUS_NEAR_EXPIRY)
        ->and($status->daysText('2026-10-27'))->toBe('Kedaluwarsa hari ini');

    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01, UTC still the 27th
    expect($status->status('2026-10-27'))->toBe(BatchExpiryStatusService::STATUS_EXPIRED)
        ->and($status->daysText('2026-10-27'))->toBe('Kedaluwarsa 1 hari lalu')
        ->and($status->daysText('2026-10-28'))->toBe('Kedaluwarsa hari ini');
});

it('excludes a batch expired on the previous clinic day from the not-expired scope', function () {
    $expired = f4bBatchWithStock('2026-10-27');
    $current = f4bBatchWithStock('2026-10-28');

    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01

    $ids = InventoryBatch::query()->notExpired()->pluck('id')->all();

    expect($ids)->toContain($current->id)->not->toContain($expired->id);
});

it('counts days until expiry in an alert on the clinic calendar', function () {
    $batch = f4bBatchWithStock('2026-10-28');

    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01, UTC still the 27th

    $alerts = app(InventoryAlertService::class)->getBatchExpiryAlerts(branchId: $this->branch->id);
    $alert = $alerts->firstWhere('inventory_batch_id', $batch->id);

    expect($alert)->not->toBeNull()
        ->and($alert['days_until_expiry'])->toBe(0);
});
