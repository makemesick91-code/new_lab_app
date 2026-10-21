<?php

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — the correction lifecycle
| through the SERVICE (the layer every caller goes through): submit, maker !=
| checker, approve / reject / cancel, apply, STALE, idempotency, snapshots,
| frozen document versions, audit. HTTP layers are covered in the access suite;
| row-lock behaviour in the PostgreSQL-only concurrency suite.
*/

use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionEvent;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrectionLine;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => ricSetup());

function ricExpectRefusal(callable $call, string $code): void
{
    try {
        $call();
        test()->fail("Expected refusal {$code}");
    } catch (ValidationException $e) {
        expect(json_encode($e->errors()))->toContain($code);
    }
}

// ─── Submission ──────────────────────────────────────────────────────────────

it('submits a pre-payment correction with server-computed totals and an immutable original snapshot', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and($correction->correction_type)->toBe('PRE_PAYMENT')
        ->and($correction->correction_number)->toStartWith('RMECOR-')
        ->and((float) $correction->original_grand_total)->toEqual(500000.0)
        ->and((float) $correction->proposed_grand_total)->toEqual(350000.0)
        ->and((float) $correction->difference_amount)->toEqual(-150000.0)
        ->and((float) $correction->paid_total_at_request)->toEqual(0.0)
        ->and((int) $correction->branch_id)->toBe((int) $invoice->branch_id)
        ->and((int) $correction->invoice_version_at_request)->toBe(0);

    $snapshot = $correction->original_snapshot;
    expect(RmeInvoiceSnapshot::isComplete($snapshot))->toBeTrue()
        ->and($snapshot['totals']['grand_total_cents'])->toBe(50000000)
        ->and($snapshot['items'])->toHaveCount(2)
        ->and($correction->invoice_fingerprint)->toBe(RmeInvoiceSnapshot::fingerprint($snapshot));

    // The invoice itself is untouched by a submission.
    expect((float) $invoice->fresh()->grand_total)->toEqual(500000.0)
        ->and($invoice->fresh()->items()->count())->toBe(2);
});

it('derives line operations server-side and never trusts a submitted total', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[0]['subtotal'] = '1';            // ignored
    // FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 (D21): an added line must carry the
    // branch tariff and no discount, so the addition is 2 × Tambal at its tariff.
    $lines[] = ['description' => 'Kontrol tambahan', 'qty' => 2, 'unit_price' => '50000', 'discount' => '0', 'treatment_id' => test()->treatment2->id];

    $correction = ricSubmit($invoice, lines: $lines);
    $ops = $correction->lines()->pluck('operation')->all();

    expect($ops)->toBe([RmeInvoiceCorrectionLine::OP_KEEP, RmeInvoiceCorrectionLine::OP_CHANGE, RmeInvoiceCorrectionLine::OP_ADD])
        ->and((float) $correction->proposed_grand_total)->toEqual(450000.0); // 300000 + 50000 + 2 × 50000
});

it('records a REMOVE line for an original item left out of the proposal', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice, lines: [ricLinesReducing($invoice)[0]]);

    expect($correction->lines()->pluck('operation')->all())->toBe([RmeInvoiceCorrectionLine::OP_KEEP, RmeInvoiceCorrectionLine::OP_REMOVE])
        ->and((float) $correction->proposed_grand_total)->toEqual(300000.0);
});

it('refuses a correction that changes nothing', function () {
    $invoice = ricInvoice();
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: ricLinesReducing($invoice, '200000')), 'CORRECTION_NO_CHANGE');
    expect(RmeInvoiceCorrection::count())->toBe(0);
});

it('requires a reason of at least ten characters', function () {
    $invoice = ricInvoice();
    ricExpectRefusal(fn () => ricSubmit($invoice, reason: 'singkat'), 'Alasan koreksi');
});

it('refuses an original item that belongs to another invoice', function () {
    $invoice = ricInvoice();
    $other = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[1]['original_item_id'] = $other->items()->first()->id;

    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_ITEM_UNKNOWN');
});

it('refuses a duplicated original item, a negative line and malformed money', function () {
    $invoice = ricInvoice();

    $dup = ricLinesReducing($invoice);
    $dup[1]['original_item_id'] = $dup[0]['original_item_id'];
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $dup), 'CORRECTION_ITEM_DUPLICATED');

    $negative = ricLinesReducing($invoice);
    $negative[1]['discount'] = '999999';
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $negative), 'CORRECTION_LINE_NEGATIVE');

    $bad = ricLinesReducing($invoice);
    $bad[1]['unit_price'] = '-50000';
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $bad), 'CORRECTION_LINE_INVALID');

    $decimals = ricLinesReducing($invoice);
    $decimals[1]['unit_price'] = '100.555';
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $decimals), 'CORRECTION_LINE_INVALID');
});

it('refuses an unknown or inactive treatment on a changed line', function () {
    $invoice = ricInvoice();
    $inactive = Treatment::factory()->create(['is_active' => false]);

    $lines = ricLinesReducing($invoice);
    $lines[1]['treatment_id'] = $inactive->id;
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_TREATMENT_INVALID');

    $lines[1]['treatment_id'] = 999999;
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_TREATMENT_INVALID');
});

it('never creates an Rp0 line or an Rp0 invoice (Rp0 policy undecided)', function () {
    $invoice = ricInvoice();

    $zeroLine = ricLinesReducing($invoice, '0');
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $zeroLine), 'CORRECTION_RP0_NOT_PERMITTED');

    $consultationAsZero = ricLinesReducing($invoice);
    $consultationAsZero[] = ['description' => 'Konsultasi Dokter', 'qty' => 1, 'unit_price' => '0', 'discount' => '0'];
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $consultationAsZero), 'CORRECTION_RP0_NOT_PERMITTED');

    $fullDiscount = ricLinesReducing($invoice);
    $fullDiscount[0]['discount'] = '300000';
    $fullDiscount[1]['discount'] = '200000';
    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $fullDiscount), 'CORRECTION_RP0_NOT_PERMITTED');
});

it('refuses a correction of an invoice that already has a payment (post-payment not enabled)', function () {
    $invoice = ricInvoice();
    ricPay($invoice, 100000);

    ricExpectRefusal(fn () => ricSubmit($invoice->fresh()), 'CORRECTION_TYPE_NOT_ENABLED');
    expect(RmeInvoiceCorrection::count())->toBe(0);
});

it('refuses a second active correction for the same invoice', function () {
    $invoice = ricInvoice();
    ricSubmit($invoice);

    ricExpectRefusal(fn () => ricSubmit($invoice, lines: ricLinesReducing($invoice, '60000')), 'CORRECTION_ALREADY_ACTIVE');
    expect(RmeInvoiceCorrection::count())->toBe(1);
});

it('refuses an invoice that already produced lab case candidates (D23)', function () {
    $invoice = ricInvoice();
    LabCaseCandidate::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'rme_invoice_item_id' => $invoice->items()->first()->id,
        'branch_id' => $invoice->branch_id,
        'patient_id' => $invoice->patient_id,
        'clinic_visit_id' => $invoice->clinic_visit_id,
    ]);

    ricExpectRefusal(fn () => ricSubmit($invoice), 'CORRECTION_LAB_CANDIDATES_EXIST');
});

it('replays an idempotent submission instead of creating a second correction', function () {
    $invoice = ricInvoice();
    test()->actingAs(test()->maker);
    $service = app(RmeInvoiceCorrectionService::class);
    $data = ['reason' => 'Harga tambal salah input oleh kasir.', 'lines' => ricLinesReducing($invoice), 'idempotency_key' => 'abcd-1234-efgh-5678'];

    $first = $service->submit($invoice, test()->maker, $data);
    $second = $service->submit($invoice, test()->maker, $data);

    expect($second->id)->toBe($first->id)
        ->and(RmeInvoiceCorrection::count())->toBe(1);
});

it('refuses every mutation while the capability flag is off', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    ricFlag(false);

    ricExpectRefusal(fn () => ricSubmit($invoice), 'belum diaktifkan');
    ricExpectRefusal(fn () => ricApprove($correction), 'belum diaktifkan');
    ricExpectRefusal(fn () => ricApply($correction), 'belum diaktifkan');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// ─── Maker–checker ──────────────────────────────────────────────────────────

it('refuses self-approval for an account holding both permissions', function () {
    $both = userWith(['request_rme_invoice_correction', 'approve_rme_invoice_correction']);
    // REVISED by rule 197 (owner D1e): only a designated maker may submit, so the
    // dual-permission account is designated as the maker. Self-approval is
    // checked BEFORE identity and keeps its own refusal.
    ricDesignateMaker($both);
    $correction = ricSubmit(ricInvoice(), $both);

    expect(fn () => ricApprove($correction, $both))->toThrow(RmeInvoiceCorrectionDenied::class);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses self-approval even for a Super Admin (Gate::before cannot bypass the service)', function () {
    $admin = superAdmin();
    // REVISED by rule 197 (owner D1e): designated as the maker so it can submit.
    ricDesignateMaker($admin);
    $correction = ricSubmit(ricInvoice(), $admin);

    expect(fn () => ricApprove($correction, $admin))->toThrow(RmeInvoiceCorrectionDenied::class);

    $refused = ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED);
    expect($refused)->toHaveCount(1)
        ->and($refused->first()->new_values['code'])->toBe(RmeInvoiceCorrectionDenied::SELF_APPROVAL)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

it('refuses approval by an account without the approve permission', function () {
    $correction = ricSubmit(ricInvoice());
    $intruder = userWith(['request_rme_invoice_correction', 'manage_rme_billing']);

    expect(fn () => ricApprove($correction, $intruder))->toThrow(RmeInvoiceCorrectionDenied::class);
    expect(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED)->first()->new_values['code'])
        ->toBe(RmeInvoiceCorrectionDenied::PERMISSION_MISSING);
});

// REVISED by rule 195 (owner D1a, 2026-10-21): this test used to assert that an
// approval left the invoice untouched until a separate apply. Under D1a the
// checker's approval APPLIES the correction in the same transaction; the audit
// of the approval itself (from SUBMITTED, by the checker) is unchanged.
it('approves, audits and — under owner D1a — applies in the same transaction', function () {
    $invoice = ricInvoice();
    $correction = ricApprove(ricSubmit($invoice));

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $correction->decided_by)->toBe((int) test()->checker->id)
        ->and((int) $correction->applied_by)->toBe((int) test()->checker->id)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(350000.0);

    $row = ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_APPROVED)->sole();
    expect($row->new_values['from_status'])->toBe('SUBMITTED')
        ->and((int) $row->performed_by)->toBe((int) test()->checker->id);
});

// REVISED by rule 195 (D1a): the replayed approval now finds an APPLIED row; it
// is still a no-op — one approval, one application, never a second of either.
it('treats a repeated approval as an idempotent no-op', function () {
    $correction = ricApprove(ricSubmit(ricInvoice()));
    $again = ricApprove($correction);

    expect($again->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_APPROVED))->toHaveCount(1)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED))->toHaveCount(1)
        ->and(RmeInvoiceCorrectionEvent::where('to_status', 'APPROVED')->count())->toBe(1)
        ->and(RmeInvoiceCorrectionEvent::where('to_status', 'APPLIED')->count())->toBe(1);
});

it('rejects with a mandatory reason and a rejected correction can never be applied', function () {
    $correction = ricSubmit(ricInvoice());
    test()->actingAs(test()->checker);
    $service = app(RmeInvoiceCorrectionService::class);

    ricExpectRefusal(fn () => $service->reject($correction, test()->checker, ''), 'Alasan penolakan');

    $rejected = $service->reject($correction, test()->checker, 'Harga sudah benar sesuai tarif.');
    expect($rejected->status)->toBe(RmeInvoiceCorrectionStatus::REJECTED)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_REJECTED))->toHaveCount(1);

    ricExpectRefusal(fn () => ricApply($rejected), 'CORRECTION_NOT_APPROVED');
});

// REVISED by rule 195 (D1a): an APPROVED-unapplied row now only exists from
// before D1a, so the cancellable approved correction is built as that legacy shape.
it('lets only the maker cancel, with a reason, and a cancelled correction cannot be applied', function () {
    $correction = ricLegacyApproved(ricSubmit(ricInvoice()));
    $service = app(RmeInvoiceCorrectionService::class);

    $other = userWith(['request_rme_invoice_correction']);
    test()->actingAs($other);
    expect(fn () => $service->cancel($correction, $other, 'Saya ingin membatalkan ini.'))->toThrow(RmeInvoiceCorrectionDenied::class);

    test()->actingAs(test()->maker);
    $cancelled = $service->cancel($correction, test()->maker, 'Salah memilih tagihan pasien.');
    expect($cancelled->status)->toBe(RmeInvoiceCorrectionStatus::CANCELLED);

    ricExpectRefusal(fn () => ricApply($cancelled), 'CORRECTION_NOT_APPROVED');
});

// ─── Application ────────────────────────────────────────────────────────────

it('applies an approved pre-payment correction atomically: items, totals, version, documents', function () {
    $invoice = ricInvoice();
    $correction = ricApply(ricApprove(ricSubmit($invoice)));
    $invoice = $invoice->fresh(['items']);

    expect($correction->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $correction->applied_invoice_version)->toBe(1)
        ->and((float) $invoice->grand_total)->toEqual(350000.0)
        ->and((float) $invoice->subtotal)->toEqual(350000.0)
        ->and((float) $invoice->discount_total)->toEqual(0.0)
        ->and((int) $invoice->correction_version)->toBe(1)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($invoice->items->sortBy('id')->pluck('subtotal')->map(fn ($v) => (float) $v)->values()->all())->toBe([300000.0, 50000.0]);

    $versions = RmeInvoiceDocumentVersion::where('rme_invoice_id', $invoice->id)->orderBy('version_number')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[0]->version_kind)->toBe('ORIGINAL')
        ->and($versions[0]->snapshot['totals']['grand_total_cents'])->toBe(50000000)
        ->and($versions[0]->integrityHolds())->toBeTrue()
        ->and($versions[1]->version_kind)->toBe('REVISION')
        ->and((int) $versions[1]->source_correction_id)->toBe((int) $correction->id)
        ->and($versions[1]->snapshot['totals']['grand_total_cents'])->toBe(35000000)
        ->and($versions[1]->document_reference)->toBe($invoice->invoice_number.'-REV1');

    expect(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED))->toHaveCount(1)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_DOCUMENT_VERSION_ISSUED))->toHaveCount(2);
});

it('applies removals and additions, and the invoice can then be paid normally', function () {
    $invoice = ricInvoice();
    $lines = [ricLinesReducing($invoice)[0]];
    // D21: the added line is priced at the branch tariff (Tambal 50.000).
    $lines[] = ['description' => 'Konsultasi lanjutan', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0', 'treatment_id' => test()->treatment2->id];

    ricApply(ricApprove(ricSubmit($invoice, lines: $lines)));
    $invoice = $invoice->fresh(['items']);

    expect($invoice->items)->toHaveCount(2)
        ->and((float) $invoice->grand_total)->toEqual(350000.0);

    ricPay($invoice, 350000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(RmePayment::where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(350000);
});

it('treats a repeated application as a no-op: no second revision, no double totals', function () {
    $correction = ricApply(ricApprove(ricSubmit(ricInvoice())));
    ricApply($correction);

    expect(RmeInvoiceDocumentVersion::count())->toBe(2)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED))->toHaveCount(1)
        ->and((int) $correction->invoice->fresh()->correction_version)->toBe(1);
});

/*
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — owner decision D10 now REFUSES a payment
| while a correction is active (RmeInvoiceCorrectionPaymentBlockTest). These two
| tests used to land that payment through the payment service; they now write the
| payment row directly — a writer that bypassed the D10 guard — to keep proving
| the correction side's own defence in depth: a payment that exists makes it STALE.
*/
function ricPaymentRowBypassingTheService(RmeInvoice $invoice, float $amount): void
{
    DB::table('trx_rme_payments')->insert([
        'branch_id' => $invoice->branch_id, 'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id, 'patient_id' => $invoice->patient_id,
        'cashier_id' => test()->cashier->id, 'payment_number' => 'PAY-BYPASS-'.uniqid(),
        'amount' => $amount, 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['status' => RmeInvoice::STATUS_PARTIAL]);
}

// REVISED by rule 195 (D1a): approve() now applies, so the separate apply() path
// is exercised on the only rows it still serves — APPROVED rows that predate D1a.
// The approval-time equivalent lives in RmeInvoiceCorrectionPilotActivationTest.
it('marks a correction STALE when a payment row exists before application (defence behind D10), and applies nothing', function () {
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice));
    ricPaymentRowBypassingTheService($invoice, 100000);

    ricExpectRefusal(fn () => ricApply($correction), 'CORRECTION_STALE:PAYMENT_RECORDED');

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and($correction->fresh()->stale_reason_code)->toBe('PAYMENT_RECORDED')
        ->and((float) $invoice->fresh()->grand_total)->toEqual(500000.0)
        ->and(RmeInvoiceDocumentVersion::count())->toBe(0)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_STALE))->toHaveCount(1);
});

it('marks a correction STALE at approval when the invoice changed since submission', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    ricPaymentRowBypassingTheService($invoice, 50000);

    ricExpectRefusal(fn () => ricApprove($correction), 'CORRECTION_STALE:PAYMENT_RECORDED');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE);
});

// REVISED by rule 195 (D1a): approve() now applies, so the separate apply() path
// is exercised on the only rows it still serves — APPROVED rows that predate D1a.
// The approval-time equivalent lives in RmeInvoiceCorrectionPilotActivationTest.
it('marks a correction STALE when the invoice content changed outside the workflow', function () {
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice));
    // A writer outside the workflow edits an item (the way such a change actually arises).
    DB::table('trx_rme_invoice_items')->where('id', $invoice->items()->first()->id)->update(['description' => 'Diubah di luar alur']);

    ricExpectRefusal(fn () => ricApply($correction), 'CORRECTION_STALE:INVOICE_CONTENT_CHANGED');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE);
});

// REVISED by rule 195 (D1a): approve() now applies, so the separate apply() path
// is exercised on the only rows it still serves — APPROVED rows that predate D1a.
// The approval-time equivalent lives in RmeInvoiceCorrectionPilotActivationTest.
it('marks a correction STALE when the invoice version moved after submission', function () {
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice));
    // Only an applied correction moves the version; a raw bump models a version that
    // moved underneath an active correction (which the one-active rule otherwise forbids).
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['correction_version' => 7]);

    ricExpectRefusal(fn () => ricApply($correction), 'CORRECTION_STALE:INVOICE_VERSION_CHANGED');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and(RmeInvoiceDocumentVersion::count())->toBe(0);
});

it('accepts a second, sequential correction against the revised invoice and versions it v2', function () {
    $invoice = ricInvoice();
    ricApply(ricApprove(ricSubmit($invoice)));

    $revised = $invoice->fresh();
    // D21: a second correction can only stay within tariff authority — here it
    // bills the Tambal line twice at its tariff (300.000 + 2 × 50.000).
    $second = ricApprove(ricSubmit($revised, lines: [
        ricLinesReducing($revised)[0],
        array_merge(ricLinesReducing($revised, '50000')[1], ['qty' => 2]),
    ]));
    expect((int) $second->invoice_version_at_request)->toBe(1);

    $applied = ricApply($second);
    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and(RmeInvoiceDocumentVersion::where('rme_invoice_id', $invoice->id)->orderBy('version_number')->pluck('version_number')->all())->toBe([0, 1, 2])
        ->and(RmeInvoiceDocumentVersion::where('version_number', 0)->count())->toBe(1)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(400000.0);
});

it('keeps the original snapshot readable and unchanged after application', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    $before = $correction->original_snapshot;

    ricApply(ricApprove($correction));

    expect($correction->fresh()->original_snapshot)->toEqual($before)
        ->and(RmeInvoiceDocumentVersion::where('version_number', 0)->sole()->snapshot['items'][1]['subtotal_cents'])->toBe(20000000);
});

// REVISED by rule 195 (D1a): an approval now applies (and freezes v0/v1), so the
// "approved" half of this test is the pre-D1a legacy shape.
it('does not issue a revised document for a pending or approved correction', function () {
    ricSubmit(ricInvoice());
    ricLegacyApproved(ricSubmit(ricInvoice(ricVisit())));

    expect(RmeInvoiceDocumentVersion::count())->toBe(0);
});

// REVISED by rule 195 (D1a): approve() now applies, so the separate apply() path
// is exercised on the only rows it still serves — APPROVED rows that predate D1a.
// The approval-time equivalent lives in RmeInvoiceCorrectionPilotActivationTest.
it('rolls back the whole application when the applied total would differ from the approved total', function () {
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice));
    // Tamper with the approved total (raw, as only a raw writer could).
    DB::table('trx_rme_invoice_corrections')->where('id', $correction->id)->update(['proposed_grand_total' => '1.00', 'difference_amount' => '-499999.00']);

    expect(fn () => ricApply($correction->fresh()))->toThrow(LogicException::class);

    expect((float) $invoice->fresh()->grand_total)->toEqual(500000.0)
        ->and($invoice->fresh()->items()->count())->toBe(2)
        ->and(RmeInvoiceDocumentVersion::count())->toBe(0)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPROVED);
});

// ─── Immutability, audit privacy ────────────────────────────────────────────

it('refuses to change the reviewed content of a correction or delete it', function () {
    $correction = ricSubmit(ricInvoice());

    expect(fn () => $correction->forceFill(['proposed_grand_total' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $correction->fresh()->forceFill(['reason' => 'lain'])->save())->toThrow(LogicException::class)
        ->and(fn () => $correction->fresh()->delete())->toThrow(LogicException::class)
        ->and(fn () => $correction->lines()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => RmeInvoiceCorrectionEvent::first()->forceFill(['to_status' => 'APPLIED'])->save())->toThrow(LogicException::class);
});

it('refuses to edit or delete a frozen document version', function () {
    ricApply(ricApprove(ricSubmit(ricInvoice())));
    $version = RmeInvoiceDocumentVersion::first();

    expect(fn () => $version->forceFill(['document_reference' => 'X'])->save())->toThrow(LogicException::class)
        ->and(fn () => $version->fresh()->delete())->toThrow(LogicException::class);
});

it('keeps patient name, NIK, item descriptions and the free-text reason out of every correction audit payload', function () {
    ricApply(ricApprove(ricSubmit(ricInvoice())));

    $payloads = AuditLog::query()
        ->whereIn('entity_type', [RmeFinancialAuditEvent::ENTITY_CORRECTION, RmeFinancialAuditEvent::ENTITY_DOCUMENT_VERSION])
        ->get()
        ->map(fn ($row) => json_encode([$row->old_values, $row->new_values]))
        ->implode("\n");

    expect($payloads)->not->toContain('Pasien Rahasia Koreksi')
        ->and($payloads)->not->toContain('7371010101900001')
        ->and($payloads)->not->toContain('Tambal komposit')
        ->and($payloads)->not->toContain('salah input')
        ->and($payloads)->not->toContain('DG-IC01-2026-0001');

    $eventMeta = RmeInvoiceCorrectionEvent::all()->map(fn ($e) => json_encode($e->metadata))->implode("\n");
    expect($eventMeta)->not->toContain('Pasien Rahasia Koreksi')->and($eventMeta)->not->toContain('salah input');
});

it('stores no patient identity in the frozen document snapshot', function () {
    ricApply(ricApprove(ricSubmit(ricInvoice())));

    $json = RmeInvoiceDocumentVersion::all()->map(fn ($v) => json_encode($v->snapshot))->implode("\n");
    expect($json)->not->toContain('Pasien Rahasia Koreksi')
        ->and($json)->not->toContain('7371010101900001')
        ->and($json)->not->toContain('DG-IC01-2026-0001');
});

it('never touches a payment row when applying a correction to a later-paid invoice', function () {
    $invoice = ricInvoice();
    ricApply(ricApprove(ricSubmit($invoice)));
    ricPay($invoice, 200000);
    $payment = RmePayment::where('rme_invoice_id', $invoice->id)->sole();
    $before = $payment->only(['amount', 'paid_at', 'payment_batch_uuid', 'branch_id', 'rme_invoice_id']);

    expect($payment->fresh()->only(array_keys($before)))->toEqual($before)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);

    // Once paid, a new pre-payment correction is refused.
    ricExpectRefusal(fn () => ricSubmit($invoice->fresh(), lines: ricLinesReducing($invoice->fresh(), '40000')), 'CORRECTION_TYPE_NOT_ENABLED');
});

// ─── Adversarial-review regressions ─────────────────────────────────────────

it('refuses silently clearing the treatment of an existing item', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[1]['treatment_id'] = null;

    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_TREATMENT_INVALID');
    expect(RmeInvoiceCorrection::count())->toBe(0);
});

it('keeps an existing item on a since-deactivated treatment as an unchanged line', function () {
    $invoice = ricInvoice();
    test()->treatment->forceFill(['is_active' => false])->save();

    $correction = ricSubmit($invoice);
    expect($correction->lines()->orderBy('line_no')->first()->operation)->toBe(RmeInvoiceCorrectionLine::OP_KEEP);
});

it('refuses re-attributing an existing item to another doctor (doctor attribution is undecided policy)', function () {
    $invoice = ricInvoice();
    $other = Doctor::factory()->create();
    $lines = ricLinesReducing($invoice);
    $lines[0]['doctor_id'] = $other->id;

    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_DOCTOR_CHANGE_NOT_PERMITTED');
});

it('refuses an amount that would overflow integer cents with a stable code, not a 500', function () {
    $invoice = ricInvoice();
    $lines = ricLinesReducing($invoice);
    $lines[1]['qty'] = 10000;
    $lines[1]['unit_price'] = '9999999999999';

    ricExpectRefusal(fn () => ricSubmit($invoice, lines: $lines), 'CORRECTION_TOTAL_OVERFLOW');
});

it('refuses an invoice whose total is already negative with a stable code', function () {
    $invoice = ricInvoice();
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['discount_total' => 600000, 'grand_total' => -100000]);

    ricExpectRefusal(fn () => ricSubmit($invoice->fresh()), 'CORRECTION_INVOICE_TOTAL_NEGATIVE');
});

it('refuses an idempotency key already used by another account', function () {
    $invoice = ricInvoice();
    $service = app(RmeInvoiceCorrectionService::class);
    test()->actingAs(test()->maker);
    $service->submit($invoice, test()->maker, ['reason' => 'Harga tambal salah input oleh kasir.', 'lines' => ricLinesReducing($invoice), 'idempotency_key' => 'shared-key-0001']);

    $other = userWith(['request_rme_invoice_correction']);
    test()->actingAs($other);
    ricExpectRefusal(fn () => $service->submit($invoice, $other, ['reason' => 'Pengajuan dengan kunci yang sama.', 'lines' => ricLinesReducing($invoice, '60000'), 'idempotency_key' => 'shared-key-0001']), 'Kunci idempoten sudah dipakai');
    expect(RmeInvoiceCorrection::count())->toBe(1);
});

it('refuses moving a terminal correction status at the model layer', function () {
    $correction = ricApply(ricApprove(ricSubmit(ricInvoice())));

    expect(fn () => $correction->fresh()->forceFill(['status' => RmeInvoiceCorrectionStatus::SUBMITTED])->save())->toThrow(LogicException::class);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED);
});
