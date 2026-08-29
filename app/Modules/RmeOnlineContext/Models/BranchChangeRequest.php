<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — a request to move a locked working
 * branch mid-day, and the record of what a Super Admin decided about it.
 *
 * The status vocabulary is closed and every transition runs through
 * {@see BranchChangeApprovalService}.
 * Nothing here may be reached by mass assignment: `status`, `decided_by_user_id`,
 * `decided_at` and `applied_at` are the whole security value of the row, so
 * `$fillable` excludes them and the service writes them explicitly.
 */
class BranchChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * A request that outlived its clinical day. It can never authorise a switch
     * — see {@see self::isStaleForClinicalDay()}, which fails closed whether or
     * not this status was ever physically written.
     */
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'trx_branch_change_requests';

    /**
     * Only the fields a requester legitimately contributes, and even those are
     * re-derived server-side before the row is written. The decision fields are
     * absent on purpose: a forged `status=approved` or `decided_by_user_id=self`
     * in a payload has nowhere to land.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'requester_user_id',
        'clinical_date',
        'role_context',
        'source_branch_id',
        'destination_branch_id',
        'reason',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'requester_user_id' => 'integer',
            'clinical_date' => 'date',
            'source_branch_id' => 'integer',
            'destination_branch_id' => 'integer',
            'decided_by_user_id' => 'integer',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDecided(): bool
    {
        return ! $this->isPending();
    }

    /**
     * A request belonging to a clinical day that is no longer today.
     *
     * Evaluated from the row itself rather than from a status column, so a
     * yesterday's PENDING request is refused even if no background job has run
     * to stamp it EXPIRED. Security correctness must never depend on a cron.
     */
    public function isStaleForClinicalDay(string $clinicalToday): bool
    {
        return $this->clinical_date?->toDateString() !== $clinicalToday;
    }

    /**
     * Human-readable status, for the operator and approver surfaces.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Menunggu Persetujuan',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_CANCELLED => 'Dibatalkan',
            self::STATUS_EXPIRED => 'Kedaluwarsa',
            default => (string) $this->status,
        };
    }
}
