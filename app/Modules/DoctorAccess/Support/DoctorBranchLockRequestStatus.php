<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the lifecycle of a request to
 * SET or MOVE a doctor's HOME_LOCKED_BRANCH.
 *
 * A typed, closed vocabulary plus the allowed transitions. Status strings live
 * ONLY here (and on the model constants that reference this class) so they are
 * never scattered as magic strings across services, requests or views.
 *
 * The repository has no native PHP enum anywhere in app/; the established
 * convention is public constants plus an explicit list, the shape
 * app/Modules/LegacyRme/Support/LegacyRmeImportStatus.php:18-49 records.
 *
 * THERE IS DELIBERATELY NO `EXPIRED`.
 *
 * The sibling BranchChangeRequest has STATUS_EXPIRED
 * (app/Modules/RmeOnlineContext/Models/BranchChangeRequest.php:38) only
 * because that row is scoped to a `clinical_date` and stops meaning anything
 * when the day turns over. A permanent lock has no day. A pending request here
 * stays pending until a human decides it or the requester cancels it, and its
 * freshness boundary is the stale-source guard the approval service re-asserts
 * under the row lock — not a status. Do not 'restore' a missing state.
 */
final class DoctorBranchLockRequestStatus
{
    /** Filed, awaiting a Super Admin or Supervisor RME decision. */
    public const PENDING = 'pending';

    /**
     * Approved AND applied. The move happens inside the approval transaction,
     * so this value never denotes a reusable credential — `applied_at` is the
     * proof it was consumed.
     */
    public const APPROVED = 'approved';

    /** Refused. The home lock is untouched. */
    public const REJECTED = 'rejected';

    /** Withdrawn by the requester before a decision. */
    public const CANCELLED = 'cancelled';

    /**
     * The closed vocabulary.
     *
     * @var list<string>
     */
    public const ALL = [
        self::PENDING,
        self::APPROVED,
        self::REJECTED,
        self::CANCELLED,
    ];

    /**
     * Once a request leaves PENDING it is decided forever. Unlike a cover
     * there is no post-approval withdrawal: the lock has already moved.
     *
     * @var list<string>
     */
    public const TERMINAL = [
        self::APPROVED,
        self::REJECTED,
        self::CANCELLED,
    ];

    /**
     * The allowed transitions. Every terminal state is a dead end.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::PENDING => [self::APPROVED, self::REJECTED, self::CANCELLED],
        self::APPROVED => [],
        self::REJECTED => [],
        self::CANCELLED => [],
    ];

    /** Never instantiated: this is a vocabulary. */
    private function __construct() {}

    /** Human-readable, for the requester and approver surfaces. */
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
