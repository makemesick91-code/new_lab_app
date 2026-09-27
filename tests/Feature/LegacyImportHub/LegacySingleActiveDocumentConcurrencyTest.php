<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the slot holds under
| concurrency.
|--------------------------------------------------------------------------
|
| WHY THIS SUITE IS SEPARATE, AND WHY IT SKIPS.
|
| The rest of the slot suite runs on SQLite, where `pg_advisory_xact_lock` does
| not exist and the guard's lock step is a documented no-op. Every sequential
| boundary assertion there is real, but none of them can prove the property that
| actually matters in production: that two operators uploading for the SAME
| patient at the SAME moment cannot both get a lifecycle.
|
| That is a PostgreSQL advisory-lock property, so it is proven against
| PostgreSQL and SKIPPED everywhere else. The skip is stated out loud rather
| than passing silently — a concurrency test that "passes" on a driver with no
| locks is worse than no test, because it reads as evidence.
|
| WHY A LOCK AND NOT A UNIQUE INDEX.
|
| Occupancy spans two tables (a live staging row OR a published archive record,
| with a VOID record releasing the slot), so no single partial UNIQUE index can
| express the predicate. The race is closed by serializing the DECISION instead.
| That makes the lock itself the load-bearing part, and therefore the thing this
| file has to prove is really taken.
|
| WHAT IS PROVEN HERE:
|
|   1. asserting the slot really takes the advisory lock — a second session
|      cannot acquire it while the first transaction is open;
|   2. the RME and ODONTOGRAM namespaces are independent AT THE LOCK LEVEL, so
|      one document type can never serialize the other;
|   3. two different patients never contend with each other;
|   4. the lock is TRANSACTION-scoped: it is released by both rollback and
|      commit, and cannot leak across a pooled connection.
|
| WHY THERE IS NO PATIENT FIXTURE.
|
| The lock key is derived from (document type, patient id) alone, so the locking
| property is fully exercised with an id that owns no rows. That keeps this suite
| from having to commit — and then clean up — patients, branches, imports and
| records on a connection RefreshDatabase does not manage. Occupancy against real
| rows is the sequential suite's job.
|
| Run it against a PostgreSQL 16 database (the production major):
|
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55433 \
|   DB_DATABASE=lsad_concurrency DB_USERNAME=lsaduser DB_PASSWORD=... \
|   php artisan test tests/Feature/LegacyImportHub/LegacySingleActiveDocumentConcurrencyTest.php
*/

use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyDocumentSlotLock;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Support\Facades\DB;

/*
| WHY THE DEFAULT CONNECTION IS SWAPPED.
|
| `tests/Pest.php` binds RefreshDatabase to every test under `Feature`, and
| RefreshDatabase wraps the default connection in a transaction that is never
| committed. An advisory lock taken inside that wrapper would be held for the
| whole test, and — worse — a second session could never observe the state the
| first one wrote. So the guard is driven over `lsad_writer`, a connection
| RefreshDatabase does not manage, and `lsad_probe` is an independent session
| that contends for the same key.
|
| The service, the lock and the transaction boundaries are the production ones —
| only the connection name differs.
*/

const LSAD_WRITER = 'lsad_concurrency_writer';
const LSAD_PROBE = 'lsad_concurrency_probe';

/** An id that owns no rows; the lock key does not care. */
const LSAD_PATIENT = 424242;

const LSAD_OTHER_PATIENT = 515151;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Advisory-lock behaviour is only observable on PostgreSQL; on other drivers the '
            .'slot guard documents its lock step as a no-op and the property cannot be proven.',
        );
    }

    $base = config('database.connections.'.config('database.default'));

    config()->set('database.connections.'.LSAD_WRITER, $base);
    config()->set('database.connections.'.LSAD_PROBE, $base);

    DB::purge(LSAD_WRITER);
    DB::purge(LSAD_PROBE);

    // Everything the guard touches — the repositories, DB::transaction,
    // DB::connection() — now resolves to a connection that really commits.
    config()->set('database.default', LSAD_WRITER);
});

afterEach(function () {
    if (! config()->has('database.connections.'.LSAD_WRITER)) {
        return;
    }

    // Advisory xact locks die with their transaction, so there is no lock state
    // to clean up — only the connections themselves.
    DB::purge(LSAD_WRITER);
    DB::purge(LSAD_PROBE);
});

function lsadSlotGuard(): LegacySingleActiveDocumentService
{
    return app(LegacySingleActiveDocumentService::class);
}

/**
 * Can an INDEPENDENT session take this slot's advisory lock right now?
 *
 * `pg_try_advisory_xact_lock` returns false instead of blocking, which turns
 * "would hang forever" into an assertable boolean. Wrapped in its own
 * transaction so the probe's own lock is released immediately.
 */
function lsadProbeCanLock(string $type, int $patientId): bool
{
    [$classId, $objectId] = LegacyDocumentSlotLock::keyFor($type, $patientId);

    DB::purge(LSAD_PROBE);

    $probe = DB::connection(LSAD_PROBE);

    $probe->beginTransaction();

    try {
        $rows = $probe->select('select pg_try_advisory_xact_lock(?, ?) as acquired', [$classId, $objectId]);

        return (bool) ($rows[0]->acquired ?? false);
    } finally {
        $probe->rollBack();
        DB::purge(LSAD_PROBE);
    }
}

it('holds an advisory lock on the patient slot for the duration of the assertion', function () {
    // Sanity first: the probe CAN take the lock when nobody holds it. Without
    // this the next assertion could pass for the wrong reason.
    expect(lsadProbeCanLock(LegacyImportType::LEGACY_RME, LSAD_PATIENT))->toBeTrue();

    DB::beginTransaction();

    try {
        lsadSlotGuard()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LSAD_PATIENT);

        expect(lsadProbeCanLock(LegacyImportType::LEGACY_RME, LSAD_PATIENT))->toBeFalse(
            'The patient RME slot was NOT locked while the decision was in flight — two '
            .'concurrent uploads could both read a free slot and both create a lifecycle.',
        );
    } finally {
        DB::rollBack();
    }
});

it('releases the slot lock when the transaction rolls back', function () {
    DB::beginTransaction();
    lsadSlotGuard()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LSAD_PATIENT);
    expect(lsadProbeCanLock(LegacyImportType::LEGACY_RME, LSAD_PATIENT))->toBeFalse();
    DB::rollBack();

    // A refused intake must not strand the patient's slot. This is why the lock
    // is xact-scoped and never session-scoped: a session lock on a pooled
    // connection could hold a patient out for the life of the worker.
    expect(lsadProbeCanLock(LegacyImportType::LEGACY_RME, LSAD_PATIENT))->toBeTrue();
});

it('releases the slot lock when the transaction commits', function () {
    DB::beginTransaction();
    lsadSlotGuard()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LSAD_PATIENT);
    DB::commit();

    expect(lsadProbeCanLock(LegacyImportType::LEGACY_RME, LSAD_PATIENT))->toBeTrue();
});

it('keeps the RME and odontogram slots independent at the lock level', function () {
    DB::beginTransaction();

    try {
        lsadSlotGuard()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LSAD_PATIENT);

        // The whole point of the two-argument advisory key: the same patient's
        // odontogram slot is a DIFFERENT namespace and must remain free. If this
        // ever returns false, an RME upload would be serializing odontogram
        // uploads for the same patient — a liveness bug that a query-only test
        // could never see.
        expect(lsadProbeCanLock(LegacyImportType::LEGACY_ODONTOGRAM, LSAD_PATIENT))->toBeTrue();
    } finally {
        DB::rollBack();
    }
});

it('never lets one patient slot lock another patient out', function () {
    DB::beginTransaction();

    try {
        lsadSlotGuard()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LSAD_PATIENT);

        expect(lsadProbeCanLock(LegacyImportType::LEGACY_RME, LSAD_OTHER_PATIENT))->toBeTrue();
    } finally {
        DB::rollBack();
    }
});

it('serializes two concurrent assertions for the same patient and type', function () {
    // The end-to-end safety argument, stated as a lock property: while session A
    // is deciding, session B cannot even begin to decide. B therefore cannot read
    // a stale "free" slot, which is the only way both could have proceeded.
    DB::beginTransaction();

    try {
        lsadSlotGuard()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_ODONTOGRAM, LSAD_PATIENT);

        $probeBlocked = ! lsadProbeCanLock(LegacyImportType::LEGACY_ODONTOGRAM, LSAD_PATIENT);

        expect($probeBlocked)->toBeTrue();
    } finally {
        DB::rollBack();
    }

    // And once A is done, B proceeds normally — the guard serializes, it does not
    // deadlock or starve.
    expect(lsadProbeCanLock(LegacyImportType::LEGACY_ODONTOGRAM, LSAD_PATIENT))->toBeTrue();
});
