<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4D — append-only branch readiness transition history.
 *
 * Records promotion / demotion / suspension / resume with reason + actor.
 * External send / production are never a transition target. No PII.
 */
class SatusehatBranchTransition extends Model
{
    protected $table = 'trx_satusehat_branch_transitions';

    public const UPDATED_AT = null;

    public const TYPE_PROMOTION = 'promotion';

    public const TYPE_DEMOTION = 'demotion';

    public const TYPE_SUSPENSION = 'suspension';

    public const TYPE_RESUME = 'resume';

    protected $fillable = [
        'environment',
        'branch_id',
        'rollout_wave_id',
        'from_stage',
        'to_stage',
        'transition_type',
        'reason',
        'gate_snapshot',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'rollout_wave_id' => 'integer',
            'gate_snapshot' => 'array',
            'actor_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(SatusehatRolloutWave::class, 'rollout_wave_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
