<?php

// Sprint 20 Phase 1.10 — Cashier RME Billing tests

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordHandwriting;
use App\Modules\MedicalRecord\Services\MedicalRecordService;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_rme_enabled' => true]);
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

// ─── Test 3: Cannot bill unfinalized RME ─────────────────────────────────────

it('service rejects invoice creation when medical record is not finalized', function () {
    $this->actingAs($this->cashier);

    $visit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->branch->id]);
    MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    expect(fn () => app(RmeInvoiceService::class)->create(
        $visit,
        $this->cashier,
        ['items' => [makeItemPayload()]]
    ))->toThrow(ValidationException::class);
});

// ─── Test 4: Cannot create invoice for non-cashier-pending visit ─────────────

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

it('cashier cannot access invoice from a non-RME branch', function () {
    $this->actingAs($this->cashier);

    // Sprint 23 Phase 23.10: RME billing is scoped to the active RME-enabled set,
    // so isolation now means "not an RME branch" (MAIN/non-RME), not a single
    // BranchContext branch. A non-RME-enabled branch must stay forbidden.
    $otherBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
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

// ─── RME branch queue + clinical display (Sprint 23 hardening) ───────────────

it('lists an RME-branch visit in cashier queue after medical record finalization', function () {
    $visit = ClinicVisit::factory()->inProgress()->create(['branch_id' => $this->branch->id]);
    $record = MedicalRecord::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'doctor_id' => $visit->doctor_id,
    ]);

    app(MedicalRecordService::class)->finalize($record);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertSee($visit->fresh()->visit_number);
});

it('does not list a MAIN branch visit in the cashier queue', function () {
    $mainVisit = ClinicVisit::factory()->cashierPending()->create(['branch_id' => $this->main->id]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertDontSee($mainVisit->visit_number);
});

it('does not list a completed visit without invoice in the cashier queue', function () {
    $completed = ClinicVisit::factory()->completed()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.index'))
        ->assertOk()
        ->assertDontSee($completed->visit_number);
});

it('cashier create page displays odontogram and RME clinical summary', function () {
    [$visit, $record] = makeFinalizedVisit($this->branch);

    Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'medical_record_id' => $record->id,
        'status' => Odontogram::STATUS_FINALIZED,
        'summary_notes' => 'Catatan odontogram kasir',
        'additional_conditions' => 'Kondisi tambahan umum',
        'tooth_map_payload' => [
            'teeth' => [
                '16' => [
                    'status' => 'caries',
                    'conditions' => ['filling'],
                    'note' => 'Karies mesial',
                ],
            ],
        ],
    ]);

    MedicalRecordHandwriting::factory()->create([
        'medical_record_id' => $record->id,
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'doctor_id' => $visit->doctor_id,
        'handwriting_path' => 'data:image/png;base64,'.base64_encode('fake-png'),
    ]);

    $this->actingAs($this->cashier)
        ->get(route('rme.cashier.create', $visit))
        ->assertOk()
        ->assertSee('Ringkasan Klinis')
        ->assertSee('RME Tulisan Tangan')
        ->assertSee('Odontogram')
        ->assertSee('Catatan odontogram kasir')
        ->assertSee('Karies')
        ->assertSee('Tanda Klinis')
        ->assertSee('Karies mesial');
});

it('cashier can view rme receivables page with unpaid and partial balances', function () {
    $this->actingAs($this->cashier);

    [$partialVisit] = makeFinalizedVisit($this->branch);
    $partialInvoice = app(RmeInvoiceService::class)->create(
        $partialVisit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Tambal Cicilan', 'unit_price' => 500000])]]
    );

    RmePayment::factory()->create([
        'branch_id' => $this->branch->id,
        'rme_invoice_id' => $partialInvoice->id,
        'clinic_visit_id' => $partialVisit->id,
        'patient_id' => $partialVisit->patient_id,
        'cashier_id' => $this->cashier->id,
        'amount' => 200000,
        'paid_at' => now(),
    ]);

    $partialInvoice->update(['status' => RmeInvoice::STATUS_PARTIAL]);

    [$unpaidVisit] = makeFinalizedVisit($this->branch);
    $unpaidInvoice = app(RmeInvoiceService::class)->create(
        $unpaidVisit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Tagihan Belum Bayar', 'unit_price' => 300000])]]
    );

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee('Piutang RME')
        ->assertSee($partialInvoice->invoice_number)
        ->assertSee($unpaidInvoice->invoice_number)
        ->assertSee('Cicilan / Sebagian')
        ->assertSee('Belum Dibayar')
        ->assertSee('Sisa Tagihan')
        ->assertSee('Bayar Cicilan');
});

it('rme receivables page filters by partial status', function () {
    $this->actingAs($this->cashier);

    [$partialVisit] = makeFinalizedVisit($this->branch);
    $partialInvoice = app(RmeInvoiceService::class)->create(
        $partialVisit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Piutang Partial', 'unit_price' => 400000])]]
    );

    RmePayment::factory()->create([
        'branch_id' => $this->branch->id,
        'rme_invoice_id' => $partialInvoice->id,
        'clinic_visit_id' => $partialVisit->id,
        'patient_id' => $partialVisit->patient_id,
        'cashier_id' => $this->cashier->id,
        'amount' => 100000,
        'paid_at' => now(),
    ]);

    $partialInvoice->update(['status' => RmeInvoice::STATUS_PARTIAL]);

    [$unpaidVisit] = makeFinalizedVisit($this->branch);
    $unpaidInvoice = app(RmeInvoiceService::class)->create(
        $unpaidVisit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Piutang Unpaid', 'unit_price' => 250000])]]
    );

    $this->get(route('rme.cashier.receivables', ['status' => RmeInvoice::STATUS_PARTIAL]))
        ->assertOk()
        ->assertSee($partialInvoice->invoice_number)
        ->assertDontSee($unpaidInvoice->invoice_number);
});

it('unauthorized user cannot view rme receivables page', function () {
    $this->actingAs($this->unauthorized);

    $this->get(route('rme.cashier.receivables'))
        ->assertForbidden();
});

// ─── Sprint 24.6 — Aging + CSV export ───────────────────────────────────────

function makeReceivableInvoice(Branch $branch, User $cashier, int $ageDays, float $unitPrice = 300000): RmeInvoice
{
    [$visit] = makeFinalizedVisit($branch);
    $invoice = app(RmeInvoiceService::class)->create(
        $visit,
        $cashier,
        ['items' => [makeItemPayload(['description' => 'Tagihan Aging', 'unit_price' => $unitPrice])]]
    );

    RmeInvoice::query()->whereKey($invoice->id)->update(['created_at' => now()->subDays($ageDays)]);

    return $invoice->fresh();
}

it('cashier can view receivable aging summary', function () {
    $this->actingAs($this->cashier);

    makeReceivableInvoice($this->branch, $this->cashier, 2);   // 0-7
    makeReceivableInvoice($this->branch, $this->cashier, 10);  // 8-14
    makeReceivableInvoice($this->branch, $this->cashier, 20);  // 15-30
    makeReceivableInvoice($this->branch, $this->cashier, 45);  // >30

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee('0–7 Hari')
        ->assertSee('8–14 Hari')
        ->assertSee('15–30 Hari')
        ->assertSee('>30 Hari');
});

it('cashier can filter receivables by aging bucket', function () {
    $this->actingAs($this->cashier);

    $inBucket = makeReceivableInvoice($this->branch, $this->cashier, 10);  // 8-14
    $otherBucket = makeReceivableInvoice($this->branch, $this->cashier, 2); // 0-7

    $this->get(route('rme.cashier.receivables', ['aging_bucket' => '8-14']))
        ->assertOk()
        ->assertSee($inBucket->invoice_number)
        ->assertDontSee($otherBucket->invoice_number);
});

it('cashier can export filtered receivables as csv', function () {
    $this->actingAs($this->cashier);

    $inBucket = makeReceivableInvoice($this->branch, $this->cashier, 10);  // 8-14
    $otherBucket = makeReceivableInvoice($this->branch, $this->cashier, 2); // 0-7

    $response = $this->get(route('rme.cashier.receivables.export', ['aging_bucket' => '8-14']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->headers->get('content-disposition'))->toContain('attachment');

    $csv = $response->streamedContent();

    expect($csv)->toContain('No Invoice');
    expect($csv)->toContain('Bucket Aging');
    expect($csv)->toContain($inBucket->invoice_number);
    expect($csv)->not->toContain($otherBucket->invoice_number);
});

it('paid invoices are excluded from aging and export', function () {
    $this->actingAs($this->cashier);

    $active = makeReceivableInvoice($this->branch, $this->cashier, 10);

    [$paidVisit] = makeFinalizedVisit($this->branch);
    $paidInvoice = app(RmeInvoiceService::class)->create(
        $paidVisit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Lunas', 'unit_price' => 300000])]]
    );
    $paidInvoice->update(['status' => RmeInvoice::STATUS_PAID]);

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee($active->invoice_number)
        ->assertDontSee($paidInvoice->invoice_number);

    $csv = $this->get(route('rme.cashier.receivables.export'))->streamedContent();
    expect($csv)->toContain($active->invoice_number);
    expect($csv)->not->toContain($paidInvoice->invoice_number);
});

// ─── Sprint 27 Phase 27.4.2 — Exclude zero-remaining invoices from receivables ──

function makeZeroGrandTotalUnpaidInvoice(Branch $branch): RmeInvoice
{
    [$visit] = makeFinalizedVisit($branch);

    return RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'subtotal' => 0,
        'discount_total' => 0,
        'grand_total' => 0,
    ]);
}

it('excludes zero-grand-total unpaid invoices from active receivables', function () {
    $this->actingAs($this->cashier);

    $active = makeReceivableInvoice($this->branch, $this->cashier, 5);
    $zeroInvoice = makeZeroGrandTotalUnpaidInvoice($this->branch);

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee($active->invoice_number)
        ->assertDontSee($zeroInvoice->invoice_number);
});

it('excludes zero-remaining settled invoices from active receivables', function () {
    $this->actingAs($this->cashier);

    [$settledVisit] = makeFinalizedVisit($this->branch);
    $settledInvoice = app(RmeInvoiceService::class)->create(
        $settledVisit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Lunas Sebagian', 'unit_price' => 100000])]]
    );

    RmePayment::factory()->create([
        'branch_id' => $this->branch->id,
        'rme_invoice_id' => $settledInvoice->id,
        'clinic_visit_id' => $settledVisit->id,
        'patient_id' => $settledVisit->patient_id,
        'cashier_id' => $this->cashier->id,
        'amount' => 100000,
        'paid_at' => now(),
    ]);

    // Stale status: still PARTIAL even though fully covered by payments.
    $settledInvoice->update(['status' => RmeInvoice::STATUS_PARTIAL]);

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertDontSee($settledInvoice->invoice_number);
});

it('still shows unpaid invoice with positive remaining balance', function () {
    $this->actingAs($this->cashier);

    [$partialVisit] = makeFinalizedVisit($this->branch);
    $partialInvoice = app(RmeInvoiceService::class)->create(
        $partialVisit,
        $this->cashier,
        ['items' => [makeItemPayload(['description' => 'Cicilan Besar', 'unit_price' => 1500000])]]
    );

    RmePayment::factory()->create([
        'branch_id' => $this->branch->id,
        'rme_invoice_id' => $partialInvoice->id,
        'clinic_visit_id' => $partialVisit->id,
        'patient_id' => $partialVisit->patient_id,
        'cashier_id' => $this->cashier->id,
        'amount' => 900000,
        'paid_at' => now(),
    ]);

    $partialInvoice->update(['status' => RmeInvoice::STATUS_PARTIAL]);

    $this->get(route('rme.cashier.receivables'))
        ->assertOk()
        ->assertSee($partialInvoice->invoice_number);

    // Remaining 600000 surfaces in the stable CSV export representation.
    $csv = $this->get(route('rme.cashier.receivables.export'))->streamedContent();
    expect($csv)->toContain($partialInvoice->invoice_number);
    expect($csv)->toContain('600000.00');
});

it('excludes zero-grand-total invoice from receivable aging summary', function () {
    $this->actingAs($this->cashier);

    $active = makeReceivableInvoice($this->branch, $this->cashier, 5, 300000);
    makeZeroGrandTotalUnpaidInvoice($this->branch);

    $response = $this->get(route('rme.cashier.receivables'))->assertOk();

    // Only the single real receivable is counted; the Rp0 invoice does not inflate totals.
    $summary = $response->viewData('summary');
    expect($summary['invoice_count'])->toBe(1);
    expect($summary['grand_total'])->toBe(300000.0);
    expect($summary['remaining_total'])->toBe(300000.0);

    $response->assertSee($active->invoice_number);
});

it('excludes zero-grand-total invoice from receivable export', function () {
    $this->actingAs($this->cashier);

    $active = makeReceivableInvoice($this->branch, $this->cashier, 5);
    $zeroInvoice = makeZeroGrandTotalUnpaidInvoice($this->branch);

    $csv = $this->get(route('rme.cashier.receivables.export'))->streamedContent();

    expect($csv)->toContain($active->invoice_number);
    expect($csv)->not->toContain($zeroInvoice->invoice_number);
});
