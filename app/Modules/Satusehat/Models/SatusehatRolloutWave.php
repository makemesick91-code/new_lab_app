<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SATUSEHAT-4D — controlled multi-branch rollout wave.
 *
 * INTERNAL readiness scale-up only. A wave never enables SATUSEHAT external
 * send or production. No wave is active by default (starts DRAFT). No PII.
 */
class SatusehatRolloutWave extends Model
{
    protected $table = 'mst_satusehat_rollout_waves';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROFILING = 'profiling';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_IN_REMEDIATION = 'in_remediation';

    public const STATUS_UAT_SCHEDULED = 'uat_scheduled';

    public const STATUS_UAT_IN_PROGRESS = 'uat_in_progress';

    public const STATUS_REHEARSAL_READY = 'rehearsal_ready';

    public const STATUS_PILOT_READY_INTERNAL = 'pilot_ready_internal';

    public const STATUS_BLOCKED_EXTERNAL_CREDENTIAL = 'blocked_external_credential';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    /** Statuses that count as "active" for the single-active-wave rule. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PROFILING,
        self::STATUS_APPROVED,
        self::STATUS_IN_REMEDIATION,
        self::STATUS_UAT_SCHEDULED,
        self::STATUS_UAT_IN_PROGRESS,
        self::STATUS_REHEARSAL_READY,
        self::STATUS_PILOT_READY_INTERNAL,
        self::STATUS_BLOCKED_EXTERNAL_CREDENTIAL,
    ];

    protected $fillable = [
        'environment',
        'name',
        'sequence',
        'status',
        'scope',
        'threshold_version',
        'operational_owner_id',
        'clinical_owner_id',
        'technical_owner_id',
        'approved_by',
        'approved_at',
        'started_at',
        'target_date',
        'completed_at',
        'suspended_at',
        'suspension_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'threshold_version' => 'integer',
            'operational_owner_id' => 'integer',
            'clinical_owner_id' => 'integer',
            'technical_owner_id' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'target_date' => 'date',
            'completed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PROFILING => 'Profiling',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_IN_REMEDIATION => 'Remediasi',
            self::STATUS_UAT_SCHEDULED => 'UAT Dijadwalkan',
            self::STATUS_UAT_IN_PROGRESS => 'UAT Berlangsung',
            self::STATUS_REHEARSAL_READY => 'Siap Rehearsal',
            self::STATUS_PILOT_READY_INTERNAL => 'Siap Pilot Internal',
            self::STATUS_BLOCKED_EXTERNAL_CREDENTIAL => 'Terblokir Kredensial Eksternal',
            self::STATUS_SUSPENDED => 'Ditangguhkan',
            self::STATUS_CLOSED => 'Ditutup',
            default => 'Draf',
        };
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SatusehatWaveBranchMembership::class, 'rollout_wave_id');
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', SatusehatWaveBranchMembership::STATUS_ENROLLED);
    }

    public function operationalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operational_owner_id');
    }

    public function clinicalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinical_owner_id');
    }

    public function technicalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technical_owner_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
