<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — owner OPS-OQ-1:
| an invoice known to be wrong is NOT COLLECTED at that amount (rule 203).
|--------------------------------------------------------------------------
|
| THE CONTRACT. While an invoice has an ACTIVE billing dispute (OPEN,
| UNDER_REVIEW or ESCALATED) it accepts NO payment — on any payment path, for
| any invoice the batch would settle. The refusal is decided under the invoice
| row lock in RmePaymentService (the early FormRequest check is a courtesy),
| rolls the WHOLE batch back (no money is silently redirected to another
| invoice of the batch), and is audited after the rollback, de-duplicated so a
| repeated click cannot flood the trail. The hold is INDEPENDENT of D10: a
| rejected/cancelled/stale/applied correction releases D10, never this hold.
| Only a RESOLVED dispute releases it — and the payment is then still bound to
| the invoice's current version (rule 191). The real-concurrency orders are in
| RmeBillingDisputeConcurrencyTest (PostgreSQL).
*/

use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoicePaymentBlocked;
use App\Modules\RmeInvoice\Support\RmeInvoicePaymentDisputed;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rbdSetup());

// ─── Every active state holds ───────────────────────────────────────────────

it('holds payment while a dispute is OPEN, and records nothing', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 100000));

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

it('holds payment while the dispute is UNDER_REVIEW and while it is ESCALATED', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    $service = app(RmeBillingDisputeService::class);

    $service->startReview($dispute, test()->reviewer);
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));

    $service->escalate($dispute->fresh(), test()->reviewer, 'Perlu keputusan Owner atas koreksi harga.');
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('holds a retried payment exactly like the first attempt', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 250000));

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('holds further collection on a PARTIAL invoice without touching the payment already recorded', function () {
    $invoice = ricInvoice();
    $first = rbdPay($invoice, 100000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);

    rbdReport($invoice);
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 400000));

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->pluck('id')->all())->toBe([(int) $first->id])
        ->and((float) RmePayment::where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(100000.0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

// ─── Every payment path, atomically ────────────────────────────────────────

it('holds a control payment when the carry-over parent is disputed, and posts nothing at all', function () {
    [$parent, $control] = rbdControlChain();
    rbdReport($parent);

    rbdExpectDisputeBlocked(function () use ($control) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->cashier, rmeFreshPaymentForm($control->fresh(), [
            'amount' => 1000000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
    });

    expect(RmePayment::whereIn('rme_invoice_id', [$parent->id, $control->id])->count())->toBe(0)
        ->and($parent->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('holds a control payment when the control invoice itself is disputed, and posts nothing at all', function () {
    [$parent, $control] = rbdControlChain();
    rbdReport($control);

    rbdExpectDisputeBlocked(function () use ($control) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->cashier, rmeFreshPaymentForm($control->fresh(), [
            'amount' => 100000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
    });

    // The parent was NOT paid either: the batch is refused whole, never trimmed.
    expect(RmePayment::whereIn('rme_invoice_id', [$parent->id, $control->id])->count())->toBe(0)
        ->and($parent->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('holds a visit payment that selects a disputed receivable, without a partial allocation', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    rbdReport($prior);

    rbdExpectDisputeBlocked(function () use ($current, $prior) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), test()->cashier, rmeFreshPaymentForm($current->fresh(), [
            'amount' => 1000000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'selected_receivable_ids' => [$prior->id],
        ]), [$prior->id]);
    });

    expect(RmePayment::whereIn('rme_invoice_id', [$prior->id, $current->id])->count())->toBe(0)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('holds a visit payment when the CURRENT invoice is disputed, even with a clean receivable selected', function () {
    // Pins the service check on the anchor invoice; over HTTP the FormRequest
    // would refuse first and hide a missing service check.
    $prior = ricInvoice();
    $current = ricInvoice();
    rbdReport($current);

    rbdExpectDisputeBlocked(function () use ($current, $prior) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), test()->cashier, rmeFreshPaymentForm($current->fresh(), [
            'amount' => 1000000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'selected_receivable_ids' => [$prior->id],
        ]), [$prior->id]);
    });

    expect(RmePayment::whereIn('rme_invoice_id', [$prior->id, $current->id])->count())->toBe(0)
        ->and($prior->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('lets an unrelated invoice of the same patient be paid while another is disputed', function () {
    $disputed = ricInvoice();
    $other = ricInvoice();
    rbdReport($disputed);

    rbdPay($other, 500000);
    expect($other->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($disputed->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

// ─── Audit ──────────────────────────────────────────────────────────────────

it('audits the held attempt after the rollback, with ids and codes only', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 123456, ['notes' => 'Pasien Rahasia Koreksi', 'reference_number' => 'REK-777']));

    $row = rbdAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_DISPUTE)->sole();
    expect((int) $row->entity_id)->toBe((int) $invoice->id)
        ->and((int) $row->performed_by)->toBe((int) test()->cashier->id)
        ->and($row->new_values)->toMatchArray([
            'reason' => RmeInvoicePaymentDisputed::REASON,
            'dispute_id' => (int) $dispute->id,
            'dispute_status' => RmeBillingDisputeStatus::OPEN,
            'invoice_version' => 0,
        ])
        ->and(json_encode($row->new_values))->not->toContain('123456')
        ->and(json_encode($row->new_values))->not->toContain('Rahasia')
        ->and(json_encode($row->new_values))->not->toContain('REK-777');
});

it('does not flood the audit trail when the same cashier retries a held payment', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);

    foreach (range(1, 4) as $attempt) {
        rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));
    }
    expect(rbdAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_DISPUTE))->toHaveCount(1);

    // A different cashier's attempt is its own evidence.
    $other = userWith(['manage_rme_billing', 'view_clinic_visits']);
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000, [], $other));
    expect(rbdAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_DISPUTE))->toHaveCount(2);
});

// ─── Release only by an authorized resolution ──────────────────────────────

it('releases the hold only once the dispute is RESOLVED, then the payment proceeds', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));

    rbdConfirmOriginal($dispute);

    rbdPay($invoice, 500000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((float) RmePayment::where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(500000.0);
});

it('keeps holding after the correction is REJECTED — D10 releases, the dispute does not', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);
    $correction = ricSubmit($invoice);

    test()->actingAs(test()->checker);
    app(RmeInvoiceCorrectionService::class)->reject($correction, test()->checker, 'Owner belum menyetujui koreksi ini.');

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));
    expect(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('keeps holding after the maker CANCELS the correction', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);
    $correction = ricSubmit($invoice);

    test()->actingAs(test()->maker);
    app(RmeInvoiceCorrectionService::class)->cancel($correction, test()->maker, 'Dibatalkan, menunggu Owner.');

    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 500000));
});

it('keeps holding after the correction is APPLIED until the dispute is resolved', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);
    ricApprove(ricSubmit($invoice)); // D1a: approve applies

    expect((int) $invoice->fresh()->correction_version)->toBe(1);
    rbdExpectDisputeBlocked(fn () => rbdPay($invoice, 350000));
});

it('keeps D10 holding when a dispute is resolved while a correction is still active', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    ricSubmit($invoice);

    // A dispute cannot be resolved over a correction in flight…
    expect(fn () => rbdConfirmOriginal($dispute))->toThrow(ValidationException::class);

    // …and D10 still refuses payment on its own.
    try {
        rbdPay($invoice, 500000);
        test()->fail('D10 should still block.');
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()[RmePaymentService::PAYMENT_BLOCKED_FIELD]))->toContain('['.RmeInvoicePaymentBlocked::REASON.']');
    }
});

it('binds the payment to the corrected version after a CORRECTION_APPLIED resolution (rule 191)', function () {
    $invoice = ricInvoice();
    $dispute = rbdReport($invoice);
    ricApprove(ricSubmit($invoice));

    test()->actingAs(test()->reviewer);
    app(RmeBillingDisputeService::class)->resolve($dispute->fresh(), test()->reviewer, [
        'outcome' => RmeBillingDisputeOutcome::CORRECTION_APPLIED,
        'resolution_reason' => 'Koreksi harga tambal sudah diterapkan oleh checker.',
    ]);

    test()->actingAs(test()->cashier);
    // A form rendered before the correction is refused…
    expect(fn () => app(RmePaymentService::class)->pay($invoice->fresh(), test()->cashier, [
        'amount' => 350000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'invoice_versions' => [$invoice->id => 0],
    ]))->toThrow(ValidationException::class);

    // …and a fresh form settles the CORRECTED total.
    rbdPay($invoice, 350000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((float) RmePayment::where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(350000.0);
});

// ─── The HTTP surface ──────────────────────────────────────────────────────

it('refuses the held payment over HTTP with the dispute message, before the amount cap, and audits it', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);
    rbdActingAs(test()->cashier);

    $this->post(route('rme.cashier.payment.store', [$invoice->clinic_visit_id, $invoice]), [
        'amount' => 99_999_999,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => 0],
    ])->assertSessionHasErrors(RmePaymentService::PAYMENT_BLOCKED_FIELD)
        ->assertSessionDoesntHaveErrors('amount');

    expect(RmePayment::count())->toBe(0)
        ->and(rbdAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_DISPUTE))->toHaveCount(1);
});

it('refuses a held receivable over HTTP when the visit form selects it', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    rbdReport($prior);
    rbdActingAs(test()->cashier);

    $this->post(route('rme.cashier.payment.store', [$current->clinic_visit_id, $current]), [
        'amount' => 1000000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'selected_receivable_ids' => [$prior->id],
        'invoice_versions' => [$prior->id => 0, $current->id => 0],
    ])->assertSessionHasErrors(RmePaymentService::PAYMENT_BLOCKED_FIELD);

    expect(RmePayment::count())->toBe(0);
});

it('explains the hold on the payment page and the invoice page and disables the action', function () {
    $invoice = ricInvoice();
    rbdReport($invoice);
    rbdActingAs(test()->cashier);

    $payment = $this->get(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();
    $show = $this->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();

    expect($payment)->toContain('data-payment-held-by-dispute')
        ->and($payment)->toContain('Pembayaran sementara tidak dapat diproses')
        ->and($payment)->toMatch('/<button[^>]*type="submit"[^>]*disabled/')
        ->and($show)->toContain('data-payment-held-by-dispute')
        ->and($show)->toContain('data-payment-blocked-button')
        ->and($show)->not->toContain(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]));
});

it('renders no hold on the payment and invoice pages when no dispute is active', function () {
    $invoice = ricInvoice();
    rbdActingAs(test()->cashier);

    $payment = $this->get(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();
    $show = $this->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();

    expect($payment)->not->toContain('data-payment-held-by-dispute')
        ->and($show)->not->toContain('data-payment-held-by-dispute')
        ->and($show)->toContain(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]));
});
