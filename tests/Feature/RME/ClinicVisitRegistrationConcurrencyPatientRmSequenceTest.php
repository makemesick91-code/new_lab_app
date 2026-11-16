<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §1) — two
| registrations in one branch-year never receive the same Nomor RM
|--------------------------------------------------------------------------
|
| The allocator locks the branch-year counter row FOR UPDATE inside the
| registration transaction, so a second allocation in the same branch-year waits
| until the first commits and then takes the NEXT number. The first-of-year path
| creates the row with INSERT … ON CONFLICT DO NOTHING and then locks it, so two
| first allocations serialise on the unique key the same way.
|
| Only observable on PostgreSQL (SQLite has no row locks), so the suite SKIPS
| elsewhere rather than passing. Rows are written over a separate, committing
| connection and removed after each test (the ClinicVisitRegistrationConcurrency
| pattern). The waiting side runs with `lock_timeout`, so "blocked" is a
| deterministic 55P03 rather than a hang.
|
| Selected by the existing `ClinicVisitRegistrationConcurrency` critical-gate token
| in both runner variants and declared in critical_gate_mandatory_suites (CG-19).
| Helper prefix `cvrs` is unique.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Interfaces\PatientRmSequenceRepositoryInterface;
use App\Modules\Patient\Services\PatientRmNumberAllocator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

const CVRS_WRITER = 'cvrs_sequence_writer';
const CVRS_PROBE = 'cvrs_sequence_probe';
const CVRS_YEAR = 2033;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Row-lock behaviour is only observable on PostgreSQL.');
    }

    $this->cvrsDefault = config('database.default');
    $base = config('database.connections.'.$this->cvrsDefault);
    config()->set('database.connections.'.CVRS_WRITER, $base);
    config()->set('database.connections.'.CVRS_PROBE, $base);
    DB::purge(CVRS_WRITER);
    DB::purge(CVRS_PROBE);
    config()->set('database.default', CVRS_WRITER);

    $suffix = strtoupper(substr(uniqid(), -4));
    $this->branchId = (int) DB::connection(CVRS_WRITER)->table('mst_branches')->insertGetId([
        'code' => 'RS'.$suffix,
        'name' => 'Cabang Urutan RM '.$suffix,
        'is_active' => true,
        'is_rme_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    if (! config()->has('database.connections.'.CVRS_WRITER)) {
        return;
    }

    foreach ([CVRS_PROBE, CVRS_WRITER] as $name) {
        while (DB::connection($name)->transactionLevel() > 0) {
            DB::connection($name)->rollBack();
        }
    }

    $w = DB::connection(CVRS_WRITER);
    $w->table('mst_patient_rm_sequences')->where('branch_id', $this->branchId ?? 0)->delete();
    $w->table('mst_branches')->where('id', $this->branchId ?? 0)->delete();

    config()->set('database.default', $this->cvrsDefault);
    DB::purge(CVRS_WRITER);
    DB::purge(CVRS_PROBE);
});

function cvrsBranch(): Branch
{
    return Branch::on(CVRS_WRITER)->findOrFail(test()->branchId);
}

/** Allocate on the given connection, inside a transaction the caller controls. */
function cvrsAllocateOn(string $connection): string
{
    config()->set('database.default', $connection);

    return app(PatientRmNumberAllocator::class)->allocate(cvrsBranch(), CVRS_YEAR);
}

function cvrsCounterRowBlocked(int $branchId): bool
{
    DB::purge(CVRS_PROBE);

    try {
        DB::connection(CVRS_PROBE)->transaction(fn () => DB::connection(CVRS_PROBE)->select(
            'SELECT id FROM mst_patient_rm_sequences WHERE branch_id = ? AND year = ? FOR UPDATE NOWAIT',
            [$branchId, CVRS_YEAR],
        ));
    } catch (QueryException $e) {
        return str_contains($e->getMessage(), '55P03') || str_contains(strtolower($e->getMessage()), 'could not obtain lock');
    }

    return false;
}

function cvrsIsLockTimeout(QueryException $e): bool
{
    return str_contains($e->getMessage(), '55P03') || str_contains(strtolower($e->getMessage()), 'lock timeout');
}

it('lets an idle counter row be locked (sanity: the probe is not always blocked)', function (): void {
    DB::connection(CVRS_WRITER)->table('mst_patient_rm_sequences')->insert([
        'branch_id' => $this->branchId, 'year' => CVRS_YEAR, 'last_number' => 0,
        'seed_source' => 'command', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(cvrsCounterRowBlocked($this->branchId))->toBeFalse();
});

it('holds the counter row FOR UPDATE until the registration commits, and the next registration takes the next number', function (): void {
    DB::connection(CVRS_WRITER)->table('mst_patient_rm_sequences')->insert([
        'branch_id' => $this->branchId, 'year' => CVRS_YEAR, 'last_number' => 41,
        'seed_source' => 'command', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $code = cvrsBranch()->code;

    DB::connection(CVRS_WRITER)->beginTransaction();
    $first = cvrsAllocateOn(CVRS_WRITER);
    expect($first)->toBe("DG-{$code}-".CVRS_YEAR.'-0042')
        ->and(cvrsCounterRowBlocked($this->branchId))->toBeTrue();

    // A concurrent registration in the same branch-year waits; it never reads 41.
    DB::purge(CVRS_PROBE);
    DB::connection(CVRS_PROBE)->beginTransaction();
    DB::connection(CVRS_PROBE)->statement("SET LOCAL lock_timeout = '300ms'");
    $waited = false;
    try {
        cvrsAllocateOn(CVRS_PROBE);
    } catch (QueryException $e) {
        $waited = cvrsIsLockTimeout($e);
    }
    DB::connection(CVRS_PROBE)->rollBack();
    expect($waited)->toBeTrue();

    DB::connection(CVRS_WRITER)->commit();

    DB::connection(CVRS_PROBE)->beginTransaction();
    $second = cvrsAllocateOn(CVRS_PROBE);
    DB::connection(CVRS_PROBE)->commit();

    expect($second)->toBe("DG-{$code}-".CVRS_YEAR.'-0043');
});

it('serialises two first-of-year registrations on the new counter row', function (): void {
    $code = cvrsBranch()->code;

    DB::connection(CVRS_WRITER)->beginTransaction();
    $first = cvrsAllocateOn(CVRS_WRITER);
    expect($first)->toBe("DG-{$code}-".CVRS_YEAR.'-0001');

    DB::purge(CVRS_PROBE);
    DB::connection(CVRS_PROBE)->beginTransaction();
    DB::connection(CVRS_PROBE)->statement("SET LOCAL lock_timeout = '300ms'");
    $waited = false;
    try {
        cvrsAllocateOn(CVRS_PROBE);
    } catch (QueryException $e) {
        $waited = cvrsIsLockTimeout($e);
    }
    DB::connection(CVRS_PROBE)->rollBack();
    expect($waited)->toBeTrue();

    DB::connection(CVRS_WRITER)->commit();

    DB::connection(CVRS_PROBE)->beginTransaction();
    $second = cvrsAllocateOn(CVRS_PROBE);
    DB::connection(CVRS_PROBE)->commit();

    expect($second)->toBe("DG-{$code}-".CVRS_YEAR.'-0002')
        ->and(DB::connection(CVRS_WRITER)->table('mst_patient_rm_sequences')->where('branch_id', $this->branchId)->count())->toBe(1);
});

it('locks an existing counter row before reading it, not only when it is written', function (): void {
    // Without FOR UPDATE on the read, two registrations would both read 41 and the
    // later UPDATE would merely queue behind the first — both issuing 0042. The
    // lock must therefore already be held right after the counter is read.
    DB::connection(CVRS_WRITER)->table('mst_patient_rm_sequences')->insert([
        'branch_id' => $this->branchId, 'year' => CVRS_YEAR, 'last_number' => 41,
        'seed_source' => 'command', 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::connection(CVRS_WRITER)->beginTransaction();
    config()->set('database.default', CVRS_WRITER);
    $row = app(PatientRmSequenceRepositoryInterface::class)
        ->lockOrCreate($this->branchId, CVRS_YEAR, fn (): int => 0);

    expect((int) $row->last_number)->toBe(41)
        ->and(cvrsCounterRowBlocked($this->branchId))->toBeTrue();

    DB::connection(CVRS_WRITER)->rollBack();
    expect(cvrsCounterRowBlocked($this->branchId))->toBeFalse();
});

it('reads the counter FOR UPDATE before it writes it, inside one allocation', function (): void {
    DB::connection(CVRS_WRITER)->table('mst_patient_rm_sequences')->insert([
        'branch_id' => $this->branchId, 'year' => CVRS_YEAR, 'last_number' => 7,
        'seed_source' => 'command', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $statements = [];
    DB::connection(CVRS_WRITER)->listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    DB::connection(CVRS_WRITER)->beginTransaction();
    cvrsAllocateOn(CVRS_WRITER);
    DB::connection(CVRS_WRITER)->rollBack();

    $read = collect($statements)->search(fn (string $s) => str_contains($s, 'mst_patient_rm_sequences') && str_starts_with(ltrim($s), 'select'));
    $write = collect($statements)->search(fn (string $s) => str_contains($s, 'mst_patient_rm_sequences') && str_starts_with(ltrim($s), 'update'));

    expect($read)->not->toBeFalse()
        ->and($write)->not->toBeFalse()
        ->and($read)->toBeLessThan($write)
        ->and($statements[$read])->toContain('for update');
});
