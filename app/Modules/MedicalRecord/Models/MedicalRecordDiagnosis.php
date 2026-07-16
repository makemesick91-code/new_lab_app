<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SATUSEHAT-4A — Structured diagnosis attached to a medical record.
 *
 * Never auto-created from free text; only a doctor (medical record update
 * authority) records one explicitly. Legacy records without rows stay fully
 * readable and are surfaced as MISSING_STRUCTURED_DIAGNOSIS, never backfilled.
 */
class MedicalRecordDiagnosis extends Model
{
    use SoftDeletes;

    public const ROLE_PRIMARY = 'primary';

    public const ROLE_SECONDARY = 'secondary';

    public const ROLES = [self::ROLE_PRIMARY, self::ROLE_SECONDARY];

    protected $table = 'trx_medical_record_diagnoses';

    protected $fillable = [
        'medical_record_id',
        'clinic_visit_id',
        'branch_id',
        'clinical_diagnosis_id',
        'diagnosis_role',
        'clinical_status',
        'verification_status',
        'diagnosed_by',
        'diagnosed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'medical_record_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'branch_id' => 'integer',
            'clinical_diagnosis_id' => 'integer',
            'diagnosed_at' => 'datetime',
        ];
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinicalDiagnosis(): BelongsTo
    {
        return $this->belongsTo(ClinicalDiagnosis::class, 'clinical_diagnosis_id');
    }

    public function diagnosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by');
    }
}
