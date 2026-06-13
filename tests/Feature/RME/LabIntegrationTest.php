<?php

// Sprint 21 Phase 21.2 — RME → Lab Case Candidate integration tests

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmeLabIntegrationService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function labFinalizedVisit(Branch $branch): array
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

function labUnpaidInvoice(Branch $branch, User $cashier, array $itemsPayload): array
{
    [$visit] = labFinalizedVisit($branch);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $cashier,
        ['items' => $itemsPayload],
    );

    return [$visit, $invoice->refresh()];
}

function labItemPayload(array $overrides = []): array
{
    return array_merge([
        'treatment_id' => null,
        'description' => 'Perawatan Gigi',
        'qty' => 1,
        'unit_price' => 300000,
        'discount' => 0,
    ], $overrides);
}

function labPaymentPayload(RmeInvoice $invoice, array $overrides = []): array
{
    return array_merge([
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ], $overrides);
}

// ─── Test 1: No requires_lab items → no candidates ───────────────────────────

it('paid rme invoice with no requires_lab items creates no lab case candidates', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->create(['requires_lab' => false]);
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    expect(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

// ─── Test 2: One requires_lab item → one candidate ───────────────────────────

it('paid rme invoice with one requires_lab item creates one lab case candidate', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    expect(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

// ─── Test 3: Multiple items, only lab-eligible produce candidates ─────────────

it('paid rme invoice with mixed items creates one candidate per lab-eligible item only', function () {
    $this->actingAs($this->cashier);

    $labTreatment = Treatment::factory()->requiresLab()->create();
    $nonLabTreatment = Treatment::factory()->create(['requires_lab' => false]);

    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $labTreatment->id,    'unit_price' => 400000]),
        labItemPayload(['treatment_id' => $labTreatment->id,    'unit_price' => 300000, 'description' => 'Mahkota Porselen']),
        labItemPayload(['treatment_id' => $nonLabTreatment->id, 'unit_price' => 100000, 'description' => 'Scaling']),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    expect(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(2);
});

// ─── Test 4: Service generation is idempotent ────────────────────────────────

it('repeated candidate generation calls are idempotent', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    $invoice->update(['status' => RmeInvoice::STATUS_PAID]);

    $svc = app(RmeLabIntegrationService::class);

    $first = $svc->generateForPaidInvoice($invoice->fresh(), $this->cashier);
    $second = $svc->generateForPaidInvoice($invoice->fresh(), $this->cashier);

    expect($first->count())->toBe(1);
    expect($second->count())->toBe(1);
    expect(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(1);
    expect($first->first()->id)->toBe($second->first()->id);
});

// ─── Test 5: Full payment flow does not duplicate candidates ─────────────────

it('paying the same invoice via payment service does not duplicate candidates', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    // Call generation again on the already-PAID invoice
    app(RmeLabIntegrationService::class)->generateForPaidInvoice($invoice->fresh(), $this->cashier);

    expect(LabCaseCandidate::where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

// ─── Test 6: Candidate stores all required source references ─────────────────

it('candidate stores all required source references and correct status', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload([
            'treatment_id' => $treatment->id,
            'unit_price' => 750000,
            'qty' => 2,
            'description' => 'Crown Porselen Zirkonia',
        ]),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    $item = RmeInvoiceItem::where('rme_invoice_id', $invoice->id)->first();
    $candidate = LabCaseCandidate::where('rme_invoice_item_id', $item->id)->firstOrFail();

    expect($candidate->branch_id)->toBe($invoice->branch_id)
        ->and($candidate->clinic_visit_id)->toBe($invoice->clinic_visit_id)
        ->and($candidate->rme_invoice_id)->toBe($invoice->id)
        ->and($candidate->rme_invoice_item_id)->toBe($item->id)
        ->and($candidate->patient_id)->toBe($invoice->patient_id)
        ->and($candidate->treatment_id)->toBe($treatment->id)
        ->and($candidate->source_description)->toBe('Crown Porselen Zirkonia')
        ->and((int) $candidate->quantity)->toBe(2)
        ->and((float) $candidate->estimated_price)->toBe(750000.0)
        ->and($candidate->status)->toBe(LabCaseCandidate::STATUS_PENDING_REVIEW);
});

// ─── Test 7: Candidate generation is branch isolated ─────────────────────────

it('generated candidates carry the source invoice branch_id', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    expect($candidate->branch_id)->toBe($this->branch->id);
});

// ─── Test 8: Cross-branch invoice is rejected by the service ─────────────────

it('service rejects candidate generation for an invoice from a non-RME branch', function () {
    $this->actingAs($this->cashier);

    // Sprint 23 Phase 23.10: candidate generation is scoped to the active RME set,
    // so a non-RME-enabled branch is rejected (no single BranchContext fallback).
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    $treatment = Treatment::factory()->requiresLab()->create();

    // Build a PAID invoice directly in the other branch (bypass payment service)
    $otherVisit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $otherBranch->id]);
    $otherInvoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $otherBranch->id,
        'clinic_visit_id' => $otherVisit->id,
        'patient_id' => $otherVisit->patient_id,
        'grand_total' => 500000,
    ]);
    RmeInvoiceItem::create([
        'rme_invoice_id' => $otherInvoice->id,
        'treatment_id' => $treatment->id,
        'description' => 'Lab item from other branch',
        'qty' => 1,
        'unit_price' => 500000,
        'discount' => 0,
        'subtotal' => 500000,
    ]);

    expect(fn () => app(RmeLabIntegrationService::class)->generateForPaidInvoice(
        $otherInvoice->fresh(),
        $this->cashier,
    ))->toThrow(ValidationException::class);

    expect(LabCaseCandidate::where('rme_invoice_id', $otherInvoice->id)->count())->toBe(0);
});

// ─── Test 9: RME payment does not create LabOrder payment records ─────────────

it('rme payment with lab items still does not create lab-order payment records', function () {
    $this->actingAs($this->cashier);

    $beforeCount = DB::table('trx_payments')->count();

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    expect(DB::table('trx_payments')->count())->toBe($beforeCount);
});

// ─── Test 10: RME partial payment is still rejected ──────────────────────────

it('rme partial payment is still rejected (sprint 20 rule preserved)', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    $partial = round((float) $invoice->grand_total / 2, 2);

    expect(fn () => app(RmePaymentService::class)->pay(
        $invoice,
        $this->cashier,
        labPaymentPayload($invoice, ['amount' => $partial]),
    ))->toThrow(ValidationException::class);
});

// ─── Test 11: No real LabOrder is created in Phase 21.2 ──────────────────────

it('no real lab order is created during phase 21.2 candidate generation', function () {
    $this->actingAs($this->cashier);

    $beforeCount = LabOrder::count();

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = labUnpaidInvoice($this->branch, $this->cashier, [
        labItemPayload(['treatment_id' => $treatment->id, 'unit_price' => 500000]),
    ]);

    app(RmePaymentService::class)->pay($invoice, $this->cashier, labPaymentPayload($invoice));

    expect(LabOrder::count())->toBe($beforeCount);
});
