<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Polymorphic file metadata (sys_attachments). Shared support used by Lab Order
 * in Sprint 3 and reusable by QC / Delivery / Invoice / Payment later.
 */
class Attachment extends Model
{
    use SoftDeletes;

    public const CATEGORIES = [
        // Sprint 3 — Lab Order
        'PRESCRIPTION',
        'CASE_PHOTO',
        'STL_FILE',
        'X_RAY',
        'OTHER_DOCUMENT',
        // Sprint 4 — Production (reuse polymorphic sys_attachments)
        'WORK_PHOTO',
        'DESIGN_FILE',
        'STL_REVISION',
        'REFERENCE_IMAGE',
        'PRODUCTION_NOTE',
        // Sprint 5 — Quality Control evidence
        'QC_PHOTO',
        'QC_NOTE',
        'QC_EVIDENCE',
        'QC_REJECTION_EVIDENCE',
    ];

    protected $table = 'sys_attachments';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'category',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
