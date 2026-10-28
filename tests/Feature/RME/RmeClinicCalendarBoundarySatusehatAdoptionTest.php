<?php

/**
 * SECURITY-F4A3-CLINIC-CALENDAR-SATUSEHAT-ADOPTION-1 — part of SECURITY-F4A (split by module).
 * The clinic calendar (Asia/Makassar, UTC+8), never the UTC one.
 *
 * The application timezone is UTC. Between 00:00 and 08:00 WITA the UTC date is
 * still yesterday, so "today" counts, receivable aging, due follow-ups and
 * date defaults named the previous clinic day for eight hours of every day.
 *
 * Every case freezes the clock at a UTC instant chosen so that the UTC date and
 * the clinic date differ (or, for the control, agree):
 *
 *   2026-10-27 15:59 UTC = 2026-10-27 23:59 WITA  (same date)
 *   2026-10-27 16:01 UTC = 2026-10-28 00:01 WITA  (UTC is still the 27th)
 *   2026-10-27 23:59 UTC = 2026-10-28 07:59 WITA  (UTC is still the 27th)
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Satusehat\Services\SatusehatDiagnosisAdoptionService;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->cashier = userWith(['manage_rme_billing']);
});

afterEach(function () {
    freeTestClock();
});
it('ends the default diagnosis adoption period on the clinic date', function () {
    pinTestClock('2026-10-27 16:30:00'); // clinic 2026-10-28 00:30

    $metrics = app(SatusehatDiagnosisAdoptionService::class)->metrics([]);

    expect($metrics['period']['to'])->toBe('2026-10-28');
});
