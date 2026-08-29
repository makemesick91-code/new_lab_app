<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the lock holds under concurrency.
|--------------------------------------------------------------------------
|
| WHY THIS SUITE IS SEPARATE, AND WHY IT SKIPS.
|
| The rest of the feature's suites run on SQLite, where `SELECT ... FOR UPDATE`
| compiles to nothing. Every sequential assertion there is real, but none of them
| can prove the properties that actually matter in production:
|
|   1. two sessions of the SAME user selecting DIFFERENT branches at the same
|      moment cannot both succeed, and cannot create two daily contexts;
|   2. two Super Admins approving the SAME request at the same moment apply the
|      switch exactly once;
|   3. a double-submitted change request cannot produce two PENDING rows.
|
| Those are PostgreSQL row-lock and unique-index properties, so they are proven
| against PostgreSQL and SKIPPED everywhere else. The skip is stated out loud
| rather than silently passing: a concurrency test that "passes" on a driver with
| no row locks is worse than no test, because it reads as evidence.
|
| WHY THERE IS NO RefreshDatabase HERE.
|
| RefreshDatabase wraps each test in a transaction that is never committed, so a
| SECOND database session cannot see any row the test wrote — and a lock on a row
| the prober cannot see is unobservable. This suite therefore commits for real
| and cleans its own tables between tests.
|
| The pattern, the connection swap and the NOWAIT probe are lifted deliberately
| from tests/Feature/LegacyImportHub/LegacyImportHubConcurrencyTest.php, which
| established it for exactly this reason.
|
| Run it against a PostgreSQL 16 database (the production major):
|
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55433 \
|   DB_DATABASE=dbc_concurrency DB_USERNAME=dbcuser DB_PASSWORD=... \
|   php artisan test tests/Feature/AccessControl/DailyBranchContextConcurrencyTest.php
*/

use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use App\Modules\RmeOnlineContext\Models\DailyBranchContext;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

const DBC_WRITER = 'dbc_concurrency_writer';
const DBC_PROBE = 'dbc_concurrency_probe';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Row-lock behaviour is only observable on PostgreSQL; SQLite compiles lockForUpdate() to nothing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));

    config()->set('database.connections.'.DBC_WRITER, $base);
    config()->set('database.connections.'.DBC_PROBE, $base);

    DB::purge(DBC_WRITER);
    DB::purge(DBC_PROBE);

    // The service, the repositories and DB::transaction now resolve to a
    // connection that really commits — exactly as they do in production.
    config()->set('database.default', DBC_WRITER);

    DB::connection(DBC_WRITER)->table('trx_branch_change_requests')->delete();
    DB::connection(DBC_WRITER)->table('trx_daily_branch_contexts')->delete();

    seedAccessControl();
});

afterEach(function () {
    if (! config()->has('database.connections.'.DBC_WRITER)) {
        return;
    }

    // This suite commits for real, so it cleans up after itself.
    DB::connection(DBC_WRITER)->table('trx_branch_change_requests')->delete();
    DB::connection(DBC_WRITER)->table('trx_daily_branch_contexts')->delete();

    DB::purge(DBC_WRITER);
    DB::purge(DBC_PROBE);
});

function dbcProbeConnection(): string
{
    DB::purge(DBC_PROBE);

    return DBC_PROBE;
}

/*
|--------------------------------------------------------------------------
| FIRST-SELECTION RACE
|--------------------------------------------------------------------------
*/

it('lets exactly one branch win the first selection when two sessions race', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    // Session A commits the day.
    dbcStart($user, $a);

    // Session B arrives with a different branch. In production the two overlap;
    // here the second arrives after the first has committed, which is the state
    // the loser of any real race observes once it acquires the row lock.
    expect(fn () => dbcStart($user, $b))->toThrow(ValidationException::class);

    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(1);
    expect((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('refuses a second daily context at the database level, not only in the service', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    // Bypass the service entirely — the guard could be removed and this would
    // still have to fail. UNIQUE(user_id, clinical_date) is the real invariant.
    $duplicate = fn () => DB::table('trx_daily_branch_contexts')->insert([
        'user_id' => $user->id,
        'clinical_date' => dbcClinicalToday(),
        'role_context' => 'kasir',
        'initial_branch_id' => $b->id,
        'current_branch_id' => $b->id,
        'first_selected_at' => now(),
        'change_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($duplicate)->toThrow(QueryException::class);
    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('holds a row lock on the daily context while a selection is in flight', function () {
    $a = dbcBranch('AAA');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    $probe = dbcProbeConnection();

    // Sanity: the probe CAN see the row when nobody holds it. Without this the
    // next assertion could pass for the wrong reason — an invisible row also
    // raises nothing.
    $visible = DB::connection($probe)->select(
        'select id from trx_daily_branch_contexts where user_id = ? for update nowait',
        [$user->id],
    );
    expect($visible)->toHaveCount(1);

    DB::beginTransaction();

    try {
        // A re-selection takes FOR UPDATE on the context and holds it until this
        // transaction ends.
        dbcStart($user, $a);

        $blocked = false;
        $reason = '';

        try {
            DB::connection($probe)->select(
                'select id from trx_daily_branch_contexts where user_id = ? for update nowait',
                [$user->id],
            );
        } catch (QueryException $exception) {
            // 55P03 = lock_not_available. NOWAIT turns "block forever" into an
            // immediate, assertable error; without it this test would hang.
            $reason = (string) $exception->getMessage();
            $blocked = str_contains($reason, '55P03')
                || str_contains(strtolower($reason), 'could not obtain lock');
        }

        expect($blocked)->toBeTrue(
            'The daily context row was NOT locked during a selection — the lock is raceable. Probe said: '.$reason,
        );
    } finally {
        DB::rollBack();
        DB::purge($probe);
    }
});

/*
|--------------------------------------------------------------------------
| APPROVAL RACE
|--------------------------------------------------------------------------
*/

it('applies the switch exactly once when two approvers race the same request', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');
    $adminOne = dbcSuperAdmin();
    $adminTwo = dbcSuperAdmin();

    dbcStart($user, $a);
    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah cabang.');

    dbcApprovals()->approve((int) $request->id, $adminOne);

    // The second approver acquires the lock, re-reads a row that is no longer
    // PENDING, and is refused.
    expect(fn () => dbcApprovals()->approve((int) $request->id, $adminTwo))
        ->toThrow(ValidationException::class);

    $context = dbcDaily()->currentFor($user);

    expect((int) $context->current_branch_id)->toBe((int) $b->id)
        // The observable proof the move was not applied twice.
        ->and((int) $context->change_count)->toBe(1);
});

it('holds a row lock on the request while an approval is in flight', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);
    $request = dbcApprovals()->request($user, (int) $b->id, 'Pindah cabang.');

    $probe = dbcProbeConnection();

    $visible = DB::connection($probe)->select(
        'select id from trx_branch_change_requests where id = ? for update nowait',
        [$request->id],
    );
    expect($visible)->toHaveCount(1);

    DB::beginTransaction();

    try {
        dbcApprovals()->approve((int) $request->id, dbcSuperAdmin());

        $blocked = false;
        $reason = '';

        try {
            DB::connection($probe)->select(
                'select id from trx_branch_change_requests where id = ? for update nowait',
                [$request->id],
            );
        } catch (QueryException $exception) {
            $reason = (string) $exception->getMessage();
            $blocked = str_contains($reason, '55P03')
                || str_contains(strtolower($reason), 'could not obtain lock');
        }

        expect($blocked)->toBeTrue(
            'The request row was NOT locked during an approval — a double approval is possible. Probe said: '.$reason,
        );
    } finally {
        DB::rollBack();
        DB::purge($probe);
    }
});

/*
|--------------------------------------------------------------------------
| PENDING-REQUEST RACE
|--------------------------------------------------------------------------
*/

it('refuses a second pending request at the database level', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $c = dbcBranch('CCC');
    $user = userInRole('Kasir');

    dbcStart($user, $a);
    dbcApprovals()->request($user, (int) $b->id, 'Permintaan pertama.');

    // Straight past the service. The partial unique index is the real guard
    // against a double submit, not the application check in front of it.
    $duplicate = fn () => DB::table('trx_branch_change_requests')->insert([
        'requester_user_id' => $user->id,
        'clinical_date' => dbcClinicalToday(),
        'role_context' => 'kasir',
        'source_branch_id' => $a->id,
        'destination_branch_id' => $c->id,
        'reason' => 'Permintaan kedua.',
        'requested_at' => now(),
        'status' => BranchChangeRequest::STATUS_PENDING,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($duplicate)->toThrow(QueryException::class);

    expect(BranchChangeRequest::query()
        ->where('requester_user_id', $user->id)
        ->where('status', BranchChangeRequest::STATUS_PENDING)
        ->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| THE ABORTED-TRANSACTION TRAP
|--------------------------------------------------------------------------
|
| PostgreSQL aborts the ENTIRE transaction the moment any statement raises:
| every later statement fails with 25P02 until a rollback. Catching a unique
| violation and simply carrying on — the obvious shape, and the one that works
| on SQLite — therefore leaves a POISONED connection on the database production
| actually runs.
|
| Both violation handlers wrap their INSERT in a nested transaction, which
| Laravel implements as a SAVEPOINT, so only the failed statement is discarded.
| These two tests are what prove it: each provokes the violation and then keeps
| querying on the same connection. Before the fix the follow-up query raised
| 25P02 and the suites that only run on SQLite could not see it.
*/

it('leaves the connection usable after a duplicate request is refused', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $c = dbcBranch('CCC');
    $user = userInRole('Kasir');

    dbcStart($user, $a);
    dbcApprovals()->request($user, (int) $b->id, 'Permintaan pertama.');

    expect(fn () => dbcApprovals()->request($user, (int) $c->id, 'Permintaan kedua.'))
        ->toThrow(ValidationException::class);

    // The query that used to raise 25P02.
    expect(BranchChangeRequest::query()->where('requester_user_id', $user->id)->count())->toBe(1);

    // And the connection is healthy enough to keep working normally.
    dbcApprovals()->cancel(
        (int) BranchChangeRequest::query()->where('requester_user_id', $user->id)->value('id'),
        $user,
    );

    expect(BranchChangeRequest::query()
        ->where('requester_user_id', $user->id)
        ->where('status', BranchChangeRequest::STATUS_CANCELLED)
        ->count())->toBe(1);
});

it('leaves the connection usable when the first-selection race handler catches its violation', function () {
    // Drives the loser-of-the-race path directly: a daily context already
    // exists, but the caller has not seen it, so assertSelectable() attempts the
    // INSERT, catches the unique violation, and must then re-read under a lock
    // on the SAME transaction. That re-read is the statement 25P02 killed.
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);

    $service = app(DailyBranchContextService::class);

    // Same branch: the loser is idempotent and must NOT raise.
    $service->assertSelectable($user, (int) $a->id, 'kasir');

    // Different branch: the loser is refused — a ValidationException, never a
    // QueryException from an aborted transaction.
    expect(fn () => $service->assertSelectable($user, (int) $b->id, 'kasir'))
        ->toThrow(ValidationException::class);

    expect(DailyBranchContext::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and((int) dbcDaily()->lockedBranchIdFor($user))->toBe((int) $a->id);
});

it('still allows a new pending request once the previous one is decided', function () {
    $a = dbcBranch('AAA');
    $b = dbcBranch('BBB');
    $user = userInRole('Kasir');

    dbcStart($user, $a);
    $first = dbcApprovals()->request($user, (int) $b->id, 'Permintaan pertama.');
    dbcApprovals()->reject((int) $first->id, dbcSuperAdmin());

    // The partial index constrains PENDING rows only, so the decided row does
    // not block a retry — the audit trail accumulates.
    $second = dbcApprovals()->request($user, (int) $b->id, 'Permintaan kedua.');

    expect($second->status)->toBe(BranchChangeRequest::STATUS_PENDING);
    expect(BranchChangeRequest::query()->where('requester_user_id', $user->id)->count())->toBe(2);
});
