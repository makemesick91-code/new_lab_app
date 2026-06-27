<?php

namespace App\Modules\Patient\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sprint 62.3 — Legacy RME Patient Batch Import (staging header).
 */
class LegacyPatientImportBatch extends Model
{
    use SoftDeletes;

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_COMMITTING = 'committing';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const STATUS_FAILED = 'failed';

    protected $table = 'stg_legacy_patient_import_batches';

    protected $fillable = [
        'uuid',
        'uploaded_by',
        'original_filename',
        'stored_path',
        'file_hash',
        'status',
        'total_rows',
        'valid_rows',
        'warning_rows',
        'error_rows',
        'committed_rows',
        'error_summary',
        'committed_by',
        'committed_at',
        'rolled_back_by',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'warning_rows' => 'integer',
            'error_rows' => 'integer',
            'committed_rows' => 'integer',
            'error_summary' => 'array',
            'committed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(LegacyPatientImportRow::class, 'batch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function committable(): bool
    {
        return $this->status === self::STATUS_VALIDATED && ($this->valid_rows + $this->warning_rows) > 0;
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }
}
