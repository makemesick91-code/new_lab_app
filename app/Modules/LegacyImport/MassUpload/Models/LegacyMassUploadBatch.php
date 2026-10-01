<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use Database\Factories\LegacyMassUploadBatchFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operator's mass-intake work-queue — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1.
 *
 * NOT a clinical record. This row remembers which archive an operator
 * submitted and what the server decided about each line in it. The archive
 * itself becomes clinical only when the canonical single-item service creates a
 * real import, and that import is what occupies a patient's document slot.
 *
 * @property int $id
 * @property string $uuid
 * @property string $import_type
 * @property string $status
 * @property int $created_by
 * @property int|null $confirmed_by
 * @property int|null $origin_branch_id
 * @property string|null $package_original_name
 * @property string $package_disk
 * @property string $package_path
 * @property string $package_sha256
 * @property int $package_bytes
 * @property string|null $manifest_sha256
 * @property int $total_items
 * @property int $eligible_items
 * @property int $warning_items
 * @property int $blocked_items
 * @property int $error_items
 * @property int $dispatched_items
 * @property int $failed_items
 * @property string|null $failure_code
 * @property string|null $failure_message
 */
class LegacyMassUploadBatch extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'stg_legacy_mass_upload_batches';

    protected $fillable = [
        'uuid',
        'import_type',
        'status',
        'created_by',
        'confirmed_by',
        'origin_branch_id',
        'package_original_name',
        'package_disk',
        'package_path',
        'package_sha256',
        'package_bytes',
        'manifest_sha256',
        'total_items',
        'eligible_items',
        'warning_items',
        'blocked_items',
        'error_items',
        'dispatched_items',
        'failed_items',
        'failure_code',
        'failure_message',
        'preflight_completed_at',
        'confirmed_at',
        'dispatch_started_at',
        'completed_at',
        'cancelled_at',
        'workspace_cleaned_at',
    ];

    protected function casts(): array
    {
        return [
            'package_bytes' => 'integer',
            'total_items' => 'integer',
            'eligible_items' => 'integer',
            'warning_items' => 'integer',
            'blocked_items' => 'integer',
            'error_items' => 'integer',
            'dispatched_items' => 'integer',
            'failed_items' => 'integer',
            'preflight_completed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'dispatch_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'workspace_cleaned_at' => 'datetime',
        ];
    }

    /**
     * Module models must declare this explicitly. Laravel would otherwise look
     * for Database\Factories\Modules\LegacyImport\MassUpload\Models\...Factory,
     * which does not exist — every factory in this codebase lives under
     * Database\Factories directly.
     */
    protected static function newFactory(): Factory
    {
        return LegacyMassUploadBatchFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<LegacyMassUploadItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(LegacyMassUploadItem::class, 'mass_upload_batch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<Branch, $this> */
    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function isRme(): bool
    {
        return $this->import_type === LegacyImportType::LEGACY_RME;
    }

    public function isOdontogram(): bool
    {
        return $this->import_type === LegacyImportType::LEGACY_ODONTOGRAM;
    }

    public function isTerminal(): bool
    {
        return LegacyMassUploadBatchStatus::isTerminal((string) $this->status);
    }

    public function isCancellable(): bool
    {
        return LegacyMassUploadBatchStatus::isCancellable((string) $this->status);
    }

    public function awaitingReview(): bool
    {
        return $this->status === LegacyMassUploadBatchStatus::PREFLIGHT_READY;
    }

    /**
     * True once at least one canonical import has been created from this batch.
     *
     * This is the question §29 turns on: after that point the batch can never
     * be "cancelled", because the things it produced are real documents whose
     * withdrawal is VOID under its own authority — not a side effect of tidying
     * up a work-queue row.
     */
    public function hasCreatedLifecycles(): bool
    {
        return $this->dispatched_items > 0;
    }

    public function statusLabel(): string
    {
        return LegacyMassUploadBatchStatus::label((string) $this->status);
    }

    public function documentTypeLabel(): string
    {
        return LegacyImportType::label((string) $this->import_type);
    }
}
