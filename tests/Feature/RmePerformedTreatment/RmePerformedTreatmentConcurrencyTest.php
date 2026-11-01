<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the doctor's structured
| record under REAL PostgreSQL concurrency (DPT-RACE-A … L).
|--------------------------------------------------------------------------
|
| THE LOCK. Every write locks the VISIT row first (SELECT … FOR UPDATE) and
| re-reads, AFTER the lock, the examination status, the consent, the account and
| the treating-doctor identity. The examination-completion UPDATE and the Rp0
| closure take the same row lock, so a clinical write either commits while the
| visit is in_progress or is refused — never half.
|
| THE PROOF. The competing transaction is a child PHP process
| (performed_treatment_race_child.php) running the REAL services inside one open
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
|   php artisan test tests/Feature/RmePerformedTreatment/RmePerformedTreatmentConcurrencyTest.php
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Repositories\PatientMergeOwnershipRepository;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Services\RmePerformedTreatmentService;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeZeroValueClosureFeatureGuard;
use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

const DPTC_WRITER = 'dptc_race_writer';

/** Tables this suite writes to, in child-before-parent delete order. */
const DPTC_TABLES = [
    'trx_rme_performed_treatments',
    'trx_rme_treatment_declarations',
    'trx_daily_branch_contexts',
    'trx_user_online_contexts',
    'sys_audit_logs',
    'trx_satusehat_audit_logs',
    'trx_satusehat_candidates',
    'trx_rme_receipt_snapshots',
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
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.DPTC_WRITER, $base);
    DB::purge(DPTC_WRITER);
    config()->set('database.default', DPTC_WRITER);

    seedAccessControl();
    dptcFlags(true);

    $this->watermarks = [];
    foreach (DPTC_TABLES as $table) {
        $this->watermarks[$table] = (int) DB::connection(DPTC_WRITER)->table($table)->max('id');
    }
});

afterEach(function () {
    if (! isset($this->watermarks)) {
        return;
    }

    $writer = DB::connection(DPTC_WRITER);
    $newDoctorIds = $writer->table('mst_doctors')->where('id', '>', $this->watermarks['mst_doctors'])->pluck('id');
    $writer->table('mst_doctor_branches')->whereIn('doctor_id', $newDoctorIds)->delete();
    foreach (['model_has_permissions', 'model_has_roles'] as $pivot) {
        $writer->table($pivot)->where('model_type', User::class)->where('model_id', '>', $this->watermarks['users'])->delete();
    }
    $writer->table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', '>', $this->watermarks['users'])->delete();

    foreach (DPTC_TABLES as $table) {
        $writer->table($table)->where('id', '>', $this->watermarks[$table])->delete();
    }

    DB::purge(DPTC_WRITER);
});

function dptcFlags(bool $enabled): void
{
    $flags = config('feature_flags.flags', []);
    foreach ([RmePerformedTreatmentFeatureGuard::FLAG, RmeZeroValueClosureFeatureGuard::FLAG] as $flag) {
        $flags[$flag]['default'] = $enabled;
        $flags[$flag]['env_value'] = null;
    }
    config()->set('feature_flags.flags', $flags);
}

/**
 * Committed rows: an RME branch, a room, a patient, the treating doctor online in
 * that room with a linked account, a cashier, a paid treatment (Scaling), a
 * Konsultasi Dokter, and an in_progress, consented visit.
 *
 * @return array<string, mixed>
 */
function dptcFixture(): array
{
    $fx = Model::withoutEvents(function () {
        $branch = Branch::factory()->create(['code' => 'DC'.random_int(100, 999), 'is_rme_enabled' => true, 'is_active' => true]);
        $restorative = TreatmentCategory::query()->where('code', 'dptc-restorative')->first()
            ?? TreatmentCategory::factory()->create(['code' => 'dptc-restorative', 'name' => 'Restoratif DPTC']);
        $consultationCategory = TreatmentCategory::query()->where('code', 'consultation')->first()
            ?? TreatmentCategory::factory()->create(['code' => 'consultation', 'name' => 'Konsultasi']);

        return [
            'branch' => $branch,
            'room' => ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]),
            'clinic' => Clinic::factory()->create(),
            'patient' => Patient::factory()->create(['branch_id' => $branch->id]),
            'doctor' => Doctor::factory()->create(['branch_id' => $branch->id]),
            'cashier' => userWith(['manage_rme_billing', 'view_clinic_visits']),
            'scaling' => Treatment::factory()->create(['name' => 'Scaling DPTC '.uniqid(), 'treatment_category_id' => $restorative->id, 'is_active' => true, 'requires_lab' => false]),
            'consultation' => Treatment::factory()->create(['name' => 'Konsultasi DPTC '.uniqid(), 'treatment_category_id' => $consultationCategory->id, 'is_active' => true, 'requires_lab' => false]),
        ];
    });

    $fx['doctorUser'] = rmeMakeDoctorOnline($fx['doctor'], $fx['branch'], $fx['room']);
    $fx['visit'] = dptcVisit($fx);

    return $fx;
}

/** @param array<string, mixed> $fx */
function dptcVisit(array $fx): ClinicVisit
{
    return Model::withoutEvents(function () use ($fx) {
        $visit = ClinicVisit::factory()->create([
            'branch_id' => $fx['branch']->id, 'clinic_id' => $fx['clinic']->id, 'patient_id' => $fx['patient']->id,
            'doctor_id' => $fx['doctor']->id, 'clinic_room_id' => $fx['room']->id,
            'status' => ClinicVisit::STATUS_IN_PROGRESS, 'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
            'visit_number' => 'VIS-DC-'.uniqid(), 'visit_date' => clinicalToday()->toDateString(),
        ]);
        MedicalRecord::factory()->final()->create([
            'clinic_visit_id' => $visit->id, 'branch_id' => $fx['branch']->id,
            'patient_id' => $fx['patient']->id, 'doctor_id' => $fx['doctor']->id,
        ]);
        rmeSignedConsentFor($visit);

        return $visit->fresh();
    });
}

/** @param array<string, mixed> $fx */
function dptcRp0Invoice(array $fx, ClinicVisit $visit): RmeInvoice
{
    return Model::withoutEvents(function () use ($fx, $visit) {
        $invoice = RmeInvoice::factory()->unpaid()->create([
            'branch_id' => $fx['branch']->id, 'clinic_visit_id' => $visit->id, 'patient_id' => $fx['patient']->id,
            'medical_record_id' => MedicalRecord::query()->where('clinic_visit_id', $visit->id)->value('id'),
            'cashier_id' => $fx['cashier']->id, 'subtotal' => 0, 'discount_total' => 0, 'grand_total' => 0,
        ]);
        DB::table('trx_rme_invoice_items')->insert([
            'rme_invoice_id' => $invoice->id, 'treatment_id' => $fx['consultation']->id, 'description' => 'Konsultasi dokter', 'qty' => 1,
            'unit_price' => 0, 'discount' => 0, 'subtotal' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $invoice->fresh();
    });
}

/** @return array<string, mixed> */
function dptcData(Treatment $treatment, array $teeth = [], ?string $token = null): array
{
    return ['treatment_id' => $treatment->id, 'quantity' => 1, 'tooth_numbers' => $teeth, 'submission_token' => $token ?? (string) Str::uuid()];
}

/**
 * @param  list<array<string, mixed>>  $steps
 * @return array{0: resource, 1: array<int, resource>}
 */
function dptcCompeting(array $steps, float $hold = 2.0): array
{
    $db = config('database.connections.'.DPTC_WRITER);

    $payload = base64_encode((string) json_encode([
        'steps' => $steps,
        'hold' => $hold,
        'config' => ['feature_flags.flags' => config('feature_flags.flags')],
    ]));

    $env = [
        'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()),
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'APP_KEY' => (string) config('app.key'), 'APP_URL' => (string) config('app.url'),
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'null', 'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
    ];

    $process = proc_open(
        [PHP_BINARY, __DIR__.'/performed_treatment_race_child.php', $payload],
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

    return [$process, $pipes];
}

/** @param array{0: resource, 1: array<int, resource>} $handle */
function dptcJoin(array $handle): void
{
    [$process, $pipes] = $handle;
    $rest = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, 'competing transaction failed: '.$rest.' '.$err)->and($rest)->toContain('COMMITTED');
}

/** @return array{waited: float, refusal: ?string} */
function dptcRace(callable $work): array
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

function dptcSvc(): RmePerformedTreatmentService
{
    return app(RmePerformedTreatmentService::class);
}

function dptcRows(ClinicVisit $visit): int
{
    return RmePerformedTreatment::query()->where('clinic_visit_id', $visit->id)->active()->count();
}

// ─── A / H: concurrent saves of the same draft ───────────────────────────────

it('DPT-RACE-A two concurrent saves of different rows both land — no lost update', function () {
    $fx = dptcFixture();
    $handle = dptcCompeting([['kind' => 'record', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id, 'data' => dptcData($fx['scaling'], ['16'])]]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling'], ['26']), $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and(dptcRows($fx['visit']))->toBe(2);
});

it('DPT-RACE-H a duplicated submission racing itself writes exactly one row and one audit', function () {
    $fx = dptcFixture();
    $token = (string) Str::uuid();
    $handle = dptcCompeting([['kind' => 'record', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id, 'data' => dptcData($fx['scaling'], [], $token)]]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling'], [], $token), $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and(RmePerformedTreatment::query()->where('clinic_visit_id', $fx['visit']->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('entity_id', $fx['visit']->id)->where('action', 'RME_PERFORMED_TREATMENT_RECORDED')->count())->toBe(1);
});

// ─── B / L: clinical write versus examination completion ────────────────────

it('DPT-RACE-B a save waiting behind the examination completion is refused, never half-written', function () {
    $fx = dptcFixture();
    $handle = dptcCompeting([['kind' => 'complete_examination', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id]]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(dptcRows($fx['visit']))->toBe(0)
        ->and($fx['visit']->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING);
});

it('DPT-RACE-B completion waiting behind a save sees the committed row', function () {
    $fx = dptcFixture();
    $handle = dptcCompeting([['kind' => 'record', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id, 'data' => dptcData($fx['scaling'])]]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => app(ClinicVisitService::class)->transitionStatus($fx['visit']->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toBeNull()
        ->and($fx['visit']->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(dptcRows($fx['visit']))->toBe(1);
});

it('DPT-RACE-L a no-treatment declaration racing completion lands before it or is refused after it', function () {
    $fx = dptcFixture();
    $handle = dptcCompeting([['kind' => 'complete_examination', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id]]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->declare($fx['visit']->fresh(), RmeTreatmentDeclaration::TYPE_NO_TREATMENT, $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(RmeTreatmentDeclaration::query()->where('clinic_visit_id', $fx['visit']->id)->count())->toBe(0);

    $second = dptcVisit($fx);
    $handle = dptcCompeting([['kind' => 'declare', 'visit' => $second->id, 'actor' => $fx['doctorUser']->id, 'type' => RmeTreatmentDeclaration::TYPE_NO_TREATMENT]]);
    $race = dptcRace(fn () => app(ClinicVisitService::class)->transitionStatus($second->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($second->fresh()->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(RmeTreatmentDeclaration::query()->where('clinic_visit_id', $second->id)->value('declaration_type'))->toBe(RmeTreatmentDeclaration::TYPE_NO_TREATMENT);
});

// ─── C / G / J: clinical record versus billing ──────────────────────────────

it('DPT-RACE-C an amendment racing the hand-off to billing is refused once the visit left the examination', function () {
    $fx = dptcFixture();
    test()->actingAs($fx['doctorUser']);
    $row = dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']);
    $handle = dptcCompeting([['kind' => 'complete_examination', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id]]);

    $race = dptcRace(fn () => dptcSvc()->void($fx['visit']->fresh(), $row->fresh(), 'Batal.', $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});

it('DPT-RACE-G the Rp0 closure evaluates exactly the rows that remain — a clinical write cannot slip in', function () {
    $fx = dptcFixture();
    test()->actingAs($fx['doctorUser']);
    $row = dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']);
    app(ClinicVisitService::class)->transitionStatus($fx['visit']->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $fx['doctorUser']);
    $invoice = dptcRp0Invoice($fx, $fx['visit']);
    rmeMakeKasirActive($fx['cashier'], $fx['branch']);

    $handle = dptcCompeting([['kind' => 'close_zero_value', 'invoice' => $invoice->id, 'actor' => $fx['cashier']->id]]);
    $race = dptcRace(fn () => dptcSvc()->void($fx['visit']->fresh(), $row->fresh(), 'Batal.', $fx['doctorUser']));
    dptcJoin($handle);

    $snapshot = AuditLog::query()->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)->where('entity_id', $invoice->id)
        ->value('new_values')['clinical_billing_consistency'];

    expect($race['refusal'])->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and($invoice->fresh()->status)->toBe(RmeInvoice::STATUS_NO_CHARGE)
        ->and($snapshot['doctor_evidence']['performed_count'])->toBe(dptcRows($fx['visit']))
        ->and(DB::table('trx_rme_payments')->where('rme_invoice_id', $invoice->id)->count())->toBe(0);
});

it('DPT-RACE-J an amendment racing the completion is seen by the later consistency evaluation', function () {
    $fx = dptcFixture();
    test()->actingAs($fx['doctorUser']);
    $row = dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']);
    $handle = dptcCompeting([['kind' => 'void', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id, 'row' => $row->id, 'reason' => 'Tidak jadi.']]);

    $race = dptcRace(fn () => app(ClinicVisitService::class)->transitionStatus($fx['visit']->fresh(), ClinicVisit::STATUS_CASHIER_PENDING, $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and(dptcSvc()->evidenceFor($fx['visit']->fresh())->performedCount())->toBe(0)
        ->and($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_VOIDED);
});

// ─── K: no-treatment versus insertion ───────────────────────────────────────

it('DPT-RACE-K "no treatment" and a performed row can never both commit', function () {
    $fx = dptcFixture();
    $handle = dptcCompeting([['kind' => 'declare', 'visit' => $fx['visit']->id, 'actor' => $fx['doctorUser']->id, 'type' => RmeTreatmentDeclaration::TYPE_NO_TREATMENT]]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::DECLARED_NO_TREATMENT)
        ->and(dptcRows($fx['visit']))->toBe(0);

    // A second patient: the first visit is still in progress, and a patient with
    // two current encounters is ambiguous and fails closed (ENCOUNTER_NOT_ACTIVE).
    $fx['patient'] = Model::withoutEvents(fn () => Patient::factory()->create(['branch_id' => $fx['branch']->id]));
    $second = dptcVisit($fx);
    $handle = dptcCompeting([['kind' => 'record', 'visit' => $second->id, 'actor' => $fx['doctorUser']->id, 'data' => dptcData($fx['scaling'])]]);
    $race = dptcRace(fn () => dptcSvc()->declare($second->fresh(), RmeTreatmentDeclaration::TYPE_NO_TREATMENT, $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::HAS_ACTIVE_TREATMENTS)
        ->and(RmeTreatmentDeclaration::query()->where('clinic_visit_id', $second->id)->value('declaration_type'))->toBe(RmeTreatmentDeclaration::TYPE_HAS_TREATMENT);
});

// ─── E / F: authority changing under the write ───────────────────────────────

it('DPT-RACE-E a room change committed behind the visit lock is seen after the lock — no write from the old room', function () {
    $fx = dptcFixture();
    $otherRoom = ClinicRoom::factory()->create(['branch_id' => $fx['branch']->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $handle = dptcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_clinic_visits WHERE id = ? FOR UPDATE', 'bindings' => [$fx['visit']->id]],
        ['kind' => 'statement', 'sql' => 'UPDATE trx_user_online_contexts SET clinic_room_id = ? WHERE user_id = ?', 'bindings' => [$otherRoom->id, $fx['doctorUser']->id]],
    ]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::ACTOR_NOT_AUTHORIZED)
        ->and(dptcRows($fx['visit']))->toBe(0);
});

it('DPT-RACE-F an access revocation committed behind the visit lock refuses the write', function () {
    $fx = dptcFixture();
    $handle = dptcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_clinic_visits WHERE id = ? FOR UPDATE', 'bindings' => [$fx['visit']->id]],
        ['kind' => 'statement', 'sql' => 'UPDATE users SET is_active = false WHERE id = ?', 'bindings' => [$fx['doctorUser']->id]],
    ]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::ACTOR_NOT_AUTHORIZED)
        ->and(dptcRows($fx['visit']))->toBe(0);
});

// ─── D / I: patient merge, master data ──────────────────────────────────────

it('DPT-RACE-D a save never deadlocks with a merge lock on the patient, and merge activity detection watches both tables', function () {
    $fx = dptcFixture();
    $handle = dptcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM mst_patients WHERE id = ? FOR UPDATE', 'bindings' => [$fx['patient']->id]],
    ]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']));
    dptcJoin($handle);

    // The merge refuses a patient with a live encounter (pre-existing guard), and
    // a post-merge write is visible to its activity detection.
    expect($race['refusal'])->toBeNull()
        ->and(dptcRows($fx['visit']))->toBe(1)
        ->and(PatientMergeOwnershipRepository::ACTIVE_ENCOUNTER_STATUSES)->toContain(ClinicVisit::STATUS_IN_PROGRESS)
        ->and(array_keys(PatientMergeOwnershipRepository::CHILD_TABLES))->toContain('trx_rme_performed_treatments', 'trx_rme_treatment_declarations');
});

it('DPT-RACE-I a master-data update racing a save never rewrites the snapshot the save took', function () {
    $fx = dptcFixture();
    $originalName = $fx['scaling']->name;
    $handle = dptcCompeting([
        ['kind' => 'statement', 'sql' => "UPDATE mst_treatments SET name = 'Diganti Bersamaan' WHERE id = ?", 'bindings' => [$fx['scaling']->id]],
    ]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']));
    dptcJoin($handle);

    $row = RmePerformedTreatment::query()->where('clinic_visit_id', $fx['visit']->id)->sole();

    expect($race['refusal'])->toBeNull()
        ->and($row->treatment_name_snapshot)->toBe($originalName)
        ->and(Treatment::query()->find($fx['scaling']->id)->name)->toBe('Diganti Bersamaan');
});

// ─── M: the encounter becoming ambiguous behind the lock (security review LOW-1) ─

it('DPT-RACE-M a second encounter opened behind the visit lock is seen after the lock — the write fails closed', function () {
    $fx = dptcFixture();
    $waiting = Model::withoutEvents(fn () => ClinicVisit::factory()->create([
        'branch_id' => $fx['branch']->id, 'clinic_id' => $fx['clinic']->id, 'patient_id' => $fx['patient']->id,
        'doctor_id' => $fx['doctor']->id, 'clinic_room_id' => $fx['room']->id,
        'status' => ClinicVisit::STATUS_WAITING, 'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'visit_number' => 'VIS-DC-'.uniqid(), 'visit_date' => clinicalToday()->toDateString(),
    ]));
    $handle = dptcCompeting([
        ['kind' => 'select', 'sql' => 'SELECT id FROM trx_clinic_visits WHERE id = ? FOR UPDATE', 'bindings' => [$fx['visit']->id]],
        ['kind' => 'statement', 'sql' => 'UPDATE trx_clinic_visits SET status = ? WHERE id = ?', 'bindings' => [ClinicVisit::STATUS_IN_PROGRESS, $waiting->id]],
    ]);

    test()->actingAs($fx['doctorUser']);
    $race = dptcRace(fn () => dptcSvc()->record($fx['visit']->fresh(), dptcData($fx['scaling']), $fx['doctorUser']));
    dptcJoin($handle);

    expect($race['waited'])->toBeGreaterThan(1.0)
        ->and($race['refusal'])->toContain(RmePerformedTreatmentRefusal::ENCOUNTER_NOT_ACTIVE)
        ->and(dptcRows($fx['visit']))->toBe(0);
});
