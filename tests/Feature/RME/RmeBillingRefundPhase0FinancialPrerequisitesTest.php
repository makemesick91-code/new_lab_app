<?php

/*
 * FEATURE-RME-BILLING-REFUND-1 / Phase 0 — financial safety prerequisites.
 *
 * A refund workflow is only as trustworthy as the ledger it refunds against.
 * These tests pin the four prerequisites shipped before any refund code:
 *   BCF-02  invoice creation and payment recording are audited, PII-free
 *   BCF-08  invoice creation is serialized per visit (the gate reads the locked row)
 *   BCF-03  paid_at can never lie in the future (and is NOT a backdating rule)
 *   BCF-09  a recorded payment is immutable at the application layer
 *
 * Concurrency (two simultaneous creates) needs real row locks and lives in the
 * PostgreSQL-only sibling suite; SQLite compiles lockForUpdate() to nothing.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['code' => 'RF01', 'is_rme_enabled' => true]);
    $this->doctor = Doctor::factory()->create();
    $this->treatment = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false]);
    $this->cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $this->patient = Patient::factory()->create([
        'name' => 'Pasien Rahasia Refund',
        'medical_record_number' => 'DG-RF01-2026-0001',
        'branch_id' => $this->branch->id,
    ]);
});

function rf0Visit(?string $visitDate = null, array $overrides = []): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create(array_merge([
        'branch_id' => test()->branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-RF0-'.uniqid(),
        'visit_date' => $visitDate ?? now()->toDateString(),
    ], $overrides));

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
    ]);

    rmeSignedConsentFor($visit);

    return $visit;
}

function rf0Invoice(ClinicVisit $visit, float $amount, array $itemOverrides = [], array $data = []): RmeInvoice
{
    return app(RmeInvoiceService::class)->create($visit, test()->cashier, array_merge([
        'items' => [array_merge([
            'description' => 'Tindakan rahasia: pencabutan gigi 36',
            'qty' => 1,
            'unit_price' => $amount,
            'discount' => 0,
            'treatment_id' => test()->treatment->id,
        ], $itemOverrides)],
    ], $data))->refresh();
}

function rf0Payment(float $amount, array $overrides = []): array
{
    return array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ], $overrides);
}

/**
 * @return Collection<int, AuditLog>
 */
function rf0AuditRows(string $action)
{
    return AuditLog::query()->where('action', $action)->orderBy('id')->get();
}

// ─── BCF-02: invoice creation audit ──────────────────────────────────────────

it('audits invoice creation with the persisted totals and the acting cashier', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 450000, ['discount' => 50000]);

    $rows = rf0AuditRows(RmeFinancialAuditEvent::ACTION_INVOICE_CREATED);

    expect($rows)->toHaveCount(1);
    $row = $rows->first();

    expect($row->entity_type)->toBe(RmeFinancialAuditEvent::ENTITY_INVOICE)
        ->and((int) $row->entity_id)->toBe((int) $invoice->id)
        ->and((int) $row->performed_by)->toBe((int) test()->cashier->id)
        ->and($row->old_values)->toBeNull()
        ->and($row->new_values['invoice_number'])->toBe($invoice->invoice_number)
        ->and($row->new_values['branch_id'])->toBe((int) test()->branch->id)
        ->and($row->new_values['clinic_visit_id'])->toBe((int) $visit->id)
        ->and($row->new_values['status'])->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($row->new_values['item_count'])->toBe(1)
        ->and($row->new_values['subtotal'])->toEqual(450000.0)
        ->and($row->new_values['discount_total'])->toEqual(50000.0)
        ->and($row->new_values['grand_total'])->toEqual(400000.0);
});

it('keeps patient names, item descriptions and notes out of the invoice audit payload', function () {
    $visit = rf0Visit();
    rf0Invoice($visit, 300000, [], ['notes' => 'Catatan kasir pribadi']);

    $payload = json_encode(rf0AuditRows(RmeFinancialAuditEvent::ACTION_INVOICE_CREATED)->first()->new_values);

    expect($payload)
        ->not->toContain('Pasien Rahasia Refund')
        ->not->toContain('pencabutan gigi')
        ->not->toContain('Catatan kasir pribadi')
        ->not->toContain('DG-RF01-2026-0001');
});

it('writes no invoice audit row when creation is refused', function () {
    $visit = rf0Visit();
    $visit->update(['status' => ClinicVisit::STATUS_IN_PROGRESS]);

    expect(fn () => rf0Invoice($visit, 300000))->toThrow(ValidationException::class);

    expect(rf0AuditRows(RmeFinancialAuditEvent::ACTION_INVOICE_CREATED))->toHaveCount(0)
        ->and(RmeInvoice::count())->toBe(0);
});

// ─── BCF-08: invoice creation reads the LOCKED visit row ─────────────────────

it('refuses a second invoice for the same visit', function () {
    $visit = rf0Visit();
    rf0Invoice($visit, 300000);

    expect(fn () => rf0Invoice($visit, 300000))->toThrow(ValidationException::class);

    expect(RmeInvoice::where('clinic_visit_id', $visit->id)->count())->toBe(1)
        ->and(rf0AuditRows(RmeFinancialAuditEvent::ACTION_INVOICE_CREATED))->toHaveCount(1);
});

it('gates on the freshly locked visit row, never on the stale instance it was handed', function () {
    $visit = rf0Visit();

    // A concurrent actor moved the visit on after this instance was loaded.
    // The in-memory model still says cashier_pending; the database does not.
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    expect($visit->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);

    expect(fn () => rf0Invoice($visit, 300000))->toThrow(ValidationException::class);
    expect(RmeInvoice::count())->toBe(0);
});

// ─── BCF-02: payment audit ───────────────────────────────────────────────────

it('audits a recorded payment with before/after ledger figures', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 500000);

    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(200000, [
        'reference_number' => 'TRF-REK-1234567890',
        'notes' => 'Dibayar oleh Pasien Rahasia Refund',
    ]));

    $rows = rf0AuditRows(RmeFinancialAuditEvent::ACTION_PAYMENT_RECORDED);
    expect($rows)->toHaveCount(1);
    $values = $rows->first()->new_values;

    expect($rows->first()->entity_type)->toBe(RmeFinancialAuditEvent::ENTITY_PAYMENT)
        ->and((int) $rows->first()->entity_id)->toBe((int) $payment->id)
        ->and((int) $rows->first()->performed_by)->toBe((int) test()->cashier->id)
        ->and($values['payment_number'])->toBe($payment->payment_number)
        ->and($values['rme_invoice_id'])->toBe((int) $invoice->id)
        ->and($values['branch_id'])->toBe((int) test()->branch->id)
        ->and($values['amount'])->toEqual(200000.0)
        ->and($values['invoice_grand_total'])->toEqual(500000.0)
        ->and($values['invoice_paid_before'])->toEqual(0.0)
        ->and($values['invoice_paid_after'])->toEqual(200000.0)
        ->and($values['payment_batch_uuid'])->toBeNull()
        ->and($values['reference_recorded'])->toBeTrue();

    $payload = json_encode($values);
    expect($payload)
        ->not->toContain('TRF-REK-1234567890')
        ->not->toContain('Pasien Rahasia Refund');
});

it('audits every payment row of a carry-over batch under one batch uuid', function () {
    $prior = rf0Visit(now()->subDays(5)->toDateString());
    $priorInvoice = rf0Invoice($prior, 200000);
    $current = rf0Visit();
    $currentInvoice = rf0Invoice($current, 100000);

    $result = app(RmePaymentService::class)->allocateVisitPayment(
        $currentInvoice,
        test()->cashier,
        rf0Payment(250000),
        [$priorInvoice->id],
    );

    $rows = rf0AuditRows(RmeFinancialAuditEvent::ACTION_PAYMENT_RECORDED);
    $payments = RmePayment::query()->where('payment_batch_uuid', $result->paymentBatchUuid)->orderBy('id')->get();

    expect($payments)->toHaveCount(2)
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('entity_id')->map(fn ($id) => (int) $id)->all())->toBe($payments->pluck('id')->all())
        ->and($rows->pluck('new_values.payment_batch_uuid')->unique()->all())->toBe([$result->paymentBatchUuid])
        ->and($rows->pluck('new_values.amount')->sum())->toEqual(250000.0);
});

it('writes no payment audit row when the payment is refused', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);

    expect(fn () => app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(150000)))
        ->toThrow(ValidationException::class);

    expect(rf0AuditRows(RmeFinancialAuditEvent::ACTION_PAYMENT_RECORDED))->toHaveCount(0)
        ->and(RmePayment::count())->toBe(0);
});

// ─── BCF-03: paid_at upper bound ─────────────────────────────────────────────

it('refuses a paid_at in the future on every payment path', function (string $path) {
    $prior = rf0Visit(now()->subDays(3)->toDateString());
    $priorInvoice = rf0Invoice($prior, 100000);
    $current = $path === 'control-batch'
        ? rf0Visit(null, ['visit_type' => ClinicVisit::VISIT_TYPE_CONTROL, 'follow_up_of_visit_id' => $prior->id])
        : rf0Visit();
    $currentInvoice = rf0Invoice($current, 100000);

    $future = rf0Payment(50000, ['paid_at' => now()->addHours(2)->format('Y-m-d H:i:s')]);
    $service = app(RmePaymentService::class);

    $call = match ($path) {
        'pay' => fn () => $service->pay($currentInvoice, test()->cashier, $future),
        'visit-batch' => fn () => $service->allocateVisitPayment($currentInvoice, test()->cashier, $future, [$priorInvoice->id]),
        'control-batch' => fn () => $service->allocateControlPayment($currentInvoice, test()->cashier, $future),
    };

    try {
        $call();
        $this->fail('A future paid_at was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('paid_at');
    }

    expect(RmePayment::count())->toBe(0)
        ->and(rf0AuditRows(RmeFinancialAuditEvent::ACTION_PAYMENT_RECORDED))->toHaveCount(0);
})->with(['pay', 'visit-batch', 'control-batch']);

it('control allocation still succeeds with a valid paid_at (positive control for the dataset above)', function () {
    $prior = rf0Visit(now()->subDays(3)->toDateString());
    rf0Invoice($prior, 100000);
    $control = rf0Visit(null, ['visit_type' => ClinicVisit::VISIT_TYPE_CONTROL, 'follow_up_of_visit_id' => $prior->id]);
    $controlInvoice = rf0Invoice($control, 50000);

    $result = app(RmePaymentService::class)->allocateControlPayment($controlInvoice, test()->cashier, rf0Payment(150000));

    expect($result->allocatedToParent)->toEqual(100000.0)
        ->and($result->allocatedToControl)->toEqual(50000.0)
        ->and(rf0AuditRows(RmeFinancialAuditEvent::ACTION_PAYMENT_RECORDED))->toHaveCount(2);
});

it('accepts the current minute — the datetime-local field and clock skew are tolerated', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);

    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000, [
        'paid_at' => now()->addSeconds(30)->format('Y-m-d H:i:s'),
    ]));

    expect($payment->exists)->toBeTrue();
});

it('does not bound backdating — that is an owner policy decision, not invented here', function () {
    $visit = rf0Visit(now()->subDays(10)->toDateString());
    $invoice = rf0Invoice($visit, 100000);

    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000, [
        'paid_at' => now()->subDays(9)->format('Y-m-d H:i:s'),
    ]));

    expect($payment->paid_at->toDateString())->toBe(now()->subDays(9)->toDateString());
});

it('keeps the existing interpretation of paid_at (application timezone)', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);
    $submitted = now()->subHour()->format('Y-m-d H:i:s');

    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000, ['paid_at' => $submitted]));

    expect($payment->fresh()->paid_at->format('Y-m-d H:i:s'))->toBe($submitted);
});

it('refuses an unparseable paid_at', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);

    try {
        app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000, ['paid_at' => 'bukan-tanggal']));
        $this->fail('An unparseable paid_at was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('paid_at');
    }
});

it('refuses a blank paid_at instead of inventing "now"', function (mixed $blank) {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);

    try {
        app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000, ['paid_at' => $blank]));
        $this->fail('A blank paid_at was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('paid_at');
    }

    expect(RmePayment::count())->toBe(0);
})->with(['empty string' => [''], 'whitespace' => ['   '], 'null' => [null]]);

it('stores an offset-bearing paid_at as its true instant, closing the offset bypass', function () {
    // Reviewer finding (MEDIUM): Eloquent persists the WALL CLOCK of the offset a
    // value carries. "now" written as +14:00 passed an instant comparison yet was
    // stored 14 hours in the application's future. The value is now converted to
    // the application timezone before it is checked and stored.
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);
    $now = now()->startOfSecond();

    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000, [
        'paid_at' => $now->copy()->setTimezone('+14:00')->format('Y-m-d\TH:i:sP'),
    ]));

    $stored = Carbon\Carbon::parse(DB::table('trx_rme_payments')->where('id', $payment->id)->value('paid_at'), config('app.timezone'));

    expect($stored->equalTo($now))->toBeTrue('Stored '.$stored->toIso8601String().' instead of '.$now->toIso8601String());
});

it('refuses a future instant even when it is written with a negative offset', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);

    try {
        app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000, [
            'paid_at' => now()->addHours(3)->setTimezone('-10:00')->format('Y-m-d\TH:i:sP'),
        ]));
        $this->fail('A future instant written with an offset was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('paid_at');
    }
});

it('surfaces the future paid_at refusal as a field error on the cashier form', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);
    $cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);

    $this->actingAs($cashier)
        ->from(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), [
            'amount' => 100000,
            'paid_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])
        ->assertSessionHasErrors('paid_at');

    expect(RmePayment::count())->toBe(0);
});

// ─── BCF-09: payment immutability ────────────────────────────────────────────

it('refuses to re-amount, re-date, re-point or re-batch a recorded payment', function (string $column, mixed $value) {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);
    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(60000));

    expect(fn () => $payment->update([$column => $value]))->toThrow(LogicException::class);

    expect(DB::table('trx_rme_payments')->where('id', $payment->id)->value($column))
        ->not->toBe($value);
})->with([
    'amount' => ['amount', 1],
    'paid_at' => ['paid_at', '2020-01-01 00:00:00'],
    'rme_invoice_id' => ['rme_invoice_id', 999999],
    'branch_id' => ['branch_id', 999999],
    'payment_batch_uuid' => ['payment_batch_uuid', '00000000-0000-0000-0000-000000000000'],
    'cashier_id' => ['cashier_id', 999999],
]);

it('refuses to soft-delete or force-delete a recorded payment', function (string $method) {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);
    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000));

    expect(fn () => $payment->{$method}())->toThrow(LogicException::class);

    expect(RmePayment::query()->whereKey($payment->id)->exists())->toBeTrue()
        ->and((float) $invoice->fresh()->paidAmount())->toEqual(100000.0);
})->with(['delete', 'forceDelete']);

it('still lets patient merge re-point payment ownership', function () {
    $visit = rf0Visit();
    $invoice = rf0Invoice($visit, 100000);
    $payment = app(RmePaymentService::class)->pay($invoice, test()->cashier, rf0Payment(100000));
    $survivor = Patient::factory()->create();

    // The merge executor writes through the query builder (no model events) …
    DB::table('trx_rme_payments')->where('id', $payment->id)->update(['patient_id' => $survivor->id]);
    expect((int) $payment->fresh()->patient_id)->toBe((int) $survivor->id);

    // … and patient_id is the one attribute the guard leaves mutable anyway.
    $payment->fresh()->update(['patient_id' => test()->patient->id]);
    expect((int) $payment->fresh()->patient_id)->toBe((int) test()->patient->id);
});
