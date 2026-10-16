<?php

/*
| FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 (rule 186) — the five KPI
| definitions, their WITA day boundaries, branch attribution and the
| server-side branch narrowing.
|
| The clinic day D = 2026-10-10 (Asia/Makassar, UTC+8) is the UTC range
| [2026-10-09 16:00:00, 2026-10-10 16:00:00).
*/

use App\Modules\ClinicOversight\Services\OversightDashboardService;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Invoice\Models\Payment;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Support\Clinical\ClinicalClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    $this->branchA = covBranch('KPA', 'Cabang KPI A');
    $this->branchB = covBranch('KPB', 'Cabang KPI B');
    $this->viewer = covUser('Finance Klinik');
    $this->day = '2026-10-10';
});

afterEach(fn () => Carbon::setTestNow());

/**
 * REVISION-OVERSIGHT-DASHBOARD-DATE-RANGE-1 (rule 187): the service now takes
 * a ClinicalDateRange. A single date D is the one-day range D..D, so every
 * assertion below is unchanged — this suite is the single-day regression.
 */
function covBuild(?string $date = '2026-10-10', mixed $branch = null): array
{
    $range = $date === null ? null : ClinicalDateRange::singleDay($date, app(ClinicalClock::class));

    return app(OversightDashboardService::class)->build(test()->viewer, $range, $branch);
}

function covRow(array $payload, $branch): ?array
{
    return collect($payload['branches'])->firstWhere('branch_id', $branch->id);
}

it('counts distinct CHECKED-IN patients per branch and distinct people across branches', function () {
    $p1 = covPatient($this->branchA);
    $p2 = covPatient($this->branchA);

    covVisit($this->branchA, $p1, ['visit_date' => $this->day]);
    covVisit($this->branchB, $p1, ['visit_date' => $this->day]);           // same person, second branch
    covVisit($this->branchA, $p2, ['visit_date' => $this->day]);
    covVisit($this->branchA, $p2, ['visit_date' => $this->day]);           // second visit, same day

    covVisit($this->branchA, covPatient(), ['visit_date' => $this->day, 'check_in_at' => null, 'status' => ClinicVisit::STATUS_REGISTERED]);
    covVisit($this->branchA, covPatient(), ['visit_date' => $this->day, 'status' => ClinicVisit::STATUS_CANCELLED, 'cancelled_at' => '2026-10-10 02:00:00']);
    covVisit($this->branchA, covPatient(), ['visit_date' => $this->day])->delete();                    // soft-deleted
    covVisit($this->branchA, covPatient(), ['visit_date' => '2026-10-09']);                            // other day

    $payload = covBuild();

    expect(covRow($payload, $this->branchA)['patients'])->toBe(2)
        ->and(covRow($payload, $this->branchB)['patients'])->toBe(1)
        ->and($payload['totals']['patients'])->toBe(2)
        ->and($payload['totals']['patients_branch_sum'])->toBe(3);
});

it('counts a cashier batch once, falls back to the payment id for an un-batched payment, and attributes to the invoice branch', function () {
    $a1 = covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_PARTIAL, '500000');
    $a2 = covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_PAID, '50000');
    $b1 = covInvoice(covVisit($this->branchB, covPatient()), RmeInvoice::STATUS_PAID, '70000');

    covPayment($a1, '100000.00', '2026-10-09 17:00:00');            // 01:00 WITA, no batch
    covPayment($a2, '50000.00', '2026-10-10 02:00:00', 'b9f3c2a0-1d4e-4f6a-9b2c-0e1f2a3b4c5d'); // one batch across two branches (payment_batch_uuid is a PG uuid column)
    covPayment($b1, '70000.00', '2026-10-10 02:00:00', 'b9f3c2a0-1d4e-4f6a-9b2c-0e1f2a3b4c5d');
    covPayment($a1, '30000.00', '2026-10-10 15:59:59');             // 23:59:59 WITA — inside
    covPayment($a1, '20000.00', '2026-10-09 16:00:00');             // 00:00:00 WITA — inside (start inclusive)
    covPayment($a1, '444444.00', '2026-10-09 15:59:59');            // 23:59:59 WITA the day BEFORE — outside
    covPayment($a1, '999999.00', '2026-10-10 16:00:00');            // 00:00 next day — outside (end exclusive)
    covSoftDeletePayment(covPayment($a1, '888888.00', '2026-10-10 03:00:00'));  // soft-deleted — not money received

    // A payment row whose own branch_id disagrees is still attributed to its INVOICE branch.
    covPayment($a2, '10000.00', '2026-10-10 04:00:00')->forceFill(['branch_id' => $this->branchB->id])->save();

    $payload = covBuild();

    expect(covRow($payload, $this->branchA)['transactions'])->toBe(5)   // p1, bat-1, p4, p_start, p_misbranch
        ->and(covRow($payload, $this->branchB)['transactions'])->toBe(1)
        ->and($payload['totals']['transactions'])->toBe(5)             // bat-1 counted once overall
        ->and($payload['totals']['transactions_branch_sum'])->toBe(6)
        ->and(covRow($payload, $this->branchA)['revenue_cents'])->toBe(21_000_000) // 100000+50000+30000+20000+10000
        ->and(covRow($payload, $this->branchB)['revenue_cents'])->toBe(7_000_000)
        ->and($payload['totals']['revenue_cents'])->toBe(28_000_000);
});

it('never reads laboratory billing payments into revenue', function () {
    $a1 = covInvoice(covVisit($this->branchA, covPatient()), RmeInvoice::STATUS_PAID, '100000');
    covPayment($a1, '100000.00', '2026-10-10 02:00:00');

    Payment::factory()->create(['amount' => 777777, 'payment_date' => '2026-10-10']);

    expect(covBuild()['totals']['revenue_cents'])->toBe(10_000_000);
})->skip(fn () => ! class_exists(Payment::class), 'lab Payment model absent');

it('computes current active receivables from UNPAID/PARTIAL balances, undated', function () {
    $visit = fn ($branch) => covVisit($branch, covPatient(), ['visit_date' => '2025-01-01']);

    covInvoice($visit($this->branchA), RmeInvoice::STATUS_UNPAID, '300000');
    $partial = covInvoice($visit($this->branchA), RmeInvoice::STATUS_PARTIAL, '200000');
    covPayment($partial, '50000.00', '2025-01-01 03:00:00');
    $paid = covInvoice($visit($this->branchA), RmeInvoice::STATUS_PAID, '100000');
    covPayment($paid, '100000.00', '2025-01-01 03:00:00');
    covInvoice($visit($this->branchA), RmeInvoice::STATUS_VOID, '500000');
    covInvoice($visit($this->branchA), RmeInvoice::STATUS_UNPAID, '0');
    $over = covInvoice($visit($this->branchA), RmeInvoice::STATUS_PARTIAL, '100000');
    covPayment($over, '120000.00', '2025-01-01 03:00:00');
    $softPaid = covInvoice($visit($this->branchA), RmeInvoice::STATUS_PARTIAL, '100000');
    covSoftDeletePayment(covPayment($softPaid, '100000.00', '2025-01-01 03:00:00'));
    covPayment($softPaid, '40000.00', '2025-01-01 03:00:00');
    covInvoice($visit($this->branchB), RmeInvoice::STATUS_UNPAID, '80000');
    covInvoice($visit($this->branchA), RmeInvoice::STATUS_UNPAID, '90000')->delete(); // soft-deleted invoice

    $payload = covBuild();

    // 300000 + 150000 + 60000 = 510000 at A across 3 invoices; B 80000 / 1.
    expect(covRow($payload, $this->branchA)['receivable_cents'])->toBe(51_000_000)
        ->and(covRow($payload, $this->branchA)['receivable_invoices'])->toBe(3)
        ->and(covRow($payload, $this->branchB)['receivable_cents'])->toBe(8_000_000)
        ->and($payload['totals']['receivable_cents'])->toBe(59_000_000)
        ->and($payload['totals']['receivable_invoices'])->toBe(4);

    // Not date-filtered: another selected date shows the same current balance.
    expect(covBuild('2026-01-01')['totals']['receivable_cents'])->toBe(59_000_000);
});

it('counts cancellations by the cancellation instant, not the visit date or updated_at', function () {
    $cancelled = fn (array $attrs) => covVisit($this->branchA, covPatient(), array_merge([
        'status' => ClinicVisit::STATUS_CANCELLED,
    ], $attrs));

    $cancelled(['visit_date' => $this->day, 'cancelled_at' => '2026-10-10 01:00:00']);
    $cancelled(['visit_date' => '2026-10-05', 'cancelled_at' => '2026-10-10 02:00:00']);   // old visit cancelled today
    $cancelled(['visit_date' => '2026-10-13', 'cancelled_at' => '2026-10-09 16:00:00']);   // future visit, start boundary
    $cancelled(['visit_date' => $this->day, 'cancelled_at' => '2026-10-09 15:59:59']);     // cancelled the day before
    $cancelled(['visit_date' => $this->day, 'cancelled_at' => '2026-10-10 03:00:00'])->delete();
    covVisit($this->branchB, covPatient(), ['status' => ClinicVisit::STATUS_CANCELLED, 'visit_date' => $this->day, 'cancelled_at' => '2026-10-10 16:00:00']);
    covVisit($this->branchA, covPatient(), ['visit_date' => $this->day, 'check_in_at' => null, 'status' => ClinicVisit::STATUS_REGISTERED]); // a no-show is not a cancellation

    $payload = covBuild();

    expect(covRow($payload, $this->branchA)['cancellations'])->toBe(3)
        ->and(covRow($payload, $this->branchB)['cancellations'])->toBe(0)
        ->and($payload['totals']['cancellations'])->toBe(3);
});

it('defaults to the clinical (WITA) today, not the UTC date', function () {
    // 2026-10-10 17:00 UTC is already 2026-10-11 01:00 in Makassar.
    Carbon::setTestNow('2026-10-10 17:00:00');

    expect(covBuild(null)['start_date'])->toBe('2026-10-11')
        ->and(covBuild(null)['end_date'])->toBe('2026-10-11')
        ->and(covBuild(null)['is_today'])->toBeTrue();
});

it('lets a branch filter only narrow, and fails closed on a forged branch id', function () {
    $main = covBranch('MAIN', 'Kantor Pusat', rme: true);
    $inactive = covBranch('OFF1', 'Cabang Nonaktif', active: false);
    $nonRme = covBranch('NRM1', 'Cabang Non RME', rme: false);

    $all = covBuild();
    $ids = collect($all['branches'])->pluck('branch_id')->all();
    expect($ids)->toContain($this->branchA->id, $this->branchB->id)
        ->not->toContain($main->id, $inactive->id, $nonRme->id);

    $narrowed = covBuild(null, (string) $this->branchA->id);
    expect(collect($narrowed['branches'])->pluck('branch_id')->all())->toBe([$this->branchA->id])
        ->and($narrowed['selected_branch_id'])->toBe($this->branchA->id);

    foreach ([$main->id, $inactive->id, $nonRme->id, 999999, 'abc'] as $forged) {
        $payload = covBuild(null, (string) $forged);
        expect($payload['branches'])->toBe([])
            ->and($payload['branch_filter_rejected'])->toBeTrue()
            ->and($payload['totals']['revenue_cents'])->toBe(0);
    }
});

it('answers every KPI with a bounded number of grouped queries, independent of branch count', function () {
    foreach (range(1, 6) as $i) {
        covBranch('QB'.$i);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    covBuild();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 5 grouped KPI queries + 2 distinct totals + the branch list + permission lookups.
    expect($queries)->toBeLessThanOrEqual(14);
});

it('serves the polling feed as JSON to an oversight role and refuses other roles', function () {
    covActingAs($this->viewer);
    // The legacy single `date` parameter still means that one day (rule 187).
    $this->getJson(route('oversight.dashboard.metrics', ['date' => $this->day]))
        ->assertOk()
        ->assertJsonPath('start_date', $this->day)
        ->assertJsonPath('end_date', $this->day)
        ->assertJsonPath('metric_version', 2)
        ->assertHeader('Cache-Control', 'no-store, private');

    covActingAs(covUser('Kasir'));
    $this->getJson(route('oversight.dashboard.metrics'))->assertForbidden();
});
