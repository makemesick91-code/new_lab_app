<?php

declare(strict_types=1);

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — RFS-1, RFS-5, RFS-6.
|
| A settled refund is money given back. Every reader that answers "how much has this
| invoice received / how much is still owed" must count it, through ONE definition:
|   net received = Σ payments − Σ SETTLED refund settlements
| The incoming payment rows themselves are never touched (RFS-5), and reporting keeps
| gross incoming, refund outflow and net apart (RFS-6).
*/

use App\Modules\ClinicOversight\Interfaces\OversightKpiRepositoryInterface;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\RmeInvoice\Interfaces\RmeRefundSettlementRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rsSetup();
});

/** Overpaid cents the way every reader computes it: grand total against NET received. */
function rsOverpaidCents(RmeInvoice $invoice): int
{
    return RmeInvoiceLedger::overpaidCents(RmeInvoiceDocument::toCents($invoice->grand_total), RmeInvoiceLedger::netReceivedCents($invoice));
}

/** One SETTLED 150.000 refund on a 500.000 invoice corrected down to 350.000. */
function rsSettledInvoice(): RmeInvoice
{
    $settlement = rsConfirm(rsStart(rsApproved()));

    return RmeInvoice::query()->findOrFail($settlement->rme_invoice_id);
}

it('RS-LED-01: settling keeps the incoming payment rows byte-identical and the invoice PAID', function () {
    $request = rsApproved();
    $before = rsLedgerFingerprint();

    rsConfirm(rsStart($request));

    $after = rsLedgerFingerprint();
    expect($after['payments'])->toEqual($before['payments'])
        ->and($after['payment_rows'])->toEqual($before['payment_rows'])
        ->and($after['items'])->toEqual($before['items'])
        ->and($after['corrections'])->toEqual($before['corrections'])
        ->and($after['invoices'])->toEqual($before['invoices']);

    $invoice = RmeInvoice::query()->findOrFail($request->rme_invoice_id);
    expect($invoice->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('RS-LED-02: RFS-1 — gross stays gross, net subtracts only SETTLED refunds, remaining never goes negative', function () {
    $invoice = rsSettledInvoice();

    expect((float) $invoice->paidAmount())->toBe(500000.0)
        ->and((float) $invoice->settledRefundAmount())->toBe(150000.0)
        ->and((float) $invoice->netReceivedAmount())->toBe(350000.0)
        ->and((float) $invoice->remainingAmount())->toBe(0.0)
        ->and(RmeInvoiceLedger::paidCents($invoice))->toBe(50000000)
        ->and(RmeInvoiceLedger::settledRefundCents($invoice))->toBe(15000000)
        ->and(RmeInvoiceLedger::netReceivedCents($invoice))->toBe(35000000)
        ->and(rsOverpaidCents($invoice))->toBe(0);
});

it('RS-LED-03: a DISBURSING, OUTCOME_UNKNOWN or ABORTED settlement does not reduce net received', function () {
    $request = rsApproved();
    $invoiceId = (int) $request->rme_invoice_id;
    $settlement = rsStart($request);

    $net = fn () => RmeInvoiceLedger::netReceivedCents(RmeInvoice::query()->findOrFail($invoiceId));
    expect($net())->toBe(50000000);

    rsUnknown($settlement);
    expect($net())->toBe(50000000);

    rsAbort($settlement, evidence: rsPdf('mutasi.pdf', 'aborted'));
    expect($net())->toBe(50000000)
        ->and(rsOverpaidCents(RmeInvoice::query()->findOrFail($invoiceId)))->toBe(15000000);
});

it('RS-LED-04: the SQL helpers agree with the model, per invoice', function () {
    $settled = rsSettledInvoice();
    $plain = rppPaidInvoice(500000);

    $rows = RmeInvoiceLedger::netReceivedTotalsQuery()->get()->keyBy('rme_invoice_id');
    expect((float) $rows[$settled->id]->paid_total)->toBe(350000.0)
        ->and((float) $rows[$plain->id]->paid_total)->toBe(500000.0);

    $sql = RmeInvoiceLedger::netReceivedSql('trx_rme_invoices.id');
    $viaSql = DB::table('trx_rme_invoices')->whereIn('id', [$settled->id, $plain->id])
        ->selectRaw('id, '.$sql.' AS net')->get()->keyBy('id');
    expect((float) $viaSql[$settled->id]->net)->toBe(350000.0)
        ->and((float) $viaSql[$plain->id]->net)->toBe(500000.0);
});

it('RS-LED-05: the SQL expression refuses anything but a code literal column', function (string $column) {
    expect(fn () => RmeInvoiceLedger::netReceivedSql($column))->toThrow(InvalidArgumentException::class);
})->with(['id', 'trx_rme_invoices.id; DROP TABLE x', 'trx_rme_invoices.id)', "trx_rme_invoices.id' --", 'TRX.ID']);

it('RS-LED-06: the aggregate scope reads the same net received for a list query (no N+1)', function () {
    $settled = rsSettledInvoice();

    $row = RmeInvoice::query()->withNetReceived()->findOrFail($settled->id);
    expect($row->aggregatedNetReceived())->toBe(350000.0);
});

it('RS-LED-07: a later upward correction is measured against NET received, not the gross that was partly given back', function () {
    $invoice = rsSettledInvoice();

    // 350.000 → 400.000 after 150.000 was already returned: the patient now owes 50.000.
    ricApprove(rppSubmit($invoice->fresh(), ricLinesReducing($invoice->fresh(), '100000'), rppReference('80')));

    $invoice = $invoice->fresh();
    expect((float) $invoice->grand_total)->toBe(400000.0)
        ->and((float) $invoice->remainingAmount())->toBe(50000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and(RmeOverpaymentObligation::query()->where('rme_invoice_id', $invoice->id)->where('status', RmeOverpaymentObligation::STATUS_OPEN)->exists())->toBeFalse();

    // Receivable readers agree: the invoice is an active 50.000 receivable.
    $receivables = app(OversightKpiRepositoryInterface::class)->activeReceivablesByBranch([(int) test()->branch->id]);
    expect($receivables[(int) test()->branch->id] ?? null)->toBe(['amount_cents' => 5000000, 'invoices' => 1]);
});

it('RS-LED-08: a later downward correction opens a new obligation sized against NET received', function () {
    $invoice = rsSettledInvoice();

    // 350.000 → 330.000: of the 350.000 net received, 20.000 is owed back again.
    ricApprove(rppSubmit($invoice->fresh(), ricLinesReducing($invoice->fresh(), '30000'), rppReference('81')));

    $open = RmeOverpaymentObligation::query()->where('rme_invoice_id', $invoice->id)->where('status', RmeOverpaymentObligation::STATUS_OPEN)->sole();
    expect((float) $open->amount)->toBe(20000.0)
        ->and(rsOverpaidCents($invoice->fresh()))->toBe(2000000);
});

it('RS-LED-09: an invoice whose refund was paid out is not an active receivable in any reader', function () {
    $invoice = rsSettledInvoice();

    expect(app(OversightKpiRepositoryInterface::class)->activeReceivablesByBranch([(int) test()->branch->id]))->toBe([])
        ->and(RmeInvoice::query()->whereKey($invoice->id)
            ->whereRaw('trx_rme_invoices.grand_total > '.RmeInvoiceLedger::netReceivedSql('trx_rme_invoices.id'))
            ->exists())->toBeFalse();
});

// ─── RS-RPT: RFS-6 reporting ─────────────────────────────────────────────────

it('RS-RPT-01: gross incoming, refund outflow and net stay separate for the clinical period, by invoice branch', function () {
    rsSettledInvoice();
    $range = ClinicalDateRange::today(app(ClinicalClock::class));
    $repo = app(RmeRefundSettlementRepositoryInterface::class);
    $branchId = (int) test()->branch->id;

    $gross = $repo->grossIncomingByBranch([$branchId], $range->startUtc, $range->endExclusiveUtc);
    $outflow = $repo->settledOutflowByBranch([$branchId], $range->startUtc, $range->endExclusiveUtc);

    expect($gross[$branchId])->toBe(50000000)
        ->and($outflow[$branchId])->toBe(['count' => 1, 'amount_cents' => 15000000])
        ->and($repo->inFlightByBranch([$branchId]))->toBe([]);
});

it('RS-RPT-02: a payout settled outside the period is not counted in it; an in-flight payout is a liability, not outflow', function () {
    $settlement = rsConfirm(rsStart(rsApproved()));
    $repo = app(RmeRefundSettlementRepositoryInterface::class);
    $branchId = (int) test()->branch->id;
    $clock = app(ClinicalClock::class);

    $yesterday = $clock->today()->subDay()->toDateString();
    $past = ClinicalDateRange::tryFrom($yesterday, $yesterday, $clock);
    expect($repo->settledOutflowByBranch([$branchId], $past->startUtc, $past->endExclusiveUtc))->toBe([]);

    $inFlight = rsStart(rsApproved('72'));
    expect($repo->inFlightByBranch([$branchId]))->toBe([$branchId => ['count' => 1, 'amount_cents' => 15000000]]);
    $today = ClinicalDateRange::today($clock);
    expect($repo->settledOutflowByBranch([$branchId], $today->startUtc, $today->endExclusiveUtc)[$branchId]['count'])->toBe(1)
        ->and($inFlight->status)->not->toBe($settlement->fresh()->status);
});

it('RS-RPT-03: the oversight page shows gross, outflow and net for the period', function () {
    rsSettledInvoice();

    test()->actingAs(userInRole('Finance Klinik'))
        ->get(route('oversight.billing-corrections.refund-settlements.index'))
        ->assertOk()
        ->assertSee('data-oversight-settlement-branch="'.test()->branch->id.'"', false)
        ->assertSeeInOrder(['Rp 500.000', 'Rp 150.000', 'Rp 350.000']);
});
