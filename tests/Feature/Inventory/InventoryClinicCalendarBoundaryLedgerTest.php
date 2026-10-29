<?php

/**
 * SECURITY-F4B1-CLINIC-CALENDAR-INVENTORY-LEDGER-1 — part of SECURITY-F4B, owner decision D15: inventory business dates use the
 * clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is still yesterday,
 * so stock movements and documents were dated the previous clinic day, an expired batch could
 * still leave stock, and every "days since" figure was one short.
 *
 * This file covers stock movement and document dates, the outbound expiry guard and receipt-date validation.
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
use App\Modules\Inventory\Requests\StoreReceiveStockRequest;
use App\Modules\Inventory\Services\InventoryStockService;
use App\Modules\Inventory\Services\StockOpnameService;
use App\Modules\Inventory\Support\InventoryBusinessCalendar;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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

it('reports the clinic date in the application date frame', function (string $instant, string $clinicDate) {
    pinTestClock($instant);
    $calendar = app(InventoryBusinessCalendar::class);

    expect($calendar->todayString())->toBe($clinicDate)
        ->and($calendar->today()->toDateString())->toBe($clinicDate)
        ->and($calendar->today()->format('H:i:s'))->toBe('00:00:00')
        ->and($calendar->today()->getTimezone()->getName())->toBe(config('app.timezone'));
})->with('instants');

it('dates a stock movement on the clinic date when none is given', function (string $instant, string $clinicDate) {
    pinTestClock($instant);

    $movement = app(InventoryStockService::class)->receiveStock($this->product->id, $this->location->id, 5, 1000);

    expect($movement->fresh()->movement_date->toDateString())->toBe($clinicDate);
})->with('instants');

it('keeps an explicitly entered movement date exactly as entered', function () {
    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01

    $movement = app(InventoryStockService::class)->receiveStock(
        $this->product->id, $this->location->id, 5, 1000, movementDate: '2026-10-20',
    );

    expect($movement->fresh()->movement_date->toDateString())->toBe('2026-10-20');
});

it('keeps the event timestamp as the UTC instant', function () {
    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01

    $movement = app(InventoryStockService::class)->receiveStock($this->product->id, $this->location->id, 5, 1000);

    expect($movement->fresh()->created_at->toDateTimeString())->toBe('2026-10-27 16:01:00')
        ->and($movement->fresh()->movement_date->toDateString())->toBe('2026-10-28');
});

it('refuses to issue a batch that expired on the previous clinic day', function () {
    $batch = f4bBatchWithStock('2026-10-27');

    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01

    expect(fn () => app(InventoryStockService::class)->adjustOut(
        $this->product->id, $this->location->id, 1, 'pemakaian', $batch->id,
    ))->toThrow(ValidationException::class, 'kedaluwarsa');
});

it('still issues a batch on its own expiry date before clinic midnight', function () {
    $batch = f4bBatchWithStock('2026-10-27');

    pinTestClock('2026-10-27 15:59:00'); // clinic 2026-10-27 23:59

    $movement = app(InventoryStockService::class)->adjustOut(
        $this->product->id, $this->location->id, 1, 'pemakaian', $batch->id,
    );

    expect((float) $movement->quantity_out)->toBe(1.0)
        ->and($movement->movement_date->toDateString())->toBe('2026-10-27');
});

it('accepts a received date on the clinic date and refuses the next one', function () {
    pinTestClock('2026-10-27 16:01:00'); // clinic 2026-10-28 00:01, UTC still the 27th
    $rules = (fn () => $this->batchInputRules())->call(new StoreReceiveStockRequest);

    $valid = fn (string $date) => Validator::make(['received_date' => $date], ['received_date' => $rules['received_date']])->passes();

    expect($valid('2026-10-28'))->toBeTrue()
        ->and($valid('2026-10-29'))->toBeFalse();
});

it('numbers an opname by the clinic month across the month boundary', function () {
    pinTestClock('2026-10-31 16:30:00'); // clinic 2026-11-01 00:30, UTC still October
    $service = app(StockOpnameService::class);

    $number = (fn () => $this->generateOpnameNumber())->call($service);

    expect($number)->toStartWith('OPN-202611-');
});
