<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the PERSISTED cover vocabulary.
 *
 * Four values, and only four: what a human decided. This is the closed set the
 * `trx_doctor_branch_covers.status` column may ever hold, and it is what a
 * FormRequest validates against and what the approval service asserts before a
 * transition.
 *
 * IT DECLARES NO `ACTIVE` AND NO `EXPIRED`, AND THAT IS THE ENTIRE POINT.
 *
 * Owner decision O6/BRIEF section Q: whether an approved cover is SCHEDULED,
 * ACTIVE or EXPIRED is DERIVED from the period plus the current instant and is
 * never stored, because a stored one is a value some job has to maintain and a
 * late job leaves an expired cover still effective. Those three names live on
 * {@see DoctorBranchCoverState}, reachable only through a call that demands
 * the instant to judge against. Keeping them out of THIS class is what makes
 * 'derive, never persist' structural: there is no name here for a writer to
 * put in the column.
 *
 * The repository has no native PHP enum anywhere in app/; the established
 * convention is public constants plus an explicit list, the shape
 * app/Modules/LegacyRme/Support/LegacyRmeImportStatus.php:18-49 records.
 */
final class DoctorBranchCoverStatus
{
    /** Requested, awaiting a Super Admin or Supervisor RME decision. */
    public const PENDING = 'pending';

    /**
     * Granted. Says nothing about the clock — a row that is APPROVED may be
     * scheduled, current or long finished. Ask DoctorBranchCoverState.
     */
    public const APPROVED = 'approved';

    /** Refused. Never becomes current. */
    public const REJECTED = 'rejected';

    /**
     * Withdrawn after approval. Cancellation MUST write this value as well as
     * `cancelled_at`: the duplicate-period backstop index is predicated on
     * `status = 'approved'`, so a row that keeps that status after being
     * cancelled would block a legitimate re-grant for the identical period.
     */
    public const CANCELLED = 'cancelled';

    /**
     * Every value that is ever WRITTEN to the column.
     *
     * @var list<string>
     */
    public const PERSISTED = [
        self::PENDING,
        self::APPROVED,
        self::REJECTED,
        self::CANCELLED,
    ];

    /**
     * A cover in one of these states is never decided again. APPROVED is
     * absent on purpose: an approved cover can still be cancelled.
     *
     * @var list<string>
     */
    public const TERMINAL = [
        self::REJECTED,
        self::CANCELLED,
    ];

    /**
     * The allowed transitions. APPROVED -> CANCELLED is the withdrawal path
     * and is the only edge that leaves a granted cover.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::PENDING => [self::APPROVED, self::REJECTED, self::CANCELLED],
        self::APPROVED => [self::CANCELLED],
        self::REJECTED => [],
        self::CANCELLED => [],
    ];

    /** Never instantiated: this is a vocabulary. */
    private function __construct() {}

    /** Human-readable, for the approver and doctor surfaces. */
    public static function labelFor(string $status): string
    {
        return match ($status) {
            self::PENDING => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            default => $status,
        };
    }
}
