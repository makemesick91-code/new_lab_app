<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-6 — clinical evidence writes are serialised
|--------------------------------------------------------------------------
|
| P6-HW-04  A handwriting save locks its medical-record row FOR NO KEY UPDATE
|           before choosing the page. Without it, two concurrent first saves of
|           Page 1 each found "no row" and inserted one (that table has no
|           unique index), and two concurrent saves of a new Page N raced into
|           UNIQUE(medical_record_id, page_number) and answered HTTP 500.
| P6-RX-02  A prescription create locks its visit row FOR NO KEY UPDATE before
|           the "already exists" check, so a double submit answers that check
|           instead of racing UNIQUE(clinic_visit_id) into an HTTP 500.
|
| WHY THE PROBE IS `FOR NO KEY UPDATE`. The page/prescription insert itself
| takes FOR KEY SHARE on the parent row through its foreign key; FOR UPDATE
| conflicts with that, so a FOR UPDATE probe would be blocked even with no lock
| taken and the test would pass vacuously. FOR NO KEY UPDATE does not conflict
| with KEY SHARE, but does conflict with the lock the code must take.
|
| Only observable on PostgreSQL (SQLite compiles row locks to nothing), so the
| suite SKIPS elsewhere rather than passing. Rows are written over a separate,
| committing connection and removed after each test (ClinicVisitRegistration-
| ConcurrencyTest pattern).
*/

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Services\MedicalRecordHandwritingService;
use App\Modules\Prescription\Services\RmePrescriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

const P6L_WRITER = 'p6_evidence_lock_writer';
const P6L_PROBE = 'p6_evidence_lock_probe';

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row-lock behaviour is only observable on PostgreSQL; SQLite compiles row locks to nothing.');
    }

    Storage::fake('clinical_evidence');

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.P6L_WRITER, $base);
    config()->set('database.connections.'.P6L_PROBE, $base);
    DB::purge(P6L_WRITER);
    DB::purge(P6L_PROBE);
    config()->set('database.default', P6L_WRITER);

    $w = DB::connection(P6L_WRITER);
    $suffix = uniqid();

    $this->branchId = (int) $w->table('mst_branches')->insertGetId([
        'code' => 'P6'.substr($suffix, -4),
        'name' => 'Cabang Integritas '.$suffix,
        'is_active' => true,
        'is_rme_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->userId = (int) $w->table('users')->insertGetId([
        'name' => 'Penulis '.$suffix,
        'email' => 'p6l-'.$suffix.'@example.test',
        'password' => bcrypt('password'),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->doctorId = (int) $w->table('mst_doctors')->insertGetId([
        'code' => 'P6D'.substr($suffix, -5),
        'name' => 'drg. Integritas',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->patientId = (int) $w->table('mst_patients')->insertGetId([
        'name' => 'Pasien Integritas',
        'medical_record_number' => 'P6L-'.$suffix,
        'branch_id' => $this->branchId,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->visitId = (int) $w->table('trx_clinic_visits')->insertGetId([
        'visit_number' => 'VIS-P6L-'.$suffix,
        'branch_id' => $this->branchId,
        'patient_id' => $this->patientId,
        'doctor_id' => $this->doctorId,
        'visit_date' => now()->toDateString(),
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'created_by' => $this->userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->recordId = (int) $w->table('trx_medical_records')->insertGetId([
        'clinic_visit_id' => $this->visitId,
        'branch_id' => $this->branchId,
        'patient_id' => $this->patientId,
        'doctor_id' => $this->doctorId,
        'status' => MedicalRecord::STATUS_DRAFT,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    if (! config()->has('database.connections.'.P6L_WRITER)) {
        return;
    }

    p6lRevokeClinicalAuthor($this);

    $w = DB::connection(P6L_WRITER);
    $w->table('trx_rme_prescriptions')->where('clinic_visit_id', $this->visitId ?? 0)->delete();
    $w->table('trx_medical_record_handwriting_pages')->where('medical_record_id', $this->recordId ?? 0)->delete();
    $w->table('trx_medical_record_handwritings')->where('medical_record_id', $this->recordId ?? 0)->delete();
    $w->table('trx_medical_records')->where('id', $this->recordId ?? 0)->delete();
    $w->table('trx_clinic_visits')->where('id', $this->visitId ?? 0)->delete();
    $w->table('mst_patients')->where('id', $this->patientId ?? 0)->delete();
    $w->table('mst_doctors')->where('id', $this->doctorId ?? 0)->delete();
    $w->table('users')->where('id', $this->userId ?? 0)->delete();
    $w->table('mst_branches')->where('id', $this->branchId ?? 0)->delete();

    DB::purge(P6L_WRITER);
    DB::purge(P6L_PROBE);
});

/**
 * DMS-RME-REVISION-PROGRAM-8B / D7 — prescription authoring needs the write
 * permission and a clinical role, and this suite runs on a COMMITTING
 * connection (no RefreshDatabase rollback reaches it). So the role, the
 * permission and the assignment are created here and removed by
 * p6lRevokeClinicalAuthor() — only the rows this suite created.
 */
function p6lGrantClinicalAuthor(object $test, User $user): void
{
    $role = Role::findOrCreate('Supervisor RME', 'web');
    $permission = Permission::findOrCreate('manage_clinic_visits', 'web');
    $test->p6lCreatedRoleId = $role->wasRecentlyCreated ? (int) $role->id : null;
    $test->p6lCreatedPermissionId = $permission->wasRecentlyCreated ? (int) $permission->id : null;

    if (! $role->hasPermissionTo($permission)) {
        $role->givePermissionTo($permission);
    }

    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function p6lRevokeClinicalAuthor(object $test): void
{
    $w = DB::connection(P6L_WRITER);
    $w->table('model_has_roles')->where('model_id', $test->userId ?? 0)->where('model_type', User::class)->delete();

    if (($test->p6lCreatedRoleId ?? null) !== null) {
        $w->table('role_has_permissions')->where('role_id', $test->p6lCreatedRoleId)->delete();
        $w->table('roles')->where('id', $test->p6lCreatedRoleId)->delete();
    }

    if (($test->p6lCreatedPermissionId ?? null) !== null) {
        $w->table('role_has_permissions')->where('permission_id', $test->p6lCreatedPermissionId)->delete();
        $w->table('permissions')->where('id', $test->p6lCreatedPermissionId)->delete();
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function p6lProbeBlocked(string $table, int $id): bool
{
    DB::purge(P6L_PROBE);

    try {
        DB::connection(P6L_PROBE)->transaction(fn () => DB::connection(P6L_PROBE)
            ->select("SELECT id FROM {$table} WHERE id = ? FOR NO KEY UPDATE NOWAIT", [$id]));
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), '55P03') || str_contains(strtolower($e->getMessage()), 'could not obtain lock');
    }

    return false;
}

function p6lPng(int $seed): string
{
    $raw = '';
    for ($y = 0; $y < 4; $y++) {
        $raw .= "\0";
        for ($x = 0; $x < 4; $x++) {
            $raw .= chr(($seed * 37 + $x * 50) % 256).chr(($seed * 11 + $y * 60) % 256).chr(($x + $y) * 30 % 256).chr(255);
        }
    }
    $chunk = static fn (string $t, string $d): string => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 4, 4, 8, 6, 0, 0, 0)).$chunk('IDAT', (string) gzcompress($raw)).$chunk('IEND', '');
}

function p6lSaveHandwriting(object $test, int $page): array
{
    $record = MedicalRecord::query()->findOrFail($test->recordId);
    $visit = ClinicVisit::query()->findOrFail($test->visitId);

    return app(MedicalRecordHandwritingService::class)->save(
        fn (): array => [$record, $visit],
        $page,
        p6lPng($page),
        $test->userId,
    );
}

it('leaves the medical-record and visit rows lockable when nothing holds them (sanity: the probe is not always blocked)', function (): void {
    expect(p6lProbeBlocked('trx_medical_records', $this->recordId))->toBeFalse()
        ->and(p6lProbeBlocked('trx_clinic_visits', $this->visitId))->toBeFalse();
});

it('holds the medical-record row while a handwriting page is being saved', function (int $page): void {
    DB::connection(P6L_WRITER)->transaction(function () use ($page): void {
        p6lSaveHandwriting($this, $page);

        expect(p6lProbeBlocked('trx_medical_records', $this->recordId))->toBeTrue();
    });

    // Released on commit, and the save really happened.
    expect(p6lProbeBlocked('trx_medical_records', $this->recordId))->toBeFalse();
    $table = $page === 1 ? 'trx_medical_record_handwritings' : 'trx_medical_record_handwriting_pages';
    expect(DB::connection(P6L_WRITER)->table($table)->where('medical_record_id', $this->recordId)->count())->toBe(1);
})->with([
    'page 1 (no unique index)' => [1],
    'page 2 (unique per record)' => [2],
]);

// Sequential CONTROL: it passes with or without the lock. The race itself is
// proven by the lock-held probe above, not here.
it('keeps a repeated page-1 save to one row whose hash matches its object', function (): void {
    p6lSaveHandwriting($this, 1);
    p6lSaveHandwriting($this, 1);

    $rows = DB::connection(P6L_WRITER)->table('trx_medical_record_handwritings')->where('medical_record_id', $this->recordId)->get();
    expect($rows)->toHaveCount(1)
        ->and(hash('sha256', (string) Storage::disk('clinical_evidence')->get($rows[0]->handwriting_path)))
        ->toBe($rows[0]->handwriting_hash);
});

it('holds the visit row while a prescription is being created', function (): void {
    $visit = ClinicVisit::query()->findOrFail($this->visitId);
    $user = User::query()->findOrFail($this->userId);
    p6lGrantClinicalAuthor($this, $user);
    $png = 'data:image/png;base64,'.base64_encode(p6lPng(7));

    DB::connection(P6L_WRITER)->transaction(function () use ($visit, $user, $png): void {
        app(RmePrescriptionService::class)->create($visit, [
            'prescribed_by_name' => 'drg. Integritas',
            'prescription_date' => '2026-11-06',
            'patient_name_snapshot' => 'Pasien Integritas',
            'prescription_canvas_data' => $png,
            // DMS-RME-REVISION-PROGRAM-8C (rule 228 §12) — the author here is not the
            // visit's doctor, so it writes the content only; the row lock under
            // test is taken before the signature is ever considered.
        ], $user);

        expect(p6lProbeBlocked('trx_clinic_visits', $this->visitId))->toBeTrue();
    });

    expect(p6lProbeBlocked('trx_clinic_visits', $this->visitId))->toBeFalse()
        ->and(DB::connection(P6L_WRITER)->table('trx_rme_prescriptions')->where('clinic_visit_id', $this->visitId)->count())->toBe(1);
});

it('answers a second prescription create with the existing contract, not a database error', function (): void {
    $visit = ClinicVisit::query()->findOrFail($this->visitId);
    $user = User::query()->findOrFail($this->userId);
    p6lGrantClinicalAuthor($this, $user);
    $png = 'data:image/png;base64,'.base64_encode(p6lPng(8));
    $payload = [
        'prescribed_by_name' => 'drg. Integritas',
        'prescription_date' => '2026-11-06',
        'patient_name_snapshot' => 'Pasien Integritas',
        'prescription_canvas_data' => $png,
        // 8C (rule 228 §12) — content only: the author is not the visit's doctor.
    ];

    app(RmePrescriptionService::class)->create($visit, $payload, $user);
    $objectsAfterFirst = Storage::disk('clinical_evidence')->allFiles();

    // The "already exists" answer specifically, so a different refusal (the 8C
    // signer or completion lock) cannot satisfy this assertion.
    expect(fn () => app(RmePrescriptionService::class)->create($visit->fresh(), $payload, $user))
        ->toThrow(ValidationException::class, 'Resep untuk kunjungan ini sudah ada. Gunakan Edit Resep.');

    expect(Storage::disk('clinical_evidence')->allFiles())->toBe($objectsAfterFirst);
});
