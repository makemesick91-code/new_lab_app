<?php

namespace App\Modules\Prescription\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Support\Storage\ClinicalEvidenceStorage;
use Database\Factories\RmePrescriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class RmePrescription extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    protected $table = 'trx_rme_prescriptions';

    protected $fillable = [
        'branch_id',
        'clinic_visit_id',
        'medical_record_id',
        'patient_id',
        'doctor_id',
        'prescribed_by_name',
        'prescription_date',
        'patient_name_snapshot',
        'patient_age_snapshot',
        'allergy_note',
        'pregnant_or_breastfeeding',
        'renal_function_issue',
        'prescription_canvas_path',
        'doctor_signature_canvas_path',
        'notes',
        'status',
        'printed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'prescription_date' => 'date',
        'printed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function prescriptionCanvasUrl(): ?string
    {
        return $this->canvasUrl($this->prescription_canvas_path);
    }

    public function signatureCanvasUrl(): ?string
    {
        return $this->canvasUrl($this->doctor_signature_canvas_path);
    }

    /** Inline bytes for print templates (no session cookie available). */
    public function prescriptionCanvasDataUri(): ?string
    {
        return ClinicalEvidenceStorage::dataUri($this->prescription_canvas_path);
    }

    /** Inline bytes for print templates (no session cookie available). */
    public function signatureCanvasDataUri(): ?string
    {
        return ClinicalEvidenceStorage::dataUri($this->doctor_signature_canvas_path);
    }

    /**
     * STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — canvases (including the doctor's
     * signature) are clinical evidence. They previously resolved to a raw
     * public-disk URL; they now resolve to an authenticated, policy-gated route.
     */
    private function canvasUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (ClinicalEvidenceStorage::isInlineDataUri($path)) {
            return $path;
        }

        $kind = $path === $this->doctor_signature_canvas_path ? 'signature' : 'prescription';

        return route('rme.prescriptions.canvas', [
            'rmePrescription' => $this->getKey(),
            'kind' => $kind,
        ]);
    }

    /**
     * FIX-02 — WhatsApp hand-off attempts for this prescription.
     */
    public function whatsAppDeliveries(): HasMany
    {
        return $this->hasMany(PrescriptionWhatsAppDelivery::class, 'rme_prescription_id');
    }

    protected static function newFactory(): RmePrescriptionFactory
    {
        return RmePrescriptionFactory::new();
    }
}
