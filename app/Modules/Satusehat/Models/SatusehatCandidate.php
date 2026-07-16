<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use Database\Factories\SatusehatCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A controlled SATUSEHAT submission candidate. Generated (idempotently) for a
 * completed visit with a FINAL medical record. It is NEVER auto-sent — it only
 * becomes eligible for the review filter. Readiness + review are two axes.
 */
class SatusehatCandidate extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'trx_satusehat_candidates';

    // --- Readiness axis ---
    public const READINESS_READY = 'ready';

    public const READINESS_INCOMPLETE = 'incomplete';

    public const READINESS_BLOCKED = 'blocked';

    public const READINESS_SOURCE_CHANGED = 'source_changed';

    public const READINESS_STATUSES = [
        self::READINESS_READY,
        self::READINESS_INCOMPLETE,
        self::READINESS_BLOCKED,
        self::READINESS_SOURCE_CHANGED,
    ];

    // --- Dental readiness axis (SATUSEHAT-3) ---
    public const DENTAL_READY = 'dental_ready';

    public const DENTAL_INCOMPLETE = 'dental_incomplete';

    public const DENTAL_MAPPING_BLOCKED = 'dental_mapping_blocked';

    public const DENTAL_UNSUPPORTED = 'dental_unsupported';

    public const DENTAL_SOURCE_CHANGED = 'dental_source_changed';

    public const DENTAL_CONFORMANCE_FAILED = 'dental_conformance_failed';

    public const DENTAL_READINESS_STATUSES = [
        self::DENTAL_READY,
        self::DENTAL_INCOMPLETE,
        self::DENTAL_MAPPING_BLOCKED,
        self::DENTAL_UNSUPPORTED,
        self::DENTAL_SOURCE_CHANGED,
        self::DENTAL_CONFORMANCE_FAILED,
    ];

    // --- Review axis ---
    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_EXCLUDED = 'excluded';

    public const REVIEW_QUEUED = 'queued';

    public const REVIEW_STATUSES = [
        self::REVIEW_PENDING,
        self::REVIEW_APPROVED,
        self::REVIEW_EXCLUDED,
        self::REVIEW_QUEUED,
    ];

    protected $fillable = [
        'environment',
        'branch_id',
        'clinic_visit_id',
        'patient_id',
        'doctor_id',
        'medical_record_id',
        'source_version',
        'source_hash',
        'approved_source_hash',
        'readiness_status',
        'readiness_reasons',
        'dental_readiness_status',
        'dental_readiness_reasons',
        'dental_source_hash',
        'approved_dental_source_hash',
        'dental_evaluated_at',
        'dental_coverage_snapshot',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'excluded_by',
        'excluded_at',
        'exclusion_reason',
        'revoked_at',
        'eligibility_snapshot',
        'preview_snapshot',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'medical_record_id' => 'integer',
            'source_version' => 'integer',
            'readiness_reasons' => 'array',
            'dental_readiness_reasons' => 'array',
            'dental_evaluated_at' => 'datetime',
            'dental_coverage_snapshot' => 'array',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'excluded_by' => 'integer',
            'excluded_at' => 'datetime',
            'revoked_at' => 'datetime',
            // Encrypted at rest — the first encrypted casts in the codebase.
            'eligibility_snapshot' => 'encrypted:array',
            'preview_snapshot' => 'encrypted:array',
            'created_by' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReady(): bool
    {
        return $this->readiness_status === self::READINESS_READY;
    }

    public function isSourceChanged(): bool
    {
        return $this->readiness_status === self::READINESS_SOURCE_CHANGED;
    }

    public function isDentalReady(): bool
    {
        return $this->dental_readiness_status === self::DENTAL_READY;
    }

    public function isDentalSourceChanged(): bool
    {
        return $this->dental_readiness_status === self::DENTAL_SOURCE_CHANGED;
    }

    public function dentalReadinessLabel(): string
    {
        return match ($this->dental_readiness_status) {
            self::DENTAL_READY => 'Gigi Siap',
            self::DENTAL_INCOMPLETE => 'Data Gigi Belum Lengkap',
            self::DENTAL_MAPPING_BLOCKED => 'Mapping Gigi Belum Aktif',
            self::DENTAL_UNSUPPORTED => 'Data Gigi Tidak Didukung',
            self::DENTAL_SOURCE_CHANGED => 'Sumber Gigi Berubah',
            self::DENTAL_CONFORMANCE_FAILED => 'Validasi Gigi Gagal',
            default => $this->dental_readiness_status ?? 'Belum Dievaluasi',
        };
    }

    public function dentalReadinessTone(): string
    {
        return match ($this->dental_readiness_status) {
            self::DENTAL_READY => 'success',
            self::DENTAL_INCOMPLETE, self::DENTAL_MAPPING_BLOCKED => 'warning',
            self::DENTAL_UNSUPPORTED, self::DENTAL_CONFORMANCE_FAILED => 'danger',
            self::DENTAL_SOURCE_CHANGED => 'info',
            default => 'neutral',
        };
    }

    public function isApproved(): bool
    {
        return $this->review_status === self::REVIEW_APPROVED;
    }

    public function isExcluded(): bool
    {
        return $this->review_status === self::REVIEW_EXCLUDED;
    }

    /**
     * An approval is only meaningful when the candidate is READY and not already
     * approved/queued and the clinical source has not drifted since generation.
     */
    public function canApprove(): bool
    {
        return $this->isReady()
            && ! $this->isSourceChanged()
            && in_array($this->review_status, [self::REVIEW_PENDING, self::REVIEW_EXCLUDED], true);
    }

    public function canExclude(): bool
    {
        return in_array($this->review_status, [self::REVIEW_PENDING, self::REVIEW_APPROVED], true);
    }

    public function readinessLabel(): string
    {
        return match ($this->readiness_status) {
            self::READINESS_READY => 'Siap',
            self::READINESS_INCOMPLETE => 'Belum Lengkap',
            self::READINESS_BLOCKED => 'Terblokir',
            self::READINESS_SOURCE_CHANGED => 'Sumber Berubah',
            default => $this->readiness_status,
        };
    }

    public function readinessTone(): string
    {
        return match ($this->readiness_status) {
            self::READINESS_READY => 'success',
            self::READINESS_INCOMPLETE => 'warning',
            self::READINESS_BLOCKED => 'danger',
            self::READINESS_SOURCE_CHANGED => 'info',
            default => 'neutral',
        };
    }

    public function reviewLabel(): string
    {
        return match ($this->review_status) {
            self::REVIEW_PENDING => 'Menunggu Review',
            self::REVIEW_APPROVED => 'Disetujui',
            self::REVIEW_EXCLUDED => 'Dikecualikan',
            self::REVIEW_QUEUED => 'Disiapkan',
            default => $this->review_status,
        };
    }

    public function reviewTone(): string
    {
        return match ($this->review_status) {
            self::REVIEW_PENDING => 'warning',
            self::REVIEW_APPROVED => 'success',
            self::REVIEW_EXCLUDED => 'neutral',
            self::REVIEW_QUEUED => 'info',
            default => 'neutral',
        };
    }

    protected static function newFactory(): SatusehatCandidateFactory
    {
        return SatusehatCandidateFactory::new();
    }
}
