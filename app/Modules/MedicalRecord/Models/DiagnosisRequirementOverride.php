<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4B — reasoned, time-boxed emergency override of the pilot-enforced
 * structured diagnosis requirement. Append-only: no update/delete endpoint
 * exists. Never makes the SATUSEHAT candidate ready.
 */
class DiagnosisRequirementOverride extends Model
{
    protected $table = 'trx_diagnosis_requirement_overrides';

    protected $fillable = [
        'medical_record_id',
        'clinic_visit_id',
        'branch_id',
        'used_by',
        'reason',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'medical_record_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'branch_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
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

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
