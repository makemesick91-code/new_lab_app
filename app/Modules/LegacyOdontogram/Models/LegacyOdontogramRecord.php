<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use App\Modules\Patient\Models\Patient;
use Database\Factories\LegacyOdontogramRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * FIX-04b — a PUBLISHED legacy (historical) odontogram record.
 *
 * Immutable once published: patient, branch, date, file, hash and pages are
 * never edited in place and the row is never hard-deleted. A correction is a
 * VOID (with a reason) plus a fresh import — the repository therefore exposes
 * no generic update() at all, and the policy hard-denies `update`/`delete`.
 *
 * It is part of the patient's clinical history but is NOT an examination: it
 * produces no visit, no native odontogram, no medical record, no invoice, no
 * payment, no lab candidate/order and no SATUSEHAT submission, and it never
 * counts toward visit or revenue KPI.
 */
class LegacyOdontogramRecord extends Model
{
    use HasFactory;

    public const STATUS_PUBLISHED = LegacyOdontogramRecordStatus::PUBLISHED;

    public const STATUS_VOID = LegacyOdontogramRecordStatus::VOID;

    /** @var list<string> */
    public const STATUSES = LegacyOdontogramRecordStatus::ALL;

    protected $table = 'trx_odontogram_legacy_records';

    /**
     * Fillable is the PUBLISH-TIME projection of an already-validated staging
     * row, written exactly once inside LegacyOdontogramPublishService. Nothing
     * else in this module calls create() or update() on this model, and there
     * is no route, controller or form that reaches these attributes.
     */
    protected $fillable = [
        'uuid',
        'patient_id',
        'branch_id',
        'source_branch_code',
        'source_medical_record_number',
        'odontogram_date',
        'title',
        'description',
        'source_disk',
        'source_pdf_path',
        'source_pdf_sha256',
        'page_count',
        'status',
        'source_import_id',
        'imported_by',
        'published_by',
        'published_at',
    ];

    protected static function newFactory(): LegacyOdontogramRecordFactory
    {
        return LegacyOdontogramRecordFactory::new();
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
            'branch_id' => 'integer',
            'odontogram_date' => 'date',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function sourceImport(): BelongsTo
    {
        return $this->belongsTo(LegacyOdontogramImport::class, 'source_import_id');
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
        return $this->hasMany(LegacyOdontogramRecordPage::class, 'odontogram_legacy_record_id');
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
        return LegacyOdontogramRecordStatus::canTransition($this->status, $status);
    }
}
