<?php

/**
 * SECURITY-F4B3-CLINIC-CALENDAR-INVENTORY-REPORTS-1 — part of SECURITY-F4B, owner decision D15: inventory business dates use the
 * clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is still yesterday,
 * so stock movements and documents were dated the previous clinic day, an expired batch could
 * still leave stock, and every "days since" figure was one short.
 *
 * This file covers the default report periods.
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
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryBatchDisposalReportService;
use App\Modules\Inventory\Services\InventoryBatchMonthlyClosingPackService;
use App\Modules\Inventory\Services\InventoryReportService;
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

it('defaults a stock mutation report to the clinic month', function () {
    pinTestClock('2026-10-31 16:30:00'); // clinic 2026-11-01 00:30, UTC still October

    $filters = app(InventoryReportService::class)->prepareReportFilters(['report_type' => 'mutation', 'branch_id' => $this->branch->id]);

    expect($filters['date_from'])->toBe('2026-11-01')
        ->and($filters['date_to'])->toBe('2026-11-01');
});

it('defaults the batch disposal report to the clinic month', function () {
    pinTestClock('2026-10-31 16:30:00'); // clinic 2026-11-01 00:30, UTC still October

    $filters = app(InventoryBatchDisposalReportService::class)->prepareFilters(['branch_id' => $this->branch->id]);

    expect($filters['date_from'])->toBe('2026-11-01')
        ->and($filters['date_to'])->toBe('2026-11-01');
});

it('defaults the monthly closing pack to the clinic year and month', function () {
    pinTestClock('2026-12-31 16:30:00'); // clinic 2027-01-01 00:30, UTC still 2026

    $filters = app(InventoryBatchMonthlyClosingPackService::class)->prepareFilters(['branch_id' => $this->branch->id]);

    expect($filters['year'])->toBe(2027)
        ->and($filters['month'])->toBe(1);
});
