<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->rmeBranch = Branch::factory()->create(['code' => 'COV1', 'is_rme_enabled' => true]);
    $this->nonRmeBranch = Branch::factory()->create(['code' => 'COVN', 'is_rme_enabled' => false]);
    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true, 'requires_lab' => true]);
    $this->cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $this->patient = Patient::factory()->create([
        'medical_record_number' => 'DG-COV1-2026-0001',
        'branch_id' => $this->rmeBranch->id,
    ]);
});

function covItemPayload(float $amount, array $overrides = []): array
{
    return array_merge([
        'description' => 'Tindakan RME',
        'qty' => 1,
        'unit_price' => $amount,
        'discount' => 0,
        'treatment_id' => test()->treatment->id,
    ], $overrides);
}

function covCreateInvoice(ClinicVisit $visit, User $cashier, float $amount): RmeInvoice
{
    return app(RmeInvoiceService::class)->create($visit, $cashier, [
        'items' => [covItemPayload($amount)],
    ])->refresh();
}

function covParentVisit(Patient $patient, Branch $branch, Doctor $doctor): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-P-'.uniqid(),
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    return $visit;
}

function covControlVisit(ClinicVisit $parent, Patient $patient, Branch $branch, Doctor $doctor): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parent->id,
        'visit_number' => 'VIS-C-'.uniqid(),
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    return $visit;
}

function covScenario(float $parentAmount = 300000, float $controlAmount = 100000): array
{
    $parent = covParentVisit(test()->patient, test()->rmeBranch, test()->doctor);
    $parentInvoice = covCreateInvoice($parent, test()->cashier, $parentAmount);
    $control = covControlVisit($parent, test()->patient, test()->rmeBranch, test()->doctor);
    $controlInvoice = covCreateInvoice($control, test()->cashier, $controlAmount);

    return compact('parent', 'parentInvoice', 'control', 'controlInvoice');
}

function covPaymentPayload(float $amount, array $overrides = []): array
{
    return array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ], $overrides);
}

// ─── Summary display / service ───────────────────────────────────────────────

it('control visit with unpaid parent invoice shows carry-over balance', function () {
    ['parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    $summary = app(RmeControlReceivableService::class)->getControlPayableSummary($controlInvoice);

    expect($summary['has_carry_over'])->toBeTrue()
        ->and($summary['carry_over_remaining'])->toBe(300000.0)
        ->and($summary['current_remaining'])->toBe(100000.0)
        ->and($summary['total_payable'])->toBe(400000.0)
        ->and($summary['carry_over_invoices']->first()?->id)->toBe($parentInvoice->id);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.show', [$control, $controlInvoice]))
        ->assertOk()
        ->assertSee('Piutang Kunjungan Sebelumnya')
        ->assertSee('Total Harus Dibayar')
        ->assertSee($parentInvoice->invoice_number);
});

it('control visit with partial parent invoice shows remaining balance', function () {
    ['parent' => $parent, 'parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    app(RmePaymentService::class)->pay($parentInvoice, $this->cashier, covPaymentPayload(100000));

    $summary = app(RmeControlReceivableService::class)->getControlPayableSummary($controlInvoice->fresh());

    expect($summary['carry_over_remaining'])->toBe(200000.0)
        ->and($summary['total_payable'])->toBe(300000.0);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$control, $controlInvoice]))
        ->assertOk()
        ->assertSee('Sisa piutang sebelumnya');
});

it('control visit with paid parent invoice shows zero carry-over', function () {
    ['parentInvoice' => $parentInvoice, 'controlInvoice' => $controlInvoice] = covScenario();

    app(RmePaymentService::class)->pay($parentInvoice, $this->cashier, covPaymentPayload(300000));

    $summary = app(RmeControlReceivableService::class)->getControlPayableSummary($controlInvoice->fresh());

    expect($summary['has_carry_over'])->toBeFalse()
        ->and($summary['carry_over_remaining'])->toBe(0.0)
        ->and($summary['total_payable'])->toBe(100000.0);
});

it('non-control visit does not include carry-over', function () {
    $visit = covParentVisit($this->patient, $this->rmeBranch, $this->doctor);
    $invoice = covCreateInvoice($visit, $this->cashier, 150000);

    $carryOver = app(RmeControlReceivableService::class)->getCarryOverInvoicesForControlVisit($visit);

    expect($carryOver)->toBeEmpty()
        ->and(app(RmeControlReceivableService::class)->hasCarryOver($invoice))->toBeFalse();
});

it('parent invoice from different patient is ignored', function () {
    ['control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    $otherPatient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $otherParent = covParentVisit($otherPatient, $this->rmeBranch, $this->doctor);
    covCreateInvoice($otherParent, $this->cashier, 200000);

    $control->update(['follow_up_of_visit_id' => $otherParent->id]);

    $carryOver = app(RmeControlReceivableService::class)->getCarryOverInvoicesForControlVisit($control->fresh());

    expect($carryOver)->toBeEmpty();
});

it('parent invoice from non-rme branch is ignored', function () {
    $parent = covParentVisit($this->patient, $this->nonRmeBranch, $this->doctor);

    // The cashier service refuses to create an RME invoice in a non-RME branch,
    // so the only way such a record could exist is legacy data. Build it directly
    // to prove the carry-over walk excludes non-RME-branch parents.
    RmeInvoice::factory()->unpaid()->create([
        'clinic_visit_id' => $parent->id,
        'branch_id' => $this->nonRmeBranch->id,
        'patient_id' => $this->patient->id,
        'subtotal' => 250000,
        'grand_total' => 250000,
    ]);

    $control = covControlVisit($parent, $this->patient, $this->rmeBranch, $this->doctor);
    $controlInvoice = covCreateInvoice($control, $this->cashier, 100000);

    $carryOver = app(RmeControlReceivableService::class)->getCarryOverInvoicesForControlVisit($control);

    expect($carryOver)->toBeEmpty()
        ->and(app(RmeControlReceivableService::class)->hasCarryOver($controlInvoice))->toBeFalse();
});

// ─── Payment allocation ──────────────────────────────────────────────────────

it('payment smaller than parent outstanding reduces parent invoice only', function () {
    ['parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    $parentItemCount = $parentInvoice->items()->count();
    $controlItemCount = $controlInvoice->items()->count();

    $result = app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(250000),
    );

    expect($result->allocatedToParent)->toBe(250000.0)
        ->and($result->allocatedToControl)->toBe(0.0)
        ->and($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($parentInvoice->fresh()->remainingAmount())->toBe(50000.0)
        ->and($controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($controlInvoice->fresh()->remainingAmount())->toBe(100000.0)
        ->and($parentInvoice->fresh()->items()->count())->toBe($parentItemCount)
        ->and($controlInvoice->fresh()->items()->count())->toBe($controlItemCount);
});

it('payment equal to parent outstanding pays parent and leaves control unchanged', function () {
    ['parentInvoice' => $parentInvoice, 'controlInvoice' => $controlInvoice] = covScenario();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(300000),
    );

    expect($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($controlInvoice->fresh()->remainingAmount())->toBe(100000.0);
});

it('payment greater than parent outstanding pays parent first and applies remainder to control', function () {
    ['parentInvoice' => $parentInvoice, 'controlInvoice' => $controlInvoice] = covScenario();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(350000),
    );

    expect($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($controlInvoice->fresh()->remainingAmount())->toBe(50000.0);
});

it('payment equal to total payable pays both parent and control invoice', function () {
    ['parent' => $parent, 'parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(400000),
    );

    expect($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($parent->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($control->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('payment greater than total payable is rejected', function () {
    ['controlInvoice' => $controlInvoice] = covScenario();

    expect(fn () => app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(450000),
    ))->toThrow(ValidationException::class);
});

it('payment with no carry-over uses normal existing flow', function () {
    $visit = covParentVisit($this->patient, $this->rmeBranch, $this->doctor);
    $invoice = covCreateInvoice($visit, $this->cashier, 200000);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, covPaymentPayload(200000));

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

it('does not create duplicate patient or mutate old rme records during carry-over payment', function () {
    ['parent' => $parent, 'controlInvoice' => $controlInvoice] = covScenario();

    // covParentVisit() already created the (unique-per-visit) medical record;
    // mark it with a known value so we can assert the old RM is never mutated.
    $parentMr = MedicalRecord::where('clinic_visit_id', $parent->id)->firstOrFail();
    $parentMr->update(['notes' => 'RM lama tidak boleh berubah']);
    $parentOdontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $parent->id,
        'branch_id' => $this->rmeBranch->id,
        'summary_notes' => 'Odontogram lama',
    ]);

    $patientCount = Patient::count();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(250000),
    );

    expect(Patient::count())->toBe($patientCount)
        ->and($parentMr->fresh()->notes)->toBe('RM lama tidak boleh berubah')
        ->and($parentOdontogram->fresh()->summary_notes)->toBe('Odontogram lama');
});

// ─── Status correctness / receivable report ───────────────────────────────────

it('report piutang excludes fully paid parent after allocation', function () {
    ['parentInvoice' => $parentInvoice, 'controlInvoice' => $controlInvoice] = covScenario();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(300000),
    );

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertDontSee($parentInvoice->invoice_number)
        ->assertSee($controlInvoice->invoice_number);
});

it('partial balances remain visible in receivable report', function () {
    ['parentInvoice' => $parentInvoice, 'controlInvoice' => $controlInvoice] = covScenario();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(250000),
    );

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee($parentInvoice->invoice_number)
        ->assertSee($controlInvoice->invoice_number);
});

// ─── Receipt / UI via HTTP ───────────────────────────────────────────────────

it('cashier payment page shows total payable for control carry-over', function () {
    ['control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$control, $controlInvoice]))
        ->assertOk()
        ->assertSee('Piutang Kunjungan Sebelumnya')
        ->assertSee('Total Harus Dibayar');
});

it('receipt shows payment allocation parent and control after full payable payment', function () {
    ['control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$control, $controlInvoice]), covPaymentPayload(400000))
        ->assertRedirect(route('rme.cashier.receipt.show', [$control, $controlInvoice->fresh()]));

    $this->get(route('rme.cashier.receipt.show', [$control, $controlInvoice->fresh()]))
        ->assertOk()
        ->assertSee('Alokasi Pembayaran')
        ->assertSee('Dibayarkan ke tagihan sebelumnya')
        ->assertSee('Dibayarkan ke tagihan kontrol');
});

it('no carry-over case keeps normal receipt layout', function () {
    $visit = covParentVisit($this->patient, $this->rmeBranch, $this->doctor);
    $invoice = covCreateInvoice($visit, $this->cashier, 150000);

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), covPaymentPayload(150000))
        ->assertRedirect(route('rme.cashier.receipt.show', [$visit, $invoice->fresh()]));

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice->fresh()]))
        ->assertOk()
        ->assertDontSee('Dibayarkan ke tagihan sebelumnya');
});

// ─── Lab candidate safety ────────────────────────────────────────────────────

it('split payment does not create duplicate lab candidate', function () {
    ['controlInvoice' => $controlInvoice] = covScenario(300000, 100000);

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(400000),
    );

    $controlItemId = $controlInvoice->fresh()->items->first()->id;

    expect(LabCaseCandidate::where('rme_invoice_item_id', $controlItemId)->count())->toBe(1);
});

it('parent invoice full payment via control remains idempotent for lab candidates', function () {
    ['parentInvoice' => $parentInvoice, 'controlInvoice' => $controlInvoice] = covScenario();

    $service = app(RmePaymentService::class);
    // First the cashier settles the parent receivable via the control screen…
    $service->allocateControlPayment($controlInvoice, $this->cashier, covPaymentPayload(300000));
    // …then pays the remaining control balance. With no carry-over left, this
    // mirrors the controller routing into the normal pay() flow.
    $service->pay($controlInvoice->fresh(), $this->cashier, covPaymentPayload(100000));

    $parentItemId = $parentInvoice->fresh()->items->first()->id;

    expect(LabCaseCandidate::where('rme_invoice_item_id', $parentItemId)->count())->toBe(1);
});

// ─── Chain FIFO (optional safe support) ──────────────────────────────────────

it('supports chained parent carry-over fifo from oldest to newest', function () {
    $grandparent = covParentVisit($this->patient, $this->rmeBranch, $this->doctor);
    $grandparentInvoice = covCreateInvoice($grandparent, $this->cashier, 100000);

    $parent = covControlVisit($grandparent, $this->patient, $this->rmeBranch, $this->doctor);
    $parentInvoice = covCreateInvoice($parent, $this->cashier, 200000);

    $control = covControlVisit($parent, $this->patient, $this->rmeBranch, $this->doctor);
    $controlInvoice = covCreateInvoice($control, $this->cashier, 50000);

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(150000),
    );

    expect($grandparentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($parentInvoice->fresh()->remainingAmount())->toBe(150000.0)
        ->and($controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('rejects http payment above total payable for control carry-over', function () {
    ['control' => $control, 'controlInvoice' => $controlInvoice] = covScenario();

    $this->actingAs($this->cashier)
        ->from(route('rme.cashier.payment.create', [$control, $controlInvoice]))
        ->post(route('rme.cashier.payment.store', [$control, $controlInvoice]), covPaymentPayload(500000))
        ->assertSessionHasErrors('amount');
});

it('stores payment batch uuid for grouped carry-over payments', function () {
    ['controlInvoice' => $controlInvoice] = covScenario();

    $result = app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(350000),
    );

    $batchPayments = RmePayment::where('payment_batch_uuid', $result->paymentBatchUuid)->get();

    expect($batchPayments)->toHaveCount(2)
        ->and($batchPayments->pluck('payment_batch_uuid')->unique())->toHaveCount(1);
});

it('does not create zero-amount payments during allocation', function () {
    ['controlInvoice' => $controlInvoice] = covScenario();

    $beforeCount = RmePayment::count();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(300000),
    );

    expect(RmePayment::count())->toBe($beforeCount + 1);
});

it('parent invoice items remain unchanged after carry-over payment', function () {
    ['parentInvoice' => $parentInvoice, 'controlInvoice' => $controlInvoice] = covScenario();

    $itemsBefore = $parentInvoice->items()->get(['description', 'qty', 'unit_price', 'subtotal'])->toArray();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(250000),
    );

    expect($parentInvoice->fresh()->items()->get(['description', 'qty', 'unit_price', 'subtotal'])->toArray())
        ->toBe($itemsBefore);
});

it('control invoice items remain unchanged after carry-over payment', function () {
    ['controlInvoice' => $controlInvoice] = covScenario();

    $itemsBefore = $controlInvoice->items()->get(['description', 'qty', 'unit_price', 'subtotal'])->toArray();

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(350000),
    );

    expect($controlInvoice->fresh()->items()->get(['description', 'qty', 'unit_price', 'subtotal'])->toArray())
        ->toBe($itemsBefore);
});

// ─── Phase 27.4.1: free follow-up completion rule ────────────────────────────
// A free control visit (control invoice grand_total 0) is modelled with a single
// zero-priced item — the cashier service accepts unit_price 0, so the invoice is
// UNPAID with remaining 0 and reaches the carry-over allocation flow normally.

it('completes a free control visit after a partial parent receivable payment', function () {
    ['parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario(300000, 0);

    $beforePayments = RmePayment::count();

    $result = app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(50000),
    );

    expect($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($parentInvoice->fresh()->remainingAmount())->toBe(250000.0)
        ->and($control->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($result->allocatedToParent)->toBe(50000.0)
        ->and($result->allocatedToControl)->toBe(0.0)
        // Only the parent allocation produced a payment row — no zero-amount control payment.
        ->and(RmePayment::count())->toBe($beforePayments + 1)
        ->and(RmePayment::where('rme_invoice_id', $controlInvoice->id)->count())->toBe(0)
        // Invoice items untouched on both sides.
        ->and($parentInvoice->fresh()->items()->count())->toBe(1)
        ->and($controlInvoice->fresh()->items()->count())->toBe(1);
});

it('completes a free control visit even though the parent invoice stays PARTIAL', function () {
    ['parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario(300000, 0);

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(50000),
    );

    expect($parentInvoice->fresh()->isPaid())->toBeFalse()
        ->and($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($control->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('keeps a control visit cashier_pending when additional treatment is not fully paid', function () {
    ['parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario(300000, 100000);

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(350000),
    );

    expect($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($controlInvoice->fresh()->remainingAmount())->toBe(50000.0)
        ->and($control->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('completes a control visit with additional treatment once its invoice is paid', function () {
    ['parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario(300000, 100000);

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(400000),
    );

    expect($parentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($control->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('does not auto-complete a free control visit when no payment is made', function () {
    ['control' => $control] = covScenario(300000, 0);

    // No allocateControlPayment / store action is invoked at all.
    expect($control->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(RmePayment::where('clinic_visit_id', $control->id)->count())->toBe(0);
});

it('leaves a normal non-control visit cashier_pending on partial payment', function () {
    $visit = covParentVisit($this->patient, $this->rmeBranch, $this->doctor);
    $invoice = covCreateInvoice($visit, $this->cashier, 200000);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, covPaymentPayload(120000));

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('does not let an unpaid parent receivable block control visit completion', function () {
    ['parentInvoice' => $parentInvoice, 'control' => $control, 'controlInvoice' => $controlInvoice] = covScenario(300000, 0);

    app(RmePaymentService::class)->allocateControlPayment(
        $controlInvoice,
        $this->cashier,
        covPaymentPayload(50000),
    );

    // Parent still owes 250k, yet the control visit is done.
    expect($parentInvoice->fresh()->remainingAmount())->toBe(250000.0)
        ->and($parentInvoice->fresh()->isPaid())->toBeFalse()
        ->and($control->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});
