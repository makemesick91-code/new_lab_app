<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1 (rule 201) — two races that
| were not covered by any suite, under REAL PostgreSQL concurrency.
|--------------------------------------------------------------------------
|
| RACE A — CORRECTION REJECTION vs PAYMENT (D10).
|   A payment reads the invoice's active correction under the invoice row lock;
|   a rejection takes that same lock first (invoice → correction). So:
|     rejection commits first  ⇒ the payment waits, then is ACCEPTED (the
|                                invoice is otherwise payable, version unchanged);
|     rejection rolls back     ⇒ the payment waits, then is REFUSED (D10 — the
|                                correction is still active), and records nothing.
|
| RACE B — ACCOUNT DEACTIVATION vs A CORRECTION MUTATION.
|   The service re-reads the actor (ActiveAccountResolver) AFTER it holds the
|   invoice lock. That read is the LINEARIZATION POINT:
|     deactivation committed before it (even while the mutation was waiting on
|       the invoice lock) ⇒ the mutation is REFUSED (ACTOR_UNUSABLE), nothing
|       written, the attempt audited;
|     deactivation still uncommitted at it ⇒ the mutation proceeds and is
|       ordered BEFORE the deactivation — even if the deactivation commits while
|       the mutation's transaction is still open. The schedule is serializable
|       (mutation, then deactivation): the mutation never writes the account
|       row, and the account was active when the authority was evaluated. A late
|       deactivation does not retroactively undo an authorized commit; it stops
|       the NEXT mutation.
|
| THE PROOF. The competing transaction is a child PHP process
| (race_child.php) that boots the REAL application and runs the REAL service —
| RmeInvoiceCorrectionService::reject(), UserService::deactivate() — inside one
| transaction it holds open, while the parent runs the other real service. Raw
| SQL is used only for the two lock primitives that represent "something holds
| this lock" (a FOR UPDATE on the invoice, a SHARE lock that pauses the parent
| after its authorization read).
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing; the
| property is unobservable there. The skip is stated, never a silent pass.
|
| WHY NO RefreshDatabase. A second session cannot see rows of a transaction that
| never commits. This suite commits for real and deletes what it created by id
| watermark — the pattern of RmeInvoiceCorrectionConcurrencyTest.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55491 DB_DATABASE=zog \
|   DB_USERNAME=zog DB_PASSWORD=zog \
|   php artisan test tests/Feature/RmeInvoiceCorrection/RmeInvoiceCorrectionRejectionDeactivationRaceTest.php
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionDenied;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const RDR_WRITER = 'rdr_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RDR_TABLES = [
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
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
    config()->set('database.connections.'.RDR_WRITER, $base);
    DB::purge(RDR_WRITER);
    config()->set('database.default', RDR_WRITER);

    seedAccessControl();
    ricFlag(true);

    $this->watermarks = [];
    foreach (RDR_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RDR_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RDR_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RDR_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RDR_WRITER);
});

/**
 * A committed UNPAID 500.000 invoice and the designated maker / checker plus a
 * cashier, admitted to the pilot like production admits SPN4 / 29 / 1.
 *
 * @return array{invoice: RmeInvoice, maker: User, checker: User, cashier: User}
 */
function rdrCommittedInvoice(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RD'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        ricPilot([(string) $branch->code]);
        $clinic = Clinic::factory()->create();
        $doctor = Doctor::factory()->create(['branch_id' => $branch->id]);
        $patient = Patient::factory()->create(['branch_id' => $branch->id]);
        $cashier = userWith(['manage_rme_billing', 'view_clinic_visits']);

        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $branch->id,
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-RD-'.uniqid(),
        ]);
        $record = MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => $branch->id,
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        ]);

        $invoice = RmeInvoice::factory()->unpaid()->create([
            'branch_id' => $branch->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $patient->id,
            'medical_record_id' => $record->id, 'cashier_id' => $cashier->id,
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

        $maker = userWith(['request_rme_invoice_correction']);
        $checker = userWith(['approve_rme_invoice_correction']);
        ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

        return ['invoice' => $invoice->fresh(), 'maker' => $maker, 'checker' => $checker, 'cashier' => $cashier];
    });
}

/** @return list<array<string, mixed>> */
function rdrLines(RmeInvoice $invoice): array
{
    $items = $invoice->items()->orderBy('id')->get();

    return [
        ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
        ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
    ];
}

function rdrSubmitted(array $fx): RmeInvoiceCorrection
{
    return app(RmeInvoiceCorrectionService::class)->submit($fx['invoice'], $fx['maker'], [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'lines' => rdrLines($fx['invoice']),
    ]);
}

/**
 * Starts the competing transaction (race_child.php) and returns once it holds
 * every lock its steps took. The caller must rdrJoin() the handle.
 *
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rdrCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RDR_WRITER);

    // The child sees exactly the configuration the parent's request sees.
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
        [PHP_BINARY, __DIR__.'/race_child.php', $payload],
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
function rdrJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

function rdrLockInvoice(RmeInvoice $invoice): array
{
    return ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $invoice->id]];
}

function rdrPayForm(RmeInvoice $invoice, int $amount): array
{
    // Rule 205: the page also carries a fresh intent and declares its plan and balances.
    return rmeDeclaredPaymentForm($invoice, ['amount' => $amount, 'paid_at' => now()->format('Y-m-d H:i:s'), 'invoice_versions' => [$invoice->id => 0]]);
}

function rdrRefusalCount(string $action, int $entityId): int
{
    return DB::table('sys_audit_logs')->where('action', $action)->where('entity_id', $entityId)->count();
}

// ─── RACE A — correction rejection vs payment ──────────────────────────────

it('A1: a payment waits on a mid-flight rejection, then is accepted once the rejection commits', function () {
    $fx = rdrCommittedInvoice();
    $correction = rdrSubmitted($fx);

    // The REAL rejection holds the invoice → correction locks, uncommitted.
    $handle = rdrCompeting([[
        'kind' => 'reject_correction', 'correction' => (int) $correction->id,
        'actor' => (int) $fx['checker']->id, 'note' => 'Ditolak: harga tambal sudah benar.',
    ]]);

    $this->actingAs($fx['cashier']);
    $started = microtime(true);
    app(RmePaymentService::class)->pay($fx['invoice'], $fx['cashier'], rdrPayForm($fx['invoice'], 100000));
    $waited = microtime(true) - $started;
    rdrJoin($handle);

    $invoice = $fx['invoice']->fresh();
    expect($waited)->toBeGreaterThan(1.0)
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::REJECTED)
        ->and((int) $correction->fresh()->decided_by)->toBe((int) $fx['checker']->id)
        // Exactly one payment — no double payment, no partial commit.
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe(1)
        ->and((float) DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(100000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        // The rejection changed no money and no version: the page's version 0 stays valid.
        ->and((int) $invoice->correction_version)->toBe(0)
        ->and((float) $invoice->grand_total)->toEqual(500000.0)
        ->and(DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and(DB::table('trx_rme_invoice_correction_events')->where('rme_invoice_correction_id', $correction->id)->where('to_status', RmeInvoiceCorrectionStatus::REJECTED)->count())->toBe(1)
        ->and(rdrRefusalCount(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_CORRECTION, (int) $invoice->id))->toBe(0);
});

it('A2: a payment waits on a rejection that rolls back, then D10 refuses it — and the next rejection releases it', function () {
    $fx = rdrCommittedInvoice();
    $correction = rdrSubmitted($fx);

    $handle = rdrCompeting([[
        'kind' => 'reject_correction', 'correction' => (int) $correction->id,
        'actor' => (int) $fx['checker']->id, 'note' => 'Ditolak lalu dibatalkan transaksinya.',
    ]], 'rollback');

    $this->actingAs($fx['cashier']);
    $started = microtime(true);
    $refusal = null;
    try {
        app(RmePaymentService::class)->pay($fx['invoice'], $fx['cashier'], rdrPayForm($fx['invoice'], 100000));
    } catch (ValidationException $e) {
        $refusal = json_encode($e->errors());
    }
    $waited = microtime(true) - $started;
    rdrJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('INVOICE_CORRECTION_ACTIVE')
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $fx['invoice']->id)->count())->toBe(0)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(DB::table('trx_rme_invoice_correction_events')->where('rme_invoice_correction_id', $correction->id)->where('to_status', RmeInvoiceCorrectionStatus::REJECTED)->count())->toBe(0)
        ->and(rdrRefusalCount(RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_CORRECTION, (int) $fx['invoice']->id))->toBe(1);

    // The release path: a committed rejection, then the same page's payment succeeds.
    app(RmeInvoiceCorrectionService::class)->reject($correction, $fx['checker'], 'Ditolak: harga tambal sudah benar.');
    app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], rdrPayForm($fx['invoice'], 100000));

    expect(DB::table('trx_rme_payments')->where('rme_invoice_id', $fx['invoice']->id)->count())->toBe(1)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_PARTIAL);
});

// ─── RACE B — account deactivation vs a correction mutation ────────────────

it('B1: a deactivation committed while the mutation waits on the invoice lock refuses it (%s)', function (string $action) {
    $fx = rdrCommittedInvoice();
    $service = app(RmeInvoiceCorrectionService::class);
    $correction = $action === 'submit' ? null : rdrSubmitted($fx);
    $actor = in_array($action, ['submit', 'cancel'], true) ? $fx['maker'] : $fx['checker'];
    $auditBefore = DB::table('sys_audit_logs')->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED)->count();

    // The competitor holds the invoice lock AND deactivates the actor through the
    // real UserService; both commit together after the hold.
    $handle = rdrCompeting([rdrLockInvoice($fx['invoice']), ['kind' => 'deactivate_user', 'user' => (int) $actor->id]]);

    $started = microtime(true);
    $code = null;
    try {
        // $actor is the object loaded BEFORE the deactivation: still "active" in memory.
        match ($action) {
            'submit' => $service->submit($fx['invoice'], $actor, ['reason' => 'Harga tambal salah input oleh kasir.', 'lines' => rdrLines($fx['invoice'])]),
            'approve' => $service->approve($correction, $actor, null),
            'reject' => $service->reject($correction, $actor, 'Ditolak oleh akun yang sedang dinonaktifkan.'),
            'cancel' => $service->cancel($correction, $actor, 'Ditarik oleh akun yang sedang dinonaktifkan.'),
        };
    } catch (RmeInvoiceCorrectionDenied $e) {
        $code = $e->reasonCode;
    }
    $waited = microtime(true) - $started;
    rdrJoin($handle);

    $invoice = $fx['invoice']->fresh();
    expect($waited)->toBeGreaterThan(1.0)
        ->and($code)->toBe(RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE)
        ->and((bool) DB::table('users')->where('id', $actor->id)->value('is_active'))->toBeFalse()
        ->and((float) $invoice->grand_total)->toEqual(500000.0)
        ->and((int) $invoice->correction_version)->toBe(0)
        ->and(DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and(DB::table('trx_rme_invoice_corrections')->where('rme_invoice_id', $invoice->id)->count())->toBe($correction === null ? 0 : 1)
        // The refused attempt is evidence: audited after the rollback.
        ->and(DB::table('sys_audit_logs')->where('action', RmeFinancialAuditEvent::ACTION_CORRECTION_REFUSED)->count())->toBe($auditBefore + 1);

    if ($correction !== null) {
        expect($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED);
    }
})->with(['submit', 'approve', 'reject', 'cancel']);

it('B2: a deactivation still uncommitted at the authorization read orders the approval before it — the next mutation is refused', function () {
    $fx = rdrCommittedInvoice();
    $other = rdrCommittedInvoice();
    $service = app(RmeInvoiceCorrectionService::class);

    // One maker / one checker over both invoices' branches, like production's 29 / 1.
    ricPilot([(string) $fx['invoice']->branch->code, (string) $other['invoice']->branch->code]);
    ricPilotIdentities([(int) $fx['maker']->id], [(int) $fx['checker']->id]);
    $correction = rdrSubmitted($fx);
    $pending = $service->submit($other['invoice'], $fx['maker'], ['reason' => 'Koreksi kedua yang masih menunggu.', 'lines' => rdrLines($other['invoice'])]);

    // The competitor deactivates the checker (uncommitted) and holds a SHARE lock
    // on the correction-event table. The approval reads the account (still active
    // — the deactivation is not committed), passes every check, and then blocks
    // on its first event insert. The deactivation COMMITS while the approval's
    // transaction is still open; the approval then completes.
    $handle = rdrCompeting([
        ['kind' => 'statement', 'sql' => 'LOCK TABLE trx_rme_invoice_correction_events IN SHARE MODE'],
        ['kind' => 'deactivate_user', 'user' => (int) $fx['checker']->id],
    ]);

    $started = microtime(true);
    $applied = $service->approve($correction, $fx['checker'], null);
    $waited = microtime(true) - $started;
    rdrJoin($handle);

    $invoice = $fx['invoice']->fresh();
    expect($waited)->toBeGreaterThan(1.0)
        ->and($applied->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $applied->applied_by)->toBe((int) $fx['checker']->id)
        ->and((float) $invoice->grand_total)->toEqual(350000.0)
        ->and((int) $invoice->correction_version)->toBe(1)
        ->and(DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->orderBy('version_number')->pluck('version_number')->all())->toBe([0, 1])
        ->and((bool) DB::table('users')->where('id', $fx['checker']->id)->value('is_active'))->toBeFalse();

    // The deactivation stops the checker's NEXT mutation, on any path…
    foreach (['reject', 'approve'] as $action) {
        $code = null;
        try {
            $action === 'reject'
                ? $service->reject($pending, $fx['checker'], 'Ditolak oleh akun yang sudah nonaktif.')
                : $service->approve($pending, $fx['checker'], null);
        } catch (RmeInvoiceCorrectionDenied $e) {
            $code = $e->reasonCode;
        }
        expect($code)->toBe(RmeInvoiceCorrectionDenied::ACTOR_UNUSABLE);
    }

    // …and, with no usable checker left, nothing new can be filed (rule 197 L2):
    // a correction nobody may decide would hold its invoice behind D10.
    $code = null;
    try {
        $service->submit($invoice, $fx['maker'], ['reason' => 'Koreksi baru tanpa checker aktif.', 'lines' => rdrLines($invoice)]);
    } catch (RmeInvoiceCorrectionDenied $e) {
        $code = $e->reasonCode;
    }

    expect($code)->toBe(RmeInvoiceCorrectionDenied::PILOT_IDENTITY_NOT_AUTHORIZED)
        ->and($pending->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::SUBMITTED)
        // The maker's own release path stays open (D10): cancel needs no checker.
        ->and($service->cancel($pending, $fx['maker'], 'Ditarik: checker tidak lagi aktif.')->status)->toBe(RmeInvoiceCorrectionStatus::CANCELLED);
});
