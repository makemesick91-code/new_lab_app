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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    protected static function newFactory(): MedicalRecordFactory
    {
        return MedicalRecordFactory::new();
    }
}
