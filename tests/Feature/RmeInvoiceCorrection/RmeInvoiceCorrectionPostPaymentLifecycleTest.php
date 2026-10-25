<?php

/*
| FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the post-payment correction
| lifecycle through the SERVICE: the type derived from the ledger, the second
| switch (PP-1), the mandatory owner approval reference (PP-2/PP-7), the three
| financial outcomes (PP-3 downward → OPEN obligation, PP-4 upward → outstanding,
| equal → balanced), receipt snapshots (PP-5), lab work (PP-8), and every
| invariant that must hold whatever the outcome: no payment row written or
| changed, no negative receivable, no refund, no lab candidate, no visit change.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeInvoiceDocumentVersion;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligationEvent;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmeReceiptSnapshot;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionReadinessService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmeReceiptSnapshotService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/postpayment-helpers.php';

beforeEach(function () {
    ricSetup();
    rppFlag(true);
});

/** Every payment row of the invoice exactly as stored, to prove nothing touched it. */
function rppPaymentRows(RmeInvoice $invoice): array
{
    return DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->orderBy('id')->get()
        ->map(fn ($row) => (array) $row)->all();
}

// ─── Switches and type ──────────────────────────────────────────────────────

it('refuses a correction of a paid invoice while the post-payment switch is off, writing nothing', function () {
    rppFlag(false);
    $invoice = rppPaidInvoice(200000);

    rppExpectRefusal(fn () => rppSubmit($invoice), 'CORRECTION_TYPE_NOT_ENABLED');

    expect(RmeInvoiceCorrection::count())->toBe(0)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_SUBMITTED))->toHaveCount(0);
});

it('never lets the pre-payment switch alone reach a paid invoice, even with the type list untouched', function () {
    // The ENABLED list stays PRE_PAYMENT-only; post-payment asks its own switch.
    expect(RmeInvoiceCorrectionType::ENABLED)->toBe([RmeInvoiceCorrectionType::PRE_PAYMENT]);

    rppFlag(false);
    $invoice = rppPaidInvoice(500000);
    rppExpectRefusal(fn () => rppSubmit($invoice), 'CORRECTION_TYPE_NOT_ENABLED');
});

it('refuses post-payment correction when the main switch is off even if the post-payment switch is on', function () {
    ricFlag(false);
    $invoice = ricInvoice();
    ricFlag(true);
    ricPay($invoice, 200000);
    ricFlag(false);

    rppExpectRefusal(fn () => rppSubmit($invoice->fresh()), 'rme_invoice_correction');
});

it('derives the type from the ledger and ignores a submitted type', function () {
    $invoice = rppPaidInvoice(200000);

    $correction = rppSubmit($invoice, extra: ['correction_type' => RmeInvoiceCorrectionType::PRE_PAYMENT]);

    expect($correction->correction_type)->toBe(RmeInvoiceCorrectionType::POST_PAYMENT)
        ->and($correction->invoice_status_at_request)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($correction->paid_payment_count_at_request)->toBe(1)
        ->and((string) $correction->paid_total_at_request)->toBe('200000.00');
});

it('keeps an unpaid invoice on the pre-payment path, with the reference optional', function () {
    $invoice = ricInvoice();

    $withoutReference = rppSubmit($invoice, reference: null);
    expect($withoutReference->correction_type)->toBe(RmeInvoiceCorrectionType::PRE_PAYMENT)
        ->and($withoutReference->owner_approval_reference)->toBeNull();
});

it('stores a reference given for a pre-payment correction', function () {
    $correction = rppSubmit(ricInvoice(), reference: rppReference('07'));

    expect($correction->correction_type)->toBe(RmeInvoiceCorrectionType::PRE_PAYMENT)
        ->and($correction->owner_approval_reference)->toBe(rppReference('07'));
});

// ─── Owner approval reference (PP-2 / PP-7, rule 202) ───────────────────────

it('requires the owner approval reference for a post-payment correction', function () {
    $invoice = rppPaidInvoice(200000);

    rppExpectRefusal(fn () => rppSubmit($invoice, reference: null), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REQUIRED');
    rppExpectRefusal(fn () => rppSubmit($invoice, reference: '   '), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REQUIRED');

    expect(RmeInvoiceCorrection::count())->toBe(0);
});

it('refuses a malformed reference instead of dropping it', function (string $reference) {
    $invoice = rppPaidInvoice(200000);

    rppExpectRefusal(fn () => rppSubmit($invoice, reference: $reference), 'CORRECTION_OWNER_APPROVAL_REFERENCE_INVALID');
    expect(RmeInvoiceCorrection::count())->toBe(0);
})->with([
    'too short' => ['OWN-1'],
    'thirteen digit run (NIK-shaped)' => ['REF-7371010101900'],
    'space inside' => ['OWNER COR 01'],
    'forbidden character' => ['OWNER<script>01'],
    'too long' => [str_repeat('A', 121)],
    // Security review: a NIK split by the allowed punctuation is still a NIK.
    'NIK split by dashes' => ['REF-3171-0123-4567-8901'],
    'NIK split by a dot' => ['REF-317101234567.8901'],
    'NIK split by slashes and colons' => ['REF:3171/0123:4567/8901'],
]);

it('accepts references whose numbers are an invoice number or a date, not an identity number', function (string $reference) {
    $invoice = rppPaidInvoice(200000);

    expect(rppSubmit($invoice, reference: $reference)->owner_approval_reference)->toBe($reference);
})->with([
    'invoice number shaped (12 digits in one run)' => ['OWNER-RME-202610-000042'],
    'date then sequence, separated by letters' => ['OWN/2026-10-25/KASUS/0042'],
]);

it('refuses a reference already recorded on a post-payment correction of ANOTHER invoice, case-insensitively', function () {
    $first = rppPaidInvoice(200000);
    rppSubmit($first, reference: rppReference('11'));

    $second = rppPaidInvoice(200000);
    rppExpectRefusal(fn () => rppSubmit($second, reference: rppReference('11')), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED');
    rppExpectRefusal(fn () => rppSubmit($second, reference: strtolower(rppReference('11'))), 'CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED');

    expect(RmeInvoiceCorrection::where('rme_invoice_id', $second->id)->count())->toBe(0);
});

it('lets the same invoice reuse its own reference after the earlier request was cancelled', function () {
    $invoice = rppPaidInvoice(200000);
    $first = rppSubmit($invoice, reference: rppReference('12'));
    app(RmeInvoiceCorrectionService::class)->cancel($first->fresh(), test()->maker, 'Salah baris, diajukan ulang.');

    $again = rppSubmit($invoice->fresh(), reference: rppReference('12'));

    expect($again->owner_approval_reference)->toBe(rppReference('12'))
        ->and($again->id)->not->toBe($first->id);
});

it('stores the trimmed reference on the correction', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice, reference: '  '.rppReference('09').'  ');

    expect($correction->owner_approval_reference)->toBe(rppReference('09'));
});

// ─── PP-3: downward correction below the money received ──────────────────────

it('applies a downward correction of a fully paid invoice as an OPEN overpayment obligation, moving no money', function () {
    $invoice = rppPaidInvoice(500000);
    $visitStatus = $invoice->clinicVisit->fresh()->status;
    $paymentsBefore = rppPaymentRows($invoice);

    $correction = rppSubmit($invoice); // Tambal 200.000 → 50.000, total 350.000
    $applied = ricApprove($correction);

    $invoice->refresh();
    $obligation = RmeOverpaymentObligation::firstOrFail();

    expect($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((string) $invoice->grand_total)->toBe('350000.00')
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((int) $invoice->correction_version)->toBe(1)
        ->and((string) $applied->outstanding_after)->toBe('0.00')
        ->and((string) $applied->overpaid_after)->toBe('150000.00')
        ->and($applied->invoice_status_after)->toBe(RmeInvoice::STATUS_PAID)
        // The obligation.
        ->and($obligation->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN)
        ->and((string) $obligation->amount)->toBe('150000.00')
        ->and((int) $obligation->source_correction_id)->toBe((int) $correction->id)
        ->and((int) $obligation->invoice_version)->toBe(1)
        ->and($obligation->obligation_number)->toStartWith('RMEOVP-')
        ->and(RmeOverpaymentObligationEvent::where('rme_overpayment_obligation_id', $obligation->id)->count())->toBe(1)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_OVERPAYMENT_OBLIGATION_OPENED))->toHaveCount(1)
        // Nothing moved: payment rows byte-identical, no new payment, no refund.
        ->and(rppPaymentRows($invoice))->toBe($paymentsBefore)
        ->and(RmePayment::count())->toBe(1)
        // The visit is not reopened and no lab work is generated.
        ->and($invoice->clinicVisit->fresh()->status)->toBe($visitStatus)
        ->and(LabCaseCandidate::count())->toBe(0)
        // Frozen documents.
        ->and(RmeInvoiceDocumentVersion::where('rme_invoice_id', $invoice->id)->count())->toBe(2);

    $audit = ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED)->last();
    expect($audit->new_values['correction_type'])->toBe(RmeInvoiceCorrectionType::POST_PAYMENT)
        ->and($audit->new_values['invoice_status_before'])->toBe(RmeInvoice::STATUS_PAID)
        ->and($audit->new_values['invoice_status_after'])->toBe(RmeInvoice::STATUS_PAID)
        ->and((float) $audit->new_values['overpaid_after'])->toBe(150000.0)
        ->and((int) $audit->new_values['overpayment_obligation_id'])->toBe((int) $obligation->id)
        ->and($audit->new_values['owner_approval_reference'])->toBe(rppReference());
});

it('turns a partial invoice into a settled one with an obligation when the new total drops below the money received', function () {
    $invoice = rppPaidInvoice(400000); // PARTIAL, 100.000 still due

    $applied = ricApprove(rppSubmit($invoice)); // new total 350.000

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((string) $applied->outstanding_after)->toBe('0.00')
        ->and((string) $applied->overpaid_after)->toBe('50000.00')
        ->and((string) RmeOverpaymentObligation::firstOrFail()->amount)->toBe('50000.00');
});

it('settles a partial invoice exactly, with no obligation, when the new total equals the money received', function () {
    $invoice = rppPaidInvoice(350000);

    $applied = ricApprove(rppSubmit($invoice)); // new total 350.000

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((string) $applied->outstanding_after)->toBe('0.00')
        ->and((string) $applied->overpaid_after)->toBe('0.00')
        ->and(RmeOverpaymentObligation::count())->toBe(0);
});

it('keeps a partial invoice partial when the reduced total is still above the money received', function () {
    $invoice = rppPaidInvoice(200000);

    $applied = ricApprove(rppSubmit($invoice)); // new total 350.000

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and((string) $applied->outstanding_after)->toBe('150000.00')
        ->and((string) $applied->overpaid_after)->toBe('0.00')
        ->and(RmeOverpaymentObligation::count())->toBe(0)
        ->and($invoice->fresh()->remainingAmount())->toBe(150000.0);
});

// ─── PP-4: upward correction, and the balanced case ─────────────────────────

it('reopens a balance on a paid invoice when the total goes up, without reopening the visit', function () {
    $invoice = rppPaidInvoice(500000);
    $visitStatus = $invoice->clinicVisit->fresh()->status;

    $applied = ricApprove(rppSubmit($invoice, rppLines($invoice, '400000', '200000'))); // total 600.000

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and((string) $applied->outstanding_after)->toBe('100000.00')
        ->and((string) $applied->overpaid_after)->toBe('0.00')
        ->and(RmeOverpaymentObligation::count())->toBe(0)
        ->and($invoice->clinicVisit->fresh()->status)->toBe($visitStatus)
        ->and(RmePayment::count())->toBe(1);

    // The new balance is collected by the ordinary payment path.
    ricPay($invoice->fresh(), 100000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($invoice->fresh()->remainingAmount())->toBe(0.0);
});

it('keeps a fully paid invoice paid with no balance either way when the total is unchanged', function () {
    $invoice = rppPaidInvoice(500000);

    $applied = ricApprove(rppSubmit($invoice, rppLines($invoice, '200000', '300000'))); // still 500.000

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((string) $applied->outstanding_after)->toBe('0.00')
        ->and((string) $applied->overpaid_after)->toBe('0.00')
        ->and(RmeOverpaymentObligation::count())->toBe(0)
        ->and((int) $invoice->fresh()->correction_version)->toBe(1);
});

it('refuses a post-payment correction to a Rp0 total', function () {
    $invoice = rppPaidInvoice(500000);

    rppExpectRefusal(fn () => rppSubmit($invoice, rppLines($invoice, '0', '0')), 'CORRECTION');
    expect(RmeInvoiceCorrection::count())->toBe(0);
});

// ─── Guards ─────────────────────────────────────────────────────────────────

it('refuses the next correction while an obligation is OPEN', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));

    rppExpectRefusal(fn () => rppSubmit($invoice->fresh(), rppLines($invoice, '300000', '100000'), reference: rppReference('02')), 'CORRECTION_OVERPAYMENT_OBLIGATION_OPEN');
});

it('refuses an invoice that already generated lab work (PP-8 a)', function () {
    $invoice = rppPaidInvoice(500000);
    LabCaseCandidate::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'rme_invoice_item_id' => $invoice->items()->first()->id,
        'branch_id' => $invoice->branch_id,
        'patient_id' => $invoice->patient_id,
        'clinic_visit_id' => $invoice->clinic_visit_id,
    ]);

    rppExpectRefusal(fn () => rppSubmit($invoice), 'CORRECTION_LAB_CANDIDATES_EXIST');
});

it('never generates lab work itself, even when it settles an invoice that carries a lab treatment (PP-8 b)', function () {
    test()->treatment2->forceFill(['requires_lab' => true])->save();
    $invoice = rppPaidInvoice(400000); // PARTIAL: the payment path generated nothing

    ricApprove(rppSubmit($invoice)); // settles at 350.000

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(LabCaseCandidate::count())->toBe(0);
});

it('refuses an invoice of a cancelled visit', function () {
    $invoice = rppPaidInvoice(200000);
    ClinicVisit::query()->whereKey($invoice->clinic_visit_id)->update(['status' => ClinicVisit::STATUS_CANCELLED]);

    rppExpectRefusal(fn () => rppSubmit($invoice), 'CORRECTION_VISIT_CANCELLED');
});

it('refuses an invoice whose visit is still waiting at the cashier, so a correction never strands a Kontrol visit', function () {
    // A Kontrol visit waits at cashier_pending while its own invoice is PARTIAL and
    // only the payment path completes it; a correction that settled the invoice
    // would leave it there for good (security review).
    $invoice = rppPaidInvoice(200000);
    ClinicVisit::query()->whereKey($invoice->clinic_visit_id)->update(['status' => ClinicVisit::STATUS_CASHIER_PENDING]);

    rppExpectRefusal(fn () => rppSubmit($invoice), 'CORRECTION_VISIT_NOT_COMPLETED');
    expect(RmeInvoiceCorrection::count())->toBe(0);
});

it('fails closed on an invoice whose visit is missing', function () {
    $invoice = rppPaidInvoice(200000);
    ClinicVisit::query()->whereKey($invoice->clinic_visit_id)->delete();

    rppExpectRefusal(fn () => rppSubmit($invoice), 'CORRECTION_VISIT_NOT_COMPLETED');
});

it('goes STALE at approval when the visit was cancelled after submission, changing nothing', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice);
    $before = $invoice->fresh()->only(['grand_total', 'status', 'correction_version']);
    ClinicVisit::query()->whereKey($invoice->clinic_visit_id)->update(['status' => ClinicVisit::STATUS_CANCELLED]);

    rppExpectRefusal(fn () => ricApprove($correction), 'CORRECTION_STALE:VISIT_STATUS_CHANGED');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and($invoice->fresh()->only(['grand_total', 'status', 'correction_version']))->toBe($before)
        ->and(RmeOverpaymentObligation::count())->toBe(0);
});

it('registers the obligation with patient merge, which re-points it without touching its money', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));
    $obligation = RmeOverpaymentObligation::sole();
    $ownership = app(PatientMergeOwnershipRepositoryInterface::class);

    expect(PatientOwnedRecordRegistry::REASSIGN)->toHaveKey('trx_rme_overpayment_obligations')
        ->and($ownership->unregisteredPatientForeignKeys())->not->toContain('trx_rme_overpayment_obligations.patient_id');

    $canonical = Patient::factory()->create();
    expect($ownership->reassign('trx_rme_overpayment_obligations', 'patient_id', [$obligation->id], $canonical->id))->toBe(1);

    $moved = $obligation->fresh();
    expect($moved->patient_id)->toBe($canonical->id)
        ->and((string) $moved->amount)->toBe((string) $obligation->amount)
        ->and($moved->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN)
        ->and($moved->source_correction_id)->toBe($obligation->source_correction_id);
});

it('fails closed on a ledger that does not explain its own status', function () {
    $invoice = rppPaidInvoice(200000); // PARTIAL
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['status' => RmeInvoice::STATUS_PAID]);

    rppExpectRefusal(fn () => rppSubmit($invoice), 'CORRECTION_LEDGER_INCONSISTENT');
});

it('lets the maker neither approve nor apply their own post-payment correction', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice);
    ricDesignateChecker(test()->maker);
    test()->maker->givePermissionTo('approve_rme_invoice_correction');

    try {
        ricApprove($correction, test()->maker);
        test()->fail('self approval must be refused');
    } catch (RmeInvoiceCorrectionDenied $e) {
        expect($e->reasonCode)->toBe(RmeInvoiceCorrectionDenied::SELF_APPROVAL);
    }

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and($invoice->fresh()->correction_version)->toBe(0);
});

it('refuses a post-payment correction outside the pilot allowlist', function () {
    ricPilot([]);
    $invoice = ricInvoice();
    ricPay($invoice, 200000);

    expect(fn () => rppSubmit($invoice->fresh()))->toThrow(RmeInvoiceCorrectionDenied::class);
});

it('refuses approval once the post-payment switch is off, leaving the invoice untouched', function () {
    $invoice = rppPaidInvoice(500000);
    $correction = rppSubmit($invoice);
    rppFlag(false);

    rppExpectRefusal(fn () => ricApprove($correction), 'CORRECTION_TYPE_NOT_ENABLED');

    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and((string) $invoice->fresh()->grand_total)->toBe('500000.00')
        ->and(RmeOverpaymentObligation::count())->toBe(0);
});

it('lets reject and cancel de-escalate after the post-payment switch is off', function () {
    $invoice = rppPaidInvoice(200000);
    $first = rppSubmit($invoice);
    rppFlag(false);

    test()->actingAs(test()->checker);
    app(RmeInvoiceCorrectionService::class)->reject($first, test()->checker, 'Owner membatalkan persetujuan kasus ini.');
    expect($first->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::REJECTED);

    rppFlag(true);
    $second = rppSubmit($invoice, reference: rppReference('03'));
    rppFlag(false);

    test()->actingAs(test()->maker);
    app(RmeInvoiceCorrectionService::class)->cancel($second, test()->maker, 'Pengajuan ditarik oleh kasir.');
    expect($second->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::CANCELLED);
});

it('refuses the legacy recovery apply for a post-payment correction', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = ricLegacyApproved(rppSubmit($invoice));

    rppExpectRefusal(fn () => ricApply($correction), 'CORRECTION_RECOVERY_NOT_SUPPORTED');
    expect($invoice->fresh()->correction_version)->toBe(0);
});

it('blocks payment of the invoice while its post-payment correction is active (D10)', function () {
    $invoice = rppPaidInvoice(200000);
    rppSubmit($invoice);

    expect(fn () => ricPay($invoice->fresh(), 100000))->toThrow(ValidationException::class);
    expect(RmePayment::count())->toBe(1);
});

it('goes STALE when a payment lands between submission and approval by any route', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice);

    // A payment written past D10 (defence in depth).
    RmePayment::factory()->create([
        'branch_id' => $invoice->branch_id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id,
        'patient_id' => $invoice->patient_id,
        'amount' => 50000,
    ]);

    rppExpectRefusal(fn () => ricApprove($correction), 'CORRECTION_STALE:PAYMENT_RECORDED');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and(RmeOverpaymentObligation::count())->toBe(0);
});

it('goes STALE when an OPEN obligation appears on the invoice between submission and approval', function () {
    // Defence in depth: no service path can open an obligation while this
    // correction is active (it blocks every other correction), and an obligation
    // is not part of the invoice fingerprint — so the approval reads it itself,
    // under the lock, rather than trusting that impossibility.
    $invoice = rppPaidInvoice(500000);
    $correction = rppSubmit($invoice);

    RmeOverpaymentObligation::query()->create([
        'uuid' => (string) Str::uuid(),
        'branch_id' => $invoice->branch_id,
        'rme_invoice_id' => $invoice->id,
        'clinic_visit_id' => $invoice->clinic_visit_id,
        'patient_id' => $invoice->patient_id,
        'source_correction_id' => $correction->id,
        'amount' => 10000,
        'invoice_version' => (int) $invoice->correction_version,
        'status' => RmeOverpaymentObligation::STATUS_OPEN,
        'created_by' => test()->checker->id,
    ]);

    rppExpectRefusal(fn () => ricApprove($correction), 'CORRECTION_STALE:OVERPAYMENT_OBLIGATION_OPEN');
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(500000.0);
});

it('replays a double-submitted post-payment correction instead of filing two', function () {
    $invoice = rppPaidInvoice(200000);
    $key = 'rpp-idem-key-0001';

    $first = rppSubmit($invoice, extra: ['idempotency_key' => $key]);
    $second = rppSubmit($invoice, extra: ['idempotency_key' => $key]);

    expect($second->id)->toBe($first->id)
        ->and(RmeInvoiceCorrection::count())->toBe(1);
});

// ─── PP-5: receipt snapshots ────────────────────────────────────────────────

it('issues exactly one immutable receipt snapshot per payment, in the payment transaction', function () {
    $invoice = ricInvoice();
    ricPay($invoice, 200000);
    ricPay($invoice->fresh(), 300000);

    $snapshots = RmeReceiptSnapshot::orderBy('rme_payment_id')->get();
    $payments = RmePayment::orderBy('id')->get();

    expect($snapshots)->toHaveCount(2)
        ->and($snapshots->pluck('rme_payment_id')->all())->toBe($payments->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->and($snapshots[0]->capture_basis)->toBe(RmeReceiptSnapshot::BASIS_ISSUED_AT_PAYMENT)
        ->and($snapshots[0]->invoice_status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($snapshots[1]->invoice_status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(count($snapshots[0]->snapshot['payments']))->toBe(1)
        ->and(count($snapshots[1]->snapshot['payments']))->toBe(2)
        ->and($snapshots[0]->snapshot['totals']['paid_cents'])->toBe(20000000)
        ->and($snapshots[0]->snapshot['receipt']['outstanding_cents'])->toBe(30000000)
        ->and($snapshots[0]->integrityHolds())->toBeTrue()
        ->and($snapshots[1]->integrityHolds())->toBeTrue()
        ->and((int) $snapshots[0]->issued_by)->toBe((int) test()->cashier->id)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_RECEIPT_SNAPSHOT_ISSUED))->toHaveCount(2);
});

it('keeps an issued receipt exactly as it was after the invoice is corrected', function () {
    $invoice = rppPaidInvoice(500000);
    $receipt = RmeReceiptSnapshot::firstOrFail();
    $hashBefore = $receipt->snapshot_sha256;

    ricApprove(rppSubmit($invoice));

    $receipt->refresh();
    expect($receipt->snapshot_sha256)->toBe($hashBefore)
        ->and($receipt->integrityHolds())->toBeTrue()
        ->and($receipt->snapshot['totals']['grand_total_cents'])->toBe(50000000)
        ->and((int) $receipt->invoice_version)->toBe(0)
        ->and(RmeReceiptSnapshot::count())->toBe(1);
});

it('reconstructs, before mutating, the receipt of a payment recorded before receipt snapshots existed', function () {
    $invoice = rppPaidInvoice(500000);
    // A payment from before this release: no receipt snapshot.
    DB::table('trx_rme_receipt_snapshots')->delete();
    $payment = RmePayment::firstOrFail();

    ricApprove(rppSubmit($invoice));

    $receipt = RmeReceiptSnapshot::where('rme_payment_id', $payment->id)->firstOrFail();
    expect($receipt->capture_basis)->toBe(RmeReceiptSnapshot::BASIS_RECONSTRUCTED)
        ->and($receipt->isReconstructed())->toBeTrue()
        ->and($receipt->issued_by)->toBeNull()
        // The PRE-correction document: original items and total, version 0.
        ->and((int) $receipt->invoice_version)->toBe(0)
        ->and($receipt->snapshot['totals']['grand_total_cents'])->toBe(50000000)
        ->and($receipt->invoice_status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($receipt->integrityHolds())->toBeTrue()
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_RECEIPT_SNAPSHOT_RECONSTRUCTED))->toHaveCount(1);

    $audit = ricAudit(RmeFinancialAuditEvent::ACTION_CORRECTION_APPLIED)->last();
    expect((int) $audit->new_values['receipt_snapshots_reconstructed'])->toBe(1);
});

it('reconstructs each legacy receipt with only the payments up to it and the status it had then', function () {
    $invoice = ricInvoice();
    ricPay($invoice, 200000);
    ricPay($invoice->fresh(), 300000); // PAID at 500.000
    DB::table('trx_rme_receipt_snapshots')->delete(); // both from before receipt snapshots existed
    [$first, $second] = RmePayment::orderBy('id')->get()->all();

    ricApprove(rppSubmit($invoice->fresh()));

    $firstReceipt = RmeReceiptSnapshot::where('rme_payment_id', $first->id)->firstOrFail();
    $secondReceipt = RmeReceiptSnapshot::where('rme_payment_id', $second->id)->firstOrFail();

    expect(count($firstReceipt->snapshot['payments']))->toBe(1)
        ->and($firstReceipt->snapshot['totals']['paid_cents'])->toBe(20000000)
        ->and($firstReceipt->invoice_status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($firstReceipt->snapshot['receipt']['outstanding_cents'])->toBe(30000000)
        ->and(count($secondReceipt->snapshot['payments']))->toBe(2)
        ->and($secondReceipt->invoice_status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_RECEIPT_SNAPSHOT_RECONSTRUCTED))->toHaveCount(2);
});

it('reports post-payment as NOT_ACTIVE with its switch off, and every OPEN obligation as a WATCH', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));
    $readiness = app(RmeInvoiceCorrectionReadinessService::class);

    rppFlag(false);
    $report = $readiness->report();
    $codes = array_column($report['findings'], 'code');
    expect($report['post_payment']['state'])->toBe('NOT_ACTIVE')
        ->and($report['post_payment']['flag_enabled'])->toBeFalse()
        ->and($codes)->toContain('OVERPAYMENT_OBLIGATIONS_OPEN')
        ->and($codes)->not->toContain('POSTPAYMENT_FLAG_ON')
        ->and($report['post_payment']['open_overpayment_obligations_by_branch']['IC01']['count'])->toBe(1)
        ->and($report['post_payment']['open_overpayment_obligations_by_branch']['IC01']['amount'])->toBe('150000.00');

    rppFlag(true);
    $on = $readiness->report();
    expect($on['post_payment']['state'])->toBe('ACTIVE')
        ->and(array_column($on['findings'], 'code'))->toContain('POSTPAYMENT_FLAG_ON');
});

it('refuses to issue a second receipt for the same payment', function () {
    $invoice = rppPaidInvoice(200000);
    $payment = RmePayment::firstOrFail();

    DB::transaction(function () use ($invoice, $payment) {
        expect(fn () => app(RmeReceiptSnapshotService::class)
            ->issueForPayment($invoice->fresh(), $payment, test()->cashier))->toThrow(LogicException::class);
    });
});

// The "only inside a transaction" guard of RmeReceiptSnapshotService cannot be
// exercised here: RefreshDatabase wraps every test in a transaction, so the level
// is never 0. It is a programming-error guard (LogicException), not a control a
// request can reach.

// ─── Immutability ───────────────────────────────────────────────────────────

it('never updates or deletes a receipt snapshot, an obligation or an obligation event', function () {
    $invoice = rppPaidInvoice(500000);
    ricApprove(rppSubmit($invoice));

    $receipt = RmeReceiptSnapshot::firstOrFail();
    $obligation = RmeOverpaymentObligation::firstOrFail();
    $event = RmeOverpaymentObligationEvent::firstOrFail();

    expect(fn () => $receipt->forceFill(['invoice_status' => RmeInvoice::STATUS_PARTIAL])->save())->toThrow(LogicException::class)
        ->and(fn () => $receipt->delete())->toThrow(LogicException::class)
        ->and(fn () => $obligation->forceFill(['amount' => '1.00'])->save())->toThrow(LogicException::class)
        ->and(fn () => $obligation->forceFill(['obligation_number' => 'RMEOVP-OTHER'])->save())->toThrow(LogicException::class)
        ->and(fn () => $obligation->delete())->toThrow(LogicException::class)
        ->and(fn () => $event->forceFill(['to_status' => 'SETTLED'])->save())->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});

// ─── Panel ──────────────────────────────────────────────────────────────────

it('offers the post-payment form only when its switch is on, with the reference marked as required', function () {
    $invoice = rppPaidInvoice(200000);
    $service = app(RmeInvoiceCorrectionService::class);

    rppFlag(false);
    $off = $service->panelFor($invoice->fresh()->load('items'), test()->maker);
    expect($off['eligible'])->toBeFalse()
        ->and($off['ineligible_reason'])->toContain('CORRECTION_TYPE_NOT_ENABLED');

    rppFlag(true);
    $on = $service->panelFor($invoice->fresh()->load('items'), test()->maker);
    expect($on['eligible'])->toBeTrue()
        ->and($on['correction_type'])->toBe(RmeInvoiceCorrectionType::POST_PAYMENT)
        ->and($on['requires_owner_approval_reference'])->toBeTrue();
});

it('records the fingerprint of the paid invoice the checker reviews', function () {
    $invoice = rppPaidInvoice(200000);
    $correction = rppSubmit($invoice);

    expect($correction->invoice_fingerprint)
        ->toBe(RmeInvoiceSnapshot::fingerprint(RmeInvoiceSnapshot::capture($invoice->fresh())));
});
