<?php

/*
| FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 — PostgreSQL 16 only (TDD matrix §3).
|
| The REAL refund service runs in a second PostgreSQL session
| (`tests/Feature/RmeInvoiceCorrection/race_child.php`), holding every lock it took
| for `hold` seconds, while the parent runs the competing real service. SQLite
| compiles lockForUpdate() to nothing and carries none of the CHECKs, so these
| properties are only observable here.
|
| No RefreshDatabase: the child cannot see the parent's uncommitted fixtures, so
| fixtures are COMMITTED and cleaned up by id watermark afterwards.
|
| Cases: RR-CONC-01..06, RR-REF-08 (race half), RR-REF-11, RR-REF-12, RR-PAT-06,
| and the database backstops RR-SOD-04, RR-AMT-05, RR-REF-03, RR-REF-05, RR-REF-13,
| RR-PAT-07. RR-REF-14 (REPEATABLE READ) is the registry's own G4 in
| RmeOwnerReferenceRegistryConcurrencyTest.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Models\RmeRefundRequestEvent;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus as S;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const RRC_WRITER = 'rrc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RRC_TABLES = [
    'trx_rme_refund_request_events',
    'trx_rme_refund_patient_references',
    'trx_rme_refund_requests',
    'trx_rme_owner_approval_references',
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
    'trx_rme_billing_dispute_events',
    'trx_rme_billing_disputes',
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
    'trx_daily_branch_contexts',
    'trx_user_online_contexts',
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
        $this->markTestSkipped('Row-lock waits, READ COMMITTED visibility and the PostgreSQL CHECKs are only observable on PostgreSQL.');
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RRC_WRITER, $base);
    DB::purge(RRC_WRITER);
    config()->set('database.default', RRC_WRITER);

    seedAccessControl();
    ricFlag(true);
    rppFlag(true);
    rrrFlag(true);

    $this->watermarks = [];
    foreach (RRC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(RRC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RRC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    foreach (['model_has_permissions', 'model_has_roles'] as $pivot) {
        $writer->table($pivot)->where('model_type', User::class)->where('model_id', '>', $this->watermarks['users'])->delete();
    }

    foreach (RRC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RRC_WRITER);
});

/**
 * One pilot branch, the correction pair that produces the obligation, a cashier,
 * and the refund actors of RF-1: a Front Office maker with a working context, a
 * Supervisor RME and a Kepala Cabang of the branch.
 *
 * @return array<string, mixed>
 */
function rrcFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RF'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        ricPilot([(string) $branch->code]);
        rrrPilot([(string) $branch->code]);

        $maker = userWith(['request_rme_invoice_correction']);
        $checker = userWith(['approve_rme_invoice_correction']);
        ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

        $frontOffice = userInRole('Front Office');
        $supervisor = userInRole('Supervisor RME');
        $head = userInRole('Kepala Cabang');
        $head->forceFill(['branch_id' => $branch->id])->save();
        rrrIdentities([(int) $frontOffice->id], [(int) $supervisor->id, (int) $head->id]);

        return [
            'branch' => $branch,
            'clinic' => Clinic::factory()->create(),
            'doctor' => Doctor::factory()->create(['branch_id' => $branch->id]),
            'maker' => $maker,
            'checker' => $checker,
            'cashier' => userWith(['manage_rme_billing', 'view_clinic_visits']),
            'frontOffice' => $frontOffice,
            'supervisor' => $supervisor,
            'head' => $head,
        ];
    });
}

/** The Front Office working context, committed so the child session sees it. */
function rrcContext(array $fx): void
{
    rmeMakeFrontOfficeActive($fx['frontOffice'], $fx['branch']);
}

/**
 * A committed PAID 500.000 invoice corrected down to 350.000 through the REAL
 * services: one OPEN obligation of 150.000.
 *
 * @return array{0: RmeInvoice, 1: RmeOverpaymentObligation}
 */
function rrcObligation(array $fx, string $reference): array
{
    $invoice = Model::withoutEvents(function () use ($fx) {
        $patient = Patient::factory()->create(['branch_id' => $fx['branch']->id]);
        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $fx['branch']->id, 'clinic_id' => $fx['clinic']->id, 'patient_id' => $patient->id,
            'doctor_id' => $fx['doctor']->id, 'visit_type' => ClinicVisit::VISIT_TYPE_NEW, 'visit_number' => 'VIS-RF-'.uniqid(),
        ]);
        $record = MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => $fx['branch']->id, 'patient_id' => $patient->id, 'doctor_id' => $fx['doctor']->id,
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
                'unit_price' => $price, 'discount' => 0, 'subtotal' => $price, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $invoice->fresh();
    });

    app(RmePaymentService::class)->pay($invoice, $fx['cashier'], rmeDeclaredPaymentForm($invoice, [
        'amount' => 500000,
        'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => (int) ($invoice->correction_version ?? 0)],
    ]));

    $items = $invoice->fresh()->items()->orderBy('id')->get();
    $correction = app(RmeInvoiceCorrectionService::class)->submit($invoice->fresh(), $fx['maker'], [
        'reason' => 'Harga tambal salah input oleh kasir.',
        'owner_approval_reference' => $reference,
        'lines' => [
            ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
            ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
        ],
    ]);
    app(RmeInvoiceCorrectionService::class)->approve($correction->fresh(), $fx['checker'], null);

    $invoice = $invoice->fresh();

    return [$invoice, RmeOverpaymentObligation::query()->where('rme_invoice_id', $invoice->id)->firstOrFail()];
}

/** @return array<string, mixed> */
function rrcData(RmeInvoice $invoice, RmeOverpaymentObligation $obligation, string $owner, string $patient): array
{
    return [
        RmeRefundRequestIntent::FIELD => RmeRefundRequestIntent::issue((int) $obligation->id),
        'obligation_id' => (int) $obligation->id,
        'obligation_amount' => RmeRefundRequestSnapshot::decimal(RmeRefundRequestSnapshot::cents($obligation->amount)),
        'invoice_version' => (int) ($invoice->fresh()->correction_version ?? 0),
        'reason_note' => 'Pasien meminta pengembalian kelebihan bayar.',
        'patient_request_reference' => $patient,
        'owner_approval_reference' => $owner,
    ];
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rrcCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RRC_WRITER);
    $payload = base64_encode((string) json_encode([
        'steps' => $steps,
        'hold' => $hold,
        'finish' => $finish,
        'config' => [
            'feature_flags.flags' => config('feature_flags.flags'),
            'rme_invoice_correction.pilot.branch_codes' => config('rme_invoice_correction.pilot.branch_codes'),
            'rme_invoice_correction.pilot.identities' => config('rme_invoice_correction.pilot.identities'),
            'rme_refund.pilot.branch_codes' => config('rme_refund.pilot.branch_codes'),
            'rme_refund.pilot.identities' => config('rme_refund.pilot.identities'),
        ],
    ]));

    $env = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
        'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
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

function rrcJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

/** Runs $work, returns [refusal code or null, seconds waited]; the connection must stay usable. */
function rrcAttempt(callable $work): array
{
    $started = microtime(true);
    $code = null;
    try {
        $work();
    } catch (R $refused) {
        $code = $refused->reasonCode;
    } catch (ValidationException $e) {
        $code = (string) json_encode($e->errors());
    }

    expect(DB::transactionLevel())->toBe(0)
        ->and((int) DB::selectOne('SELECT 1 AS one')->one)->toBe(1);

    return [$code, microtime(true) - $started];
}

/** SQLSTATE of a statement run inside a savepoint, so the outer state survives. */
function rrcSqlState(callable $statement): ?string
{
    try {
        DB::transaction(fn () => $statement());
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    return null;
}

// ─── RR-CONC ─────────────────────────────────────────────────────────────────

it('RR-CONC-01: two concurrent requests for one obligation — exactly one REQUESTED', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$invoice, $obligation] = rrcObligation($fx, 'OWNER-COR-RF-20261031-C1');

    $handle = rrcCompeting([['kind' => 'submit_refund', 'invoice' => $invoice->id, 'actor' => $fx['frontOffice']->id,
        'data' => rrcData($invoice, $obligation, 'OWNER-REF-RF-C1A', 'FORM-PASIEN-C1A')]]);
    [$code, $waited] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->submit($invoice->fresh(), $fx['frontOffice'], rrcData($invoice, $obligation, 'OWNER-REF-RF-C1B', 'FORM-PASIEN-C1A')));
    rrcJoin($handle);

    expect($code)->toBe(R::ACTIVE_REQUEST_EXISTS)
        ->and($waited)->toBeGreaterThan(1.0)
        ->and(RmeRefundRequest::query()->where('rme_overpayment_obligation_id', $obligation->id)->where('status', S::REQUESTED)->count())->toBe(1);
});

it('RR-CONC-02: two concurrent approvals — exactly one APPROVED, the other ALREADY_DECIDED', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$invoice, $obligation] = rrcObligation($fx, 'OWNER-COR-RF-20261031-C2');
    $request = app(RmeRefundRequestService::class)->submit($invoice->fresh(), $fx['frontOffice'], rrcData($invoice, $obligation, 'OWNER-REF-RF-C2', 'FORM-PASIEN-C2'));

    $handle = rrcCompeting([['kind' => 'approve_refund', 'request' => $request->id, 'actor' => $fx['supervisor']->id]]);
    [$code] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->approve($request->fresh(), $fx['head'], []));
    rrcJoin($handle);

    expect($code)->toBe(R::ALREADY_DECIDED)
        ->and($request->fresh()->status)->toBe(S::APPROVED)
        ->and((int) $request->fresh()->decided_by)->toBe((int) $fx['supervisor']->id)
        ->and(RmeRefundRequestEvent::query()->where('rme_refund_request_id', $request->id)->where('to_status', S::APPROVED)->count())->toBe(1);
});

it('RR-CONC-03: approve against withdraw — one wins, the other is refused', function () {
    $fx = rrcFixture();
    rrcContext($fx);

    // The withdraw wins: the approval finds a CANCELLED request.
    [$invoice, $obligation] = rrcObligation($fx, 'OWNER-COR-RF-20261031-C3A');
    $first = app(RmeRefundRequestService::class)->submit($invoice->fresh(), $fx['frontOffice'], rrcData($invoice, $obligation, 'OWNER-REF-RF-C3A', 'FORM-PASIEN-C3A'));
    $handle = rrcCompeting([['kind' => 'cancel_refund', 'request' => $first->id, 'actor' => $fx['frontOffice']->id, 'reason' => 'Pasien membatalkan permintaannya.']]);
    [$code] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->approve($first->fresh(), $fx['supervisor'], []));
    rrcJoin($handle);
    expect($code)->toBe(R::INVALID_TRANSITION)->and($first->fresh()->status)->toBe(S::CANCELLED);

    // The approval wins: the maker's withdraw now targets an APPROVED request, which
    // only a reviewer other than the maker may revoke (RR-LIFE-06).
    [$invoice2, $obligation2] = rrcObligation($fx, 'OWNER-COR-RF-20261031-C3B');
    $second = app(RmeRefundRequestService::class)->submit($invoice2->fresh(), $fx['frontOffice'], rrcData($invoice2, $obligation2, 'OWNER-REF-RF-C3B', 'FORM-PASIEN-C3B'));
    $handle = rrcCompeting([['kind' => 'approve_refund', 'request' => $second->id, 'actor' => $fx['supervisor']->id]]);
    [$code] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->cancel($second->fresh(), $fx['frontOffice'], ['cancel_reason' => 'Pasien membatalkan permintaannya.']));
    rrcJoin($handle);
    expect($code)->toBe(R::SELF_DECISION_FORBIDDEN)->and($second->fresh()->status)->toBe(S::APPROVED);
});

it('RR-CONC-04: approve against a dispute filed on the invoice — never approved while a dispute is active', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$invoice, $obligation] = rrcObligation($fx, 'OWNER-COR-RF-20261031-C4');
    $request = app(RmeRefundRequestService::class)->submit($invoice->fresh(), $fx['frontOffice'], rrcData($invoice, $obligation, 'OWNER-REF-RF-C4', 'FORM-PASIEN-C4'));

    $handle = rrcCompeting([['kind' => 'report_dispute', 'invoice' => $invoice->id, 'actor' => $fx['cashier']->id,
        'data' => ['reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT, 'description' => 'Pasien mempertanyakan nominal tagihan.']]]);
    [$code] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->approve($request->fresh(), $fx['supervisor'], []));
    rrcJoin($handle);

    expect($code)->toBe(R::INVOICE_DISPUTED)->and($request->fresh()->status)->toBe(S::REQUESTED);
});

it('RR-CONC-05: a request against a payment attempt on the same invoice — payment refused, no deadlock', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$invoice, $obligation] = rrcObligation($fx, 'OWNER-COR-RF-20261031-C5');
    $payments = DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count();

    $handle = rrcCompeting([['kind' => 'submit_refund', 'invoice' => $invoice->id, 'actor' => $fx['frontOffice']->id,
        'data' => rrcData($invoice, $obligation, 'OWNER-REF-RF-C5', 'FORM-PASIEN-C5')]]);
    $thrown = null;
    try {
        app(RmePaymentService::class)->pay($invoice->fresh(), $fx['cashier'], rmeDeclaredPaymentForm($invoice->fresh(), [
            'amount' => 1000, 'paid_at' => now()->format('Y-m-d H:i:s'),
            'invoice_versions' => [$invoice->id => (int) ($invoice->fresh()->correction_version ?? 0)],
        ]));
    } catch (Throwable $e) {
        $thrown = $e;
    }
    rrcJoin($handle);

    expect($thrown)->not->toBeNull()
        ->and($thrown)->not->toBeInstanceOf(QueryException::class)
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe($payments)
        ->and(RmeRefundRequest::query()->where('rme_overpayment_obligation_id', $obligation->id)->where('status', S::REQUESTED)->count())->toBe(1);
});

it('RR-CONC-06: a unique violation on the one-active index is translated at the boundary, never a 25P02 leak', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$invoice, $obligation] = rrcObligation($fx, 'OWNER-COR-RF-20261031-C6');
    $template = app(RmeRefundRequestService::class)->submit($invoice->fresh(), $fx['frontOffice'], rrcData($invoice, $obligation, 'OWNER-REF-RF-C6A', 'FORM-PASIEN-C6A'));
    $row = (array) DB::table('trx_rme_refund_requests')->where('id', $template->id)->first();
    // Withdraw the template so the obligation is free again, then let the child hold
    // an uncommitted ACTIVE row the parent's application check cannot see.
    DB::table('trx_rme_refund_requests')->where('id', $template->id)->update(['status' => S::CANCELLED, 'cancelled_by' => $fx['frontOffice']->id, 'cancelled_at' => now(), 'cancel_reason' => 'Dibatalkan untuk uji balapan.']);
    unset($row['id']);
    $row = array_merge($row, [
        'uuid' => (string) Str::uuid(), 'request_number' => null, 'status' => S::REQUESTED,
        'intent_key' => bin2hex(random_bytes(16)), 'owner_approval_reference' => 'OWNER-REF-RF-C6-CHILD',
        'request_snapshot' => json_encode($row['request_snapshot'] ? json_decode((string) $row['request_snapshot'], true) : []),
    ]);
    $columns = array_keys($row);
    $handle = rrcCompeting([['kind' => 'statement',
        'sql' => 'INSERT INTO trx_rme_refund_requests ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')',
        'bindings' => array_values($row)]]);

    [$code] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->submit($invoice->fresh(), $fx['frontOffice'], rrcData($invoice, $obligation, 'OWNER-REF-RF-C6B', 'FORM-PASIEN-C6A')));
    rrcJoin($handle);

    expect($code)->toBe(R::ACTIVE_REQUEST_EXISTS);
});

// ─── RR-REF / RR-PAT races ───────────────────────────────────────────────────

it('RR-REF-11: two obligations, case variants of one Owner reference — exactly one owns it', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$a, $oa] = rrcObligation($fx, 'OWNER-COR-RF-20261031-R11A');
    [$b, $ob] = rrcObligation($fx, 'OWNER-COR-RF-20261031-R11B');

    $handle = rrcCompeting([['kind' => 'submit_refund', 'invoice' => $a->id, 'actor' => $fx['frontOffice']->id,
        'data' => rrcData($a, $oa, 'OWNER-REF-RF-R11', 'FORM-PASIEN-R11A')]]);
    [$code, $waited] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->submit($b->fresh(), $fx['frontOffice'], rrcData($b, $ob, 'owner-ref-rf-r11', 'FORM-PASIEN-R11B')));
    rrcJoin($handle);

    expect($code)->toBe(R::OWNER_REFERENCE_REUSED)
        ->and($waited)->toBeGreaterThan(1.0)
        ->and(RmeRefundRequest::query()->whereRaw('lower(owner_approval_reference) = ?', ['owner-ref-rf-r11'])->count())->toBe(1)
        ->and(DB::table('trx_rme_owner_approval_references')->where('reference_folded', 'owner-ref-rf-r11')->count())->toBe(1);
});

it('RR-REF-08 (race half): a correction and a refund racing for one reference on two invoices — one wins', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$a, $oa] = rrcObligation($fx, 'OWNER-COR-RF-20261031-R8A');
    // $b now carries an OPEN obligation, so a further correction there is refused
    // by PP-3; a fresh paid invoice is the correction's target instead.

    $handle = rrcCompeting([['kind' => 'submit_refund', 'invoice' => $a->id, 'actor' => $fx['frontOffice']->id,
        'data' => rrcData($a, $oa, 'OWNER-REF-RF-R8', 'FORM-PASIEN-R8')]]);
    [$code] = rrcAttempt(function () use ($fx) {
        [$target] = rrcPaidOnly($fx);
        $items = $target->items()->orderBy('id')->get();
        app(RmeInvoiceCorrectionService::class)->submit($target, $fx['maker'], [
            'reason' => 'Harga tambal salah input oleh kasir.',
            'owner_approval_reference' => 'owner-ref-rf-r8',
            'lines' => [
                ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
                ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
            ],
        ]);
    });
    rrcJoin($handle);

    expect($code)->toContain('CORRECTION_OWNER_APPROVAL_REFERENCE_REUSED')
        ->and(DB::table('trx_rme_owner_approval_references')->where('reference_folded', 'owner-ref-rf-r8')->value('scope_type'))->toBe('REFUND_REQUEST');
});

it('RR-REF-12: a refund submission rolled back after its claims leaves the references free', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$a, $oa] = rrcObligation($fx, 'OWNER-COR-RF-20261031-R12A');
    [$b, $ob] = rrcObligation($fx, 'OWNER-COR-RF-20261031-R12B');

    $handle = rrcCompeting([['kind' => 'submit_refund', 'invoice' => $a->id, 'actor' => $fx['frontOffice']->id,
        'data' => rrcData($a, $oa, 'OWNER-REF-RF-R12', 'FORM-PASIEN-R12')]], finish: 'rollback');
    [$code] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->submit($b->fresh(), $fx['frontOffice'], rrcData($b, $ob, 'OWNER-REF-RF-R12', 'FORM-PASIEN-R12')));
    rrcJoin($handle);

    expect($code)->toBeNull()
        ->and(DB::table('trx_rme_owner_approval_references')->where('reference_folded', 'owner-ref-rf-r12')->value('scope_type'))->toBe('REFUND_REQUEST')
        ->and((int) DB::table('trx_rme_refund_patient_references')->where('reference_folded', 'form-pasien-r12')->value('rme_overpayment_obligation_id'))->toBe((int) $ob->id);
});

it('RR-PAT-06: two obligations, case variants of one patient reference — one owns it, no deadlock', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$a, $oa] = rrcObligation($fx, 'OWNER-COR-RF-20261031-P6A');
    [$b, $ob] = rrcObligation($fx, 'OWNER-COR-RF-20261031-P6B');

    $handle = rrcCompeting([['kind' => 'submit_refund', 'invoice' => $a->id, 'actor' => $fx['frontOffice']->id,
        'data' => rrcData($a, $oa, 'OWNER-REF-RF-P6A', 'FORM-PASIEN-P6')]]);
    [$code, $waited] = rrcAttempt(fn () => app(RmeRefundRequestService::class)->submit($b->fresh(), $fx['frontOffice'], rrcData($b, $ob, 'OWNER-REF-RF-P6B', 'form-pasien-p6')));
    rrcJoin($handle);

    expect($code)->toBe(R::PATIENT_REFERENCE_REUSED)
        ->and($waited)->toBeGreaterThan(1.0)
        ->and((int) DB::table('trx_rme_refund_patient_references')->where('reference_folded', 'form-pasien-p6')->value('rme_overpayment_obligation_id'))->toBe((int) $oa->id)
        ->and(RmeRefundRequest::query()->where('rme_overpayment_obligation_id', $ob->id)->count())->toBe(0);
});

// ─── Database backstops ──────────────────────────────────────────────────────

it('RR-SOD-04 / RR-AMT-05 / RR-REF-05 / RR-PAT-07: the PostgreSQL CHECKs refuse what the service never writes', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$invoice, $obligation] = rrcObligation($fx, 'OWNER-COR-RF-20261031-DB');
    $request = app(RmeRefundRequestService::class)->submit($invoice->fresh(), $fx['frontOffice'], rrcData($invoice, $obligation, 'OWNER-REF-RF-DB', 'FORM-PASIEN-DB'));
    $update = fn (array $values) => rrcSqlState(fn () => DB::table('trx_rme_refund_requests')->where('id', $request->id)->update($values));

    expect($update(['decided_by' => $request->requested_by, 'decided_at' => now(), 'status' => S::APPROVED]))->toBe('23514')   // maker = checker
        ->and($update(['amount' => 1]))->toBe('23514')                        // amount ≠ snapshot
        ->and($update(['amount' => 0, 'obligation_amount_snapshot' => 0]))->toBe('23514') // amount ≤ 0
        ->and($update(['owner_approval_reference' => null]))->toBe('23502')   // NOT NULL
        ->and($update(['owner_approval_reference' => 'AB12']))->toBe('23514') // < 6
        ->and($update(['patient_request_reference' => null]))->toBe('23502')
        ->and($update(['patient_request_reference' => 'AB12']))->toBe('23514')
        ->and($update(['status' => 'SETTLED']))->toBe('23514')
        ->and($update(['status' => S::REJECTED, 'decided_by' => $fx['supervisor']->id, 'decided_at' => now()]))->toBe('23514') // REJECTED without a note
        ->and(rrcSqlState(fn () => DB::table('trx_rme_refund_patient_references')->insert([
            'reference_folded' => 'FORM-UNFOLDED', 'rme_overpayment_obligation_id' => $obligation->id,
            'first_consumed_by' => $fx['frontOffice']->id, 'first_consumed_at' => now(), 'created_at' => now(),
        ])))->toBe('23514');
});

it('RR-REF-03 / RR-REF-13: the case-folded UNIQUE among refunds and the registry CHECK hold', function () {
    $fx = rrcFixture();
    rrcContext($fx);
    [$a, $oa] = rrcObligation($fx, 'OWNER-COR-RF-20261031-U1');
    [$b, $ob] = rrcObligation($fx, 'OWNER-COR-RF-20261031-U2');
    app(RmeRefundRequestService::class)->submit($a->fresh(), $fx['frontOffice'], rrcData($a, $oa, 'OWNER-REF-RF-UQ', 'FORM-PASIEN-UQA'));
    $second = app(RmeRefundRequestService::class)->submit($b->fresh(), $fx['frontOffice'], rrcData($b, $ob, 'OWNER-REF-RF-UQ2', 'FORM-PASIEN-UQB'));

    expect(rrcSqlState(fn () => DB::table('trx_rme_refund_requests')->where('id', $second->id)->update(['owner_approval_reference' => 'owner-ref-rf-uq'])))->toBe('23505')
        ->and(rrcSqlState(fn () => DB::table('trx_rme_owner_approval_references')->insert([
            'reference_folded' => 'OWNER-UNFOLDED-1', 'scope_type' => 'REFUND_REQUEST', 'scope_id' => 1,
            'first_consumed_by' => $fx['frontOffice']->id, 'first_consumed_at' => now(), 'created_at' => now(),
        ])))->toBe('23514');
});

/** A committed PAID invoice with no correction (a target for a correction race). */
function rrcPaidOnly(array $fx): array
{
    $invoice = Model::withoutEvents(function () use ($fx) {
        $patient = Patient::factory()->create(['branch_id' => $fx['branch']->id]);
        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $fx['branch']->id, 'clinic_id' => $fx['clinic']->id, 'patient_id' => $patient->id,
            'doctor_id' => $fx['doctor']->id, 'visit_type' => ClinicVisit::VISIT_TYPE_NEW, 'visit_number' => 'VIS-RF-'.uniqid(),
        ]);
        $record = MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => $fx['branch']->id, 'patient_id' => $patient->id, 'doctor_id' => $fx['doctor']->id,
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
                'unit_price' => $price, 'discount' => 0, 'subtotal' => $price, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $invoice->fresh();
    });

    app(RmePaymentService::class)->pay($invoice, $fx['cashier'], rmeDeclaredPaymentForm($invoice, [
        'amount' => 500000, 'paid_at' => now()->format('Y-m-d H:i:s'),
        'invoice_versions' => [$invoice->id => (int) ($invoice->correction_version ?? 0)],
    ]));

    return [$invoice->fresh()];
}
