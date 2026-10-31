<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — the Owner-reference registry under
| REAL concurrency (PostgreSQL only), plus its database backstops.
|--------------------------------------------------------------------------
|
| THE RACE. Two post-payment submissions on two DIFFERENT invoices that carry one
| Owner reference run at the same moment. The registry key is shared, so the
| second claimant waits for the first claimant's key and then sees who owns it.
|   G1 the first commits   → the second is refused, exactly one correction
|                            carries the reference;
|   G2 the first rolls back → the second wins the reference;
|   G3 the refusal is an application exception, never an aborted transaction
|      (25P02), and the connection keeps working;
|   G4 the registry refuses to run under REPEATABLE READ;
|   G5 the PostgreSQL CHECKs refuse an unfolded key, an unknown scope and a
|      non-positive scope id.
|
| THE PROOF. race_child.php boots the REAL application and runs the REAL
| correction service in its own PostgreSQL session, holding every lock it took
| for `hold` seconds; the parent runs the competing real service meanwhile.
|
| WHY IT SKIPS ELSEWHERE. SQLite compiles lockForUpdate() to nothing, serialises
| writers globally and carries none of the PostgreSQL CHECKs. The skip is stated.
|
| WHY NO RefreshDatabase. A second session cannot see rows inside a transaction
| that is never committed. Every row created is deleted by id watermark.
|
| Run against PostgreSQL 16 (the production major):
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=… DB_DATABASE=… \
|   DB_USERNAME=postgres DB_PASSWORD=… \
|   php artisan test tests/Feature/RmeOwnerReferenceRegistry/RmeOwnerReferenceRegistryConcurrencyTest.php
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
use App\Modules\RmeInvoice\Services\RmeOwnerApprovalReferenceRegistry;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../RmeInvoiceCorrection/postpayment-helpers.php';

const RORC_WRITER = 'rorc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RORC_TABLES = [
    // FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1: refund rows reference the
    // obligation, invoice and users with RESTRICT, so they go first.
    'trx_rme_refund_request_events',
    'trx_rme_refund_patient_references',
    'trx_rme_refund_requests',
    'trx_rme_owner_approval_references',
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
            'Row-lock waits on a unique key, READ COMMITTED visibility and the PostgreSQL CHECKs are only observable on PostgreSQL.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RORC_WRITER, $base);
    DB::purge(RORC_WRITER);
    config()->set('database.default', RORC_WRITER);

    seedAccessControl();
    ricFlag(true);
    rppFlag(true);

    $this->watermarks = [];
    foreach (RORC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RORC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RORC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    $writer->table('model_has_permissions')
        ->where('model_type', User::class)
        ->where('model_id', '>', $this->watermarks['users'])
        ->delete();

    foreach (RORC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RORC_WRITER);
});

/**
 * One pilot branch with a designated maker / checker and a cashier, like
 * production designates 29 / 1.
 *
 * @return array{branch: Branch, clinic: Clinic, doctor: Doctor, maker: User, checker: User, cashier: User}
 */
function rorcFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RR'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        ricPilot([(string) $branch->code]);

        $maker = userWith(['request_rme_invoice_correction']);
        $checker = userWith(['approve_rme_invoice_correction']);
        ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

        return [
            'branch' => $branch,
            'clinic' => Clinic::factory()->create(),
            'doctor' => Doctor::factory()->create(['branch_id' => $branch->id]),
            'maker' => $maker,
            'checker' => $checker,
            'cashier' => userWith(['manage_rme_billing', 'view_clinic_visits']),
        ];
    });
}

/** A committed 500.000 invoice of the fixture branch that has received $paid through the REAL payment service. */
function rorcPaidInvoice(array $fx, int $paid): RmeInvoice
{
    $invoice = Model::withoutEvents(function () use ($fx) {
        $patient = Patient::factory()->create(['branch_id' => $fx['branch']->id]);
        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $fx['branch']->id,
            'clinic_id' => $fx['clinic']->id,
            'patient_id' => $patient->id,
            'doctor_id' => $fx['doctor']->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-RR-'.uniqid(),
        ]);
        $record = MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => $fx['branch']->id,
            'patient_id' => $patient->id, 'doctor_id' => $fx['doctor']->id,
        ]);

        $invoice = RmeInvoice::factory()->unpaid()->create([
            'branch_id' => $fx['branch']->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $patient->id,
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

    app(RmePaymentService::class)->pay($invoice, $fx['cashier'], rmeDeclaredPaymentForm($invoice, [
        'amount' => $paid,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => (int) ($invoice->correction_version ?? 0)],
    ]));

    return $invoice->fresh();
}

/** A downward correction (Tambal 200.000 → 50.000) carrying $reference. */
function rorcData(RmeInvoice $invoice, string $reference): array
{
    $items = $invoice->items()->orderBy('id')->get();

    return [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'owner_approval_reference' => $reference,
        'lines' => [
            ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
            ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
        ],
    ];
}

/**
 * Starts race_child.php and returns once it holds every lock its steps took.
 *
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rorcCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RORC_WRITER);

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
        [PHP_BINARY, __DIR__.'/../RmeInvoiceCorrection/race_child.php', $payload],
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
function rorcJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

function rorcPostPaymentCorrectionsWith(string $reference): int
{
    return RmeInvoiceCorrection::query()
        ->where('correction_type', RmeInvoiceCorrectionType::POST_PAYMENT)
        ->whereRaw('lower(owner_approval_reference) = ?', [strtolower($reference)])
        ->count();
}

function rorcOwnerScopeId(string $reference): ?int
{
    $id = DB::table('trx_rme_owner_approval_references')->where('reference_folded', strtolower($reference))->value('scope_id');

    return $id === null ? null : (int) $id;
}

// ─── G1 / G3: the first claimant commits ─────────────────────────────────────

it('G1: refuses the second of two concurrent submissions on two invoices that share one reference', function () {
    $fx = rorcFixture();
    $a = rorcPaidInvoice($fx, 200000);
    $b = rorcPaidInvoice($fx, 200000);
    $reference = 'OWNER-COR-RR-20261031-G1';

    $handle = rorcCompeting([
        ['kind' => 'submit_correction', 'invoice' => $a->id, 'actor' => $fx['maker']->id, 'data' => rorcData($a, $reference)],
    ]);

    $refusal = null;
    $started = microtime(true);
    try {
        // Case variant: the fold, not the spelling, is the key.
        app(RmeInvoiceCorrectionService::class)->submit($b->fresh(), $fx['maker'], rorcData($b, strtolower($reference)));
    } catch (ValidationException $e) {
        $refusal = (string) json_encode($e->errors());
    }
    $waited = microtime(true) - $started;

    rorcJoin($handle);

    // The application check is a plain read and cannot see the competitor's
    // uncommitted correction, so it lets the submission through; the only
    // statement that can make this session wait on the other is the registry
    // INSERT on the shared key. Having waited proves the registry refused it.
    expect($waited)->toBeGreaterThan(1.0)
        ->and($refusal)->toContain('CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED')
        ->and(rorcPostPaymentCorrectionsWith($reference))->toBe(1)
        ->and(RmeInvoiceCorrection::query()->where('rme_invoice_id', $b->id)->count())->toBe(0)
        ->and(rorcOwnerScopeId($reference))->toBe((int) $a->id)
        ->and(DB::table('trx_rme_owner_approval_references')->where('reference_folded', strtolower($reference))->count())->toBe(1);
});

it('G3: the refusal is an application exception and the connection keeps working (no 25P02)', function () {
    $fx = rorcFixture();
    $a = rorcPaidInvoice($fx, 200000);
    $b = rorcPaidInvoice($fx, 200000);
    $reference = 'OWNER-COR-RR-20261031-G3';

    $handle = rorcCompeting([
        ['kind' => 'submit_correction', 'invoice' => $a->id, 'actor' => $fx['maker']->id, 'data' => rorcData($a, $reference)],
    ]);

    $thrown = null;
    try {
        app(RmeInvoiceCorrectionService::class)->submit($b->fresh(), $fx['maker'], rorcData($b, $reference));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    rorcJoin($handle);

    // The code matters: the submit path turns any unique violation into
    // CORRECTION_ALREADY_ACTIVE, so only REUSED shows that ON CONFLICT kept the
    // transaction alive rather than a violation being translated after it died.
    expect($thrown)->toBeInstanceOf(ValidationException::class)
        ->and($thrown)->not->toBeInstanceOf(QueryException::class)
        ->and((string) json_encode($thrown->errors()))->toContain('CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED')
        ->and((string) json_encode($thrown->errors()))->not->toContain('CORRECTION_ALREADY_ACTIVE')
        ->and(DB::transactionLevel())->toBe(0)
        ->and((int) DB::selectOne('SELECT 1 AS one')->one)->toBe(1);

    // The loser's invoice is still correctable with its OWN reference.
    $own = app(RmeInvoiceCorrectionService::class)->submit($b->fresh(), $fx['maker'], rorcData($b, 'OWNER-COR-RR-20261031-G3B'));
    expect(rorcOwnerScopeId('OWNER-COR-RR-20261031-G3B'))->toBe((int) $b->id)
        ->and($own->correction_type)->toBe(RmeInvoiceCorrectionType::POST_PAYMENT);
});

// ─── G2: the first claimant rolls back ───────────────────────────────────────

it('G2: lets the second submission win the reference when the first rolls back', function () {
    $fx = rorcFixture();
    $a = rorcPaidInvoice($fx, 200000);
    $b = rorcPaidInvoice($fx, 200000);
    $reference = 'OWNER-COR-RR-20261031-G2';

    $handle = rorcCompeting([
        ['kind' => 'submit_correction', 'invoice' => $a->id, 'actor' => $fx['maker']->id, 'data' => rorcData($a, $reference)],
    ], 'rollback');

    $correction = app(RmeInvoiceCorrectionService::class)->submit($b->fresh(), $fx['maker'], rorcData($b, $reference));

    rorcJoin($handle);

    expect((int) $correction->rme_invoice_id)->toBe((int) $b->id)
        ->and(rorcPostPaymentCorrectionsWith($reference))->toBe(1)
        ->and(RmeInvoiceCorrection::query()->where('rme_invoice_id', $a->id)->count())->toBe(0)
        ->and(rorcOwnerScopeId($reference))->toBe((int) $b->id);
});

// ─── G4: isolation ───────────────────────────────────────────────────────────

it('G4: refuses to consume under REPEATABLE READ, where the winner could be invisible', function () {
    $fx = rorcFixture();
    $a = rorcPaidInvoice($fx, 200000);

    DB::beginTransaction();
    try {
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        expect(fn () => app(RmeOwnerApprovalReferenceRegistry::class)->consume(
            'OWNER-COR-RR-20261031-G4',
            RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
            (int) $a->id,
            $fx['maker'],
        ))->toThrow(LogicException::class, 'READ COMMITTED');
    } finally {
        DB::rollBack();
    }

    expect(rorcOwnerScopeId('OWNER-COR-RR-20261031-G4'))->toBeNull();
});

// ─── G5: database backstops ──────────────────────────────────────────────────

it('G5: the PostgreSQL CHECKs refuse an unfolded key, an unknown scope and a non-positive scope id', function (array $row) {
    $fx = rorcFixture();

    $state = null;
    try {
        DB::table('trx_rme_owner_approval_references')->insert(array_merge([
            'reference_folded' => 'owner-cor-rr-20261031-g5',
            'scope_type' => RmeOwnerApprovalReferenceRegistry::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
            'scope_id' => 1,
            'first_consumed_by' => $fx['maker']->id,
            'first_consumed_at' => now(),
            'created_at' => now(),
        ], $row));
    } catch (QueryException $e) {
        $state = $e->getCode();
    }

    expect($state)->toBe('23514');
})->with([
    'unfolded key' => [['reference_folded' => 'OWNER-COR-RR-20261031-G5']],
    'unknown scope' => [['scope_type' => 'PAYMENT']],
    'zero scope id' => [['scope_id' => 0]],
]);

it('G5: one folded key, one row — the UNIQUE holds under the CHECKs', function () {
    $fx = rorcFixture();
    $row = [
        'reference_folded' => 'owner-cor-rr-20261031-g5u',
        'scope_type' => RmeOwnerApprovalReferenceRegistry::SCOPE_REFUND_REQUEST,
        'scope_id' => 7,
        'first_consumed_by' => $fx['maker']->id,
        'first_consumed_at' => now(),
        'created_at' => now(),
    ];
    DB::table('trx_rme_owner_approval_references')->insert($row);

    $state = null;
    try {
        DB::table('trx_rme_owner_approval_references')->insert(array_merge($row, ['scope_id' => 8]));
    } catch (QueryException $e) {
        $state = $e->getCode();
    }

    expect($state)->toBe('23505');
});
