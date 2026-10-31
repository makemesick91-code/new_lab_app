<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — the dispute and its
| payment hold under REAL PostgreSQL concurrency (rule 203).
|--------------------------------------------------------------------------
|
| THE LOCK ORDER. Every dispute mutation locks the INVOICE row first, then the
| dispute row; every payment locks the invoice row(s) first and reads the
| active dispute under that lock. So a dispute action and a payment of the same
| invoice are serialized by one row lock, and neither can observe a half state.
|
| THE LINEARIZATION POINTS.
|   - The active-dispute read of a payment happens UNDER the invoice lock: a
|     report that committed before the payment took the lock holds it; a report
|     that rolled back, or that has not started, does not.
|   - The actor re-read of a dispute action (ActiveAccountResolver) happens
|     AFTER the invoice lock: a deactivation committed before that read refuses
|     the action; one still uncommitted at it orders the action first.
|   - One active dispute per invoice is decided under the invoice lock and
|     backed by the partial unique index — two racing reports never both win.
|
| THE PROOF. The competing transaction is a child PHP process
| (dispute_race_child.php) that boots the REAL application and runs the REAL
| services inside one transaction it holds open, while the parent runs the
| other real service. Raw SQL appears only as a lock primitive.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing; the
| property is unobservable there. The skip is stated, never a silent pass.
|
| WHY NO RefreshDatabase. A second session cannot see rows of a transaction
| that never commits. This suite commits for real and deletes what it created
| by id watermark — the pattern of RmeInvoiceCorrectionRejectionDeactivationRaceTest.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=<port> DB_DATABASE=<db> \
|   DB_USERNAME=<user> DB_PASSWORD=<password> \
|   php artisan test tests/Feature/RmeBillingDispute/RmeBillingDisputeConcurrencyTest.php
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeDenied;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const RBDC_WRITER = 'rbdc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RBDC_TABLES = [
    // FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1: refund rows reference the
    // obligation, invoice and users with RESTRICT, so they go first.
    'trx_rme_refund_request_events',
    'trx_rme_refund_patient_references',
    'trx_rme_refund_requests',
    'trx_rme_owner_approval_references', // HARDENING-RME-OWNER-REFERENCE-REGISTRY-1: child of users only
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
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
    'trx_rme_payment_submissions', // rule 205 — references trx_rme_payments
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
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RBDC_WRITER, $base);
    DB::purge(RBDC_WRITER);
    config()->set('database.default', RBDC_WRITER);

    seedAccessControl();
    ricFlag(true);

    $this->watermarks = [];
    foreach (RBDC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RBDC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RBDC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();
    // Database notifications are keyed by UUID: delete them by recipient.
    $writer->table('notifications')
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RBDC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RBDC_WRITER);
});

/**
 * A committed patient with one UNPAID 500.000 invoice (or more), a cashier, two
 * reviewers, and a pilot maker — all admitted like production admits SPN4.
 *
 * @return array{branch: Branch, invoice: RmeInvoice, cashier: User, reviewer: User, reviewer2: User, maker: User, patient: Patient}
 */
function rbdcFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'DC'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        ricPilot([(string) $branch->code]);
        $patient = Patient::factory()->create(['branch_id' => $branch->id]);
        $cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);
        $maker = userWith(['request_rme_invoice_correction']);
        $checker = userWith(['approve_rme_invoice_correction']);
        // The identity allowlist is fail-closed as a pair: designate both halves.
        ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

        $fx = [
            'branch' => $branch,
            'patient' => $patient,
            'cashier' => $cashier,
            'reviewer' => userWith(['review_rme_billing_disputes']),
            'reviewer2' => userWith(['review_rme_billing_disputes']),
            'maker' => $maker,
        ];
        $fx['invoice'] = rbdcInvoice($fx);

        return $fx;
    });
}

/** @param array<string, mixed> $fx */
function rbdcInvoice(array $fx): RmeInvoice
{
    return Model::withoutEvents(function () use ($fx) {
        $clinic = Clinic::factory()->create();
        $doctor = Doctor::factory()->create(['branch_id' => $fx['branch']->id]);
        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $fx['branch']->id,
            'clinic_id' => $clinic->id,
            'patient_id' => $fx['patient']->id,
            'doctor_id' => $doctor->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-DC-'.uniqid(),
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
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rbdcCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RBDC_WRITER);

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
        [PHP_BINARY, __DIR__.'/dispute_race_child.php', $payload],
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
function rbdcJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

function rbdcPayForm(RmeInvoice $invoice, int $amount): array
{
    // Rule 205: the page also carries a fresh intent and declares its plan and balances.
    return rmeDeclaredPaymentForm($invoice, ['amount' => $amount, 'paid_at' => now()->format('Y-m-d H:i:s'), 'invoice_versions' => [$invoice->id => 0]]);
}

/** @return array<string, string> */
function rbdcReportStep(RmeInvoice $invoice, User $reporter): array
{
    return [
        'kind' => 'report_dispute', 'invoice' => (int) $invoice->id, 'actor' => (int) $reporter->id,
        'reason' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ];
}

/** @return array<string, mixed> */
function rbdcConfirmData(): array
{
    return [
        'outcome' => RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT,
        'resolution_reason' => 'Dicek dengan nota tindakan: harga sudah benar.',
        'resolution_reference' => 'OWNER-OK/2026-10-24/RACE',
    ];
}

function rbdcCommittedReport(array $fx, ?RmeInvoice $invoice = null): RmeBillingDispute
{
    $invoice ??= $fx['invoice'];
    test()->actingAs($fx['cashier']);

    return app(RmeBillingDisputeService::class)->report($invoice->fresh(), $fx['cashier'], [
        'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Harga tambal tertulis 200.000, seharusnya 50.000.',
    ]);
}

function rbdcRefusalMessage(callable $work): ?string
{
    try {
        $work();
    } catch (ValidationException $e) {
        return (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    return null;
}

function rbdcPayments(RmeInvoice $invoice): int
{
    return DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count();
}

// ─── A: two reports race ────────────────────────────────────────────────────

it('A: two racing reports of one invoice leave exactly one dispute; the loser is told it is a duplicate', function () {
    $fx = rbdcFixture();
    $handle = rbdcCompeting([rbdcReportStep($fx['invoice'], $fx['cashier'])]);

    $started = microtime(true);
    $refusal = rbdcRefusalMessage(fn () => rbdcCommittedReport($fx));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('BILLING_DISPUTE_DUPLICATE')
        ->and(RmeBillingDispute::query()->where('rme_invoice_id', $fx['invoice']->id)->count())->toBe(1)
        ->and(RmeBillingDispute::query()->where('rme_invoice_id', $fx['invoice']->id)->value('status'))->toBe(RmeBillingDisputeStatus::OPEN);
});

// ─── B / C: report vs payment ───────────────────────────────────────────────

it('B: a payment waiting on a report that commits is held, records no payment, and is audited once', function () {
    $fx = rbdcFixture();
    $handle = rbdcCompeting([rbdcReportStep($fx['invoice'], $fx['cashier'])]);

    test()->actingAs($fx['cashier']);
    $started = microtime(true);
    $refusal = rbdcRefusalMessage(fn () => app(RmePaymentService::class)->pay($fx['invoice'], $fx['cashier'], rbdcPayForm($fx['invoice'], 100000)));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('BILLING_DISPUTE_ACTIVE')
        ->and(rbdcPayments($fx['invoice']))->toBe(0)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(DB::table('sys_audit_logs')->where('action', RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_DISPUTE)->where('entity_id', $fx['invoice']->id)->count())->toBe(1);
});

it('C: a payment waiting on a report that rolls back is accepted, and no dispute exists', function () {
    $fx = rbdcFixture();
    $handle = rbdcCompeting([rbdcReportStep($fx['invoice'], $fx['cashier'])], 'rollback');

    test()->actingAs($fx['cashier']);
    $started = microtime(true);
    app(RmePaymentService::class)->pay($fx['invoice'], $fx['cashier'], rbdcPayForm($fx['invoice'], 100000));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and(rbdcPayments($fx['invoice']))->toBe(1)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and(RmeBillingDispute::query()->where('rme_invoice_id', $fx['invoice']->id)->count())->toBe(0);
});

it('D: a report waiting on a payment that commits snapshots the paid state it was decided against', function () {
    $fx = rbdcFixture();
    $handle = rbdcCompeting([[
        'kind' => 'pay_invoice', 'invoice' => (int) $fx['invoice']->id, 'actor' => (int) $fx['cashier']->id,
        'form' => rbdcPayForm($fx['invoice'], 100000),
    ]]);

    $started = microtime(true);
    $dispute = rbdcCommittedReport($fx);
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($dispute->invoice_status_at_report)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and((float) $dispute->invoice_paid_total_at_report)->toEqual(100000.0)
        ->and(rbdcPayments($fx['invoice']))->toBe(1);
});

// ─── E / F: resolution vs payment ───────────────────────────────────────────

it('E: a payment waiting on a resolution that commits is accepted once the hold is released', function () {
    $fx = rbdcFixture();
    $dispute = rbdcCommittedReport($fx);
    $handle = rbdcCompeting([[
        'kind' => 'resolve_dispute', 'dispute' => (int) $dispute->id, 'actor' => (int) $fx['reviewer']->id,
        'data' => rbdcConfirmData(),
    ]]);

    test()->actingAs($fx['cashier']);
    $started = microtime(true);
    app(RmePaymentService::class)->pay($fx['invoice'], $fx['cashier'], rbdcPayForm($fx['invoice'], 100000));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::RESOLVED)
        ->and(rbdcPayments($fx['invoice']))->toBe(1);
});

it('F: a payment waiting on a resolution that rolls back stays held', function () {
    $fx = rbdcFixture();
    $dispute = rbdcCommittedReport($fx);
    $handle = rbdcCompeting([[
        'kind' => 'resolve_dispute', 'dispute' => (int) $dispute->id, 'actor' => (int) $fx['reviewer']->id,
        'data' => rbdcConfirmData(),
    ]], 'rollback');

    test()->actingAs($fx['cashier']);
    $started = microtime(true);
    $refusal = rbdcRefusalMessage(fn () => app(RmePaymentService::class)->pay($fx['invoice'], $fx['cashier'], rbdcPayForm($fx['invoice'], 100000)));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('BILLING_DISPUTE_ACTIVE')
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN)
        ->and(rbdcPayments($fx['invoice']))->toBe(0);
});

// ─── G: resolution vs a correction filed meanwhile ──────────────────────────

it('G: a resolution waiting on a correction submission sees the active correction and refuses', function () {
    $fx = rbdcFixture();
    $dispute = rbdcCommittedReport($fx);
    $items = $fx['invoice']->items()->orderBy('id')->get();
    $handle = rbdcCompeting([[
        'kind' => 'submit_correction', 'invoice' => (int) $fx['invoice']->id, 'actor' => (int) $fx['maker']->id,
        'data' => [
            'reason' => 'Harga tambal salah input oleh kasir.',
            'lines' => [
                ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
                ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
            ],
        ],
    ]]);

    test()->actingAs($fx['reviewer']);
    $started = microtime(true);
    $refusal = rbdcRefusalMessage(fn () => app(RmeBillingDisputeService::class)->resolve($dispute, $fx['reviewer'], rbdcConfirmData()));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('BILLING_DISPUTE_CORRECTION_ACTIVE')
        ->and($dispute->fresh()->status)->toBe(RmeBillingDisputeStatus::OPEN);
});

// ─── H: two resolutions race ────────────────────────────────────────────────

it('H: two racing resolutions resolve once; the second sees a terminal dispute', function () {
    $fx = rbdcFixture();
    $dispute = rbdcCommittedReport($fx);
    $handle = rbdcCompeting([[
        'kind' => 'resolve_dispute', 'dispute' => (int) $dispute->id, 'actor' => (int) $fx['reviewer']->id,
        'data' => rbdcConfirmData(),
    ]]);

    test()->actingAs($fx['reviewer2']);
    $started = microtime(true);
    $refusal = rbdcRefusalMessage(fn () => app(RmeBillingDisputeService::class)->resolve($dispute, $fx['reviewer2'], rbdcConfirmData()));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    $resolved = $dispute->fresh();
    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('BILLING_DISPUTE_INVALID_TRANSITION')
        ->and($resolved->status)->toBe(RmeBillingDisputeStatus::RESOLVED)
        ->and((int) $resolved->resolved_by)->toBe((int) $fx['reviewer']->id)
        ->and(DB::table('trx_rme_billing_dispute_events')->where('rme_billing_dispute_id', $dispute->id)->where('event_type', 'RESOLVED')->count())->toBe(1);
});

// ─── I: deactivation vs report (the linearization point) ────────────────────

it('I: a deactivation committed while the report waits on the invoice lock refuses the report', function () {
    $fx = rbdcFixture();
    $reporter = $fx['cashier']; // loaded BEFORE the deactivation: still "active" in memory
    $handle = rbdcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $fx['invoice']->id]],
        ['kind' => 'deactivate_user', 'user' => (int) $reporter->id],
    ]);

    test()->actingAs($reporter);
    $started = microtime(true);
    $code = null;
    try {
        app(RmeBillingDisputeService::class)->report($fx['invoice'], $reporter, [
            'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
            'description' => 'Laporan dari akun yang sedang dinonaktifkan.',
        ]);
    } catch (RmeBillingDisputeDenied $e) {
        $code = $e->reasonCode;
    }
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($code)->toBe(RmeBillingDisputeDenied::ACTOR_UNUSABLE)
        ->and(RmeBillingDispute::query()->where('rme_invoice_id', $fx['invoice']->id)->count())->toBe(0)
        ->and(DB::table('sys_audit_logs')->where('action', RmeBillingDisputeAuditEvent::ACTION_REFUSED)->where('performed_by', $reporter->id)->count())->toBe(1);
});

// ─── J: a multi-invoice batch vs a report on one of its invoices ────────────

it('J: a visit payment waiting on a report against its selected receivable is refused whole — no partial allocation', function () {
    $fx = rbdcFixture();
    $prior = $fx['invoice'];
    $current = rbdcInvoice($fx);
    $handle = rbdcCompeting([rbdcReportStep($prior, $fx['cashier'])]);

    test()->actingAs($fx['cashier']);
    $started = microtime(true);
    $form = rmeDeclaredPaymentForm($current, [
        'amount' => 1000000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'selected_receivable_ids' => [$prior->id],
        'invoice_versions' => [$prior->id => 0, $current->id => 0],
    ]);
    $refusal = rbdcRefusalMessage(fn () => app(RmePaymentService::class)->allocateVisitPayment($current->fresh(), $fx['cashier'], $form, [$prior->id]));
    $waited = microtime(true) - $started;
    rbdcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('BILLING_DISPUTE_ACTIVE')
        ->and(rbdcPayments($prior))->toBe(0)
        ->and(rbdcPayments($current))->toBe(0)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID);
});
