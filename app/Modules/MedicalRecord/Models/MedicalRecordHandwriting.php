<?php

namespace App\Modules\MedicalRecord\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
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

    protected static function newFactory(): MedicalRecordHandwritingFactory
    {
        return MedicalRecordHandwritingFactory::new();
    }
}
