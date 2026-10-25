<?php

/*
|--------------------------------------------------------------------------
| HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — OD-1 financial ownership
|--------------------------------------------------------------------------
|
| OD-1 lets a patient return for Kontrol at ANOTHER branch. The new visit, its
| invoice and the money taken for it belong to the branch where the care was
| given; the earlier visit's invoice and payments stay with the branch that
| issued them.
|
| Reproduced on the unmodified base (b1e6d3c6): both carry-over discoveries in
| RmeControlReceivableService read EVERY RME branch, and only the CURRENT
| invoice went through RmePaymentService::assertInvoicePayable(). So a Kasir
| working branch B, taking the Kontrol payment, had the cash allocated FIFO
| into branch A's unpaid invoice (recorded on branch A's ledger, by a branch-B
| cashier) and was shown branch A's invoice number and balance on screen —
| exactly the cross-branch financial reach FIX-03 (rule 197) forbids for the
| invoice the cashier opened. Production held 0 such cases (read-only check).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->clinic = Clinic::factory()->create();
    $this->a = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->b = Branch::factory()->create(['code' => 'LDK2', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->doctorA = Doctor::factory()->create();
    $this->doctorB = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false]);

    // Fixture-only billing account (not context-bound): issues the invoices.
    $this->biller = userWith(['manage_rme_billing', 'view_clinic_visits']);

    $this->kasirB = userInRole('Kasir');
    rmeMakeKasirActive($this->kasirB, $this->b);

    $this->patient = Patient::factory()->create(['branch_id' => $this->a->id]);

    // Branch A: the original visit, billed and only partly paid.
    $this->parent = cbkVisit($this->a, $this->doctorA, ClinicVisit::VISIT_TYPE_NEW, null, now()->subDays(10)->toDateString());
    $this->parentInvoice = cbkInvoice($this->parent, 200000);

    // Branch B: the Kontrol, with another doctor.
    $this->control = cbkVisit($this->b, $this->doctorB, ClinicVisit::VISIT_TYPE_CONTROL, $this->parent);
    $this->controlInvoice = cbkInvoice($this->control, 100000);
});

function cbkVisit(Branch $branch, Doctor $doctor, string $type, ?ClinicVisit $parent = null, ?string $date = null): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
        'visit_type' => $type,
        'follow_up_of_visit_id' => $parent?->id,
        'visit_number' => 'VIS-CBK-'.uniqid(),
        'visit_date' => $date ?? now()->toDateString(),
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => $doctor->id,
    ]);

    rmeSignedConsentFor($visit);

    return $visit;
}

function cbkInvoice(ClinicVisit $visit, float $amount): RmeInvoice
{
    return app(RmeInvoiceService::class)->create($visit, test()->biller, [
        'items' => [[
            'description' => 'Tindakan RME',
            'qty' => 1,
            'unit_price' => $amount,
            'discount' => 0,
            'treatment_id' => test()->treatment->id,
        ]],
    ])->refresh();
}

function cbkPayload(RmeInvoice $invoice, float $amount, array $overrides = []): array
{
    return rmeFreshPaymentForm($invoice, array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ], $overrides));
}

function cbkLedger(RmeInvoice $invoice): array
{
    return [
        'invoice' => (array) DB::table('trx_rme_invoices')->where('id', $invoice->id)->first(),
        'payments' => DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

it('bills the Kontrol at the branch where the care was given', function () {
    expect((int) $this->controlInvoice->branch_id)->toBe((int) $this->b->id)
        ->and((int) $this->parentInvoice->branch_id)->toBe((int) $this->a->id);
});

it('does not carry branch A\'s receivable into a Kontrol billed at branch B', function () {
    $summary = app(RmeControlReceivableService::class)->getControlPayableSummary($this->controlInvoice);

    expect($summary['has_carry_over'])->toBeFalse()
        ->and($summary['carry_over_invoices'])->toHaveCount(0)
        ->and($summary['total_payable'])->toBe(100000.0);
});

it('does not offer branch A\'s receivable on a branch-B visit, Kontrol or not', function () {
    $outstanding = app(RmeControlReceivableService::class)
        ->getOutstandingReceivablesForPatientVisit($this->control, $this->controlInvoice);

    expect($outstanding->pluck('id')->all())->not->toContain($this->parentInvoice->id);
});

it('does not show branch A\'s invoice on the branch-B payment screen', function () {
    $this->actingAs($this->kasirB)
        ->get(route('rme.cashier.payment.create', [$this->control, $this->controlInvoice]))
        ->assertOk()
        ->assertSee($this->controlInvoice->invoice_number)
        ->assertDontSee($this->parentInvoice->invoice_number);
});

it('records the branch-B cashier\'s payment on the branch-B invoice only and leaves branch A\'s ledger untouched', function () {
    $ledgerA = cbkLedger($this->parentInvoice);

    $this->actingAs($this->kasirB)
        ->post(
            route('rme.cashier.payment.store', [$this->control, $this->controlInvoice]),
            cbkPayload($this->controlInvoice, 100000),
        )
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $payments = RmePayment::query()->where('cashier_id', $this->kasirB->id)->get();

    expect($payments)->toHaveCount(1)
        ->and((int) $payments->first()->rme_invoice_id)->toBe((int) $this->controlInvoice->id)
        ->and((int) $payments->first()->branch_id)->toBe((int) $this->b->id)
        ->and($this->controlInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(cbkLedger($this->parentInvoice))->toBe($ledgerA);
});

it('drops a crafted branch-A receivable id from a branch-B payment', function () {
    $ledgerA = cbkLedger($this->parentInvoice);

    $this->actingAs($this->kasirB)
        ->post(
            route('rme.cashier.payment.store', [$this->control, $this->controlInvoice]),
            cbkPayload($this->controlInvoice, 100000, ['selected_receivable_ids' => [$this->parentInvoice->id]]),
        )
        ->assertRedirect();

    expect(cbkLedger($this->parentInvoice))->toBe($ledgerA)
        ->and(RmePayment::query()->where('rme_invoice_id', $this->parentInvoice->id)->count())->toBe(0);
});

it('refuses the same cross-branch allocation through a direct service call', function () {
    $ledgerA = cbkLedger($this->parentInvoice);

    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $this->controlInvoice,
        $this->kasirB,
        cbkPayload($this->controlInvoice, 1000),
        [$this->parentInvoice->id],
    ))->toThrow(ValidationException::class);

    expect(cbkLedger($this->parentInvoice))->toBe($ledgerA);
});

it('keeps the branch-B cashier out of branch A\'s invoice altogether', function () {
    $this->actingAs($this->kasirB)
        ->get(route('rme.cashier.show', [$this->parent, $this->parentInvoice]))
        ->assertForbidden();
});

it('still carries a same-branch parent receivable into a Kontrol (regression)', function () {
    $sameBranchParent = cbkVisit($this->b, $this->doctorB, ClinicVisit::VISIT_TYPE_NEW, null, now()->subDays(3)->toDateString());
    $sameBranchInvoice = cbkInvoice($sameBranchParent, 50000);
    $kontrol = cbkVisit($this->b, $this->doctorB, ClinicVisit::VISIT_TYPE_CONTROL, $sameBranchParent);
    $kontrolInvoice = cbkInvoice($kontrol, 70000);

    $summary = app(RmeControlReceivableService::class)->getControlPayableSummary($kontrolInvoice);

    expect($summary['has_carry_over'])->toBeTrue()
        ->and($summary['carry_over_invoices']->pluck('id')->all())->toBe([$sameBranchInvoice->id])
        ->and($summary['total_payable'])->toBe(120000.0);

    $result = app(RmePaymentService::class)->allocateControlPayment(
        $kontrolInvoice,
        $this->kasirB,
        rmeFreshPaymentForm($kontrolInvoice, ['amount' => 120000, 'paid_at' => now()->format('Y-m-d H:i:s')]),
    );

    expect($result->allocatedToParent)->toBe(50000.0)
        ->and($result->allocatedToControl)->toBe(70000.0)
        ->and(RmePayment::query()->where('rme_invoice_id', $sameBranchInvoice->id)->value('branch_id'))->toBe((int) $this->b->id);
});

it('still offers a same-branch receivable on an ordinary visit (regression)', function () {
    $earlier = cbkVisit($this->b, $this->doctorB, ClinicVisit::VISIT_TYPE_NEW, null, now()->subDays(4)->toDateString());
    $earlierInvoice = cbkInvoice($earlier, 30000);

    $outstanding = app(RmeControlReceivableService::class)
        ->getOutstandingReceivablesForPatientVisit($this->control, $this->controlInvoice);

    expect($outstanding->pluck('id')->all())->toBe([$earlierInvoice->id]);
});

/*
| Each defence layer is tested on its own: a discovery that WRONGLY returned a
| branch-A invoice must still not get branch-B money allocated into it.
*/
function cbkLeakyDiscovery(RmeInvoice $leak): RmeControlReceivableService
{
    return new class(app(BranchService::class), $leak) extends RmeControlReceivableService
    {
        public function __construct(BranchService $branches, private RmeInvoice $leak)
        {
            parent::__construct($branches);
        }

        public function getControlPayableSummary(RmeInvoice $controlInvoice): array
        {
            $remaining = $this->getCurrentInvoiceRemaining($this->leak) + $this->getCurrentInvoiceRemaining($controlInvoice);

            return [
                'carry_over_invoices' => collect([$this->leak]),
                'carry_over_remaining' => $this->getCurrentInvoiceRemaining($this->leak),
                'current_invoice' => $controlInvoice,
                'current_remaining' => $this->getCurrentInvoiceRemaining($controlInvoice),
                'total_payable' => round($remaining, 2),
                'has_carry_over' => true,
            ];
        }

        public function getVisitPayableSummary(RmeInvoice $currentInvoice, array $selectedReceivableInvoiceIds = []): array
        {
            $leakRemaining = $this->getCurrentInvoiceRemaining($this->leak);

            return [
                'outstanding_receivables' => collect([$this->leak]),
                'selected_receivables' => collect([$this->leak]),
                'selected_remaining' => $leakRemaining,
                'current_invoice' => $currentInvoice,
                'current_remaining' => $this->getCurrentInvoiceRemaining($currentInvoice),
                'total_payable' => round($leakRemaining + $this->getCurrentInvoiceRemaining($currentInvoice), 2),
                'has_outstanding' => true,
            ];
        }
    };
}

/*
| SUPERSEDED by FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205). These two
| tests used to pin, as the SAFE outcome, that a leaked cross-branch parent was
| silently skipped under the lock and the rest of the money capped: 300.000 was
| submitted and 100.000 recorded. That silent truncation is the defect rule 205
| removes — the cashier is told one amount was received while another is in the
| ledger. A plan invoice that is not collectable under the lock now refuses the
| WHOLE payment ([PAYMENT_PLAN_CHANGED]); the defence the tests exist for — no
| branch-B money ever reaches the branch-A invoice — is asserted unchanged.
*/
it('never allocates control-path money into another branch\'s invoice, even if discovery leaked it', function () {
    app()->instance(RmeControlReceivableService::class, cbkLeakyDiscovery($this->parentInvoice));
    app()->forgetInstance(RmePaymentService::class);
    $ledgerA = cbkLedger($this->parentInvoice);
    $ledgerB = cbkLedger($this->controlInvoice);
    $paymentsBefore = RmePayment::query()->count();

    expect(fn () => app(RmePaymentService::class)->allocateControlPayment(
        $this->controlInvoice,
        $this->kasirB,
        cbkPayload($this->controlInvoice, 300000),
    ))->toThrow(ValidationException::class, '[PAYMENT_PLAN_CHANGED]');

    expect(cbkLedger($this->parentInvoice))->toBe($ledgerA)
        ->and(cbkLedger($this->controlInvoice))->toBe($ledgerB)
        ->and(RmePayment::query()->count())->toBe($paymentsBefore);
});

it('never allocates visit-path money into another branch\'s invoice, even if discovery leaked it', function () {
    app()->instance(RmeControlReceivableService::class, cbkLeakyDiscovery($this->parentInvoice));
    app()->forgetInstance(RmePaymentService::class);
    $ledgerA = cbkLedger($this->parentInvoice);
    $ledgerB = cbkLedger($this->controlInvoice);
    $paymentsBefore = RmePayment::query()->count();

    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $this->controlInvoice,
        $this->kasirB,
        cbkPayload($this->controlInvoice, 300000),
        [$this->parentInvoice->id],
    ))->toThrow(ValidationException::class, '[PAYMENT_PLAN_CHANGED]');

    expect(cbkLedger($this->parentInvoice))->toBe($ledgerA)
        ->and(cbkLedger($this->controlInvoice))->toBe($ledgerB)
        ->and(RmePayment::query()->count())->toBe($paymentsBefore);
});
