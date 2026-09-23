<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Support\DoctorBranchCoverState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — TEMPORARY_BRANCH_COVER.
 *
 * Approved, time-boxed authority to work somewhere other than home. It never
 * touches the home lock; when it stops being current the effective branch
 * resolves back to home on the next request.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DECLARE.
 *
 * There is no STATUS_ACTIVE and no STATUS_EXPIRED. Section Q: those are
 * DERIVED from approval plus timestamps and must never be persisted, because a
 * persisted one is a value some job has to maintain and a late job means an
 * expired cover that is still effective. Leaving the constants out means there
 * is no name for a writer to assign. The derived vocabulary lives on
 * {@see DoctorBranchCoverState} and is only reachable through a call that
 * takes the instant to judge against.
 *
 * THE PERIOD IS HALF-OPEN, [starts_at, ends_at).
 *
 * A cover that ends at 17:00 does not cover 17:00, so a second cover may start
 * there. A closed interval would refuse a legitimate back-to-back handover and
 * would make two covers 'overlap' at a single instant.
 */
class DoctorBranchCover extends Model
{
    /** Requested, awaiting a Super Admin or Supervisor RME decision. */
    public const STATUS_PENDING = 'pending';

    /**
     * Granted. Whether it is SCHEDULED, ACTIVE or EXPIRED right now is not
     * recorded — ask {@see self::state()}.
     */
    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Withdrawn after approval. Stops being current immediately. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Every status that is ever WRITTEN. Deliberately shorter than the state
     * vocabulary a reader sees.
     */
    public const PERSISTED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'trx_doctor_branch_covers';

    /**
     * Requester-contributed only, and re-derived server-side before the write.
     * `status`, the decision stamps and the cancellation stamps are absent:
     * a doctor must never self-approve or self-extend a cover.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'doctor_id',
        'requester_user_id',
        'source_home_branch_id',
        'target_branch_id',
        'starts_at',
        'ends_at',
        'reason',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'requester_user_id' => 'integer',
            'source_home_branch_id' => 'integer',
            'target_branch_id' => 'integer',
            'decided_by_user_id' => 'integer',
            'cancelled_by_user_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function sourceHomeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_home_branch_id');
    }

    public function targetBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'target_branch_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Granted and not withdrawn. Says nothing about the clock. */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->cancelled_at === null;
    }

    /**
     * Is this cover the doctor's operational authority at the given instant?
     *
     * The whole effective-branch rule reduces to this predicate. It reads only
     * the row and the instant it is handed — no cron, no cached flag, no
     * ambient clock — so an expired cover can never remain effective because a
     * queue worker was late.
     */
    public function coversInstant(CarbonInterface $at): bool
    {
        if (! $this->isApproved() || $this->starts_at === null || $this->ends_at === null) {
            return false;
        }

        return $this->starts_at->lessThanOrEqualTo($at) && $this->ends_at->greaterThan($at);
    }

    /**
     * Would this cover collide with the proposed period?
     *
     * Half-open on both sides, so `ends_at == $start` is NOT a collision. This
     * is the predicate the approval service evaluates under the doctor's
     * mst_doctor_branch_locks row lock, because no index on either engine can
     * express it.
     */
    public function overlapsPeriod(CarbonInterface $start, CarbonInterface $end): bool
    {
        if (! $this->isApproved() || $this->starts_at === null || $this->ends_at === null) {
            return false;
        }

        return $this->starts_at->lessThan($end) && $this->ends_at->greaterThan($start);
    }

    /** SCHEDULED / ACTIVE / EXPIRED / PENDING / REJECTED / CANCELLED. */
    public function state(CarbonInterface $at): string
    {
        return DoctorBranchCoverState::for($this, $at);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Menunggu Persetujuan',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_CANCELLED => 'Dibatalkan',
            default => (string) $this->status,
        };
    }
}
