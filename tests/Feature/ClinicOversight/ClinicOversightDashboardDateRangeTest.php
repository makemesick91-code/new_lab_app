<?php

/*
| REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 (rule 187) — the Finance & Data
| Analyst dashboard over an INCLUSIVE clinic-date range.
|
| The example range R = 2026-10-12 .. 2026-10-30 (Asia/Makassar, UTC+8, no DST)
| is the half-open UTC range [2026-10-11 16:00:00, 2026-10-30 16:00:00).
|
|   2026-10-11 16:00:00 UTC = 2026-10-12 00:00:00 WITA  -> first instant, IN
|   2026-10-11 15:59:59 UTC = 2026-10-11 23:59:59 WITA  -> day before,   OUT
|   2026-10-30 15:59:59 UTC = 2026-10-30 23:59:59 WITA  -> last second,  IN
|   2026-10-30 16:00:00 UTC = 2026-10-31 00:00:00 WITA  -> end exclusive, OUT
|
| Helpers are prefixed `covdr` so they never collide with the single-day
| suite's global `covBuild`/`covRow`.
*/

use App\Modules\ClinicOversight\Requests\OversightDashboardFilterRequest;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Services\OversightDashboardService;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Invoice\Models\Payment;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Support\Clinical\ClinicalClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->branchA = covBranch('DRA', 'Cabang Range A');
    $this->branchB = covBranch('DRB', 'Cabang Range B');
    $this->viewer = covUser('Finance Klinik');
    $this->start = '2026-10-12';
    $this->end = '2026-10-30';
});

afterEach(fn () => Carbon::setTestNow());

function covdrRange(string $start, string $end): ClinicalDateRange
{
    $range = ClinicalDateRange::tryFrom($start, $end, app(ClinicalClock::class));
    expect($range)->not->toBeNull("range {$start}..{$end} should be valid");

    return $range;
}

function covdrBuild(?string $start = '2026-10-12', ?string $end = '2026-10-30', mixed $branch = null): array
{
    $range = $start === null ? null : covdrRange($start, $end);

    return app(OversightDashboardService::class)->build(test()->viewer, $range, $branch);
}

function covdrRow(array $payload, $branch): ?array
{
    return collect($payload['branches'])->firstWhere('branch_id', $branch->id);
}

/* -------------------------------------------------------------------------
 | The value object: inclusive calendar range, exclusive UTC upper bound.
 * ---------------------------------------------------------------------- */

it('converts an inclusive WITA date range into a half-open UTC instant range', function () {
    $range = covdrRange('2026-10-12', '2026-10-30');

    expect($range->startDate)->toBe('2026-10-12')
        ->and($range->endDate)->toBe('2026-10-30')
        ->and($range->dayCount)->toBe(19)
        ->and($range->startUtcString())->toBe('2026-10-11 16:00:00')
        ->and($range->endExclusiveUtcString())->toBe('2026-10-30 16:00:00')
        ->and($range->endExclusiveDate())->toBe('2026-10-31')
        ->and($range->isSingleDay())->toBeFalse();
});

it('treats start == end as exactly one clinic day, identical to the single-day window', function () {
    $range = covdrRange('2026-10-10', '2026-10-10');

    expect($range->dayCount)->toBe(1)
        ->and($range->isSingleDay())->toBeTrue()
        ->and($range->startUtcString())->toBe('2026-10-09 16:00:00')
        ->and($range->endExclusiveUtcString())->toBe('2026-10-10 16:00:00')
        ->and($range->endExclusiveDate())->toBe('2026-10-11');
});

it('rolls month, year and leap-day boundaries correctly', function () {
    expect(covdrRange('2026-12-31', '2026-12-31')->endExclusiveDate())->toBe('2027-01-01')
        ->and(covdrRange('2026-12-31', '2026-12-31')->endExclusiveUtcString())->toBe('2026-12-31 16:00:00')
        ->and(covdrRange('2028-02-28', '2028-02-29')->dayCount)->toBe(2)
        ->and(covdrRange('2028-02-28', '2028-02-29')->endExclusiveDate())->toBe('2028-03-01')
        ->and(covdrRange('2026-01-31', '2026-03-01')->dayCount)->toBe(30);
});

it('never swaps a reversed range and refuses malformed, out-of-bound or oversized ranges', function () {
    $clock = app(ClinicalClock::class);

    foreach ([
        ['2026-10-30', '2026-10-12'],   // reversed — refused, never exchanged
        ['2026-02-30', '2026-03-01'],   // not a calendar date
        ['2026-10-1', '2026-10-30'],
        ['12/10/2026', '30/10/2026'],
        ['2026-10-12 00:00:00', '2026-10-30'],
        ['1999-12-31', '2000-01-05'],   // below the calendar floor
        ['2099-12-30', '2100-01-01'],   // above the calendar ceiling
        ['0000-01-01', '0000-01-02'],
        ['2025-01-01', '2026-01-02'],   // 367 days
        [null, '2026-10-30'],
        ['2026-10-12', null],
    ] as [$start, $end]) {
        expect(ClinicalDateRange::tryFrom($start, $end, $clock))->toBeNull("{$start}..{$end}");
    }

    // 366 inclusive days is the documented maximum — accepted.
    expect(ClinicalDateRange::tryFrom('2025-01-01', '2026-01-01', $clock)?->dayCount)->toBe(ClinicalDateRange::MAX_DAYS)
        ->and(ClinicalDateRange::MAX_DAYS)->toBe(366);
});

/* -------------------------------------------------------------------------
 | Default, reset and the single compatibility rule.
 * ---------------------------------------------------------------------- */

it('defaults to the clinical (WITA) today for both dates, not the UTC date', function () {
    // 2026-10-10 17:00 UTC is already 2026-10-11 01:00 in Makassar.
    Carbon::setTestNow('2026-10-10 17:00:00');
    covActingAs($this->viewer);

    $this->getJson(route('oversight.dashboard.metrics'))
        ->assertOk()
        ->assertJsonPath('start_date', '2026-10-11')
        ->assertJsonPath('end_date', '2026-10-11')
        ->assertJsonPath('day_count', 1)
        ->assertJsonPath('is_today', true)
        ->assertJsonPath('includes_today', true)
        ->assertJsonPath('metric_version', 2);

    expect(covdrBuild(null)['start_date'])->toBe('2026-10-11');
});

it('keeps the legacy single `date` parameter meaning one day, and refuses mixing it with a range', function () {
    covActingAs($this->viewer);

    $this->getJson(route('oversight.dashboard.metrics', ['date' => '2026-10-15']))
        ->assertOk()
        ->assertJsonPath('start_date', '2026-10-15')
        ->assertJsonPath('end_date', '2026-10-15');

    $this->getJson(route('oversight.dashboard.metrics', ['date' => '2026-10-15', 'start_date' => $this->start, 'end_date' => $this->end]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['date']);
});

it('rejects a range with only one of its two dates instead of guessing the other', function () {
    covActingAs($this->viewer);

    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => $this->start]))
        ->assertStatus(422)->assertJsonValidationErrors(['end_date']);

    $this->getJson(route('oversight.dashboard.metrics', ['end_date' => $this->end]))
        ->assertStatus(422)->assertJsonValidationErrors(['start_date']);
});

it('rejects a reversed range server-side without exchanging the dates', function () {
    covActingAs($this->viewer);

    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => '2026-10-30', 'end_date' => '2026-10-12']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);
});

it('rejects malformed and out-of-bound date input', function () {
    covActingAs($this->viewer);

    foreach ([
        ['start_date' => '2026-02-30', 'end_date' => '2026-03-01'],
        ['start_date' => '12/10/2026', 'end_date' => '2026-10-30'],
        ['start_date' => '2026-10-12', 'end_date' => '2026-10-30T23:59'],
        ['start_date' => "2026-10-12' OR '1'='1", 'end_date' => '2026-10-30'],
        ['start_date' => ['2026-10-12'], 'end_date' => '2026-10-30'],
        ['start_date' => '0000-01-01', 'end_date' => '0000-01-02'],
        ['start_date' => '1999-12-31', 'end_date' => '2000-01-02'],
        ['start_date' => '2099-12-30', 'end_date' => '2100-01-01'],
    ] as $query) {
        $this->getJson(route('oversight.dashboard.metrics', $query))
            ->assertStatus(422);
    }

    // The calendar bounds are a REQUEST rule with a field-specific message,
    // not only the value object's fail-closed backstop.
    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => '1999-12-31', 'end_date' => '2000-01-02']))
        ->assertJsonValidationErrors(['start_date' => 'antara 01/01/2000 dan 31/12/2099']);
    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => '2099-12-30', 'end_date' => '2100-01-01']))
        ->assertJsonValidationErrors(['end_date' => 'antara 01/01/2000 dan 31/12/2099']);
    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => ['2026-10-12'], 'end_date' => '2026-10-30']))
        ->assertJsonValidationErrors(['start_date' => 'YYYY-MM-DD']);
});

it('ignores the list-page filters it does not use, so a malformed one can never 500 the dashboard', function () {
    covActingAs($this->viewer);

    // `visit_date_to` + an ARRAY `visit_date_from` reaches a field-reference
    // date rule on the shared list request; the dashboard does not validate
    // or carry those keys at all.
    $this->getJson(route('oversight.dashboard.metrics', [
        'visit_date_to' => '2026-01-01', 'visit_date_from' => ['x'],
        'start_date' => $this->start, 'end_date' => $this->end,
    ]))->assertOk()->assertJsonPath('start_date', $this->start);

    $this->get(route('oversight.dashboard', ['visit_date_to' => '2026-01-01', 'visit_date_from' => ['x'], 'status' => ['x']]))
        ->assertOk();
});

it('accepts exactly the documented maximum span and refuses one day more', function () {
    covActingAs($this->viewer);

    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => '2025-01-01', 'end_date' => '2026-01-01']))
        ->assertOk()
        ->assertJsonPath('day_count', 366);

    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => '2025-01-01', 'end_date' => '2026-01-02']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['end_date' => 'maksimal 366 hari']);
});

it('fails closed in dateRange() instead of falling back to today when the values are not a valid range', function () {
    // The request rules and the value object enforce the same invariants, so
    // this branch is unreachable over HTTP while they agree. Pin the contract
    // directly: a request that asked for something invalid never gets "today".
    $request = OversightDashboardFilterRequest::create('/oversight/dashboard', 'GET', ['start_date' => '2026-10-30', 'end_date' => '2026-10-12']);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->setValidator(Validator::make($request->all(), ['start_date' => 'nullable', 'end_date' => 'nullable']));

    expect(fn () => $request->dateRange())->toThrow(ValidationException::class);
});

it('sends an invalid filter on the page back to the dashboard with a visible error, keeping a valid branch', function () {
    covActingAs($this->viewer);

    $this->get(route('oversight.dashboard', ['start_date' => '2026-10-30', 'end_date' => '2026-10-12', 'branch_id' => $this->branchA->id]))
        ->assertRedirect(route('oversight.dashboard', ['branch_id' => $this->branchA->id]))
        ->assertSessionHasErrors('end_date');

    // A malformed branch id is never carried into the redirect.
    $this->get(route('oversight.dashboard', ['start_date' => '2026-10-12', 'branch_id' => 'x']))
        ->assertRedirect(route('oversight.dashboard'));

    $this->followingRedirects()
        ->get(route('oversight.dashboard', ['start_date' => '2026-10-30', 'end_date' => '2026-10-12']))
        ->assertOk()
        ->assertSee('Tanggal akhir tidak boleh sebelum tanggal mulai');
});

/* -------------------------------------------------------------------------
 | The five KPIs over a multi-day range.
 * ---------------------------------------------------------------------- */

it('counts DISTINCT checked-in patients over the range, inclusive of the end date, and deduplicates across branches', function () {
    $p1 = covPatient($this->branchA);
    $p2 = covPatient($this->branchA);

    covVisit($this->branchA, $p1, ['visit_date' => '2026-10-12']);   // first day
    covVisit($this->branchA, $p1, ['visit_date' => '2026-10-20']);   // same person again
    covVisit($this->branchB, $p1, ['visit_date' => '2026-10-25']);   // same person, other branch
    covVisit($this->branchA, $p2, ['visit_date' => '2026-10-30']);   // end date — inclusive
    covVisit($this->branchB, covPatient(), ['visit_date' => '2026-10-12']); // ONLY visit is on the start date — inclusive

    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-31']);                                          // day after
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-11']);                                          // day before
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15', 'check_in_at' => null, 'status' => ClinicVisit::STATUS_REGISTERED]);
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15', 'status' => ClinicVisit::STATUS_CANCELLED, 'cancelled_at' => '2026-10-15 02:00:00']);
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15'])->delete();                                 // soft-deleted

    $payload = covdrBuild();

    expect(covdrRow($payload, $this->branchA)['patients'])->toBe(2)
        ->and(covdrRow($payload, $this->branchB)['patients'])->toBe(2)
        ->and($payload['totals']['patients'])->toBe(3)              // p1 + p2 + the start-date patient
        ->and($payload['totals']['patients_branch_sum'])->toBe(4);
});

it('counts payment batches once over the range at the exact WITA midnight boundaries and sums revenue across dates', function () {
    $a1 = covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_PARTIAL, '900000');
    $a2 = covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_PAID, '50000');
    $a3 = covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_PAID, '5000');
    $b1 = covInvoice(covVisit($this->branchB, covPatient()), RmeInvoice::STATUS_PAID, '60000');

    covPayment($a1, '100000.00', '2026-10-11 16:00:00');            // 2026-10-12 00:00:00 WITA — IN (start inclusive)
    covPayment($a1, '50000.00', '2026-10-30 15:59:59');             // 2026-10-30 23:59:59 WITA — IN (end date inclusive)
    covPayment($a1, '999999.00', '2026-10-30 16:00:00');            // 2026-10-31 00:00 WITA — OUT (exclusive upper bound)
    covPayment($a1, '888888.00', '2026-10-11 15:59:59');            // 2026-10-11 23:59:59 WITA — OUT
    covSoftDeletePayment(covPayment($a1, '777777.00', '2026-10-20 03:00:00'));  // soft-deleted — not money received

    $x = 'aa000000-0000-4000-8000-000000000001';
    covPayment($a2, '40000.00', '2026-10-20 03:00:00', $x);         // one batch across two branches
    covPayment($b1, '60000.00', '2026-10-20 03:00:00', $x);

    $y = 'aa000000-0000-4000-8000-000000000002';
    covPayment($a2, '10000.00', '2026-10-25 03:00:00', $y);         // one batch, two invoices, same branch
    covPayment($a3, '5000.00', '2026-10-25 03:00:00', $y);

    $payload = covdrBuild();

    expect(covdrRow($payload, $this->branchA)['transactions'])->toBe(4)     // 100000, 50000, X, Y
        ->and(covdrRow($payload, $this->branchB)['transactions'])->toBe(1)  // X
        ->and($payload['totals']['transactions'])->toBe(4)                 // X counted once overall
        ->and($payload['totals']['transactions_branch_sum'])->toBe(5)
        ->and(covdrRow($payload, $this->branchA)['revenue_cents'])->toBe(20_500_000) // 100000+50000+40000+10000+5000
        ->and(covdrRow($payload, $this->branchB)['revenue_cents'])->toBe(6_000_000)
        ->and($payload['totals']['revenue_cents'])->toBe(26_500_000);
});

it('counts a carry-over batch once and attributes each allocation to its invoice branch by paid_at, not visit date', function () {
    // A receivable from a September visit at branch A, settled together with a
    // new visit's invoice at branch B inside the range.
    $old = covInvoice(covVisit($this->branchA, covPatient(), ['visit_date' => '2026-09-01']), RmeInvoice::STATUS_PAID, '200000');
    $new = covInvoice(covVisit($this->branchB, covPatient(), ['visit_date' => '2026-10-22']), RmeInvoice::STATUS_PAID, '150000');

    $z = 'aa000000-0000-4000-8000-000000000003';
    covPayment($old, '200000.00', '2026-10-22 03:00:00', $z);
    covPayment($new, '150000.00', '2026-10-22 03:00:00', $z);

    $payload = covdrBuild();

    expect($payload['totals']['transactions'])->toBe(1)
        ->and(covdrRow($payload, $this->branchA)['transactions'])->toBe(1)
        ->and(covdrRow($payload, $this->branchB)['transactions'])->toBe(1)
        ->and(covdrRow($payload, $this->branchA)['revenue_cents'])->toBe(20_000_000)
        ->and(covdrRow($payload, $this->branchB)['revenue_cents'])->toBe(15_000_000);
});

it('never reads laboratory billing payments into range revenue', function () {
    $a1 = covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_PAID, '100000');
    covPayment($a1, '100000.00', '2026-10-15 02:00:00');

    Payment::factory()->create(['amount' => 777777, 'payment_date' => '2026-10-15']);

    expect(covdrBuild()['totals']['revenue_cents'])->toBe(10_000_000);
})->skip(fn () => ! class_exists(Payment::class), 'lab Payment model absent');

it('keeps Piutang Aktif the CURRENT balance whatever range is selected', function () {
    $partial = covInvoice(covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15']), RmeInvoice::STATUS_PARTIAL, '300000');
    covPayment($partial, '100000.00', '2026-11-05 03:00:00');  // paid AFTER the range — still reduces today's balance
    covInvoice(covVisit($this->branchB, covPatient(), ['visit_date' => '2024-01-02']), RmeInvoice::STATUS_UNPAID, '80000'); // years before the range
    covInvoice(covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-16']), RmeInvoice::STATUS_UNPAID, '50000')->delete();

    $inRange = covdrBuild();
    $pastRange = covdrBuild('2025-01-01', '2025-01-31');
    $today = covdrBuild(null);

    expect($inRange['totals']['receivable_cents'])->toBe(28_000_000)   // 200000 + 80000
        ->and($inRange['totals']['receivable_invoices'])->toBe(2)
        ->and($pastRange['totals']['receivable_cents'])->toBe(28_000_000)
        ->and($today['totals']['receivable_cents'])->toBe(28_000_000)
        ->and($inRange['receivable_basis'])->toBe('current_balance');
});

it('counts cancellations by the cancelled_at instant over the range — never visit_date or updated_at', function () {
    $cancelled = fn (array $attrs) => covVisit($this->branchA, covPatient(), array_merge([
        'status' => ClinicVisit::STATUS_CANCELLED,
    ], $attrs));

    $cancelled(['visit_date' => '2026-10-12', 'cancelled_at' => '2026-10-11 16:00:00']);   // start boundary — IN
    $cancelled(['visit_date' => '2026-10-30', 'cancelled_at' => '2026-10-30 15:59:59']);   // end-date last second — IN
    $cancelled(['visit_date' => '2026-09-01', 'cancelled_at' => '2026-10-15 03:00:00']);   // old visit cancelled in range — IN
    $cancelled(['visit_date' => '2026-10-30', 'cancelled_at' => '2026-10-30 16:00:00']);   // 2026-10-31 00:00 WITA — OUT
    $cancelled(['visit_date' => '2026-10-15', 'cancelled_at' => '2026-11-02 03:00:00']);   // visit in range, cancelled after — OUT
    $cancelled(['visit_date' => '2026-10-15', 'cancelled_at' => '2026-10-15 03:00:00'])->delete();
    $old = $cancelled(['visit_date' => '2026-09-01', 'cancelled_at' => '2026-09-01 03:00:00']);
    DB::table('trx_clinic_visits')->where('id', $old->id)->update(['updated_at' => '2026-10-20 03:00:00']); // touched in range — OUT
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15', 'check_in_at' => null, 'status' => ClinicVisit::STATUS_REGISTERED]); // no-show

    $payload = covdrBuild();

    expect(covdrRow($payload, $this->branchA)['cancellations'])->toBe(3)
        ->and($payload['totals']['cancellations'])->toBe(3);
});

it('produces exactly the single-day figures when start equals end', function () {
    $p = covPatient($this->branchA);
    covVisit($this->branchA, $p, ['visit_date' => '2026-10-10']);
    covVisit($this->branchB, $p, ['visit_date' => '2026-10-10']);
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-11']);
    $inv = covInvoice(covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-10']), RmeInvoice::STATUS_PARTIAL, '500000');
    covPayment($inv, '100000.00', '2026-10-09 16:00:00');
    covPayment($inv, '30000.00', '2026-10-10 15:59:59');
    covPayment($inv, '99999.00', '2026-10-10 16:00:00');
    covVisit($this->branchA, covPatient(), ['status' => ClinicVisit::STATUS_CANCELLED, 'visit_date' => '2026-10-10', 'cancelled_at' => '2026-10-10 01:00:00']);

    covActingAs($this->viewer);
    $legacy = $this->getJson(route('oversight.dashboard.metrics', ['date' => '2026-10-10']))->assertOk()->json();
    $range = $this->getJson(route('oversight.dashboard.metrics', ['start_date' => '2026-10-10', 'end_date' => '2026-10-10']))->assertOk()->json();

    expect($range['totals'])->toBe($legacy['totals'])
        ->and($range['branches'])->toBe($legacy['branches'])
        ->and($range['totals']['patients'])->toBe(2)
        ->and($range['totals']['transactions'])->toBe(2)
        ->and($range['totals']['revenue_cents'])->toBe(13_000_000)
        ->and($range['totals']['cancellations'])->toBe(1)
        ->and($range['is_single_day'])->toBeTrue();
});

/* -------------------------------------------------------------------------
 | Branch scope: a range never widens authorization.
 * ---------------------------------------------------------------------- */

it('intersects the branch filter with the range and fails closed on a forged branch', function () {
    $inv = covInvoice(covVisit($this->branchB, covPatient()), RmeInvoice::STATUS_PAID, '70000');
    covPayment($inv, '70000.00', '2026-10-15 02:00:00');
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15']);

    $narrowed = covdrBuild(branch: (string) $this->branchB->id);
    expect(collect($narrowed['branches'])->pluck('branch_id')->all())->toBe([$this->branchB->id])
        ->and($narrowed['totals']['revenue_cents'])->toBe(7_000_000)
        ->and($narrowed['totals']['patients'])->toBe(1);  // the visit of the invoice, not branch A's

    $nonRme = covBranch('DRN', 'Cabang Non RME', rme: false);
    foreach ([$nonRme->id, 999999, 'abc'] as $forged) {
        $payload = covdrBuild(branch: (string) $forged);
        expect($payload['branches'])->toBe([])
            ->and($payload['branch_filter_rejected'])->toBeTrue()
            ->and($payload['totals']['revenue_cents'])->toBe(0)
            ->and($payload['totals']['patients'])->toBe(0);
    }
});

/* -------------------------------------------------------------------------
 | Authorization — unchanged by the range.
 * ---------------------------------------------------------------------- */

it('serves the range dashboard to Finance Klinik and Data Analyst', function () {
    foreach (covRoles() as $role) {
        covActingAs(covUser($role));

        $this->get(route('oversight.dashboard', ['start_date' => $this->start, 'end_date' => $this->end]))
            ->assertOk()
            ->assertSee('Tanggal Mulai')
            ->assertSee('Tanggal Akhir')
            ->assertSee('12/10/2026')
            ->assertSee('30/10/2026');

        $this->getJson(route('oversight.dashboard.metrics', ['start_date' => $this->start, 'end_date' => $this->end]))
            ->assertOk()
            ->assertJsonPath('start_date', $this->start)
            ->assertJsonPath('end_date', $this->end)
            ->assertJsonPath('day_count', 19);
    }
});

it('keeps the lab Finance role and other roles out of every dashboard route, before any date validation', function () {
    $valid = ['start_date' => $this->start, 'end_date' => $this->end];
    $invalid = ['start_date' => '2026-10-30', 'end_date' => '2026-10-12'];

    foreach (['Finance', 'Kasir', 'Doctor'] as $role) {
        covActingAs(covUser($role));
        foreach (['oversight.dashboard', 'oversight.dashboard.metrics', 'oversight.dashboard.export', 'oversight.dashboard.print'] as $name) {
            $this->get(route($name, $valid))->assertForbidden();
            // 403, never 422: an invalid range is not an oracle for a caller with no access.
            $this->getJson(route($name, $invalid))->assertForbidden();
        }
    }
});

it('refuses the metrics feed to a guest', function () {
    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => $this->start, 'end_date' => $this->end]))
        ->assertUnauthorized();
    $this->get(route('oversight.dashboard', ['start_date' => $this->start, 'end_date' => $this->end]))
        ->assertRedirect(route('login'));
});

/* -------------------------------------------------------------------------
 | Auto-refresh, reset, export, print.
 * ---------------------------------------------------------------------- */

it('builds the 60-second refresh feed URL with both dates and the branch filter', function () {
    covActingAs($this->viewer);

    $html = $this->get(route('oversight.dashboard', [
        'start_date' => $this->start, 'end_date' => $this->end, 'branch_id' => $this->branchA->id, 'junk' => 'x',
    ]))->assertOk()->getContent();

    expect(preg_match('/data-metrics-url="([^"]+)"/', $html, $m))->toBe(1);
    $url = html_entity_decode($m[1]);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    $expected = ['start_date' => $this->start, 'end_date' => $this->end, 'branch_id' => (string) $this->branchA->id];
    ksort($query);
    ksort($expected);

    // Exactly the filter — the unrelated `junk` key is not carried forward.
    expect(parse_url($url, PHP_URL_PATH))->toBe(parse_url(route('oversight.dashboard.metrics'), PHP_URL_PATH))
        ->and($query)->toBe($expected);

    // The feed itself answers for the same range.
    $this->getJson($url)->assertOk()
        ->assertJsonPath('start_date', $this->start)
        ->assertJsonPath('end_date', $this->end)
        ->assertJsonPath('selected_branch_id', $this->branchA->id);

    // The client guards against an older response overwriting a newer one.
    expect($html)->toContain('requestSeq');
});

it('resets both dates to the clinical today while keeping the selected branch', function () {
    Carbon::setTestNow('2026-10-13 02:00:00'); // 10:00 WITA
    covActingAs($this->viewer);

    $this->get(route('oversight.dashboard', ['start_date' => $this->start, 'end_date' => $this->end, 'branch_id' => $this->branchA->id]))
        ->assertOk()
        ->assertSee('href="'.route('oversight.dashboard', ['branch_id' => $this->branchA->id]).'"', false);

    $this->getJson(route('oversight.dashboard.metrics', ['branch_id' => $this->branchA->id]))
        ->assertJsonPath('start_date', '2026-10-13')
        ->assertJsonPath('end_date', '2026-10-13')
        ->assertJsonPath('selected_branch_id', $this->branchA->id);
});

it('exports the CSV for exactly the selected range and branch scope, and audits the range', function () {
    $inv = covInvoice(covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15']), RmeInvoice::STATUS_PAID, '120000');
    covPayment($inv, '120000.00', '2026-10-15 02:00:00');
    $outside = covInvoice(covVisit($this->branchA, covPatient(), ['visit_date' => '2026-11-15']), RmeInvoice::STATUS_PAID, '555000');
    covPayment($outside, '555000.00', '2026-11-15 02:00:00');

    covActingAs($this->viewer);
    $response = $this->get(route('oversight.dashboard.export', [
        'start_date' => $this->start, 'end_date' => $this->end, 'branch_id' => $this->branchA->id,
    ]))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('dashboard-finance-data-analyst-2026-10-12_sd_2026-10-30.csv');

    $lines = array_values(array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
    $header = str_getcsv($lines[0]);
    $row = str_getcsv($lines[1]);
    $total = str_getcsv($lines[2]);

    expect($header[0])->toBe('Tanggal Mulai (WITA)')
        ->and($header[1])->toBe('Tanggal Akhir (WITA)')
        ->and(count($lines))->toBe(3)                                 // header, branch A, TOTAL — branch B is out of scope
        ->and($row[0])->toBe($this->start)
        ->and($row[1])->toBe($this->end)
        ->and($row[2])->toBe('DRA')
        ->and($row[array_search('Omzet (pembayaran RME)', $header, true)])->toBe('120000.00')
        ->and($total[array_search('Omzet (pembayaran RME)', $header, true)])->toBe('120000.00');

    $audit = DB::table('sys_audit_logs')->where('action', OversightAuditService::DATA_EXPORTED)->latest('id')->first();
    $meta = json_decode((string) ($audit->new_values ?? $audit->metadata ?? '{}'), true);
    expect($meta['date_from'] ?? null)->toBe($this->start)
        ->and($meta['date_to'] ?? null)->toBe($this->end)
        ->and($meta['branch_filter'] ?? null)->toBe($this->branchA->id);
});

it('keeps the single-day export file name for a one-day range', function () {
    covActingAs($this->viewer);

    $this->get(route('oversight.dashboard.export', ['start_date' => '2026-10-10', 'end_date' => '2026-10-10']))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=dashboard-finance-data-analyst-2026-10-10.csv');
});

it('prints the selected range, its totals and a back link that keeps the range', function () {
    $inv = covInvoice(covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-15']), RmeInvoice::STATUS_PAID, '120000');
    covPayment($inv, '120000.00', '2026-10-15 02:00:00');

    covActingAs($this->viewer);
    $html = $this->get(route('oversight.dashboard.print', ['start_date' => $this->start, 'end_date' => $this->end]))
        ->assertOk()
        ->assertSee('12/10/2026')
        ->assertSee('30/10/2026')
        ->assertSee('19 hari')
        ->assertSee('Rp 120.000')
        ->assertSee('saldo saat ini')
        ->getContent();

    expect(html_entity_decode($html))->toContain(route('oversight.dashboard', ['start_date' => $this->start, 'end_date' => $this->end]));

    $audit = DB::table('sys_audit_logs')->where('action', OversightAuditService::DATA_PRINTED)->latest('id')->first();
    $meta = json_decode((string) ($audit->new_values ?? $audit->metadata ?? '{}'), true);
    expect($meta['date_from'] ?? null)->toBe($this->start)
        ->and($meta['date_to'] ?? null)->toBe($this->end);
});

/* -------------------------------------------------------------------------
 | Read-only and bounded.
 * ---------------------------------------------------------------------- */

it('answers a multi-day range with the same bounded number of grouped queries as a single day', function () {
    foreach (range(1, 6) as $i) {
        covBranch('DQ'.$i);
    }

    $count = function (?string $start, ?string $end): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        covdrBuild($start, $end);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count('2026-10-12', '2026-10-12'); // warm permission caches
    $single = $count('2026-10-12', '2026-10-12');
    $year = $count('2025-01-01', '2026-01-01');

    expect($year)->toBe($single)
        ->and($year)->toBeLessThanOrEqual(14);
});

it('writes nothing while answering a range', function () {
    covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_UNPAID, '100000');
    covActingAs($this->viewer);

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql) === 1
            && ! str_contains($query->sql, 'sessions') && ! str_contains($query->sql, 'cache')) {
            $writes[] = $query->sql;
        }
    });

    $this->get(route('oversight.dashboard', ['start_date' => $this->start, 'end_date' => $this->end]))->assertOk();
    $this->getJson(route('oversight.dashboard.metrics', ['start_date' => $this->start, 'end_date' => $this->end]))->assertOk();

    expect($writes)->toBe([]);
});
