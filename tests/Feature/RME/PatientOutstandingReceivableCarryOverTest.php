<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\Reporting\Services\OwnerDashboardKpiService;
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
    $this->rmeBranch = Branch::factory()->create(['code' => 'POR1', 'is_rme_enabled' => true]);
    $this->nonRmeBranch = Branch::factory()->create(['code' => 'PORN', 'is_rme_enabled' => false]);
    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false]);
    $this->cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $this->patient = Patient::factory()->create([
        'medical_record_number' => 'DG-POR1-2026-0001',
        'branch_id' => $this->rmeBranch->id,
    ]);
});

function porVisit(Patient $patient, Branch $branch, Doctor $doctor, ?string $visitDate = null): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-POR-'.uniqid(),
        'visit_date' => $visitDate ?? now()->toDateString(),
    ]);

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    return $visit;
}

function porInvoice(ClinicVisit $visit, float $amount): RmeInvoice
{
    return app(RmeInvoiceService::class)->create($visit, test()->cashier, [
        'items' => [[
            'description' => 'Tindakan RME',
            'qty' => 1,
            'unit_price' => $amount,
            'discount' => 0,
            'treatment_id' => test()->treatment->id,
        ]],
    ])->refresh();
}

function porPaymentPayload(float $amount, array $overrides = []): array
{
    return array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ], $overrides);
}

// ─── Discovery & display ──────────────────────────────────────────────────────

it('lists a prior outstanding receivable on an ordinary new visit cashier screen, unchecked', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$current, $currentInvoice]))
        ->assertOk()
        ->assertSee('Piutang Sebelumnya')
        ->assertSee($priorInvoice->invoice_number)
        ->assertSee('name="selected_receivable_ids[]"', false);
});

it('shows no outstanding card when the patient has no prior receivables', function () {
    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$current, $currentInvoice]))
        ->assertOk()
        ->assertDontSee('Piutang Sebelumnya');
});

it('excludes paid/void and the current invoice but includes partial receivables', function () {
    $paidVisit = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(9)->toDateString());
    $paidInvoice = porInvoice($paidVisit, 150000);
    app(RmePaymentService::class)->pay($paidInvoice, $this->cashier, porPaymentPayload(150000));

    $partialVisit = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(7)->toDateString());
    $partialInvoice = porInvoice($partialVisit, 200000);
    app(RmePaymentService::class)->pay($partialInvoice, $this->cashier, porPaymentPayload(50000));

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $outstanding = app(RmeControlReceivableService::class)
        ->getOutstandingReceivablesForPatientVisit($current, $currentInvoice);

    expect($outstanding->pluck('id')->all())->toBe([$partialInvoice->id])
        ->and($outstanding->first()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

it('does not list receivables from a non-rme branch', function () {
    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $otherBranchVisit = porVisit($this->patient, $this->nonRmeBranch, $this->doctor, now()->subDays(3)->toDateString());
    RmeInvoice::factory()->unpaid()->create([
        'clinic_visit_id' => $otherBranchVisit->id,
        'branch_id' => $this->nonRmeBranch->id,
        'patient_id' => $this->patient->id,
        'subtotal' => 250000,
        'grand_total' => 250000,
    ]);

    $outstanding = app(RmeControlReceivableService::class)
        ->getOutstandingReceivablesForPatientVisit($current, $currentInvoice);

    expect($outstanding)->toBeEmpty();
});

it('does not list another patient outstanding invoice', function () {
    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $otherPatient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $otherVisit = porVisit($otherPatient, $this->rmeBranch, $this->doctor, now()->subDays(2)->toDateString());
    porInvoice($otherVisit, 300000);

    $outstanding = app(RmeControlReceivableService::class)
        ->getOutstandingReceivablesForPatientVisit($current, $currentInvoice);

    expect($outstanding)->toBeEmpty();
});

it('does not include zero-grand-total or zero-remaining invoices', function () {
    $freeVisit = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(4)->toDateString());
    porInvoice($freeVisit, 0); // grand_total 0

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $outstanding = app(RmeControlReceivableService::class)
        ->getOutstandingReceivablesForPatientVisit($current, $currentInvoice);

    expect($outstanding)->toBeEmpty();
});

// ─── Allocation ───────────────────────────────────────────────────────────────

it('allocates FIFO to selected prior receivable first, then current invoice', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    // Pay 250k against a 200k prior + 100k current = 300k total payable.
    $result = app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(250000),
        [$priorInvoice->id],
    );

    expect($result->allocatedToParent)->toBe(200000.0)
        ->and($result->allocatedToControl)->toBe(50000.0)
        ->and($priorInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($currentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($currentInvoice->fresh()->remainingAmount())->toBe(50000.0)
        ->and($current->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('records one payment per invoice id sharing a payment batch uuid without inflating grand totals', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $result = app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(300000),
        [$priorInvoice->id],
    );

    $batch = RmePayment::where('payment_batch_uuid', $result->paymentBatchUuid)->get();

    expect($batch)->toHaveCount(2)
        ->and($batch->pluck('rme_invoice_id')->sort()->values()->all())
        ->toBe(collect([$priorInvoice->id, $currentInvoice->id])->sort()->values()->all())
        ->and($batch->pluck('payment_batch_uuid')->unique())->toHaveCount(1)
        // grand_total of both invoices stays pure (no merged debt).
        ->and((float) $priorInvoice->fresh()->grand_total)->toBe(200000.0)
        ->and((float) $currentInvoice->fresh()->grand_total)->toBe(100000.0);
});

it('full payment clears both invoices and completes the current visit', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(300000),
        [$priorInvoice->id],
    );

    expect($priorInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($currentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($current->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('partial payment of only the prior receivable completes the current visit and keeps both as piutang', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(80000),
        [$priorInvoice->id],
    );

    expect($priorInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($priorInvoice->fresh()->remainingAmount())->toBe(120000.0)
        ->and($currentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($currentInvoice->fresh()->remainingAmount())->toBe(100000.0)
        ->and($current->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(RmePayment::where('rme_invoice_id', $currentInvoice->id)->count())->toBe(0);
});

it('zero-grand-total current visit can still collect a selected prior receivable and completes', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 0); // free current visit

    app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(200000),
        [$priorInvoice->id],
    );

    expect($priorInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($current->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(RmePayment::where('rme_invoice_id', $currentInvoice->id)->count())->toBe(0);
});

it('rejects an amount greater than the total payable', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(350000),
        [$priorInvoice->id],
    ))->toThrow(ValidationException::class);

    expect(RmePayment::count())->toBe(0);
});

// ─── Security / IDOR ──────────────────────────────────────────────────────────

it('drops a forged receivable id belonging to another patient', function () {
    $otherPatient = Patient::factory()->create(['branch_id' => $this->rmeBranch->id]);
    $otherVisit = porVisit($otherPatient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $otherInvoice = porInvoice($otherVisit, 500000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    // Forging the other patient's invoice id leaves no eligible selection.
    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(100000),
        [$otherInvoice->id],
    ))->toThrow(ValidationException::class);

    expect(RmePayment::where('rme_invoice_id', $otherInvoice->id)->count())->toBe(0)
        ->and($otherInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('drops a forged paid or non-rme-branch receivable id from the selection', function () {
    $paidVisit = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(8)->toDateString());
    $paidInvoice = porInvoice($paidVisit, 150000);
    app(RmePaymentService::class)->pay($paidInvoice, $this->cashier, porPaymentPayload(150000));

    $nonRmeVisit = porVisit($this->patient, $this->nonRmeBranch, $this->doctor, now()->subDays(6)->toDateString());
    $nonRmeInvoice = RmeInvoice::factory()->unpaid()->create([
        'clinic_visit_id' => $nonRmeVisit->id,
        'branch_id' => $this->nonRmeBranch->id,
        'patient_id' => $this->patient->id,
        'subtotal' => 250000,
        'grand_total' => 250000,
    ]);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $summary = app(RmeControlReceivableService::class)
        ->getVisitPayableSummary($currentInvoice, [$paidInvoice->id, $nonRmeInvoice->id]);

    expect($summary['selected_receivables'])->toBeEmpty()
        ->and($summary['selected_remaining'])->toBe(0.0);
});

// ─── HTTP store path ──────────────────────────────────────────────────────────

it('collects a selected prior receivable through the cashier store endpoint', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $this->actingAs($this->cashier)
        ->post(
            route('rme.cashier.payment.store', [$current, $currentInvoice]),
            porPaymentPayload(300000, ['selected_receivable_ids' => [$priorInvoice->id]]),
        )
        ->assertRedirect(route('rme.cashier.receipt.show', [$current, $currentInvoice->fresh()]));

    expect($priorInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($currentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('behaves like the normal plain payment flow when no receivable is selected', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $this->actingAs($this->cashier)
        ->post(
            route('rme.cashier.payment.store', [$current, $currentInvoice]),
            porPaymentPayload(100000),
        )
        ->assertRedirect(route('rme.cashier.receipt.show', [$current, $currentInvoice->fresh()]));

    // The untouched prior receivable stays open; only the current invoice is paid.
    expect($currentInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($priorInvoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(RmePayment::where('rme_invoice_id', $priorInvoice->id)->count())->toBe(0);
});

it('blocks payment when consent is not verified', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(300000, [
            'consent_signed_by_patient' => false,
            'consent_signed_by_doctor' => false,
        ]),
        [$priorInvoice->id],
    ))->toThrow(ValidationException::class);

    expect(RmePayment::count())->toBe(0);
});

it('does not render KTP, NIK, scanned documents or raw medical notes on the cashier screen', function () {
    $this->patient->update([
        'ktp_number' => '1234567890123456',
    ]);

    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$current, $currentInvoice]))
        ->assertOk()
        ->assertDontSee('1234567890123456');
});

// ─── Owner KPI / receivable report ────────────────────────────────────────────

it('reduces the owner KPI active receivable by exactly the collected amount', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    $kpi = app(OwnerDashboardKpiService::class);
    $period = $kpi->resolvePeriod('month', null, null);

    $before = $kpi->metrics(null, $period['from'], $period['to'])['active_receivable'];

    app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(200000),
        [$priorInvoice->id],
    );

    $after = $kpi->metrics(null, $period['from'], $period['to'])['active_receivable'];

    // 200k prior fully collected; current 100k stays piutang → drop is exactly 200k.
    expect(round($before - $after, 2))->toBe(200000.0);
});

it('drops a paid-down prior receivable from the receivable report', function () {
    $prior = porVisit($this->patient, $this->rmeBranch, $this->doctor, now()->subDays(5)->toDateString());
    $priorInvoice = porInvoice($prior, 200000);

    $current = porVisit($this->patient, $this->rmeBranch, $this->doctor);
    $currentInvoice = porInvoice($current, 100000);

    app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        $this->cashier,
        porPaymentPayload(200000),
        [$priorInvoice->id],
    );

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertDontSee($priorInvoice->invoice_number)
        ->assertSee($currentInvoice->invoice_number);
});
