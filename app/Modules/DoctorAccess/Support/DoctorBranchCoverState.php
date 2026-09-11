<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use Carbon\CarbonInterface;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — what a cover IS right now.
 *
 * Section Q draws a line the schema has to hold: PENDING, APPROVED, REJECTED
 * and CANCELLED are decisions a human made and are written down. SCHEDULED,
 * ACTIVE and EXPIRED are consequences of the clock and are never written down.
 *
 * They live here rather than beside the persisted statuses on the model for a
 * blunt reason: a constant sitting next to `STATUS_APPROVED` eventually gets
 * assigned to `status`, and the moment ACTIVE is a stored value some job has
 * to keep it true — which is the failure mode the decision exists to prevent.
 * A late job would leave an expired cover effective. Here the only way to
 * obtain one of these names is to hand over the instant to judge against.
 *
 * THIS IS THE ONLY PLACE ACTIVE AND EXPIRED EXIST. Its sibling
 * {@see DoctorBranchCoverStatus} carries the PERSISTED vocabulary and
 * deliberately declares neither, so the split is structural rather than a
 * convention a reader has to remember.
 *
 * There is no ambient clock in this class. The caller supplies the instant, so
 * the middleware, the approval service, the resolver and the tests all reach
 * the same answer, and Carbon::setTestNow() controls it without any bespoke
 * freezing mechanism.
 */
final class DoctorBranchCoverState
{
    /** Requested, undecided. */
    public const PENDING = 'pending';

    /** Refused. Never becomes current. */
    public const REJECTED = 'rejected';

    /** Withdrawn after approval. Never becomes current again. */
    public const CANCELLED = 'cancelled';

    /** Approved, but `starts_at` is still ahead of the given instant. */
    public const SCHEDULED = 'scheduled';

    /** Approved and current: starts_at <= at < ends_at. THE effective branch. */
    public const ACTIVE = 'active';

    /** Approved and run out. The effective branch is home again. */
    public const EXPIRED = 'expired';

    /**
     * The closed vocabulary. A value outside this list is not a state to be
     * interpreted generously; it is a row this build cannot read.
     *
     * @var list<string>
     */
    public const STATES = [
        self::PENDING,
        self::REJECTED,
        self::CANCELLED,
        self::SCHEDULED,
        self::ACTIVE,
        self::EXPIRED,
    ];

    /** Never instantiated: this is a vocabulary and a single derivation. */
    private function __construct() {}

    /**
     * Derive the state of one cover at one instant.
     *
     * Cancellation outranks the clock: a cover cancelled mid-period is not
     * ACTIVE, whatever `ends_at` says. A row missing either bound is treated as
     * EXPIRED rather than ACTIVE — an unreadable period must never widen a
     * doctor's authority.
     */
    public static function for(DoctorBranchCover $cover, CarbonInterface $at): string
    {
        if ($cover->cancelled_at !== null || $cover->status === DoctorBranchCover::STATUS_CANCELLED) {
            return self::CANCELLED;
        }

        if ($cover->status === DoctorBranchCover::STATUS_PENDING) {
            return self::PENDING;
        }

        if ($cover->status !== DoctorBranchCover::STATUS_APPROVED) {
            return self::REJECTED;
        }

        if ($cover->starts_at === null || $cover->ends_at === null) {
            return self::EXPIRED;
        }

        if ($cover->starts_at->greaterThan($at)) {
            return self::SCHEDULED;
        }

        return $cover->ends_at->greaterThan($at) ? self::ACTIVE : self::EXPIRED;
    }

    /** Human-readable, for the approver and doctor surfaces. */
    public static function label(string $state): string
    {
        return match ($state) {
            self::PENDING => 'Menunggu Persetujuan',
            self::REJECTED => 'Ditolak',
            self::CANCELLED => 'Dibatalkan',
            self::SCHEDULED => 'Terjadwal',
            self::ACTIVE => 'Sedang Berlaku',
            self::EXPIRED => 'Sudah Berakhir',
            default => $state,
        };
    }
}
