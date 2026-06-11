<?php

// Sprint 21 Phase 21.4 — Convert LabCaseCandidate to LabOrder (tests-first)

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderItem;
use App\Modules\LabOrder\Services\LabCaseCandidateConversionService;
use App\Modules\LabService\Models\LabService;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->converter = userWith(['view_lab_orders', 'create_lab_orders']);
    $this->labService = LabService::factory()->create(['is_active' => true, 'price' => 850000]);
});

function conversionCandidate(Branch $branch, array $overrides = []): LabCaseCandidate
{
    return LabCaseCandidate::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'status' => LabCaseCandidate::STATUS_PENDING_REVIEW,
        'source_description' => 'Crown Porselen Anterior',
        'quantity' => 2,
    ], $overrides));
}

function conversionPayload(LabService $service, array $overrides = []): array
{
    return array_merge([
        'lab_service_id' => $service->id,
        'due_date' => now()->addDays(7)->toDateString(),
        'notes' => 'Konversi dari kandidat RME',
    ], $overrides);
}

// ─── Test 1: Authorized user can convert ─────────────────────────────────────

it('authorized user can convert pending candidate into lab order with explicit lab_service_id', function () {
    $candidate = conversionCandidate($this->branch);

    $this->actingAs($this->converter)
        ->post(route('lab-case-candidates.convert', $candidate), conversionPayload($this->labService))
        ->assertRedirect();

    expect(LabOrder::count())->toBe(1);
});

// ─── Test 2: Creates exactly one LabOrder and one LabOrderItem ─────────────

it('conversion creates exactly one lab order and one lab order item', function () {
    $candidate = conversionCandidate($this->branch);

    app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    );

    expect(LabOrder::count())->toBe(1)
        ->and(LabOrderItem::count())->toBe(1);
});

// ─── Test 3: Copies safe source fields ───────────────────────────────────────

it('conversion copies safe source fields to lab order and item', function () {
    $candidate = conversionCandidate($this->branch, [
        'source_description' => 'Veneer Komposit',
        'quantity' => 3,
    ]);

    $order = app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService, ['quantity' => 3]),
        $this->converter,
    )->load('items');

    expect($order->branch_id)->toBe($candidate->branch_id)
        ->and($order->patient_id)->toBe($candidate->patient_id)
        ->and($order->doctor_id)->toBe($candidate->doctor_id)
        ->and($order->items)->toHaveCount(1);

    $item = $order->items->first();
    expect($item->lab_service_id)->toBe($this->labService->id)
        ->and((float) $item->quantity)->toBe(3.0)
        ->and($item->notes)->toContain('Veneer Komposit');
});

// ─── Test 4: Candidate status becomes converted ──────────────────────────────

it('sets candidate status to converted_to_lab_order after conversion', function () {
    $candidate = conversionCandidate($this->branch);

    app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    );

    expect($candidate->fresh()->status)->toBe(LabCaseCandidate::STATUS_CONVERTED_TO_LAB_ORDER);
});

// ─── Test 5: converted_lab_order_id stored ───────────────────────────────────

it('stores converted_lab_order_id on candidate', function () {
    $candidate = conversionCandidate($this->branch);

    $order = app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    );

    expect($candidate->fresh()->converted_lab_order_id)->toBe($order->id);
});

// ─── Test 6: reviewed_by and reviewed_at stored ──────────────────────────────

it('stores reviewed_by and reviewed_at on candidate', function () {
    $candidate = conversionCandidate($this->branch);

    app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    );

    $fresh = $candidate->fresh();
    expect($fresh->reviewed_by)->toBe($this->converter->id)
        ->and($fresh->reviewed_at)->not->toBeNull();
});

// ─── Test 7: Missing lab_service_id rejected ─────────────────────────────────

it('rejects conversion without lab_service_id when no treatment mapping exists', function () {
    $candidate = conversionCandidate($this->branch);

    expect(fn () => app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        ['due_date' => now()->addDays(5)->toDateString()],
        $this->converter,
    ))->toThrow(ValidationException::class);

    expect(LabOrder::count())->toBe(0);
});

// ─── Test 8: Non-pending candidate rejected ──────────────────────────────────

it('rejects conversion for non-pending candidate', function () {
    $candidate = conversionCandidate($this->branch, [
        'status' => LabCaseCandidate::STATUS_REJECTED,
    ]);

    expect(fn () => app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    ))->toThrow(ValidationException::class);

    expect(LabOrder::count())->toBe(0);
});

// ─── Test 9: Idempotent conversion ─────────────────────────────────────────────

it('converting an already converted candidate is idempotent', function () {
    $candidate = conversionCandidate($this->branch);
    $svc = app(LabCaseCandidateConversionService::class);

    $first = $svc->convertToLabOrder($candidate, conversionPayload($this->labService), $this->converter);
    $second = $svc->convertToLabOrder($candidate->fresh(), conversionPayload($this->labService), $this->converter);

    expect($first->id)->toBe($second->id)
        ->and(LabOrder::count())->toBe(1)
        ->and(LabOrderItem::count())->toBe(1);
});

// ─── Test 10: Cross-branch rejection ─────────────────────────────────────────

it('cross-branch user cannot convert candidate from another branch', function () {
    $otherBranch = Branch::factory()->create(['is_active' => true]);
    $candidate = conversionCandidate($otherBranch);

    $this->actingAs($this->converter)
        ->post(route('lab-case-candidates.convert', $candidate), conversionPayload($this->labService))
        ->assertForbidden();

    expect(LabOrder::count())->toBe(0);
});

// ─── Test 11: Inactive lab service rejected ──────────────────────────────────

it('rejects conversion with inactive lab service', function () {
    $inactive = LabService::factory()->inactive()->create();
    $candidate = conversionCandidate($this->branch);

    expect(fn () => app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($inactive),
        $this->converter,
    ))->toThrow(ValidationException::class);

    expect(LabOrder::count())->toBe(0);
});

// ─── Test 12: No invoice/payment records created ─────────────────────────────

it('conversion does not create lab invoice or payment records', function () {
    $candidate = conversionCandidate($this->branch);
    $beforePayments = DB::table('trx_payments')->count();
    $beforeInvoices = Invoice::count();

    app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    );

    expect(DB::table('trx_payments')->count())->toBe($beforePayments)
        ->and(Invoice::count())->toBe($beforeInvoices);
});

// ─── Test 13: RME state unchanged ────────────────────────────────────────────

it('conversion does not alter rme invoice payment or visit status', function () {
    $candidate = conversionCandidate($this->branch);
    $invoiceStatus = $candidate->rmeInvoice->status;
    $visitStatus = $candidate->clinicVisit->status;
    $paymentCount = RmePayment::where('rme_invoice_id', $candidate->rme_invoice_id)->count();

    app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    );

    expect($candidate->rmeInvoice->fresh()->status)->toBe($invoiceStatus)
        ->and($candidate->clinicVisit->fresh()->status)->toBe($visitStatus)
        ->and(RmePayment::where('rme_invoice_id', $candidate->rme_invoice_id)->count())->toBe($paymentCount);
});

// ─── Test 14: RME payment does not auto-create LabOrder ──────────────────────

it('rme payment still does not auto-create lab order only explicit conversion does', function () {
    $this->actingAs(userWith(['manage_rme_billing']));

    $treatment = Treatment::factory()->requiresLab()->create();
    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->branch->id]);
    $invoice = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $this->branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'grand_total' => 500000,
    ]);
    $invoice->items()->create([
        'treatment_id' => $treatment->id,
        'description' => 'Lab item',
        'qty' => 1,
        'unit_price' => 500000,
        'discount' => 0,
        'subtotal' => 500000,
    ]);

    $beforeOrders = LabOrder::count();

    app(RmePaymentService::class)->pay($invoice->fresh(), $this->converter, [
        'amount' => $invoice->grand_total,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ]);

    expect(LabOrder::count())->toBe($beforeOrders);

    $candidate = LabCaseCandidate::where('rme_invoice_id', $invoice->id)->firstOrFail();

    app(LabCaseCandidateConversionService::class)->convertToLabOrder(
        $candidate,
        conversionPayload($this->labService),
        $this->converter,
    );

    expect(LabOrder::count())->toBe($beforeOrders + 1);
});

// ─── Test 15: Unauthorized user cannot convert ───────────────────────────────

it('unauthorized user cannot access conversion route', function () {
    $candidate = conversionCandidate($this->branch);
    $unauth = User::factory()->create();

    $this->actingAs($unauth)
        ->post(route('lab-case-candidates.convert', $candidate), conversionPayload($this->labService))
        ->assertForbidden();

    expect(LabOrder::count())->toBe(0);
});

// ─── Test 16: Show page displays conversion form for authorized user ─────────

it('candidate show page displays conversion action only for authorized user', function () {
    $candidate = conversionCandidate($this->branch);

    $this->actingAs($this->converter)
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertOk()
        ->assertSee('Konversi ke Lab Order');

    $viewer = userWith(['view_lab_orders']);

    $this->actingAs($viewer)
        ->get(route('lab-case-candidates.show', $candidate))
        ->assertOk()
        ->assertDontSee('Konversi ke Lab Order');
});
