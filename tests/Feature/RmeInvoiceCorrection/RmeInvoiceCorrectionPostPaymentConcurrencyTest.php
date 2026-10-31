<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — post-payment corrections under
| REAL concurrency (PostgreSQL only), plus the database backstops.
|--------------------------------------------------------------------------
|
| THE RACES (rule 206 R1–R8, the ones that can change money):
|   P1 a payment lands while a post-payment approval is in flight → STALE;
|   P2 two approvals of one correction → exactly one application, one obligation;
|   P3 a rejection and an approval → the approval is refused, nothing applied;
|   P4 a new submission while a downward correction is being applied → refused
|      on the OPEN obligation the application created;
|   P5 a payment while an upward correction is being applied → refused on the
|      version the cashier saw (rule 191), never settling a total nobody showed.
| And the database backstops: one OPEN obligation per invoice, positive amount,
| known status, ledger figures never negative or both positive, a POST_PAYMENT
| row always carrying its approval reference, one receipt per payment.
|
| THE PROOF. race_child.php boots the REAL application and runs the REAL service
| in its own PostgreSQL session, holding every lock it took for `hold` seconds;
| the parent runs the competing real service meanwhile.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing and carries
| none of the PostgreSQL CHECKs / partial indexes. The skip is stated.
|
| WHY NO RefreshDatabase. A second session cannot see rows inside a transaction
| that is never committed. Every row created is deleted by id watermark.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55437 DB_DATABASE=rme_ci \
|   DB_USERNAME=postgres DB_PASSWORD=… \
|   php artisan test tests/Feature/RmeInvoiceCorrection/RmeInvoiceCorrectionPostPaymentConcurrencyTest.php
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
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/postpayment-helpers.php';

const RPPC_WRITER = 'rppc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RPPC_TABLES = [
    'trx_rme_owner_approval_references', // HARDENING-RME-OWNER-REFERENCE-REGISTRY-1: child of users only
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
    'trx_rme_overpayment_obligation_events',
    'trx_rme_overpayment_obligations',
    'trx_rme_receipt_snapshots',
    'trx_rme_invoice_correction_events',
    'trx_rme_invoice_correction_lines',
    'trx_rme_invoice_document_versions',
    'trx_rme_invoice_corrections',
    'trx_rme_payment_submissions',
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
            'Row-lock behaviour and the PostgreSQL CHECKs / partial indexes are only observable on PostgreSQL.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RPPC_WRITER, $base);
    DB::purge(RPPC_WRITER);
    config()->set('database.default', RPPC_WRITER);

    seedAccessControl();
    ricFlag(true);
    rppFlag(true);

    $this->watermarks = [];
    foreach (RPPC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RPPC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RPPC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RPPC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RPPC_WRITER);
});

/**
 * A committed 500.000 invoice that has received $paid through the REAL payment
 * service (so it carries an issued receipt snapshot), plus maker / checker /
 * cashier designated like production designates 29 / 1.
 *
 * @return array{invoice: RmeInvoice, maker: User, checker: User, cashier: User}
 */
function rppcPaidInvoice(int $paid): array
{
    $fx = Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RP'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
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
            'visit_number' => 'VIS-RP-'.uniqid(),
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

    app(RmePaymentService::class)->pay($fx['invoice'], $fx['cashier'], rppcPayForm($fx['invoice'], $paid));
    $fx['invoice'] = $fx['invoice']->fresh();

    return $fx;
}

function rppcPayForm(RmeInvoice $invoice, int $amount): array
{
    return rmeDeclaredPaymentForm($invoice, [
        'amount' => $amount,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => (int) ($invoice->correction_version ?? 0)],
    ]);
}

/** @return list<array<string, mixed>> */
function rppcLines(RmeInvoice $invoice, string $scaling, string $tambal): array
{
    $items = $invoice->items()->orderBy('id')->get();

    return [
        ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => $scaling, 'discount' => '0'],
        ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => $tambal, 'discount' => '0'],
    ];
}

function rppcSubmit(array $fx, string $scaling = '300000', string $tambal = '50000', string $suffix = '01'): RmeInvoiceCorrection
{
    return app(RmeInvoiceCorrectionService::class)->submit($fx['invoice']->fresh(), $fx['maker'], [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'lines' => rppcLines($fx['invoice'], $scaling, $tambal),
        'owner_approval_reference' => rppReference($suffix),
    ]);
}

/**
 * Starts race_child.php and returns once it holds every lock its steps took.
 *
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rppcCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RPPC_WRITER);

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
function rppcJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

function rppcRefusal(callable $call): ?string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return (string) json_encode($e->errors());
    }

    return null;
}

/** Expect the statement to be refused by the database itself. */
function rppcRefusedByDatabase(string $sql, array $bindings): void
{
    $refused = false;
    try {
        DB::connection(RPPC_WRITER)->statement($sql, $bindings);
    } catch (QueryException) {
        $refused = true;
    }

    expect($refused)->toBeTrue('the database accepted: '.$sql);
}

// ─── The races ──────────────────────────────────────────────────────────────

it('P1 — waits on a payment that is mid-flight, then marks the post-payment correction STALE', function () {
    $fx = rppcPaidInvoice(200000);
    $correction = rppcSubmit($fx);
    $invoice = $fx['invoice'];

    // A payment written past D10 (defence in depth): lock the invoice exactly as
    // the payment path does, insert, keep the transaction open.
    $handle = rppcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [(int) $invoice->id]],
        ['kind' => 'statement', 'sql' => 'INSERT INTO trx_rme_payments (branch_id, rme_invoice_id, clinic_visit_id, patient_id, cashier_id, payment_number, amount, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
            'bindings' => [$invoice->branch_id, $invoice->id, $invoice->clinic_visit_id, $invoice->patient_id, $fx['cashier']->id, 'PAY-RPPC-'.uniqid(), 50000]],
    ]);

    $started = microtime(true);
    $refusal = rppcRefusal(fn () => app(RmeInvoiceCorrectionService::class)->approve($correction, $fx['checker'], null));
    $waited = microtime(true) - $started;
    rppcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('CORRECTION_STALE:PAYMENT_RECORDED')
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE)
        ->and((float) $invoice->fresh()->grand_total)->toEqual(500000.0)
        ->and(DB::table('trx_rme_overpayment_obligations')->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('P2 — applies a downward correction exactly once when two approvals race, with one obligation', function () {
    $fx = rppcPaidInvoice(500000);
    $correction = rppcSubmit($fx);
    $other = userWith(['approve_rme_invoice_correction']);
    ricDesignateChecker($other);

    $handle = rppcCompeting([
        ['kind' => 'approve_correction', 'correction' => (int) $correction->id, 'actor' => (int) $fx['checker']->id],
    ]);

    $started = microtime(true);
    $result = app(RmeInvoiceCorrectionService::class)->approve($correction, $other, null);
    $waited = microtime(true) - $started;
    rppcJoin($handle);

    $invoice = $fx['invoice']->fresh();
    expect($waited)->toBeGreaterThan(1.0)
        ->and($result->status)->toBe(RmeInvoiceCorrectionStatus::APPLIED)
        ->and((int) $result->decided_by)->toBe((int) $fx['checker']->id)
        ->and((int) $invoice->correction_version)->toBe(1)
        ->and((float) $invoice->grand_total)->toEqual(350000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and(DB::table('trx_rme_overpayment_obligations')->where('rme_invoice_id', $invoice->id)->count())->toBe(1)
        ->and((float) DB::table('trx_rme_overpayment_obligations')->where('rme_invoice_id', $invoice->id)->value('amount'))->toEqual(150000.0)
        ->and(DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->count())->toBe(2)
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

it('P3 — refuses an approval that loses to a rejection, applying nothing', function () {
    $fx = rppcPaidInvoice(500000);
    $correction = rppcSubmit($fx);
    $rejecter = userWith(['approve_rme_invoice_correction']);
    ricDesignateChecker($rejecter);

    $handle = rppcCompeting([
        ['kind' => 'reject_correction', 'correction' => (int) $correction->id, 'actor' => (int) $rejecter->id, 'note' => 'Owner tidak menyetujui kasus ini.'],
    ]);

    $refusal = rppcRefusal(fn () => app(RmeInvoiceCorrectionService::class)->approve($correction, $fx['checker'], null));
    rppcJoin($handle);

    $invoice = $fx['invoice']->fresh();
    expect($refusal)->not->toBeNull()
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::REJECTED)
        ->and((int) $invoice->correction_version)->toBe(0)
        ->and((float) $invoice->grand_total)->toEqual(500000.0)
        ->and(DB::table('trx_rme_overpayment_obligations')->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('P4 — refuses a new submission that waited on a downward application, on the obligation it opened', function () {
    $fx = rppcPaidInvoice(500000);
    $correction = rppcSubmit($fx);

    $handle = rppcCompeting([
        ['kind' => 'approve_correction', 'correction' => (int) $correction->id, 'actor' => (int) $fx['checker']->id],
    ]);

    $started = microtime(true);
    $refusal = rppcRefusal(fn () => rppcSubmit($fx, '250000', '50000', '02'));
    $waited = microtime(true) - $started;
    rppcJoin($handle);

    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('CORRECTION_OVERPAYMENT_OBLIGATION_OPEN')
        ->and(RmeInvoiceCorrection::query()->where('rme_invoice_id', $fx['invoice']->id)->count())->toBe(1);
});

it('P5 — refuses a payment that waited on an upward application, on the version the cashier saw', function () {
    $fx = rppcPaidInvoice(500000);
    $correction = rppcSubmit($fx, '400000', '200000'); // 600.000 → PARTIAL, 100.000 due
    $form = rppcPayForm($fx['invoice'], 100000); // the page rendered BEFORE the correction (version 0)

    $handle = rppcCompeting([
        ['kind' => 'approve_correction', 'correction' => (int) $correction->id, 'actor' => (int) $fx['checker']->id],
    ]);

    $refusal = rppcRefusal(fn () => app(RmePaymentService::class)->pay($fx['invoice']->fresh(), $fx['cashier'], $form));
    rppcJoin($handle);

    $invoice = $fx['invoice']->fresh();
    expect($refusal)->not->toBeNull()
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and((int) $invoice->correction_version)->toBe(1)
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

// ─── Database backstops ─────────────────────────────────────────────────────

it('holds the obligation invariants in the database itself', function () {
    $fx = rppcPaidInvoice(500000);
    app(RmeInvoiceCorrectionService::class)->approve(rppcSubmit($fx), $fx['checker'], null);
    $obligation = (array) DB::table('trx_rme_overpayment_obligations')->where('rme_invoice_id', $fx['invoice']->id)->first();

    $insert = 'INSERT INTO trx_rme_overpayment_obligations (uuid, branch_id, rme_invoice_id, clinic_visit_id, patient_id, source_correction_id, amount, invoice_version, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
    $row = fn (string $amount, string $status, int $correctionId) => [
        (string) Str::uuid(), $obligation['branch_id'], $obligation['rme_invoice_id'], $obligation['clinic_visit_id'],
        $obligation['patient_id'], $correctionId, $amount, 1, $status, $obligation['created_by'],
    ];

    // One obligation per correction, and at most one OPEN per invoice.
    rppcRefusedByDatabase($insert, $row('10.00', 'OPEN', (int) $obligation['source_correction_id']));
    // Positive amount and a known status (a free correction id is not needed: CHECKs fire first).
    rppcRefusedByDatabase($insert, $row('0.00', 'OPEN', (int) $obligation['source_correction_id']));
    rppcRefusedByDatabase($insert, $row('10.00', 'SETTLED', (int) $obligation['source_correction_id']));
});

it('refuses a POST_PAYMENT correction row without an approval reference, and contradictory ledger figures', function () {
    $fx = rppcPaidInvoice(500000);
    $correction = rppcSubmit($fx);

    rppcRefusedByDatabase('UPDATE trx_rme_invoice_corrections SET owner_approval_reference = NULL WHERE id = ?', [$correction->id]);
    rppcRefusedByDatabase("UPDATE trx_rme_invoice_corrections SET owner_approval_reference = '   ' WHERE id = ?", [$correction->id]);
    rppcRefusedByDatabase('UPDATE trx_rme_invoice_corrections SET outstanding_after = 10, overpaid_after = 10 WHERE id = ?', [$correction->id]);
    rppcRefusedByDatabase('UPDATE trx_rme_invoice_corrections SET overpaid_after = -1 WHERE id = ?', [$correction->id]);

    expect($correction->fresh()->correction_type)->toBe(RmeInvoiceCorrectionType::POST_PAYMENT);
});

it('refuses a second receipt for one payment and an unknown receipt basis in the database itself', function () {
    $fx = rppcPaidInvoice(500000);
    $receipt = (array) DB::table('trx_rme_receipt_snapshots')->where('rme_invoice_id', $fx['invoice']->id)->first();

    $insert = 'INSERT INTO trx_rme_receipt_snapshots (uuid, rme_payment_id, rme_invoice_id, branch_id, receipt_number, invoice_version, invoice_status, capture_basis, snapshot_schema_version, snapshot, snapshot_sha256, issued_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, NOW(), NOW(), NOW())';
    $row = fn (string $basis, string $status) => [
        (string) Str::uuid(), $receipt['rme_payment_id'], $receipt['rme_invoice_id'], $receipt['branch_id'], 'X', 0, $status, $basis, 1, '{}', str_repeat('a', 64),
    ];

    rppcRefusedByDatabase($insert, $row('ISSUED_AT_PAYMENT', 'PAID')); // duplicate payment id
    rppcRefusedByDatabase($insert, $row('MADE_UP', 'PAID'));
    rppcRefusedByDatabase($insert, $row('ISSUED_AT_PAYMENT', 'UNPAID'));
});
