<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 — a payment settles the
| invoice version the cashier SAW (rule 191).
|--------------------------------------------------------------------------
|
| THE DEFECT (rule 190 §4a, audit residual L4). A correction can change an
| UNPAID invoice's items and totals. The payment form was not bound to that
| change: a cashier who opened the payment page before a correction was applied
| could submit it afterwards and settle the corrected total while looking at the
| original one. Nothing in the payment path read `correction_version`.
|
| THE CONTRACT. The payment form carries `invoice_versions[<id>] = <version>` for
| every invoice it displays. Under the invoice row lock, the payment service
| refuses — and audits — any payment against an invoice whose current
| `correction_version` differs from the one the form carried, or that the form
| never carried. The whole batch rolls back: no payment, no status change.
|
| WHAT THIS IS NOT. It does not decide owner decision D10 ("does a PENDING
| correction block payment?"). A submitted or approved correction changes
| nothing the cashier sees, so a payment against it is still accepted here —
| and the correction then goes STALE, as before. Binding the form to the version
| is required under EITHER answer to D10, because it is about an APPLIED change.
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => ricSetup());

/** A payment payload as a page rendered RIGHT NOW would submit it. */
function rpvPayload(float $amount, array $versions, array $overrides = []): array
{
    return array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => $versions,
    ], $overrides);
}

/** Apply the canonical reducing correction (500.000 → 350.000) through the real service. */
function rpvApplyCorrection(RmeInvoice $invoice): RmeInvoice
{
    ricApply(ricApprove(ricSubmit($invoice)));

    return $invoice->fresh();
}

/** Expect $work to be refused with the given stable code on `invoice_versions`. */
function rpvExpectRefused(callable $work, string $code): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('invoice_versions')
            ->and(implode(' ', $e->errors()['invoice_versions']))->toContain('['.$code.']');

        return;
    }

    test()->fail('The payment was accepted; it should have been refused with ['.$code.'].');
}

// ─── The form carries the versions ─────────────────────────────────────────

it('renders the version of the invoice being paid into the payment form', function () {
    $invoice = rpvApplyCorrection(ricInvoice());
    ricActingAs(test()->cashier);

    $html = $this->get(route('rme.cashier.payment.create', [$invoice->clinic_visit_id, $invoice]))
        ->assertOk()
        ->getContent();

    expect($invoice->correction_version)->toBe(1)
        ->and($html)->toContain('name="invoice_versions['.$invoice->id.']" value="1"');
});

it('renders the version of every outstanding receivable the cashier may select', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    ricActingAs(test()->cashier);

    $html = $this->get(route('rme.cashier.payment.create', [$current->clinic_visit_id, $current]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('name="invoice_versions['.$current->id.']" value="0"')
        ->and($html)->toContain('name="invoice_versions['.$prior->id.']" value="0"');
});

// ─── HTTP boundary ──────────────────────────────────────────────────────────

it('refuses an HTTP payment whose form carries no invoice version', function () {
    $invoice = ricInvoice();
    ricActingAs(test()->cashier);

    $this->post(route('rme.cashier.payment.store', [$invoice->clinic_visit_id, $invoice]), [
        'amount' => 500000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors('invoice_versions');

    expect(RmePayment::count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('refuses an HTTP payment whose declared versions are malformed', function (string $shape) {
    $invoice = ricInvoice();
    ricActingAs(test()->cashier);

    $versions = match ($shape) {
        'non-numeric key' => ['abc' => 0, $invoice->id => 0],
        'negative version' => [$invoice->id => -1],
        'fractional version' => [$invoice->id => '0.5'],
        'not a list of versions' => [$invoice->id => ['0']],
        'only another invoice' => [999999 => 0],
    };

    $this->post(route('rme.cashier.payment.store', [$invoice->clinic_visit_id, $invoice]), [
        'amount' => 500000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => $versions,
    ])->assertSessionHasErrors('invoice_versions');

    expect(RmePayment::count())->toBe(0);
})->with(['non-numeric key', 'negative version', 'fractional version', 'not a list of versions', 'only another invoice']);

it('records an HTTP payment whose form carries the current version', function () {
    $invoice = ricInvoice();
    ricActingAs(test()->cashier);

    $this->post(route('rme.cashier.payment.store', [$invoice->clinic_visit_id, $invoice]),
        rpvPayload(500000, [$invoice->id => 0]))
        ->assertSessionHasNoErrors();

    expect(RmePayment::count())->toBe(1)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('refuses an HTTP payment from a page opened before a correction was applied', function () {
    $invoice = ricInvoice();
    $staleForm = rpvPayload(500000, [$invoice->id => 0]);

    rpvApplyCorrection($invoice);
    ricActingAs(test()->cashier);

    $response = $this->post(route('rme.cashier.payment.store', [$invoice->clinic_visit_id, $invoice]), $staleForm);

    // The version error is reported — not the misleading "amount exceeds the
    // remaining balance" that the corrected (lower) total would otherwise cause.
    $response->assertSessionHasErrors('invoice_versions')
        ->assertSessionDoesntHaveErrors('amount');
    expect(session('errors')->first('invoice_versions'))->toContain('[INVOICE_VERSION_CHANGED]')
        ->and(RmePayment::count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('accepts the payment once the cashier reloads the page and sees the corrected total', function () {
    $invoice = ricInvoice();
    rpvApplyCorrection($invoice);
    ricActingAs(test()->cashier);

    $this->post(route('rme.cashier.payment.store', [$invoice->clinic_visit_id, $invoice]),
        rpvPayload(350000, [$invoice->id => 1]))
        ->assertSessionHasNoErrors();

    $invoice = $invoice->fresh();
    expect((float) $invoice->grand_total)->toEqual(350000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((float) RmePayment::sum('amount'))->toEqual(350000.0);
});

// ─── The service is the authority, not the request ──────────────────────────

it('refuses a programmatic payment that declares no version', function () {
    $invoice = ricInvoice();
    test()->actingAs(test()->cashier);

    rpvExpectRefused(fn () => app(RmePaymentService::class)->pay($invoice, test()->cashier, [
        'amount' => 500000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ]), 'INVOICE_VERSION_MISSING');

    expect(RmePayment::count())->toBe(0);
});

it('refuses a programmatic payment against a version that is no longer current, and audits the attempt', function () {
    $invoice = rpvApplyCorrection(ricInvoice());
    test()->actingAs(test()->cashier);

    rpvExpectRefused(fn () => app(RmePaymentService::class)->pay($invoice, test()->cashier,
        rpvPayload(350000, [$invoice->id => 0])), 'INVOICE_VERSION_CHANGED');

    $audit = ricAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INVOICE_VERSION);
    expect(RmePayment::count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($audit)->toHaveCount(1)
        ->and($audit->first()->entity_type)->toBe(RmeFinancialAuditEvent::ENTITY_INVOICE)
        ->and((int) $audit->first()->entity_id)->toBe($invoice->id)
        ->and((int) $audit->first()->performed_by)->toBe(test()->cashier->id)
        ->and($audit->first()->new_values)->toMatchArray([
            'invoice_id' => $invoice->id,
            'reason' => 'INVOICE_VERSION_CHANGED',
            'payment_path' => 'single',
            'expected_version' => 0,
            'current_version' => 1,
        ]);
});

it('keeps the refusal audit free of patient identity and free text', function () {
    $invoice = rpvApplyCorrection(ricInvoice());
    test()->actingAs(test()->cashier);

    try {
        app(RmePaymentService::class)->pay($invoice, test()->cashier, rpvPayload(350000, [$invoice->id => 0], [
            'notes' => 'Catatan kasir menyebut Pasien Rahasia Koreksi',
            'reference_number' => 'REF-BANK-7788',
        ]));
    } catch (ValidationException) {
    }

    $payload = json_encode(ricAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INVOICE_VERSION)->first()->new_values);
    expect($payload)->not->toContain('Pasien Rahasia Koreksi')
        ->and($payload)->not->toContain('DG-IC01-2026-0001')
        ->and($payload)->not->toContain('7371010101900001')
        ->and($payload)->not->toContain('REF-BANK-7788')
        ->and($payload)->not->toContain('Catatan kasir');
});

it('ignores versions declared for invoices the payment does not touch', function () {
    $invoice = ricInvoice();
    test()->actingAs(test()->cashier);

    app(RmePaymentService::class)->pay($invoice, test()->cashier,
        rpvPayload(500000, [$invoice->id => 0, 999999 => 7]));

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('accepts a payment while a correction is only SUBMITTED, then the correction goes STALE (D10 is not decided here)', function () {
    $invoice = ricInvoice();
    $correction = ricApprove(ricSubmit($invoice));
    test()->actingAs(test()->cashier);

    app(RmePaymentService::class)->pay($invoice->fresh(), test()->cashier, rpvPayload(100000, [$invoice->id => 0]));

    expect(fn () => ricApply($correction))->toThrow(ValidationException::class)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(500000.0);
});

it('binds payments the same way while the correction capability is switched off', function () {
    $invoice = ricInvoice();
    ricFlag(false);
    test()->actingAs(test()->cashier);

    rpvExpectRefused(fn () => app(RmePaymentService::class)->pay($invoice, test()->cashier, [
        'amount' => 500000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ]), 'INVOICE_VERSION_MISSING');

    app(RmePaymentService::class)->pay($invoice, test()->cashier, rpvPayload(500000, [$invoice->id => 0]));
    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

// ─── Multi-invoice batches ──────────────────────────────────────────────────

it('refuses a whole visit batch when a selected previous receivable was corrected after the page opened', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    $staleVersions = [$prior->id => 0, $current->id => 0];

    rpvApplyCorrection($prior);
    test()->actingAs(test()->cashier);

    rpvExpectRefused(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $current, test()->cashier, rpvPayload(600000, $staleVersions), [$prior->id],
    ), 'INVOICE_VERSION_CHANGED');

    expect(RmePayment::count())->toBe(0)
        ->and($prior->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INVOICE_VERSION)->first()->new_values)
        ->toMatchArray(['invoice_id' => $prior->id, 'payment_path' => 'visit']);
});

it('refuses a visit batch whose form did not carry a version for a selected receivable', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    test()->actingAs(test()->cashier);

    rpvExpectRefused(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $current, test()->cashier, rpvPayload(600000, [$current->id => 0]), [$prior->id],
    ), 'INVOICE_VERSION_MISSING');

    expect(RmePayment::count())->toBe(0);
});

it('records a visit batch whose form carries every current version', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    test()->actingAs(test()->cashier);

    app(RmePaymentService::class)->allocateVisitPayment(
        $current, test()->cashier, rpvPayload(600000, [$prior->id => 0, $current->id => 0]), [$prior->id],
    );

    expect(RmePayment::count())->toBe(2)
        ->and($prior->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

it('refuses a control-visit batch when the parent invoice was corrected after the page opened', function () {
    $parentVisit = ricVisit();
    $parent = ricInvoice($parentVisit);

    $controlVisit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => test()->branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parentVisit->id,
        'visit_number' => 'VIS-IC-CTL-'.uniqid(),
        'visit_date' => clinicalToday()->toDateString(),
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $controlVisit->id,
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
    ]);
    rmeSignedConsentFor($controlVisit);
    $control = ricInvoice($controlVisit);
    $staleVersions = [$parent->id => 0, $control->id => 0];

    rpvApplyCorrection($parent);
    test()->actingAs(test()->cashier);

    rpvExpectRefused(fn () => app(RmePaymentService::class)->allocateControlPayment(
        $control, test()->cashier, rpvPayload(400000, $staleVersions),
    ), 'INVOICE_VERSION_CHANGED');

    expect(RmePayment::count())->toBe(0)
        ->and(ricAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INVOICE_VERSION)->first()->new_values)
        ->toMatchArray(['invoice_id' => $parent->id, 'payment_path' => 'control']);

    app(RmePaymentService::class)->allocateControlPayment(
        $control, test()->cashier, rpvPayload(400000, [$parent->id => 1, $control->id => 0]),
    );
    expect($parent->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((float) RmePayment::sum('amount'))->toEqual(400000.0);
});
