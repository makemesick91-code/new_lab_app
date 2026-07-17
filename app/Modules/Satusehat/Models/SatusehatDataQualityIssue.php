<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4A — Deterministic data-quality issue.
 *
 * Idempotent per fingerprint; carries the remediation lifecycle. Waiving an
 * issue only silences workspace triage — it never changes the canonical
 * readiness engine's verdict, so a hard defect can never be waived to "ready".
 */
class SatusehatDataQualityIssue extends Model
{
    public const SEVERITY_HARD = 'hard';

    public const SEVERITY_SOFT = 'soft';

    public const SEVERITY_INFO = 'info';

    public const SEVERITIES = [self::SEVERITY_HARD, self::SEVERITY_SOFT, self::SEVERITY_INFO];

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_IN_REMEDIATION = 'in_remediation';

    public const STATUS_AWAITING_CLINICAL_REVIEW = 'awaiting_clinical_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REOPENED = 'reopened';

    public const STATUS_WAIVED = 'waived';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_IN_REMEDIATION,
        self::STATUS_AWAITING_CLINICAL_REVIEW,
        self::STATUS_RESOLVED,
        self::STATUS_REOPENED,
        self::STATUS_WAIVED,
        self::STATUS_UNSUPPORTED,
    ];

    /** States in which the defect still needs work (counted as "open"). */
    public const OPEN_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_IN_REMEDIATION,
        self::STATUS_AWAITING_CLINICAL_REVIEW,
        self::STATUS_REOPENED,
        self::STATUS_UNSUPPORTED,
    ];

    // --- SATUSEHAT-4C: issue SLA priority + escalation ---
    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL_INTERNAL = 'critical_internal';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_CRITICAL_INTERNAL,
    ];

    public const ESCALATION_NONE = 'none';

    public const ESCALATION_LEVELS = [
        'none',
        'branch_supervisor',
        'clinical_reviewer',
        'it_operator',
        'super_admin',
        'management',
    ];

    protected $table = 'trx_satusehat_data_quality_issues';

    protected $fillable = [
        'environment',
        'branch_id',
        'satusehat_candidate_id',
        'clinic_visit_id',
        'patient_id',
        'doctor_id',
        'rule_code',
        'severity',
        'status',
        'fingerprint',
        'entity_type',
        'entity_id',
        'field_path',
        'message',
        'remediation_action',
        'owner_role',
        'source_hash',
        'assigned_to',
        'assigned_at',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
        'resolution_type',
        'resolved_by',
        'waived_by',
        'waived_at',
        'waiver_reason',
        'waiver_expires_at',
        'metadata',
        // SATUSEHAT-4C SLA + escalation
        'priority',
        'assigned_role',
        'due_at',
        'escalation_level',
        'escalated_at',
        'resolution_evidence',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'satusehat_candidate_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'entity_id' => 'integer',
            'assigned_to' => 'integer',
            'assigned_at' => 'datetime',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'resolved_by' => 'integer',
            'waived_by' => 'integer',
            'waived_at' => 'datetime',
            'waiver_expires_at' => 'datetime',
            'metadata' => 'array',
            'due_at' => 'datetime',
            'escalated_at' => 'datetime',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Open AND past its SLA due time. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function priorityLabel(): string
    {
        return match ($this->priority) {
            self::PRIORITY_CRITICAL_INTERNAL => 'Kritis (Internal)',
            self::PRIORITY_HIGH => 'Tinggi',
            self::PRIORITY_LOW => 'Rendah',
            self::PRIORITY_NORMAL => 'Normal',
            default => '—',
        };
    }

    public function priorityTone(): string
    {
        return match ($this->priority) {
            self::PRIORITY_CRITICAL_INTERNAL => 'danger',
            self::PRIORITY_HIGH => 'warning',
            self::PRIORITY_LOW => 'neutral',
            default => 'info',
        };
    }

    public function isHard(): bool
    {
        return $this->severity === self::SEVERITY_HARD;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Terbuka',
            self::STATUS_ACKNOWLEDGED => 'Diakui',
            self::STATUS_IN_REMEDIATION => 'Sedang Diperbaiki',
            self::STATUS_AWAITING_CLINICAL_REVIEW => 'Menunggu Review Klinis',
            self::STATUS_RESOLVED => 'Selesai',
            self::STATUS_REOPENED => 'Dibuka Kembali',
            self::STATUS_WAIVED => 'Dikecualikan (Waiver)',
            self::STATUS_UNSUPPORTED => 'Belum Didukung Skema',
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_RESOLVED => 'success',
            self::STATUS_WAIVED => 'neutral',
            self::STATUS_AWAITING_CLINICAL_REVIEW => 'info',
            self::STATUS_IN_REMEDIATION, self::STATUS_ACKNOWLEDGED => 'warning',
            default => 'danger',
        };
    }

    public function severityTone(): string
    {
        return match ($this->severity) {
            self::SEVERITY_HARD => 'danger',
            self::SEVERITY_SOFT => 'warning',
            default => 'info',
        };
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SatusehatCandidate::class, 'satusehat_candidate_id');
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
