<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217) — "Selesai Tanpa Biaya"
| under REAL PostgreSQL concurrency.
|--------------------------------------------------------------------------
|
| THE LOCK ORDER. The closure locks the INVOICE row first (the lock every
| payment path, every dispute mutation and every correction takes on this row),
| then the visit row. So a closure and a payment / dispute / Kontrol batch of the
| same invoice are serialized by one row lock and never observe a half state.
|
| THE LINEARIZATION POINTS.
|   - Everything the closure decides (status, payments, disputes, visit status,
|     eligibility) is read UNDER the invoice lock.
|   - The cashier account is re-read AFTER the invoice lock: a deactivation
|     committed before that read refuses the closure.
|   - An already-closed invoice is answered as it stands (idempotent): two
|     racing closures write exactly one NO_CHARGE transition and one audit row.
|
| THE PROOF. The competing transaction is a child PHP process
| (zero_value_race_child.php) that runs the REAL services inside one open
| transaction while the parent runs the other real service.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing; the
| property is unobservable there. The skip is stated, never a silent pass.
|
| WHY NO RefreshDatabase. A second session cannot see rows of a transaction that
| never commits. This suite commits for real and deletes what it created by id
| watermark.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=<port> DB_DATABASE=<db> \
|   DB_USERNAME=<user> DB_PASSWORD=<password> \
|   php artisan test tests/Feature/RmeZeroValueClosure/RmeZeroValueClosureConcurrencyTest.php
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
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

const RZVC_WRITER = 'rzvc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RZVC_TABLES = [
    // FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1: refund rows reference the
    // obligation, invoice and users with RESTRICT, so they go first.
    'trx_rme_refund_request_events',
    'trx_rme_refund_patient_references',
    'trx_rme_refund_requests',
    'trx_rme_owner_approval_references', // HARDENING-RME-OWNER-REFERENCE-REGISTRY-1: child of users only
    // HARDENING (rule 217 §9) race I: a context-bound Kasir's daily and online contexts (FK users, branches).
    'trx_daily_branch_contexts',
    'trx_user_online_contexts',
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
    'roles', // race I creates the Kasir role when the database carries no seeded roles
];

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RZVC_WRITER, $base);
    DB::purge(RZVC_WRITER);
    config()->set('database.default', RZVC_WRITER);

    seedAccessControl();
    rzvFlag(true);

    $this->watermarks = [];
    foreach (RZVC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RZVC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RZVC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();
    $writer->table('model_has_roles')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();
    $writer->table('notifications')
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RZVC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RZVC_WRITER);
});

/**
 * Committed rows: a branch, a patient, a cashier, a Konsultasi Dokter treatment,
 * and a cashier_pending NEW visit with a final record and an UNPAID Rp0
 * consultation invoice.
 *
 * @return array{branch: Branch, patient: Patient, cashier: User, clinic: Clinic, doctor: Doctor, consultation: Treatment, invoice: RmeInvoice}
 */
function rzvcFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'ZC'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        $category = TreatmentCategory::query()->where('code', 'consultation')->first()
            ?? TreatmentCategory::factory()->create(['code' => 'consultation', 'name' => 'Konsultasi']);

        $fx = [
            'branch' => $branch,
            'patient' => Patient::factory()->create(['branch_id' => $branch->id]),
            'cashier' => userWith(['manage_rme_billing', 'view_clinic_visits']),
            'clinic' => Clinic::factory()->create(),
            'doctor' => Doctor::factory()->create(['branch_id' => $branch->id]),
            'consultation' => Treatment::factory()->create([
                'name' => 'Konsultasi Dokter '.uniqid(), 'treatment_category_id' => $category->id,
                'is_active' => true, 'requires_lab' => false,
            ]),
        ];
        $fx['invoice'] = rzvcInvoice($fx, rzvcVisit($fx), 0);

        return $fx;
    });
}

/** @param array<string, mixed> $fx */
function rzvcVisit(array $fx, ?ClinicVisit $parent = null): ClinicVisit
{
    return Model::withoutEvents(function () use ($fx, $parent) {
        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $fx['branch']->id,
            'clinic_id' => $fx['clinic']->id,
            'patient_id' => $fx['patient']->id,
            'doctor_id' => $fx['doctor']->id,
            'visit_type' => $parent ? ClinicVisit::VISIT_TYPE_CONTROL : ClinicVisit::VISIT_TYPE_NEW,
            'follow_up_of_visit_id' => $parent?->id,
            'visit_number' => 'VIS-ZC-'.uniqid(),
        ]);
        MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => $fx['branch']->id,
            'patient_id' => $fx['patient']->id, 'doctor_id' => $fx['doctor']->id,
        ]);

        return $visit;
    });
}

/** @param array<string, mixed> $fx */
function rzvcInvoice(array $fx, ClinicVisit $visit, int $price): RmeInvoice
{
    return Model::withoutEvents(function () use ($fx, $visit, $price) {
        $invoice = RmeInvoice::factory()->unpaid()->create([
            'branch_id' => $fx['branch']->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $fx['patient']->id,
            'medical_record_id' => MedicalRecord::query()->where('clinic_visit_id', $visit->id)->value('id'),
            'cashier_id' => $fx['cashier']->id,
            'subtotal' => $price, 'discount_total' => 0, 'grand_total' => $price,
        ]);
        DB::table('trx_rme_invoice_items')->insert([
            'rme_invoice_id' => $invoice->id, 'treatment_id' => $fx['consultation']->id, 'description' => 'Konsultasi dokter', 'qty' => 1,
            'unit_price' => $price, 'discount' => 0, 'subtotal' => $price,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $invoice->fresh();
    });
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rzvcCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RZVC_WRITER);

    $payload = base64_encode((string) json_encode([
        'steps' => $steps,
        'hold' => $hold,
        'finish' => $finish,
        'config' => [
            'feature_flags.flags' => config('feature_flags.flags'),
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
        [PHP_BINARY, __DIR__.'/zero_value_race_child.php', $payload],
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
function rzvcJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

/** @return array{waited: float, refusal: ?string} */
function rzvcRace(callable $work): array
{
    $started = microtime(true);
    $refusal = null;
    try {
        $work();
    } catch (ValidationException $e) {
        $refusal = (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    }

    return ['waited' => microtime(true) - $started, 'refusal' => $refusal];
}

function rzvcCloseStep(RmeInvoice $invoice, User $actor): array
{
    return ['kind' => 'close_zero_value', 'invoice' => (int) $invoice->id, 'actor' => (int) $actor->id];
}

function rzvcAudits(RmeInvoice $invoice): int
{
    return DB::table('sys_audit_logs')
        ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
        ->where('entity_id', $invoice->id)
        ->count();
}

function rzvcPayments(RmeInvoice ...$invoices): int
{
    return DB::table('trx_rme_payments')->whereIn('rme_invoice_id', array_map(fn ($i) => $i->id, $invoices))->count();
}

// ─── A: two closures race ────────────────────────────────────────────────────

it('A: two racing closures write exactly one NO_CHARGE transition and one audit row; the loser is answered idempotently', function () {
    $fx = rzvcFixture();
    $handle = rzvcCompeting([rzvcCloseStep($fx['invoice'], $fx['cashier'])]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($fx['invoice']->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rzvcAudits($fx['invoice']))->toBe(1)
        ->and(rzvcPayments($fx['invoice']))->toBe(0)
        ->and(ClinicVisit::query()->find($fx['invoice']->clinic_visit_id)->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        // Rule 218 — the one audit row carries the one cross-check snapshot.
        ->and(rzvcSnapshot($fx['invoice'])['status'] ?? null)->toBe('INSUFFICIENT_STRUCTURED_EVIDENCE');
});

/** The rule 218 cross-check snapshot of the (single) closure audit row. */
function rzvcSnapshot(RmeInvoice $invoice): ?array
{
    $raw = DB::table('sys_audit_logs')
        ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
        ->where('entity_id', $invoice->id)
        ->sole()->new_values;
    $values = is_string($raw) ? json_decode($raw, true) : (array) $raw;

    return $values['clinical_billing_consistency'] ?? null;
}

// ─── M (rule 218): a Tindakan Awal edit racing the closure ───────────────────

it('M: a Tindakan Awal edit holding the visit row commits first; the waiting closure records the COMMITTED value, still closes, and writes one snapshot', function () {
    $fx = rzvcFixture();
    $category = TreatmentCategory::query()->where('code', 'preventive')->first()
        ?? TreatmentCategory::factory()->create(['code' => 'preventive', 'name' => 'Preventif']);
    $scaling = Treatment::factory()->create([
        'name' => 'Scaling '.uniqid(), 'treatment_category_id' => $category->id, 'is_active' => true, 'requires_lab' => false,
    ]);

    // The competing transaction takes the VISIT row lock the closure needs second
    // (after the invoice lock) and holds it while it changes the registered treatment.
    $handle = rzvcCompeting([[
        'kind' => 'select',
        'sql' => 'UPDATE trx_clinic_visits SET initial_treatment_id = ? WHERE id = ?',
        'bindings' => [(int) $scaling->id, (int) $fx['invoice']->clinic_visit_id],
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($fx['invoice']->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        // Detective (CB-Q2): the signal never refuses.
        ->and($race['refusal'])->toBeNull()
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rzvcAudits($fx['invoice']))->toBe(1)
        ->and(rzvcPayments($fx['invoice']))->toBe(0)
        ->and(rzvcSnapshot($fx['invoice']))->toMatchArray([
            'status' => 'REVIEW_REQUIRED',
            'signals' => ['CBC_REGISTERED_TREATMENT_NOT_BILLED'],
            'registered_treatment_id' => (int) $scaling->id,
        ]);
});

// ─── B / C: closure vs billing dispute ───────────────────────────────────────

it('B: a closure waiting on a dispute report that commits is refused and changes nothing', function () {
    $fx = rzvcFixture();
    $handle = rzvcCompeting([[
        'kind' => 'report_dispute', 'invoice' => (int) $fx['invoice']->id, 'actor' => (int) $fx['cashier']->id,
        'reason' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Pasien merasa konsultasi ini seharusnya berbayar.',
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($fx['invoice']->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain('peninjauan')
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rzvcAudits($fx['invoice']))->toBe(0)
        ->and(ClinicVisit::query()->find($fx['invoice']->clinic_visit_id)->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('C: a dispute report waiting on a closure that commits is refused — a NO_CHARGE invoice is not reportable', function () {
    $fx = rzvcFixture();
    $handle = rzvcCompeting([rzvcCloseStep($fx['invoice'], $fx['cashier'])]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmeBillingDisputeService::class)->report($fx['invoice']->fresh(), $fx['cashier'], [
        'reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT,
        'description' => 'Pasien merasa konsultasi ini seharusnya berbayar.',
    ]));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain('BILLING_DISPUTE_INVOICE_STATUS_UNSUPPORTED')
        ->and(RmeBillingDispute::query()->where('rme_invoice_id', $fx['invoice']->id)->count())->toBe(0)
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE);
});

// ─── D: closure vs account deactivation ──────────────────────────────────────

it('D: a closure waiting on the invoice lock while the cashier is deactivated is refused on the persisted account', function () {
    $fx = rzvcFixture();
    $admin = superAdmin();
    $handle = rzvcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $fx['invoice']->id]],
        ['kind' => 'deactivate_user', 'actor' => (int) $admin->id, 'user' => (int) $fx['cashier']->id],
    ]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($fx['invoice']->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->not->toBeNull()
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rzvcAudits($fx['invoice']))->toBe(0);
});

// ─── E: closure vs a Kontrol batch payment on the same Rp0 invoice ──────────

it('E: a Kontrol batch that pays the parent commits first, the visit is HELD, and the waiting closure then closes it (rule 217 §9)', function () {
    $fx = rzvcFixture();
    $parentVisit = rzvcVisit($fx);
    $parent = rzvcInvoice($fx, $parentVisit, 500000);
    $control = rzvcInvoice($fx, rzvcVisit($fx, $parentVisit), 0);
    $form = rmeDeclaredPaymentForm($control, [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$control->id => 0, $parent->id => 0],
    ]);

    $handle = rzvcCompeting([[
        'kind' => 'pay_control', 'invoice' => (int) $control->id, 'actor' => (int) $fx['cashier']->id, 'form' => $form,
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($control->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    // HARDENING (rule 217 §9): before, the batch completed the Kontrol visit around
    // its UNPAID Rp0 invoice (the #36 pattern) and this closure was refused
    // VISIT_NOT_PENDING. Now the batch leaves the closable visit cashier_pending, so
    // the closure — decided after it under the same lock — closes it, once.
    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and(rzvcPayments($control))->toBe(0)
        ->and(rzvcPayments($parent))->toBe(1)
        ->and($parent->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(ClinicVisit::query()->find($control->clinic_visit_id)->status)->toBe(ClinicVisit::STATUS_COMPLETED)
        ->and(rzvcAudits($control))->toBe(1);
});

it('F: a closure waiting on a closure that rolls back succeeds once', function () {
    $fx = rzvcFixture();
    $handle = rzvcCompeting([rzvcCloseStep($fx['invoice'], $fx['cashier'])], 'rollback');

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($fx['invoice']->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rzvcAudits($fx['invoice']))->toBe(1);
});

// ─── HARDENING (rule 217 §9) — the remaining §15 scenarios ──────────────────

/** @param array<string, mixed> $fx */
function rzvcMixedInvoice(array $fx, ClinicVisit $visit, int $paidPrice): RmeInvoice
{
    $invoice = rzvcInvoice($fx, $visit, 0);
    $category = TreatmentCategory::query()->where('code', 'preventive')->first()
        ?? TreatmentCategory::factory()->create(['code' => 'preventive', 'name' => 'Preventif']);
    $scaling = Treatment::factory()->create(['name' => 'Scaling '.uniqid(), 'treatment_category_id' => $category->id, 'is_active' => true, 'requires_lab' => false]);

    DB::table('trx_rme_invoice_items')->insert([
        'rme_invoice_id' => $invoice->id, 'treatment_id' => $scaling->id, 'description' => 'Scaling', 'qty' => 1,
        'unit_price' => $paidPrice, 'discount' => 0, 'subtotal' => $paidPrice, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('trx_rme_invoices')->where('id', $invoice->id)->update(['subtotal' => $paidPrice, 'grand_total' => $paidPrice]);

    return $invoice->fresh();
}

it('G: an ordinary carry-over batch that pays a prior receivable commits first; the new visit is held and the waiting closure closes it', function () {
    $fx = rzvcFixture();
    $prior = rzvcInvoice($fx, rzvcVisit($fx), 500000);
    $current = $fx['invoice'];
    $form = rmeDeclaredPaymentForm($current, [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$current->id => 0, $prior->id => 0],
    ]);

    $handle = rzvcCompeting([[
        'kind' => 'pay_visit', 'invoice' => (int) $current->id, 'actor' => (int) $fx['cashier']->id, 'form' => $form, 'selected' => [(int) $prior->id],
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($current->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and(rzvcPayments($current))->toBe(0)
        ->and(rzvcPayments($prior))->toBe(1)
        ->and($prior->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($current->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rzvcAudits($current))->toBe(1);
});

it('H: a closure commits first; a Kontrol batch waiting on it is refused and writes nothing — the parent stays collectible from its own invoice', function () {
    $fx = rzvcFixture();
    $parentVisit = rzvcVisit($fx);
    $parent = rzvcInvoice($fx, $parentVisit, 500000);
    $control = rzvcInvoice($fx, rzvcVisit($fx, $parentVisit), 0);
    $form = rmeDeclaredPaymentForm($control, [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$control->id => 0, $parent->id => 0],
    ]);

    $handle = rzvcCompeting([rzvcCloseStep($control, $fx['cashier'])]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->allocateControlPayment($control->fresh(), $fx['cashier'], $form));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->not->toBeNull()
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rzvcPayments($control, $parent))->toBe(0)
        ->and(rzvcAudits($control))->toBe(1);

    // The parent receivable is collected from the parent's own invoice.
    app(RmePaymentService::class)->pay($parent->fresh(), $fx['cashier'], rmeDeclaredPaymentForm($parent->fresh(), [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$parent->id => 0, $control->id => 0],
    ]));
    expect($parent->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('I: a closure waiting while the Kasir\'s working branch is switched away (daily authority) is refused on the re-read scope', function () {
    $fx = rzvcFixture();
    Role::findOrCreate('Kasir', 'web');
    $kasir = userWith(['manage_rme_billing', 'view_clinic_visits']);
    $kasir->assignRole('Kasir');
    app(UserOnlineContextService::class)->startKasirSession($kasir, (int) $fx['branch']->id);
    $elsewhere = Branch::factory()->create(['code' => 'ZD'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);

    $handle = rzvcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $fx['invoice']->id]],
        // The effect of an APPROVED daily branch switch (BranchChangeApprovalService):
        // the daily context is the branch authority (FEATURE-DAILY-BRANCH-CONTEXT-
        // LOCK-1); the online row follows it. Moving only the online row would not
        // move the scope at all — by design.
        ['kind' => 'select', 'sql' => 'UPDATE trx_daily_branch_contexts SET current_branch_id = ? WHERE user_id = ? RETURNING id', 'bindings' => [(int) $elsewhere->id, (int) $kasir->id]],
        ['kind' => 'select', 'sql' => 'UPDATE trx_user_online_contexts SET branch_id = ? WHERE user_id = ? RETURNING id', 'bindings' => [(int) $elsewhere->id, (int) $kasir->id]],
    ]);

    test()->actingAs($kasir);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($fx['invoice']->fresh(), $kasir));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->not->toBeNull()
        ->and($fx['invoice']->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(rzvcAudits($fx['invoice']))->toBe(0);
});

it('J: a mixed Kontrol batch (Rp0 Kontrol + paid treatment) and a direct parent payment never double-collect the parent', function () {
    $fx = rzvcFixture();
    $parentVisit = rzvcVisit($fx);
    $parent = rzvcInvoice($fx, $parentVisit, 500000);
    $control = rzvcMixedInvoice($fx, rzvcVisit($fx, $parentVisit), 250000);
    $batch = rmeDeclaredPaymentForm($control, [
        'amount' => 750000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$control->id => 0, $parent->id => 0],
    ]);
    $direct = rmeDeclaredPaymentForm($parent, [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$parent->id => 0, $control->id => 0],
    ]);

    $handle = rzvcCompeting([[
        'kind' => 'pay_control', 'invoice' => (int) $control->id, 'actor' => (int) $fx['cashier']->id, 'form' => $batch,
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->pay($parent->fresh(), $fx['cashier'], $direct));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->not->toBeNull()
        ->and(rzvcPayments($parent))->toBe(1)
        ->and(rzvcPayments($control))->toBe(1)
        ->and((float) DB::table('trx_rme_payments')->where('rme_invoice_id', $parent->id)->sum('amount'))->toEqual(500000.0)
        ->and($parent->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_PAID);
});

it('K: a parent receivable payment and the Kontrol Rp0 closure run together without a deadlock, each touching only its own invoice', function () {
    $fx = rzvcFixture();
    $parentVisit = rzvcVisit($fx);
    $parent = rzvcInvoice($fx, $parentVisit, 500000);
    $control = rzvcInvoice($fx, rzvcVisit($fx, $parentVisit), 0);
    $form = rmeDeclaredPaymentForm($parent, [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$parent->id => 0, $control->id => 0],
    ]);

    $handle = rzvcCompeting([[
        'kind' => 'pay', 'invoice' => (int) $parent->id, 'actor' => (int) $fx['cashier']->id, 'form' => $form,
    ]]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($control->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['refusal'])->toBeNull()
        ->and($parent->fresh()->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and(rzvcPayments($parent))->toBe(1)
        ->and(rzvcPayments($control))->toBe(0)
        ->and(rzvcAudits($control))->toBe(1);
});

it('L: a patient-merge ownership re-point (the merge\'s row writes) waiting ahead of the closure leaves one consistent NO_CHARGE on the canonical patient', function () {
    // Simulates PatientMergeExecutionService's registry-driven UPDATE of patient_id
    // on visits and invoices, in one transaction holding those rows — not the whole
    // merge workflow (cases, approvals), which is out of the closure's lock path.
    $fx = rzvcFixture();
    $parentVisit = rzvcVisit($fx);
    $control = rzvcInvoice($fx, rzvcVisit($fx, $parentVisit), 0);
    $canonical = Patient::factory()->create(['branch_id' => $fx['branch']->id]);
    $old = (int) $fx['patient']->id;

    $handle = rzvcCompeting([
        ['kind' => 'select', 'sql' => 'UPDATE trx_rme_invoices SET patient_id = ? WHERE patient_id = ? RETURNING id', 'bindings' => [(int) $canonical->id, $old]],
        ['kind' => 'select', 'sql' => 'UPDATE trx_clinic_visits SET patient_id = ? WHERE patient_id = ? RETURNING id', 'bindings' => [(int) $canonical->id, $old]],
    ]);

    test()->actingAs($fx['cashier']);
    $race = rzvcRace(fn () => app(RmePaymentService::class)->closeZeroValueInvoice($control->fresh(), $fx['cashier']));
    rzvcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and($control->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and((int) $control->fresh()->patient_id)->toBe((int) $canonical->id)
        ->and(rzvcPayments($control))->toBe(0)
        ->and(rzvcAudits($control))->toBe(1);
});
