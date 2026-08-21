<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\Patient\Models\Patient;
use Database\Factories\LegacyOdontogramImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * FIX-04b — staging row for one historical (legacy) odontogram chart.
 *
 * NOT a clinic visit and NOT a native odontogram: it never triggers the
 * visit/cashier workflow, billing, consent, lab or SATUSEHAT.
 *
 * `selected_odontogram_date` is the operator-chosen historical date read from
 * the document. `earliest_native_odontogram_date_snapshot` is the
 * server-computed cutoff it was validated against — the client supplies neither.
 *
 * Business rules (transitions, date validation, branch derivation, publishing)
 * live in the services; this model only carries structure, casts and relations.
 */
class LegacyOdontogramImport extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_DRAFT = LegacyOdontogramImportStatus::DRAFT;

    public const STATUS_UPLOADED = LegacyOdontogramImportStatus::UPLOADED;

    public const STATUS_QUEUED = LegacyOdontogramImportStatus::QUEUED;

    public const STATUS_PROCESSING = LegacyOdontogramImportStatus::PROCESSING;

    public const STATUS_READY_FOR_REVIEW = LegacyOdontogramImportStatus::READY_FOR_REVIEW;

    public const STATUS_REVIEWED = LegacyOdontogramImportStatus::REVIEWED;

    public const STATUS_PUBLISHED = LegacyOdontogramImportStatus::PUBLISHED;

    public const STATUS_FAILED = LegacyOdontogramImportStatus::FAILED;

    public const STATUS_CANCELLED = LegacyOdontogramImportStatus::CANCELLED;

    /** @var list<string> */
    public const STATUSES = LegacyOdontogramImportStatus::ALL;

    protected $table = 'stg_odontogram_legacy_imports';

    /**
     * `origin_branch_id`, `source_branch_code` and
     * `source_medical_record_number` are fillable because the INTAKE SERVICE
     * writes them once, from the server-side resolution. No update path in this
     * module writes them again and no form field maps to them, so a request can
     * never reach them: the FormRequest does not accept a branch, and the
     * service passes only what the resolver returned.
     */
    protected $fillable = [
        'uuid',
        'patient_id',
        'origin_branch_id',
        'source_branch_code',
        'source_medical_record_number',
        'selected_odontogram_date',
        'earliest_native_odontogram_date_snapshot',
        'original_filename',
        'source_disk',
        'source_pdf_path',
        'source_pdf_sha256',
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

    /**
     * Module models must declare their factory explicitly: the convention
     * resolver would look for Database\Factories\Modules\LegacyOdontogram\… and
     * fail, because every factory in this codebase lives in
     * Database\Factories directly.
     */
    protected static function newFactory(): LegacyOdontogramImportFactory
    {
        return LegacyOdontogramImportFactory::new();
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
            'selected_odontogram_date' => 'date',
            'earliest_native_odontogram_date_snapshot' => 'date',
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
        return $this->hasMany(LegacyOdontogramImportPage::class, 'legacy_import_id');
    }

    public function record(): HasOne
    {
        // At most one published record per import — guaranteed by the
        // UNIQUE(source_import_id) constraint on trx_odontogram_legacy_records.
        return $this->hasOne(LegacyOdontogramRecord::class, 'source_import_id');
    }

    public function isTerminal(): bool
    {
        return LegacyOdontogramImportStatus::isTerminal($this->status);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function canTransitionTo(string $status): bool
    {
        return LegacyOdontogramImportStatus::canTransition($this->status, $status);
    }
}
