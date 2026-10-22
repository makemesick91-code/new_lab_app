<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — the prior-visit row lock
|--------------------------------------------------------------------------
|
| Visit registration validates the prior visit (same patient, live, RME branch)
| and then inserts a visit that references it. Between the two, a concurrent
| soft-delete of the prior visit, or a merge re-pointing it to another patient,
| would leave the new Kontrol linked to a row that no longer satisfies the rule.
|
| The guard therefore reads the prior visit FOR SHARE inside the registering
| transaction. The property is only observable on PostgreSQL (SQLite compiles
| sharedLock() to nothing), so this suite SKIPS elsewhere rather than passing.
|
| WHY THE PROBE IS `FOR NO KEY UPDATE`, NOT `FOR UPDATE`. The insert itself takes
| FOR KEY SHARE on the referenced row through the foreign key, and FOR UPDATE
| conflicts with that — so a FOR UPDATE probe would be blocked even if the guard
| took no lock at all, and the test would pass vacuously. A soft-delete or a
| patient re-point is an ordinary UPDATE (FOR NO KEY UPDATE): it does NOT
| conflict with the foreign key's KEY SHARE, but DOES conflict with the guard's
| FOR SHARE. That is exactly the lock this test must be able to see.
|
| Rows are written over a separate, committing connection and removed after each
| test (same pattern as PatientMergeConcurrencyTest).
*/

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitRegistrationGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const CVRC_WRITER = 'cvrc_concurrency_writer';
const CVRC_PROBE = 'cvrc_concurrency_probe';

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row-lock behaviour is only observable on PostgreSQL; SQLite compiles sharedLock() to nothing.');
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.CVRC_WRITER, $base);
    config()->set('database.connections.'.CVRC_PROBE, $base);
    DB::purge(CVRC_WRITER);
    DB::purge(CVRC_PROBE);
    config()->set('database.default', CVRC_WRITER);

    $w = DB::connection(CVRC_WRITER);
    $suffix = uniqid();

    $this->branchId = (int) $w->table('mst_branches')->insertGetId([
        'code' => 'CV'.substr($suffix, -4),
        'name' => 'Cabang Konkurensi '.$suffix,
        'is_active' => true,
        'is_rme_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->userId = (int) $w->table('users')->insertGetId([
        'name' => 'Registrar '.$suffix,
        'email' => 'cvrc-'.$suffix.'@example.test',
        'password' => bcrypt('password'),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->patientId = (int) $w->table('mst_patients')->insertGetId([
        'name' => 'Pasien Konkurensi',
        'medical_record_number' => 'CVRC-'.$suffix,
        'branch_id' => $this->branchId,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->priorId = (int) $w->table('trx_clinic_visits')->insertGetId([
        'visit_number' => 'VIS-CVRC-'.$suffix,
        'branch_id' => $this->branchId,
        'patient_id' => $this->patientId,
        'visit_date' => now()->toDateString(),
        'queue_number' => 1,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        'created_by' => $this->userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    if (! config()->has('database.connections.'.CVRC_WRITER)) {
        return;
    }

    $w = DB::connection(CVRC_WRITER);
    $w->table('trx_clinic_visits')->where('patient_id', $this->patientId ?? 0)->delete();
    $w->table('mst_patients')->where('id', $this->patientId ?? 0)->delete();
    $w->table('users')->where('id', $this->userId ?? 0)->delete();
    $w->table('mst_branches')->where('id', $this->branchId ?? 0)->delete();

    DB::purge(CVRC_WRITER);
    DB::purge(CVRC_PROBE);
});

function cvrcProbeBlocked(string $lockClause, int $visitId): bool
{
    DB::purge(CVRC_PROBE);

    try {
        DB::connection(CVRC_PROBE)->transaction(fn () => DB::connection(CVRC_PROBE)
            ->select("SELECT id FROM trx_clinic_visits WHERE id = ? {$lockClause} NOWAIT", [$visitId]));
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), '55P03') || str_contains(strtolower($e->getMessage()), 'could not obtain lock');
    }

    return false;
}

function cvrcLink(int $patientId, int $priorId): array
{
    return [
        'patient_mode' => 'existing',
        'patient_id' => $patientId,
        'visit_type' => ClinicVisit::VISIT_TYPE_CONTROL,
        'follow_up_of_visit_id' => $priorId,
    ];
}

it('lets an unlocked prior visit be updated (sanity: the probe is not always blocked)', function (): void {
    expect(cvrcProbeBlocked('FOR NO KEY UPDATE', $this->priorId))->toBeFalse();
});

it('holds the prior visit FOR SHARE while a registration validates it, so it cannot be soft-deleted or re-pointed underneath', function (): void {
    DB::connection(CVRC_WRITER)->transaction(function (): void {
        app(ClinicVisitRegistrationGuard::class)->assertFollowUpLink(cvrcLink($this->patientId, $this->priorId), lock: true);

        // A soft-delete / patient re-point (FOR NO KEY UPDATE) must wait.
        expect(cvrcProbeBlocked('FOR NO KEY UPDATE', $this->priorId))->toBeTrue();

        // Readers and the foreign-key check are not blocked (FOR KEY SHARE).
        expect(cvrcProbeBlocked('FOR KEY SHARE', $this->priorId))->toBeFalse();
    });

    // Released when the registering transaction ends.
    expect(cvrcProbeBlocked('FOR NO KEY UPDATE', $this->priorId))->toBeFalse();
});

it('takes no lock on the prior visit when a caller only asks for a verdict (the form)', function (): void {
    DB::connection(CVRC_WRITER)->transaction(function (): void {
        expect(app(ClinicVisitRegistrationGuard::class)->followUpViolation(cvrcLink($this->patientId, $this->priorId)))->toBeNull();

        expect(cvrcProbeBlocked('FOR NO KEY UPDATE', $this->priorId))->toBeFalse();
    });
});

it('refuses a prior visit that a committed concurrent soft-delete removed before the lock was taken', function (): void {
    DB::connection(CVRC_WRITER)->table('trx_clinic_visits')->where('id', $this->priorId)->update(['deleted_at' => now()]);

    DB::connection(CVRC_WRITER)->transaction(function (): void {
        expect(app(ClinicVisitRegistrationGuard::class)->followUpViolation(cvrcLink($this->patientId, $this->priorId), lock: true))
            ->toBe(['follow_up_of_visit_id', 'Kunjungan sebelumnya harus berasal dari cabang RME yang valid.']);
    });
});
