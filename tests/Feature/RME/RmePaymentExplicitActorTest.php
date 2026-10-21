<?php

/*
| HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1 (rule 197) —
| the payment branch-scope check judges the EXPLICIT cashier the caller passes,
| never the ambient session.
|
| Root cause it pins: RmePaymentService::assertInvoicePayable() asked
| RmeWorkingBranchScope::allows(Auth::user(), …). That is wrong twice:
|   1. it judged whoever happened to be logged in, not the cashier the payment
|      is recorded under (`cashier_id`) — the two can differ for any non-HTTP
|      caller, so the wrong person's branch was checked;
|   2. with NO session, RmeWorkingBranchScope::branchIdsFor(null) answers EVERY
|      active RME branch — the branch check failed OPEN.
| The service now authorizes the explicit, persisted, active cashier on every
| payment path (single, control allocation, visit allocation).
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeInvoiceCorrection/helpers.php';

beforeEach(function () {
    ricSetup();

    $t = test();
    $t->elsewhere = Branch::factory()->create(['code' => 'IC08', 'name' => 'Cabang Lain', 'is_rme_enabled' => true, 'is_active' => true]);

    // A context-bound cashier working in the invoice branch, and one working
    // in another branch — the case the branch scope exists for.
    $t->branchCashier = User::factory()->create();
    rmeMakeKasirActive($t->branchCashier, $t->branch);
    $t->foreignCashier = User::factory()->create();
    rmeMakeKasirActive($t->foreignCashier, $t->elsewhere);

    // An ambient Super Admin session, to prove the check never borrows it.
    $t->ambientSuperAdmin = userInRole('Super Admin');
});

function rpeForm(RmeInvoice $invoice, float $amount, array $extra = []): array
{
    return rmeFreshPaymentForm($invoice->fresh(), array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ], $extra));
}

function rpePay(RmeInvoice $invoice, User $cashier, float $amount = 100000): RmePayment
{
    return app(RmePaymentService::class)->pay($invoice->fresh(), $cashier, rpeForm($invoice, $amount));
}

function rpeRefusedOnBranch(callable $work): void
{
    try {
        $work();
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('rme_invoice_id');

        return;
    }

    test()->fail('The payment was accepted; the explicit cashier is outside the invoice branch.');
}

function rpeControlChain(): array
{
    $parentVisit = ricVisit();
    $parent = ricInvoice($parentVisit);

    $controlVisit = ClinicVisit::factory()->cashierPending()->create([
        'branch_id' => test()->branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $parentVisit->id,
        'visit_number' => 'VIS-RPE-CTL-'.uniqid(),
        'visit_date' => clinicalToday()->toDateString(),
    ]);
    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $controlVisit->id, 'branch_id' => test()->branch->id,
        'patient_id' => test()->patient->id, 'doctor_id' => test()->doctor->id,
    ]);
    rmeSignedConsentFor($controlVisit);

    return [$parent, ricInvoice($controlVisit)];
}

// ─── The fail-open: no ambient session ──────────────────────────────────────

it('refuses a wrong-branch cashier when no session exists (the old check failed open)', function () {
    $invoice = ricInvoice();
    Auth::logout();
    expect(Auth::user())->toBeNull();

    rpeRefusedOnBranch(fn () => rpePay($invoice, test()->foreignCashier));

    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

it('lets an authorized cashier pay when no session exists', function () {
    $invoice = ricInvoice();
    Auth::logout();

    $payment = rpePay($invoice, test()->branchCashier);

    expect((int) $payment->cashier_id)->toBe((int) test()->branchCashier->id);
});

// ─── Actor substitution, both directions ────────────────────────────────────

it('never lets an ambient Super Admin session authorize a wrong-branch cashier', function () {
    $invoice = ricInvoice();
    test()->actingAs(test()->ambientSuperAdmin);

    rpeRefusedOnBranch(fn () => rpePay($invoice, test()->foreignCashier));

    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('judges the explicit cashier even when the ambient session belongs to another branch', function () {
    $invoice = ricInvoice();
    test()->actingAs(test()->foreignCashier);

    $payment = rpePay($invoice, test()->branchCashier);

    expect((int) $payment->cashier_id)->toBe((int) test()->branchCashier->id);
});

// ─── Unusable actors ────────────────────────────────────────────────────────

it('refuses an inactive cashier', function () {
    $invoice = ricInvoice();
    test()->branchCashier->forceFill(['is_active' => false])->save();

    rpeRefusedOnBranch(fn () => rpePay($invoice, test()->branchCashier->fresh()));
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('refuses a deleted cashier', function () {
    $invoice = ricInvoice();
    test()->branchCashier->delete();

    rpeRefusedOnBranch(fn () => rpePay($invoice, test()->branchCashier));
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('refuses an unsaved cashier object carrying an authorized id', function () {
    $invoice = ricInvoice();
    $forged = new User(['name' => 'Bukan akun', 'email' => 'forged-cashier@example.test']);
    $forged->id = test()->ambientSuperAdmin->id;
    $forged->is_active = true;

    rpeRefusedOnBranch(fn () => rpePay($invoice, $forged));
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('refuses an active account without the billing permission (security review L3)', function () {
    $invoice = ricInvoice();
    $noBilling = userWith(['view_clinic_visits']);
    Auth::logout();

    rpeRefusedOnBranch(fn () => rpePay($invoice, $noBilling));
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('declares a non-nullable cashier on every payment entry point', function (string $method) {
    $parameter = collect((new ReflectionMethod(RmePaymentService::class, $method))->getParameters())
        ->first(fn (ReflectionParameter $p) => $p->getName() === 'cashier');

    expect($parameter)->not->toBeNull()
        ->and($parameter->getType()?->allowsNull())->toBeFalse()
        ->and((string) $parameter->getType())->toBe(User::class);
})->with(['pay', 'allocateControlPayment', 'allocateVisitPayment']);

// ─── Every payment path propagates the explicit actor ───────────────────────

it('judges the explicit cashier on the control allocation path', function () {
    [$parent, $control] = rpeControlChain();
    Auth::logout();

    rpeRefusedOnBranch(fn () => app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->foreignCashier, rpeForm($control, 100000)));
    expect(RmePayment::query()->whereIn('rme_invoice_id', [$parent->id, $control->id])->count())->toBe(0);

    $result = app(RmePaymentService::class)->allocateControlPayment($control->fresh(), test()->branchCashier, rpeForm($control, 100000));
    expect($result->parentPayments->pluck('cashier_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([(int) test()->branchCashier->id]);
});

it('judges the explicit cashier on the visit allocation path', function () {
    $prior = ricInvoice();
    $current = ricInvoice();
    Auth::logout();

    rpeRefusedOnBranch(fn () => app(RmePaymentService::class)->allocateVisitPayment(
        $current->fresh(), test()->foreignCashier, rpeForm($current, 600000, ['selected_receivable_ids' => [$prior->id]]), [$prior->id],
    ));
    expect(RmePayment::query()->whereIn('rme_invoice_id', [$prior->id, $current->id])->count())->toBe(0);

    $result = app(RmePaymentService::class)->allocateVisitPayment(
        $current->fresh(), test()->branchCashier, rpeForm($current, 600000, ['selected_receivable_ids' => [$prior->id]]), [$prior->id],
    );
    expect($result->parentPayments->pluck('cashier_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([(int) test()->branchCashier->id]);
});

// ─── The financial invariants the change must not disturb ───────────────────

it('still blocks payment on an active correction (D10) for an authorized cashier', function () {
    $invoice = ricInvoice();
    ricSubmit($invoice);
    Auth::logout();

    expect(fn () => rpePay($invoice, test()->branchCashier))->toThrow(ValidationException::class);
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('still refuses a stale invoice version for an authorized cashier', function () {
    $invoice = ricInvoice();
    $form = rpeForm($invoice, 100000, ['invoice_versions' => [$invoice->id => 7]]);
    Auth::logout();

    expect(fn () => app(RmePaymentService::class)->pay($invoice->fresh(), test()->branchCashier, $form))
        ->toThrow(ValidationException::class);
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('keeps the governance cashier path unchanged for a Super Admin', function () {
    $invoice = ricInvoice();
    Auth::logout();

    $payment = rpePay($invoice, test()->ambientSuperAdmin);

    expect((int) $payment->cashier_id)->toBe((int) test()->ambientSuperAdmin->id);
});

it('keeps the payment HTTP route behind authentication', function () {
    $visit = ricVisit();
    $invoice = ricInvoice($visit);
    Auth::logout();

    test()->post(route('rme.cashier.payment.store', [$visit, $invoice]), rpeForm($invoice, 100000))
        ->assertRedirect(route('login'));
    expect(RmePayment::query()->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('reads no ambient session anywhere in the payment service code', function () {
    // Comments are stripped with the tokenizer: the explanation of WHY the
    // ambient session is wrong names it, and that must not satisfy this check.
    $code = collect(token_get_all(file_get_contents(app_path('Modules/RmeInvoice/Services/RmePaymentService.php'))))
        ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ->map(fn ($token) => is_array($token) ? $token[1] : $token)
        ->implode('');

    expect($code)->not->toContain('Auth::')
        ->and($code)->not->toContain('auth()')
        ->and($code)->not->toContain('request()');
});
