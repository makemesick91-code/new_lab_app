<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 — owner
| decision OPS-OQ-1b (rule 204): a legitimate Kontrol invoice may be paid on
| its own while its ORIGIN visit invoice is under an active billing dispute.
|--------------------------------------------------------------------------
|
| The money goes to the Kontrol invoice ONLY. The disputed origin invoice is
| never allocated, settled, reduced, re-statused or released; its dispute and
| payment hold stand. The exception needs explicit operator intent
| (payment_scope=control_only + confirmation), applies only when EVERY
| outstanding carry-over invoice of the chain is under an active dispute (a
| collectable, undisputed debt is never skipped — fail closed), and keeps every
| guard of the Kontrol invoice itself: its own dispute, D10, version binding,
| branch scope and the cashier's account.
|
| The ordinary control payment (origin first, FIFO) is unchanged and is still
| refused whole while the origin is disputed.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    rbdSetup();
});

/**
 * @param  array<string, mixed>  $extra
 */
function rcoPayOnly(RmeInvoice $control, float $amount, array $extra = [], ?User $cashier = null): RmePayment
{
    $cashier ??= test()->cashier;
    test()->actingAs($cashier);

    return app(RmePaymentService::class)->payControlInvoiceOnly($control->fresh(), $cashier, rmeFreshPaymentForm($control->fresh(), array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'confirm_control_only' => true,
    ], $extra)));
}

/** The origin invoice's financial state the B-only payment must never change. */
function rcoOriginState(RmeInvoice $origin): array
{
    $origin = $origin->fresh();

    return [
        'status' => $origin->status,
        'grand_total' => (string) $origin->grand_total,
        'paid' => round((float) $origin->paidAmount(), 2),
        'remaining' => round((float) $origin->remainingAmount(), 2),
        'correction_version' => (int) $origin->correction_version,
        'payments' => DB::table('trx_rme_payments')->where('rme_invoice_id', $origin->id)->orderBy('id')
            ->get(['id', 'amount', 'paid_at', 'payment_number'])->map(fn ($r) => (array) $r)->all(),
        'items' => DB::table('trx_rme_invoice_items')->where('rme_invoice_id', $origin->id)->orderBy('id')
            ->get(['id', 'unit_price', 'discount', 'subtotal', 'qty'])->map(fn ($r) => (array) $r)->all(),
        'document_versions' => DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $origin->id)->count(),
    ];
}

function rcoExpectRefusal(callable $work, string $field, string $needle): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
        expect(implode(' ', $e->errors()[$field]))->toContain($needle);

        return;
    }

    test()->fail("Expected a refusal on {$field} containing {$needle}; the payment was accepted.");
}

/** A control visit of $parentVisit, with a finalized record and consent. */
function rcoControlVisit(ClinicVisit $parentVisit, ?Branch $branch = null): ClinicVisit
{
    $branch ??= test()->branch;
    ricTariffs($branch);
    $visit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => $branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parentVisit->id,
        'visit_number' => 'VIS-RCO-CTL-'.uniqid(),
        'visit_date' => clinicalToday()->toDateString(),
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id, 'branch_id' => $branch->id,
        'patient_id' => test()->patient->id, 'doctor_id' => test()->doctor->id,
    ]);
    rmeSignedConsentFor($visit);

    return $visit;
}

function rcoPaymentForm(RmeInvoice $control, array $data): array
{
    return rmeFreshPaymentForm($control->fresh(), array_merge([
        'paid_at' => now()->format('Y-m-d\TH:i'),
        'payment_scope' => RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY,
        'confirm_control_only' => '1',
    ], $data));
}

// ─── 01–09  the approved case, and what it must never touch ─────────────────

it('01–04 pays a Kontrol invoice alone while its origin is disputed, allocating nothing to the origin', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    $originBefore = rcoOriginState($origin);

    $payment = rcoPayOnly($control, 500000);

    expect((int) $payment->rme_invoice_id)->toBe((int) $control->id)
        ->and((float) $payment->amount)->toBe(500000.0)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(1)
        // No zero-value or any other row on the origin.
        ->and(RmePayment::where('rme_invoice_id', $origin->id)->count())->toBe(0)
        ->and(rcoOriginState($origin))->toBe($originBefore);
});

it('05–06 leaves a partially paid origin exactly as it was: prior payments, status and remaining balance', function () {
    [$origin, $control] = rbdControlChain();
    rbdPay($origin, 100000);
    expect($origin->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
    rbdReport($origin);
    $originBefore = rcoOriginState($origin);

    rcoPayOnly($control, 200000);

    expect(rcoOriginState($origin))->toBe($originBefore)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

it('07–08 keeps the origin dispute active and the origin payment hold in force afterwards', function () {
    [$origin, $control] = rbdControlChain();
    $dispute = rbdReport($origin);

    rcoPayOnly($control, 500000);

    expect($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
    rbdExpectDisputeBlocked(fn () => rbdPay($origin, 500000));
});

it('09 completes the Kontrol visit when its own invoice is settled and leaves the origin visit alone', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    $originVisitStatus = $origin->clinicVisit->fresh()->status;

    rcoPayOnly($control, 500000);

    expect($control->clinicVisit->fresh()->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and($origin->clinicVisit->fresh()->status)->toBe($originVisitStatus);
});

it('09b prints a receipt that names the Kontrol payment only and never the origin invoice', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    $payment = rcoPayOnly($control, 500000);

    rbdActingAs(test()->cashier);
    test()->get(route('rme.cashier.receipt.show', [$control->clinic_visit_id, $control->id]))
        ->assertOk()
        ->assertSee((string) $payment->payment_number)
        ->assertDontSee((string) $origin->invoice_number);
});

// ─── 10–13  the ordinary path and the Kontrol invoice's own guards ──────────

it('10–11 still refuses the ordinary combined payment whole while the origin is disputed (no partial commit)', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);

    rbdActingAs(test()->cashier);
    test()->post(route('rme.cashier.payment.store', [$control->clinic_visit_id, $control->id]), rmeFreshPaymentForm($control->fresh(), [
        'amount' => 600000, 'paid_at' => now()->format('Y-m-d\TH:i'),
    ]))->assertSessionHasErrors(RmePaymentService::PAYMENT_BLOCKED_FIELD);

    expect(RmePayment::whereIn('rme_invoice_id', [$origin->id, $control->id])->count())->toBe(0);
});

it('12 refuses a Kontrol-only payment when the Kontrol invoice is itself disputed', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    rbdReport($control);

    rbdExpectDisputeBlocked(fn () => rcoPayOnly($control, 500000));
    expect(RmePayment::whereIn('rme_invoice_id', [$origin->id, $control->id])->count())->toBe(0);
});

it('13 refuses a Kontrol-only payment while the Kontrol invoice has an active correction (D10)', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    ricSubmit($control);

    rcoExpectRefusal(fn () => rcoPayOnly($control, 500000), RmePaymentService::PAYMENT_BLOCKED_FIELD, 'koreksi');
    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0);
});

// ─── 14–19  the exception cannot be triggered, widened or forged ────────────

it('14 refuses the exception when the origin is NOT disputed, and the ordinary path still settles the origin first', function () {
    [$origin, $control] = rbdControlChain();

    rcoExpectRefusal(fn () => rcoPayOnly($control, 500000), 'payment_scope', 'CONTROL_ONLY_PAYMENT_ORIGIN_NOT_DISPUTED');
    expect(RmePayment::whereIn('rme_invoice_id', [$origin->id, $control->id])->count())->toBe(0);

    test()->actingAs(test()->cashier);
    app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->cashier, rmeFreshPaymentForm($control->fresh(), [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
    ]));
    expect($origin->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('15 refuses the exception when only an UNRELATED invoice of the patient is disputed', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport(ricInvoice()); // same patient, not in the Kontrol chain

    rcoExpectRefusal(fn () => rcoPayOnly($control, 500000), 'payment_scope', 'CONTROL_ONLY_PAYMENT_ORIGIN_NOT_DISPUTED');
    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0);
});

it('15b fails closed on a mixed chain: an undisputed, collectable carry-over invoice is never skipped', function () {
    // V1 (undisputed, outstanding) → V2 (disputed) → V3 (Kontrol).
    $v1 = ricVisit();
    $i1 = ricInvoice($v1);
    $v2 = rcoControlVisit($v1);
    $i2 = ricInvoice($v2);
    $v3 = rcoControlVisit($v2);
    $i3 = ricInvoice($v3);
    rbdReport($i2);

    rcoExpectRefusal(fn () => rcoPayOnly($i3, 500000), 'payment_scope', 'CONTROL_ONLY_PAYMENT_ORIGIN_NOT_DISPUTED');
    expect(RmePayment::whereIn('rme_invoice_id', [$i1->id, $i2->id, $i3->id])->count())->toBe(0);
});

it('15c allows the exception on a chain whose every outstanding origin is disputed', function () {
    $v1 = ricVisit();
    $i1 = ricInvoice($v1);
    $v2 = rcoControlVisit($v1);
    $i2 = ricInvoice($v2);
    $v3 = rcoControlVisit($v2);
    $i3 = ricInvoice($v3);
    rbdReport($i1);
    rbdReport($i2);

    rcoPayOnly($i3, 500000);

    expect(RmePayment::whereIn('rme_invoice_id', [$i1->id, $i2->id])->count())->toBe(0)
        ->and($i3->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('16 refuses the exception for an invoice that is not a Kontrol with carry-over (forged relationship)', function () {
    $ordinary = ricInvoice();
    rbdReport(ricInvoice());

    rcoExpectRefusal(fn () => rcoPayOnly($ordinary, 500000), 'payment_scope', 'CONTROL_ONLY_PAYMENT_NOT_APPLICABLE');
    expect(RmePayment::where('rme_invoice_id', $ordinary->id)->count())->toBe(0);
});

it('17–18 ignores a forged origin id and a forged allocation in the request: the money only ever reaches the Kontrol invoice', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    $originBefore = rcoOriginState($origin);

    rbdActingAs(test()->cashier);
    test()->post(route('rme.cashier.payment.store', [$control->clinic_visit_id, $control->id]), rcoPaymentForm($control, [
        'amount' => 500000,
        'origin_invoice_id' => $origin->id,
        'selected_receivable_ids' => [$origin->id],
        'allocations' => [$origin->id => 250000, $control->id => 250000],
    ]))->assertRedirect();

    expect(RmePayment::where('rme_invoice_id', $control->id)->sum('amount'))->toEqual(500000)
        ->and(rcoOriginState($origin))->toBe($originBefore);
});

it('19 refuses a stale Kontrol invoice version', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);

    rcoExpectRefusal(fn () => rcoPayOnly($control, 500000, ['invoice_versions' => [$control->id => 7]]), 'invoice_versions', 'INVOICE_VERSION_CHANGED');
    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0);
});

// ─── 20–25  the cashier and the branch ─────────────────────────────────────

it('20 refuses an inactive cashier even with a stale in-memory account', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    DB::table('users')->where('id', test()->cashier->id)->update(['is_active' => false]);

    expect(fn () => rcoPayOnly($control, 500000))->toThrow(Exception::class);
    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0);
});

it('21 refuses a cashier working at another branch', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    $otherBranch = Branch::factory()->create(['code' => 'IC02', 'is_rme_enabled' => true, 'is_active' => true]);
    $foreignCashier = userInRole('Front Office');
    rmeMakeFrontOfficeActive($foreignCashier, $otherBranch);

    expect(fn () => rcoPayOnly($control, 500000, [], $foreignCashier->fresh()))->toThrow(Exception::class);
    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0);
});

it('23–25 lets a cross-branch Kontrol be collected at its own branch without ever touching or showing the other branch\'s disputed origin (OD-1 / OD-1a)', function () {
    $branchB = Branch::factory()->create(['code' => 'IC02', 'is_rme_enabled' => true, 'is_active' => true]);
    $originVisit = ricVisit();
    $origin = ricInvoice($originVisit);
    rbdReport($origin);
    $originBefore = rcoOriginState($origin);

    $control = ricInvoice(rcoControlVisit($originVisit, $branchB));
    $branchBCashier = userInRole('Front Office');
    rmeMakeFrontOfficeActive($branchBCashier, $branchB);
    $branchBCashier = $branchBCashier->fresh();

    // Branch B sees no carry-over at all, so the ordinary path collects B alone.
    rbdActingAs($branchBCashier);
    test()->get(route('rme.cashier.payment.create', [$control->clinic_visit_id, $control->id]))
        ->assertOk()->assertDontSee((string) $origin->invoice_number);

    rbdPay($control, 500000, [], $branchBCashier);
    expect($control->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(rcoOriginState($origin))->toBe($originBefore);

    // Branch B can neither select nor settle branch A's disputed receivable.
    $another = ricInvoice(rcoControlVisit($originVisit, $branchB));
    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment($another->fresh(), $branchBCashier, rmeFreshPaymentForm($another->fresh(), [
        'amount' => 600000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'selected_receivable_ids' => [$origin->id],
    ]), [$origin->id]))->toThrow(ValidationException::class);
    expect(rcoOriginState($origin))->toBe($originBefore);
});

// ─── 27–37  batches, retries, ledger, audit ─────────────────────────────────

it('27 keeps refusing a multi-invoice visit batch that includes the disputed origin', function () {
    [$origin] = rbdControlChain();
    rbdReport($origin);
    $current = ricInvoice();

    rbdExpectDisputeBlocked(fn () => app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), test()->cashier, rmeFreshPaymentForm($current->fresh(), [
        'amount' => 1000000, 'paid_at' => now()->format('Y-m-d H:i:s'), 'selected_receivable_ids' => [$origin->id],
    ]), [$origin->id]));
    expect(RmePayment::whereIn('rme_invoice_id', [$origin->id, $current->id])->count())->toBe(0);
});

it('28–29 never overpays on a retry: a repeated full payment is refused once the Kontrol invoice is settled', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);

    rcoPayOnly($control, 500000);
    expect(fn () => rcoPayOnly($control, 500000))->toThrow(ValidationException::class);

    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(1)
        ->and((float) RmePayment::where('rme_invoice_id', $control->id)->sum('amount'))->toBe(500000.0);
});

it('29b refuses an amount above the Kontrol invoice\'s own remaining balance (the origin balance is not payable here)', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);

    rcoExpectRefusal(fn () => rcoPayOnly($control, 600000), 'amount', 'melebihi sisa tagihan');
    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0);
});

it('30–31, 35–36 changes nothing else in the ledger: historical payments, prices, items and versions', function () {
    [$origin, $control] = rbdControlChain();
    $other = ricInvoice();
    rbdPay($other, 100000);
    rbdReport($origin);
    $before = rbdLedger();

    rcoPayOnly($control, 500000);
    $after = rbdLedger();

    // Exactly one new payment, on the Kontrol invoice; nothing else moved except its status.
    $newPayments = array_values(array_udiff($after['payments'], $before['payments'], fn ($a, $b) => $a['id'] <=> $b['id']));
    expect($newPayments)->toHaveCount(1)
        ->and((int) $newPayments[0]['rme_invoice_id'])->toBe((int) $control->id)
        ->and($after['items'])->toBe($before['items'])
        ->and($after['corrections'])->toBe($before['corrections'])
        ->and($after['document_versions'])->toBe($before['document_versions'])
        ->and(collect($after['invoices'])->reject(fn ($i) => $i['id'] === $control->id)->values()->all())
        ->toBe(collect($before['invoices'])->reject(fn ($i) => $i['id'] === $control->id)->values()->all());
});

it('37 audits the exception with ids only: Kontrol payment recorded, origin held with zero allocation', function () {
    [$origin, $control] = rbdControlChain();
    $dispute = rbdReport($origin);

    $payment = rcoPayOnly($control, 500000);

    $row = rbdAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_CONTROL_ONLY_ORIGIN_DISPUTED)->sole();
    expect((int) $row->entity_id)->toBe((int) $payment->id)
        ->and($row->new_values['control_invoice_id'])->toBe((int) $control->id)
        ->and($row->new_values['held_origin_invoice_ids'])->toBe([(int) $origin->id])
        ->and($row->new_values['held_dispute_ids'])->toBe([(int) $dispute->id])
        ->and((float) $row->new_values['allocated_to_origin'])->toBe(0.0)
        ->and(json_encode($row->new_values))->not->toContain((string) test()->patient->name);

    $recorded = rbdAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_RECORDED);
    expect($recorded)->toHaveCount(1)
        ->and((int) $recorded->sole()->new_values['rme_invoice_id'])->toBe((int) $control->id);
});

// ─── HTTP: explicit intent and the operator's screen ───────────────────────

it('requires an explicit confirmation before the Kontrol-only payment is accepted over HTTP', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);

    rbdActingAs(test()->cashier);
    test()->post(route('rme.cashier.payment.store', [$control->clinic_visit_id, $control->id]), rcoPaymentForm($control, [
        'amount' => 500000, 'confirm_control_only' => null,
    ]))->assertSessionHasErrors('confirm_control_only');

    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0);

    test()->post(route('rme.cashier.payment.store', [$control->clinic_visit_id, $control->id]), rcoPaymentForm($control, [
        'amount' => 500000,
    ]))->assertRedirect(route('rme.cashier.receipt.show', [$control->clinic_visit_id, $control->id]));

    expect($control->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(RmePayment::where('rme_invoice_id', $origin->id)->count())->toBe(0);
});

it('shows the operator the held origin, Rp0 allocated to it, and a separate Kontrol-only action', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);

    rbdActingAs(test()->cashier);
    test()->get(route('rme.cashier.payment.create', [$control->clinic_visit_id, $control->id]))
        ->assertOk()
        ->assertSee('Tagihan kunjungan asal sedang dalam peninjauan dan tidak dapat ditagihkan.')
        ->assertSee('Bayar Tagihan Kontrol Saja')
        ->assertSee('data-control-only-payment', false)
        ->assertSee('Rp0', false);
});

it('offers no Kontrol-only action when the origin is not disputed', function () {
    [, $control] = rbdControlChain();

    rbdActingAs(test()->cashier);
    test()->get(route('rme.cashier.payment.create', [$control->clinic_visit_id, $control->id]))
        ->assertOk()
        ->assertDontSee('data-control-only-payment', false);
});

it('requires the explicit confirmation in the service itself, not only in the HTTP form', function () {
    [$origin, $control] = rbdControlChain();
    rbdReport($origin);
    rbdActingAs(test()->cashier);

    try {
        app(RmePaymentService::class)->payControlInvoiceOnly($control->fresh(), test()->cashier, rmeFreshPaymentForm($control->fresh(), [
            'amount' => 500000,
            'paid_at' => now()->format('Y-m-d H:i:s'),
        ]));
        test()->fail('A Kontrol-only payment without the confirmation was accepted.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('confirm_control_only');
    }

    expect(RmePayment::where('rme_invoice_id', $control->id)->count())->toBe(0)
        ->and(RmePayment::where('rme_invoice_id', $origin->id)->count())->toBe(0);
});
