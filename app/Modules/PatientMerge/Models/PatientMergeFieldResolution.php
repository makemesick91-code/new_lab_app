<?php

namespace App\Modules\PatientMerge\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provenance of one final identity field: which patient (or which manual
 * verification) it came from, who decided, and why.
 *
 * The value itself is encrypted at rest; `final_value_display` is the only
 * column a screen or audit row reads, and it is masked for KTP/NIK and phone.
 */
class PatientMergeFieldResolution extends Model
{
    protected $table = 'trx_patient_merge_field_resolutions';

    protected $fillable = [
        'merge_case_id',
        'field',
        'comparison_status',
        'source',
        'final_value_encrypted',
        'final_value_display',
        'manual_reason',
        'resolved_by',
        'resolved_at',
    ];

    protected $hidden = ['final_value_encrypted'];

    protected function casts(): array
    {
        return [
            'merge_case_id' => 'integer',
            'resolved_by' => 'integer',
            'final_value_encrypted' => 'encrypted',
            'resolved_at' => 'datetime',
        ];
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->source !== null;
    }
}
