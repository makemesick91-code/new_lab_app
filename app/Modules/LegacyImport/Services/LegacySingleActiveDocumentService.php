<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Services;

use App\Modules\LegacyImport\Exceptions\LegacyDocumentSlotLockUnavailable;
use App\Modules\LegacyImport\Exceptions\LegacyDocumentSlotOccupied;
use App\Modules\LegacyImport\Support\LegacyDocumentSlotLock;
use App\Modules\LegacyImport\Support\LegacyDocumentSlotOccupancy;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramImportRepositoryInterface;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the ONE place that
 * answers "may this patient begin a NEW legacy lifecycle of this type?".
 *
 * THE INVARIANT
 * -------------
 * Per patient, per document type, at most ONE active / non-VOID logical legacy
 * lifecycle. The RME slot and the odontogram slot are entirely independent: a
 * published RME never blocks a first odontogram, and vice versa. There is
 * deliberately no patient-wide "has any legacy document" flag anywhere — that
 * shortcut is exactly how the two slots would get fused.
 *
 * WHY OCCUPANCY SPANS TWO TABLES
 * ------------------------------
 * A lifecycle begins as a staging row and, if it survives review, produces an
 * immutable archive record. So the slot is held by whichever of those is
 * currently live:
 *
 *   1. a PUBLISHED archive record                  -> OCCUPIED
 *   2. a VOID archive record                       -> RELEASED (correction path)
 *   3. a staging row in a SLOT_OCCUPYING state     -> OCCUPIED (incl. FAILED,
 *                                                     which is retryable)
 *   4. a CANCELLED staging row                     -> RELEASED (terminal, and it
 *                                                     produced no archive)
 *   5. a PUBLISHED staging row whose record is VOID -> RELEASED
 *
 * Case 5 is the one that is easy to get wrong, and getting it wrong would break
 * void-then-reimport permanently: the staging row keeps its historical PUBLISHED
 * status forever, so if that alone held the slot, a voided archive could never be
 * replaced. It is handled for free by the order of the checks below — the record
 * is consulted first, and a PUBLISHED staging row is not in SLOT_OCCUPYING.
 *
 * RETRY IS NOT A NEW UPLOAD
 * -------------------------
 * This service is only ever consulted when a NEW lifecycle is about to be
 * created (`createFromUpload`). Retrying, resuming or re-rendering an EXISTING
 * import goes through `queue($import, $actor, isRetry: true)` and never reaches
 * here, so the guard cannot break queue retries, worker recovery or the ops CLI.
 */
class LegacySingleActiveDocumentService
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $rmeImports,
        private readonly LegacyRmeRecordRepositoryInterface $rmeRecords,
        private readonly LegacyOdontogramImportRepositoryInterface $odontogramImports,
        private readonly LegacyOdontogramRecordRepositoryInterface $odontogramRecords,
    ) {}

    /**
     * The current occupancy of one patient's slot for one document type.
     *
     * Read-only and lock-free: safe for a UI panel. It is NOT sufficient as a
     * gate — see assertAvailableForNewLifecycle().
     */
    public function occupancyFor(string $type, int $patientId): LegacyDocumentSlotOccupancy
    {
        // The ARCHIVE is consulted first, deliberately. It is the only thing
        // that can be VOID, and VOID is what releases a published slot; asking
        // the staging table first would make a historical PUBLISHED staging row
        // look like an occupant forever.
        $record = match ($type) {
            LegacyImportType::LEGACY_RME => $this->rmeRecords->firstPublishedForPatientUnscoped($patientId),
            LegacyImportType::LEGACY_ODONTOGRAM => $this->odontogramRecords->firstPublishedForPatientUnscoped($patientId),
            default => throw LegacyDocumentSlotLockUnavailable::unsupportedType($type),
        };

        if ($record !== null) {
            return LegacyDocumentSlotOccupancy::alreadyPublished(
                $type,
                $patientId,
                (int) $record->getKey(),
                $record->source_import_id !== null ? (int) $record->source_import_id : null,
            );
        }

        $import = match ($type) {
            LegacyImportType::LEGACY_RME => $this->rmeImports->firstSlotOccupyingForPatient($patientId),
            LegacyImportType::LEGACY_ODONTOGRAM => $this->odontogramImports->firstSlotOccupyingForPatient($patientId),
            default => throw LegacyDocumentSlotLockUnavailable::unsupportedType($type),
        };

        if ($import !== null) {
            return LegacyDocumentSlotOccupancy::activeImport(
                $type,
                $patientId,
                (int) $import->getKey(),
                (string) $import->status,
            );
        }

        return LegacyDocumentSlotOccupancy::available($type, $patientId);
    }

    /**
     * ADVISORY pre-check, for use before an upload is spent.
     *
     * Returns the blocking occupancy, or null when the slot is free. Null here
     * never means "you may proceed" — it means "nothing is blocking you yet".
     * Another operator may take the slot between this call and the insert, which
     * is what the authoritative assertion below exists for.
     */
    public function previewForNewLifecycle(string $type, int $patientId): ?LegacyDocumentSlotOccupancy
    {
        $occupancy = $this->occupancyFor($type, $patientId);

        return $occupancy->occupied ? $occupancy : null;
    }

    /**
     * THE AUTHORITATIVE GATE. Must be called INSIDE the transaction that creates
     * the new lifecycle, and before the insert.
     *
     * 1. Refuses outright if there is no surrounding transaction — a
     *    transaction-scoped advisory lock taken outside one is released
     *    immediately, so running unguarded would look like it worked while
     *    guarding nothing. Failing loudly is the only safe behaviour.
     *
     * 2. Serializes every concurrent decision for this (patient, type) pair.
     *    Taken BEFORE the occupancy is read, so the read cannot be stale by the
     *    time it is acted on.
     *
     * 3. Re-reads occupancy and refuses if the slot is held.
     *
     * LOCK ORDER. This is the FIRST lock taken on the intake path, ahead of the
     * hub daily-quota bucket and the wave quota buckets. Those are keyed by
     * branch; this one is keyed by patient, so the two classes cannot form a
     * cycle as long as every site takes them in this order — and the two
     * `createFromUpload` methods are the only sites that take any of them.
     *
     * @throws LegacyDocumentSlotOccupied when another lifecycle holds the slot
     */
    public function assertAvailableForNewLifecycle(string $type, int $patientId): void
    {
        if (DB::transactionLevel() < 1) {
            throw LegacyDocumentSlotLockUnavailable::outsideTransaction();
        }

        $this->acquireSlotLock($type, $patientId);

        $occupancy = $this->occupancyFor($type, $patientId);

        if ($occupancy->occupied) {
            throw LegacyDocumentSlotOccupied::from($occupancy);
        }
    }

    /**
     * Serialize on a patient's document slot WITHOUT deciding anything — for a
     * caller (the patient merge) that changes which patient owns an archive
     * and therefore must not interleave with an intake deciding occupancy.
     *
     * Same lock, same key, same transaction-scoped lifetime as the intake
     * gate. A caller must take it BEFORE any patient row lock, the order the
     * intake path uses (advisory lock, then the patient row), or the two can
     * form a cycle.
     */
    public function lockSlot(string $type, int $patientId): void
    {
        if (DB::transactionLevel() < 1) {
            throw LegacyDocumentSlotLockUnavailable::outsideTransaction();
        }

        $this->acquireSlotLock($type, $patientId);
    }

    /**
     * Take the transaction-scoped advisory lock on PostgreSQL.
     *
     * The key is validated first, so an unsupported type or an out-of-range
     * patient id fails before any statement runs, on every driver.
     *
     * ON NON-POSTGRESQL DRIVERS THIS IS A NO-OP, and that is a real limitation
     * rather than a portability nicety. SQLite — which the default test suite
     * uses — serializes writers at the file level and has no advisory lock to
     * take. Production is PostgreSQL, and the concurrency guarantee is proven by
     * tests that run against a real PostgreSQL server; asserting it on SQLite
     * would prove nothing about production.
     *
     * Note that the re-read which follows this lock is correctly routed: Laravel
     * returns the WRITE pdo from getReadPdo() whenever a transaction is open, so
     * the occupancy check cannot be answered by a lagging read replica.
     */
    private function acquireSlotLock(string $type, int $patientId): void
    {
        [$classId, $objectId] = LegacyDocumentSlotLock::keyFor($type, $patientId);

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('select pg_advisory_xact_lock(?, ?)', [$classId, $objectId]);
    }
}
