<?php

declare(strict_types=1);

/*
| HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1 (rule 217 §9) —
| the revised Owner decision S6-Q3: a Kontrol may be Rp0, an additional treatment
| that is normally paid is billed at a positive price and paid normally, and a
| Kontrol link never makes a paid treatment free. Each test carries the scenario
| id of the master prompt (RP0-REV-xx).
|
| Approved free services (S6-Q3a, 2026-11-01): the `consultation` category on any
| visit, plus the reserved `control` category on a VALID Kontrol only. Nothing
| else at Rp0 can be closed "Tanpa Biaya", whoever closes it.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeZeroValueCategory;
use App\Modules\RmeInvoice\Support\RmeZeroValueEligibility;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rzvSetup();

    $t = test();
    $category = fn (string $code, string $name) => TreatmentCategory::query()->firstOrCreate(['code' => $code], ['name' => $name]);
    $make = fn (string $code, string $name, string $categoryCode, bool $lab = false) => Treatment::factory()->create([
        'code' => $code, 'name' => $name, 'treatment_category_id' => $category($categoryCode, ucfirst($categoryCode))->id,
        'is_active' => true, 'requires_lab' => $lab,
    ]);

    $t->scaling = $make('TRT-SCAL', 'Scaling Gigi', 'preventive');
    $t->tambal = $make('TRT-FILL', 'Tambal Komposit', 'restoration');
    $t->crown = $make('TRT-CRWN', 'Crown Porselen', 'prosthodontic', true);
    $t->controlService = $make('TRT-KONTROL', 'Kontrol', 'control');
});

function rzhIssueRefusal(ClinicVisit $visit, array $lines): ?string
{
    try {
        rzvInvoice($visit, $lines);
    } catch (ValidationException $e) {
        return (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    return null;
}

/** A completed parent visit carrying a real receivable (PARTIAL), and a Kontrol of it. */
function rzhParentWithReceivable(int $total = 500000, int $paid = 100000): array
{
    $parentVisit = ricVisit();
    $parent = rzvInvoice($parentVisit, [rzvLine(test()->tambal, $total)]);
    ricPay($parent, $paid);

    return [$parentVisit->fresh(), $parent->fresh()];
}

function rzhPayControlBatch(RmeInvoice $control, int $amount): void
{
    test()->actingAs(test()->cashier);
    app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->cashier, rmeFreshPaymentForm($control->fresh(), [
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ]));
}

// ─── Legitimate free services ──────────────────────────────────────────────────

it('RP0-REV-01: closes a Kontrol whose only line is the Rp0 Kontrol service', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService, 0, 0, 'Kontrol')]);

    $closed = rzvClose($invoice);

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($closed->zero_value_category)->toBe(RmeZeroValueCategory::CONTROL)
        ->and($invoice->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('RP0-REV-02: closes a Kontrol carrying the Kontrol service and a valid Konsultasi Dokter, both Rp0', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->consultation)]);

    expect(rzvClose($invoice)->status)->toBe(RmeInvoice::STATUS_NO_CHARGE);
});

// ─── Mixed Rp0 + paid: regular billing ────────────────────────────────────────

it('RP0-REV-03: bills a Kontrol plus Scaling Rp250.000 normally', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->scaling, 250000, 0, 'Scaling')]);

    expect((float) $invoice->grand_total)->toEqual(250000.0)
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::TOTAL_NOT_ZERO);

    ricPay($invoice, 250000);

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($invoice->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and((float) RmePayment::query()->where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(250000.0);
});

it('RP0-REV-04: bills a Kontrol plus Tambal Rp350.000 normally', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->tambal, 350000, 0, 'Tambal')]);

    expect((float) $invoice->grand_total)->toEqual(350000.0)
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::TOTAL_NOT_ZERO);

    ricPay($invoice, 350000);

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('RP0-REV-05: totals a Kontrol plus two paid treatments correctly', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [
        rzvLine(test()->controlService),
        rzvLine(test()->scaling, 250000, 0, 'Scaling'),
        rzvLine(test()->tambal, 350000, 0, 'Tambal'),
    ]);

    expect((float) $invoice->grand_total)->toEqual(600000.0)
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::TOTAL_NOT_ZERO);
});

// ─── A normally paid treatment at Rp0 ─────────────────────────────────────────

it('RP0-REV-06: refuses NO_CHARGE for a Kontrol carrying a non-free Scaling at Rp0', function () {
    $invoice = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->scaling, 0, 0, 'Scaling')]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rzvClosureAudits())->toBe(0);
});

it('RP0-REV-06b: with the flag ON a Kontrol invoice cannot even be ISSUED with a normally paid treatment at Rp0 (S6-Q3b)', function () {
    $visit = rzvControlVisit();
    $before = RmeInvoice::query()->count();

    expect(rzhIssueRefusal($visit, [rzvLine(test()->controlService), rzvLine(test()->scaling, 0, 0, 'Scaling')]))
        ->toContain('Rp0')
        ->and(rzhIssueRefusal($visit, [rzvLine(test()->controlService), rzvLine(null, 0, 0, 'Kontrol')]))->toContain('Rp0')
        ->and(rzhIssueRefusal($visit, [rzvLine(test()->crown, 0, 0, 'Crown garansi')]))->toContain('Rp0')
        ->and(RmeInvoice::query()->count())->toBe($before);

    // The same paid treatment at a positive price issues normally.
    expect((float) rzvInvoice($visit, [rzvLine(test()->controlService), rzvLine(test()->scaling, 250000, 0, 'Scaling')])->grand_total)
        ->toEqual(250000.0);
});

it('RP0-REV-07: a Kontrol link never makes a non-free treatment free — the evaluator itself refuses', function () {
    $invoice = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->scaling, 0, 0, 'Scaling')])->load('items.treatment.category');

    $result = app(RmeZeroValueEligibility::class)->evaluate($invoice, $invoice->clinicVisit, 0);

    expect(RmeZeroValueEligibility::isControlVisit($invoice->clinicVisit))->toBeTrue()
        ->and($result->eligible())->toBeFalse()
        ->and($result->reason())->toBe(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE);
});

it('RP0-REV-08: a renamed description or a free-text line never makes a treatment free', function () {
    $renamed = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->scaling, 0, 0, 'Kontrol / Konsultasi Dokter')]);
    $freeText = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(null, 0, 0, 'Kontrol')]);

    expect(rzvRefusal($renamed))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE)
        ->and(rzvRefusal($freeText))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE);
});

it('RP0-REV-09: a paid treatment can never drop out of the invoice total — a submitted total is ignored', function () {
    $invoice = app(RmeInvoiceService::class)->create(rzvControlVisit(), test()->cashier, [
        'items' => [rzvLine(test()->controlService), rzvLine(test()->scaling, 250000, 0, 'Scaling')],
        'grand_total' => 0,
        'subtotal' => 0,
    ])->refresh();

    expect((float) $invoice->grand_total)->toEqual(250000.0)
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::TOTAL_NOT_ZERO);
});

// ─── Lab ───────────────────────────────────────────────────────────────────────

it('RP0-REV-10: a line requiring lab work cannot use NO_CHARGE', function () {
    $invoice = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->crown, 0, 0, 'Crown garansi')]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::LAB_LINE_EXCLUDED);
});

it('RP0-REV-11: a PAID lab treatment on a Kontrol follows normal payment and the lab workflow', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->crown, 1500000, 0, 'Crown')]);

    ricPay($invoice, 1500000);

    $crownItem = $invoice->items()->where('treatment_id', test()->crown->id)->sole();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(LabCaseCandidate::query()->where('rme_invoice_item_id', $crownItem->id)->count())->toBe(1);
});

it('RP0-REV-12: a treatment discounted to Rp0 is not an approved free service', function () {
    // REVISED after the independent security review (MEDIUM, issuance bypass by a
    // 100% discount): with the flag ON the Kontrol invoice is no longer even
    // issued; issued while OFF, the closure still refuses it.
    expect(rzhIssueRefusal(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->scaling, 250000, 250000, 'Scaling')]))
        ->toContain(RmeZeroValueEligibility::LINE_NOT_FREE);

    $invoice = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->controlService), rzvLine(test()->scaling, 250000, 250000, 'Scaling')]);

    expect((float) $invoice->grand_total)->toEqual(0.0)
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::LINE_NOT_FREE);
});

it('RP0-REV-12b: a 100% discount cannot slip a paid or lab treatment past the issuance guard; a partial discount bills normally', function () {
    $visit = rzvControlVisit();
    $before = RmeInvoice::query()->count();

    expect(rzhIssueRefusal($visit, [rzvLine(test()->controlService), rzvLine(test()->scaling, 250000, 250000, 'Scaling')]))->toContain(RmeZeroValueEligibility::LINE_NOT_FREE)
        ->and(rzhIssueRefusal($visit, [rzvLine(test()->crown, 1500000, 1500000, 'Crown')]))->toContain(RmeZeroValueEligibility::LINE_NOT_FREE)
        // An approved free service is priced at Rp0 directly, never discounted to it.
        ->and(rzhIssueRefusal($visit, [rzvLine(test()->consultation, 100000, 100000, 'Konsultasi')]))->toContain(RmeZeroValueEligibility::LINE_NOT_FREE)
        ->and(RmeInvoice::query()->count())->toBe($before);

    $partial = rzvInvoice($visit, [rzvLine(test()->controlService), rzvLine(test()->scaling, 250000, 50000, 'Scaling')]);

    expect((float) $partial->grand_total)->toEqual(200000.0)
        ->and(rzvRefusal($partial))->toContain(RmeZeroValueEligibility::TOTAL_NOT_ZERO);
});

it('RP0-REV-12c: a Kontrol with an INVALID parent cannot be issued at Rp0, so no unpayable and unclosable invoice is created (S6-Q1)', function () {
    foreach ([ClinicVisit::STATUS_CANCELLED, ClinicVisit::STATUS_REGISTERED] as $status) {
        $parent = ricVisit();
        DB::table('trx_clinic_visits')->where('id', $parent->id)->update(['status' => $status]);
        $visit = rzvControlVisit($parent->fresh());
        $before = RmeInvoice::query()->count();

        expect(rzhIssueRefusal($visit, [rzvLine(test()->consultation)]))->toContain(RmeZeroValueEligibility::CONTROL_PARENT_INVALID)
            ->and(rzhIssueRefusal($visit, [rzvLine(test()->controlService)]))->toContain(RmeZeroValueEligibility::CONTROL_PARENT_INVALID)
            ->and(RmeInvoice::query()->count())->toBe($before);

        // A positive price is billed and paid normally.
        expect((float) rzvInvoice($visit, [rzvLine(test()->consultation, 100000, 0, 'Konsultasi')])->grand_total)->toEqual(100000.0);
    }
});

it('RP0-REV-12d: the voucher cannot be issued at Rp0 on a Kontrol either', function () {
    expect(rzhIssueRefusal(rzvControlVisit(), [rzvLine(test()->voucher, 0, 0, 'Voucher scaling')]))
        ->toContain(RmeZeroValueEligibility::VOUCHER_EXCLUDED);
});

it('RP0-REV-12e: issuance and closure decide every Rp0 line with ONE predicate — no drift', function () {
    $cases = [
        'kontrol service' => [rzvLine(test()->controlService), null],
        'konsultasi' => [rzvLine(test()->consultation), null],
        'scaling at Rp0' => [rzvLine(test()->scaling, 0, 0, 'Scaling'), RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE],
        'free text' => [rzvLine(null, 0, 0, 'Kontrol'), RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE],
        'lab at Rp0' => [rzvLine(test()->crown, 0, 0, 'Crown'), RmeZeroValueEligibility::LAB_LINE_EXCLUDED],
        'voucher' => [rzvLine(test()->voucher, 0, 0, 'Voucher'), RmeZeroValueEligibility::VOUCHER_EXCLUDED],
        'discount to zero' => [rzvLine(test()->scaling, 250000, 250000, 'Scaling'), RmeZeroValueEligibility::LINE_NOT_FREE],
    ];

    foreach ($cases as $label => [$line, $expected]) {
        $issuance = rzhIssueRefusal(rzvControlVisit(), [$line]);
        $closure = rzvRefusal(rzvIssueWhileOff(rzvControlVisit(), [$line]));

        if ($expected === null) {
            expect($issuance)->toBeNull($label)->and($closure)->toBeNull($label);
        } else {
            expect($issuance)->toContain($expected)->and($closure)->toContain($expected);
        }
    }
});

// ─── Authority never substitutes for eligibility ──────────────────────────────

it('RP0-REV-13: a DIFFERENT account closing does not make an ineligible invoice eligible', function () {
    $invoice = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->scaling, 0, 0, 'Scaling')]);
    $other = User::factory()->create();
    rmeMakeKasirActive($other, test()->branch);

    expect((int) $invoice->cashier_id)->not->toBe((int) $other->id)
        ->and(rzvRefusal($invoice, $other))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('RP0-REV-14: Super Admin cannot bypass financial eligibility', function () {
    $invoice = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->scaling, 0, 0, 'Scaling')]);

    expect(rzvRefusal($invoice, superAdmin()))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('RP0-REV-15: a cashier of another branch is refused', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService)]);
    $other = Branch::factory()->create(['code' => 'IC09', 'is_rme_enabled' => true, 'is_active' => true]);
    $kasir = User::factory()->create();
    rmeMakeKasirActive($kasir, $other);

    expect(rzvRefusal($invoice, $kasir))->toContain('cabang lain')
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

// ─── S6-Q1 — the Kontrol parent ───────────────────────────────────────────────

it('RP0-REV-16: the Kontrol parent must belong to the same patient', function () {
    $parent = ricVisit();
    $invoice = rzvInvoice(rzvControlVisit($parent), [rzvLine(test()->controlService)]);
    DB::table('trx_clinic_visits')->where('id', $parent->id)->update(['patient_id' => Patient::factory()->create()->id]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CONTROL_PARENT_INVALID);
});

it('RP0-REV-17: a cancelled or never-examined parent is rejected', function () {
    foreach ([ClinicVisit::STATUS_CANCELLED, ClinicVisit::STATUS_REGISTERED, ClinicVisit::STATUS_WAITING] as $status) {
        $parent = ricVisit();
        $invoice = rzvInvoice(rzvControlVisit($parent), [rzvLine(test()->controlService)]);
        DB::table('trx_clinic_visits')->where('id', $parent->id)->update(['status' => $status]);

        expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CONTROL_PARENT_INVALID);
    }
});

it('S6-Q3a: the reserved Kontrol category is free ONLY on a valid Kontrol, never on a new visit', function () {
    $onNewVisit = rzvIssueWhileOff(rzvNewVisit(), [rzvLine(test()->controlService)]);

    expect(rzvRefusal($onNewVisit))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE);
});

// ─── The Kontrol batch residual (#36 pattern) ─────────────────────────────────

it('RP0-REV-18: a Kontrol batch that pays the parent receivable no longer completes the visit around an eligible Rp0 invoice', function () {
    [$parentVisit, $parent] = rzhParentWithReceivable();
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->controlService)]);

    rzhPayControlBatch($control, 400000);

    expect($control->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($control->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);

    // The explicit, authorized action is still available and is what closes it.
    expect(rzvClose($control)->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($control->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);
});

it('RP0-REV-18b: an ordinary carry-over batch no longer completes a new visit around an eligible Rp0 Konsultasi', function () {
    [, $prior] = rzhParentWithReceivable();
    $current = rzvConsultationInvoice();

    test()->actingAs(test()->cashier);
    app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), test()->cashier, rmeFreshPaymentForm($current->fresh(), [
        'amount' => 400000, 'paid_at' => now()->format('Y-m-d H:i:s'),
    ]), [$prior->id]);

    expect($prior->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($current->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(rzvClose($current)->status)->toBe(RmeInvoice::STATUS_NO_CHARGE);
});

it('RP0-REV-18c: an INELIGIBLE Rp0 Kontrol keeps the existing batch completion (only the closable case is held)', function () {
    [$parentVisit] = rzhParentWithReceivable();
    $control = rzvIssueWhileOff(rzvControlVisit($parentVisit), [rzvLine(test()->voucher, 0, 0, 'Voucher scaling')]);

    rzhPayControlBatch($control, 400000);

    expect($control->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('RP0-REV-19: the parent payment is attributed only to the parent invoice', function () {
    [$parentVisit, $parent] = rzhParentWithReceivable();
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->controlService)]);
    $parentPaymentsBefore = RmePayment::query()->where('rme_invoice_id', $parent->id)->count();

    rzhPayControlBatch($control, 400000);

    expect(RmePayment::query()->where('rme_invoice_id', $control->id)->count())->toBe(0)
        ->and(RmePayment::query()->where('rme_invoice_id', $parent->id)->count())->toBe($parentPaymentsBefore + 1)
        ->and((float) RmePayment::query()->where('rme_invoice_id', $parent->id)->sum('amount'))->toEqual(500000.0);
});

it('RP0-REV-20: a legitimate parent receivable payment stays possible, before or after the closure', function () {
    [$parentVisit, $parent] = rzhParentWithReceivable();
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->controlService)]);

    rzvClose($control);
    ricPay($parent->fresh(), 400000);

    expect($parent->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE);
});

// ─── Financial invariants ─────────────────────────────────────────────────────

it('RP0-REV-21/22/23: NO_CHARGE writes no payment, adds no revenue, and neither creates nor erases a receivable', function () {
    [$parentVisit, $parent] = rzhParentWithReceivable();
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->controlService)]);
    $moneyBefore = rzvMoney();
    $parentRemainingBefore = $parent->fresh()->remainingAmount();

    rzvClose($control);

    expect(rzvMoney())->toBe($moneyBefore)
        ->and(RmePayment::query()->where('rme_invoice_id', $control->id)->count())->toBe(0)
        ->and($parent->fresh()->remainingAmount())->toEqual($parentRemainingBefore)
        ->and($control->fresh()->remainingAmount())->toEqual(0.0);
});

it('RP0-REV-24: a duplicate closure is idempotent', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService)]);
    rzvClose($invoice);
    rzvClose($invoice);

    expect(rzvClosureAudits($invoice))->toBe(1);
});

it('RP0-REV-27: closing one invoice leaves every other invoice untouched', function () {
    $other = rzvInvoice(ricVisit(), [rzvLine(test()->tambal, 200000)]);
    ricPay($other, 50000);
    $target = rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService)]);
    $snapshot = fn () => DB::table('trx_rme_invoices')->where('id', '!=', $target->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    $before = $snapshot();

    rzvClose($target);

    expect($snapshot())->toBe($before);
});

// ─── Flag OFF = today's production ────────────────────────────────────────────

it('RP0-REV-28: with the flag OFF, issuance, the Kontrol batch and the closure behave exactly as before', function () {
    rzvFlag(false);

    // Issuance: a normally paid treatment at Rp0 on a Kontrol is still accepted.
    $loose = rzvInvoice(rzvControlVisit(), [rzvLine(test()->scaling, 0, 0, 'Scaling')]);
    expect($loose->exists)->toBeTrue();

    // Batch: the existing Phase 27.4.1 completion is untouched.
    [$parentVisit] = rzhParentWithReceivable();
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->controlService)]);
    rzhPayControlBatch($control, 400000);
    expect($control->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED);

    // Closure: refused while the capability is off.
    expect(rzvRefusal(rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService)])))
        ->toContain(RmeZeroValueEligibility::NOT_ENABLED);
});

// ─── Neighbouring guards and refusal behaviour ────────────────────────────────

it('RP0-REV-29: payment on a NO_CHARGE invoice is refused, and the dispute guard still refuses it', function () {
    $invoice = rzvClose(rzvInvoice(rzvControlVisit(), [rzvLine(test()->controlService)]));

    expect(fn () => ricPay($invoice, 1000))->toThrow(ValidationException::class);
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('RP0-REV-30: every eligibility refusal answers under one key, names its stable code, writes no audit row and changes nothing', function () {
    $cases = [
        RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE => rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->scaling, 0, 0, 'Scaling')]),
        RmeZeroValueEligibility::LAB_LINE_EXCLUDED => rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->crown, 0, 0, 'Crown')]),
        RmeZeroValueEligibility::VOUCHER_EXCLUDED => rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->voucher, 0, 0, 'Voucher')]),
        RmeZeroValueEligibility::LINE_NOT_FREE => rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->scaling, 250000, 250000, 'Scaling')]),
        RmeZeroValueEligibility::TOTAL_NOT_ZERO => rzvInvoice(rzvControlVisit(), [rzvLine(test()->scaling, 250000, 0, 'Scaling')]),
    ];

    foreach ($cases as $code => $invoice) {
        $before = $invoice->fresh()->only(['status', 'grand_total', 'zero_value_category', 'zero_value_closed_by', 'zero_value_closed_at']);

        try {
            rzvClose($invoice);
            $errors = [];
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        expect(array_keys($errors))->toBe(['zero_value'])
            ->and((string) json_encode($errors))->toContain($code)
            ->and($invoice->fresh()->only(array_keys($before)))->toBe($before);
    }

    expect(rzvClosureAudits())->toBe(0);
});

// ─── The cashier's next-step message (security review LOW: flag OFF changes nothing) ─

function rzhPostControlBatch(RmeInvoice $control, int $amount)
{
    return test()->actingAs(test()->cashier)
        ->post(route('rme.cashier.payment.store', [$control->clinicVisit, $control]), rmeFreshPaymentForm($control->fresh(), [
            'amount' => $amount,
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
}

it('RP0-REV-18d: the "selesaikan dengan Selesai Tanpa Biaya" message appears when — and only when — the visit was held', function () {
    [$parentVisit] = rzhParentWithReceivable();
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->controlService)]);

    rzhPostControlBatch($control, 400000)->assertSessionHas('status', fn ($status) => str_contains($status, 'Selesai Tanpa Biaya'));
    expect($control->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('RP0-REV-18e: a Kontrol whose OWN invoice still has a balance never gets the Rp0 message, flag ON or OFF', function (bool $flag) {
    rzvFlag($flag);
    [$parentVisit] = rzhParentWithReceivable();
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->controlService), rzvLine(test()->scaling, 100000, 0, 'Scaling')]);

    rzhPostControlBatch($control, 400000)
        ->assertSessionHas('status', fn ($status) => ! str_contains($status, 'Selesai Tanpa Biaya') && str_contains($status, 'Pembayaran berhasil dicatat'));

    expect($control->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
})->with(['flag ON' => true, 'flag OFF' => false]);
