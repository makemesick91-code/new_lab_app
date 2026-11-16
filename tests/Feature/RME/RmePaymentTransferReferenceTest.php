<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 PR8 (memo M5.1) — transfer reference required
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 ("Transfer reference required"): an RME payment by
| Bank Transfer — including an instalment a patient pays by transfer without
| visiting — must carry the transfer's reference number, so the cashier can match
| it to the bank statement. The method's type is read from the payment-method
| record on the server, never from the form. The request refuses a missing
| reference before anything runs; the service's single payment insert re-asserts
| it, so a control-invoice or visit allocation cannot bypass the rule. Other
| methods are unchanged.
|
| Selected by the existing `RmePayment` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `rptr` is unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
    $this->transfer = PaymentMethod::factory()->create(['type' => 'bank_transfer', 'name' => 'Transfer Bank RPTR']);
    $this->cash = PaymentMethod::factory()->create(['type' => 'cash', 'name' => 'Tunai RPTR']);
});

/** @return array{0: ClinicVisit, 1: RmeInvoice} */
function rptrUnpaidInvoice(object $t): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $t->branch->id]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $t->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    rmeSignedConsentFor($visit);

    $invoice = app(RmeInvoiceService::class)->create($visit, $t->cashier, ['items' => [[
        'description' => 'Pembersihan Karang Gigi',
        'qty' => 1,
        'unit_price' => 400000,
        'discount' => 0,
    ]]]);

    return [$visit, $invoice->refresh()];
}

function rptrForm(RmeInvoice $invoice, PaymentMethod $method, ?string $reference, float $amount = 100000): array
{
    $data = [
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ];

    if ($reference !== null) {
        $data['reference_number'] = $reference;
    }

    return rmeFreshPaymentForm($invoice, $data);
}

function rptrStore(object $t, ClinicVisit $visit, RmeInvoice $invoice, array $form)
{
    return $t->actingAs($t->cashier)->post(route('rme.cashier.payment.store', [$visit, $invoice]), $form);
}

it('TR-01 refuses a Bank Transfer payment without a reference and records nothing', function () {
    [$visit, $invoice] = rptrUnpaidInvoice($this);

    rptrStore($this, $visit, $invoice, rptrForm($invoice, $this->transfer, null))
        ->assertSessionHasErrors(['reference_number']);

    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('TR-02 refuses a whitespace-only reference for a Bank Transfer', function () {
    [$visit, $invoice] = rptrUnpaidInvoice($this);

    rptrStore($this, $visit, $invoice, rptrForm($invoice, $this->transfer, '   '))
        ->assertSessionHasErrors(['reference_number']);

    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('TR-03 records a Bank Transfer instalment that carries its reference', function () {
    [$visit, $invoice] = rptrUnpaidInvoice($this);

    rptrStore($this, $visit, $invoice, rptrForm($invoice, $this->transfer, 'TRF-20261117-0042'))
        ->assertSessionHasNoErrors();

    $payment = RmePayment::query()->where('rme_invoice_id', $invoice->id)->sole();

    expect($payment->reference_number)->toBe('TRF-20261117-0042')
        ->and((int) $payment->payment_method_id)->toBe((int) $this->transfer->id)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

it('TR-04 keeps every other method unchanged: cash needs no reference', function () {
    [$visit, $invoice] = rptrUnpaidInvoice($this);

    rptrStore($this, $visit, $invoice, rptrForm($invoice, $this->cash, null))
        ->assertSessionHasNoErrors();

    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

it('TR-05 decides from the server-side method record, never from a client-supplied type', function () {
    [$visit, $invoice] = rptrUnpaidInvoice($this);

    $form = rptrForm($invoice, $this->transfer, null) + ['payment_method_type' => 'cash', 'type' => 'cash'];

    rptrStore($this, $visit, $invoice, $form)->assertSessionHasErrors(['reference_number']);

    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('TR-06 re-asserts the rule in the service, so no payment path can insert a transfer without a reference', function () {
    [, $invoice] = rptrUnpaidInvoice($this);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        rptrForm($invoice, $this->transfer, null),
    ))->toThrow(ValidationException::class);

    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('TR-07 marks the transfer method as needing a reference on the payment form', function () {
    [$visit, $invoice] = rptrUnpaidInvoice($this);

    $html = $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertOk()
        ->getContent();

    expect($html)->toMatch('/value="'.$this->transfer->id.'"[^>]*data-requires-reference="1"/')
        ->and($html)->toMatch('/value="'.$this->cash->id.'"[^>]*data-requires-reference="0"/')
        ->and($html)->toContain('wajib untuk Transfer Bank');
});
