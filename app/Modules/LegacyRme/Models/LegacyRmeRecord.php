<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use App\Modules\Patient\Models\Patient;
use Database\Factories\LegacyRmeRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * LEGACY-RME-PDF-1A — a published legacy (historical) RME record.
 *
 * Immutable once published: patient, date, file, hash and pages are never
 * edited in place and the row is never hard-deleted. A correction is a VOID
 * (with a reason) plus a fresh import.
 *
 * A legacy record is part of the patient's medical history but is NOT a
 * visit: it produces no invoice, no payment, no consent, no odontogram, no
 * lab candidate/order and no SATUSEHAT submission, and it never counts toward
 * visit or revenue KPI.
 */
class LegacyRmeRecord extends Model
{
    use HasFactory;

    public const STATUS_PUBLISHED = LegacyRmeRecordStatus::PUBLISHED;

    public const STATUS_VOID = LegacyRmeRecordStatus::VOID;

    /** @var list<string> */
    public const STATUSES = LegacyRmeRecordStatus::ALL;

    protected $table = 'trx_rme_legacy_records';

    protected $fillable = [
        'uuid',
        'patient_id',
        'origin_branch_id',
        'rme_date',
        'latest_rme_date',
        'title',
        'description',
        'source_disk',
        'source_pdf_path',
        'source_pdf_sha256',
        'normalized_content_hash',
        'page_count',
        'status',
        'source_import_id',
        'imported_by',
        'published_by',
        'published_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected static function newFactory(): LegacyRmeRecordFactory
    {
        return LegacyRmeRecordFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            if (blank($record->uuid)) {
                $record->uuid = (string) Str::uuid();
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
            'rme_date' => 'date',
            'latest_rme_date' => 'date',
            'page_count' => 'integer',
            'source_import_id' => 'integer',
            'imported_by' => 'integer',
            'published_by' => 'integer',
            'voided_by' => 'integer',
            'published_at' => 'datetime',
            'voided_at' => 'datetime',
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

    public function sourceImport(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeImport::class, 'source_import_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(LegacyRmeRecordPage::class, 'rme_legacy_record_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function canTransitionTo(string $status): bool
    {
        return LegacyRmeRecordStatus::canTransition($this->status, $status);
    }
}
