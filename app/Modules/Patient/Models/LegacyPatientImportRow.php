<?php

namespace App\Modules\Patient\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import (staging detail).
 *
 * Full KTP/NIK is never stored in a dedicated rendered column — it lives only
 * inside raw_payload / normalized_payload (JSON, server-side). The UI reads
 * ktp_masked. Advisory columns are staged only and never promoted to RME/visit.
 */
class LegacyPatientImportRow extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_WARNING = 'warning';

    public const STATUS_ERROR = 'error';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $table = 'stg_legacy_patient_imports';

    protected $fillable = [
        'batch_id',
        'row_number',
        'raw_payload',
        'normalized_payload',
        'status',
        'errors',
        'warnings',
        'generated_medical_record_number',
        'matched_branch_id',
        'matched_room_id',
        'matched_doctor_id',
        'committed_patient_id',
        'ktp_masked',
        'patient_name',
        'phone',
        'wa_number',
        'email',
        'gender',
        'birth_date',
        'address',
        'occupation',
        'manual_rm_number',
        'legacy_patient_id',
        'legacy_timestamp',
        'advisory_initial_treatment',
        'advisory_chief_complaint',
        'advisory_doctor_signature',
        'advisory_patient_signature',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
            'matched_branch_id' => 'integer',
            'matched_room_id' => 'integer',
            'matched_doctor_id' => 'integer',
            'committed_patient_id' => 'integer',
            'birth_date' => 'date',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LegacyPatientImportBatch::class, 'batch_id');
    }

    /**
     * Rows that may be inserted at commit (no blocking error).
     */
    public function isCommittable(): bool
    {
        return in_array($this->status, [self::STATUS_VALID, self::STATUS_WARNING], true);
    }
}
