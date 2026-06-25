<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use Database\Factories\MedicalRecordHandwritingPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Sprint 60 — one row per RM page beyond Page 1 (1 canvas = 1 RM page).
 *
 * Page 1 remains the legacy `trx_medical_record_handwritings` row (read-through);
 * this model holds Page 2 and later for a single Medical Record.
 */
class MedicalRecordHandwritingPage extends Model
{
    use HasFactory;

    protected $table = 'trx_medical_record_handwriting_pages';

    protected $fillable = [
        'medical_record_id',
        'clinic_visit_id',
        'branch_id',
        'doctor_id',
        'page_number',
        'handwriting_path',
        'handwriting_hash',
        'saved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'page_number' => 'integer',
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function previewUrl(): ?string
    {
        $path = $this->handwriting_path;

        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'data:image/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    protected static function newFactory(): MedicalRecordHandwritingPageFactory
    {
        return MedicalRecordHandwritingPageFactory::new();
    }
}
