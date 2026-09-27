<?php

namespace App\Modules\Patient\Models;

use App\Models\User;
use App\Modules\Patient\Services\LegacyPatientImportService;
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

    /**
     * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1 — a batch the
     * operator stopped before it was ever confirmed. It created zero patients,
     * which is what makes it a different event from STATUS_ROLLED_BACK.
     */
    public const STATUS_CANCELLED = 'cancelled';

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
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'revalidation_attempts',
        'revalidated_at',
        'source_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'warning_rows' => 'integer',
            'error_rows' => 'integer',
            'committed_rows' => 'integer',
            'revalidation_attempts' => 'integer',
            'error_summary' => 'array',
            'committed_at' => 'datetime',
            'rolled_back_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'revalidated_at' => 'datetime',
            'source_verified_at' => 'datetime',
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

    /**
     * The batch is staged and its whole-batch validation result is known.
     *
     * Both review states below are DERIVED from this plus `error_rows`. They are
     * not stored, because a stored state could disagree with the counters and
     * then two answers would exist to one question.
     */
    public function isAwaitingReview(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    /**
     * REVIEW_REQUIRED — the batch carries at least one ERROR row, so it may not
     * be imported at all.
     *
     * REVISION-LEGACY-PATIENT-STAGED-VERIFICATION-CANCEL-1. Sprint 62.3 let a
     * batch with errors import its valid rows and leave the failures behind.
     * That is the behaviour this revision removes: a partially imported file
     * cannot be reconciled afterwards, because "which of these 500 rows became
     * real patients" is not answerable from the file the operator still holds.
     */
    public function isReviewRequired(): bool
    {
        return $this->isAwaitingReview() && $this->error_rows > 0;
    }

    /**
     * READY_TO_IMPORT — zero errors, and at least one row to import.
     *
     * A batch of nothing but blank rows is not ready: confirming it would report
     * a successful import of no patients, which reads as success and is not.
     */
    public function isReadyToImport(): bool
    {
        return $this->isAwaitingReview()
            && $this->error_rows === 0
            && ($this->valid_rows + $this->warning_rows) > 0;
    }

    /**
     * Whether the CONFIRM endpoint may proceed.
     *
     * This is the server-side gate, not the button's enabled state. A crafted
     * POST reaches the same predicate.
     */
    public function committable(): bool
    {
        return $this->isReadyToImport();
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * A batch may be cancelled only BEFORE the import is committed.
     *
     * After commitment, withdrawing patients is {@see LegacyPatientImportService::rollback()},
     * which is transactional and refuses when a patient already has a downstream
     * visit. "Cancel and delete the patients it created" is deliberately not a
     * thing this workflow offers.
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, [
            self::STATUS_UPLOADED,
            self::STATUS_VALIDATED,
            self::STATUS_FAILED,
        ], true);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
