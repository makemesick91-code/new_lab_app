<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Factories\MedicalRecordHandwritingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecordHandwriting extends Model
{
    use HasFactory;

    protected $table = 'trx_medical_record_handwritings';

    protected $fillable = [
        'medical_record_id',
        'clinic_visit_id',
        'branch_id',
        'doctor_id',
        'handwriting_path',
        'handwriting_hash',
        'saved_at',
        'created_by',
    ];

    protected $casts = [
        'saved_at' => 'datetime',
    ];

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — screen preview source.
     *
     * This used to return a raw public-disk URL, which made the handwriting
     * readable by anyone who could guess the path. It now returns an
     * authenticated, policy-gated route; the object key is never exposed and
     * possession of a URL grants nothing without a session that passes
     * MedicalRecordPolicy::view.
     */
    public function previewUrl(): ?string
    {
        $path = $this->handwriting_path;

        if (blank($path)) {
            return null;
        }

        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
            return $path;
        }

        return route('rme.handwritings.image', ['handwriting' => $this->getKey()]);
    }

    /**
     * Print/PDF preview source. dompdf cannot present a session cookie, so a
     * print template must embed the bytes inline rather than link to the
     * authorized route. Returns null when the object is missing so the template
     * renders an honest empty state instead of a broken image.
     */
    public function previewDataUri(): ?string
    {
        return ClinicalEvidenceStorage::dataUri($this->handwriting_path);
    }

    public function hasStoredImage(): bool
    {
        return ClinicalEvidenceStorage::exists($this->handwriting_path);
    }

    protected static function newFactory(): MedicalRecordHandwritingFactory
    {
        return MedicalRecordHandwritingFactory::new();
    }
}
