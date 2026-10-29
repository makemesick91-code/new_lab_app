<?php

/**
 * SECURITY-F4B4-CLINIC-CALENDAR-INVENTORY-ANALYTICS-1 — part of SECURITY-F4B, owner decision D15: inventory business dates use the
 * clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is still yesterday,
 * so stock movements and documents were dated the previous clinic day, an expired batch could
 * still leave stock, and every "days since" figure was one short.
 *
 * This file covers analytics windows and days-since figures.
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
use App\Modules\Inventory\Services\InventoryAnalyticsService;
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

it('counts days since the last outbound movement on the clinic calendar', function () {
    $batch = f4bBatchWithStock('2027-12-31');
    InventoryMovement::factory()->create([
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $this->location->id,
        'product_id' => $this->product->id,
        'inventory_batch_id' => $batch->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'movement_date' => '2026-10-20',
        'quantity_in' => 0,
        'quantity_out' => 1,
    ]);

    // Clinic 2026-10-28 00:01. A 7-day dead-stock window starts 2026-10-21 on the clinic
    // calendar, so the last outbound movement (2026-10-20) is 8 days old and qualifies. On the
    // UTC calendar the window would start 2026-10-20 and the product would not be listed at all.
    pinTestClock('2026-10-27 16:01:00');

    $rows = app(InventoryAnalyticsService::class)->getDeadStockProducts($this->branch->id, ['dead_stock_days' => 7]);
    $row = $rows->firstWhere('product_id', $this->product->id);

    expect($row)->not->toBeNull()
        ->and($row['days_since_last_out'])->toBe(8);
});
