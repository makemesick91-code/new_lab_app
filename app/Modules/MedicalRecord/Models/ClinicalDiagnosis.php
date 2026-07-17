<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use Database\Factories\ClinicalDiagnosisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SATUSEHAT-4A — Master clinical diagnosis (default ICD-10).
 * SATUSEHAT-4B — operational review lifecycle:
 *   draft → under_review → approved → active → deprecated (or rejected).
 *
 * Deliberately separate from SATUSEHAT code mappings: an entry here is NOT
 * automatically SATUSEHAT-ready — external readiness still requires an ACTIVE,
 * clinically reviewed mapping in mst_satusehat_code_mappings. Active entries
 * are immutable (code/display never edited in place); corrections deprecate
 * the old entry with a replacement pointer.
 */
class ClinicalDiagnosis extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEPRECATED = 'deprecated';

    /** Synthetic rehearsal entries — hidden from doctor-facing search. */
    public const STATUS_SYNTHETIC = 'synthetic_rehearsal';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_ACTIVE,
        self::STATUS_DEPRECATED,
        self::STATUS_SYNTHETIC,
    ];

    protected $table = 'mst_clinical_diagnoses';

    protected $fillable = [
        'code_system',
        'code',
        'display',
        'normalized_search',
        'status',
        'version',
        'effective_from',
        'effective_to',
        'source',
        'source_version',
        'aliases',
        'reviewed_by',
        'reviewed_at',
        'submitted_by',
        'submitted_for_review_at',
        'approved_by',
        'approved_at',
        'approval_reason',
        'rejected_reason',
        'replacement_diagnosis_id',
        'deprecated_by',
        'deprecated_at',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'reviewed_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'approved_at' => 'datetime',
            'deprecated_at' => 'datetime',
            'replacement_diagnosis_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $diagnosis) {
            // Aliases fold into the search key only — the official display text
            // is never altered by an alias.
            $alias = is_string($diagnosis->aliases) ? ' '.trim($diagnosis->aliases) : '';
            $diagnosis->normalized_search = mb_substr(
                mb_strtolower(trim($diagnosis->code.' '.$diagnosis->display.$alias)),
                0,
                320,
            );
        });
    }

    protected static function newFactory(): ClinicalDiagnosisFactory
    {
        return ClinicalDiagnosisFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function label(): string
    {
        return trim($this->code.' — '.$this->display);
    }

    /** Only ACTIVE terminology may be selected for new medical records. */
    public function isSelectable(): bool
    {
        return $this->isActive();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deprecatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deprecated_by');
    }

    /** Active replacement suggested when this terminology was deprecated. */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_diagnosis_id');
    }

    /** Structured diagnosis rows referencing this terminology (usage). */
    public function records(): HasMany
    {
        return $this->hasMany(MedicalRecordDiagnosis::class, 'clinical_diagnosis_id');
    }
}
