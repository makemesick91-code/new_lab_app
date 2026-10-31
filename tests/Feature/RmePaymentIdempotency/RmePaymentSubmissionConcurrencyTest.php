<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 — payment submission identity and the
| under-lock decision, under REAL PostgreSQL concurrency (rule 205).
|--------------------------------------------------------------------------
|
| THE LINEARIZATION POINTS.
|   - Replay: a payment looks its intent up AFTER it holds the route-invoice
|     lock. A first submission that committed before that lock is answered as a
|     replay; one that rolled back, or has not committed, is not.
|   - The commit backstop: UNIQUE(intent_key) on trx_rme_payment_submissions.
|     A transaction that passed the lookup because the other one had not
|     committed yet waits on the index entry and loses at commit; the loss is
|     resolved to the winner's result, never a second payment.
|   - Stale view: plan and balances are compared with the LOCKED rows. A
|     payment from a page whose numbers changed while it waited is refused
|     whole — never truncated, never redistributed.
|
| THE PROOF. Each scenario starts a child PHP process (payment_race_child.php)
| that boots the REAL application and runs the REAL service inside one
| transaction it holds open, while the parent runs the competing real service.
| `$waited > 1.0` proves the two genuinely overlapped: the parent was blocked on
| a row lock or an index entry the child held. Raw SQL appears only as a lock
| primitive and for the backstop scenario (P), where the ledger row must be
| written WITHOUT the route-invoice lock that would otherwise serialize them.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing; the
| property is unobservable there. The skip is stated, never a silent pass.
|
| WHY NO RefreshDatabase. A second session cannot see rows of a transaction that
| never commits. This suite commits for real and deletes what it created by id
| watermark — the pattern of RmeBillingDisputeConcurrencyTest.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=<port> DB_DATABASE=<db> \
|   DB_USERNAME=<user> DB_PASSWORD=<password> \
|   php artisan test tests/Feature/RmePaymentIdempotency/RmePaymentSubmissionConcurrencyTest.php
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Interfaces\RmePaymentSubmissionRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePaymentSubmission;
use App\Modules\RmeInvoice\Repositories\RmePaymentSubmissionRepository;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Services\RmePaymentSubmissionService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmePaymentSubmissionReplayed;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeBillingDispute/helpers.php';

const RPSC_WRITER = 'rpsc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RPSC_TABLES = [
    // FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1: refund rows reference the
    // obligation, invoice and users with RESTRICT, so they go first.
    'trx_rme_refund_request_events',
    'trx_rme_refund_patient_references',
    'trx_rme_refund_requests',
    'trx_rme_owner_approval_references', // HARDENING-RME-OWNER-REFERENCE-REGISTRY-1: child of users only
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
    'trx_lab_case_candidates',
    'trx_rme_payment_submissions',
    'trx_rme_billing_dispute_events',
    'trx_rme_billing_disputes',
    // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — every payment issues a receipt
    // snapshot (FK to trx_rme_payments); obligations reference corrections.
    'trx_rme_overpayment_obligation_events',
    'trx_rme_overpayment_obligations',
    'trx_rme_receipt_snapshots',
    'trx_rme_invoice_correction_events',
    'trx_rme_invoice_correction_lines',
    'trx_rme_invoice_document_versions',
    'trx_rme_invoice_corrections',
    'trx_rme_payments',
    'trx_rme_invoice_items',
    'trx_rme_invoices',
    'trx_rme_visit_consents',
    'trx_medical_records',
    'trx_clinic_visits',
    'mst_clinic_rooms',
    'mst_patients',
    'mst_doctors',
    'mst_clinics',
    'mst_treatments',
    'mst_treatment_categories',
    'mst_branches',
    'users',
];

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Row-lock and unique-index waits are only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RPSC_WRITER, $base);
    DB::purge(RPSC_WRITER);
    config()->set('database.default', RPSC_WRITER);

    seedAccessControl();
    ricFlag(true);

    $this->watermarks = [];
    foreach (RPSC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RPSC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RPSC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();
    $writer->table('notifications')
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RPSC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RPSC_WRITER);
});

/**
 * A committed patient with one UNPAID 500.000 invoice, two cashiers and a pilot
 * correction maker — admitted like production admits SPN4.
 *
 * @return array{branch: Branch, patient: Patient, cashier: User, cashier2: User, maker: User, invoice: RmeInvoice}
 */
function rpscFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'PS'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        ricPilot([(string) $branch->code]);
        $maker = userWith(['request_rme_invoice_correction']);
        $checker = userWith(['approve_rme_invoice_correction']);
        ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

        $fx = [
            'branch' => $branch,
            'patient' => Patient::factory()->create(['branch_id' => $branch->id]),
            'cashier' => userWith(['manage_rme_billing', 'view_clinic_visits']),
            'cashier2' => userWith(['manage_rme_billing', 'view_clinic_visits']),
            'maker' => $maker,
        ];
        $fx['invoice'] = rpscInvoice($fx);

        return $fx;
    });
}

/**
 * One cashier-pending visit with a 500.000 UNPAID invoice. With $origin it is a
 * Kontrol (follow-up) visit of the origin invoice's visit.
 *
 * @param  array<string, mixed>  $fx
 */
function rpscInvoice(array $fx, ?RmeInvoice $origin = null): RmeInvoice
{
    return Model::withoutEvents(function () use ($fx, $origin) {
        $clinic = Clinic::factory()->create();
        $doctor = Doctor::factory()->create(['branch_id' => $fx['branch']->id]);
        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $fx['branch']->id,
            'clinic_id' => $clinic->id,
            'patient_id' => $fx['patient']->id,
            'doctor_id' => $doctor->id,
            'visit_type' => $origin ? ClinicVisit::VISIT_TYPE_CONTROL : ClinicVisit::VISIT_TYPE_NEW,
            'follow_up_of_visit_id' => $origin?->clinic_visit_id,
            'visit_number' => 'VIS-PS-'.uniqid(),
        ]);
        $record = MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => $fx['branch']->id,
            'patient_id' => $fx['patient']->id, 'doctor_id' => $doctor->id,
        ]);

        $invoice = RmeInvoice::factory()->unpaid()->create([
            'branch_id' => $fx['branch']->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $fx['patient']->id,
            'medical_record_id' => $record->id, 'cashier_id' => $fx['cashier']->id,
            'subtotal' => 500000, 'discount_total' => 0, 'grand_total' => 500000,
        ]);
        foreach ([['Scaling', 300000], ['Tambal', 200000]] as [$desc, $price]) {
            $treatment = Treatment::factory()->create(['name' => $desc.' '.uniqid(), 'is_active' => true, 'requires_lab' => false]);
            DB::table('trx_rme_invoice_items')->insert([
                'rme_invoice_id' => $invoice->id, 'treatment_id' => $treatment->id, 'description' => $desc, 'qty' => 1,
                'unit_price' => $price, 'discount' => 0, 'subtotal' => $price,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $invoice->fresh();
    });
}

/**
 * A payment form exactly as the page for $route renders it NOW: fresh intent,
 * current versions, current plan and balances.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function rpscForm(RmeInvoice $route, int $amount, array $extra = []): array
{
    return rmeFreshPaymentForm($route->fresh(), array_merge([
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
    ], $extra));
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rpscCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RPSC_WRITER);

    $payload = base64_encode((string) json_encode([
        'steps' => $steps,
        'hold' => $hold,
        'finish' => $finish,
        'config' => [
            'feature_flags.flags' => config('feature_flags.flags'),
            'rme_invoice_correction.pilot.branch_codes' => config('rme_invoice_correction.pilot.branch_codes'),
            'rme_invoice_correction.pilot.identities' => config('rme_invoice_correction.pilot.identities'),
        ],
    ]));

    $env = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        // The parent minted every intent with this key; the child must verify it.
        'APP_KEY' => (string) config('app.key'),
        'APP_URL' => (string) config('app.url'),
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $db['host'],
        'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) $db['username'],
        'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'null',
        'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null',
    ];

    $process = proc_open(
        [PHP_BINARY, __DIR__.'/payment_race_child.php', $payload],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        $env,
    );
    expect($process)->toBeResource();

    $line = trim((string) fgets($pipes[1]));
    if ($line !== 'LOCKED') {
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        test()->fail('The competing transaction did not take its locks: '.$line.' '.$err);
    }

    return [$process, $pipes, $finish];
}

/** @param array{0: resource, 1: array<int, resource>, 2: string} $handle */
function rpscJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

/**
 * Run $work as the parent and report what happened, timing the wait.
 *
 * @return array{outcome: string, detail: string, waited: float, submission: ?RmePaymentSubmission}
 */
function rpscRace(callable $work): array
{
    $started = microtime(true);
    $outcome = 'accepted';
    $detail = '';
    $submission = null;

    try {
        $work();
    } catch (RmePaymentSubmissionReplayed $replay) {
        $outcome = 'replayed';
        $submission = $replay->submission;
    } catch (ValidationException $e) {
        $outcome = 'refused';
        $detail = (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    return ['outcome' => $outcome, 'detail' => $detail, 'waited' => microtime(true) - $started, 'submission' => $submission];
}

/** @param list<RmeInvoice> $invoices */
function rpscPaymentCount(array $invoices): int
{
    return DB::table('trx_rme_payments')->whereIn('rme_invoice_id', array_map(fn (RmeInvoice $i) => $i->id, $invoices))->count();
}

/** @param list<RmeInvoice> $invoices */
function rpscPaidTotal(array $invoices): float
{
    return (float) DB::table('trx_rme_payments')->whereIn('rme_invoice_id', array_map(fn (RmeInvoice $i) => $i->id, $invoices))->sum('amount');
}

/** @param list<RmeInvoice> $invoices */
function rpscSubmissionCount(array $invoices): int
{
    return DB::table('trx_rme_payment_submissions')->whereIn('rme_invoice_id', array_map(fn (RmeInvoice $i) => $i->id, $invoices))->count();
}

/** @param array<string, mixed> $form @return array<string, mixed> */
function rpscPayStep(string $kind, RmeInvoice $invoice, User $cashier, array $form, array $extra = []): array
{
    return array_merge(['kind' => $kind, 'invoice' => (int) $invoice->id, 'actor' => (int) $cashier->id, 'form' => $form], $extra);
}

/** @param array<string, mixed> $fx */
function rpscReport(array $fx, RmeInvoice $invoice): void
{
    test()->actingAs($fx['cashier']);
    app(RmeBillingDisputeService::class)->report($invoice->fresh(), $fx['cashier'], [
        'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ]);
}

// ─── A–E: the same intent submitted twice at once (F1) ──────────────────────

it('A: a double-submitted partial payment waiting on the first that commits is answered as a replay and records nothing', function () {
    $fx = rpscFixture();
    $form = rpscForm($fx['invoice'], 200000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $form)]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    $first = DB::table('trx_rme_payments')->where('rme_invoice_id', $fx['invoice']->id)->first();
    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('replayed')
        ->and((int) $race['submission']->rme_payment_id)->toBe((int) $first->id)
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1)
        ->and(rpscPaidTotal([$fx['invoice']]))->toBe(200000.0)
        ->and(rpscSubmissionCount([$fx['invoice']]))->toBe(1)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

it('B: a double-submitted payment waiting on a first attempt that rolls back records exactly one payment', function () {
    $fx = rpscFixture();
    $form = rpscForm($fx['invoice'], 200000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $form)], 'rollback');

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('accepted')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1)
        ->and(rpscSubmissionCount([$fx['invoice']]))->toBe(1)
        ->and(rpscPaidTotal([$fx['invoice']]))->toBe(200000.0);
});

it('C: a double-submitted FULL payment is answered as a replay of the settled invoice, never an overpayment', function () {
    $fx = rpscFixture();
    $form = rpscForm($fx['invoice'], 500000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $form)]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('replayed')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1)
        ->and(rpscPaidTotal([$fx['invoice']]))->toBe(500000.0)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('D: the same intent with a different amount, waiting on the first that commits, is refused as reuse', function () {
    $fx = rpscFixture();
    $form = rpscForm($fx['invoice'], 200000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $form)]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], array_merge($form, ['amount' => 300000])));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('PAYMENT_INTENT_REUSED')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1)
        ->and(rpscPaidTotal([$fx['invoice']]))->toBe(200000.0);
});

it('E: the same intent submitted by a different cashier is refused as reuse and never shown the first receipt', function () {
    $fx = rpscFixture();
    $form = rpscForm($fx['invoice'], 200000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $form)]);

    test()->actingAs($fx['cashier2']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier2'], $form));
    rpscJoin($handle);

    $receipt = (string) DB::table('trx_rme_payments')->where('rme_invoice_id', $fx['invoice']->id)->value('payment_number');
    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('PAYMENT_INTENT_REUSED')
        ->and($race['detail'])->not->toContain($receipt)
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1);
});

// ─── F–G: two pages opened at once, two intents (stale amount) ─────────────

it('F: a second page that waited on another payment that commits is refused as stale, never a silent second partial', function () {
    $fx = rpscFixture();
    $first = rpscForm($fx['invoice'], 200000);
    $second = rpscForm($fx['invoice'], 200000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $first)]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $second));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('PAYMENT_BALANCE_CHANGED')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1)
        ->and(rpscPaidTotal([$fx['invoice']]))->toBe(200000.0);
});

it('G: a second page that waited on another payment that rolls back is accepted — its view is still true', function () {
    $fx = rpscFixture();
    $first = rpscForm($fx['invoice'], 200000);
    $second = rpscForm($fx['invoice'], 200000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $first)], 'rollback');

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $second));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('accepted')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1);
});

// ─── H–K: the multi-invoice paths decide under the lock (F3) ────────────────

it('H: a double-submitted Kontrol payment is answered as a replay of the whole batch', function () {
    $fx = rpscFixture();
    $origin = $fx['invoice'];
    $control = rpscInvoice($fx, $origin);
    $form = rpscForm($control, 700000);
    $handle = rpscCompeting([rpscPayStep('pay_control', $control, $fx['cashier'], $form)]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->allocateControlPayment($control->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('replayed')
        ->and(rpscPaymentCount([$origin, $control]))->toBe(2)
        ->and(rpscPaidTotal([$origin, $control]))->toBe(700000.0)
        ->and(rpscSubmissionCount([$origin, $control]))->toBe(1);
});

it('I: a Kontrol payment whose origin was settled while it waited is refused whole — never truncated onto the Kontrol invoice', function () {
    $fx = rpscFixture();
    $origin = $fx['invoice'];
    $control = rpscInvoice($fx, $origin);
    $form = rpscForm($control, 600000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $origin, $fx['cashier2'], rpscForm($origin, 500000))]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->allocateControlPayment($control->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('PAYMENT_PLAN_CHANGED')
        ->and(rpscPaymentCount([$control]))->toBe(0)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rpscPaidTotal([$origin]))->toBe(500000.0);
});

it('J: a visit payment whose selected receivable was settled while it waited is refused whole — never redistributed', function () {
    $fx = rpscFixture();
    $prior = $fx['invoice'];
    $current = rpscInvoice($fx);
    $form = rpscForm($current, 700000, ['selected_receivable_ids' => [$prior->id]]);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $prior, $fx['cashier2'], rpscForm($prior, 500000))]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), $fx['cashier'], $form, [$prior->id]));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('PAYMENT_PLAN_CHANGED')
        ->and(rpscPaymentCount([$current]))->toBe(0)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rpscPaidTotal([$prior]))->toBe(500000.0);
});

it('K: a visit payment whose selected receivable was partly paid while it waited is refused as a stale balance', function () {
    $fx = rpscFixture();
    $prior = $fx['invoice'];
    $current = rpscInvoice($fx);
    $form = rpscForm($current, 700000, ['selected_receivable_ids' => [$prior->id]]);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $prior, $fx['cashier2'], rpscForm($prior, 100000))]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), $fx['cashier'], $form, [$prior->id]));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('PAYMENT_BALANCE_CHANGED')
        ->and(rpscPaymentCount([$current]))->toBe(0)
        ->and(rpscPaidTotal([$prior]))->toBe(100000.0);
});

// ─── L: the Kontrol-only path (OPS-OQ-1b) ──────────────────────────────────

it('L: a double-submitted Kontrol-only payment is answered as a replay; the disputed origin stays untouched', function () {
    $fx = rpscFixture();
    $origin = $fx['invoice'];
    $control = rpscInvoice($fx, $origin);
    rpscReport($fx, $origin);
    $form = rpscForm($control, 500000, [
        'payment_scope' => RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY,
        'confirm_control_only' => true,
    ]);
    $handle = rpscCompeting([rpscPayStep('pay_control_only', $control, $fx['cashier'], $form)]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->payControlInvoiceOnly($control->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('replayed')
        ->and(rpscPaymentCount([$control]))->toBe(1)
        ->and(rpscPaymentCount([$origin]))->toBe(0)
        ->and($origin->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});

// ─── M–O: holds are never bypassed by an intent ─────────────────────────────

it('M: a fresh payment waiting on a dispute report that commits is held and leaves no submission row', function () {
    $fx = rpscFixture();
    $form = rpscForm($fx['invoice'], 200000);
    $handle = rpscCompeting([[
        'kind' => 'report_dispute', 'invoice' => (int) $fx['invoice']->id, 'actor' => (int) $fx['cashier']->id,
        'reason' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('BILLING_DISPUTE_ACTIVE')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(0)
        ->and(rpscSubmissionCount([$fx['invoice']]))->toBe(0);
});

it('N: a fresh payment waiting on a correction submission that commits is held by D10 and leaves no submission row', function () {
    $fx = rpscFixture();
    $form = rpscForm($fx['invoice'], 200000);
    $items = $fx['invoice']->items()->orderBy('id')->get();
    $handle = rpscCompeting([[
        'kind' => 'submit_correction', 'invoice' => (int) $fx['invoice']->id, 'actor' => (int) $fx['maker']->id,
        'data' => [
            'reason' => 'Harga tambal salah input oleh kasir.',
            'lines' => [
                ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
                ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
            ],
        ],
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('INVOICE_CORRECTION_ACTIVE')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(0)
        ->and(rpscSubmissionCount([$fx['invoice']]))->toBe(0);
});

it('O: a replay waiting on a dispute report that commits returns the original result and moves no money; a fresh attempt is held', function () {
    $fx = rpscFixture();
    test()->actingAs($fx['cashier']);
    $form = rpscForm($fx['invoice'], 200000);
    app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form);

    $handle = rpscCompeting([[
        'kind' => 'report_dispute', 'invoice' => (int) $fx['invoice']->id, 'actor' => (int) $fx['cashier']->id,
        'reason' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ]]);

    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);
    $fresh = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], rpscForm($fx['invoice'], 100000)));

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('replayed')
        ->and($fresh['outcome'])->toBe('refused')
        ->and($fresh['detail'])->toContain('BILLING_DISPUTE_ACTIVE')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1)
        ->and(rpscPaidTotal([$fx['invoice']]))->toBe(200000.0);
});

// ─── P: the commit backstop ─────────────────────────────────────────────────

it('P: a payment that passed the lookup before another ledger row committed waits on the unique index and loses — never a second write', function () {
    $fx = rpscFixture();
    $invoice = $fx['invoice'];
    $other = rpscInvoice($fx);
    $form = rpscForm($invoice, 200000);
    $intent = app(RmePaymentSubmissionService::class)->intentFor($invoice, $fx['cashier'], $form);

    // The competing writer records a ledger row holding the SAME intent key
    // against ANOTHER invoice of the patient. It never touches the route
    // invoice — not even the FOR KEY SHARE an insert referencing it would take —
    // so the only thing that can make the parent wait is the index entry. The
    // parent's lookup cannot see the uncommitted row, so it proceeds, inserts,
    // waits on UNIQUE(intent_key), and loses at the child's commit.
    $handle = rpscCompeting([[
        'kind' => 'statement',
        'sql' => <<<'SQL'
            WITH p AS (
                INSERT INTO trx_rme_payments (branch_id, rme_invoice_id, clinic_visit_id, patient_id, cashier_id, payment_number, amount, paid_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 200000, now(), now(), now())
                RETURNING id
            )
            INSERT INTO trx_rme_payment_submissions (intent_key, rme_invoice_id, branch_id, cashier_id, payment_path, request_fingerprint, amount, rme_payment_id, payment_count, committed_at, created_at, updated_at)
            SELECT ?, ?, ?, ?, 'single', ?, 200000, p.id, 1, now(), now(), now() FROM p
            SQL,
        'bindings' => [
            (int) $other->branch_id, (int) $other->id, (int) $other->clinic_visit_id, (int) $other->patient_id, (int) $fx['cashier']->id, 'PS-RACE-'.uniqid(),
            $intent['key'], (int) $other->id, (int) $other->branch_id, (int) $fx['cashier']->id, $intent['fingerprint'],
        ],
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($invoice->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('PAYMENT_INTENT_REUSED')
        ->and(rpscPaymentCount([$invoice]))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(DB::table('trx_rme_payment_submissions')->where('intent_key', $intent['key'])->count())->toBe(1)
        ->and((int) DB::table('trx_rme_payment_submissions')->where('intent_key', $intent['key'])->value('rme_invoice_id'))->toBe((int) $other->id);
});

// ─── Q–T ────────────────────────────────────────────────────────────────────

it('Q: two pages paying the full balance at once settle it exactly once', function () {
    $fx = rpscFixture();
    $first = rpscForm($fx['invoice'], 500000);
    $second = rpscForm($fx['invoice'], 500000);
    $handle = rpscCompeting([rpscPayStep('pay_invoice', $fx['invoice'], $fx['cashier'], $first)]);

    test()->actingAs($fx['cashier2']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier2'], $second));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1)
        ->and(rpscPaidTotal([$fx['invoice']]))->toBe(500000.0)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('R: a replay waiting on the deactivation of its cashier is refused — a revoked account is never shown the receipt', function () {
    $fx = rpscFixture();
    test()->actingAs($fx['cashier']);
    $form = rpscForm($fx['invoice'], 200000);
    app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form);

    $handle = rpscCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $fx['invoice']->id]],
        ['kind' => 'deactivate_user', 'user' => (int) $fx['cashier']->id],
    ]);

    $race = rpscRace(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('refused')
        ->and($race['detail'])->toContain('Akun kasir tidak aktif')
        ->and(rpscPaymentCount([$fx['invoice']]))->toBe(1);
});

it('S: a double-submitted Kontrol payment waiting on a first attempt that rolls back records the batch exactly once', function () {
    $fx = rpscFixture();
    $origin = $fx['invoice'];
    $control = rpscInvoice($fx, $origin);
    $form = rpscForm($control, 700000);
    $handle = rpscCompeting([rpscPayStep('pay_control', $control, $fx['cashier'], $form)], 'rollback');

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->allocateControlPayment($control->fresh(), $fx['cashier'], $form));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('accepted')
        ->and(rpscPaymentCount([$origin, $control]))->toBe(2)
        ->and(rpscPaidTotal([$origin, $control]))->toBe(700000.0)
        ->and(rpscSubmissionCount([$origin, $control]))->toBe(1);
});

it('T: a double-submitted visit payment with a selected receivable is answered as a replay of the whole batch', function () {
    $fx = rpscFixture();
    $prior = $fx['invoice'];
    $current = rpscInvoice($fx);
    $form = rpscForm($current, 700000, ['selected_receivable_ids' => [$prior->id]]);
    $handle = rpscCompeting([rpscPayStep('pay_visit', $current, $fx['cashier'], $form, ['selected' => [$prior->id]])]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), $fx['cashier'], $form, [$prior->id]));
    rpscJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['outcome'])->toBe('replayed')
        ->and(rpscPaymentCount([$prior, $current]))->toBe(2)
        ->and(rpscPaidTotal([$prior, $current]))->toBe(700000.0)
        ->and(rpscSubmissionCount([$prior, $current]))->toBe(1);
});

// ─── U: a deadlock between two payments of one patient (security review L3) ──

it('U: a visit payment that deadlocks with another transaction is retried and settles once — never a 500', function () {
    $fx = rpscFixture();
    $prior = $fx['invoice'];
    $current = rpscInvoice($fx);
    $form = rpscForm($current, 700000, ['selected_receivable_ids' => [$prior->id]]);
    $deadlocks = fn (): int => (int) DB::connection(RPSC_WRITER)->selectOne('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()')->deadlocks;
    $before = $deadlocks();

    // The competing transaction holds the receivable, releases the parent, then
    // reaches for the invoice the parent locks first: a cycle PostgreSQL must break.
    $handle = rpscCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $prior->id]],
        ['kind' => 'signal'],
        ['kind' => 'sleep', 'seconds' => 0.5],
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $current->id]],
    ]);

    test()->actingAs($fx['cashier']);
    $race = rpscRace(fn () => app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), $fx['cashier'], $form, [$prior->id]));
    rpscJoin($handle);

    $observed = $before;
    for ($i = 0; $i < 30 && $observed === $before; $i++) {
        usleep(100_000);
        $observed = $deadlocks();
    }

    expect($observed)->toBeGreaterThan($before)
        ->and($race['outcome'])->toBe('accepted')
        ->and($race['waited'])->toBeGreaterThan(1.0)
        ->and(rpscPaymentCount([$prior, $current]))->toBe(2)
        ->and(rpscPaidTotal([$prior, $current]))->toBe(700000.0)
        ->and(rpscSubmissionCount([$prior, $current]))->toBe(1);
});

// ─── V: a retried attempt never reuses state from the rolled-back one (R1) ──

it('V: a payment retried after a lock conflict still completes its visit — the retry never reuses the rolled-back visit model', function (string $path) {
    $fx = rpscFixture();
    $origin = $fx['invoice'];
    $control = rpscInvoice($fx, $origin);

    [$route, $call] = match ($path) {
        'control' => [$control, fn () => app(RmePaymentService::class)->allocateControlPayment($control->fresh(), $fx['cashier'], rpscForm($control, 1000000))],
        'control_only' => [$control, function () use ($fx, $origin, $control) {
            rpscReport($fx, $origin);
            test()->actingAs($fx['cashier']);

            return app(RmePaymentService::class)->payControlInvoiceOnly($control->fresh(), $fx['cashier'], rpscForm($control, 500000, [
                'payment_scope' => RmePaymentService::PAYMENT_SCOPE_CONTROL_ONLY,
                'confirm_control_only' => true,
            ]));
        }],
        'visit' => [$control, fn () => app(RmePaymentService::class)->allocateVisitPayment($control->fresh(), $fx['cashier'], rpscForm($control, 1000000, ['selected_receivable_ids' => [$origin->id]]), [$origin->id])],
    };
    if ($path === 'visit') {
        // An ordinary (non-Kontrol) current visit, so the visit path applies.
        $control->clinicVisit()->update(['visit_type' => ClinicVisit::VISIT_TYPE_NEW, 'follow_up_of_visit_id' => null]);
    }

    // The ledger insert is the LAST write of an attempt — after the visit was
    // completed in it. Fail the first attempt there with a lock conflict.
    $failed = new stdClass;
    $failed->times = 0;
    app()->instance(RmePaymentSubmissionRepositoryInterface::class, new class(new RmePaymentSubmissionRepository, $failed) implements RmePaymentSubmissionRepositoryInterface
    {
        public function __construct(private readonly RmePaymentSubmissionRepository $inner, private readonly stdClass $failed) {}

        public function findByIntentKey(string $intentKey): ?RmePaymentSubmission
        {
            return $this->inner->findByIntentKey($intentKey);
        }

        public function create(array $data): RmePaymentSubmission
        {
            if ($this->failed->times === 0) {
                $this->failed->times++;

                throw new QueryException('pgsql', 'insert into "trx_rme_payment_submissions"', [], new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'));
            }

            return $this->inner->create($data);
        }
    });
    app()->forgetInstance(RmePaymentSubmissionService::class);
    app()->forgetInstance(RmePaymentService::class);

    test()->actingAs($fx['cashier']);
    $call();

    expect($failed->times)->toBe(1)
        ->and($route->clinicVisit()->value('status'))->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(rpscSubmissionCount([$origin, $control]))->toBe(1);
})->with(['control', 'control_only', 'visit']);
