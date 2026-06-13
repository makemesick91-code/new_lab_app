<?php

// Sprint 21 Phase 21.5 — RME → Lab workflow visibility polish (tests-first)

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\LabCaseCandidateConversionService;
use App\Modules\LabService\Models\LabService;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
    $this->labViewer = userWith(['manage_rme_billing', 'view_lab_orders']);
    $this->labConverter = userWith(['manage_rme_billing', 'view_lab_orders', 'create_lab_orders']);
    $this->labService = LabService::factory()->create(['is_active' => true, 'price' => 850000]);
});

function polishFinalizedVisit(Branch $branch): array
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

function polishItemPayload(array $overrides = []): array
{
    return array_merge([
        'treatment_id' => null,
        'description' => 'Perawatan Gigi',
        'qty' => 1,
        'unit_price' => 300000,
        'discount' => 0,
    ], $overrides);
}

function polishPaidInvoiceWithLabItems(Branch $branch, User $cashier, array $itemsPayload): array
{
    [$visit] = polishFinalizedVisit($branch);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $cashier,
        ['items' => $itemsPayload],
    );

    app(RmePaymentService::class)->pay($invoice->refresh(), $cashier, [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ]);

    return [$visit, $invoice->fresh()];
}

function polishConversionPayload(LabService $service, array $overrides = []): array
{
    return array_merge([
        'lab_service_id' => $service->id,
        'due_date' => now()->addDays(7)->toDateString(),
        'notes' => 'Konversi polish test',
    ], $overrides);
}

// ─── Test 1: RME invoice show displays lab candidate summary ────────────────

it('rme invoice show page displays lab candidate summary when paid invoice generated candidates', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_LAB_CROWN_ALPHA',
            'unit_price' => 500000,
        ]),
    ]);

    $this->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Status Pekerjaan Lab RME')
        ->assertSee('POLISH_LAB_CROWN_ALPHA')
        ->assertSee('Menunggu Review')
        ->assertSee('kandidat');
});

// ─── Test 2: RME invoice show displays no-lab-candidate message ───────────────

it('rme invoice show page displays no lab candidate message when paid invoice has no lab candidates', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->create(['requires_lab' => false]);
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_NO_LAB_CLEANING',
            'unit_price' => 200000,
        ]),
    ]);

    $this->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Status Pekerjaan Lab RME')
        ->assertSee('Belum ada kandidat pekerjaan lab untuk tagihan ini');
});

// ─── Test 3: RME receipt displays lab workflow section ───────────────────────

it('rme receipt page displays lab candidate status section when candidates exist', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_RECEIPT_LAB_ITEM',
            'unit_price' => 600000,
        ]),
    ]);

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Kandidat Lab RME')
        ->assertSee('POLISH_RECEIPT_LAB_ITEM')
        ->assertSee('Menunggu Review');
});

// ─── Test 4: Receipt links to candidate for permitted user ───────────────────

it('rme receipt page links to lab case candidate show page for permitted user', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_RECEIPT_LINK_ITEM',
            'unit_price' => 700000,
        ]),
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee(parse_url(route('lab-case-candidates.show', $candidate), PHP_URL_PATH), false);
});

// ─── Test 5: Receipt shows converted LabOrder reference ──────────────────────

it('rme receipt page shows converted lab order reference when candidate is converted', function () {
    $this->actingAs($this->labConverter);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_CONVERTED_RECEIPT',
            'unit_price' => 800000,
        ]),
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();
    $order = app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        polishConversionPayload($this->labService),
        $this->labConverter,
    );

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice->fresh()]))
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee(parse_url(route('lab-orders.show', $order), PHP_URL_PATH), false);
});

// ─── Test 6: Candidate index shows converted status and LabOrder link ────────

it('lab case candidate index clearly shows converted status and linked lab order when converted', function () {
    $candidate = LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
        'source_description' => 'POLISH_INDEX_PENDING_ITEM',
    ]);

    $converted = LabCaseCandidate::factory()->converted()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'POLISH_INDEX_CONVERTED_ITEM',
    ]);

    $order = LabOrder::factory()->create(['branch_id' => $this->branch->id]);
    $converted->update(['converted_lab_order_id' => $order->id]);

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-case-candidates.index', ['status' => LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER]))
        ->assertOk()
        ->assertSee('POLISH_INDEX_CONVERTED_ITEM')
        ->assertSee('Sudah Dikonversi')
        ->assertSee($order->order_number)
        ->assertSee(parse_url(route('lab-orders.show', $order), PHP_URL_PATH), false)
        ->assertDontSee('POLISH_INDEX_PENDING_ITEM');
});

// ─── Test 7: Candidate show displays source RME invoice link ─────────────────

it('lab case candidate show page shows source rme invoice link', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_SHOW_INVOICE_LINK',
            'unit_price' => 500000,
        ]),
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->get(route('lab-case-candidates.show', $candidate))
        ->assertOk()
        ->assertSee($invoice->invoice_number)
        ->assertSee(parse_url(route('rme.cashier.show', [$visit, $invoice]), PHP_URL_PATH), false);
});

// ─── Test 8: Candidate show displays converted LabOrder link ─────────────────

it('lab case candidate show page shows converted lab order link when converted', function () {
    $candidate = LabCaseCandidate::factory()->converted()->create([
        'branch_id' => $this->branch->id,
    ]);

    $order = LabOrder::factory()->create(['branch_id' => $this->branch->id]);
    $candidate->update([
        'converted_lab_order_id' => $order->id,
        'reviewed_by' => $this->labConverter->id,
        'reviewed_at' => now(),
    ]);

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee(parse_url(route('lab-orders.show', $order), PHP_URL_PATH), false);
});

// ─── Test 9: LabOrder show displays RME source when from candidate ───────────

it('lab order show page displays source rme candidate reference when order was created from candidate', function () {
    $this->actingAs($this->labConverter);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_ORDER_SOURCE_VENEER',
            'unit_price' => 500000,
        ]),
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $order = app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        polishConversionPayload($this->labService),
        $this->labConverter,
    );

    $this->get(route('lab-orders.show', $order))
        ->assertOk()
        ->assertSee('Sumber RME')
        ->assertSee('POLISH_ORDER_SOURCE_VENEER')
        ->assertSee($candidate->rmeInvoice->invoice_number)
        ->assertSee(parse_url(route('lab-case-candidates.show', $candidate), PHP_URL_PATH), false);
});

// ─── Test 10: LabOrder show hides RME source for normal order ────────────────

it('lab order show page does not display rme source section for normal lab order', function () {
    $order = LabOrder::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-orders.show', $order))
        ->assertOk()
        ->assertDontSee('Sumber RME');
});

// ─── Test 11: Branch isolation ───────────────────────────────────────────────

it('rme invoice receipt and lab order pages do not leak candidates from another branch', function () {
    // Sprint 23 Phase 23.10: RME billing pages are scoped to the active RME set,
    // so isolation now means "non-RME branch" (no single BranchContext fallback).
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    $otherVisit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $otherBranch->id]);
    $otherInvoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $otherBranch->id,
        'clinic_visit_id' => $otherVisit->id,
        'patient_id' => $otherVisit->patient_id,
    ]);

    $otherCandidate = LabCaseCandidate::factory()->create([
        'branch_id' => $otherBranch->id,
        'clinic_visit_id' => $otherVisit->id,
        'rme_invoice_id' => $otherInvoice->id,
        'source_description' => 'POLISH_OTHER_BRANCH_SECRET',
    ]);

    $this->actingAs($this->labViewer)
        ->get(route('rme.cashier.show', [$otherVisit, $otherInvoice]))
        ->assertForbidden();

    $this->actingAs($this->labViewer)
        ->get(route('rme.cashier.receipt.show', [$otherVisit, $otherInvoice]))
        ->assertForbidden();

    $this->actingAs($this->labViewer)
        ->get(route('lab-case-candidates.show', $otherCandidate))
        ->assertForbidden();
});

// ─── Test 12: Unauthorized user does not see inaccessible links ──────────────

it('cashier without lab permission does not see candidate links on receipt', function () {
    $this->actingAs($this->cashier);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_CASHIER_ONLY_ITEM',
            'unit_price' => 400000,
        ]),
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee('Kandidat Lab RME')
        ->assertSee('POLISH_CASHIER_ONLY_ITEM')
        ->assertDontSee(parse_url(route('lab-case-candidates.show', $candidate), PHP_URL_PATH), false);
});

// ─── Test 13: Existing candidate conversion still works ──────────────────────

it('existing candidate conversion still works after polish', function () {
    $candidate = LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
    ]);

    $this->actingAs($this->labConverter)
        ->post(route('lab-case-candidates.convert', $candidate), polishConversionPayload($this->labService))
        ->assertRedirect();

    expect($candidate->fresh()->status)->toBe(LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER)
        ->and(LabOrder::count())->toBe(1);
});

// ─── Test 14: Existing candidate queue still works ───────────────────────────

it('existing candidate queue still works after polish', function () {
    LabCaseCandidate::factory()->create([
        'branch_id' => $this->branch->id,
        'source_description' => 'POLISH_QUEUE_STILL_WORKS',
    ]);

    $this->actingAs(userWith(['view_lab_orders']))
        ->get(route('lab-case-candidates.index'))
        ->assertOk()
        ->assertSee('Kandidat Pekerjaan Lab')
        ->assertSee('POLISH_QUEUE_STILL_WORKS');
});

// ─── Test 15: No new LabOrder during RME payment ─────────────────────────────

it('no new lab order is created during rme payment after polish', function () {
    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_PAYMENT_NO_ORDER',
            'unit_price' => 500000,
        ]),
    ]);

    expect(LabOrder::count())->toBe(0);

    $this->actingAs($this->labViewer)
        ->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk();

    expect(LabOrder::count())->toBe(0);
});

// ─── Test 16: Polish views do not create payment or invoice records ──────────

it('polish views do not create lab invoice or payment records', function () {
    $this->actingAs($this->labViewer);

    $treatment = Treatment::factory()->requiresLab()->create();
    [$visit, $invoice] = polishPaidInvoiceWithLabItems($this->branch, $this->cashier, [
        polishItemPayload([
            'treatment_id' => $treatment->id,
            'description' => 'POLISH_NO_BILLING_SIDE_EFFECT',
            'unit_price' => 500000,
        ]),
    ]);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();
    $order = app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        polishConversionPayload($this->labService),
        $this->labConverter,
    );

    $beforePayments = DB::table('trx_payments')->count();
    $beforeInvoices = Invoice::count();
    $beforeRmePayments = RmePayment::count();

    $this->get(route('rme.cashier.show', [$visit, $invoice->fresh()]))->assertOk();
    $this->get(route('rme.cashier.receipt.show', [$visit, $invoice->fresh()]))->assertOk();
    $this->get(route('lab-case-candidates.show', $candidate->fresh()))->assertOk();
    $this->get(route('lab-orders.show', $order))->assertOk();

    expect(DB::table('trx_payments')->count())->toBe($beforePayments)
        ->and(Invoice::count())->toBe($beforeInvoices)
        ->and(RmePayment::count())->toBe($beforeRmePayments);
});
