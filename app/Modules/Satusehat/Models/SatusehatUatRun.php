<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SATUSEHAT-4D — human operator UAT run.
 *
 * Records a real operator UAT session. Evidence stays synthetic / PII-safe.
 * A run reaching SIGNED_OFF is the mandatory precondition for an operational
 * GO decision; automated tests never substitute for a signed-off run.
 */
class SatusehatUatRun extends Model
{
    protected $table = 'trx_satusehat_uat_runs';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SIGNED_OFF = 'signed_off';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'environment',
        'rollout_wave_id',
        'title',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rollout_wave_id' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function isSignedOff(): bool
    {
        return $this->status === self::STATUS_SIGNED_OFF;
    }

    public function wave(): BelongsTo
    {
        return $this->belongsTo(SatusehatRolloutWave::class, 'rollout_wave_id');
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(SatusehatUatScenario::class, 'uat_run_id');
    }

    public function signoffs(): HasMany
    {
        return $this->hasMany(SatusehatUatSignoff::class, 'uat_run_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
