<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — HOME_LOCKED_BRANCH.
 *
 * One doctor, one home branch, set only by an approved workflow. The absence
 * of a row is UNSET, and UNSET is the compatibility state: a doctor without a
 * row keeps the pre-sprint selection behaviour exactly (owner decision O1).
 *
 * A cover NEVER changes this row. `home_branch_id` moves only through an
 * approved initial assignment or an approved transfer, both applied inside the
 * approval transaction.
 *
 * MODULE NOTE. This model lives in App\Modules\DoctorAccess, NOT in
 * App\Modules\Doctor, which is the doctor MASTER-DATA context and hosts
 * DoctorClinicalBranchResolver — a resolver that deliberately stays
 * practice-set-wide and that owner decision O3 freezes. Putting an effective-
 * branch authority beside a resolver that must behave oppositely is a
 * maintenance hazard, so this sprint's classes get their own bounded context.
 */
class DoctorBranchLock extends Model
{
    /** The first branch this doctor was ever locked to. */
    public const VIA_INITIAL_ASSIGNMENT = 'initial_assignment';

    /** A later approved move from one home branch to another. */
    public const VIA_TRANSFER = 'transfer';

    public const ESTABLISHED_VIA = [
        self::VIA_INITIAL_ASSIGNMENT,
        self::VIA_TRANSFER,
    ];

    protected $table = 'mst_doctor_branch_locks';

    /**
     * Nothing is fillable. `home_branch_id` IS the authority, so a request
     * payload must never be able to drive it by mass assignment — the same
     * reading as DoctorDeviceAuthorization (:65-70). The approval repository
     * writes with forceFill, the pattern already used at
     * app/Modules/DoctorDevice/Repositories/DoctorDeviceAuthorizationRepository.php:60-67.
     *
     * @var array<int, string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'doctor_id' => 'integer',
            'home_branch_id' => 'integer',
            'previous_branch_id' => 'integer',
            'established_by_user_id' => 'integer',
            'established_at' => 'datetime',
            'last_transferred_at' => 'datetime',
            'transfer_count' => 'integer',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    public function previousBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'previous_branch_id');
    }

    public function establishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'established_by_user_id');
    }

    /** Every approval that ever moved this lock, newest last. */
    public function requests(): HasMany
    {
        return $this->hasMany(DoctorBranchLockRequest::class, 'doctor_id', 'doctor_id');
    }

    /** Every cover ever granted against this doctor. */
    public function covers(): HasMany
    {
        return $this->hasMany(DoctorBranchCover::class, 'doctor_id', 'doctor_id');
    }

    /**
     * True when the given branch is the home this doctor is committed to.
     *
     * Mirrors DailyBranchContext::isLockedTo() (:84-87). Note this asks about
     * HOME, not about the effective branch — an active cover does not change
     * the answer, and a caller that wants the effective branch must ask the
     * resolver, not this model.
     */
    public function isLockedTo(int $branchId): bool
    {
        return (int) $this->home_branch_id === $branchId;
    }
}
