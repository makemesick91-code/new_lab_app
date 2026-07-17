<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4C — per-branch readiness profile + internal pilot state.
 *
 * INTERNAL readiness only. `pilot_ready_internal` never means externally ready;
 * external readiness stays a separate credential blocker. No PII is stored.
 */
class SatusehatBranchPilotProfile extends Model
{
    protected $table = 'mst_satusehat_branch_pilot_profiles';

    // --- Pilot status ---
    public const STATUS_NONE = 'none';

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SUSPENDED = 'suspended';

    public const PILOT_STATUSES = [
        self::STATUS_NONE,
        self::STATUS_CANDIDATE,
        self::STATUS_APPROVED,
        self::STATUS_SUSPENDED,
    ];

    // --- Readiness stages ---
    public const STAGE_NOT_STARTED = 'not_started';

    public const STAGE_PROFILING = 'profiling';

    public const STAGE_REMEDIATION = 'remediation';

    public const STAGE_UAT_READY = 'uat_ready';

    public const STAGE_PILOT_READY_INTERNAL = 'pilot_ready_internal';

    public const STAGE_BLOCKED_EXTERNAL_CREDENTIAL = 'blocked_external_credential';

    public const STAGE_SUSPENDED = 'suspended';

    public const STAGES = [
        self::STAGE_NOT_STARTED,
        self::STAGE_PROFILING,
        self::STAGE_REMEDIATION,
        self::STAGE_UAT_READY,
        self::STAGE_PILOT_READY_INTERNAL,
        self::STAGE_BLOCKED_EXTERNAL_CREDENTIAL,
        self::STAGE_SUSPENDED,
    ];

    protected $fillable = [
        'environment',
        'branch_id',
        'pilot_status',
        'readiness_stage',
        'internal_readiness_score',
        'score_version',
        'open_hard_issues',
        'open_soft_issues',
        'diagnosis_adoption_rate',
        'treatment_mapping_rate',
        'dental_readiness_rate',
        'patient_data_readiness_rate',
        'practitioner_readiness_rate',
        'location_readiness_rate',
        'local_conformance_rate',
        'threshold_overrides',
        'threshold_version',
        'last_recalculated_at',
        'last_rehearsal_at',
        'last_rehearsal_result',
        'selected_by',
        'selected_at',
        'approved_by',
        'approved_at',
        'suspended_at',
        'suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'internal_readiness_score' => 'integer',
            'score_version' => 'integer',
            'open_hard_issues' => 'integer',
            'open_soft_issues' => 'integer',
            'diagnosis_adoption_rate' => 'decimal:2',
            'treatment_mapping_rate' => 'decimal:2',
            'dental_readiness_rate' => 'decimal:2',
            'patient_data_readiness_rate' => 'decimal:2',
            'practitioner_readiness_rate' => 'decimal:2',
            'location_readiness_rate' => 'decimal:2',
            'local_conformance_rate' => 'decimal:2',
            'threshold_overrides' => 'array',
            'threshold_version' => 'integer',
            'last_recalculated_at' => 'datetime',
            'last_rehearsal_at' => 'datetime',
            'selected_by' => 'integer',
            'selected_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function isApprovedPilot(): bool
    {
        return $this->pilot_status === self::STATUS_APPROVED;
    }

    public function isSuspended(): bool
    {
        return $this->pilot_status === self::STATUS_SUSPENDED;
    }

    public function pilotStatusLabel(): string
    {
        return match ($this->pilot_status) {
            self::STATUS_CANDIDATE => 'Kandidat Pilot',
            self::STATUS_APPROVED => 'Pilot Disetujui',
            self::STATUS_SUSPENDED => 'Pilot Ditangguhkan',
            default => 'Bukan Pilot',
        };
    }

    public function stageLabel(): string
    {
        return match ($this->readiness_stage) {
            self::STAGE_PROFILING => 'Profiling',
            self::STAGE_REMEDIATION => 'Remediasi',
            self::STAGE_UAT_READY => 'Siap UAT',
            self::STAGE_PILOT_READY_INTERNAL => 'Siap Pilot Internal',
            self::STAGE_BLOCKED_EXTERNAL_CREDENTIAL => 'Terblokir Kredensial Eksternal',
            self::STAGE_SUSPENDED => 'Ditangguhkan',
            default => 'Belum Dimulai',
        };
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
