<?php

namespace App\Modules\Patient\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Patient\Services\PatientDocumentAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * Sprint 61.1 — private patient identity document (KTP scan).
 *
 * The actual file lives on the private `local` disk; this model only holds the
 * pointer + integrity metadata. Never expose {@see $file_path} directly to the
 * UI — serve through the access-controlled document route instead.
 *
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — `captured_branch_id` is
 * the branch the card was captured at. It is the ONLY input to the per-branch
 * access decision ({@see PatientDocumentAccessService}),
 * it is written by the server at attach time, and it is WRITE-ONCE: a value may
 * be recorded where none existed (attach, or the evidence backfill), but a
 * recorded value can never be changed, so the document's ownership cannot be
 * silently re-homed. NULL means provenance unknown and fails closed.
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
        'captured_branch_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (PatientDocument $document): void {
            if (! $document->isDirty('captured_branch_id')) {
                return;
            }

            if ($document->getOriginal('captured_branch_id') !== null) {
                throw new LogicException('A recorded capture branch is write-once; patient document ownership cannot be reassigned.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'file_size' => 'integer',
            'compressed_file_size' => 'integer',
            'uploaded_by' => 'integer',
            'captured_branch_id' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function capturedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'captured_branch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
