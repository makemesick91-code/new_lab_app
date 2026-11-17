<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Services\HandwritingPayloadGuard;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeAmendment\Models\RmeAmendment;
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
        'clinic_visit_id', 'canonical_visit_id', 'source_visit_id', 'sheet_number',
        'branch_id', 'patient_id', 'doctor_id',
        'subjective', 'objective', 'assessment', 'plan', 'notes',
        'status', 'recorded_by', 'finalized_at', 'finalized_by',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
        'sheet_number' => 'integer',
    ];

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    /**
     * Sprint 64.0 — the patient RM workspace anchor (earliest non-cancelled RME
     * visit with an MR). Audit cache only; the live anchor is computed by
     * PatientRmWorkspaceResolver.
     */
    public function canonicalVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'canonical_visit_id');
    }

    /**
     * Sprint 64.0 — the visit this RM sheet was created from. Under the
     * one-sheet-per-visit rule this equals clinic_visit_id; kept for audit and
     * forward-compatibility.
     */
    public function sourceVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'source_visit_id');
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
     * SATUSEHAT-4A — structured diagnoses (never auto-created; legacy records
     * simply have none and stay fully readable).
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(MedicalRecordDiagnosis::class)->orderBy('diagnosis_role')->orderBy('id');
    }

    /** SATUSEHAT-4B emergency overrides of the structured-diagnosis requirement (append-only). */
    public function diagnosisRequirementOverrides(): HasMany
    {
        return $this->hasMany(DiagnosisRequirementOverride::class);
    }

    /** DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-6-1) — the revision of a page that has no stored drawing. */
    public const HANDWRITING_REVISION_NONE = 'none';

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-6-1, owner decision 2026-11-16) — an
     * opaque revision of one handwriting page. Every save writes a fresh object
     * key (rule 224), so the key's digest changes on every save; the key itself
     * never reaches a page (rule 121 §3). A page without a drawing is `none`.
     */
    public static function handwritingRevision(?string $path): string
    {
        return filled($path) ? sha1('rme-handwriting|'.$path) : self::HANDWRITING_REVISION_NONE;
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
    /**
     * @param  bool  $embedBinary  When true, `preview_url` carries inline image
     *                             bytes (a data URI) instead of a link to the
     *                             authorized streaming route.
     *
     *                             Print and PDF templates must pass true:
     *                             STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 moved these
     *                             images behind an authenticated route, and
     *                             dompdf cannot present a session cookie, so a
     *                             linked image would silently render blank in
     *                             the exported PDF.
     */
    public function orderedHandwritingPages(bool $embedBinary = false): Collection
    {
        $legacy = $this->latestHandwriting();

        $pages = collect([[
            'page_number' => 1,
            'is_legacy' => true,
            'preview_url' => $embedBinary ? $legacy?->previewDataUri() : $legacy?->previewUrl(),
            'saved_at' => $legacy?->saved_at,
            'revision' => self::handwritingRevision($legacy?->handwriting_path),
            // Deliberately unchanged semantics: "a page was recorded", not "the
            // binary is currently readable". Keeping this as a path check avoids
            // a disk stat per page and preserves existing Sprint 60 behaviour.
            'has_content' => $legacy !== null && filled($legacy->handwriting_path),
        ]]);

        $rows = $this->handwritingPages()->get();

        // Rule 240 §8 (memo M3.3 PR6) — an amendment page carries its label (the
        // amendment, approved when and by whom), read in one query.
        $amendmentIds = $rows->pluck('rme_amendment_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $amendments = $amendmentIds === [] ? collect() : RmeAmendment::query()
            ->with('decider:id,name')
            ->whereKey($amendmentIds)
            ->get()
            ->keyBy(fn (RmeAmendment $amendment): int => (int) $amendment->getKey());

        foreach ($rows as $page) {
            $amendmentId = $page->getAttribute('rme_amendment_id');

            $entry = [
                'page_number' => $page->page_number,
                'is_legacy' => false,
                'preview_url' => match (true) {
                    ! $embedBinary => $page->previewUrl(),
                    // The print embeds an amendment page only when its bytes match
                    // the recorded SHA-256; a tampered one prints the honest "not
                    // available" notice. (The page route verifies the same.)
                    $amendmentId !== null => self::verifiedDataUri($page),
                    default => $page->previewDataUri(),
                },
                'saved_at' => $page->saved_at,
                'revision' => self::handwritingRevision($page->handwriting_path),
                'has_content' => filled($page->handwriting_path),
            ];

            if ($amendmentId !== null) {
                $entry['amendment_id'] = (int) $amendmentId;
                $entry['amendment_label'] = $amendments->get((int) $amendmentId)?->correctionPageLabel();
            }

            $pages->push($entry);
        }

        return $pages;
    }

    /** Rule 240 §8 — an amendment page's bytes for print, only when they verify. */
    private static function verifiedDataUri(MedicalRecordHandwritingPage $page): ?string
    {
        $bytes = HandwritingPayloadGuard::intactPng($page->handwriting_path, $page->handwriting_hash);

        return $bytes !== null ? 'data:image/png;base64,'.base64_encode($bytes) : null;
    }

    protected static function newFactory(): MedicalRecordFactory
    {
        return MedicalRecordFactory::new();
    }
}
