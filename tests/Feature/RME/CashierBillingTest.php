<?php

// Sprint 20 Phase 1.10 — Cashier RME Billing tests

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->cashier = userWith(['manage_rme_billing']);
    $this->unauthorized = userWith(['view_clinic_visits']);
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

function makeCashierPendingVisit(Branch $branch): ClinicVisit
{
    return ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
    ]);
}

function makeFinalizedVisit(Branch $branch): array
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

function makeItemPayload(array $overrides = []): array
{
    return array_merge([
        'treatment_id' => null,
        'description' => 'Pemeriksaan Gigi',
        'qty' => 1,
        'unit_price' => 150000,
        'discount' => 0,
    ], $overrides);
}

// ─── Test 1: Cashier can see CASHIER_PENDING visits ──────────────────────────

it('cashier can see cashier_pending visits in index', function () {
    $this->actingAs($this->cashier);

    $visit = makeCashierPendingVisit($this->branch);

    $this->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertSee($visit->visit_number);
});

// ─── Test 2: Non-pending visits do not appear ────────────────────────────────

it('completed visits do not appear in cashier index', function () {
    $this->actingAs($this->cashier);

    $completedVisit = ClinicVisit::factory()->completed()->create(['branch_id' => $this->branch->id]);

    $this->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertDontSee($completedVisit->visit_number);
});

// ─── Test 3: Cannot create invoice for non-cashier-pending visit ─────────────

it('service rejects invoice creation for non-cashier-pending visit', function () {
    $this->actingAs($this->cashier);

    $visit = ClinicVisit::factory()->inProgress()->create(['branch_id' => $this->branch->id]);

    expect(fn () => app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload()]]
    ))->toThrow(ValidationException::class);
});

// ─── Test 4: Cashier can create invoice for cashier_pending visit ─────────────

it('cashier can create invoice for cashier_pending visit via HTTP', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    $response = $this->post(route('rme.cashier.store', $visit), [
        'notes' => 'Test tagihan',
        'items' => [
            [
                'description' => 'Scalling',
                'qty' => 1,
                'unit_price' => 200000,
                'discount' => 0,
            ],
        ],
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('status');

    expect(RmeInvoice::where('clinic_visit_id', $visit->id)->exists())->toBeTrue();
});

// ─── Test 5: Invoice requires at least one item ───────────────────────────────

it('service rejects invoice with no items', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    expect(fn () => app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => []]
    ))->toThrow(ValidationException::class);
});

// ─── Test 6: Invoice item requires valid qty ──────────────────────────────────

it('service rejects invoice item with zero qty', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    expect(fn () => app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload(['qty' => 0])]]
    ))->toThrow(ValidationException::class);
});

// ─── Test 7: Invoice item requires non-negative price ────────────────────────

it('service rejects invoice item with negative unit price', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    expect(fn () => app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload(['unit_price' => -100])]]
    ))->toThrow(ValidationException::class);
});

// ─── Test 8: Invoice total is calculated correctly ───────────────────────────

it('invoice total is calculated correctly from items', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        [
            'items' => [
                makeItemPayload(['qty' => 2, 'unit_price' => 100000, 'discount' => 10000]),
                makeItemPayload(['qty' => 1, 'unit_price' => 50000, 'discount' => 0]),
            ],
        ]
    );

    $invoice->refresh();

    // subtotal = 2*100000 + 1*50000 = 250000
    // discount_total = 10000 + 0 = 10000
    // grand_total = 250000 - 10000 = 240000
    expect((float) $invoice->subtotal)->toBe(250000.0)
        ->and((float) $invoice->discount_total)->toBe(10000.0)
        ->and((float) $invoice->grand_total)->toBe(240000.0);
});

// ─── Test 9: Invoice uses selected treatment ─────────────────────────────────

it('invoice item stores treatment reference when provided', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    $treatment = Treatment::factory()->create();

    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload(['treatment_id' => $treatment->id])]]
    );

    expect(RmeInvoiceItem::where('rme_invoice_id', $invoice->id)->where('treatment_id', $treatment->id)->exists())
        ->toBeTrue();
});

// ─── Test 10: Initial service remains unchanged ───────────────────────────────

it('creating rme invoice does not change initial_treatment_id on visit', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    $initialTreatmentId = $visit->initial_treatment_id;

    app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload()]]
    );

    $visit->refresh();

    expect($visit->initial_treatment_id)->toBe($initialTreatmentId);
});

// ─── Test 11: No payment records created ─────────────────────────────────────

it('creating rme invoice does not create any payment records', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    $paymentTableExists = Schema::hasTable('trx_rme_payments');

    app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload()]]
    );

    if ($paymentTableExists) {
        expect(DB::table('trx_rme_payments')->count())->toBe(0);
    }

    // Invoice exists, status is UNPAID (not PAID)
    $invoice = RmeInvoice::where('clinic_visit_id', $visit->id)->first();
    expect($invoice->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

// ─── Test 12: Duplicate active invoice prevented ─────────────────────────────

it('service prevents duplicate active invoice for same visit', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    // Create first invoice
    app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload()]]
    );

    // Attempt second invoice for same visit
    expect(fn () => app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload()]]
    ))->toThrow(ValidationException::class);
});

// ─── Test 13: Unauthorized user cannot access cashier billing ────────────────

it('unauthorized user gets 403 on cashier index', function () {
    $this->actingAs($this->unauthorized);

    $this->get(route('rme.cashier.index'))->assertForbidden();
});

it('unauthorized user gets 403 on cashier create', function () {
    $this->actingAs($this->unauthorized);

    $visit = makeCashierPendingVisit($this->branch);

    $this->get(route('rme.cashier.create', $visit))->assertForbidden();
});

// ─── Test 14: Invoice detail shows correct data ───────────────────────────────

it('invoice show page displays patient visit treatment items and total', function () {
    $this->actingAs($this->cashier);

    [$visit, $record] = makeFinalizedVisit($this->branch);

    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Scalling Gigi', 'unit_price' => 300000])]]
    );

    $invoice->refresh();

    $this->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertOk()
        ->assertSee($invoice->invoice_number)
        ->assertSee($visit->patient->name)
        ->assertSee('Scalling Gigi')
        ->assertSee('300');
});

// ─── Branch isolation ─────────────────────────────────────────────────────────

it('cashier cannot access invoice from a different branch', function () {
    $this->actingAs($this->cashier);

    $otherBranch = Branch::factory()->create(['is_active' => true]);
    $visit = makeCashierPendingVisit($otherBranch);

    $invoice = RmeInvoice::factory()->create([
        'branch_id' => $otherBranch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'cashier_id' => $this->cashier->id,
    ]);

    $this->get(route('rme.cashier.show', [$visit, $invoice]))
        ->assertForbidden();
});
