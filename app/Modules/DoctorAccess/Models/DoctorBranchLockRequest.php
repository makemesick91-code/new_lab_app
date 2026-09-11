<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — a request to SET or MOVE a
 * doctor's HOME_LOCKED_BRANCH, and the record of what an approver decided.
 *
 * Both of owner decision O1's workflows live here, told apart by
 * `request_type`: an initial assignment has no `source_branch_id`, a transfer
 * has one and the approval re-asserts it under a row lock.
 *
 * The status vocabulary is closed and every transition runs through the
 * approval service. Nothing here may be reached by mass assignment: `status`,
 * `decided_by_user_id`, `decided_at` and `applied_at` are the whole security
 * value of the row, so `$fillable` excludes them and the repository writes
 * them explicitly with forceFill.
 *
 * There is no EXPIRED status. Its sibling trx_branch_change_requests has one
 * because a request belongs to a clinical day; a permanent lock has no day, so
 * a pending request stays pending until it is decided or cancelled.
 */
class DoctorBranchLockRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** UNSET -> approved home branch. `source_branch_id` is NULL. */
    public const TYPE_INITIAL_ASSIGNMENT = 'initial_assignment';

    /** Home branch A -> approved home branch B. `source_branch_id` is bound. */
    public const TYPE_TRANSFER = 'transfer';

    public const TYPES = [
        self::TYPE_INITIAL_ASSIGNMENT,
        self::TYPE_TRANSFER,
    ];

    protected $table = 'trx_doctor_branch_lock_requests';

    /**
     * Only the fields a requester legitimately contributes, and even those are
     * re-derived server-side before the row is written. The decision fields are
     * absent on purpose: a forged `status=approved` or
     * `decided_by_user_id=self` in a payload has nowhere to land.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'doctor_id',
        'requester_user_id',
        'request_type',
        'source_branch_id',
        'destination_branch_id',
        'reason',
        'requested_at',
    ];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'requester_user_id' => 'integer',
            'source_branch_id' => 'integer',
            'destination_branch_id' => 'integer',
            'decided_by_user_id' => 'integer',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
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

    public function isInitialAssignment(): bool
    {
        return $this->request_type === self::TYPE_INITIAL_ASSIGNMENT;
    }

    public function isTransfer(): bool
    {
        return $this->request_type === self::TYPE_TRANSFER;
    }

    /**
     * Was this approval already consumed?
     *
     * Read from `applied_at` rather than from `status`, because the stamp is
     * written in the same transaction that moved the lock. A row that says
     * APPROVED but carries no `applied_at` never moved anything.
     */
    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /** Human-readable status, for the requester and approver surfaces. */
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

    /** Human-readable request type, for the same surfaces. */
    public function typeLabel(): string
    {
        return match ($this->request_type) {
            self::TYPE_INITIAL_ASSIGNMENT => 'Penetapan Awal',
            self::TYPE_TRANSFER => 'Perpindahan Permanen',
            default => (string) $this->request_type,
        };
    }
}
