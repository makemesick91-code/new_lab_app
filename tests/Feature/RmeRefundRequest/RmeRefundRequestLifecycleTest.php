<?php

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — lifecycle, idempotency, amount,
| obligation, staleness and eligibility (TDD matrix RR-LIFE / RR-IDEM / RR-AMT /
| RR-OBL / RR-STALE / RR-ELIG / RR-LEDGER / RR-CLK). Every refusal is asserted by
| its stable code, and "nothing written" is asserted on the rows, not assumed.
*/

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeOverpaymentObligationRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundRequestEvent;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmePaymentIntent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus as S;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../RmeBillingDispute/helpers.php';

beforeEach(function () {
    rrrSetup();
});

/** Every financial row a refund must never touch, as plain arrays. */
function rrrFinancialState(RmeInvoice $invoice): array
{
    return [
        'invoice' => DB::table('trx_rme_invoices')->where('id', $invoice->id)->first(),
        'items' => DB::table('trx_rme_invoice_items')->where('rme_invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        'payments' => DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        'obligations' => DB::table('trx_rme_overpayment_obligations')->where('rme_invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        'obligation_events' => DB::table('trx_rme_overpayment_obligation_events')->orderBy('id')->get()->all(),
        'receipts' => DB::table('trx_rme_receipt_snapshots')->where('rme_invoice_id', $invoice->id)->orderBy('id')->get()->all(),
        'versions' => DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->orderBy('id')->get()->all(),
    ];
}

// ─── RR-LIFE ─────────────────────────────────────────────────────────────────

it('RR-LIFE-01: a Front Office maker files a request for the whole OPEN obligation', function () {
    [$invoice, $obligation] = rrrObligation();

    $request = rrrSubmit($invoice, $obligation);

    expect($request->status)->toBe(S::REQUESTED)
        ->and((string) $request->amount)->toBe('150000.00')
        ->and((string) $request->obligation_amount_snapshot)->toBe('150000.00')
        ->and($request->request_number)->toMatch('/^RMEREF-\d{6}-\d{6}$/')
        ->and((int) $request->branch_id)->toBe((int) $invoice->branch_id)
        ->and(RmeRefundRequestEvent::query()->where('rme_refund_request_id', $request->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_REFUND_REQUEST_SUBMITTED)->count())->toBe(1);
});

it('RR-LIFE-02 / RR-LEDGER-01..04: an authorised reviewer approves; no financial row changes', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation);
    $before = rrrFinancialState($invoice);

    $approved = rrrApprove($request);

    expect($approved->status)->toBe(S::APPROVED)
        ->and((int) $approved->decided_by)->toBe((int) test()->supervisor->id)
        ->and(rrrFinancialState($invoice))->toEqual($before)
        ->and($obligation->fresh()->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN);
});

it('RR-LIFE-03: a rejection keeps the obligation OPEN and admits a new request with a new intent and reference', function () {
    [$invoice, $obligation] = rrrObligation();
    $rejected = rrrReject(rrrSubmit($invoice, $obligation));

    expect($rejected->status)->toBe(S::REJECTED)
        ->and($rejected->decision_note)->not->toBeNull()
        ->and($obligation->fresh()->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN);

    $again = rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('02')]);
    expect($again->status)->toBe(S::REQUESTED);
});

it('RR-LIFE-04: the maker withdraws a REQUESTED request with a reason', function () {
    [$invoice, $obligation] = rrrObligation();
    $cancelled = rrrCancel(rrrSubmit($invoice, $obligation));

    expect($cancelled->status)->toBe(S::CANCELLED)
        ->and((int) $cancelled->cancelled_by)->toBe((int) test()->frontOffice->id)
        ->and($cancelled->cancel_reason)->toBe('Pasien membatalkan permintaannya.');
});

it('RR-LIFE-05: an authorised reviewer other than the maker revokes an APPROVED request', function () {
    [$invoice, $obligation] = rrrObligation();
    $approved = rrrApprove(rrrSubmit($invoice, $obligation));

    $revoked = rrrCancel($approved, test()->kepalaCabang, 'Persetujuan diberikan atas bukti yang keliru.');

    expect($revoked->status)->toBe(S::CANCELLED)
        ->and((int) $revoked->cancelled_by)->toBe((int) test()->kepalaCabang->id)
        ->and($obligation->fresh()->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN);
});

it('RR-LIFE-06: the maker cannot revoke its own APPROVED request', function () {
    [$invoice, $obligation] = rrrObligation();
    $approved = rrrApprove(rrrSubmit($invoice, $obligation));

    rrrRefused(fn () => rrrCancel($approved, test()->frontOffice), R::SELF_DECISION_FORBIDDEN);
    expect($approved->fresh()->status)->toBe(S::APPROVED);
});

it('RR-LIFE-07: every illegal transition is refused and writes no event', function () {
    [$invoice, $obligation] = rrrObligation();

    $rejected = rrrReject(rrrSubmit($invoice, $obligation));
    $cancelled = rrrCancel(rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('02')]));
    $events = RmeRefundRequestEvent::query()->count();

    rrrRefused(fn () => rrrApprove($rejected), R::ALREADY_DECIDED);
    rrrRefused(fn () => rrrReject($rejected, test()->kepalaCabang), R::ALREADY_DECIDED);
    rrrRefused(fn () => rrrCancel($rejected), R::INVALID_TRANSITION);
    rrrRefused(fn () => rrrApprove($cancelled), R::INVALID_TRANSITION);
    rrrRefused(fn () => rrrReject($cancelled), R::INVALID_TRANSITION);
    rrrRefused(fn () => rrrCancel($cancelled), R::INVALID_TRANSITION);

    $approved = rrrApprove(rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('03')]));
    $events = RmeRefundRequestEvent::query()->count();
    rrrRefused(fn () => rrrReject($approved), R::ALREADY_DECIDED);
    rrrRefused(fn () => rrrApprove($approved, test()->kepalaCabang), R::ALREADY_DECIDED);

    expect(RmeRefundRequestEvent::query()->count())->toBe($events);
});

it('RR-LIFE-08: the last event of every request names its current status', function () {
    [$invoice, $obligation] = rrrObligation();
    rrrReject(rrrSubmit($invoice, $obligation));
    rrrCancel(rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('02')]));
    rrrApprove(rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('03')]));

    foreach (RmeRefundRequest::all() as $request) {
        $last = RmeRefundRequestEvent::query()->where('rme_refund_request_id', $request->id)->orderByDesc('id')->first();
        expect($last->to_status)->toBe($request->status);
    }
});

it('RR-LIFE-09: a revocation by an account that is not a reviewer for the branch is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    $approved = rrrApprove(rrrSubmit($invoice, $obligation));

    $otherFrontOffice = userInRole('Front Office');
    rmeMakeFrontOfficeActive($otherFrontOffice, test()->branch);
    rrrDesignate(makers: [$otherFrontOffice]);

    rrrRefused(fn () => rrrCancel($approved, $otherFrontOffice), R::REVIEWER_NOT_AUTHORIZED);
});

it('RR-LIFE-10: a decision on a request whose obligation is not the invoice open obligation is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation);

    $double = Mockery::mock(RmeOverpaymentObligationRepositoryInterface::class);
    $double->shouldReceive('findForUpdate')->andReturn($obligation->fresh());
    $double->shouldReceive('openForInvoice')->andReturn(null);
    app()->instance(RmeOverpaymentObligationRepositoryInterface::class, $double);

    rrrRefused(fn () => rrrApprove($request), R::OBLIGATION_CHANGED);
    rrrRefused(fn () => rrrReject($request), R::OBLIGATION_CHANGED);
    rrrRefused(fn () => rrrCancel($request), R::OBLIGATION_CHANGED);
});

// ─── RR-IDEM ─────────────────────────────────────────────────────────────────

it('RR-IDEM-01: an exact duplicate submission returns the original and writes nothing', function () {
    [$invoice, $obligation] = rrrObligation();
    $data = rrrData($invoice, $obligation);

    $first = app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, $data);
    $second = app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, $data);

    expect($second->id)->toBe($first->id)
        ->and(RmeRefundRequest::query()->count())->toBe(1)
        ->and(RmeRefundRequestEvent::query()->count())->toBe(1);
});

it('RR-IDEM-02: the same intent with different content is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    $data = rrrData($invoice, $obligation);
    app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, $data);

    rrrRefused(fn () => app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, array_merge($data, ['reason_note' => 'Alasan yang berbeda dari sebelumnya.'])), R::INTENT_REUSED);
});

it('RR-IDEM-03: a different intent while a request is active is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    rrrSubmit($invoice, $obligation);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('02')]), R::ACTIVE_REQUEST_EXISTS);
    expect(RmeRefundRequest::query()->count())->toBe(1);
});

it('RR-IDEM-04: the same reviewer repeating an approval is a no-op with one event', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation);

    $first = rrrApprove($request);
    $second = rrrApprove($request);

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(S::APPROVED)
        ->and(RmeRefundRequestEvent::query()->where('to_status', S::APPROVED)->count())->toBe(1);
});

it('RR-IDEM-05: replaying a rejected request\'s exact form returns it unchanged', function () {
    [$invoice, $obligation] = rrrObligation();
    $data = rrrData($invoice, $obligation);
    $first = app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, $data);
    rrrReject($first);

    $replay = app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, $data);

    expect($replay->id)->toBe($first->id)
        ->and($replay->status)->toBe(S::REJECTED)
        ->and(RmeRefundRequest::query()->count())->toBe(1);

    rrrRefused(fn () => app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, array_merge($data, ['reason_note' => 'Diajukan ulang dengan alasan lain.'])), R::INTENT_REUSED);
});

it('RR-IDEM-06: a payment intent is not a refund intent, and the reverse', function () {
    [$invoice, $obligation] = rrrObligation();
    $paymentToken = RmePaymentIntent::issue((int) $obligation->id);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['refund_intent' => $paymentToken]), R::INTENT_INVALID);

    $refundToken = rrrData($invoice, $obligation)['refund_intent'];
    expect(RmePaymentIntent::keyFrom($refundToken, (int) $invoice->id))->toBeNull()
        ->and(RmePaymentIntent::keyFrom($refundToken, (int) $obligation->id))->toBeNull();
});

it('RR-IDEM-07: a missing, forged or nonce-swapped intent is refused', function (mixed $token) {
    [$invoice, $obligation] = rrrObligation();
    $real = rrrData($invoice, $obligation)['refund_intent'];
    $value = $token === 'swap' ? str_repeat('a', 32).substr($real, 32) : $token;

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['refund_intent' => $value]), R::INTENT_INVALID);
    expect(RmeRefundRequest::query()->count())->toBe(0);
})->with([[null], [''], ['not-a-token'], [str_repeat('a', 32).'.'.str_repeat('b', 64)], ['swap']]);

it('RR-IDEM-08: an intent signed under another application key is refused; a re-rendered form succeeds', function () {
    [$invoice, $obligation] = rrrObligation();
    $old = rrrData($invoice, $obligation);
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    rrrRefused(fn () => app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, $old), R::INTENT_INVALID);
    expect(rrrSubmit($invoice, $obligation)->status)->toBe(S::REQUESTED);
});

// ─── RR-AMT / RR-OBL ─────────────────────────────────────────────────────────

it('RR-AMT-01: an invalid declared amount is refused before any write', function (mixed $amount) {
    [$invoice, $obligation] = rrrObligation();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['obligation_amount' => $amount]), R::OBLIGATION_CHANGED);
    expect(RmeRefundRequest::query()->count())->toBe(0);
})->with([['-150000'], ['0'], ['0.00'], ['abc'], ['150000.001'], [null]]);

it('RR-AMT-02 / RR-AMT-03: a declared amount above or below the obligation is refused, never capped', function (string $amount) {
    [$invoice, $obligation] = rrrObligation();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['obligation_amount' => $amount]), R::OBLIGATION_CHANGED);
    expect(RmeRefundRequest::query()->count())->toBe(0);
})->with([['150000.01'], ['999999'], ['149999.99'], ['75000']]);

it('RR-AMT-04 / RR-OBL-05 / RR-BR-03: the amount is the obligation; amount, branch and payment fields are ignored', function () {
    [$invoice, $obligation] = rrrObligation();
    $other = rrrOtherBranch();
    $paymentId = (int) RmePayment::query()->where('rme_invoice_id', $invoice->id)->value('id');

    $request = rrrSubmit($invoice, $obligation, ['amount' => '1', 'branch_id' => $other->id, 'payment_id' => $paymentId, 'rme_payment_id' => $paymentId]);

    expect((string) $request->amount)->toBe('150000.00')
        ->and((int) $request->branch_id)->toBe((int) $invoice->branch_id)
        ->and(array_key_exists('payment_id', $request->getAttributes()))->toBeFalse();
});

it('RR-OBL-01 / RR-OBL-02: a non-existent obligation, or one of another invoice, is not found', function () {
    [$invoice, $obligation] = rrrObligation();
    [$otherInvoice, $otherObligation] = rrrObligation('71');

    rrrNotFound(fn () => rrrSubmit($invoice, $obligation, ['obligation_id' => 999999, 'refund_intent' => RmeRefundRequestIntent::issue(999999)]));
    rrrNotFound(fn () => rrrSubmit($invoice, $otherObligation));
    expect(RmeRefundRequest::query()->count())->toBe(0);
});

it('RR-OBL-03: an obligation that is no longer OPEN is refused (repository double; the CHECK admits only OPEN)', function () {
    [$invoice, $obligation] = rrrObligation();
    $closed = $obligation->fresh();
    $closed->setRawAttributes(array_merge($closed->getAttributes(), ['status' => 'SETTLED']), true);

    $double = Mockery::mock(RmeOverpaymentObligationRepositoryInterface::class);
    $double->shouldReceive('openForInvoice')->andReturn(null);
    $double->shouldReceive('find')->andReturn($closed);
    app()->instance(RmeOverpaymentObligationRepositoryInterface::class, $double);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::OBLIGATION_NOT_OPEN);
});

it('RR-OBL-04: an ordinary PAID invoice without an obligation offers no action and refuses a POST', function () {
    $invoice = rppPaidInvoice(500000);
    test()->actingAs(test()->frontOffice);

    $panel = app(RmeRefundRequestService::class)->panelFor($invoice->fresh(), test()->frontOffice);
    expect($panel['can_request'])->toBeFalse()->and($panel['obligation'])->toBeNull();

    rrrNotFound(fn () => app(RmeRefundRequestService::class)->submit($invoice->fresh(), test()->frontOffice, [
        'refund_intent' => RmeRefundRequestIntent::issue(1),
        'obligation_id' => 1,
        'obligation_amount' => '150000.00',
        'invoice_version' => 0,
        'reason_note' => 'Pasien meminta pengembalian kelebihan bayar.',
        'patient_request_reference' => rrrPatientReference(),
        'owner_approval_reference' => rrrOwnerReference(),
    ]));
});

// ─── RR-STALE ────────────────────────────────────────────────────────────────

it('RR-STALE-01: a declared invoice version that differs from the locked one is refused', function () {
    [$invoice, $obligation] = rrrObligation();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['invoice_version' => (int) $invoice->correction_version + 1]), R::INVOICE_VERSION_CHANGED);
});

it('RR-STALE-02: a changed financial state makes the request STALE (committed) and refuses the approval', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation);

    // A payment written past every gate (defence in depth): the snapshot no longer matches.
    RmePayment::factory()->create([
        'branch_id' => $invoice->branch_id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id,
        'patient_id' => $invoice->patient_id,
        'amount' => 1,
    ]);

    rrrRefused(fn () => rrrApprove($request), R::REQUEST_STALE);
    expect($request->fresh()->status)->toBe(S::STALE)
        ->and($request->fresh()->stale_reason_code)->not->toBeNull();
});

it('RR-STALE-03: a ledger that disagrees with the obligation is refused at submit', function () {
    [$invoice, $obligation] = rrrObligation();
    RmePayment::factory()->create([
        'branch_id' => $invoice->branch_id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id,
        'patient_id' => $invoice->patient_id,
        'amount' => 5000,
    ]);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::LEDGER_MISMATCH);
});

it('RR-STALE-04: STALE is terminal; a new request is accepted once the state is consistent', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation);
    $payment = RmePayment::factory()->create([
        'branch_id' => $invoice->branch_id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id,
        'patient_id' => $invoice->patient_id,
        'amount' => 1,
    ]);
    rrrRefused(fn () => rrrApprove($request), R::REQUEST_STALE);
    rrrRefused(fn () => rrrApprove($request), R::INVALID_TRANSITION);

    DB::table('trx_rme_payments')->where('id', $payment->id)->delete();
    $again = rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('02')]);
    expect($again->status)->toBe(S::REQUESTED);
});

// ─── RR-ELIG ─────────────────────────────────────────────────────────────────

it('RR-ELIG-01 / RR-ELIG-02: with the flag off the routes answer 404 and the service refuses', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation);
    rrrFlag(false);

    test()->actingAs(test()->frontOffice)->get(route('rme.refund-requests.index'))->assertNotFound();
    test()->actingAs(test()->frontOffice)->get(route('rme.refund-requests.show', $request->id))->assertNotFound();
    test()->actingAs(test()->supervisor)->post(route('rme.refund-requests.approve', $request->id))->assertNotFound();

    rrrRefused(fn () => rrrSubmit($invoice, $obligation, ['owner_approval_reference' => rrrOwnerReference('02')]), R::NOT_ENABLED);
    rrrRefused(fn () => rrrApprove($request), R::NOT_ENABLED);
    expect($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-ELIG-03: an active billing dispute refuses the submission', function () {
    [$invoice, $obligation] = rrrObligation();
    rbdReport($invoice);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::INVOICE_DISPUTED);
});

it('RR-ELIG-08: a dispute opened after submission refuses the approval and changes nothing', function () {
    [$invoice, $obligation] = rrrObligation();
    $request = rrrSubmit($invoice, $obligation);
    $dispute = rbdReport($invoice);

    test()->actingAs(test()->frontOffice);
    rrrRefused(fn () => rrrApprove($request), R::INVOICE_DISPUTED);
    expect($request->fresh()->status)->toBe(S::REQUESTED)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('RR-ELIG-04: an active correction on the invoice is refused (defensive)', function () {
    [$invoice, $obligation] = rrrObligation();
    $correction = RmeInvoiceCorrection::query()->where('rme_invoice_id', $invoice->id)->firstOrFail();
    $double = Mockery::mock(RmeInvoiceCorrectionRepositoryInterface::class);
    $double->shouldReceive('activeForInvoice')->andReturn($correction);
    app()->instance(RmeInvoiceCorrectionRepositoryInterface::class, $double);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::CORRECTION_ACTIVE);
});

it('RR-ELIG-05: a branch outside the pilot is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    rrrPilot(['SPN4']);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::PILOT_SCOPE_DENIED);
});

it('RR-ELIG-06: an invoice that is not PAID is refused', function () {
    [$invoice, $obligation] = rrrObligation();
    $partial = $invoice->fresh();
    $partial->setRawAttributes(array_merge($partial->getAttributes(), ['status' => RmeInvoice::STATUS_PARTIAL]), true);

    $double = Mockery::mock(RmeInvoiceRepositoryInterface::class);
    $double->shouldReceive('findForUpdate')->andReturn($partial);
    app()->instance(RmeInvoiceRepositoryInterface::class, $double);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::INVOICE_NOT_PAID);
});

it('RR-ELIG-07: the shipped (empty) identity allowlist admits nobody, the Sprint 2 pair included', function () {
    [$invoice, $obligation] = rrrObligation();
    rrrIdentities([], []);

    rrrRefused(fn () => rrrSubmit($invoice, $obligation), R::PILOT_IDENTITY_DENIED);

    $shipped = require base_path('config/rme_refund.php');
    expect($shipped['pilot']['identities'])->toBe(['maker_user_ids' => [], 'reviewer_user_ids' => []])
        ->and($shipped['pilot']['branch_codes'])->toBe(['SPN4']);
});

// ─── RR-CLK ──────────────────────────────────────────────────────────────────

it('RR-CLK-01: the request number takes the clinical (WITA) month, not the UTC one', function () {
    [$invoice, $obligation] = rrrObligation();
    // 2026-10-31 20:30 UTC = 2026-11-01 04:30 WITA.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-31 20:30:00', 'UTC'));
    Carbon::setTestNow(Carbon::parse('2026-10-31 20:30:00', 'UTC'));
    // A new clinical day: the maker selects its working branch for that day.
    rmeMakeFrontOfficeActive(test()->frontOffice, test()->branch);

    $request = rrrSubmit($invoice, $obligation);

    expect($request->request_number)->toStartWith('RMEREF-202611-');
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});
