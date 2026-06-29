<?php

// Sprint 22 Phase 22.4 — RME → Lab Case Candidate → Lab Order end-to-end validation

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderItem;
use App\Modules\LabOrder\Services\LabCaseCandidateConversionService;
use App\Modules\LabService\Models\LabService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmeLabIntegrationService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branch->update(['is_rme_enabled' => true, 'is_active' => true]);
    $this->doctor = doctorWithOnlineContext($this->branch);
    $this->kasir = userInRole('Kasir');
    $this->adminLab = userInRole('Admin Lab');
    $this->labService = LabService::factory()->create(['is_active' => true, 'price' => 850000]);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function e2eItemPayload(array $overrides = []): array
{
    return array_merge([
        'treatment_id' => null,
        'description' => 'E2E_LAB_CROWN_VALIDATION',
        'qty' => 1,
        'unit_price' => 500000,
        'discount' => 0,
    ], $overrides);
}

function e2ePaymentPayload(RmeInvoice $invoice, array $overrides = []): array
{
    return array_merge([
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ], $overrides);
}

function e2eConversionPayload(LabService $service, array $overrides = []): array
{
    return array_merge([
        'lab_service_id' => $service->id,
        'due_date' => now()->addDays(7)->toDateString(),
        'notes' => 'E2E konversi kandidat RME',
    ], $overrides);
}

/**
 * Visit in_progress with draft MR + handwriting — ready for doctor finalization.
 *
 * @return array{0: ClinicVisit, 1: MedicalRecord}
 */
function e2eVisitReadyForFinalize(Branch $branch): array
{
    $visit = ClinicVisit::factory()->inProgress()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
    ]);

    return [$visit, $record];
}

/**
 * Run finalize → invoice → pay for a lab-required treatment; returns paid invoice context.
 *
 * @return array{0: ClinicVisit, 1: MedicalRecord, 2: RmeInvoice, 3: Treatment}
 */
function e2ePaidLabInvoiceFlow(
    Branch $branch,
    User $doctor,
    User $kasir,
    array $itemOverrides = [],
): array {
    [$visit, $record] = e2eVisitReadyForFinalize($branch);

    app(MedicalRecordService::class)->finalize($record->fresh());

    $treatment = Treatment::factory()->requiresLab()->create(['name' => 'E2E Treatment Lab Required']);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit->fresh(),
        $kasir,
        ['items' => [e2eItemPayload(array_merge(['treatment_id' => $treatment->id], $itemOverrides))]],
    );

    app(RmePaymentService::class)->pay($invoice->fresh(), $kasir, e2ePaymentPayload($invoice->fresh()));

    return [$visit->fresh(), $record->fresh(), $invoice->fresh(), $treatment];
}

// ─── Pilot documentation presence ────────────────────────────────────────────

it('pilot e2e operator checklist and developer notes exist with required sections', function () {
    $operator = file_get_contents(base_path('docs/pilot/rme_lab_candidate_e2e_operator_checklist.md'));
    $developer = file_get_contents(base_path('docs/pilot/rme_lab_candidate_e2e_developer_notes.md'));

    expect($operator)->toContain('RME → Kandidat Lab')
        ->and($operator)->toContain('dokter.smoke@pilot-test.local')
        ->and($operator)->toContain('migrate:fresh')
        ->and($developer)->toContain('RmeLabIntegrationService')
        ->and($developer)->toContain('lab-case-candidates.convert');
});

// ─── Scenario 1: Full happy path ─────────────────────────────────────────────

it('full rme to lab candidate to lab order happy path preserves traceability and finance boundaries', function () {
    $beforeLabInvoices = Invoice::count();
    $beforeLabPayments = DB::table('trx_payments')->count();
    $beforeLabOrders = LabOrder::count();

    [$visit, $record, $invoice, $treatment] = e2ePaidLabInvoiceFlow(
        $this->branch,
        $this->doctor,
        $this->kasir,
    );

    expect($record->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(RmePayment::where('rme_invoice_id', $invoice->id)->count())->toBe(1);

    $item = RmeInvoiceItem::where('rme_invoice_id', $invoice->id)->firstOrFail();
    $candidate = LabCaseCandidate::where('rme_invoice_item_id', $item->id)->firstOrFail();

    expect($candidate->branch_id)->toBe($this->branch->id)
        ->and($candidate->clinic_visit_id)->toBe($visit->id)
        ->and($candidate->patient_id)->toBe($visit->patient_id)
        ->and($candidate->rme_invoice_id)->toBe($invoice->id)
        ->and($candidate->rme_invoice_item_id)->toBe($item->id)
        ->and($candidate->treatment_id)->toBe($treatment->id)
        ->and($candidate->medical_record_id)->toBe($record->id)
        ->and($candidate->status)->toBe(LabCaseCandidate::STATUS_PENDING_REVIEW);

    $this->actingAs($this->adminLab)
        ->post(route('lab-case-candidates.convert', $candidate), e2eConversionPayload($this->labService))
        ->assertRedirect();

    $candidate = $candidate->fresh();
    $order = LabOrder::findOrFail($candidate->converted_lab_order_id);

    expect(LabOrder::count())->toBe($beforeLabOrders + 1)
        ->and(LabOrderItem::count())->toBe(1)
        ->and($candidate->status)->toBe(LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER)
        ->and($candidate->converted_lab_order_id)->toBe($order->id)
        ->and(Invoice::count())->toBe($beforeLabInvoices)
        ->and(DB::table('trx_payments')->count())->toBe($beforeLabPayments);

    $this->actingAs($this->adminLab)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Status Pekerjaan Lab RME')
        ->assertSee('E2E_LAB_CROWN_VALIDATION');

    $this->actingAs($this->adminLab)
        ->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Kandidat Lab RME');

    $this->actingAs($this->adminLab)
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertOk()
        ->assertSee($invoice->invoice_number);

    $this->actingAs($this->adminLab)
        ->get(route('lab-orders.show', $order))
        ->assertOk()
        ->assertSee('Sumber RME')
        ->assertSee('E2E_LAB_CROWN_VALIDATION');
});

// ─── Scenario 2: Idempotency ─────────────────────────────────────────────────

it('candidate generation and conversion are idempotent across repeated calls', function () {
    [$visit, $record, $invoice] = array_slice(
        e2ePaidLabInvoiceFlow($this->branch, $this->doctor, $this->kasir),
        0,
        3,
    );

    $svc = app(RmeLabIntegrationService::class);
    $first = $svc->generateForPaidInvoice($invoice->fresh(), $this->kasir);
    $second = $svc->generateForPaidInvoice($invoice->fresh(), $this->kasir);

    expect($first->count())->toBe(1)
        ->and($second->count())->toBe(1)
        ->and(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(1)
        ->and($first->first()->id)->toBe($second->first()->id);

    $candidate = $first->first();
    $converter = app(LabCaseCandidateConversionService::class);

    $orderFirst = $converter->convertToLabOrder($candidate, e2eConversionPayload($this->labService), $this->adminLab);
    $orderSecond = $converter->convertToLabOrder($candidate->fresh(), e2eConversionPayload($this->labService), $this->adminLab);

    expect($orderFirst->id)->toBe($orderSecond->id)
        ->and(LabOrder::count())->toBe(1)
        ->and(LabOrderItem::count())->toBe(1);
});

// ─── Scenario 3: Non-lab treatment guard ─────────────────────────────────────

it('paid rme invoice without lab-required treatment does not create lab case candidate', function () {
    [$visit, $record] = e2eVisitReadyForFinalize($this->branch);
    app(MedicalRecordService::class)->finalize($record->fresh());

    $treatment = Treatment::factory()->create(['requires_lab' => false]);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit->fresh(),
        $this->kasir,
        ['items' => [e2eItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'E2E_NO_LAB_SCALING',
            'unit_price' => 150000,
        ])]],
    );

    app(RmePaymentService::class)->pay($invoice->fresh(), $this->kasir, e2ePaymentPayload($invoice->fresh()));

    expect(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

// ─── Scenario 4: Partial payment guard ─────────────────────────────────────────

it('partial rme payment marks invoice partial and does not create lab case candidate', function () {
    [$visit, $record] = e2eVisitReadyForFinalize($this->branch);
    app(MedicalRecordService::class)->finalize($record->fresh());

    $treatment = Treatment::factory()->requiresLab()->create();
    $invoice = app(RmeInvoiceService::class)->create(
        $visit->fresh(),
        $this->kasir,
        ['items' => [e2eItemPayload(['treatment_id' => $treatment->id])]],
    );

    $partial = round((float) $invoice->grand_total / 2, 2);

    app(RmePaymentService::class)->pay(
        $invoice->fresh(),
        $this->kasir,
        e2ePaymentPayload($invoice->fresh(), ['amount' => $partial]),
    );

    expect(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

// ─── Scenario 5: Role boundaries ─────────────────────────────────────────────

it('kasir can pay rme invoice but cannot convert lab case candidate', function () {
    [, , $invoice] = e2ePaidLabInvoiceFlow($this->branch, $this->doctor, $this->kasir);
    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->actingAs($this->kasir)
        ->get(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
        ->assertOk();

    $this->actingAs($this->kasir)
        ->get(route('lab-case-candidates.index'))
        ->assertForbidden();

    $this->actingAs($this->kasir)
        ->post(route('lab-case-candidates.convert', $candidate), e2eConversionPayload($this->labService))
        ->assertForbidden();

    expect(LabOrder::count())->toBe(0);
});

it('doctor cannot access cashier billing or lab candidate conversion', function () {
    [, , $invoice] = e2ePaidLabInvoiceFlow($this->branch, $this->doctor, $this->kasir);
    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->actingAs($this->doctor)
        ->get(route('rme.cashier.index'))
        ->assertForbidden();

    $this->actingAs($this->doctor)
        ->get(route('rme.cashier.payment.create', [$invoice->clinicVisit, $invoice]))
        ->assertForbidden();

    $this->actingAs($this->doctor)
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertForbidden();
});

it('admin lab can view and convert candidate while unauthorized user cannot', function () {
    [, , $invoice] = e2ePaidLabInvoiceFlow($this->branch, $this->doctor, $this->kasir);
    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->actingAs($this->adminLab)
        ->get(route('lab-case-candidates.index'))
        ->assertOk()
        ->assertSee('E2E_LAB_CROWN_VALIDATION');

    $this->actingAs($this->adminLab)
        ->post(route('lab-case-candidates.convert', $candidate), e2eConversionPayload($this->labService))
        ->assertRedirect();

    $unauth = User::factory()->create();

    $this->actingAs($unauth)
        ->get(route('lab-case-candidates.index'))
        ->assertForbidden();
});

it('cross-branch user cannot view or convert lab case candidate from another branch', function () {
    $otherBranch = Branch::factory()->create(['is_active' => true]);
    $candidate = LabCaseCandidate::factory()->create([
        'branch_id' => $otherBranch->id,
        'source_description' => 'E2E_OTHER_BRANCH_CANDIDATE',
    ]);

    $this->actingAs($this->adminLab)
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertForbidden();

    $this->actingAs($this->adminLab)
        ->post(route('lab-case-candidates.convert', $candidate), e2eConversionPayload($this->labService))
        ->assertForbidden();

    expect(LabOrder::count())->toBe(0);
});

// ─── Scenario 6: Visit status transition on finalize ───────────────────────────

it('medical record finalization moves in_progress visit to cashier_pending before billing', function () {
    [$visit, $record] = e2eVisitReadyForFinalize($this->branch);

    expect($visit->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);

    app(MedicalRecordService::class)->finalize($record->fresh());

    expect($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and($record->fresh()->status)->toBe(MedicalRecord::STATUS_FINAL);
});
