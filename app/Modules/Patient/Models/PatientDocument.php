<?php

namespace App\Modules\Patient\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sprint 61.1 — private patient identity document (KTP scan).
 *
 * The actual file lives on the private `local` disk; this model only holds the
 * pointer + integrity metadata. Never expose {@see $file_path} directly to the
 * UI — serve through the access-controlled document route instead.
 */
class PatientDocument extends Model
{
    use SoftDeletes;

    public const TYPE_KTP = 'ktp';

    protected $table = 'mst_patient_documents';

    protected $fillable = [
        'patient_id',
        'document_type',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'compressed_file_size',
        'checksum',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'file_size' => 'integer',
            'compressed_file_size' => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
