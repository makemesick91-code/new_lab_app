<?php

/*
| FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — PostgreSQL 16 only.
|
| The REAL settlement service runs in a second PostgreSQL session
| (`tests/Feature/RmeInvoiceCorrection/race_child.php`), holding every lock it took
| for `hold` seconds, while the parent runs the competing real service. SQLite
| compiles lockForUpdate() to nothing and carries none of the CHECKs or partial
| UNIQUE indexes, so these properties are only observable here.
|
| No RefreshDatabase: the child cannot see the parent's uncommitted fixtures, so
| fixtures are COMMITTED and cleaned up by id watermark afterwards. Evidence files
| land in a per-test temporary disk root shared by both processes.
|
| Cases RS-CONC-01..14 (races) and RS-DB-01..06 (database backstops).
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
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementEvidence;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementIntent;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementMethod;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementRefused as R;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus as S;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const RSCC_WRITER = 'rscc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const RSCC_TABLES = [
    'trx_rme_refund_settlement_events',
    'trx_rme_overpayment_obligation_events',
    'trx_rme_refund_request_events',
    'trx_rme_refund_patient_references',
    'trx_rme_owner_approval_references',
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
    'trx_rme_billing_dispute_events',
    'trx_rme_billing_disputes',
    'trx_rme_receipt_snapshots',
    'trx_rme_invoice_correction_events',
    'trx_rme_invoice_correction_lines',
    'trx_rme_invoice_document_versions',
    'trx_rme_invoice_corrections',
    'trx_rme_payment_submissions',
    'trx_rme_payments',
    'trx_rme_invoice_items',
    'trx_rme_visit_consents',
    'trx_medical_records',
    'trx_daily_branch_contexts',
    'trx_user_online_contexts',
    'mst_clinic_rooms',
    'mst_patients',
    'mst_doctors',
    'mst_clinics',
    'mst_treatments',
    'mst_treatment_categories',
    'users',
];

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row-lock waits, READ COMMITTED visibility, CHECKs and partial UNIQUE indexes are only observable on PostgreSQL.');
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.RSCC_WRITER, $base);
    DB::purge(RSCC_WRITER);
    config()->set('database.default', RSCC_WRITER);

    seedAccessControl();
    ricFlag(true);
    rppFlag(true);
    rrrFlag(true);
    rsFlag(true);

    $this->evidenceRoot = sys_get_temp_dir().'/rscc-evidence-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->evidenceRoot);
    config()->set('filesystems.disks.'.RmeRefundSettlementEvidence::DISK.'.root', $this->evidenceRoot);
    $this->scratch = sys_get_temp_dir().'/rscc-scratch-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->scratch);

    $this->watermarks = [];
    foreach (array_merge(RSCC_TABLES, ['trx_rme_refund_settlements', 'trx_rme_refund_requests', 'trx_rme_overpayment_obligations', 'trx_rme_invoices', 'trx_clinic_visits', 'mst_branches']) as $table) {
        $this->watermarks[$table] = (int) DB::connection(RSCC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(RSCC_WRITER);
    // The obligation points at its settlement and the settlement at its obligation:
    // return the fixture obligation to its OPEN shape in one statement (the CHECK
    // binds status, settled_at and the pointer together), then delete in order.
    $writer->table('trx_rme_overpayment_obligations')->where('id', '>', $this->watermarks['trx_rme_overpayment_obligations'])
        ->update(['status' => 'OPEN', 'settled_at' => null, 'rme_refund_settlement_id' => null]);
    $writer->table('trx_rme_refund_settlement_events')->where('id', '>', $this->watermarks['trx_rme_refund_settlement_events'])->delete();
    $writer->table('trx_rme_refund_settlements')->where('id', '>', $this->watermarks['trx_rme_refund_settlements'])->delete();

    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    foreach (['model_has_permissions', 'model_has_roles'] as $pivot) {
        $writer->table($pivot)->where('model_type', User::class)->where('model_id', '>', $this->watermarks['users'])->delete();
    }

    $ordered = [
        'trx_rme_overpayment_obligation_events', 'trx_rme_refund_request_events', 'trx_rme_refund_patient_references',
        'trx_rme_refund_requests', 'trx_rme_owner_approval_references', 'sys_audit_logs', 'trx_satusehat_audit_logs',
        'trx_satusehat_candidates', 'trx_rme_billing_dispute_events', 'trx_rme_billing_disputes',
        'trx_rme_overpayment_obligations', 'trx_rme_receipt_snapshots', 'trx_rme_invoice_correction_events',
        'trx_rme_invoice_correction_lines', 'trx_rme_invoice_document_versions', 'trx_rme_invoice_corrections',
        'trx_rme_payment_submissions', 'trx_rme_payments', 'trx_rme_invoice_items', 'trx_rme_invoices',
        'trx_rme_visit_consents', 'trx_medical_records', 'trx_clinic_visits', 'trx_daily_branch_contexts',
        'trx_user_online_contexts', 'mst_clinic_rooms', 'mst_patients', 'mst_doctors', 'mst_clinics', 'mst_treatments',
        'mst_treatment_categories', 'mst_branches', 'users',
    ];
    foreach ($ordered as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(RSCC_WRITER);
    File::deleteDirectory($this->evidenceRoot);
    File::deleteDirectory($this->scratch);
});

/** @return array<string, mixed> */
function rsccFixture(): array
{
    return Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'RS'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        ricPilot([(string) $branch->code]);
        rrrPilot([(string) $branch->code]);
        rsPilot([(string) $branch->code]);

        $maker = userWith(['request_rme_invoice_correction']);
        $checker = userWith(['approve_rme_invoice_correction']);
        ricPilotIdentities([(int) $maker->id], [(int) $checker->id]);

        $frontOffice = userInRole('Front Office');
        $supervisor = userInRole('Supervisor RME');
        rrrIdentities([(int) $frontOffice->id], [(int) $supervisor->id]);

        $settler = userWith([RmeRefundSettlementService::PERMISSION_SETTLE]);
        $settler2 = userWith([RmeRefundSettlementService::PERMISSION_SETTLE]);
        rsSettlers([
            ['user_id' => (int) $settler->id, 'branch_codes' => [(string) $branch->code]],
            ['user_id' => (int) $settler2->id, 'branch_codes' => [(string) $branch->code]],
        ]);

        return [
            'branch' => $branch,
            'clinic' => Clinic::factory()->create(),
            'doctor' => Doctor::factory()->create(['branch_id' => $branch->id]),
            'maker' => $maker,
            'checker' => $checker,
            'cashier' => userWith(['manage_rme_billing', 'view_clinic_visits']),
            'frontOffice' => $frontOffice,
            'supervisor' => $supervisor,
            'settler' => $settler,
            'settler2' => $settler2,
        ];
    });
}

/** A committed APPROVED 150.000 refund request through the REAL services. */
function rsccApproved(array $fx, string $suffix): RmeRefundRequest
{
    rmeMakeFrontOfficeActive($fx['frontOffice'], $fx['branch']);

    $invoice = Model::withoutEvents(function () use ($fx) {
        $patient = Patient::factory()->create(['branch_id' => $fx['branch']->id]);
        $visit = ClinicVisit::factory()->cashierPending()->create([
            'branch_id' => $fx['branch']->id, 'clinic_id' => $fx['clinic']->id, 'patient_id' => $patient->id,
            'doctor_id' => $fx['doctor']->id, 'visit_type' => ClinicVisit::VISIT_TYPE_NEW, 'visit_number' => 'VIS-RS-'.uniqid(),
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
        'owner_approval_reference' => 'OWNER-COR-RS-20261031-'.$suffix,
        'lines' => [
            ['original_item_id' => $items[0]->id, 'treatment_id' => $items[0]->treatment_id, 'description' => 'Scaling', 'qty' => 1, 'unit_price' => '300000', 'discount' => '0'],
            ['original_item_id' => $items[1]->id, 'treatment_id' => $items[1]->treatment_id, 'description' => 'Tambal', 'qty' => 1, 'unit_price' => '50000', 'discount' => '0'],
        ],
    ]);
    app(RmeInvoiceCorrectionService::class)->approve($correction->fresh(), $fx['checker'], null);

    $invoice = $invoice->fresh();
    $obligation = RmeOverpaymentObligation::query()->where('rme_invoice_id', $invoice->id)->firstOrFail();

    $request = app(RmeRefundRequestService::class)->submit($invoice, $fx['frontOffice'], [
        RmeRefundRequestIntent::FIELD => RmeRefundRequestIntent::issue((int) $obligation->id),
        'obligation_id' => (int) $obligation->id,
        'obligation_amount' => RmeRefundRequestSnapshot::decimal(RmeRefundRequestSnapshot::cents($obligation->amount)),
        'invoice_version' => (int) ($invoice->correction_version ?? 0),
        'reason_note' => 'Pasien meminta pengembalian kelebihan bayar.',
        'patient_request_reference' => 'FORM-PASIEN-RS-'.$suffix,
        'owner_approval_reference' => 'OWNER-REF-RS-'.$suffix,
    ]);

    return app(RmeRefundRequestService::class)->approve($request->fresh(), $fx['supervisor'], []);
}

/** @return array<string, mixed> */
function rsccStartData(RmeRefundRequest $request, string $method = RmeRefundSettlementMethod::BANK_TRANSFER, ?string $intent = null): array
{
    return [
        RmeRefundSettlementIntent::FIELD => $intent ?? RmeRefundSettlementIntent::issue((int) $request->id),
        'method' => $method,
        'amount' => RmeRefundRequestSnapshot::decimal(RmeRefundRequestSnapshot::cents($request->amount)),
    ];
}

/** @return array<string, mixed> */
function rsccBankData(string $reference = 'TRF-BCA-20261031-0001'): array
{
    return ['disbursement_reference' => $reference, 'destination_bank_name' => 'BCA', 'destination_account_last4' => '4321', 'attest_disbursed' => '1'];
}

/** A distinct genuine PDF on disk, readable by both processes. */
function rsccPdf(string $salt): string
{
    $path = test()->scratch.'/bukti-'.$salt.'.pdf';
    file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% evidence ".$salt."\ntrailer<</Root 1 0 R>>\n%%EOF\n");

    return $path;
}

function rsccUpload(string $path): UploadedFile
{
    return new UploadedFile($path, basename($path), null, null, true);
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>, 2: string}
 */
function rsccCompeting(array $steps, string $finish = 'commit', float $hold = 2.0): array
{
    $db = config('database.connections.'.RSCC_WRITER);
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
            'rme_refund_settlement.pilot.branch_codes' => config('rme_refund_settlement.pilot.branch_codes'),
            'rme_refund_settlement.pilot.settlers' => config('rme_refund_settlement.pilot.settlers'),
            'filesystems.disks.'.RmeRefundSettlementEvidence::DISK.'.root' => test()->evidenceRoot,
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

function rsccJoin(array $handle): void
{
    [$process, $pipes, $finish] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)
        ->and($rest)->toContain($finish === 'rollback' ? 'ROLLED_BACK' : 'COMMITTED');
}

/** Runs $work, returns [refusal code or null, seconds waited, result]; the connection must stay usable. */
function rsccAttempt(callable $work): array
{
    $started = microtime(true);
    $code = null;
    $result = null;
    try {
        $result = $work();
    } catch (R $refused) {
        $code = $refused->reasonCode;
    } catch (RmeRefundRequestRefused $refused) {
        $code = $refused->reasonCode;
    } catch (ValidationException $e) {
        $code = (string) json_encode($e->errors());
    }

    expect(DB::transactionLevel())->toBe(0)
        ->and((int) DB::selectOne('SELECT 1 AS one')->one)->toBe(1);

    return [$code, microtime(true) - $started, $result];
}

/** SQLSTATE of a statement run inside a savepoint, so the outer state survives. */
function rsccSqlState(callable $statement): ?string
{
    try {
        DB::transaction(fn () => $statement());
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    return null;
}

function rsccStart(array $fx, RmeRefundRequest $request, string $who = 'settler'): RmeRefundSettlement
{
    return app(RmeRefundSettlementService::class)->start($request->fresh(), $fx[$who], rsccStartData($request));
}

/** Live (non-ABORTED) settlements of a request. */
function rsccLive(RmeRefundRequest $request): int
{
    return RmeRefundSettlement::query()->where('rme_refund_request_id', $request->id)->where('status', '<>', S::ABORTED)->count();
}

// ─── RS-CONC: races through the REAL services ────────────────────────────────

it('RS-CONC-01: two settlers start the same request — the second waits, then is refused; one live settlement', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C01');

    $handle = rsccCompeting([['kind' => 'start_settlement', 'request' => $request->id, 'actor' => $fx['settler']->id, 'data' => rsccStartData($request)]]);
    [$code, $waited] = rsccAttempt(fn () => rsccStart($fx, $request, 'settler2'));
    rsccJoin($handle);

    expect($code)->toBe(R::SETTLEMENT_EXISTS)
        ->and($waited)->toBeGreaterThan(1.0)
        ->and(rsccLive($request))->toBe(1);
});

it('RS-CONC-02: the same intent submitted twice concurrently creates exactly one settlement (replay)', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C02');
    $data = rsccStartData($request);

    $handle = rsccCompeting([['kind' => 'start_settlement', 'request' => $request->id, 'actor' => $fx['settler']->id, 'data' => $data]]);
    [$code, , $result] = rsccAttempt(fn () => app(RmeRefundSettlementService::class)->start($request->fresh(), $fx['settler'], $data));
    rsccJoin($handle);

    expect($code)->toBeNull()
        ->and(RmeRefundSettlement::query()->where('rme_refund_request_id', $request->id)->count())->toBe(1)
        ->and((int) $result->id)->toBe((int) RmeRefundSettlement::query()->where('rme_refund_request_id', $request->id)->value('id'));
});

it('RS-CONC-03: payouts on two different requests do not serialize on each other', function () {
    $fx = rsccFixture();
    $first = rsccApproved($fx, 'C03A');
    $second = rsccApproved($fx, 'C03B');

    $handle = rsccCompeting([['kind' => 'start_settlement', 'request' => $first->id, 'actor' => $fx['settler']->id, 'data' => rsccStartData($first)]], hold: 3.0);
    [$code, $waited] = rsccAttempt(fn () => rsccStart($fx, $second, 'settler2'));
    rsccJoin($handle);

    expect($code)->toBeNull()
        ->and($waited)->toBeLessThan(2.0)
        ->and(rsccLive($first))->toBe(1)
        ->and(rsccLive($second))->toBe(1);
});

it('RS-CONC-04: a double-submitted confirmation settles once — one SETTLED event, obligation settled once', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C04');
    $settlement = rsccStart($fx, $request);
    $pdf = rsccPdf('c04');

    $handle = rsccCompeting([['kind' => 'confirm_settlement', 'settlement' => $settlement->id, 'actor' => $fx['settler']->id, 'data' => rsccBankData('TRF-C04-0001'), 'evidence' => $pdf]]);
    [$code] = rsccAttempt(fn () => app(RmeRefundSettlementService::class)->confirm($settlement->fresh(), $fx['settler'], rsccBankData('TRF-C04-0001'), rsccUpload($pdf)));
    rsccJoin($handle);

    expect($code)->toBeNull()
        ->and($settlement->fresh()->status)->toBe(S::SETTLED)
        ->and(DB::table('trx_rme_refund_settlement_events')->where('rme_refund_settlement_id', $settlement->id)->where('to_status', S::SETTLED)->count())->toBe(1)
        ->and(DB::table('trx_rme_overpayment_obligation_events')->where('rme_overpayment_obligation_id', $request->rme_overpayment_obligation_id)->where('to_status', 'SETTLED')->count())->toBe(1)
        ->and($request->fresh()->status)->toBe(RmeRefundRequestStatus::SETTLED);
});

it('RS-CONC-05: confirm against abort — exactly one terminal state, and the losing side changes nothing', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C05');
    $settlement = rsccStart($fx, $request);

    $handle = rsccCompeting([['kind' => 'abort_settlement', 'settlement' => $settlement->id, 'actor' => $fx['settler']->id,
        'data' => ['abort_reason' => 'Transfer dibatalkan sebelum dikirim.', 'attest_not_disbursed' => '1']]]);
    [$code] = rsccAttempt(fn () => app(RmeRefundSettlementService::class)->confirm($settlement->fresh(), $fx['settler'], rsccBankData('TRF-C05-0001'), rsccUpload(rsccPdf('c05'))));
    rsccJoin($handle);

    expect($code)->toBe(R::INVALID_TRANSITION)
        ->and($settlement->fresh()->status)->toBe(S::ABORTED)
        ->and($request->fresh()->status)->toBe(RmeRefundRequestStatus::APPROVED)
        ->and(RmeOverpaymentObligation::query()->findOrFail($request->rme_overpayment_obligation_id)->status)->toBe(RmeOverpaymentObligation::STATUS_OPEN)
        ->and(File::allFiles(test()->evidenceRoot))->toBe([]);
});

it('RS-CONC-06: an outcome reported unknown while the confirmation waits — the confirmation still lands from OUTCOME_UNKNOWN', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C06');
    $settlement = rsccStart($fx, $request);

    $handle = rsccCompeting([['kind' => 'report_settlement_unknown', 'settlement' => $settlement->id, 'actor' => $fx['settler2']->id, 'note' => 'Bank menampilkan status transfer tertunda.']]);
    [$code] = rsccAttempt(fn () => app(RmeRefundSettlementService::class)->confirm($settlement->fresh(), $fx['settler'], rsccBankData('TRF-C06-0001'), rsccUpload(rsccPdf('c06'))));
    rsccJoin($handle);

    expect($code)->toBeNull()
        ->and($settlement->fresh()->status)->toBe(S::SETTLED)
        ->and(DB::table('trx_rme_refund_settlement_events')->where('rme_refund_settlement_id', $settlement->id)->pluck('to_status')->all())
        ->toBe([S::DISBURSING, S::OUTCOME_UNKNOWN, S::SETTLED]);
});

it('RS-CONC-07: approval revoked while a payout starts — the payout waits, then is refused', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C07');

    $handle = rsccCompeting([['kind' => 'cancel_refund', 'request' => $request->id, 'actor' => $fx['supervisor']->id, 'reason' => 'Owner mencabut persetujuan refund ini.']]);
    [$code, $waited] = rsccAttempt(fn () => rsccStart($fx, $request));
    rsccJoin($handle);

    expect($code)->toBe(R::REQUEST_NOT_APPROVED)
        ->and($waited)->toBeGreaterThan(1.0)
        ->and(rsccLive($request))->toBe(0);
});

it('RS-CONC-08: a payout under way blocks revoking the approval', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C08');

    $handle = rsccCompeting([['kind' => 'start_settlement', 'request' => $request->id, 'actor' => $fx['settler']->id, 'data' => rsccStartData($request)]]);
    [$code] = rsccAttempt(fn () => app(RmeRefundRequestService::class)->cancel($request->fresh(), $fx['supervisor'], ['cancel_reason' => 'Owner mencabut persetujuan refund ini.']));
    rsccJoin($handle);

    expect($code)->toBe(RmeRefundRequestRefused::SETTLEMENT_IN_PROGRESS)
        ->and($request->fresh()->status)->toBe(RmeRefundRequestStatus::APPROVED)
        ->and(rsccLive($request))->toBe(1);
});

it('RS-CONC-09: a billing dispute reported while a payout starts — the payout is refused', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C09');

    $handle = rsccCompeting([['kind' => 'report_dispute', 'invoice' => $request->rme_invoice_id, 'actor' => $fx['cashier']->id,
        'data' => ['reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT, 'description' => 'Pasien mempertanyakan nominal tagihan.']]]);
    [$code] = rsccAttempt(fn () => rsccStart($fx, $request));
    rsccJoin($handle);

    expect($code)->toBe(R::INVOICE_DISPUTED)->and(rsccLive($request))->toBe(0);
});

it('RS-CONC-10: a billing dispute reported while a confirmation waits — refused, the payout stays open', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C10');
    $settlement = rsccStart($fx, $request);

    $handle = rsccCompeting([['kind' => 'report_dispute', 'invoice' => $request->rme_invoice_id, 'actor' => $fx['cashier']->id,
        'data' => ['reason_category' => RmeBillingDisputeReason::AMOUNT_INCORRECT, 'description' => 'Pasien mempertanyakan nominal tagihan.']]]);
    [$code] = rsccAttempt(fn () => app(RmeRefundSettlementService::class)->confirm($settlement->fresh(), $fx['settler'], rsccBankData('TRF-C10-0001'), rsccUpload(rsccPdf('c10'))));
    rsccJoin($handle);

    expect($code)->toBe(R::INVOICE_DISPUTED)
        ->and($settlement->fresh()->status)->toBe(S::DISBURSING)
        ->and(File::allFiles(test()->evidenceRoot))->toBe([]);
});

it('RS-CONC-11: lock order — a held invoice lock makes the payout wait, then it succeeds', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C11');

    $handle = rsccCompeting([['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_invoices WHERE id = ? FOR UPDATE', 'bindings' => [$request->rme_invoice_id]]]);
    [$code, $waited] = rsccAttempt(fn () => rsccStart($fx, $request));
    rsccJoin($handle);

    expect($code)->toBeNull()->and($waited)->toBeGreaterThan(1.0)->and(rsccLive($request))->toBe(1);
});

it('RS-CONC-12: lock order — a held refund request lock makes the confirmation wait, then it settles', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C12');
    $settlement = rsccStart($fx, $request);

    $handle = rsccCompeting([['kind' => 'select', 'sql' => 'SELECT id FROM trx_rme_refund_requests WHERE id = ? FOR UPDATE', 'bindings' => [$request->id]]]);
    [$code, $waited] = rsccAttempt(fn () => app(RmeRefundSettlementService::class)->confirm($settlement->fresh(), $fx['settler'], rsccBankData('TRF-C12-0001'), rsccUpload(rsccPdf('c12'))));
    rsccJoin($handle);

    expect($code)->toBeNull()->and($waited)->toBeGreaterThan(1.0)->and($settlement->fresh()->status)->toBe(S::SETTLED);
});

it('RS-CONC-13: one bank reference confirmed on two payouts concurrently — exactly one settles', function () {
    $fx = rsccFixture();
    $first = rsccStart($fx, rsccApproved($fx, 'C13A'));
    $second = rsccStart($fx, rsccApproved($fx, 'C13B'), 'settler2');

    $handle = rsccCompeting([['kind' => 'confirm_settlement', 'settlement' => $first->id, 'actor' => $fx['settler']->id, 'data' => rsccBankData('TRF-SAMA-0001'), 'evidence' => rsccPdf('c13a')]]);
    [$code] = rsccAttempt(fn () => app(RmeRefundSettlementService::class)->confirm($second->fresh(), $fx['settler2'], rsccBankData('trf-sama-0001'), rsccUpload(rsccPdf('c13b'))));
    rsccJoin($handle);

    expect($code)->toBe(R::REFERENCE_REUSED)
        ->and($first->fresh()->status)->toBe(S::SETTLED)
        ->and($second->fresh()->status)->toBe(S::DISBURSING)
        ->and(count(File::allFiles(test()->evidenceRoot)))->toBe(1);
});

it('RS-CONC-14: an abort releases the request — a new payout started meanwhile waits, then proceeds', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'C14');
    $settlement = rsccStart($fx, $request);

    $handle = rsccCompeting([['kind' => 'abort_settlement', 'settlement' => $settlement->id, 'actor' => $fx['settler']->id,
        'data' => ['abort_reason' => 'Rekening tujuan salah; transfer tidak dikirim.', 'attest_not_disbursed' => '1']]]);
    [$code, $waited, $result] = rsccAttempt(fn () => rsccStart($fx, $request, 'settler2'));
    rsccJoin($handle);

    expect($code)->toBeNull()
        ->and($waited)->toBeGreaterThan(1.0)
        ->and($settlement->fresh()->status)->toBe(S::ABORTED)
        ->and($result->status)->toBe(S::DISBURSING)
        ->and(rsccLive($request))->toBe(1);
});

// ─── RS-DB: the database backstops (the service decides first) ───────────────

it('RS-DB-01: a second live settlement for one request or one obligation is rejected by the database', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'D01');
    $settlement = rsccStart($fx, $request);
    $row = (array) DB::table('trx_rme_refund_settlements')->where('id', $settlement->id)->first();
    unset($row['id']);

    $state = rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->insert(array_merge($row, [
        'uuid' => (string) Str::uuid(), 'settlement_number' => null, 'intent_key' => str_repeat('a', 32),
    ])));

    expect($state)->toBe('23505');
});

it('RS-DB-02: an ABORTED settlement does not hold the request (partial unique index)', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'D02');
    $settlement = rsccStart($fx, $request);
    app(RmeRefundSettlementService::class)->abort($settlement->fresh(), $fx['settler'], ['abort_reason' => 'Transfer tidak jadi dikirim.', 'attest_not_disbursed' => '1'], null);

    $second = rsccStart($fx, $request, 'settler2');
    expect($second->status)->toBe(S::DISBURSING)
        ->and(RmeRefundSettlement::query()->where('rme_refund_request_id', $request->id)->count())->toBe(2);
});

it('RS-DB-03: the approver can never appear as settler, escalator or aborter in the database', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'D03');
    $settlement = rsccStart($fx, $request);

    foreach (['started_by', 'outcome_unknown_by', 'aborted_by', 'settled_by'] as $column) {
        $state = rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $settlement->id)->update([$column => $fx['supervisor']->id]));
        expect($state)->toBe('23514', $column);
    }
});

it('RS-DB-04: a SETTLED row without its proof columns is rejected', function () {
    $fx = rsccFixture();
    $settlement = rsccStart($fx, rsccApproved($fx, 'D04'));

    $state = rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $settlement->id)
        ->update(['status' => S::SETTLED, 'settled_by' => $fx['settler']->id, 'settled_at' => now()]));
    expect($state)->toBe('23514');
});

it('RS-DB-05: an obligation and a request cannot claim SETTLED without the settlement that proves it', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'D05');

    expect(rsccSqlState(fn () => DB::table('trx_rme_overpayment_obligations')->where('id', $request->rme_overpayment_obligation_id)
        ->update(['status' => 'SETTLED'])))->toBe('23514');
    expect(rsccSqlState(fn () => DB::table('trx_rme_refund_requests')->where('id', $request->id)
        ->update(['status' => 'SETTLED', 'decided_by' => null])))->toBe('23514');
});

it('RS-DB-06: amount, method and status are constrained; an evidence hash settles once', function () {
    $fx = rsccFixture();
    $first = rsccStart($fx, rsccApproved($fx, 'D06A'));
    $second = rsccStart($fx, rsccApproved($fx, 'D06B'), 'settler2');

    expect(rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $first->id)->update(['amount' => 0])))->toBe('23514')
        ->and(rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $first->id)->update(['method' => 'CREDIT'])))->toBe('23514')
        ->and(rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $first->id)->update(['status' => 'PAID'])))->toBe('23514');

    $pdf = rsccPdf('d06');
    app(RmeRefundSettlementService::class)->confirm($first->fresh(), $fx['settler'], rsccBankData('TRF-D06-0001'), rsccUpload($pdf));
    $sha = (string) $first->fresh()->evidence_sha256;

    $state = rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $second->id)->update([
        'status' => S::SETTLED, 'settled_by' => $fx['settler2']->id, 'settled_at' => now(), 'settler_attested_at' => now(),
        'disbursement_reference' => 'TRF-D06-0002', 'disbursement_reference_key' => 'TRFD060002', 'destination_bank_name' => 'BCA', 'destination_account_last4' => '1234',
        'evidence_path' => 'x/'.$sha.'.pdf', 'evidence_sha256' => $sha, 'evidence_mime' => 'application/pdf', 'evidence_size_bytes' => 10,
    ]));
    expect($state)->toBe('23505');
});

it('RS-DB-07: approved_by is bound to the request decider, so the never-the-approver CHECK cannot be fed a copied value', function () {
    $fx = rsccFixture();
    $request = rsccApproved($fx, 'D07');
    $settlement = rsccStart($fx, $request);

    // Re-pointing approved_by away from the real decider is rejected by the composite FK.
    expect(rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $settlement->id)
        ->update(['approved_by' => $fx['settler2']->id])))->toBe('23503');
    // And the request's decider cannot be rewritten under a recorded payout.
    expect(rsccSqlState(fn () => DB::table('trx_rme_refund_requests')->where('id', $request->id)
        ->update(['decided_by' => $fx['settler2']->id])))->toBe('23503');
});

it('RS-DB-08: one bank reference settles once however it is spelled, across methods', function () {
    $fx = rsccFixture();
    $first = rsccStart($fx, rsccApproved($fx, 'D08A'));
    $second = rsccStart($fx, rsccApproved($fx, 'D08B'), 'settler2');
    app(RmeRefundSettlementService::class)->confirm($first->fresh(), $fx['settler'], rsccBankData('TRF-D08-0001'), rsccUpload(rsccPdf('d08a')));

    $state = rsccSqlState(fn () => DB::table('trx_rme_refund_settlements')->where('id', $second->id)->update([
        'status' => S::SETTLED, 'method' => RmeRefundSettlementMethod::CASH, 'settled_by' => $fx['settler2']->id, 'settled_at' => now(),
        'settler_attested_at' => now(), 'disbursement_reference' => 'trf d08 0001', 'disbursement_reference_key' => 'TRFD080001',
        'recipient_relation' => 'PATIENT', 'evidence_path' => 'x/y.pdf', 'evidence_sha256' => str_repeat('b', 64),
        'evidence_mime' => 'application/pdf', 'evidence_size_bytes' => 10,
    ]));
    expect($state)->toBe('23505');
});
