<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-REVISION-PROGRAM-7 / R5-2 — registrations at one branch take turns
|--------------------------------------------------------------------------
|
| A registration numbers the visit (queue number and visit number) from the
| highest number already issued that day at that branch. The old reads were
| `... ORDER BY queue_number DESC LIMIT 1 FOR UPDATE` and
| `... WHERE visit_number LIKE prefix FOR UPDATE`. Neither serialised anything:
|
|  - on the day's FIRST registration there is no row to lock at all, so two
|    concurrent registrations both read "none" and both chose number 1;
|  - with rows present, the second reader waits on the top row and, once the
|    first commits, PostgreSQL re-checks THAT row and hands it back unchanged —
|    the new row is not in its snapshot — so it chose the same N+1 anyway.
|
| Either way UNIQUE(branch_id, visit_date, queue_number) refused the second
| insert and the second registration answered HTTP 500.
|
| The fix serialises the numbering per branch with a transaction-scoped
| PostgreSQL advisory lock, taken before either number is read and held until
| the registration commits. Advisory locks are server-global and ignore row
| visibility, so a separate PROBE connection can observe the lock held inside
| the test's own (RefreshDatabase) transaction. Only observable on PostgreSQL,
| so this suite SKIPS elsewhere rather than passing.
|
| THE KEY IS PINNED HERE ON PURPOSE. (classid 92001, objid = branch id). It
| must never be renumbered: during a rolling deploy an old and a new process
| would take different locks for the same branch and both proceed.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const P7_SEQUENCE_PROBE = 'p7_sequence_lock_probe';
const P7_SEQUENCE_CLASS_ID = 92_001;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Advisory locks exist only on PostgreSQL; SQLite serialises writers at the file level.');
    }

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    config()->set('database.connections.'.P7_SEQUENCE_PROBE, config('database.connections.'.config('database.default')));
    DB::purge(P7_SEQUENCE_PROBE);

    $this->branch = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->otherBranch = Branch::factory()->create(['code' => 'LDK2', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->doctor = Doctor::factory()->create();
    rmeMakeDoctorOnline($this->doctor, $this->branch);

    $this->manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);
    $this->actingAs($this->manager);
});

afterEach(function () {
    if (config()->has('database.connections.'.P7_SEQUENCE_PROBE)) {
        DB::connection(P7_SEQUENCE_PROBE)->rollBack(0);
        DB::purge(P7_SEQUENCE_PROBE);
    }
});

function p7RegisterAt(object $test, Branch $branch): ClinicVisit
{
    return app(ClinicVisitService::class)->create([
        'patient_mode' => 'existing',
        'patient_id' => Patient::factory()->create(['branch_id' => $branch->id])->id,
        'branch_id' => $branch->id,
        'doctor_id' => $test->doctor->id,
        'clinic_id' => null,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
    ], $test->manager);
}

/** Whether ANOTHER session could take the branch's sequence lock right now. */
function p7SequenceLockFree(int $branchId): bool
{
    $probe = DB::connection(P7_SEQUENCE_PROBE);
    $acquired = (bool) $probe->selectOne('select pg_try_advisory_lock(?, ?) as got', [P7_SEQUENCE_CLASS_ID, $branchId])->got;

    if ($acquired) {
        $probe->selectOne('select pg_advisory_unlock(?, ?) as released', [P7_SEQUENCE_CLASS_ID, $branchId]);
    }

    return $acquired;
}

it('holds the branch numbering lock while it reads the day\'s numbers and inserts the visit', function () {
    $observed = null;
    $otherBranchFree = null;

    DB::listen(function (QueryExecuted $query) use (&$observed, &$otherBranchFree): void {
        if ($observed !== null || $query->connectionName === P7_SEQUENCE_PROBE) {
            return;
        }

        $sql = strtolower(ltrim($query->sql));

        if (str_starts_with($sql, 'insert into "trx_clinic_visits"')) {
            $observed = ! p7SequenceLockFree((int) $this->branch->id);
            $otherBranchFree = p7SequenceLockFree((int) $this->otherBranch->id);
        }
    });

    $visit = p7RegisterAt($this, $this->branch);

    expect($visit->queue_number)->toBe(1)
        ->and($observed)->toBeTrue('another session could take the numbering lock while the visit was being inserted')
        ->and($otherBranchFree)->toBeTrue('the lock must be per branch: another branch was blocked');
});

it('takes the numbering lock before it reads either number', function () {
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        if ($query->connectionName !== P7_SEQUENCE_PROBE) {
            $statements[] = strtolower($query->sql);
        }
    });

    p7RegisterAt($this, $this->branch);

    $lockAt = collect($statements)->search(fn (string $s) => str_contains($s, 'pg_advisory_xact_lock'));
    $queueReadAt = collect($statements)->search(fn (string $s) => str_contains($s, 'trx_clinic_visits') && str_contains($s, 'order by "queue_number" desc'));
    $visitNumberReadAt = collect($statements)->search(fn (string $s) => str_contains($s, 'trx_clinic_visits') && str_contains($s, '"visit_number"::text like') || (str_contains($s, 'trx_clinic_visits') && str_contains($s, '"visit_number" like')));

    expect($lockAt)->not->toBeFalse('no numbering lock was taken')
        ->and($queueReadAt)->not->toBeFalse()
        ->and($visitNumberReadAt)->not->toBeFalse()
        ->and($lockAt)->toBeLessThan($queueReadAt)
        ->and($lockAt)->toBeLessThan($visitNumberReadAt);
});

it('waits for another registration at the same branch to finish numbering', function () {
    // The probe stands in for a concurrent registration that has taken the
    // branch lock and not yet committed. This registration must WAIT for it —
    // proven by a short lock_timeout expiring — instead of reading a number the
    // other one is about to use.
    $probe = DB::connection(P7_SEQUENCE_PROBE);
    $probe->beginTransaction();
    $probe->statement('select pg_advisory_xact_lock(?, ?)', [P7_SEQUENCE_CLASS_ID, (int) $this->branch->id]);

    DB::statement("set local lock_timeout = '400ms'");

    try {
        p7RegisterAt($this, $this->branch);
        $this->fail('The registration did not wait for the concurrent one holding the branch numbering lock.');
    } catch (QueryException $timedOut) {
        expect($timedOut->getCode())->toBe('55P03');
    } finally {
        $probe->rollBack();
    }

    expect(ClinicVisit::query()->where('branch_id', $this->branch->id)->count())->toBe(0);
});

it('never makes a registration at another branch wait', function () {
    rmeMakeDoctorOnline($this->doctor, $this->otherBranch);

    $probe = DB::connection(P7_SEQUENCE_PROBE);
    $probe->beginTransaction();
    $probe->statement('select pg_advisory_xact_lock(?, ?)', [P7_SEQUENCE_CLASS_ID, (int) $this->branch->id]);

    DB::statement("set local lock_timeout = '400ms'");

    try {
        $visit = p7RegisterAt($this, $this->otherBranch);
    } finally {
        $probe->rollBack();
    }

    expect($visit->branch_id)->toBe($this->otherBranch->id)
        ->and($visit->queue_number)->toBe(1);
});
