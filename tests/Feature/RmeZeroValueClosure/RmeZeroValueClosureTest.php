<?php

declare(strict_types=1);

/*
| FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, Sprint 6/7) — "Selesai Tanpa
| Biaya": closing a LEGITIMATE Rp0 invoice of a Konsultasi Dokter or a Kontrol
| visit without fabricating a payment.
|
| Owner decisions 2026-11-01: S6-D1 Konsultasi + Kontrol (every line of a
| server-derived Kontrol visit; only consultation lines on any other visit);
| S6-D2 explicit action → terminal NO_CHARGE, no payment row, visit completed in
| the same transaction, #36 left alone; S6-D3 the branch cashier, system-checked
| eligibility, no second approval; S6-D4 D28 kept; S6-D6 vouchers, discounts,
| corrections and write-offs excluded; S6-D7 every RME branch; S6-D8 flag OFF.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeZeroValueCategory;
use App\Modules\RmeInvoice\Support\RmeZeroValueEligibility;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rzvSetup());

// ─── The legitimate paths ──────────────────────────────────────────────────────

it('closes a legitimate Rp0 Konsultasi Dokter on a new visit as NO_CHARGE and completes the visit, without any payment', function () {
    $invoice = rzvConsultationInvoice();
    $before = rzvMoney();

    $closed = rzvClose($invoice);

    expect($closed->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($closed->isPaid())->toBeFalse()
        ->and($closed->isPayable())->toBeFalse()
        ->and($closed->zero_value_category)->toBe(RmeZeroValueCategory::CONSULTATION)
        ->and((int) $closed->zero_value_closed_by)->toBe((int) test()->cashier->id)
        ->and($closed->zero_value_closed_at)->not->toBeNull()
        ->and((float) $closed->grand_total)->toEqual(0.0)
        ->and($closed->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($closed->clinicVisit->fresh()->completed_at)->not->toBeNull()
        ->and(rzvMoney())->toBe($before)
        ->and(rzvClosureAudits($closed))->toBe(1);
});

// SUPERSEDED 2026-11-01 — this test used to assert the S6-D1 sub-decision "every free
// line of a Kontrol is eligible" by CLOSING exactly this invoice. The Owner revised it
// (S6-Q3): a Kontrol never makes a normally paid treatment or a free-text line free.
// The same invoice is now refused at issuance (flag ON) and, issued the way production
// still allows with the flag OFF, refused at the closure. Kept, inverted, so the old
// behaviour can never come back unnoticed.
it('SUPERSEDED S6-D1 sub-decision: a Kontrol no longer makes every free line eligible (owner S6-Q3 revised)', function () {
    $lines = [
        rzvLine(test()->consultation),
        rzvLine(test()->treatment2, 0, 0, 'Kontrol pasca tambal'),
        rzvLine(null, 0, 0, 'Cek jahitan (teks bebas)'),
    ];

    expect(fn () => rzvInvoice(rzvControlVisit(), $lines))->toThrow(ValidationException::class);

    $visit = rzvControlVisit();
    $invoice = rzvIssueWhileOff($visit, $lines);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('never touches the parent visit receivable when a Kontrol is closed without charge (OD-1a)', function () {
    $parentVisit = ricVisit();
    $parentInvoice = ricInvoice($parentVisit);
    $control = rzvInvoice(rzvControlVisit($parentVisit), [rzvLine(test()->consultation)]);

    rzvClose($control);

    $parentInvoice->refresh();
    expect($parentInvoice->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($parentInvoice->remainingAmount())->toEqual(500000.0)
        ->and($parentVisit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('records the closure in the audit trail with the category, the visit and no money', function () {
    $invoice = rzvConsultationInvoice();
    rzvClose($invoice);

    $audit = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)->sole();
    $new = (array) $audit->new_values;

    expect($audit->entity_type)->toBe(RmeFinancialAuditEvent::ENTITY_INVOICE)
        ->and((int) $audit->entity_id)->toBe((int) $invoice->id)
        ->and((int) $audit->performed_by)->toBe((int) test()->cashier->id)
        ->and($new['status'])->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($new['zero_value_category'])->toBe(RmeZeroValueCategory::CONSULTATION)
        ->and((int) $new['clinic_visit_id'])->toBe((int) $invoice->clinic_visit_id)
        ->and((float) $new['grand_total'])->toEqual(0.0)
        ->and((int) $new['payment_count'])->toBe(0)
        ->and((array) $audit->old_values)->toMatchArray(['status' => RmeInvoice::STATUS_UNPAID]);
});

it('is idempotent: a second closure changes nothing and writes no second audit row', function () {
    $invoice = rzvConsultationInvoice();
    $first = rzvClose($invoice);
    $closedAt = $first->zero_value_closed_at->toIso8601String();

    $second = rzvClose($invoice);

    expect($second->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($second->zero_value_closed_at->toIso8601String())->toBe($closedAt)
        ->and(rzvClosureAudits($invoice))->toBe(1);
});

// ─── Eligibility refusals (S6-D1 / S6-D6) ──────────────────────────────────────

it('refuses an invoice whose total is not zero, even with a free consultation line', function () {
    $invoice = rzvInvoice(rzvNewVisit(), [rzvLine(test()->consultation), rzvLine(test()->treatment, 300000, 0, 'Scaling')]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::TOTAL_NOT_ZERO)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($invoice->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(rzvClosureAudits())->toBe(0);
});

it('refuses a Rp0 reached by a full discount: a discount is not a free service (S6-D6, D21c pending)', function () {
    $invoice = rzvInvoice(rzvNewVisit(), [rzvLine(test()->consultation, 150000, 150000)]);

    expect((float) $invoice->grand_total)->toEqual(0.0)
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::LINE_NOT_FREE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('refuses a full discount on a Kontrol visit as well', function () {
    // HARDENING (2026-11-01, independent review): with the flag ON such a Kontrol
    // invoice is refused at issuance (RP0-REV-12/12b); issued the way production
    // allows with the flag OFF, the closure refuses it.
    $invoice = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->treatment2, 200000, 200000, 'Tambal')]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::LINE_NOT_FREE);
});

it('refuses the TRT-FREESC voucher on any visit, Kontrol included (S6-D6)', function () {
    // On a Kontrol the voucher at Rp0 is already refused at issuance (S6-Q3b); issued
    // the way production allows with the flag OFF, the closure refuses it as well.
    $onControl = rzvIssueWhileOff(rzvControlVisit(), [rzvLine(test()->consultation), rzvLine(test()->voucher, 0, 0, 'Voucher scaling')]);
    $onNew = rzvInvoice(rzvNewVisit(), [rzvLine(test()->voucher, 0, 0, 'Voucher scaling')]);

    expect(rzvRefusal($onControl))->toContain(RmeZeroValueEligibility::VOUCHER_EXCLUDED)
        ->and(rzvRefusal($onNew))->toContain(RmeZeroValueEligibility::VOUCHER_EXCLUDED);
});

it('refuses a free non-consultation line on a new visit (S6-D1)', function () {
    $invoice = rzvInvoice(rzvNewVisit(), [rzvLine(test()->consultation), rzvLine(test()->treatment2, 0, 0, 'Tambal gratis')]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE);
});

it('refuses a free-text line on a new visit: eligibility comes from treatment_id, never from a description', function () {
    $invoice = rzvInvoice(rzvNewVisit(), [rzvLine(null, 0, 0, 'Konsultasi Dokter')]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE);
});

it('does not treat a control visit without a server-side parent as a Kontrol', function () {
    $visit = rzvControlVisit(null, false);
    $invoice = rzvInvoice($visit, [rzvLine(test()->treatment2, 0, 0, 'Kontrol')]);

    expect($visit->follow_up_of_visit_id)->toBeNull()
        ->and(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CATEGORY_NOT_ELIGIBLE);
});

it('refuses an invoice that went through a correction', function () {
    $invoice = rzvConsultationInvoice();
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['correction_version' => 1]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::INVOICE_CORRECTED);
});

it('leaves a historical Rp0 invoice whose visit is already completed untouched (#36 pattern)', function () {
    $invoice = rzvInvoice(rzvControlVisit(), [rzvLine(test()->consultation)]);
    DB::table('trx_clinic_visits')->where('id', $invoice->clinic_visit_id)->update(['status' => ClinicVisit::STATUS_COMPLETED, 'completed_at' => now()]);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::VISIT_NOT_PENDING)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rzvClosureAudits())->toBe(0);
});

it('the pure eligibility evaluator refuses an invoice that already holds a payment', function () {
    $invoice = rzvConsultationInvoice()->load('items.treatment.category');
    $visit = $invoice->clinicVisit;

    $refused = app(RmeZeroValueEligibility::class)->evaluate($invoice, $visit, 1);
    $accepted = app(RmeZeroValueEligibility::class)->evaluate($invoice, $visit, 0);

    expect($refused->eligible())->toBeFalse()
        ->and($refused->reason())->toBe(RmeZeroValueEligibility::HAS_PAYMENTS)
        ->and($accepted->eligible())->toBeTrue()
        ->and($accepted->category())->toBe(RmeZeroValueCategory::CONSULTATION);
});

it('refuses a Rp0 invoice that is not UNPAID (VOID or DRAFT), whatever its lines', function () {
    $void = rzvConsultationInvoice();
    DB::table('trx_rme_invoices')->where('id', $void->id)->update(['status' => RmeInvoice::STATUS_VOID]);
    $draft = rzvConsultationInvoice();
    DB::table('trx_rme_invoices')->where('id', $draft->id)->update(['status' => RmeInvoice::STATUS_DRAFT]);

    expect(rzvRefusal($void))->toContain(RmeZeroValueEligibility::INVOICE_NOT_OPEN)
        ->and(rzvRefusal($draft))->toContain(RmeZeroValueEligibility::INVOICE_NOT_OPEN)
        ->and($void->fresh()->status)->toBe(RmeInvoice::STATUS_VOID)
        ->and($draft->fresh()->status)->toBe(RmeInvoice::STATUS_DRAFT);
});

it('the evaluator refuses a discount on a zero-priced line even though issuance can never write one (defence in depth)', function () {
    $invoice = rzvConsultationInvoice()->load('items.treatment.category');
    $invoice->items->first()->setAttribute('discount', '1000.00');

    $result = app(RmeZeroValueEligibility::class)->evaluate($invoice, $invoice->clinicVisit, 0);

    expect($result->eligible())->toBeFalse()
        ->and($result->reason())->toBe(RmeZeroValueEligibility::LINE_NOT_FREE);
});

// ─── Authority (S6-D3) and branch (S6-D7) ──────────────────────────────────────

it('refuses an account without the billing permission', function () {
    $invoice = rzvConsultationInvoice();

    expect(rzvRefusal($invoice, userWith(['view_clinic_visits'])))->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('refuses a deactivated cashier, judged on the persisted account', function () {
    $invoice = rzvConsultationInvoice();
    $stale = test()->cashier;
    DB::table('users')->where('id', $stale->id)->update(['is_active' => false]);

    expect(rzvRefusal($invoice, $stale))->not->toBeNull()
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('refuses a Kasir working at another branch', function () {
    $invoice = rzvConsultationInvoice();
    $other = Branch::factory()->create(['code' => 'IC02', 'is_rme_enabled' => true, 'is_active' => true]);
    $kasir = User::factory()->create();
    rmeMakeKasirActive($kasir, $other);

    expect(rzvRefusal($invoice, $kasir))->toContain('cabang lain')
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('lets a Kasir working at the invoice branch close it', function () {
    $invoice = rzvConsultationInvoice();
    $kasir = User::factory()->create();
    rmeMakeKasirActive($kasir, test()->branch);

    expect(rzvClose($invoice, $kasir)->status)->toBe(RmeInvoice::STATUS_NO_CHARGE);
});

it('does not let Super Admin bypass the eligibility rules', function () {
    $invoice = rzvInvoice(rzvNewVisit(), [rzvLine(test()->consultation, 150000, 150000)]);

    expect(rzvRefusal($invoice, superAdmin()))->toContain(RmeZeroValueEligibility::LINE_NOT_FREE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

// ─── Flag (S6-D8) ──────────────────────────────────────────────────────────────

it('refuses every closure while the flag is OFF and changes nothing', function () {
    $invoice = rzvConsultationInvoice();
    rzvFlag(false);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::NOT_ENABLED)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($invoice->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

// ─── Holds and neighbours: dispute, D28, payment, lab ──────────────────────────

it('refuses closure while a billing dispute is active (rule 203)', function () {
    $invoice = rzvConsultationInvoice();
    test()->actingAs(test()->cashier);
    app(RmeBillingDisputeService::class)->report($invoice->fresh(), test()->cashier, [
        'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Pasien merasa konsultasi ini seharusnya berbayar.',
    ]);

    expect(rzvRefusal($invoice))->toContain('peninjauan')
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rzvClosureAudits())->toBe(0);
});

it('keeps D28 after closure: a NO_CHARGE invoice can never be corrected', function () {
    $invoice = rzvConsultationInvoice();
    rzvClose($invoice);
    $item = $invoice->items()->first();

    $message = null;
    try {
        ricSubmit($invoice->fresh(), test()->maker, [[
            'original_item_id' => $item->id,
            'treatment_id' => test()->consultation->id,
            'description' => 'Konsultasi dokter',
            'qty' => 1,
            'unit_price' => '150000',
            'discount' => '0',
        ]], 'Ternyata konsultasi ini berbayar.');
    } catch (ValidationException $e) {
        $message = (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    expect($message)->not->toBeNull()
        ->and($message)->toMatch('/CORRECTION_ORIGINAL_TOTAL_ZERO|UNPAID|Belum Dibayar/')
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(DB::table('trx_rme_invoice_corrections')->count())->toBe(0);
});

it('accepts no payment and no dispute on a NO_CHARGE invoice', function () {
    $invoice = rzvConsultationInvoice();
    rzvClose($invoice);
    $before = rzvMoney();

    test()->actingAs(test()->cashier);
    expect(fn () => app(RmePaymentService::class)->pay($invoice->fresh(), test()->cashier, rmeFreshPaymentForm($invoice->fresh(), [
        'amount' => 1000, 'paid_at' => now()->format('Y-m-d H:i:s'),
    ])))->toThrow(ValidationException::class);

    $disputeRefusal = null;
    try {
        app(RmeBillingDisputeService::class)->report($invoice->fresh(), test()->cashier, [
            'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
            'description' => 'Laporan atas tagihan yang sudah ditutup tanpa biaya.',
        ]);
    } catch (ValidationException $e) {
        $disputeRefusal = (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }
    expect($disputeRefusal)->toContain('BILLING_DISPUTE_INVOICE_STATUS_UNSUPPORTED');

    expect(rzvMoney())->toBe($before)
        ->and(DB::table('trx_rme_billing_disputes')->count())->toBe(0);
});

it('never generates lab case candidates: NO_CHARGE is not PAID', function () {
    $invoice = rzvConsultationInvoice();
    rzvClose($invoice);

    expect(LabCaseCandidate::query()->count())->toBe(0);
});

// ─── HTTP (thin controller, FormRequest, policy) ───────────────────────────────

it('answers 404 on the closure route while the flag is OFF', function () {
    $invoice = rzvConsultationInvoice();
    rzvFlag(false);

    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.cashier.zero-value-closure.store', [$invoice->clinicVisit, $invoice]), ['acknowledged' => '1'])
        ->assertNotFound();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('closes through the HTTP route with an explicit acknowledgment', function () {
    $invoice = rzvConsultationInvoice();

    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.cashier.zero-value-closure.store', [$invoice->clinicVisit, $invoice]), ['acknowledged' => '1'])
        ->assertRedirect(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
        ->assertSessionHas('status');

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE);
});

it('refuses the HTTP closure without the acknowledgment and changes nothing', function () {
    $invoice = rzvConsultationInvoice();

    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
        ->post(route('rme.cashier.zero-value-closure.store', [$invoice->clinicVisit, $invoice]), [])
        ->assertSessionHasErrors('acknowledged');

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('forbids the HTTP closure to an account without the billing permission', function () {
    $invoice = rzvConsultationInvoice();

    $this->actingAs(userWith(['view_clinic_visits']))->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.cashier.zero-value-closure.store', [$invoice->clinicVisit, $invoice]), ['acknowledged' => '1'])
        ->assertForbidden();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('answers 404 when the invoice does not belong to the visit in the URL', function () {
    $invoice = rzvConsultationInvoice();
    $otherVisit = rzvNewVisit();

    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('rme.cashier.zero-value-closure.store', [$otherVisit, $invoice]), ['acknowledged' => '1'])
        ->assertNotFound();

    expect($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('shows the "Selesai Tanpa Biaya" action instead of a payment on an eligible invoice, and "Tanpa Biaya" after closure', function () {
    $invoice = rzvConsultationInvoice();
    $page = fn () => $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]));

    $page()->assertOk()
        ->assertSee('data-zero-value-closure-form', false)
        ->assertDontSee('Bayar Sekarang');

    rzvClose($invoice);

    $page()->assertOk()
        ->assertSee('Tanpa Biaya')
        ->assertDontSee('data-zero-value-closure-form', false)
        ->assertDontSee('Bayar Sekarang')
        ->assertDontSee('Lihat Kwitansi');
});

it('keeps the page exactly as before while the flag is OFF', function () {
    $invoice = rzvConsultationInvoice();
    rzvFlag(false);

    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
        ->assertOk()
        ->assertDontSee('data-zero-value-closure-form', false)
        ->assertSee('Bayar Sekarang');
});

it('shows the refusal reason instead of the action on an ineligible Rp0 invoice', function () {
    $invoice = rzvInvoice(rzvNewVisit(), [rzvLine(test()->consultation, 150000, 150000)]);

    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
        ->assertOk()
        ->assertDontSee('data-zero-value-closure-form', false)
        ->assertSee('data-zero-value-ineligible', false)
        // Security review MEDIUM-2: an INELIGIBLE Rp0 invoice keeps its payment
        // entry, otherwise a Kontrol with carry-over could never be completed.
        ->assertSee('Bayar Sekarang');
});

// ─── Security review follow-ups (2026-11-01) ────────────────────────────────

it('refuses a Kontrol whose parent visit is cancelled, never examined, deleted, or another patient\'s', function () {
    $cancelledParent = ricVisit();
    $cancelled = rzvInvoice(rzvControlVisit($cancelledParent), [rzvLine(test()->consultation)]);
    DB::table('trx_clinic_visits')->where('id', $cancelledParent->id)->update(['status' => ClinicVisit::STATUS_CANCELLED]);

    $unexaminedParent = ricVisit();
    $unexamined = rzvInvoice(rzvControlVisit($unexaminedParent), [rzvLine(test()->consultation)]);
    DB::table('trx_clinic_visits')->where('id', $unexaminedParent->id)->update(['status' => ClinicVisit::STATUS_REGISTERED]);

    $deletedParent = ricVisit();
    $deleted = rzvInvoice(rzvControlVisit($deletedParent), [rzvLine(test()->consultation)]);
    $deletedParent->delete();

    $foreignParent = ricVisit();
    $foreign = rzvInvoice(rzvControlVisit($foreignParent), [rzvLine(test()->consultation)]);
    DB::table('trx_clinic_visits')->where('id', $foreignParent->id)->update(['patient_id' => Patient::factory()->create()->id]);

    foreach ([$cancelled, $unexamined, $deleted, $foreign] as $invoice) {
        expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::CONTROL_PARENT_INVALID)
            ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
    }

    expect(rzvClosureAudits())->toBe(0);
});

it('accepts a Kontrol whose parent visit was examined and already completed', function () {
    $parent = ricVisit();
    $invoice = rzvInvoice(rzvControlVisit($parent), [rzvLine(test()->consultation)]);
    DB::table('trx_clinic_visits')->where('id', $parent->id)->update(['status' => ClinicVisit::STATUS_COMPLETED]);

    expect(rzvClose($invoice)->zero_value_category)->toBe(RmeZeroValueCategory::CONTROL);
});

it('refuses a free line that requires lab work, even on a Kontrol', function () {
    $lab = Treatment::factory()->create([
        'code' => 'TRT-RZV-LAB', 'name' => 'Remake Crown', 'is_active' => true, 'requires_lab' => true,
    ]);
    $lines = [rzvLine(test()->consultation), rzvLine($lab, 0, 0, 'Remake crown garansi')];

    // Refused at issuance on a Kontrol (S6-Q3b) ...
    expect(fn () => rzvInvoice(rzvControlVisit(), $lines))->toThrow(ValidationException::class);

    // ... and at the closure when issued with the flag OFF (S6-Q2).
    $invoice = rzvIssueWhileOff(rzvControlVisit(), $lines);

    expect(rzvRefusal($invoice))->toContain(RmeZeroValueEligibility::LAB_LINE_EXCLUDED)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('snapshots the eligibility inputs into the audit row, without any free-text description', function () {
    $parent = ricVisit();
    $invoice = rzvInvoice(rzvControlVisit($parent), [rzvLine(test()->consultation, 0, 0, 'Catatan pasien Budi Santoso')]);
    rzvClose($invoice);

    $new = (array) AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)->sole()->new_values;
    $line = (array) $new['lines'][0];

    expect($new['visit_type'])->toBe(ClinicVisit::VISIT_TYPE_CONTROL)
        ->and((int) $new['follow_up_of_visit_id'])->toBe((int) $parent->id)
        ->and($new['parent_visit_status'])->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and((int) $new['item_count'])->toBe(1)
        ->and($line['treatment_code'])->toBe('TRT-CONS')
        ->and($line['category_code'])->toBe('consultation')
        ->and($line['requires_lab'])->toBeFalse()
        ->and((float) $line['unit_price'])->toEqual(0.0)
        ->and((float) $line['discount'])->toEqual(0.0)
        ->and((string) json_encode($new))->not->toContain('Budi Santoso');
});

it('answers every refusal under the one key the cashier page renders, including the inherited guards', function () {
    $invoice = rzvConsultationInvoice();
    $stale = test()->cashier;
    DB::table('users')->where('id', $stale->id)->update(['is_active' => false]);

    try {
        rzvClose($invoice, $stale);
        $keys = [];
    } catch (ValidationException $e) {
        $keys = array_keys($e->errors());
    }

    expect($keys)->toBe(['zero_value']);
});

it('shows a refused closure on the page instead of failing silently', function () {
    $invoice = rzvConsultationInvoice();
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['correction_version' => 1]);

    $this->actingAs(test()->cashier)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->from(route('rme.cashier.show', [$invoice->clinicVisit, $invoice]))
        ->followingRedirects()
        ->post(route('rme.cashier.zero-value-closure.store', [$invoice->clinicVisit, $invoice]), ['acknowledged' => '1'])
        ->assertOk()
        ->assertSee('data-zero-value-error', false)
        ->assertSee(RmeZeroValueEligibility::INVOICE_CORRECTED);
});
