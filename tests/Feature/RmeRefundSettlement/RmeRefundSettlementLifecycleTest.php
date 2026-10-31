<?php

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — lifecycle, evidence,
| idempotency and eligibility of a refund settlement (TDD matrix S5-RED / RS-LIFE /
| RS-EVD / RS-IDEM / RS-ELIG). Every refusal is asserted by its stable code, and
| "nothing written" is asserted on the rows, never assumed.
*/

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Models\RmeRefundSettlementEvent;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementEvidence;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementIntent;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod as M;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus as S;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../RmeBillingDispute/helpers.php';

beforeEach(function () {
    rsSetup();
});

// ─── S5-RED: the twelve scenarios the sprint was opened with ────────────────

it('S5-RED-01: an APPROVED request can be settled — bank transfer, full amount, evidence', function () {
    $request = rsApproved();
    $settlement = rsConfirm(rsStart($request));

    expect($settlement->status)->toBe(S::SETTLED)
        ->and($request->fresh()->status)->toBe(RmeRefundRequestStatus::SETTLED)
        ->and(RmeOverpaymentObligation::query()->findOrFail($request->rme_overpayment_obligation_id)->status)
        ->toBe(RmeOverpaymentObligation::STATUS_SETTLED);
});

it('S5-RED-02: there is an explicit settlement actor contract — a designated, permitted, usable account', function () {
    $request = rsApproved();
    $undesignated = userInRole('Kasir');
    $undesignated->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);

    rsRefused(fn () => rsStart($request, $undesignated), R::SETTLER_NOT_AUTHORIZED);
    expect(RmeRefundSettlement::query()->count())->toBe(0);
});

it('S5-RED-03: the approver can never settle the request it approved (RF-10)', function () {
    $request = rsApproved();
    // The approver (Supervisor RME) is given the permission and a designation: still refused.
    test()->supervisor->givePermissionTo(RmeRefundSettlementService::PERMISSION_SETTLE);
    rsDesignate(test()->supervisor);

    rsRefused(fn () => rsStart($request, test()->supervisor), R::SETTLER_IS_APPROVER);
    expect(RmeRefundSettlement::query()->count())->toBe(0);
});

it('S5-RED-04: a partial settlement is refused (RF-4)', function () {
    $request = rsApproved();

    rsRefused(fn () => rsStart($request, null, M::BANK_TRANSFER, ['amount' => '100000.00']), R::AMOUNT_MISMATCH);
    rsRefused(fn () => rsStart($request, null, M::BANK_TRANSFER, ['amount' => '150000.01']), R::AMOUNT_MISMATCH);
    expect(RmeRefundSettlement::query()->count())->toBe(0);
});

it('S5-RED-05: a bank transfer needs a reference, the destination and a completed-transfer evidence file', function () {
    $settlement = rsStart(rsApproved());

    rsRefused(fn () => rsConfirm($settlement, null, rsBankConfirmData(['disbursement_reference' => ''])), R::REFERENCE_REQUIRED);
    rsRefused(fn () => rsConfirm($settlement, null, rsBankConfirmData(['destination_account_last4' => '12'])), R::DESTINATION_INVALID);
    rsRefused(fn () => rsConfirm($settlement, null, rsBankConfirmData(['destination_bank_name' => ''])), R::DESTINATION_INVALID);
    rsRefused(fn () => app(RmeRefundSettlementService::class)->confirm($settlement->fresh(), test()->settler, rsBankConfirmData(), null), R::EVIDENCE_REQUIRED);
    rsRefused(fn () => rsConfirm($settlement, null, rsBankConfirmData(['attest_disbursed' => null])), R::ATTESTATION_REQUIRED);

    expect($settlement->fresh()->status)->toBe(S::DISBURSING)
        ->and(Storage::disk(RmeRefundSettlementEvidence::DISK)->allFiles())->toBe([]);
});

it('S5-RED-06: a cash refund needs a handover acknowledgment and the recipient relation', function () {
    $settlement = rsStart(rsApproved(), null, M::CASH);

    rsRefused(fn () => rsConfirm($settlement, null, rsCashConfirmData(['recipient_relation' => 'NEIGHBOUR'])), R::RECIPIENT_INVALID);
    rsRefused(fn () => rsConfirm($settlement, null, rsCashConfirmData(['disbursement_reference' => null])), R::REFERENCE_REQUIRED);
    rsRefused(fn () => app(RmeRefundSettlementService::class)->confirm($settlement->fresh(), test()->settler, rsCashConfirmData(), null), R::EVIDENCE_REQUIRED);

    $settled = rsConfirm($settlement, null, rsCashConfirmData(), rsPng());
    expect($settled->status)->toBe(S::SETTLED)
        ->and($settled->recipient_relation)->toBe('PATIENT')
        ->and($settled->destination_account_last4)->toBeNull();
});

it('S5-RED-07: a duplicate settlement for the same request is refused', function () {
    $request = rsApproved();
    $first = rsStart($request);

    rsRefused(fn () => rsStart($request, test()->settler2), R::SETTLEMENT_EXISTS);
    rsConfirm($first);
    rsRefused(fn () => rsStart($request, test()->settler2), R::REQUEST_NOT_APPROVED);

    expect(RmeRefundSettlement::query()->count())->toBe(1);
});

it('S5-RED-08: an uncertain payment cannot be blindly retried — the claim stays held until reconciled', function () {
    $request = rsApproved();
    $settlement = rsStart($request);
    rsUnknown($settlement);

    expect($settlement->fresh()->status)->toBe(S::OUTCOME_UNKNOWN);
    // A fresh attempt by anyone is refused while the outcome is unknown.
    rsRefused(fn () => rsStart($request, test()->settler2), R::SETTLEMENT_EXISTS);
    // Releasing an unknown outcome as "no money left" needs evidence — even from the starter.
    rsRefused(fn () => rsAbort($settlement, null, null, ['abort_reason' => 'Bank menolak transfer; dana kembali.']), R::EVIDENCE_REQUIRED);

    expect(RmeRefundSettlement::query()->count())->toBe(1)
        ->and($request->fresh()->status)->toBe(RmeRefundRequestStatus::APPROVED);
});

it('S5-RED-09: a settlement reduces net received of the invoice by the settled amount (RFS-1)', function () {
    $request = rsApproved();
    $invoice = RmeInvoice::query()->findOrFail($request->rme_invoice_id);
    expect($invoice->paidAmount())->toBe(500000.0)->and($invoice->netReceivedAmount())->toBe(500000.0);

    rsConfirm(rsStart($request));
    $invoice->refresh();

    expect($invoice->paidAmount())->toBe(500000.0)
        ->and($invoice->settledRefundAmount())->toBe(150000.0)
        ->and($invoice->netReceivedAmount())->toBe(350000.0)
        ->and($invoice->remainingAmount())->toBe(0.0)
        ->and((float) $invoice->grand_total)->toBe(350000.0);
});

it('S5-RED-10: a settled obligation never reappears as an open overpayment (RFS-2)', function () {
    $request = rsApproved();
    rsConfirm(rsStart($request));
    $invoice = RmeInvoice::query()->findOrFail($request->rme_invoice_id);

    expect(RmeInvoiceLedger::overpaidCents(
        RmeInvoiceLedger::grandTotalCents($invoice),
        RmeInvoiceLedger::netReceivedCents($invoice),
    ))->toBe(0)
        ->and(RmeOverpaymentObligation::query()->where('rme_invoice_id', $invoice->id)->where('status', RmeOverpaymentObligation::STATUS_OPEN)->count())->toBe(0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('S5-RED-11: incoming payments, invoices, items, receipts and versions are never written (RFS-5)', function () {
    $request = rsApproved();
    $before = rsLedgerFingerprint();

    rsConfirm(rsStart($request));

    expect(rsLedgerFingerprint())->toEqual($before);
});

it('S5-RED-12: with the settlement flag OFF every settlement mutation is refused', function () {
    $request = rsApproved();
    $settlement = rsStart($request);
    rsFlag(false);

    rsRefused(fn () => rsStart($request, test()->settler2), R::NOT_ENABLED);
    rsRefused(fn () => rsConfirm($settlement), R::NOT_ENABLED);
    rsRefused(fn () => rsUnknown($settlement), R::NOT_ENABLED);
    rsRefused(fn () => rsAbort($settlement), R::NOT_ENABLED);

    expect($settlement->fresh()->status)->toBe(S::DISBURSING);
});

it('S5-RED-12b: settlement also needs the refund request capability (RF-3 order)', function () {
    $request = rsApproved();
    rrrFlag(false);

    rsRefused(fn () => rsStart($request), R::NOT_ENABLED);
});

// ─── RS-LIFE: state machine ─────────────────────────────────────────────────

it('RS-LIFE-01: start writes one DISBURSING claim bound to request, obligation, invoice, branch and approver', function () {
    $request = rsApproved();
    $settlement = rsStart($request);

    expect($settlement->status)->toBe(S::DISBURSING)
        ->and($settlement->method)->toBe(M::BANK_TRANSFER)
        ->and((int) $settlement->rme_refund_request_id)->toBe((int) $request->id)
        ->and((int) $settlement->rme_overpayment_obligation_id)->toBe((int) $request->rme_overpayment_obligation_id)
        ->and((int) $settlement->rme_invoice_id)->toBe((int) $request->rme_invoice_id)
        ->and((int) $settlement->branch_id)->toBe((int) $request->branch_id)
        ->and((int) $settlement->approved_by)->toBe((int) $request->decided_by)
        ->and((int) $settlement->started_by)->toBe((int) test()->settler->id)
        ->and((string) $settlement->amount)->toBe('150000.00')
        ->and($settlement->settlement_number)->toStartWith('RMESTL-')
        ->and($request->fresh()->status)->toBe(RmeRefundRequestStatus::APPROVED)
        ->and(RmeOverpaymentObligation::query()->findOrFail($request->rme_overpayment_obligation_id)->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN);
});

it('RS-LIFE-02: confirm moves settlement, request and obligation together, with server time and evidence', function () {
    Carbon::setTestNow('2026-10-31 03:15:00');
    $request = rsApproved();
    $settlement = rsConfirm(rsStart($request));
    $obligation = RmeOverpaymentObligation::query()->findOrFail($request->rme_overpayment_obligation_id);

    expect($settlement->settled_at?->toIso8601String())->toBe(Carbon::parse('2026-10-31 03:15:00')->toIso8601String())
        ->and((int) $settlement->settled_by)->toBe((int) test()->settler->id)
        ->and($settlement->evidence_sha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and($settlement->disbursement_reference)->toBe('TRF-BCA-20261031-0001')
        ->and($settlement->destination_account_last4)->toBe('4321')
        ->and($settlement->settler_attested_at)->not->toBeNull()
        ->and($obligation->status)->toBe(RmeOverpaymentObligation::STATUS_SETTLED)
        ->and((int) $obligation->rme_refund_settlement_id)->toBe((int) $settlement->id)
        ->and($obligation->settled_at)->not->toBeNull()
        ->and(Storage::disk(RmeRefundSettlementEvidence::DISK)->exists($settlement->evidence_path))->toBeTrue();
    Carbon::setTestNow();
});

it('RS-LIFE-03: only the starter confirms or aborts a DISBURSING claim; anyone authorised may escalate it', function () {
    $settlement = rsStart(rsApproved());

    rsRefused(fn () => rsConfirm($settlement, test()->settler2), R::NOT_STARTER);
    rsRefused(fn () => rsAbort($settlement, test()->settler2), R::NOT_STARTER);

    $unknown = rsUnknown($settlement, test()->settler2);
    expect($unknown->status)->toBe(S::OUTCOME_UNKNOWN)
        ->and((int) $unknown->outcome_unknown_by)->toBe((int) test()->settler2->id);

    // From OUTCOME_UNKNOWN any authorised settler reconciles.
    $settled = rsConfirm($unknown, test()->settler2);
    expect($settled->status)->toBe(S::SETTLED)->and((int) $settled->settled_by)->toBe((int) test()->settler2->id);
});

it('RS-LIFE-04: an aborted claim releases the request for a fresh attempt; nothing financial changes', function () {
    $request = rsApproved();
    $before = rsLedgerFingerprint();
    $aborted = rsAbort(rsStart($request));

    expect($aborted->status)->toBe(S::ABORTED)
        ->and($aborted->abort_reason)->toContain('dibatalkan')
        ->and($request->fresh()->status)->toBe(RmeRefundRequestStatus::APPROVED)
        ->and(RmeOverpaymentObligation::query()->findOrFail($request->rme_overpayment_obligation_id)->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN)
        ->and(rsLedgerFingerprint())->toEqual($before);

    $second = rsStart($request, test()->settler2, M::CASH);
    expect($second->status)->toBe(S::DISBURSING)->and(RmeRefundSettlement::query()->count())->toBe(2);
});

it('RS-LIFE-05: abort from DISBURSING requires the no-money attestation and a reason', function () {
    $settlement = rsStart(rsApproved());

    rsRefused(fn () => rsAbort($settlement, null, null, ['attest_not_disbursed' => null]), R::ATTESTATION_REQUIRED);
    rsRefused(fn () => rsAbort($settlement, null, null, ['abort_reason' => 'pendek']), R::REASON_REQUIRED);
    expect($settlement->fresh()->status)->toBe(S::DISBURSING);
});

it('RS-LIFE-06: an unknown outcome reconciled as not disbursed by the starter records the bank evidence', function () {
    $request = rsApproved();
    // Another settler escalates; only the starter — who made the transfer — may
    // then declare that no money left (review M1: otherwise a second settler could
    // release a payout that was in fact sent and pay it again).
    $settlement = rsUnknown(rsStart($request), test()->settler2);
    rsRefused(fn () => rsAbort($settlement, test()->settler2, rsPdf('mutasi.pdf', 'y'), ['abort_reason' => 'Bank menolak transfer; dana kembali ke rekening klinik.']), R::NOT_STARTER);

    $aborted = rsAbort($settlement, test()->settler, rsPdf('mutasi.pdf', 'x'), ['abort_reason' => 'Bank menolak transfer; dana kembali ke rekening klinik.']);

    expect($aborted->status)->toBe(S::ABORTED)
        ->and($aborted->evidence_sha256)->toMatch('/^[0-9a-f]{64}$/')
        ->and((int) $aborted->aborted_by)->toBe((int) test()->settler->id);
});

it('RS-LIFE-07: terminal settlements refuse every further transition', function () {
    $settled = rsConfirm(rsStart(rsApproved()));
    rsRefused(fn () => rsAbort($settled), R::INVALID_TRANSITION);
    rsRefused(fn () => rsUnknown($settled), R::INVALID_TRANSITION);

    $aborted = rsAbort(rsStart(rsApproved('71')));
    rsRefused(fn () => rsConfirm($aborted), R::INVALID_TRANSITION);
    rsRefused(fn () => rsUnknown($aborted), R::INVALID_TRANSITION);
});

it('RS-LIFE-08: a settled request cannot be revoked, and an in-flight claim blocks revocation too', function () {
    $request = rsApproved();
    $settlement = rsStart($request);

    rrrRefused(fn () => rrrCancel($request, test()->kepalaCabang), RmeRefundRequestRefused::SETTLEMENT_IN_PROGRESS);
    rsConfirm($settlement);
    rrrRefused(fn () => rrrCancel($request, test()->kepalaCabang), RmeRefundRequestRefused::INVALID_TRANSITION);

    expect($request->fresh()->status)->toBe(RmeRefundRequestStatus::SETTLED);
});

it('RS-LIFE-09: the model refuses a forbidden edge, a rewrite of facts and a delete', function () {
    $settled = rsConfirm(rsStart(rsApproved()));

    expect(fn () => $settled->forceFill(['status' => S::DISBURSING])->save())->toThrow(LogicException::class);
    expect(fn () => $settled->fresh()->forceFill(['amount' => '1.00'])->save())->toThrow(LogicException::class);
    expect(fn () => $settled->fresh()->forceFill(['disbursement_reference' => 'X-OTHER-REF'])->save())->toThrow(LogicException::class);
    expect(fn () => $settled->fresh()->delete())->toThrow(LogicException::class);

    $obligation = RmeOverpaymentObligation::query()->findOrFail($settled->rme_overpayment_obligation_id);
    expect(fn () => $obligation->forceFill(['status' => RmeOverpaymentObligation::STATUS_OPEN])->save())->toThrow(LogicException::class);
});

it('RS-LIFE-10: every transition writes one event and one audit row, without bank or patient text', function () {
    $settled = rsConfirm(rsStart(rsApproved()));

    expect(RmeRefundSettlementEvent::query()->where('rme_refund_settlement_id', $settled->id)->pluck('to_status')->all())
        ->toBe([S::DISBURSING, S::SETTLED]);

    $actions = AuditLog::query()->where('entity_type', RmeFinancialAuditEvent::ENTITY_REFUND_SETTLEMENT)->pluck('action')->all();
    expect($actions)->toContain(RmeFinancialAuditEvent::ACTION_REFUND_SETTLEMENT_STARTED)
        ->and($actions)->toContain(RmeFinancialAuditEvent::ACTION_REFUND_SETTLEMENT_SETTLED);
    expect(AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_OVERPAYMENT_OBLIGATION_SETTLED)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_REFUND_REQUEST_SETTLED)->count())->toBe(1);

    $json = (string) json_encode(AuditLog::query()->where('entity_type', RmeFinancialAuditEvent::ENTITY_REFUND_SETTLEMENT)->get(['new_values'])->toArray());
    expect($json)->not->toContain('TRF-BCA-20261031-0001')->not->toContain('4321')->not->toContain('BCA');
});

// ─── RS-EVD: evidence ───────────────────────────────────────────────────────

it('RS-EVD-01: evidence is stored privately under a generated name, never the uploaded file name', function () {
    $settled = rsConfirm(rsStart(rsApproved()), null, null, rsPdf('Bukti Transfer Ibu Siti.pdf'));

    expect($settled->evidence_path)->not->toContain('Siti')
        ->and($settled->evidence_path)->toStartWith($settled->uuid.'/')
        ->and($settled->evidence_mime)->toBe('application/pdf')
        ->and(config('filesystems.disks.'.RmeRefundSettlementEvidence::DISK.'.visibility'))->toBe('private')
        ->and(config('filesystems.disks.'.RmeRefundSettlementEvidence::DISK.'.serve'))->toBeFalse()
        ->and(hash('sha256', (string) Storage::disk(RmeRefundSettlementEvidence::DISK)->get($settled->evidence_path)))->toBe($settled->evidence_sha256);
});

it('RS-EVD-02: a file that is not a genuine PDF, JPEG or PNG is refused, whatever its extension', function () {
    $settlement = rsStart(rsApproved());

    rsRefused(fn () => rsConfirm($settlement, null, null, UploadedFile::fake()->createWithContent('bukti.pdf', '<?php echo 1; ?>')), R::EVIDENCE_INVALID);
    rsRefused(fn () => rsConfirm($settlement, null, null, UploadedFile::fake()->createWithContent('bukti.png', 'GIF89a fake')), R::EVIDENCE_INVALID);
    rsRefused(fn () => rsConfirm($settlement, null, null, UploadedFile::fake()->createWithContent('bukti.html', '<html><script>x</script></html>')), R::EVIDENCE_INVALID);

    expect(Storage::disk(RmeRefundSettlementEvidence::DISK)->allFiles())->toBe([])
        ->and($settlement->fresh()->status)->toBe(S::DISBURSING);
});

it('RS-EVD-03: an oversized evidence file is refused', function () {
    config()->set('rme_refund_settlement.evidence.max_kilobytes', 1);
    $settlement = rsStart(rsApproved());
    $big = UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\n".str_repeat('A', 4096)."\n%%EOF\n");

    rsRefused(fn () => rsConfirm($settlement, null, null, $big), R::EVIDENCE_INVALID);
});

it('RS-EVD-04: the same evidence file can never settle two refunds', function () {
    rsConfirm(rsStart(rsApproved()), null, null, rsPdf('a.pdf', 'same'));
    $second = rsStart(rsApproved('71'));

    rsRefused(fn () => rsConfirm($second, null, rsBankConfirmData(['disbursement_reference' => 'TRF-BCA-20261031-0002']), rsPdf('b.pdf', 'same')), R::EVIDENCE_REUSED);
    expect($second->fresh()->status)->toBe(S::DISBURSING)
        ->and(count(Storage::disk(RmeRefundSettlementEvidence::DISK)->allFiles()))->toBe(1);
});

it('RS-EVD-05: the same bank reference can never settle two refunds, case-folded', function () {
    rsConfirm(rsStart(rsApproved()));
    $second = rsStart(rsApproved('71'));

    rsRefused(fn () => rsConfirm($second, null, rsBankConfirmData(['disbursement_reference' => 'trf-bca-20261031-0001']), rsPdf('b.pdf', 'other')), R::REFERENCE_REUSED);
});

it('RS-EVD-06: a reference shaped like a NIK is refused', function () {
    $settlement = rsStart(rsApproved());
    rsRefused(fn () => rsConfirm($settlement, null, rsBankConfirmData(['disbursement_reference' => '7371012345678901'])), R::REFERENCE_INVALID);
});

// ─── RS-IDEM: idempotency ───────────────────────────────────────────────────

it('RS-IDEM-01: an exact replay of a start returns the original claim, writing nothing', function () {
    $request = rsApproved();
    $data = rsStartData($request);
    $service = app(RmeRefundSettlementService::class);

    $first = $service->start($request->fresh(), test()->settler, $data);
    $events = RmeRefundSettlementEvent::query()->count();
    $replay = $service->start($request->fresh(), test()->settler, $data);

    expect($replay->id)->toBe($first->id)
        ->and(RmeRefundSettlement::query()->count())->toBe(1)
        ->and(RmeRefundSettlementEvent::query()->count())->toBe($events);
});

it('RS-IDEM-02: the same intent with a different method or by another settler is refused', function () {
    $request = rsApproved();
    $data = rsStartData($request);
    $service = app(RmeRefundSettlementService::class);
    $service->start($request->fresh(), test()->settler, $data);

    rsRefused(fn () => $service->start($request->fresh(), test()->settler, array_merge($data, ['method' => M::CASH])), R::INTENT_REUSED);
    rsRefused(fn () => $service->start($request->fresh(), test()->settler2, $data), R::INTENT_REUSED);
});

it('RS-IDEM-03: a forged or foreign intent is refused before anything is written', function () {
    $request = rsApproved();
    $other = rsApproved('71');

    rsRefused(fn () => rsStart($request, null, M::BANK_TRANSFER, [RmeRefundSettlementIntent::FIELD => 'nonsense']), R::INTENT_INVALID);
    rsRefused(fn () => rsStart($request, null, M::BANK_TRANSFER, [RmeRefundSettlementIntent::FIELD => RmeRefundSettlementIntent::issue((int) $other->id)]), R::INTENT_INVALID);
    expect(RmeRefundSettlement::query()->count())->toBe(0);
});

it('RS-IDEM-04: a confirm repeated after it committed is a no-op returning the settled row', function () {
    $settlement = rsStart(rsApproved());
    $evidence = rsPdf('a.pdf', 'replay');
    $settled = rsConfirm($settlement, null, null, $evidence);
    $events = RmeRefundSettlementEvent::query()->count();

    $again = rsConfirm($settled, null, null, rsPdf('a.pdf', 'replay'));
    expect($again->id)->toBe($settled->id)
        ->and($again->status)->toBe(S::SETTLED)
        ->and(RmeRefundSettlementEvent::query()->count())->toBe($events)
        ->and(count(Storage::disk(RmeRefundSettlementEvidence::DISK)->allFiles()))->toBe(1);

    // A different confirmation on a settled row is not a replay.
    rsRefused(fn () => rsConfirm($settled, null, rsBankConfirmData(['disbursement_reference' => 'TRF-BCA-OTHER-0009']), rsPdf('z.pdf', 'other')), R::INVALID_TRANSITION);
});

it('RS-IDEM-05: the method is fixed at start — a cash confirmation of a bank claim is refused', function () {
    $settlement = rsStart(rsApproved(), null, M::BANK_TRANSFER);
    rsRefused(fn () => rsConfirm($settlement, null, rsCashConfirmData()), R::DESTINATION_INVALID);
    rsRefused(fn () => rsStart(rsApproved('71'), null, 'CHEQUE'), R::METHOD_INVALID);
});

// ─── RS-ELIG: eligibility re-checked under the locks ───────────────────────

it('RS-ELIG-01: a REQUESTED, rejected or cancelled request cannot be settled', function () {
    [$invoice, $obligation] = rrrObligation();
    $requested = rrrSubmit($invoice, $obligation);
    rsRefused(fn () => rsStart($requested), R::REQUEST_NOT_APPROVED);

    rrrReject($requested);
    rsRefused(fn () => rsStart($requested), R::REQUEST_NOT_APPROVED);
});

it('RS-ELIG-02: an active billing dispute refuses the start and the confirmation, keeping the claim held', function () {
    $request = rsApproved();
    $invoice = RmeInvoice::query()->findOrFail($request->rme_invoice_id);
    $settlement = rsStart($request);

    rbdReport($invoice);
    rsRefused(fn () => rsConfirm($settlement), R::INVOICE_DISPUTED);
    expect($settlement->fresh()->status)->toBe(S::DISBURSING);

    $other = rsApproved('71');
    rbdReport(RmeInvoice::query()->findOrFail($other->rme_invoice_id));
    rsRefused(fn () => rsStart($other), R::INVOICE_DISPUTED);
});

it('RS-ELIG-03: a ledger that no longer explains the obligation refuses the settlement (fail closed)', function () {
    $request = rsApproved();
    // A payment amount rewritten behind the services' back breaks the ledger identity.
    DB::table('trx_rme_payments')->where('rme_invoice_id', $request->rme_invoice_id)->update(['amount' => '501000.00']);

    rsRefused(fn () => rsStart($request), R::REQUEST_STALE);
    expect(RmeRefundSettlement::query()->count())->toBe(0);
});

it('RS-ELIG-04: the settlement amount is server-derived; a malformed declaration is refused', function () {
    $request = rsApproved();
    rsRefused(fn () => rsStart($request, null, M::BANK_TRANSFER, ['amount' => 'abc']), R::AMOUNT_MISMATCH);
    rsRefused(fn () => rsStart($request, null, M::BANK_TRANSFER, ['amount' => '-150000']), R::AMOUNT_MISMATCH);
    rsRefused(fn () => rsStart($request, null, M::BANK_TRANSFER, ['amount' => null]), R::AMOUNT_MISMATCH);
});
