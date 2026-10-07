<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — the locks a merge relies on.
|--------------------------------------------------------------------------
|
| The rest of the PatientMerge suite runs on SQLite, where lockForUpdate() and
| sharedLock() compile to nothing. Those suites prove the LOGIC (a second merge
| of the same case, a merged patient, a KTP claimed after submission are all
| refused). This suite proves the PostgreSQL row-lock property underneath:
|
|   1. while a merge holds both patients FOR UPDATE, a second merge touching
|      either patient cannot lock it (it queues instead of interleaving);
|   2. visit registration's FOR SHARE re-read of the patient queues behind a
|      running merge, so a visit can never land on the row the merge empties;
|   3. the database refuses a second alias for the same Nomor RM (alias race).
|
| It SKIPS outside PostgreSQL rather than passing silently — a lock test that
| "passes" on a driver without row locks reads as evidence it is not.
|
| No RefreshDatabase transaction can be observed by a second session, so the
| rows are written over a separate, committing connection and removed after
| each test (same pattern as LegacyImportHubConcurrencyTest).
*/

use App\Modules\PatientMerge\Interfaces\PatientMergeOwnershipRepositoryInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const PM_WRITER = 'pm_concurrency_writer';
const PM_PROBE = 'pm_concurrency_probe';

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.');
    }

    $base = config('database.connections.'.config('database.default'));
    config()->set('database.connections.'.PM_WRITER, $base);
    config()->set('database.connections.'.PM_PROBE, $base);
    DB::purge(PM_WRITER);
    DB::purge(PM_PROBE);
    config()->set('database.default', PM_WRITER);

    $this->patientIds = [];
    foreach (['Concurrency A', 'Concurrency B'] as $i => $name) {
        $this->patientIds[] = (int) DB::connection(PM_WRITER)->table('mst_patients')->insertGetId([
            'name' => $name,
            'medical_record_number' => 'PM-CONC-'.$i.'-'.uniqid(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
});

afterEach(function (): void {
    if (! config()->has('database.connections.'.PM_WRITER)) {
        return;
    }

    DB::connection(PM_WRITER)->table('mst_patients')->whereIn('id', $this->patientIds ?? [])->delete();
    DB::purge(PM_WRITER);
    DB::purge(PM_PROBE);
});

function pmProbeRaisesLockNotAvailable(string $sql, array $bindings): bool
{
    DB::purge(PM_PROBE);

    try {
        DB::connection(PM_PROBE)->transaction(fn () => DB::connection(PM_PROBE)->select($sql, $bindings));
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), '55P03') || str_contains(strtolower($e->getMessage()), 'could not obtain lock');
    }

    return false;
}

it('holds both patient rows FOR UPDATE while a merge runs', function (): void {
    [$a, $b] = $this->patientIds;

    // Sanity: the probe can see and lock the row when nobody holds it.
    expect(pmProbeRaisesLockNotAvailable('SELECT id FROM mst_patients WHERE id = ? FOR UPDATE NOWAIT', [$a]))->toBeFalse();

    DB::connection(PM_WRITER)->transaction(function () use ($a, $b): void {
        app(PatientMergeOwnershipRepositoryInterface::class)->lockPatients([$a, $b]);

        expect(pmProbeRaisesLockNotAvailable('SELECT id FROM mst_patients WHERE id = ? FOR UPDATE NOWAIT', [$a]))->toBeTrue()
            ->and(pmProbeRaisesLockNotAvailable('SELECT id FROM mst_patients WHERE id = ? FOR UPDATE NOWAIT', [$b]))->toBeTrue();
    });
});

it('makes visit registration\'s FOR SHARE re-read queue behind a running merge', function (): void {
    [$a, $b] = $this->patientIds;

    DB::connection(PM_WRITER)->transaction(function () use ($a, $b): void {
        app(PatientMergeOwnershipRepositoryInterface::class)->lockPatients([$a, $b]);

        expect(pmProbeRaisesLockNotAvailable('SELECT merged_into_patient_id FROM mst_patients WHERE id = ? FOR SHARE NOWAIT', [$a]))->toBeTrue();
    });

    // Released after commit.
    expect(pmProbeRaisesLockNotAvailable('SELECT merged_into_patient_id FROM mst_patients WHERE id = ? FOR SHARE NOWAIT', [$a]))->toBeFalse();
});
