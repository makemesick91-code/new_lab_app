<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use Database\Factories\ClinicalDiagnosisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SATUSEHAT-4A — Master clinical diagnosis (default ICD-10).
 *
 * Deliberately separate from SATUSEHAT code mappings: an entry here is NOT
 * automatically SATUSEHAT-ready — external readiness still requires an ACTIVE,
 * clinically reviewed mapping in mst_satusehat_code_mappings.
 */
class ClinicalDiagnosis extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEPRECATED = 'deprecated';

    /** Synthetic rehearsal entries — hidden from doctor-facing search. */
    public const STATUS_SYNTHETIC = 'synthetic_rehearsal';

    public const STATUSES = [
        self::STATUS_DRAFT,
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
        'reviewed_by',
        'reviewed_at',
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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $diagnosis) {
            $diagnosis->normalized_search = mb_strtolower(trim($diagnosis->code.' '.$diagnosis->display));
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
