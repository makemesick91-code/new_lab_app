<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishItemStatus;
use App\Modules\LegacyImport\BatchPublish\Support\LegacyBatchPublishRunStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A publisher's batch publish work-queue — PR2 §10.
 *
 * ORCHESTRATION ONLY, and deliberately powerless. Nothing on this row is ever
 * consulted to decide whether a document may publish; the canonical import
 * status and the archive record's UNIQUE(source_import_id) remain clinical
 * truth. Deleting every run would lose the operator's report and un-publish
 * nothing.
 *
 * @property int $id
 * @property string $uuid
 * @property string $import_type
 * @property string $status
 * @property int $started_by
 * @property int|null $origin_branch_id
 * @property int $selected_count
 * @property int $attempted_count
 * @property int $published_count
 * @property int $refused_count
 */
class LegacyBatchPublishRun extends Model
{
    use SoftDeletes;

    protected $table = 'stg_legacy_batch_publish_runs';

    protected $fillable = [
        'uuid',
        'import_type',
        'status',
        'started_by',
        'origin_branch_id',
        'selected_count',
        'attempted_count',
        'published_count',
        'refused_count',
        'started_at',
        'completed_at',
        'abandoned_at',
    ];

    protected $casts = [
        'started_by' => 'integer',
        'origin_branch_id' => 'integer',
        'selected_count' => 'integer',
        'attempted_count' => 'integer',
        'published_count' => 'integer',
        'refused_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'abandoned_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(LegacyBatchPublishItem::class, 'batch_publish_run_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function isRme(): bool
    {
        return $this->import_type === LegacyImportType::LEGACY_RME;
    }

    /** May the operator still change the selection? */
    public function isMutable(): bool
    {
        return LegacyBatchPublishRunStatus::isMutable($this->status);
    }

    public function canTransitionTo(string $status): bool
    {
        return LegacyBatchPublishRunStatus::canTransition($this->status, $status);
    }

    public function statusLabel(): string
    {
        return LegacyBatchPublishRunStatus::label($this->status);
    }

    /**
     * Items a publish pass still has work to do on.
     *
     * Drives resumability (§9): after an interrupted pass this returns exactly
     * the items never attempted, so a resumed pass cannot re-attempt a finished
     * one and cannot create a second publication.
     */
    public function attemptableItems(): HasMany
    {
        return $this->items()
            ->whereIn('status', LegacyBatchPublishItemStatus::attemptable());
    }
}
