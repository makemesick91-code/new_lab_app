<?php

declare(strict_types=1);

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — regressions for the independent
| security review of the settlement change. Each case names the finding it pins:
|   M1  only the starter may declare "no money left" (abort), from any state
|   M2  every balance reader shows NET received after a settled refund
|   L1  one bank reference / handover number proves one refund, however spelled
|   L2  PDF active content is found behind name escapes and Flate streams
|   L4  the pay button follows the settler rule, not Gate::before
|   L5  a database failure never carries the reference or free text to a log
|   +   abort evidence is never reused; a request id cannot be probed via store
*/

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Services\DoctorPerformanceReportService;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod as M;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus as S;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rsSetup();
});

function rsrPdf(string $body, string $name = 'bukti.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.5\n".$body."\n%%EOF\n");
}

// ─── M1 ──────────────────────────────────────────────────────────────────────

it('RS-REV-M1a: after another settler escalates, only the starter is offered — and allowed — the abort', function () {
    $settlement = rsUnknown(rsStart(rsApproved()), test()->settler2);
    $service = app(RmeRefundSettlementService::class);

    expect($service->actionsFor($settlement->fresh(), test()->settler2))->toMatchArray(['confirm' => true, 'abort' => false])
        ->and($service->actionsFor($settlement->fresh(), test()->settler))->toMatchArray(['confirm' => true, 'abort' => true]);

    rsRefused(fn () => rsAbort($settlement, test()->settler2, rsPdf('mutasi.pdf', 'm1')), R::NOT_STARTER);
    expect($settlement->fresh()->status)->toBe(S::OUTCOME_UNKNOWN)
        ->and($settlement->fresh()->refundRequest->status)->toBe('APPROVED');
});

it('RS-REV-M1b: the HTTP abort by a non-starter changes nothing, so the request is never released for a second payout', function () {
    $request = rsApproved();
    $settlement = rsUnknown(rsStart($request), test()->settler2);
    rmeMakeKasirActive(test()->settler2, test()->branch);

    $this->actingAs(test()->settler2)
        ->from(route('rme.refund-settlements.show', $settlement->id))
        ->post(route('rme.refund-settlements.abort', $settlement->id), ['abort_reason' => 'Transfer tidak terkirim menurut bank.', 'attest_not_disbursed' => '1', 'evidence' => rsPdf('m.pdf', 'm1b')])
        ->assertSessionHasErrors();

    expect($settlement->fresh()->status)->toBe(S::OUTCOME_UNKNOWN);
    rsRefused(fn () => rsStart($request, test()->settler2), R::SETTLEMENT_EXISTS);
});

// ─── L1 ──────────────────────────────────────────────────────────────────────

it('RS-REV-L1a: a bank reference re-spelled with other separators or case cannot prove a second refund', function () {
    rsConfirm(rsStart(rsApproved('70')), data: rsBankConfirmData());
    $second = rsStart(rsApproved('71'), test()->settler2);

    rsRefused(fn () => rsConfirm($second, test()->settler2, array_merge(rsBankConfirmData(), ['disbursement_reference' => 'trf bca 20261031 0001']), rsPdf('b.pdf', 'l1a')), R::REFERENCE_REUSED);
    expect($second->fresh()->status)->toBe(S::DISBURSING);
});

it('RS-REV-L1b: one reference cannot prove a bank payout and a cash handover', function () {
    rsConfirm(rsStart(rsApproved('70')), data: rsBankConfirmData());
    $cash = rsStart(rsApproved('71'), test()->settler2, M::CASH);

    rsRefused(fn () => rsConfirm($cash, test()->settler2, array_merge(rsCashConfirmData(), ['disbursement_reference' => 'TRF/BCA/20261031/0001']), rsPng()), R::REFERENCE_REUSED);
});

it('RS-REV-L1c: a reference must keep at least six letters or digits', function () {
    $settlement = rsStart(rsApproved());

    rsRefused(fn () => rsConfirm($settlement, data: array_merge(rsBankConfirmData(), ['disbursement_reference' => 'A-B-C-D-E'])), R::REFERENCE_INVALID);
    expect(RmeRefundSettlementService::referenceKey('trf-bca 2026/10.31#1'))->toBe('TRFBCA20261031'.'1');
});

// ─── Evidence reuse ──────────────────────────────────────────────────────────

it('RS-REV-EV: proof that money did not move can never prove that it did, nor a second abort', function () {
    $proof = rsPdf('mutasi.pdf', 'shared');
    $first = rsStart(rsApproved('70'));
    rsAbort(rsUnknown($first), null, $proof);

    $second = rsStart(rsApproved('71'), test()->settler2);
    rsRefused(fn () => rsConfirm($second, test()->settler2, null, rsPdf('mutasi.pdf', 'shared')), R::EVIDENCE_REUSED);

    $third = rsUnknown(rsStart(rsApproved('72')));
    rsRefused(fn () => rsAbort($third, null, rsPdf('mutasi.pdf', 'shared')), R::EVIDENCE_REUSED);
});

// ─── L2 ──────────────────────────────────────────────────────────────────────

it('RS-REV-L2: PDF active content behind a name escape, a newline, a Flate stream or encryption is refused', function (string $body) {
    $settlement = rsStart(rsApproved());

    rsRefused(fn () => rsConfirm($settlement, evidence: rsrPdf($body)), R::EVIDENCE_INVALID);
    expect($settlement->fresh()->status)->toBe(S::DISBURSING);
})->with([
    'hex-escaped name' => ['1 0 obj<</S/J#61vaScript/JS(app.alert(1))>>endobj'],
    'JS before a newline' => ["1 0 obj<</JS\n(x)>>endobj"],
    'Launch escaped' => ['1 0 obj<</S/Launc#68/F(x.exe)>>endobj'],
    'in a Flate object stream' => ["1 0 obj<</Type/ObjStm/Filter/FlateDecode>>\nstream\n".gzcompress('<</S/JavaScript/JS(x)>>')."\nendstream\nendobj"],
    'encrypted' => ['trailer<</Encrypt 5 0 R>>'],
]);

it('RS-REV-L2b: an ordinary statement with links and compressed text is accepted', function () {
    $settlement = rsStart(rsApproved());
    $body = "1 0 obj<</Type/Catalog/OpenAction[2 0 R/Fit]>>endobj\n2 0 obj<</S/URI/URI(https://bank.example/mutasi)>>endobj\n"
        ."3 0 obj<</Filter/FlateDecode>>\nstream\n".gzcompress('BT /F1 12 Tf (Bukti transfer refund) Tj ET')."\nendstream\nendobj";

    expect(rsConfirm($settlement, evidence: rsrPdf($body))->status)->toBe(S::SETTLED);
});

// ─── M2 ──────────────────────────────────────────────────────────────────────

/** A settled 150.000 refund followed by an upward correction to 400.000: 50.000 is owed. */
function rsrOwedAgain(): RmeInvoice
{
    $settlement = rsConfirm(rsStart(rsApproved()));
    $invoice = RmeInvoice::query()->findOrFail($settlement->rme_invoice_id);
    ricApprove(rppSubmit($invoice->fresh(), ricLinesReducing($invoice->fresh(), '100000'), rppReference('90')));

    return $invoice->fresh();
}

it('RS-REV-M2a: the cashier receivable list and its export show the NET balance per invoice', function () {
    $invoice = rsrOwedAgain();
    expect($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL);

    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, test()->branch);

    // The per-row balance cell (the summary card above it was already net).
    $this->actingAs($kasir)->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee($invoice->invoice_number)
        ->assertSee('<td class="px-4 py-3 text-right font-bold text-warning-700">Rp 50.000</td>', false);

    $csv = $this->actingAs($kasir)->get(route('rme.cashier.receivables.export'))->assertOk()->streamedContent();
    $row = collect(preg_split('/\r?\n/', trim((string) $csv)))
        ->map(fn (string $line) => str_getcsv($line))
        ->first(fn (array $cells) => ($cells[0] ?? null) === $invoice->invoice_number);
    expect($row)->not->toBeNull()
        ->and(end($row))->toBe('50000.00');
});

it('RS-REV-M2b: the invoice summary states the refund already paid back beside the gross received', function () {
    $settlement = rsConfirm(rsStart(rsApproved()));
    $invoice = RmeInvoice::query()->findOrFail($settlement->rme_invoice_id);

    $this->blade('<x-rme.invoice-summary :invoice="$invoice" />', ['invoice' => $invoice])
        ->assertSee('data-invoice-refunded', false)
        ->assertSee('Rp 150.000')
        ->assertSee('Rp 500.000');
});

it('RS-REV-M2c: the doctor performance report counts money received NET of refunds paid back', function () {
    $settlement = rsConfirm(rsStart(rsApproved()));
    $visit = RmeInvoice::query()->findOrFail($settlement->rme_invoice_id)->clinicVisit;
    $service = app(DoctorPerformanceReportService::class);
    $access = ['mode' => 'all', 'forced_doctor_id' => null, 'can_pick_doctor' => true, 'can_pick_branch' => true];

    $detail = $service->report(array_merge($access, ['forced_doctor_id' => (int) $visit->doctor_id]), []);
    expect((float) $detail['kpis']['paid'])->toBe(350000.0)
        ->and((float) $detail['kpis']['outstanding'])->toBe(0.0);
    $daily = collect($detail['daily_rows'])->firstWhere('date', $visit->visit_date->toDateString());
    expect((float) $daily['paid'])->toBe(350000.0);

    $summary = collect($service->report($access, [])['summary_rows'])->firstWhere('doctor_id', (int) $visit->doctor_id);
    expect((float) $summary['paid'])->toBe(350000.0)
        ->and((float) $summary['outstanding'])->toBe(0.0);
});

// ─── L4 / probing ────────────────────────────────────────────────────────────

it('RS-REV-L4: Super Admin passes the permission through Gate::before but is offered no pay button without a designation', function () {
    $request = rsApproved();

    expect(test()->superAdmin->can(RmeRefundSettlementService::PERMISSION_SETTLE))->toBeTrue()
        ->and(app(RmeRefundSettlementService::class)->isSettlerAccount(test()->superAdmin))->toBeFalse();
    $this->actingAs(test()->superAdmin)->get(route('rme.refund-settlements.index'))->assertOk()
        ->assertDontSee('/settlement/create', false);
    $this->actingAs(test()->superAdmin)->get(route('rme.refund-requests.show', $request->id))->assertOk()
        ->assertSee('data-refund-settlement-panel', false)
        ->assertDontSee(route('rme.refund-settlements.create', $request->id), false);
    $this->actingAs(test()->superAdmin)->get(route('rme.refund-settlements.create', $request->id))->assertNotFound();
});

it('RS-REV-PROBE: an account that cannot see the branch gets the same 404 for a real and a missing request', function () {
    $request = rsApproved();
    $holder = userInRole('Kasir');
    $holder->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
    rmeMakeKasirActive($holder, test()->branch);

    $this->actingAs($holder)->post(route('rme.refund-settlements.store', $request->id), rsStartData($request))->assertNotFound();
    $this->actingAs($holder)->post(route('rme.refund-settlements.store', 999999), [])->assertNotFound();
    expect(RmeRefundSettlement::count())->toBe(0);
});

// ─── L5 ──────────────────────────────────────────────────────────────────────

it('RS-REV-L5: a database failure on confirm is replaced by a message without the reference, bank or free text', function () {
    $settlement = rsStart(rsApproved());

    // A real database failure on the SETTLED write, carrying the bank data in its bindings.
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::unprepared("CREATE FUNCTION rsr_force_fail() RETURNS trigger AS $$ BEGIN IF NEW.status = 'SETTLED' THEN RAISE EXCEPTION 'forced failure' USING ERRCODE = '40P01'; END IF; RETURN NEW; END $$ LANGUAGE plpgsql;");
        DB::unprepared('CREATE TRIGGER rsr_force_fail BEFORE UPDATE ON trx_rme_refund_settlements FOR EACH ROW EXECUTE FUNCTION rsr_force_fail();');
    } else {
        DB::unprepared("CREATE TRIGGER rsr_force_fail BEFORE UPDATE ON trx_rme_refund_settlements WHEN NEW.status = 'SETTLED' BEGIN SELECT RAISE(ABORT, 'forced failure'); END;");
    }

    try {
        rsConfirm($settlement);
        $this->fail('confirm should have failed');
    } catch (RuntimeException $e) {
        expect($e)->not->toBeInstanceOf(QueryException::class)
            ->and($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->toContain('SQLSTATE')
            ->and($e->getMessage())->not->toContain('TRF-BCA')
            ->and($e->getMessage())->not->toContain('4321')
            ->and($e->getMessage())->not->toContain('BCA');
    }

    expect($settlement->fresh()->status)->toBe(S::DISBURSING);
});
