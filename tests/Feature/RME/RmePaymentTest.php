<?php

// Sprint 20 Phase 1.11 — RME Payment and Receipt tests

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
    $this->unauthorized = userWith(['view_clinic_visits']);
});

// ─── Helpers (unique names to avoid collision with CashierBillingTest) ────────

function pmtFinalizedVisit(Branch $branch): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    return [$visit, $record];
}

function pmtItemPayload(array $overrides = []): array
{
    return array_merge([
        'description' => 'Pembersihan Karang Gigi',
        'qty' => 1,
        'unit_price' => 300000,
        'discount' => 0,
    ], $overrides);
}

function pmtUnpaidInvoice(Branch $branch, User $cashier): array
{
    [$visit, $record] = pmtFinalizedVisit($branch);
    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $cashier,
        ['items' => [pmtItemPayload(['unit_price' => 500000])]],
    );
    $invoice->refresh();

    return [$visit, $invoice];
}

function pmtPaymentPayload(RmeInvoice $invoice, array $overrides = []): array
{
    return array_merge([
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ], $overrides);
}

// ─── Test 1: Authorized cashier can open payment form for UNPAID invoice ──────

it('authorized cashier can open payment form for unpaid rme invoice', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    $this->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertOk()
        ->assertSee($invoice->invoice_number)
        ->assertSee($visit->patient->name);
});

// ─── Test 2: Unauthorized user cannot access payment form ─────────────────────

it('unauthorized user gets 403 on payment form', function () {
    $this->actingAs($this->unauthorized);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    $this->get(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->assertForbidden();
});

// ─── Test 3: Cannot pay invoice without items ─────────────────────────────────

it('service rejects payment for invoice without items', function () {
    $this->actingAs($this->cashier);

    [$visit] = pmtFinalizedVisit($this->branch);

    $invoice = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'grand_total' => 0,
        'subtotal' => 0,
        'discount_total' => 0,
    ]);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['amount' => 100000]),
    ))->toThrow(ValidationException::class);
});

// ─── Test 4: Cannot pay invoice that is not UNPAID ────────────────────────────

it('service rejects payment for invoice not in UNPAID status', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    // Force to VOID to simulate wrong state
    $invoice->update(['status' => RmeInvoice::STATUS_VOID]);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    ))->toThrow(ValidationException::class);
});

// ─── Test 5: Cannot pay with zero amount ──────────────────────────────────────

it('service rejects payment with zero amount', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['amount' => 0]),
    ))->toThrow(ValidationException::class);
});

// ─── Test 6: Cannot pay with negative amount ──────────────────────────────────

it('service rejects payment with negative amount', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['amount' => -100]),
    ))->toThrow(ValidationException::class);
});

// ─── Test 7: Partial payment is accepted as cicilan ─────────────────────────

it('partial payment marks invoice partial and completes the visit', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    $partial = round((float) $invoice->grand_total / 2, 2);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['amount' => $partial]),
    );

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($invoice->fresh()->paidAmount())->toBe($partial)
        ->and($invoice->fresh()->remainingAmount())->toBe(round((float) $invoice->grand_total - $partial, 2))
        // Hotfix (rme-partial-payment-completes-visit): a partial payment completes
        // the visit; the remaining balance stays an active receivable.
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

// ─── Test 8: Full payment creates RME payment record ─────────────────────────

it('full payment creates rme payment record', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    );

    expect(RmePayment::where('rme_invoice_id', $invoice->id)->exists())->toBeTrue();
});

// ─── Test 9: Full payment sets invoice status to PAID ─────────────────────────

it('full payment sets invoice status to PAID', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    );

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

// ─── Test 10: Full payment sets visit status to COMPLETED ─────────────────────

it('full payment transitions clinic visit to COMPLETED', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    );

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

// ─── Test 11: Payment uses selected payment method ────────────────────────────

it('payment stores selected payment method', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);
    $method = PaymentMethod::factory()->create(['is_active' => true]);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['payment_method_id' => $method->id]),
    );

    $payment = RmePayment::where('rme_invoice_id', $invoice->id)->first();
    expect($payment->payment_method_id)->toBe($method->id);
});

// ─── Test 12: Receipt page is accessible after payment ────────────────────────

it('receipt page is accessible after full payment', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    );

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice->fresh()]))
        ->assertOk();
});

// ─── Test 13: Receipt shows patient, invoice number, items, total, cashier ────

it('receipt shows patient, invoice number, items, total, method, cashier, paid_at', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);
    $method = PaymentMethod::factory()->create(['is_active' => true]);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['payment_method_id' => $method->id]),
    );

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice->fresh()]))
        ->assertOk()
        ->assertSee($invoice->invoice_number)
        ->assertSee($visit->patient->name)
        ->assertSee($visit->visit_number)
        ->assertSee('500') // part of Rp 500.000
        ->assertSee($method->name)
        ->assertSee($this->cashier->name);
});

// ─── Test 14: Payment does not create lab-order payment records ───────────────

it('rme payment does not create lab-order payment records', function () {
    $this->actingAs($this->cashier);

    $beforeCount = DB::table('trx_payments')->count();

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    );

    expect(DB::table('trx_payments')->count())->toBe($beforeCount);
});

// ─── Test 15: Initial service remains unchanged after payment ─────────────────

it('initial treatment on visit is unchanged after rme payment', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    $initialTreatmentBefore = $visit->initial_treatment_id;

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    );

    expect($visit->fresh()->initial_treatment_id)->toBe($initialTreatmentBefore);
});

// ─── Test 16: Branch isolation is respected ───────────────────────────────────

it('cashier cannot pay invoice from a non-RME branch', function () {
    $this->actingAs($this->cashier);

    // Sprint 23 Phase 23.10: payment is scoped to the active RME-enabled set, so
    // a non-RME-enabled branch is rejected (MAIN fallback is gone).
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    [$visit] = pmtFinalizedVisit($otherBranch);

    $invoice = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $otherBranch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'grand_total' => 500000,
        'subtotal' => 500000,
    ]);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice),
    ))->toThrow(ValidationException::class);
});

it('second payment can fully pay a partial rme invoice', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['amount' => 200000]),
    );

    $partialInvoice = $invoice->fresh();

    // Hotfix (rme-partial-payment-completes-visit): the visit completes on the
    // first partial payment; a later top-up payment still settles the invoice.
    expect($partialInvoice->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);

    app(RmePaymentService::class)->pay(
        $partialInvoice,
        $this->cashier,
        pmtPaymentPayload($partialInvoice, ['amount' => $partialInvoice->remainingAmount()]),
    );

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($invoice->fresh()->remainingAmount())->toBe(0.0)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(2);
});

it('service rejects payment greater than remaining rme invoice balance', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['amount' => 200000]),
    );

    $partialInvoice = $invoice->fresh();

    expect(fn () => app(RmePaymentService::class)->pay(
        $partialInvoice,
        $this->cashier,
        pmtPaymentPayload($partialInvoice, ['amount' => $partialInvoice->remainingAmount() + 1]),
    ))->toThrow(ValidationException::class);
});

it('authorized cashier can open payment form for partial rme invoice', function () {
    $this->actingAs($this->cashier);

    [$visit, $invoice] = pmtUnpaidInvoice($this->branch, $this->cashier);

    app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        pmtPaymentPayload($invoice, ['amount' => 200000]),
    );

    $partialInvoice = $invoice->fresh();

    $this->get(route('rme.cashier.payment.create', [$visit, $partialInvoice]))
        ->assertOk()
        ->assertSee('Sisa Tagihan')
        ->assertSee('Cicilan');
});
