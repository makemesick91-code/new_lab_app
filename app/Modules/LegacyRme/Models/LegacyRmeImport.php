<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\Patient\Models\Patient;
use Database\Factories\LegacyRmeImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * LEGACY-RME-PDF-1A — staging row for one historical (legacy) RME document.
 *
 * NOT a clinic visit and NOT a native medical record: it never triggers the
 * visit/cashier workflow, billing, consent, odontogram, lab or SATUSEHAT.
 *
 * `selected_rme_date` is the operator-chosen historical date read from the
 * document. `earliest_native_rme_date_snapshot` is the server-computed cutoff
 * it was validated against — the client never supplies either bound.
 *
 * Business rules (transitions, date validation, publishing) live in the
 * services; this model only carries structure, casts and relations.
 */
class LegacyRmeImport extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_DRAFT = LegacyRmeImportStatus::DRAFT;

    public const STATUS_UPLOADED = LegacyRmeImportStatus::UPLOADED;

    public const STATUS_QUEUED = LegacyRmeImportStatus::QUEUED;

    public const STATUS_PROCESSING = LegacyRmeImportStatus::PROCESSING;

    public const STATUS_READY_FOR_REVIEW = LegacyRmeImportStatus::READY_FOR_REVIEW;

    public const STATUS_REVIEWED = LegacyRmeImportStatus::REVIEWED;

    public const STATUS_PUBLISHED = LegacyRmeImportStatus::PUBLISHED;

    public const STATUS_FAILED = LegacyRmeImportStatus::FAILED;

    public const STATUS_CANCELLED = LegacyRmeImportStatus::CANCELLED;

    /** @var list<string> */
    public const STATUSES = LegacyRmeImportStatus::ALL;

    protected $table = 'stg_rme_legacy_imports';

    protected $fillable = [
        'uuid',
        'patient_id',
        'origin_branch_id',
        'selected_rme_date',
        'earliest_native_rme_date_snapshot',
        'original_filename',
        'source_disk',
        'source_pdf_path',
        'source_pdf_sha256',
        'normalized_content_hash',
        'mime_type',
        'size_bytes',
        'page_count',
        'dpi',
        'status',
        'failure_code',
        'failure_message',
        'uploaded_by',
        'reviewed_by',
        'published_by',
        'cancelled_by',
        'uploaded_at',
        'processing_started_at',
        'processing_completed_at',
        'reviewed_at',
        'published_at',
        'cancelled_at',
    ];

    protected static function newFactory(): LegacyRmeImportFactory
    {
        return LegacyRmeImportFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $import): void {
            if (blank($import->uuid)) {
                $import->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'origin_branch_id' => 'integer',
            'selected_rme_date' => 'date',
            'earliest_native_rme_date_snapshot' => 'date',
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'dpi' => 'integer',
            'uploaded_by' => 'integer',
            'reviewed_by' => 'integer',
            'published_by' => 'integer',
            'cancelled_by' => 'integer',
            'uploaded_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(LegacyRmeImportPage::class, 'legacy_import_id');
    }

    public function record(): HasOne
    {
        // At most one published record per import — guaranteed by the
        // UNIQUE(source_import_id) constraint on trx_rme_legacy_records.
        return $this->hasOne(LegacyRmeRecord::class, 'source_import_id');
    }

    public function isTerminal(): bool
    {
        return LegacyRmeImportStatus::isTerminal($this->status);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function canTransitionTo(string $status): bool
    {
        return LegacyRmeImportStatus::canTransition($this->status, $status);
    }
}
