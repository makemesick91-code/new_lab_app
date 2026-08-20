<?php

// Sprint 21 Phase 21.6 — RME PDF export / print hardening (tests-first)

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\LabCaseCandidateConversionService;
use App\Modules\LabService\Models\LabService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->viewer = userWith(['view_clinic_visits']);
    $this->cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $this->labViewer = userWith(['manage_rme_billing', 'view_clinic_visits', 'view_lab_orders']);
    $this->labConverter = userWith(['manage_rme_billing', 'view_clinic_visits', 'view_lab_orders', 'create_lab_orders']);
    $this->labService = LabService::factory()->create(['is_active' => true, 'price' => 850000]);
});

function hardenVisit(Branch $branch, array $overrides = []): ClinicVisit
{
    return ClinicVisit::factory()->create(array_merge(['branch_id' => $branch->id], $overrides));
}

function hardenFinalizedVisit(Branch $branch): array
{
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $branch->id]);
    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — RME payment now requires a
    // signed PERSETUJUAN TINDAKAN MEDIS. This suite is not about consent, so the
    // fixture simply gives the visit one.
    rmeSignedConsentFor($visit);

    return [$visit, $record];
}

function hardenItemPayload(array $overrides = []): array
{
    return array_merge([
        'treatment_id' => null,
        'description' => 'Perawatan Gigi',
        'qty' => 1,
        'unit_price' => 300000,
        'discount' => 0,
    ], $overrides);
}

function hardenPaidInvoiceWithLabItems(Branch $branch, User $cashier, array $itemsPayload): array
{
    [$visit] = hardenFinalizedVisit($branch);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $cashier,
        ['items' => $itemsPayload],
    );

    app(RmePaymentService::class)->pay($invoice->refresh(), $cashier, [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'consent_signed_by_patient' => true,
        'consent_signed_by_doctor' => true,
    ]);

    return [$visit, $invoice->fresh()];
}

function hardenConversionPayload(LabService $service, array $overrides = []): array
{
    return array_merge([
        'lab_service_id' => $service->id,
        'due_date' => now()->addDays(7)->toDateString(),
        'notes' => 'Konversi harden test',
    ], $overrides);
}

// ─── 1–3: Authorization & branch isolation ───────────────────────────────────

it('authorized user can open rme visit print page', function () {
    $visit = hardenVisit($this->branch);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertViewIs('rme.visits.print');
});

it('unauthorized user cannot open rme visit print page', function () {
    $visit = hardenVisit($this->branch);

    $this->actingAs(userWith([]))
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();
});

it('cross branch user cannot open rme visit print page', function () {
    // Sprint 23 Phase 23.9.3: scope is the active RME-enabled branch set, so a
    // non-RME branch visit stays out of scope and forbidden.
    $otherBranch = Branch::factory()->create(['code' => 'HARDEN-XBR', 'is_rme_enabled' => false]);
    $visit = hardenVisit($otherBranch);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertForbidden();
});

// ─── 4–9: Print content sections ─────────────────────────────────────────────

it('rme print page includes patient doctor branch visit number and visit date', function () {
    $visit = hardenVisit($this->branch, [
        'visit_number' => 'VIS-HARDEN-001',
        'visit_date' => '2026-06-10',
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee($visit->patient->name)
        ->assertSee($visit->patient->medical_record_number)
        ->assertSee($visit->doctor->name)
        ->assertSee($this->branch->name)
        ->assertSee('VIS-HARDEN-001')
        ->assertSee('10/06/2026');
});

it('rme print page includes initial treatment when available', function () {
    $treatment = Treatment::factory()->create(['name' => 'HARDEN_INITIAL_SCALING']);
    $visit = hardenVisit($this->branch, ['initial_treatment_id' => $treatment->id]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Tindakan Awal')
        ->assertSee('HARDEN_INITIAL_SCALING');
});

it('rme print page includes finalized medical record status', function () {
    $visit = hardenVisit($this->branch);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'finalized_at' => now(),
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Final')
        ->assertSee('Difinalisasi');
});

it('rme print page includes handwriting preview when saved', function () {
    Storage::fake('public');

    $visit = hardenVisit($this->branch);
    $record = MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'handwriting_path' => 'handwritings/test/harden-print.png',
    ]);

    Storage::disk('public')->put('handwritings/test/harden-print.png', base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNk+M9Qz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC',
        true
    ));

    $handwriting = MedicalRecordHandwriting::where('medical_record_id', $record->id)->firstOrFail();

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('RME Tulisan Tangan')
        ->assertSee($handwriting->previewUrl(), false);
});

it('rme print page shows safe empty handwriting message when no handwriting exists', function () {
    $visit = hardenVisit($this->branch);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Belum ada handwriting RM.');
});

it('rme print page excludes the odontogram summary even when one exists', function () {
    // FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-05 — SUPERSEDES the Sprint 21.6
    // expectation that the RME print bundle carried an odontogram summary. The
    // odontogram keeps its own standalone print; the RME print is medical-record
    // content only. The odontogram record itself is untouched.
    $visit = hardenVisit($this->branch);
    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'summary_notes' => 'HARDEN_ODONTO_SUMMARY',
        'tooth_map_payload' => [
            'teeth' => ['21' => ['status' => 'caries', 'conditions' => ['filling']]],
        ],
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        // Still a working print, just without the odontogram composition.
        ->assertSee('Rekam Medis')
        ->assertDontSee('HARDEN_ODONTO_SUMMARY')
        ->assertDontSee('Catatan Odontogram');
});

// ─── 10–12: Invoice & lab workflow on print ──────────────────────────────────

it('rme print page includes rme invoice payment summary when invoice is paid', function () {
    $this->actingAs($this->labViewer);

    [$visit, $invoice] = hardenPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        hardenItemPayload([
            'description' => 'HARDEN_PRINT_INVOICE_ITEM',
            'unit_price' => 450000,
        ]),
    ]);

    $this->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Tagihan & Pembayaran RME')
        ->assertSee($invoice->invoice_number)
        ->assertSee('LUNAS')
        ->assertSee('HARDEN_PRINT_INVOICE_ITEM')
        ->assertSee(number_format($invoice->grand_total, 0, ',', '.'));
});

it('rme print page includes lab case candidate summary when generated from invoice', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = hardenPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        hardenItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'HARDEN_PRINT_LAB_CANDIDATE',
            'unit_price' => 550000,
        ]),
    ]);

    $this->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Status Pekerjaan Lab RME')
        ->assertSee('HARDEN_PRINT_LAB_CANDIDATE')
        ->assertSee('Menunggu Review');
});

it('rme print page includes converted lab order reference when candidate is converted', function () {
    $this->actingAs($this->labConverter);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = hardenPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        hardenItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'HARDEN_PRINT_CONVERTED',
            'unit_price' => 650000,
        ]),
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();
    $order = app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        hardenConversionPayload($this->labService),
        $this->labConverter,
    );

    $this->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertSee('Sudah Dikonversi')
        ->assertSee($order->order_number);
});

// ─── 13–14: SOAP hidden & no side effects ────────────────────────────────────

it('rme print page does not show editable soap fields', function () {
    $visit = hardenVisit($this->branch);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
        'subjective' => 'HARDEN_SOAP_SHOULD_NOT_PRINT',
        'objective' => 'HARDEN_OBJECTIVE_HIDDEN',
        'assessment' => 'HARDEN_ASSESSMENT_HIDDEN',
        'plan' => 'HARDEN_PLAN_HIDDEN',
        'status' => MedicalRecord::STATUS_FINAL,
    ]);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.print', $visit))
        ->assertOk()
        ->assertDontSee('Subjektif (Anamnesis)')
        ->assertDontSee('Objektif (Pemeriksaan)')
        ->assertDontSee('HARDEN_SOAP_SHOULD_NOT_PRINT')
        ->assertDontSee('textarea', false)
        ->assertDontSee('name="subjective"', false);
});

it('rme print page does not create invoice payment candidate or lab order records', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = hardenPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        hardenItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'HARDEN_NO_MUTATION',
            'unit_price' => 500000,
        ]),
    ]);

    $beforeInvoices = RmeInvoice::count();
    $beforePayments = RmePayment::count();
    $beforeCandidates = LabCaseCandidate::count();
    $beforeOrders = LabOrder::count();

    $this->get(route('rme.visits.print', $visit))->assertOk();

    expect(RmeInvoice::count())->toBe($beforeInvoices)
        ->and(RmePayment::count())->toBe($beforePayments)
        ->and(LabCaseCandidate::count())->toBe($beforeCandidates)
        ->and(LabOrder::count())->toBe($beforeOrders);
});

// ─── 15–16: Receipt print hardening ──────────────────────────────────────────

it('rme receipt print view includes lab workflow summary when candidates exist', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = hardenPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        hardenItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'HARDEN_RECEIPT_LAB_ITEM',
            'unit_price' => 600000,
        ]),
    ]);

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Kandidat Lab RME')
        ->assertSee('HARDEN_RECEIPT_LAB_ITEM')
        ->assertSee('Menunggu Review')
        ->assertSee('print-lab-workflow', false);
});

it('rme receipt totals and payment information remain unchanged on print view', function () {
    $this->actingAs($this->labViewer);

    [$visit, $invoice] = hardenPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        hardenItemPayload([
            'description' => 'HARDEN_RECEIPT_TOTAL_ITEM',
            'unit_price' => 750000,
            'qty' => 1,
            'discount' => 0,
        ]),
    ]);

    $payment = RmePayment::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('HARDEN_RECEIPT_TOTAL_ITEM')
        ->assertSee('Rp '.number_format($invoice->grand_total, 0, ',', '.'))
        ->assertSee('Rp '.number_format($payment->amount, 0, ',', '.'))
        ->assertSee('LUNAS');
});

// ─── 17–21: PDF export (barryvdh/laravel-dompdf already in project) ──────────

it('registers the rme visit pdf route name', function () {
    expect(Route::has('rme.visits.pdf'))->toBeTrue();
});

it('authorized user can download rme visit pdf', function () {
    $visit = hardenVisit($this->branch, ['visit_number' => 'VIS-HARDEN-PDF']);

    $response = $this->actingAs($this->viewer)
        ->get(route('rme.visits.pdf', $visit));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('rme-visit-VIS-HARDEN-PDF.pdf');
});

it('rme visit pdf route enforces branch isolation', function () {
    $otherBranch = Branch::factory()->create(['code' => 'HARDEN-PDF-X', 'is_rme_enabled' => false]);
    $visit = hardenVisit($otherBranch);

    $this->actingAs($this->viewer)
        ->get(route('rme.visits.pdf', $visit))
        ->assertForbidden();
});

it('rme visit pdf route does not mutate rme lab or payment records', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit] = hardenPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        hardenItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'HARDEN_PDF_NO_MUTATION',
            'unit_price' => 500000,
        ]),
    ]);

    $beforeInvoices = RmeInvoice::count();
    $beforePayments = RmePayment::count();
    $beforeCandidates = LabCaseCandidate::count();
    $beforeOrders = LabOrder::count();

    $this->get(route('rme.visits.pdf', $visit))->assertOk();

    expect(RmeInvoice::count())->toBe($beforeInvoices)
        ->and(RmePayment::count())->toBe($beforePayments)
        ->and(LabCaseCandidate::count())->toBe($beforeCandidates)
        ->and(LabOrder::count())->toBe($beforeOrders);
});

it('rme visit pdf includes stable title reference in content disposition', function () {
    $visit = hardenVisit($this->branch, ['visit_number' => 'VIS-HARDEN-TITLE']);

    $response = $this->actingAs($this->viewer)
        ->get(route('rme.visits.pdf', $visit));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('rme-visit-VIS-HARDEN-TITLE.pdf');
});
