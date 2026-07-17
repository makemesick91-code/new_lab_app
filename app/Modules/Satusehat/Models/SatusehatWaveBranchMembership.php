<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4D — wave ↔ branch enrollment.
 *
 * A branch may belong to at most one active (enrolled) wave. Enforced by the
 * service layer + a partial unique index. Only RME-enabled branches enroll;
 * MAIN is never enrollable (service layer).
 */
class SatusehatWaveBranchMembership extends Model
{
    protected $table = 'trx_satusehat_wave_branch_memberships';

    public const STATUS_ENROLLED = 'enrolled';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'environment',
        'rollout_wave_id',
        'branch_id',
        'status',
        'enrolled_by',
        'enrolled_at',
        'removed_by',
        'removed_at',
        'removal_reason',
    ];

    protected function casts(): array
    {
        return [
            'rollout_wave_id' => 'integer',
            'branch_id' => 'integer',
            'enrolled_by' => 'integer',
            'enrolled_at' => 'datetime',
            'removed_by' => 'integer',
            'removed_at' => 'datetime',
        ];
    }

    public function isEnrolled(): bool
    {
        return $this->status === self::STATUS_ENROLLED;
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(SatusehatRolloutWave::class, 'rollout_wave_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }
}
