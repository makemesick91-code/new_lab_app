<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Factories\MedicalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class MedicalRecord extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINAL = 'final';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_FINAL,
    ];

    protected $table = 'trx_medical_records';

    protected $fillable = [
        'clinic_visit_id', 'branch_id', 'patient_id', 'doctor_id',
        'subjective', 'objective', 'assessment', 'plan', 'notes',
        'status', 'recorded_by', 'finalized_at', 'finalized_by',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
    ];

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function handwriting(): HasOne
    {
        return $this->hasOne(MedicalRecordHandwriting::class)->latestOfMany();
    }

    public function hasHandwriting(): bool
    {
        return $this->handwriting()->exists();
    }

    public function latestHandwriting(): ?MedicalRecordHandwriting
    {
        return MedicalRecordHandwriting::query()
            ->where('medical_record_id', $this->id)
            ->orderByDesc('saved_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Sprint 60 — Page 2+ handwriting rows (additive pages table). Page 1 stays
     * in the legacy `trx_medical_record_handwritings` table (read-through).
     */
    public function handwritingPages(): HasMany
    {
        return $this->hasMany(MedicalRecordHandwritingPage::class)->orderBy('page_number');
    }

    /**
     * Sprint 60 — unified, ordered RM page list for rendering previews and the
     * page editor. Index 0 is always Page 1 (the legacy read-through row, even
     * when empty so the doctor can write the first page); subsequent entries are
     * Page 2+ from the additive pages table, ordered by `page_number`.
     *
     * Each entry: ['page_number', 'is_legacy', 'preview_url', 'saved_at', 'has_content'].
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function orderedHandwritingPages(): Collection
    {
        $legacy = $this->latestHandwriting();

        $pages = collect([[
            'page_number' => 1,
            'is_legacy' => true,
            'preview_url' => $legacy?->previewUrl(),
            'saved_at' => $legacy?->saved_at,
            'has_content' => $legacy !== null && $legacy->previewUrl() !== null,
        ]]);

        foreach ($this->handwritingPages()->get() as $page) {
            $pages->push([
                'page_number' => $page->page_number,
                'is_legacy' => false,
                'preview_url' => $page->previewUrl(),
                'saved_at' => $page->saved_at,
                'has_content' => $page->previewUrl() !== null,
            ]);
        }

        return $pages;
    }

    protected static function newFactory(): MedicalRecordFactory
    {
        return MedicalRecordFactory::new();
    }
}
