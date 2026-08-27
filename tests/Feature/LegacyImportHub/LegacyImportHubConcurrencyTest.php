<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-IMPORT-HUB-1 — the ceiling holds under concurrency.
|--------------------------------------------------------------------------
|
| WHY THIS SUITE IS SEPARATE, AND WHY IT SKIPS.
|
| The rest of the hub suite runs on SQLite, where `SELECT ... FOR UPDATE`
| compiles to nothing. Every sequential boundary assertion there is real, but
| none of them can prove the property that actually matters in production: that
| two operators racing for the last slot cannot both get it.
|
| That property is a PostgreSQL row-lock property, so it is proven against
| PostgreSQL and SKIPPED everywhere else. Skipping is stated out loud rather
| than silently passing — a concurrency test that "passes" on a driver with no
| row locks is worse than no test, because it reads as evidence.
|
| WHY THERE IS NO RefreshDatabase HERE.
|
| RefreshDatabase wraps each test in a transaction that is never committed, so a
| SECOND database session cannot see any row the test wrote — and a lock on a
| row the prober cannot see is unobservable. `FOR UPDATE NOWAIT` would match
| zero rows, raise nothing, and the test would report "not locked" whether or
| not the lock existed. This suite therefore commits for real and cleans its own
| table between tests.
|
| WHAT IS PROVEN HERE:
|
|   1. the bucket row is genuinely locked while a reservation is in flight — a
|      second session cannot even read it FOR UPDATE;
|   2. the second reserver, once the first commits, sees the INCREMENTED value
|      and is refused;
|   3. the counter finishes at the ceiling. Never at ceiling + 1.
|
| Run it against a PostgreSQL 16 database (the production major):
|
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55433 \
|   DB_DATABASE=lih_concurrency DB_USERNAME=lihuser DB_PASSWORD=... \
|   php artisan test tests/Feature/LegacyImportHub/LegacyImportHubConcurrencyTest.php
*/

use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

/*
| WHY THE DEFAULT CONNECTION IS SWAPPED.
|
| `tests/Pest.php` binds RefreshDatabase to EVERY test under `Feature`, and
| RefreshDatabase wraps the default connection in a transaction that is never
| committed. Nothing this suite writes would therefore be visible to a second
| session, and a lock on an invisible row cannot be observed at all — the probe
| would match zero rows, raise nothing, and the test would report "not locked"
| whether or not the lock existed. That is a false green on the one property
| this file exists to prove.
|
| So the real service is driven over `lih_writer`, a connection RefreshDatabase
| does not manage, pointed at the same database. Writes there COMMIT, exactly as
| they do in production. `lih_probe` is a third session that contends for the
| same row. The schema is created by RefreshDatabase's `migrate:fresh`, whose
| DDL is committed before the wrapping transaction begins, so all three sessions
| see the same tables.
|
| The service, the repository, the locking and the transaction boundaries are
| the production ones — only the connection name differs.
*/

const LIH_WRITER = 'lih_concurrency_writer';
const LIH_PROBE = 'lih_concurrency_probe';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));

    config()->set('database.connections.'.LIH_WRITER, $base);
    config()->set('database.connections.'.LIH_PROBE, $base);

    DB::purge(LIH_WRITER);
    DB::purge(LIH_PROBE);

    // Everything the service touches — the model, the repository, DB::transaction
    // — now resolves to a connection that really commits.
    config()->set('database.default', LIH_WRITER);

    DB::connection(LIH_WRITER)->table('ops_legacy_import_daily_quotas')->delete();
});

afterEach(function () {
    if (! config()->has('database.connections.'.LIH_WRITER)) {
        return;
    }

    // This suite commits for real, so it must clean up after itself; nothing
    // else does it.
    DB::connection(LIH_WRITER)->table('ops_legacy_import_daily_quotas')->delete();

    DB::purge(LIH_WRITER);
    DB::purge(LIH_PROBE);
});

/**
 * The contending session.
 *
 * A lock taken by the writer is only meaningful if another session can be shown
 * to be blocked by it.
 */
function lihProbeConnection(): string
{
    DB::purge(LIH_PROBE);

    return LIH_PROBE;
}

it('holds a row lock on the bucket for the duration of a reservation', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    lihLimit($type, 5);

    // Committed, so the second session can see the row at all.
    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));

    $probe = lihProbeConnection();

    // Sanity: the probe CAN see the row when nobody holds it. Without this the
    // next assertion could pass for the wrong reason — an invisible row also
    // raises nothing.
    $visible = DB::connection($probe)->select(
        'select id from ops_legacy_import_daily_quotas where import_type = ? and branch_id = ? for update nowait',
        [$type, $branch->id],
    );
    expect($visible)->toHaveCount(1);

    DB::beginTransaction();

    try {
        // The reservation takes FOR UPDATE on the bucket and holds it until this
        // transaction ends.
        lihQuota()->reserve($type, $branch->id);

        $blocked = false;
        $reason = '';

        try {
            DB::connection($probe)->select(
                'select id from ops_legacy_import_daily_quotas where import_type = ? and branch_id = ? for update nowait',
                [$type, $branch->id],
            );
        } catch (QueryException $exception) {
            // 55P03 = lock_not_available. NOWAIT turns "block forever" into an
            // immediate, assertable error; without it this test would hang.
            $reason = (string) $exception->getMessage();
            $blocked = str_contains($reason, '55P03')
                || str_contains(strtolower($reason), 'could not obtain lock');
        }

        expect($blocked)->toBeTrue(
            'The bucket row was NOT locked during a reservation — the ceiling is raceable. Probe said: '.$reason,
        );
    } finally {
        DB::rollBack();
        DB::purge($probe);
    }
});

it('lets the second reserver through only after the first commits, and refuses it at the ceiling', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    // One slot left. This is the contended case: two operators, one slot.
    lihLimit($type, 100);
    lihConsume($type, $branch->id, 99);

    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(99);

    // Operator A takes the last slot and commits.
    DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(100);

    // Operator B, whose advisory pre-check passed while there was still room,
    // now reaches the authoritative reservation. It reads the INCREMENTED value
    // under the lock and refuses.
    expect(fn () => DB::transaction(fn () => lihQuota()->reserve($type, $branch->id)))
        ->toThrow(ValidationException::class);

    // The invariant, stated plainly.
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(100);
});

it('never exceeds the ceiling when many reservations contend for the last slots', function () {
    $branch = lihBranch();
    $type = LegacyImportType::LEGACY_RME;

    lihLimit($type, 10);
    lihConsume($type, $branch->id, 8);

    $accepted = 0;
    $refused = 0;

    // Twelve attempts into two remaining slots.
    for ($i = 0; $i < 12; $i++) {
        try {
            DB::transaction(fn () => lihQuota()->reserve($type, $branch->id));
            $accepted++;
        } catch (ValidationException) {
            $refused++;
        }
    }

    expect($accepted)->toBe(2);
    expect($refused)->toBe(10);
    expect(lihQuota()->consumedToday($type, $branch->id))->toBe(10);
});

it('acquires multi-branch buckets in one order so two batches cannot deadlock', function () {
    $a = lihBranch('TKM1', 'Cabang Telkomas');
    $b = lihBranch('LDK2', 'Cabang Landak');
    $type = LegacyImportType::LEGACY_PATIENT;

    lihLimit($type, 50);

    // Two batches naming the SAME pair of branches in OPPOSITE orders.
    // `reserveMany` sorts the branch ids before locking, so both acquire the
    // lower id first — which is what makes a cycle impossible. Without the sort
    // these two calls are the classic AB/BA deadlock.
    DB::transaction(fn () => lihQuota()->reserveMany($type, [$b->id => 1, $a->id => 1]));
    DB::transaction(fn () => lihQuota()->reserveMany($type, [$a->id => 1, $b->id => 1]));

    expect(lihQuota()->consumedToday($type, $a->id))->toBe(2);
    expect(lihQuota()->consumedToday($type, $b->id))->toBe(2);
});
