<?php

namespace App\Modules\PatientMerge\Models;

use App\Models\User;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An old Nomor RM that still finds its patient.
 *
 * Merging never deletes the number a patient was issued: staff and patients
 * keep using old cards. The source patient keeps the number on its own row
 * (so no future registration can be issued it), and this alias says which
 * patient it now resolves to.
 *
 * Immutable except for two governed writes, both performed by a merge: the
 * canonical pointer follows a later merge of the canonical patient, and a
 * reversed merge stamps `revoked_at` (it is never deleted).
 */
class PatientRmAlias extends Model
{
    protected $table = 'mst_patient_rm_aliases';

    protected $fillable = [
        'alias_medical_record_number',
        'source_patient_id',
        'canonical_patient_id',
        'alias_type',
        'merge_case_id',
        'created_by',
        'merged_at',
    ];

    protected function casts(): array
    {
        return [
            'source_patient_id' => 'integer',
            'canonical_patient_id' => 'integer',
            'merge_case_id' => 'integer',
            'merged_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function sourcePatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'source_patient_id')->withTrashed();
    }

    public function canonicalPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'canonical_patient_id')->withTrashed();
    }

    public function mergeCase(): BelongsTo
    {
        return $this->belongsTo(PatientMergeCase::class, 'merge_case_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
