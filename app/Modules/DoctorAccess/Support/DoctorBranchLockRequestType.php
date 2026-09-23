<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — which of owner decision O1's
 * two workflows a lock request is, and the single place that derives it.
 *
 * INITIAL ASSIGNMENT and TRANSFER share one table because they differ in
 * exactly one column: an assignment has no `source_branch_id`. They share
 * their status vocabulary, their approver set, their single-pending invariant
 * and their apply-inside-the-approval semantics, so nothing else about them is
 * duplicated. A TEMPORARY COVER is neither of them and lives in its own table.
 *
 * THE TYPE IS DERIVED SERVER-SIDE, NEVER SUPPLIED BY A CLIENT.
 *
 * {@see self::deriveFrom()} is called once at request time — with the doctor's
 * live locked branch, so the value is persisted for audit — and AGAIN at
 * approval time under the doctor's mst_doctor_branch_locks row lock. The
 * re-derivation is what turns the type into a second stale guard: a row that
 * says INITIAL_ASSIGNMENT but whose doctor has since acquired a home lock is
 * stale and must be refused rather than silently applied. A `request_type`
 * arriving in a payload is never read.
 *
 * The repository has no native PHP enum anywhere in app/; the established
 * convention is public constants plus an explicit list, the shape
 * app/Modules/LegacyRme/Support/LegacyRmeImportStatus.php:18-49 records.
 */
final class DoctorBranchLockRequestType
{
    /**
     * UNSET -> approved home branch. `source_branch_id` is NULL because there
     * is no source: the doctor had no mst_doctor_branch_locks row at all.
     */
    public const INITIAL_ASSIGNMENT = 'initial_assignment';

    /**
     * Home branch A -> approved home branch B. `source_branch_id` is bound and
     * the approval re-asserts that the live lock still sits there.
     */
    public const TRANSFER = 'transfer';

    /**
     * The closed vocabulary.
     *
     * @var list<string>
     */
    public const ALL = [
        self::INITIAL_ASSIGNMENT,
        self::TRANSFER,
    ];

    /** Never instantiated: this is a vocabulary and a single derivation. */
    private function __construct() {}

    /**
     * Derive the workflow from the doctor's CURRENT home branch.
     *
     * NULL means the doctor has no mst_doctor_branch_locks row — the UNSET
     * state, which owner decision O1 makes the absence of a row rather than a
     * row carrying a NULL branch. That is an initial assignment. Anything else
     * is a transfer away from a branch that already exists.
     */
    public static function deriveFrom(?int $currentHomeBranchId): string
    {
        return $currentHomeBranchId === null
            ? self::INITIAL_ASSIGNMENT
            : self::TRANSFER;
    }

    /** Human-readable, for the requester and approver surfaces. */
    public static function labelFor(string $type): string
    {
        return match ($type) {
            self::INITIAL_ASSIGNMENT => 'Penetapan Awal',
            self::TRANSFER => 'Perpindahan Permanen',
            default => $type,
        };
    }
}
