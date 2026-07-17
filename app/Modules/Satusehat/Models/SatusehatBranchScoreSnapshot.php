<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4D — append-only readiness score history (reproducible).
 *
 * Derived numbers + FK only, no PII. No updated_at (append-only).
 */
class SatusehatBranchScoreSnapshot extends Model
{
    protected $table = 'trx_satusehat_branch_score_snapshots';

    public const UPDATED_AT = null;

    protected $fillable = [
        'environment',
        'branch_id',
        'rollout_wave_id',
        'score',
        'score_version',
        'threshold_version',
        'open_hard_issues',
        'open_soft_issues',
        'has_hard_blocker',
        'component_breakdown',
        'readiness_stage',
        'captured_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'rollout_wave_id' => 'integer',
            'score' => 'integer',
            'score_version' => 'integer',
            'threshold_version' => 'integer',
            'open_hard_issues' => 'integer',
            'open_soft_issues' => 'integer',
            'has_hard_blocker' => 'boolean',
            'component_breakdown' => 'array',
            'captured_by' => 'integer',
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

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }
}
