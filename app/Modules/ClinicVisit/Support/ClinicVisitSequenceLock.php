<?php

declare(strict_types=1);

namespace App\Modules\ClinicVisit\Support;

use App\Modules\LegacyImport\Support\LegacyDocumentSlotLock;
use InvalidArgumentException;

/**
 * DMS-RME-REVISION-PROGRAM-7 / R5-2 — the serialization key for numbering the
 * visits of one branch (queue number and visit number).
 *
 * WHY A LOCK, AND WHY THIS ONE
 * ----------------------------
 * Both numbers are "the highest issued today at this branch, plus one". The
 * previous reads (`ORDER BY queue_number DESC LIMIT 1 FOR UPDATE` and
 * `visit_number LIKE prefix FOR UPDATE`) serialised nothing: on the day's first
 * registration there is no row to lock, and with rows present PostgreSQL hands
 * the waiting reader the same top row back after the first commits. Two
 * concurrent registrations therefore chose the same number and
 * UNIQUE(branch_id, visit_date, queue_number) turned the second into HTTP 500.
 *
 * A transaction-scoped advisory lock taken BEFORE either number is read, and
 * released only when the registration commits or rolls back, makes the read,
 * the choice and the insert one step per branch. Registrations at different
 * branches never wait for each other.
 *
 * KEY DERIVATION — EXACT, NEVER A HASH (same convention as
 * {@see LegacyDocumentSlotLock})
 * ------------------------------------------------------------------------------
 * `pg_advisory_xact_lock(int4, int4)`:
 *
 *     classid = NAMESPACE   (fixed; 91_000.. belongs to legacy imports)
 *     objid   = $branchId   (identity-mapped: cannot collide, unlike a hash)
 *
 * NEVER RENUMBER NAMESPACE. During a rolling deploy an old and a new process
 * would take different locks for the same branch and both proceed. The value is
 * pinned by tests/Feature/RME/RmeConcurrentFirstWriteLockTest.php.
 */
final class ClinicVisitSequenceLock
{
    public const NAMESPACE = 92_001;

    /** `objid` is an int4 in PostgreSQL's lock table. */
    public const MAX_OBJID = 2_147_483_647;

    private function __construct() {}

    /**
     * The exact (classid, objid) pair handed to `pg_advisory_xact_lock`.
     *
     * @return array{0: int, 1: int}
     */
    public static function keyFor(int $branchId): array
    {
        if ($branchId < 1 || $branchId > self::MAX_OBJID) {
            throw new InvalidArgumentException('Branch id out of range for the visit sequence lock.');
        }

        return [self::NAMESPACE, $branchId];
    }
}
