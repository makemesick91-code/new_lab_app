<?php

namespace App\Modules\Patient\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_patients';

    protected $fillable = [
        'clinic_id',
        'doctor_id',
        'branch_id',
        'medical_record_number',
        'registered_at',
        'manual_rm_number',
        'name',
        'gender',
        'date_of_birth',
        'phone',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'clinic_id' => 'integer',
            'doctor_id' => 'integer',
            'branch_id' => 'integer',
            'date_of_birth' => 'date',
            'registered_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    protected static function newFactory(): PatientFactory
    {
        return PatientFactory::new();
    }
}
