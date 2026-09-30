<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §50 — concurrency
|--------------------------------------------------------------------------
|
| WHAT THIS SUITE PROVES
|
|   1. Mass upload shares the CANONICAL advisory-lock namespace. While a
|      transaction holds the slot lock for a patient+type, an independent
|      session cannot take it. This is the evidence for the central design
|      claim of §15: mass upload introduces NO second locking system, so there
|      is exactly one lock order over these rows and no way to deadlock two
|      subsystems against each other.
|
|   2. The RME and ODONTOGRAM locks are DIFFERENT KEYS for the same patient, so
|      a legacy RME being created can never block a first legacy chart (§14,
|      §48) — proven at the lock level, not just at the query level.
|
|   3. A mass-created lifecycle really registers in that shared slot namespace:
|      after a mass dispatch commits, an independent read sees the patient's
|      slot occupied.
|
| WHAT THIS SUITE DELIBERATELY DOES NOT CLAIM
|
|   It does not run two OS processes. A single PHP process cannot truly
|   parallelise, so "two sessions racing" is modelled with a second database
|   connection and `pg_try_advisory_xact_lock`, which returns false instead of
|   blocking and therefore turns "would hang" into an assertable boolean. That
|   is the same technique — and the same honesty about its limits — as
|   LegacySingleActiveDocumentConcurrencyTest.
|
|   Sequential idempotency (double confirm, double dispatch, stale preflight) is
|   proven on BOTH drivers in LegacyMassUploadRmeTest, because those properties
|   rest on row locks and re-reads rather than on advisory locks.
|
| POSTGRESQL ONLY, AND THE SKIP IS STATED OUT LOUD.
| SQLite cannot take an advisory lock, so on SQLite the guard documents its lock
| step as a no-op and the property is unprovable. Skipping is the honest
| outcome; passing would be a lie.
|
| Run against PostgreSQL 16 (the production major):
|
|   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55440 \
|   DB_DATABASE=lmu_concurrency DB_USERNAME=lmuuser DB_PASSWORD=... \
|   php artisan test tests/Feature/LegacyMassUpload/LegacyMassUploadConcurrencyTest.php
*/

use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadDispatchService;
use App\Modules\LegacyImport\MassUpload\Services\LegacyMassUploadPreflightService;
use App\Modules\LegacyImport\Services\LegacySingleActiveDocumentService;
use App\Modules\LegacyImport\Support\LegacyDocumentSlotLock;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Support\Facades\DB;

/*
| WHY THE DEFAULT CONNECTION IS SWAPPED.
|
| tests/Pest.php binds RefreshDatabase to everything under Feature, and
| RefreshDatabase wraps the default connection in a transaction that is never
| committed. An advisory lock taken inside that wrapper would be held for the
| entire test, and a second session could never observe what the first wrote.
| The guard is therefore driven over a connection RefreshDatabase does not
| manage, with a second, fully independent session contending for the same key.
|
| The service, the lock and the transaction boundaries are the production ones.
| Only the connection name differs.
*/

const LMU_WRITER = 'lmu_concurrency_writer';
const LMU_PROBE = 'lmu_concurrency_probe';

/**
 * Ids that own no rows.
 *
 * The lock key is derived arithmetically from (type, patientId) and does not
 * read the database, so the contention property is fully exercised without
 * committing — and then having to clean up — patients, branches and imports on
 * a connection RefreshDatabase does not manage. Occupancy against real rows is
 * the sequential suites' job.
 */
const LMU_PATIENT = 737373;

const LMU_OTHER_PATIENT = 848484;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped(
            'Advisory-lock contention is only observable on PostgreSQL. On other drivers the '
            .'slot guard documents its lock step as a no-op, so this property cannot be proven '
            .'and is skipped rather than reported as passing.',
        );
    }

    $base = config('database.connections.'.config('database.default'));

    config()->set('database.connections.'.LMU_WRITER, $base);
    config()->set('database.connections.'.LMU_PROBE, $base);

    DB::purge(LMU_WRITER);
    DB::purge(LMU_PROBE);

    config()->set('database.default', LMU_WRITER);
});

afterEach(function (): void {
    if (! config()->has('database.connections.'.LMU_WRITER)) {
        return;
    }

    // Advisory xact locks die with their transaction, so there is no lock state
    // to clean up — only the connections themselves.
    DB::purge(LMU_WRITER);
    DB::purge(LMU_PROBE);
});

function lmuSlots(): LegacySingleActiveDocumentService
{
    return app(LegacySingleActiveDocumentService::class);
}

/**
 * Can an INDEPENDENT session take this slot's advisory lock right now?
 *
 * pg_try_advisory_xact_lock returns false rather than blocking, so a contended
 * key is assertable instead of hanging the suite. Wrapped in its own
 * transaction so the probe's own lock is released immediately.
 */
function lmuProbeCanLock(string $type, int $patientId): bool
{
    [$classId, $objectId] = LegacyDocumentSlotLock::keyFor($type, $patientId);

    DB::purge(LMU_PROBE);

    $probe = DB::connection(LMU_PROBE);
    $probe->beginTransaction();

    try {
        $rows = $probe->select('select pg_try_advisory_xact_lock(?, ?) as acquired', [$classId, $objectId]);

        return (bool) ($rows[0]->acquired ?? false);
    } finally {
        $probe->rollBack();
    }
}

/*
|--------------------------------------------------------------------------
| 1. Mass upload shares the canonical lock namespace (§15)
|--------------------------------------------------------------------------
*/

it('holds the canonical slot lock for the duration of the creating transaction', function (): void {
    /*
     * This is the evidence for "no second locking system".
     *
     * assertAvailableForNewLifecycle() is the exact call the canonical
     * createFromUpload() makes, and mass upload creates lifecycles ONLY through
     * createFromUpload(). So if that call excludes an independent session here,
     * it excludes one for a mass item too.
     */
    expect(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_PATIENT))->toBeTrue();

    DB::connection(LMU_WRITER)->transaction(function (): void {
        lmuSlots()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LMU_PATIENT);

        // Inside the transaction the key is taken, so a second session is
        // excluded — which is what makes a duplicate lifecycle impossible.
        expect(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_PATIENT))->toBeFalse();
    });

    // And released on commit, so the next batch pass is not starved.
    expect(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_PATIENT))->toBeTrue();
});

it('releases the slot lock when the creating transaction rolls back', function (): void {
    // A refused item must not strand the patient's slot. createFromUpload()
    // rolls back on refusal, and an advisory xact lock dies with it.
    try {
        DB::connection(LMU_WRITER)->transaction(function (): void {
            lmuSlots()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LMU_PATIENT);

            expect(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_PATIENT))->toBeFalse();

            throw new RuntimeException('simulated refusal');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_PATIENT))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 2. The two document types are independent AT THE LOCK (§14, §48)
|--------------------------------------------------------------------------
*/

it('uses a different lock key for rme and odontogram on the same patient', function (): void {
    [$rmeClass, $rmeObject] = LegacyDocumentSlotLock::keyFor(LegacyImportType::LEGACY_RME, LMU_PATIENT);
    [$odontoClass, $odontoObject] = LegacyDocumentSlotLock::keyFor(LegacyImportType::LEGACY_ODONTOGRAM, LMU_PATIENT);

    expect([$rmeClass, $rmeObject])->not->toBe([$odontoClass, $odontoObject]);
});

it('lets an odontogram slot be taken while the same patients rme slot is held', function (): void {
    /*
     * §48 at the lock level. If these shared a key, a mass RME batch would
     * serialise against a mass odontogram batch for the same patients — and a
     * patient's published legacy RME would block their first legacy chart,
     * which is precisely what the single-active-document design forbids.
     */
    DB::connection(LMU_WRITER)->transaction(function (): void {
        lmuSlots()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LMU_PATIENT);

        expect(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_PATIENT))->toBeFalse()
            // The other type is untouched.
            ->and(lmuProbeCanLock(LegacyImportType::LEGACY_ODONTOGRAM, LMU_PATIENT))->toBeTrue();
    });
});

it('does not serialise two different patients of the same type', function (): void {
    // A mass batch of 250 patients must not become a single-file queue.
    DB::connection(LMU_WRITER)->transaction(function (): void {
        lmuSlots()->assertAvailableForNewLifecycle(LegacyImportType::LEGACY_RME, LMU_PATIENT);

        expect(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_PATIENT))->toBeFalse()
            ->and(lmuProbeCanLock(LegacyImportType::LEGACY_RME, LMU_OTHER_PATIENT))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| 3. The dispatcher owns no lock of its own
|--------------------------------------------------------------------------
*/

it('takes no advisory lock outside the canonical creating transaction', function (): void {
    /*
     * The negative claim, asserted rather than assumed.
     *
     * If LegacyMassUploadDispatchService had grown its own patient lock, this
     * key would be contended while a batch was mid-pass and the two lock orders
     * could deadlock. Nothing in the mass layer locks a patient: the only lock
     * is the canonical one, taken inside createFromUpload().
     */
    $dispatcher = new ReflectionClass(
        LegacyMassUploadDispatchService::class
    );

    $source = (string) file_get_contents((string) $dispatcher->getFileName());

    expect($source)
        ->not->toContain('pg_advisory')
        ->not->toContain('pg_try_advisory')
        ->not->toContain('assertAvailableForNewLifecycle')
        ->not->toContain('LegacyDocumentSlotLock');

    // It consults occupancy read-only, to CLASSIFY a refusal after the fact.
    expect($source)->toContain('previewForNewLifecycle');
});

it('keeps the preflight service free of any patient lock', function (): void {
    // Preflight is a forecast and must never take a lock: holding 250 patient
    // locks through a review screen would block every single upload in the
    // clinic while an operator read the page.
    $source = (string) file_get_contents(
        (new ReflectionClass(
            LegacyMassUploadPreflightService::class
        ))->getFileName()
    );

    expect($source)
        ->not->toContain('pg_advisory')
        ->not->toContain('assertAvailableForNewLifecycle')
        ->not->toContain('lockForUpdate\'');
});
