<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — one logical payment
| submission produces at most one committed payment effect, and every payment
| is validated against the balances the cashier saw, decided under the lock.
|--------------------------------------------------------------------------
|
| F1 — a payment form submitted twice (double click, browser retry after a lost
|      response, resubmission after "back") recorded two partial payments.
| F3 — the Kontrol / visit paths validated the amount against a payable summary
|      read BEFORE the row locks, then skipped an invoice that had changed and
|      min()-capped the rest: the cashier's amount was silently truncated or
|      silently redistributed onto another invoice.
|
| These cases run on SQLite and PostgreSQL. The genuinely concurrent cases (two
| transactions overlapping on real row locks) live in
| RmePaymentSubmissionConcurrencyTest, which runs on PostgreSQL only.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Interfaces\RmePaymentRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmePaymentSubmissionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Models\RmePaymentSubmission;
use App\Modules\RmeInvoice\Repositories\RmePaymentRepository;
use App\Modules\RmeInvoice\Repositories\RmePaymentSubmissionRepository;
use App\Modules\RmeInvoice\Services\RmeControlReceivableService;
use App\Modules\RmeInvoice\Services\RmeInvoiceService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Services\RmePaymentSubmissionService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmePaymentDeclaredView;
use App\Modules\RmeInvoice\Support\RmePaymentIntent;
use App\Modules\RmeInvoice\Support\RmePaymentSubmissionReplayed;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['code' => 'PSI1', 'is_rme_enabled' => true, 'is_active' => true]);
    $this->doctor = Doctor::factory()->create(['branch_id' => $this->branch->id]);
    $this->treatment = Treatment::factory()->create(['is_active' => true, 'requires_lab' => false]);
    $this->cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $this->patient = Patient::factory()->create([
        'medical_record_number' => 'DG-PSI1-2026-0001',
        'branch_id' => $this->branch->id,
    ]);
});

// ─── Fixtures ─────────────────────────────────────────────────────────────────

function psiVisit(array $attributes = []): ClinicVisit
{
    $visit = ClinicVisit::factory()->cashierPending()->create(array_merge([
        'branch_id' => test()->branch->id,
        'clinic_id' => test()->clinic->id,
        'patient_id' => test()->patient->id,
        'doctor_id' => test()->doctor->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-PSI-'.uniqid(),
    ], $attributes));

    MedicalRecord::factory()->final()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
        'doctor_id' => $visit->doctor_id,
    ]);
    rmeSignedConsentFor($visit);

    return $visit;
}

function psiInvoice(ClinicVisit $visit, float $amount): RmeInvoice
{
    return app(RmeInvoiceService::class)->create($visit, test()->cashier, [
        'items' => [[
            'description' => 'Tindakan RME',
            'qty' => 1,
            'unit_price' => $amount,
            'discount' => 0,
            'treatment_id' => test()->treatment->id,
        ]],
    ])->refresh();
}

/** An ordinary visit with one UNPAID invoice of $amount. */
function psiSingle(float $amount = 500000): array
{
    $visit = psiVisit();

    return [$visit, psiInvoice($visit, $amount)];
}

/** An origin visit (invoice $origin) and its Kontrol visit (invoice $control). */
function psiControlChain(float $origin = 200000, float $control = 100000): array
{
    $originVisit = psiVisit();
    $originInvoice = psiInvoice($originVisit, $origin);
    $controlVisit = psiVisit([
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $originVisit->id,
    ]);
    $controlInvoice = psiInvoice($controlVisit, $control);

    return compact('originVisit', 'originInvoice', 'controlVisit', 'controlInvoice');
}

function psiPayload(float $amount, array $overrides = []): array
{
    return array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ], $overrides);
}

/** Pays $invoice through the canonical service, as another cashier would. */
function psiPayElsewhere(RmeInvoice $invoice, float $amount): void
{
    $other = userWith(['manage_rme_billing', 'view_clinic_visits']);
    app(RmePaymentService::class)->pay($invoice->fresh(), $other, rmeFreshPaymentForm($invoice->fresh(), psiPayload($amount)));
}

function psiPaymentCount(RmeInvoice $invoice): int
{
    return RmePayment::query()->where('rme_invoice_id', $invoice->id)->count();
}

function psiRecordedTotal(): float
{
    return round((float) RmePayment::query()->sum('amount'), 2);
}

// ─── F1: a submission recorded at most once ──────────────────────────────────

it('records a partial payment form submitted twice (double click) exactly once', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));

    $this->actingAs($this->cashier);
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);

    $this->post($store, $form)->assertRedirect();
    $this->post($store, $form);

    expect(psiPaymentCount($invoice))->toBe(1)
        ->and($invoice->fresh()->paidAmount())->toBe(100000.0)
        ->and($invoice->fresh()->remainingAmount())->toBe(400000.0);
});

it('answers a replayed partial payment with the original receipt, not a new payment and not an error', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));

    $this->actingAs($this->cashier);
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);

    $this->post($store, $form);
    $original = RmePayment::query()->where('rme_invoice_id', $invoice->id)->firstOrFail();

    $this->post($store, $form)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', fn (string $status) => str_contains($status, $original->payment_number)
            && str_contains($status, 'sudah tercatat'));

    expect(psiPaymentCount($invoice))->toBe(1);
});

it('answers a replayed full payment with the original result instead of a validation error', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(500000));

    $this->actingAs($this->cashier);
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);

    $this->post($store, $form)->assertRedirect(route('rme.cashier.receipt.show', [$visit, $invoice]));

    $this->post($store, $form)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('rme.cashier.receipt.show', [$visit, $invoice]));

    expect(psiPaymentCount($invoice))->toBe(1)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('does not let a direct service call record the same submission twice — the repetition is answered with the original payment', function () {
    [, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $service = app(RmePaymentService::class);

    $original = $service->pay($invoice->fresh(), $this->cashier, $form);

    // A repetition must be recognised as the SAME submission under the lock —
    // not merely refused because its declared balance has gone stale.
    $replayed = null;
    try {
        $service->pay($invoice->fresh(), $this->cashier, $form);
    } catch (RmePaymentSubmissionReplayed $e) {
        $replayed = $e->submission;
    }

    expect($replayed)->not->toBeNull()
        ->and((int) $replayed->rme_payment_id)->toBe((int) $original->id)
        ->and(psiPaymentCount($invoice))->toBe(1);
});

it('refuses every direct service call that carries no payment intent and records nothing', function (string $path) {
    ['originInvoice' => $origin, 'controlInvoice' => $control] = psiControlChain(200000, 100000);
    [, $single] = psiSingle(500000);
    $service = app(RmePaymentService::class);

    $call = match ($path) {
        'single' => function () use ($service, $single) {
            $form = rmeFreshPaymentForm($single, psiPayload(100000));
            unset($form[RmePaymentIntent::FIELD]);
            $service->pay($single->fresh(), $this->cashier, $form);
        },
        'control' => function () use ($service, $control) {
            $form = rmeFreshPaymentForm($control, psiPayload(300000));
            unset($form[RmePaymentIntent::FIELD]);
            $service->allocateControlPayment($control->fresh(), $this->cashier, $form);
        },
        'control_only' => function () use ($service, $control) {
            $form = rmeFreshPaymentForm($control, psiPayload(100000, [
                'payment_scope' => RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY,
                'confirm_control_only' => true,
            ]));
            unset($form[RmePaymentIntent::FIELD]);
            $service->payControlInvoiceOnly($control->fresh(), $this->cashier, $form);
        },
        'visit' => function () use ($service, $origin, $single) {
            $form = rmeFreshPaymentForm($single, psiPayload(100000, ['selected_receivable_ids' => [$origin->id]]));
            unset($form[RmePaymentIntent::FIELD]);
            $service->allocateVisitPayment($single->fresh(), $this->cashier, $form, [$origin->id]);
        },
    };

    $refusal = null;
    try {
        $call();
    } catch (ValidationException $e) {
        $refusal = (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    expect($refusal)->toContain('PAYMENT_INTENT_MISSING')
        ->and(RmePayment::query()->count())->toBe(0)
        ->and(RmePaymentSubmission::query()->count())->toBe(0);
})->with(['single', 'control', 'control_only', 'visit']);

it('records two genuinely separate installments from two separately opened forms', function () {
    [$visit, $invoice] = psiSingle(500000);
    $this->actingAs($this->cashier);
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);

    $this->post($store, rmeFreshPaymentForm($invoice, psiPayload(100000)))->assertSessionHasNoErrors();
    $this->post($store, rmeFreshPaymentForm($invoice->fresh(), psiPayload(100000)))->assertSessionHasNoErrors();

    expect(psiPaymentCount($invoice))->toBe(2)
        ->and($invoice->fresh()->remainingAmount())->toBe(300000.0);
});

// ─── F3: no payment decided on a stale, pre-lock summary ─────────────────────

it('refuses a Kontrol payment whose origin changed after the summary was read, instead of truncating it', function () {
    ['originInvoice' => $origin, 'controlInvoice' => $control] = psiControlChain(200000, 100000);
    $form = rmeFreshPaymentForm($control, psiPayload(300000));

    // The summary is read, then the origin is settled elsewhere before the
    // payment reaches the origin row: exactly the pre-lock window of F3.
    app()->instance(RmeControlReceivableService::class, new class(app(BranchService::class), $origin) extends RmeControlReceivableService
    {
        private bool $fired = false;

        public function __construct(BranchService $branches, private readonly RmeInvoice $origin)
        {
            parent::__construct($branches);
        }

        public function getControlPayableSummary(RmeInvoice $controlInvoice): array
        {
            $summary = parent::getControlPayableSummary($controlInvoice);

            if (! $this->fired) {
                $this->fired = true;
                psiPayElsewhere($this->origin, 200000);
            }

            return $summary;
        }
    });

    $before = psiPaymentCount($control);

    expect(fn () => app(RmePaymentService::class)->allocateControlPayment($control->fresh(), $this->cashier, $form))
        ->toThrow(ValidationException::class);

    // Nothing was recorded on the Kontrol invoice: 300.000 received is never
    // booked as 100.000.
    expect(psiPaymentCount($control))->toBe($before);
});

it('refuses a Kontrol payment when the origin the cashier saw was settled before submit, instead of moving the money onto the Kontrol invoice', function () {
    ['controlVisit' => $controlVisit, 'originInvoice' => $origin, 'controlInvoice' => $control] = psiControlChain(200000, 100000);
    $form = rmeFreshPaymentForm($control, psiPayload(100000));

    psiPayElsewhere($origin, 200000);

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$controlVisit, $control]), $form)
        ->assertSessionHasErrors();

    expect(psiPaymentCount($control))->toBe(0)
        ->and($control->fresh()->remainingAmount())->toBe(100000.0);
});

it('refuses a visit payment whose selected previous receivable was settled before submit, instead of paying only the current invoice', function () {
    $oldVisit = psiVisit(['visit_date' => now()->subDays(10)->toDateString()]);
    $old = psiInvoice($oldVisit, 150000);
    $oldVisit->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    [$visit, $current] = psiSingle(100000);

    // 80.000 is collected oldest-first, i.e. towards the old receivable the cashier
    // selected. Once that receivable is gone the same 80.000 would fit the current
    // invoice — which is exactly the silent redistribution F3 must refuse.
    $form = rmeFreshPaymentForm($current, psiPayload(80000, ['selected_receivable_ids' => [$old->id]]));
    psiPayElsewhere($old, 150000);

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $current]), $form)
        ->assertSessionHasErrors();

    expect(psiPaymentCount($current))->toBe(0)
        ->and($current->fresh()->remainingAmount())->toBe(100000.0);
});

it('never records less than the amount the cashier entered on a successful batch', function () {
    ['originInvoice' => $origin, 'controlInvoice' => $control] = psiControlChain(200000, 100000);

    $result = app(RmePaymentService::class)->allocateControlPayment(
        $control->fresh(),
        $this->cashier,
        rmeFreshPaymentForm($control, psiPayload(300000)),
    );

    expect(round($result->allocatedToParent + $result->allocatedToControl, 2))->toBe(300000.0)
        ->and(psiRecordedTotal())->toBe(300000.0)
        ->and($origin->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

// ─── The idempotency matrix ───────────────────────────────────────────────────

/** @return Collection<int, object> */
function psiAudit(string $action): Collection
{
    return DB::table('sys_audit_logs')->where('action', $action)->orderBy('id')->get();
}

it('M01 refuses the same intent presented again with a different amount, and audits the reuse once', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $this->actingAs($this->cashier);
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);

    $this->post($store, $form)->assertSessionHasNoErrors();
    $this->post($store, array_merge($form, ['amount' => 150000]))
        ->assertSessionHasErrors(RmePaymentIntent::FIELD);

    expect(psiPaymentCount($invoice))->toBe(1)
        ->and($invoice->fresh()->paidAmount())->toBe(100000.0)
        ->and(psiAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INTENT_REUSED))->toHaveCount(1);
});

it('M02 refuses an intent minted for another invoice', function () {
    [, $first] = psiSingle(500000);
    [$visit, $second] = psiSingle(500000);
    $form = rmeFreshPaymentForm($second, psiPayload(100000, [
        RmePaymentIntent::FIELD => RmePaymentIntent::issue((int) $first->id),
    ]));

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $second]), $form)
        ->assertSessionHasErrors(RmePaymentIntent::FIELD);

    expect(psiPaymentCount($second))->toBe(0)
        ->and(RmePaymentSubmission::query()->count())->toBe(0);
});

it('M03 refuses a forged, malformed or missing intent and records nothing', function (string $shape) {
    [$visit, $invoice] = psiSingle(500000);
    $genuine = RmePaymentIntent::issue((int) $invoice->id);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));

    $form[RmePaymentIntent::FIELD] = match ($shape) {
        'forged signature' => substr($genuine, 0, -1).(str_ends_with($genuine, '0') ? '1' : '0'),
        'not a token' => 'abc',
        'uppercase hex' => strtoupper($genuine),
        'array' => [$genuine],
        'missing' => null,
    };
    if ($shape === 'missing') {
        unset($form[RmePaymentIntent::FIELD]);
    }

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), $form)
        ->assertSessionHasErrors(RmePaymentIntent::FIELD);

    expect(psiPaymentCount($invoice))->toBe(0)
        ->and(RmePaymentSubmission::query()->count())->toBe(0);
})->with(['forged signature', 'not a token', 'uppercase hex', 'array', 'missing']);

it('M04 tells the cashier to reload when the form predates the payment intent', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    unset($form[RmePaymentIntent::FIELD]);

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), $form)
        ->assertSessionHasErrors(RmePaymentIntent::FIELD);

    $message = implode(' ', session('errors')->get(RmePaymentIntent::FIELD));
    expect($message)->toContain('[PAYMENT_INTENT_MISSING]')
        ->and($message)->toContain('Muat ulang');
});

it('M05 refuses another cashier presenting a committed intent and never shows them the receipt', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);

    $this->actingAs($this->cashier)->post($store, $form)->assertSessionHasNoErrors();
    $receipt = (string) RmePayment::query()->where('rme_invoice_id', $invoice->id)->value('payment_number');

    $other = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $response = $this->actingAs($other)->post($store, $form);

    $response->assertSessionHasErrors(RmePaymentIntent::FIELD)
        ->assertSessionMissing('payment_replayed');
    expect((string) json_encode(session('errors')?->getBag('default')->toArray()))->not->toContain($receipt)
        ->and((string) session('status'))->not->toContain($receipt)
        ->and(psiPaymentCount($invoice))->toBe(1);
});

it('M06 refuses a replay from an account that has since been deactivated instead of showing the receipt', function () {
    [, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, $form);

    $this->cashier->forceFill(['is_active' => false])->save();

    $outcome = null;
    try {
        app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, $form);
    } catch (RmePaymentSubmissionReplayed) {
        $outcome = 'replayed';
    } catch (ValidationException $e) {
        $outcome = (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    expect($outcome)->toContain('Akun kasir tidak aktif')
        ->and(psiPaymentCount($invoice))->toBe(1);
});

it('M07 answers a replayed visit batch with the original allocation and moves no money', function () {
    $oldVisit = psiVisit(['visit_date' => now()->subDays(10)->toDateString()]);
    $old = psiInvoice($oldVisit, 150000);
    $oldVisit->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    [$visit, $current] = psiSingle(100000);
    $form = rmeFreshPaymentForm($current, psiPayload(200000, ['selected_receivable_ids' => [$old->id]]));
    $store = route('rme.cashier.payment.store', [$visit, $current]);

    $this->actingAs($this->cashier)->post($store, $form)->assertSessionHasNoErrors();
    $paid = psiRecordedTotal();

    $this->post($store, $form)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('payment_replayed', true)
        ->assertSessionHas('payment_allocation');

    expect(psiRecordedTotal())->toBe($paid)
        ->and($paid)->toBe(200000.0)
        ->and(RmePayment::query()->count())->toBe(2)
        ->and(RmePaymentSubmission::query()->count())->toBe(1);
});

it('M08 leaves no ledger row for a refused submission, so the same intent may still be paid once corrected', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(900000));
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);

    $this->actingAs($this->cashier)->post($store, $form)->assertSessionHasErrors('amount');
    expect(RmePaymentSubmission::query()->count())->toBe(0);

    $this->post($store, array_merge($form, ['amount' => 100000]))->assertSessionHasNoErrors();

    expect(psiPaymentCount($invoice))->toBe(1)
        ->and(RmePaymentSubmission::query()->count())->toBe(1);
});

it('M09 keys the ledger row to the original payment, path, amount and cashier', function () {
    [, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $payment = app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, $form);
    $row = RmePaymentSubmission::query()->sole();

    expect((int) $row->rme_payment_id)->toBe((int) $payment->id)
        ->and((int) $row->rme_invoice_id)->toBe((int) $invoice->id)
        ->and((int) $row->branch_id)->toBe((int) $invoice->branch_id)
        ->and((int) $row->cashier_id)->toBe((int) $this->cashier->id)
        ->and($row->payment_path)->toBe('single')
        ->and((float) $row->amount)->toBe(100000.0)
        ->and((int) $row->payment_count)->toBe(1)
        ->and($row->intent_key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($row->request_fingerprint)->toMatch('/^[0-9a-f]{64}$/')
        ->and($row->committed_at)->not->toBeNull();
});

it('M10 refuses to update or delete a ledger row', function () {
    [, $invoice] = psiSingle(500000);
    app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, rmeFreshPaymentForm($invoice, psiPayload(100000)));
    $row = RmePaymentSubmission::query()->sole();

    expect(fn () => $row->update(['amount' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and((float) $row->fresh()->amount)->toBe(100000.0);
});

it('M11 resolves a commit that loses on the unique index to the winner, never a second payment', function () {
    [, $invoice] = psiSingle(500000);
    [, $elsewhere] = psiSingle(300000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $intent = app(RmePaymentSubmissionService::class)->intentFor($invoice, $this->cashier, $form);

    // The winner: a committed ledger row for THIS intent, invoice, cashier and
    // payload. It references a real payment of another invoice so the route
    // invoice's balance — which the page declared — is left exactly as it was,
    // the state a concurrent first submission is in before it commits.
    $winner = app(RmePaymentService::class)->pay($elsewhere->fresh(), $this->cashier, rmeFreshPaymentForm($elsewhere, psiPayload(50000)));
    DB::table('trx_rme_payment_submissions')->insert([
        'intent_key' => $intent['key'],
        'rme_invoice_id' => $invoice->id,
        'branch_id' => $invoice->branch_id,
        'cashier_id' => $this->cashier->id,
        'payment_path' => 'single',
        'request_fingerprint' => $intent['fingerprint'],
        'amount' => 100000,
        'rme_payment_id' => $winner->id,
        'payment_count' => 1,
        'committed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The lookup misses once — exactly the window in which the winner had not
    // committed yet — so this attempt reaches its own insert and collides there.
    app()->instance(RmePaymentSubmissionRepositoryInterface::class, new class(new RmePaymentSubmissionRepository) implements RmePaymentSubmissionRepositoryInterface
    {
        private bool $missed = false;

        public function __construct(private readonly RmePaymentSubmissionRepository $inner) {}

        public function findByIntentKey(string $intentKey): ?RmePaymentSubmission
        {
            if (! $this->missed) {
                $this->missed = true;

                return null;
            }

            return $this->inner->findByIntentKey($intentKey);
        }

        public function create(array $data): RmePaymentSubmission
        {
            return $this->inner->create($data);
        }
    });
    app()->forgetInstance(RmePaymentSubmissionService::class);
    app()->forgetInstance(RmePaymentService::class);

    $replayed = null;
    try {
        app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, $form);
    } catch (RmePaymentSubmissionReplayed $e) {
        $replayed = $e->submission;
    }

    expect($replayed)->not->toBeNull()
        ->and($replayed->intent_key)->toBe($intent['key'])
        ->and(psiPaymentCount($invoice))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(RmePaymentSubmission::query()->where('intent_key', $intent['key'])->count())->toBe(1);
});

it('M12 audits a replay once per window and keeps every rule-205 audit free of patient identity and free text', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000, [
        'reference_number' => 'REF-PSI-7788',
        'notes' => 'Catatan kasir untuk pasien',
    ]));
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);
    $this->actingAs($this->cashier);

    $this->post($store, $form);
    $this->post($store, $form);
    $this->post($store, $form);
    $this->post($store, array_merge($form, ['amount' => 120000]));

    $replays = psiAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_SUBMISSION_REPLAYED);
    $payloads = (string) json_encode([
        $replays->pluck('new_values')->all(),
        psiAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INTENT_REUSED)->pluck('new_values')->all(),
    ]);

    expect($replays)->toHaveCount(1)
        ->and($payloads)->not->toContain($this->patient->name)
        ->and($payloads)->not->toContain('DG-PSI1-2026-0001')
        ->and($payloads)->not->toContain('REF-PSI-7788')
        ->and($payloads)->not->toContain('Catatan kasir')
        ->and(psiPaymentCount($invoice))->toBe(1);
});

it('M13 renders the intent, the plan, the displayed balances and the double-submit guard into the payment page', function () {
    $oldVisit = psiVisit(['visit_date' => now()->subDays(10)->toDateString()]);
    $old = psiInvoice($oldVisit, 150000);
    $oldVisit->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    [$visit, $current] = psiSingle(100000);

    $html = $this->actingAs($this->cashier)
        ->get(route('rme.cashier.payment.create', [$visit, $current]))
        ->assertOk()
        ->getContent();

    preg_match('/name="payment_intent" value="([^"]+)"/', $html, $match);

    expect($match)->toHaveCount(2)
        ->and(RmePaymentIntent::keyFrom($match[1], (int) $current->id))->not->toBeNull()
        ->and($html)->toContain('name="payment_plan" value="'.RmePaymentDeclaredView::PLAN_RECEIVABLES.'"')
        ->and($html)->toContain('name="invoice_balances['.$current->id.']" value="100000.00"')
        ->and($html)->toContain('name="invoice_balances['.$old->id.']" value="150000.00"')
        ->and($html)->toContain('data-payment-form')
        ->and($html)->toContain('data-payment-retry-guidance');
});

it('M14 issues a different intent each time the page is rendered', function () {
    [$visit, $invoice] = psiSingle(500000);
    $this->actingAs($this->cashier);
    $page = fn () => $this->get(route('rme.cashier.payment.create', [$visit, $invoice]))->getContent();

    preg_match('/name="payment_intent" value="([^"]+)"/', $page(), $first);
    preg_match('/name="payment_intent" value="([^"]+)"/', $page(), $second);

    expect($first[1])->not->toBe($second[1]);
});

it('M15 does not restore the stale amount after a stale-balance refusal', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(123456));
    psiPayElsewhere($invoice, 100000);
    $this->actingAs($this->cashier);

    $this->from(route('rme.cashier.payment.create', [$visit, $invoice]))
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), $form)
        ->assertSessionHasErrors(RmePaymentDeclaredView::BALANCES_FIELD);

    $html = $this->get(route('rme.cashier.payment.create', [$visit, $invoice]))->getContent();

    expect($html)->toContain('data-payment-stale-balance-error')
        ->and($html)->not->toContain('value="123456"')
        ->and(psiPaymentCount($invoice))->toBe(1);
});

it('M16 generates lab candidates once and never again for a replay', function () {
    $this->treatment->update(['requires_lab' => true]);
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(500000));
    $store = route('rme.cashier.payment.store', [$visit, $invoice]);
    $this->actingAs($this->cashier);

    $this->post($store, $form)->assertSessionHasNoErrors();
    $candidates = LabCaseCandidate::query()->count();
    $this->post($store, $form)->assertSessionHasNoErrors();

    expect($candidates)->toBeGreaterThan(0)
        ->and(LabCaseCandidate::query()->count())->toBe($candidates)
        ->and(psiPaymentCount($invoice))->toBe(1);
});

it('M17 refuses a payment whose declared plan does not match the page the server renders', function () {
    $oldVisit = psiVisit(['visit_date' => now()->subDays(10)->toDateString()]);
    $old = psiInvoice($oldVisit, 150000);
    $oldVisit->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    [$visit, $current] = psiSingle(100000);

    $form = rmeFreshPaymentForm($current, psiPayload(200000, [
        'selected_receivable_ids' => [$old->id],
        RmePaymentDeclaredView::PLAN_FIELD => RmePaymentDeclaredView::PLAN_CONTROL,
    ]));

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $current]), $form)
        ->assertSessionHasErrors(RmePaymentDeclaredView::BALANCES_FIELD);

    expect(RmePayment::query()->count())->toBe(0);
});

it('M18 refuses a selected receivable the page declared no balance for', function () {
    $oldVisit = psiVisit(['visit_date' => now()->subDays(10)->toDateString()]);
    $old = psiInvoice($oldVisit, 150000);
    $oldVisit->update(['status' => ClinicVisit::STATUS_COMPLETED]);
    [$visit, $current] = psiSingle(100000);

    $form = rmeFreshPaymentForm($current, psiPayload(200000, ['selected_receivable_ids' => [$old->id]]));
    unset($form[RmePaymentDeclaredView::BALANCES_FIELD][$old->id]);

    expect(fn () => app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), $this->cashier, $form, [$old->id]))
        ->toThrow(ValidationException::class);

    expect(RmePayment::query()->count())->toBe(0);
});

it('M19 refuses a malformed declared balance as missing', function (mixed $declared) {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $form[RmePaymentDeclaredView::BALANCES_FIELD][$invoice->id] = $declared;

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), $form)
        ->assertSessionHasErrors(RmePaymentDeclaredView::BALANCES_FIELD);

    expect(psiPaymentCount($invoice))->toBe(0);
})->with([
    'negative' => ['-500000.00'],
    'text' => ['lima ratus ribu'],
    'array' => [['500000.00']],
]);

it('M20 compares the declared balance in cents, so an equal value written differently still pays', function () {
    [$visit, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $form[RmePaymentDeclaredView::BALANCES_FIELD][$invoice->id] = '500000';

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), $form)
        ->assertSessionHasNoErrors();

    expect(psiPaymentCount($invoice))->toBe(1);
});

// ─── Security review follow-ups (L1–L3) ──────────────────────────────────────

it('L1 never writes a stale-view refusal into another patient\'s or another branch\'s audit trail', function () {
    [$visit, $invoice] = psiSingle(500000);
    $foreignBranch = Branch::factory()->create(['code' => 'PSI9', 'is_rme_enabled' => true, 'is_active' => true]);
    $foreign = RmeInvoice::factory()->unpaid()->create([
        'branch_id' => $foreignBranch->id,
        'patient_id' => Patient::factory()->create(['branch_id' => $foreignBranch->id])->id,
        'subtotal' => 900000, 'discount_total' => 0, 'grand_total' => 900000,
    ]);

    // A crafted form claims the page displayed a receivable it never showed.
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000, ['selected_receivable_ids' => [$foreign->id]]));
    $form[RmePaymentDeclaredView::PLAN_FIELD] = RmePaymentDeclaredView::PLAN_RECEIVABLES;
    $form[RmePaymentDeclaredView::BALANCES_FIELD][$foreign->id] = '1.00';

    $this->actingAs($this->cashier)
        ->post(route('rme.cashier.payment.store', [$visit, $invoice]), $form)
        ->assertSessionHasErrors(RmePaymentDeclaredView::BALANCES_FIELD);

    $rows = psiAudit(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_STALE_BALANCE);
    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->entity_id)->toBe((int) $invoice->id)
        ->and($rows->pluck('entity_id')->map(fn ($id) => (int) $id)->all())->not->toContain((int) $foreign->id)
        ->and((string) json_encode($rows->pluck('new_values')->all()))->not->toContain((string) $foreign->invoice_number)
        ->and(RmePayment::query()->count())->toBe(0);
});

it('L2 keeps two submissions that differ only in invalid UTF-8 text two different fingerprints', function () {
    [, $invoice] = psiSingle(500000);
    $base = rmeFreshPaymentForm($invoice, psiPayload(100000));

    $a = RmePaymentIntent::fingerprint((int) $invoice->id, (int) $this->cashier->id, array_merge($base, ['notes' => "kas \xff"]));
    $b = RmePaymentIntent::fingerprint((int) $invoice->id, (int) $this->cashier->id, array_merge($base, ['notes' => "kas \xfe"]));
    $c = RmePaymentIntent::fingerprint((int) $invoice->id, (int) $this->cashier->id, array_merge($base, ['notes' => "kas \xff", 'amount' => 200000]));

    expect($a)->not->toBe($b)
        ->and($a)->not->toBe($c)
        ->and($a)->not->toBe(hash('sha256', ''))
        ->and($a)->toMatch('/^[0-9a-f]{64}$/');
});

it('L3 retries a payment aborted by a lock conflict, then refuses it with a reload instruction — never a 500, never a write', function () {
    [, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));
    $attempts = new stdClass;
    $attempts->count = 0;

    app()->instance(RmePaymentRepositoryInterface::class, new class($attempts) extends RmePaymentRepository
    {
        public function __construct(private readonly stdClass $attempts) {}

        public function create(array $data): RmePayment
        {
            $this->attempts->count++;

            throw new QueryException('pgsql', 'insert into "trx_rme_payments"', [], new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'));
        }
    });
    app()->forgetInstance(RmePaymentService::class);

    $refusal = null;
    try {
        app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, $form);
    } catch (ValidationException $e) {
        $refusal = (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    // Inside an enclosing transaction (this suite's RefreshDatabase) Laravel cannot
    // retry the nested one — the enclosing transaction is the one aborted — so the
    // conflict surfaces on the first attempt; without one (production, and race U
    // on PostgreSQL) it is attempted three times. Either way: a clean refusal.
    expect($attempts->count)->toBe(DB::transactionLevel() > 0 ? 1 : 3)
        ->and($refusal)->toContain('PAYMENT_CONCURRENT_RETRY')
        ->and(RmePayment::query()->count())->toBe(0)
        ->and(RmePaymentSubmission::query()->count())->toBe(0);
});

it('L3 leaves every other database error with its original meaning', function () {
    [, $invoice] = psiSingle(500000);
    $form = rmeFreshPaymentForm($invoice, psiPayload(100000));

    app()->instance(RmePaymentRepositoryInterface::class, new class extends RmePaymentRepository
    {
        public function create(array $data): RmePayment
        {
            throw new QueryException('pgsql', 'insert into "trx_rme_payments"', [], new PDOException('SQLSTATE[23502]: Not null violation'));
        }
    });
    app()->forgetInstance(RmePaymentService::class);

    expect(fn () => app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, $form))
        ->toThrow(QueryException::class);
});

it('M21 refuses to roll the ledger table back while it holds a committed submission', function () {
    [, $invoice] = psiSingle(500000);
    app(RmePaymentService::class)->pay($invoice->fresh(), $this->cashier, rmeFreshPaymentForm($invoice, psiPayload(100000)));
    $migration = require database_path('migrations/2026_10_25_100001_create_trx_rme_payment_submissions_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Roll forward instead')
        ->and(RmePaymentSubmission::query()->count())->toBe(1);
});
