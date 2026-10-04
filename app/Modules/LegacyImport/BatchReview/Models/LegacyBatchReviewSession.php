<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSessionStatus;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSubmitStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reviewer's batch review work-queue — FEATURE-LEGACY-BATCH-REVIEW-PUBLISH-1 PR1.
 *
 * NOT a clinical record, and deliberately powerless. The clinical record of
 * review is `reviewed_by` / `reviewed_at` on the canonical staging row, written
 * only by the canonical review service under its own row lock. This row
 * remembers which documents a reviewer worked through and what they attested
 * about each; deleting every session would lose operator history and change no
 * patient's archive.
 *
 * @property int $id
 * @property string $uuid
 * @property string $import_type
 * @property string $status
 * @property int $opened_by
 * @property int|null $submitted_by
 * @property int|null $origin_branch_id
 * @property int $marked_reviewed
 * @property int $marked_blocked
 * @property int $marked_attention
 * @property int $applied_reviewed
 * @property int $refused_reviewed
 */
class LegacyBatchReviewSession extends Model
{
    use SoftDeletes;

    protected $table = 'stg_legacy_batch_review_sessions';

    protected $fillable = [
        'uuid',
        'import_type',
        'status',
        'opened_by',
        'submitted_by',
        'origin_branch_id',
        'marked_reviewed',
        'marked_blocked',
        'marked_attention',
        'applied_reviewed',
        'refused_reviewed',
        'submitted_at',
        'abandoned_at',
    ];

    protected $casts = [
        'opened_by' => 'integer',
        'submitted_by' => 'integer',
        'origin_branch_id' => 'integer',
        'marked_reviewed' => 'integer',
        'marked_blocked' => 'integer',
        'marked_attention' => 'integer',
        'applied_reviewed' => 'integer',
        'refused_reviewed' => 'integer',
        'submitted_at' => 'datetime',
        'abandoned_at' => 'datetime',
    ];

    public function decisions(): HasMany
    {
        return $this->hasMany(LegacyBatchReviewItemDecision::class, 'batch_review_session_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function isRme(): bool
    {
        return $this->import_type === LegacyImportType::LEGACY_RME;
    }

    /** May the reviewer still add or change decisions? */
    public function isMutable(): bool
    {
        return LegacyBatchReviewSessionStatus::isMutable($this->status);
    }

    public function canTransitionTo(string $status): bool
    {
        return LegacyBatchReviewSessionStatus::canTransition($this->status, $status);
    }

    public function statusLabel(): string
    {
        return LegacyBatchReviewSessionStatus::label($this->status);
    }

    /**
     * Decisions a submit pass still has work to do on.
     *
     * Drives resumability (§16): after an interrupted pass this returns exactly
     * the rows that were never applied, so a resumed submit cannot redo a
     * completed one.
     */
    public function retryableDecisions(): HasMany
    {
        return $this->decisions()
            ->whereIn('submit_status', LegacyBatchReviewSubmitStatus::retryable());
    }
}
