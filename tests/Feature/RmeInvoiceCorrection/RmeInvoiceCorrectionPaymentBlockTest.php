<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — owner decision D10: a pending
| correction blocks payment.
|--------------------------------------------------------------------------
|
| THE CONTRACT. An invoice whose correction is SUBMITTED (pending approval) or
| APPROVED (approved but not applied) accepts NO payment — on any payment path,
| for any invoice the batch would settle. The refusal is decided under the
| invoice row lock in RmePaymentService, rolls the WHOLE batch back, and is
| audited after the rollback. Payment resumes once the correction is REJECTED,
| CANCELLED, STALE or APPLIED — after APPLIED, against the new version only
| (rule 191). The real-concurrency orders are in the PostgreSQL suite.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoicePaymentBlocked;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => ricSetup());

function rpbPay(RmeInvoice $invoice, float $amount, array $extra = []): mixed
{
    test()->actingAs(test()->cashier);

    return app(RmePaymentService::class)->pay($invoice->fresh(), test()->cashier, rmeFreshPaymentForm($invoice->fresh(), array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ], $extra)));
}

function rpbExpectBlocked(callable $work): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey(RmePaymentService::PAYMENT_BLOCKED_FIELD)
            ->and(implode(' ', $e->errors()[RmePaymentService::PAYMENT_BLOCKED_FIELD]))->toContain('['.RmeInvoicePaymentBlocked::REASON.']')
            ->and(implode(' ', $e->errors()[RmePaymentService::PAYMENT_BLOCKED_FIELD]))->toContain('sedang dalam proses koreksi');

        return;
    }

    test()->fail('The payment was accepted; D10 should have blocked it.');
}

function rpbControlChain(): array
{
    $parentVisit = ricVisit();
    $parent = ricInvoice($parentVisit);

    $controlVisit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => test()->branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parentVisit->id,
        'visit_number' => 'VIS-RPB-CTL-'.uniqid(),
        'visit_date' => clinicalToday()->toDateString(),
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $controlVisit->id, 'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id, 'doctor_id' => test()->doctor->id,
    ]);
    rmeSignedConsentFor($controlVisit);

    return [$parent, ricInvoice($controlVisit)];
}

// ─── Blocked states ─────────────────────────────────────────────────────────

it('blocks payment while a correction is SUBMITTED (pending approval)', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    rpbExpectBlocked(fn () => rpbPay($invoice, 100000));

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
});

// REVISED by rule 195 (D1a): an APPROVED-unapplied correction can no longer be
// produced by approve() (which applies); it is built as the pre-D1a legacy shape.
// D10 still blocks payment on such a row.
it('blocks payment while a correction is APPROVED but not yet applied', function () {
    $invoice = ricInvoice();
    ricLegacyApproved(ricSubmit($invoice));

    rpbExpectBlocked(fn () => rpbPay($invoice, 500000));
    expect(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('audits the blocked attempt after the rollback, with ids and codes only', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);

    rpbExpectBlocked(fn () => rpbPay($invoice, 123456, ['notes' => 'Pasien Rahasia Koreksi', 'reference_number' => 'REK-777']));

    $row = ricAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_CORRECTION)->sole();
    expect((int) $row->entity_id)->toBe((int) $invoice->id)
        ->and($row->new_values)->toMatchArray([
            'reason' => RmeInvoicePaymentBlocked::REASON,
            'correction_id' => (int) $correction->id,
            'correction_status' => RmeInvoiceCorrectionStatus::SUBMITTED,
            'invoice_version' => 0,
        ])
        ->and(json_encode($row->new_values))->not->toContain('123456')
        ->and(json_encode($row->new_values))->not->toContain('Rahasia')
        ->and(json_encode($row->new_values))->not->toContain('REK-777');
});

// ─── Resume states ──────────────────────────────────────────────────────────

it('resumes payment after the correction is REJECTED', function () {
    $invoice = ricInvoice();
    $correction = ricSubmit($invoice);
    test()->actingAs(test()->checker);
    app(RmeInvoiceCorrectionService::class)->reject($correction, test()->checker, 'Harga sudah benar sesuai nota.');

    rpbPay($invoice, 500000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

// REVISED by rule 195 (D1a): an APPROVED-unapplied correction can no longer be
// produced by approve() (which applies); it is built as the pre-D1a legacy shape.
// D10 still blocks payment on such a row.
it('resumes payment after the maker CANCELS the correction', function () {
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice));
    test()->actingAs(test()->maker);
    app(RmeInvoiceCorrectionService::class)->cancel($correction, test()->maker, 'Pengajuan dibatalkan oleh kasir.');

    rpbPay($invoice, 500000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

// REVISED by rule 195 (D1a): an APPROVED-unapplied correction can no longer be
// produced by approve() (which applies); it is built as the pre-D1a legacy shape.
// D10 still blocks payment on such a row.
it('resumes payment after the correction goes STALE', function () {
    $invoice = ricInvoice();
    $correction = ricLegacyApproved(ricSubmit($invoice));
    DB::table('trx_rme_invoice_items')->where('id', $invoice->items()->first()->id)->update(['description' => 'Diubah di luar alur']);
    expect(fn () => ricApply($correction))->toThrow(ValidationException::class);
    expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE);

    rpbPay($invoice, 500000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('resumes payment after the correction is APPLIED, against the new version and total only', function () {
    $invoice = ricInvoice();
    ricApply(ricApprove(ricSubmit($invoice)));
    test()->actingAs(test()->cashier);

    // A form rendered before the application is still refused (rule 191)…
    expect(fn () => app(RmePaymentService::class)->pay($invoice->fresh(), test()->cashier, [
        'amount' => 350000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'invoice_versions' => [$invoice->id => 0],
    ]))->toThrow(ValidationException::class);

    // …and a freshly revalidated form settles the corrected total.
    rpbPay($invoice, 350000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((float) RmePayment::where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(350000.0);
});

// ─── Every payment path, atomically ────────────────────────────────────────

it('blocks a control payment when the carry-over parent has an active correction, and posts nothing at all', function () {
    [$parent, $control] = rpbControlChain();
    ricSubmit($parent);

    rpbExpectBlocked(function () use ($control) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->cashier, rmeFreshPaymentForm($control->fresh(), [
            'amount' => 1000000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
    });

    expect(RmePayment::whereIn('rme_invoice_id', [$parent->id, $control->id])->count())->toBe(0)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('blocks a control payment when the control invoice itself has an active correction', function () {
    [$parent, $control] = rpbControlChain();
    ricSubmit($control);

    rpbExpectBlocked(function () use ($control) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->cashier, rmeFreshPaymentForm($control->fresh(), [
            'amount' => 100000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
    });

    expect(RmePayment::whereIn('rme_invoice_id', [$parent->id, $control->id])->count())->toBe(0);
});

it('blocks a visit payment that selects a receivable under correction, without a partial allocation', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    ricSubmit($prior);

    rpbExpectBlocked(function () use ($current, $prior) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), test()->cashier, rmeFreshPaymentForm($current->fresh(), [
            'amount' => 1000000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'selected_receivable_ids' => [$prior->id],
        ]), [$prior->id]);
    });

    expect(RmePayment::whereIn('rme_invoice_id', [$prior->id, $current->id])->count())->toBe(0)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('blocks a visit payment when the CURRENT invoice has an active correction, even with a clean receivable selected', function () {
    // Pins the service check on the anchor invoice of the visit path. Over HTTP
    // the FormRequest would refuse first and hide a missing service check, so
    // this calls the service directly (mutation testing, this sprint).
    $prior = ricInvoice();
    $current = ricInvoice();
    ricSubmit($current);

    rpbExpectBlocked(function () use ($current, $prior) {
        test()->actingAs(test()->cashier);
        app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), test()->cashier, rmeFreshPaymentForm($current->fresh(), [
            'amount' => 1000000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'selected_receivable_ids' => [$prior->id],
        ]), [$prior->id]);
    });

    expect(RmePayment::whereIn('rme_invoice_id', [$prior->id, $current->id])->count())->toBe(0)
        ->and($prior->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('lets an unrelated invoice of the same patient be paid while another is under correction', function () {
    $underCorrection = ricInvoice();
    $other = ricInvoice();
    ricSubmit($underCorrection);

    rpbPay($other, 500000);
    expect($other->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($underCorrection->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

// ─── Flag OFF and the HTTP surface ──────────────────────────────────────────

it('refuses nothing while no correction is active (flag OFF, payments unchanged)', function () {
    ricFlag(false);
    $invoice = ricInvoice();

    rpbPay($invoice, 500000);
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('still blocks a correction left active by a flag that was switched off (resolve before switching off)', function () {
    $invoice = ricInvoice();
    ricSubmit($invoice);
    ricFlag(false);

    rpbExpectBlocked(fn () => rpbPay($invoice, 500000));
});

it('refuses the blocked payment over HTTP with the D10 message, before the amount cap, and audits it', function () {
    $invoice = ricInvoice();
    ricSubmit($invoice);
    ricActingAs(test()->cashier);

    $this->post(route('rme.cashier.payment.store', [$invoice->clinic_visit_id, $invoice]), [
        'amount' => 99_999_999, // would also exceed the balance; the D10 refusal comes first
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => 0],
    ])->assertSessionHasErrors(RmePaymentService::PAYMENT_BLOCKED_FIELD)
        ->assertSessionDoesntHaveErrors('amount');

    expect(RmePayment::count())->toBe(0)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_CORRECTION))->toHaveCount(1);
});

it('explains the block on the payment page and the invoice page and disables the action', function () {
    $invoice = ricInvoice();
    ricSubmit($invoice);
    ricActingAs(test()->cashier);

    $payment = $this->get(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();
    $show = $this->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();

    expect($payment)->toContain('data-payment-blocked-by-correction')
        ->and($payment)->toContain('Pembayaran dapat dilanjutkan setelah proses koreksi selesai.')
        ->and($payment)->toMatch('/<button[^>]*type="submit"[^>]*disabled/')
        ->and($show)->toContain('data-payment-blocked-button')
        ->and($show)->not->toContain(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]));
});

it('renders nothing new on the payment and invoice pages when no correction is active', function () {
    $invoice = ricInvoice();
    ricActingAs(test()->cashier);

    $payment = $this->get(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();
    $show = $this->get(route('rme.cashier.show', [$invoice->clinic_visit_id, $invoice]))->assertOk()->getContent();

    expect($payment)->not->toContain('data-payment-blocked-by-correction')
        ->and($show)->not->toContain('data-payment-blocked-button')
        ->and($show)->toContain(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]));
});
