<?php

namespace App\Modules\PatientMerge\Models;

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use App\Modules\PatientMerge\Support\PatientMergeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — one request to treat two
 * patient rows as one person.
 *
 * Workflow columns (status, reviewer, merge results, reversal) are NOT
 * fillable: only the services write them, through forceFill, so a posted
 * form can never mark a case approved or completed.
 */
class PatientMergeCase extends Model
{
    protected $table = 'trx_patient_merge_cases';

    protected $fillable = [
        'uuid',
        'case_number',
        'patient_a_id',
        'patient_b_id',
        'requested_by',
        'requested_at',
        'request_reason',
        'cross_branch',
    ];

    protected $hidden = ['identity_snapshot_encrypted'];

    protected function casts(): array
    {
        return [
            'patient_a_id' => 'integer',
            'patient_b_id' => 'integer',
            'canonical_patient_id' => 'integer',
            'source_patient_id' => 'integer',
            'requested_by' => 'integer',
            'reviewed_by' => 'integer',
            'submitted_by' => 'integer',
            'cross_branch' => 'boolean',
            'risk_flags' => 'array',
            'before_summary' => 'array',
            'after_summary' => 'array',
            'moved_records' => 'array',
            'reversal_assessment' => 'array',
            // Encrypted at rest: the full pre-merge identity of both patients.
            'identity_snapshot_encrypted' => 'encrypted:array',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'merged_at' => 'datetime',
            'reversal_requested_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function patientA(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_a_id')->withTrashed();
    }

    public function patientB(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_b_id')->withTrashed();
    }

    public function canonicalPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'canonical_patient_id')->withTrashed();
    }

    public function sourcePatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'source_patient_id')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function fieldResolutions(): HasMany
    {
        return $this->hasMany(PatientMergeFieldResolution::class, 'merge_case_id');
    }

    /**
     * Everyone who shaped what the reviewer approves: the requester, the
     * submitter, and every user who resolved a field or chose the canonical
     * patient. None of them may approve (maker-checker).
     *
     * @return array<int, int>
     */
    public function authorIds(): array
    {
        $ids = [(int) $this->requested_by, (int) $this->submitted_by];

        foreach ($this->fieldResolutions as $resolution) {
            $ids[] = (int) $resolution->resolved_by;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, PatientMergeStatus::OPEN, true);
    }

    public function isDraft(): bool
    {
        return $this->status === PatientMergeStatus::DRAFT;
    }

    public function isPendingReview(): bool
    {
        return $this->status === PatientMergeStatus::PENDING_REVIEW;
    }

    public function isCompleted(): bool
    {
        return $this->status === PatientMergeStatus::COMPLETED;
    }

    /** The other patient of the pair, given one of them. */
    public function otherPatientId(int $patientId): ?int
    {
        return match ($patientId) {
            $this->patient_a_id => $this->patient_b_id,
            $this->patient_b_id => $this->patient_a_id,
            default => null,
        };
    }

    public function involves(int $patientId): bool
    {
        return $patientId === $this->patient_a_id || $patientId === $this->patient_b_id;
    }
}
