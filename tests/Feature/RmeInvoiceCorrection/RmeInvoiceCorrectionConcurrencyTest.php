<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — corrections under REAL
| concurrency (PostgreSQL only).
|--------------------------------------------------------------------------
|
| THE RACES.
|   1. A payment lands while an approved correction is being applied. Without the
|      invoice row lock taken FIRST, apply reads the committed (still UNPAID)
|      invoice, passes its staleness checks, and only then blocks on the row the
|      payment holds — so when the payment commits, the correction would land on
|      a PAID invoice. With the lock first, apply waits, re-reads the paid
|      invoice, and marks the correction STALE.
|   2. Two cashiers submit a correction for the same invoice at the same time.
|      Exactly one may be active.
|
| THE PROOF. A child PHP process plays the competing request: it opens its own
| PostgreSQL session, locks the invoice exactly as the payment / submission path
| does, writes its row WITHOUT committing, signals the parent, sleeps, and only
| then commits. The parent runs the real service meanwhile, so the two genuinely
| interleave — a single PHP process cannot hold one transaction open while
| another blocks on it.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing, so the
| property is unobservable there. The skip is stated, not silently passed.
|
| WHY NO RefreshDatabase. A second session cannot see — or lock — rows inside a
| transaction that is never committed. This suite commits for real and deletes
| every row it created (by id watermark) afterwards. Pattern lifted from
| tests/Feature/RME/RmeBillingRefundPhase0InvoiceRaceConcurrencyTest.php.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55435 DB_DATABASE=ric \
|   DB_USERNAME=ric DB_PASSWORD=ric \
|   php artisan test tests/Feature/RmeInvoiceCorrection/RmeInvoiceCorrectionConcurrencyTest.php
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
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const RIC_WRITER = 'ric_concurrency_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RIC_TABLES = [
    'sys_audit_logs',
    // A successful payment completes the visit, and completion files a
    // SATUSEHAT candidate (rule 191's accepted-payment race proves that path).
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
    'trx_rme_invoice_correction_events',
    'trx_rme_invoice_correction_lines',
    'trx_rme_invoice_document_versions',
    'trx_rme_invoice_corrections',
    'trx_rme_payments',
    'trx_rme_invoice_items',
    'trx_rme_invoices',
    'trx_medical_records',
    'trx_clinic_visits',
    'mst_clinic_rooms',
    'mst_patients',
    'mst_doctors',
    'mst_clinics',
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
    config()->set('database.connections.'.RIC_WRITER, $base);
    DB::purge(RIC_WRITER);
    config()->set('database.default', RIC_WRITER);

    seedAccessControl();
    ricFlag(true);

    $this->watermarks = [];
    foreach (RIC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RIC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RIC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RIC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RIC_WRITER);
});

/**
 * A committed UNPAID 500.000 invoice (two items) plus maker / checker / applier.
 *
 * @return array{invoice: RmeInvoice, maker: User, checker: User, applier: User, cashier: User}
 */
function ricCommittedInvoice(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RC'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
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
            'visit_number' => 'VIS-RC-'.uniqid(),
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
            DB::table('trx_rme_invoice_items')->insert([
                'rme_invoice_id' => $invoice->id, 'description' => $desc, 'qty' => 1,
                'unit_price' => $price, 'discount' => 0, 'subtotal' => $price,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [
            'invoice' => $invoice->fresh(),
            'maker' => userWith(['request_rme_invoice_correction']),
            'checker' => userWith(['approve_rme_invoice_correction']),
            'applier' => userWith(['apply_rme_invoice_correction']),
            'cashier' => $cashier,
        ];
    });
}

/** @return list<array<string, mixed>> */
function ricConcurrentLines(RmeInvoice $invoice): array
{
    $items = $invoice->items()->orderBy('id')->get();

    return [
        ['original_item_id' => $items[0]->id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
        ['original_item_id' => $items[1]->id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
    ];
}

/**
 * Starts the competing request in a child PHP process. It locks the invoice,
 * runs $sql uncommitted, prints LOCKED, sleeps, then commits. Returns once the
 * child holds the lock; the caller must ricJoin() the handle.
 *
 * @param  list<array{0: string, 1: list<mixed>}>  $statements
 * @return array{0: resource, 1: array<int, resource>}
 */
function ricCompetingTransaction(int $invoiceId, array $statements, float $holdSeconds = 2.0): array
{
    $db = config('database.connections.'.RIC_WRITER);
    $payload = base64_encode(json_encode([
        'dsn' => sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['host'], $db['port'], $db['database']),
        'user' => $db['username'],
        'password' => $db['password'],
        'invoice' => $invoiceId,
        'statements' => $statements,
        'hold' => $holdSeconds,
    ]));

    $code = <<<'PHP'
        $c = json_decode(base64_decode($argv[1]), true);
        $pdo = new PDO($c['dsn'], $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE')->execute([$c['invoice']]);
        foreach ($c['statements'] as [$sql, $bindings]) { $pdo->prepare($sql)->execute($bindings); }
        fwrite(STDOUT, "LOCKED\n"); fflush(STDOUT);
        usleep((int) ($c['hold'] * 1000000));
        $pdo->commit();
        fwrite(STDOUT, "COMMITTED\n");
        PHP;

    $process = proc_open([PHP_BINARY, '-r', $code, $payload], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect($process)->toBeResource();

    $line = fgets($pipes[1]);
    if (trim((string) $line) !== 'LOCKED') {
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        test()->fail('Competing transaction did not take its lock: '.trim((string) $line).' '.$err);
    }

    return [$process, $pipes];
}

/** @param array{0: resource, 1: array<int, resource>} $handle */
function ricJoin(array $handle): void
{
    [$process, $pipes] = $handle;
    $rest = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $exit = proc_close($process);
    expect($exit)->toBe(0, 'competing transaction failed: '.$err)
        ->and($rest)->toContain('COMMITTED');
}

it('waits on a payment that is mid-flight, then marks the correction STALE instead of applying it to a paid invoice', function () {
    ['invoice' => $invoice, 'maker' => $maker, 'checker' => $checker, 'applier' => $applier, 'cashier' => $cashier] = ricCommittedInvoice();
    $service = app(RmeInvoiceCorrectionService::class);

    $correction = $service->submit($invoice, $maker, ['reason' => 'Harga tambal salah input kasir.', 'lines' => ricConcurrentLines($invoice)]);
    $correction = $service->approve($correction, $checker, null);

    // The competing payment: lock the invoice exactly as RmePaymentService does,
    // record a payment and move the invoice to PARTIAL — uncommitted for 2 s.
    $handle = ricCompetingTransaction((int) $invoice->id, [
        ['INSERT INTO trx_rme_payments (branch_id, rme_invoice_id, clinic_visit_id, patient_id, cashier_id, payment_number, amount, paid_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
            [$invoice->branch_id, $invoice->id, $invoice->clinic_visit_id, $invoice->patient_id, $cashier->id, 'PAY-RACE-'.uniqid(), 100000]],
        ["UPDATE trx_rme_invoices SET status = 'PARTIAL', updated_at = NOW() WHERE id = ?", [$invoice->id]],
    ]);

    $started = microtime(true);
    $refusal = null;
    try {
        $service->apply($correction, $applier);
    } catch (ValidationException $e) {
        $refusal = json_encode($e->errors());
    }
    $waited = microtime(true) - $started;
    ricJoin($handle);

    // The apply genuinely waited for the payment to commit…
    expect($waited)->toBeGreaterThan(1.0)
        // …then saw the paid invoice and refused, persisting STALE.
        ->and($refusal)->toContain('CORRECTION_STALE')
        ->and($correction->fresh()->status)->toBe(RmeInvoiceCorrectionStatus::STALE);

    $invoice = $invoice->fresh(['items']);
    expect((float) $invoice->grand_total)->toEqual(500000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PARTIAL)
        ->and($invoice->items->sortBy('id')->pluck('subtotal')->map(fn ($v) => (float) $v)->values()->all())->toBe([300000.0, 200000.0])
        ->and(DB::table('trx_rme_invoice_document_versions')->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('lets exactly one of two simultaneous submissions for one invoice become active', function () {
    ['invoice' => $invoice, 'maker' => $maker] = ricCommittedInvoice();
    $service = app(RmeInvoiceCorrectionService::class);
    $other = userWith(['request_rme_invoice_correction']);

    // Request A holds the invoice lock and has inserted its SUBMITTED correction,
    // uncommitted. Its row is built from a committed template, then re-inserted.
    $template = $service->submit($invoice, $maker, ['reason' => 'Template untuk baris pesaing.', 'lines' => ricConcurrentLines($invoice)]);
    $service->cancel($template, $maker, 'Template dibatalkan sebelum balapan.');
    $row = (array) DB::table('trx_rme_invoice_corrections')->where('id', $template->id)->first();
    unset($row['id']);
    $row['uuid'] = (string) Str::uuid();
    $row['correction_number'] = null;
    $row['status'] = RmeInvoiceCorrectionStatus::SUBMITTED;
    $row['cancelled_by'] = null;
    $row['cancelled_at'] = null;
    $row['cancel_reason'] = null;
    $row['idempotency_key'] = null;
    $columns = array_keys($row);

    $handle = ricCompetingTransaction((int) $invoice->id, [
        ['INSERT INTO trx_rme_invoice_corrections ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
            array_values($row)],
    ]);

    $refusal = null;
    try {
        $service->submit($invoice, $other, ['reason' => 'Pengajuan kedua bersamaan.', 'lines' => ricConcurrentLines($invoice)]);
    } catch (ValidationException $e) {
        $refusal = json_encode($e->errors());
    }
    ricJoin($handle);

    expect($refusal)->toContain('CORRECTION_ALREADY_ACTIVE')
        ->and(RmeInvoiceCorrection::where('rme_invoice_id', $invoice->id)->whereIn('status', RmeInvoiceCorrectionStatus::ACTIVE)->count())->toBe(1);
});

it('rejects a second active correction at the database even when the service checks are bypassed', function () {
    ['invoice' => $invoice, 'maker' => $maker] = ricCommittedInvoice();
    $service = app(RmeInvoiceCorrectionService::class);
    $first = $service->submit($invoice, $maker, ['reason' => 'Pengajuan pertama untuk indeks.', 'lines' => ricConcurrentLines($invoice)]);

    $row = (array) DB::table('trx_rme_invoice_corrections')->where('id', $first->id)->first();
    unset($row['id']);
    $row['uuid'] = (string) Str::uuid();
    $row['correction_number'] = null;
    $row['idempotency_key'] = null;

    $violated = false;
    try {
        DB::transaction(fn () => DB::table('trx_rme_invoice_corrections')->insert($row));
    } catch (QueryException $e) {
        $violated = $e->getCode() === '23505';
    }

    expect($violated)->toBeTrue()
        ->and(RmeInvoiceCorrection::where('rme_invoice_id', $invoice->id)->count())->toBe(1);
});

/*
| ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-1 (rule 191) — the OTHER commit
| order. The test above proves "payment first ⇒ correction STALE". These two
| prove "correction first ⇒ a payment from a page opened before it is refused,
| and a payment from a page reloaded after it is accepted against the corrected
| total". The competing transaction writes exactly what an applied correction
| writes to the invoice row (totals + `correction_version` bump) and holds it.
*/

/** @return list<array{0: string, 1: list<mixed>}> */
function ricAppliedCorrectionWrite(RmeInvoice $invoice): array
{
    return [
        ['UPDATE trx_rme_invoices SET subtotal = 350000, grand_total = 350000, correction_version = correction_version + 1, updated_at = NOW() WHERE id = ?', [$invoice->id]],
    ];
}

it('waits on a correction that is mid-apply, then refuses a payment from a page opened before it', function () {
    ['invoice' => $invoice, 'cashier' => $cashier] = ricCommittedInvoice();
    $this->actingAs($cashier);

    $handle = ricCompetingTransaction((int) $invoice->id, ricAppliedCorrectionWrite($invoice));

    $started = microtime(true);
    $refusal = null;
    try {
        app(RmePaymentService::class)->pay($invoice, $cashier, [
            'amount' => 100000,
            'paid_at' => now()->format('Y-m-d H:i:s'),
            'invoice_versions' => [$invoice->id => 0],
        ]);
    } catch (ValidationException $e) {
        $refusal = json_encode($e->errors());
    }
    $waited = microtime(true) - $started;
    ricJoin($handle);

    // The payment genuinely waited for the correction to commit…
    expect($waited)->toBeGreaterThan(1.0)
        // …then read the NEW version under the lock and refused the stale form.
        ->and($refusal)->toContain('INVOICE_VERSION_CHANGED')
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and(DB::table('sys_audit_logs')
            ->where('action', RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_INVOICE_VERSION)
            ->where('entity_id', $invoice->id)->count())->toBe(1);
});

it('waits on a correction that is mid-apply, then accepts a payment from a page reloaded after it', function () {
    ['invoice' => $invoice, 'cashier' => $cashier] = ricCommittedInvoice();
    $this->actingAs($cashier);

    $handle = ricCompetingTransaction((int) $invoice->id, ricAppliedCorrectionWrite($invoice));

    $started = microtime(true);
    app(RmePaymentService::class)->pay($invoice, $cashier, [
        'amount' => 350000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => 1],
    ]);
    $waited = microtime(true) - $started;
    ricJoin($handle);

    $invoice = $invoice->fresh();
    expect($waited)->toBeGreaterThan(1.0)
        ->and((float) $invoice->grand_total)->toEqual(350000.0)
        ->and($invoice->status)->toBe(RmeInvoice::STATUS_PAID)
        ->and((float) DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->sum('amount'))->toEqual(350000.0);
});
