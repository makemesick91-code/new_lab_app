<?php

namespace App\Modules\RME\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Factories\PatientDoctorAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientDoctorAssignment extends Model
{
    use HasFactory;

    public const TYPE_AUTO_VISIT = 'auto_visit';

    public const TYPE_MANUAL = 'manual';

    public const TYPE_SHARED = 'shared';

    public const TYPE_REASSIGNED = 'reassigned';

    protected $table = 'trx_rme_patient_doctor_assignments';

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'from_doctor_id',
        'branch_id',
        'source_visit_id',
        'assigned_by',
        'assigned_at',
        'unassigned_at',
        'assignment_type',
        'reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'from_doctor_id' => 'integer',
            'branch_id' => 'integer',
            'source_visit_id' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function fromDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'from_doctor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function sourceVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'source_visit_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return $this->unassigned_at === null;
    }

    protected static function newFactory(): PatientDoctorAssignmentFactory
    {
        return PatientDoctorAssignmentFactory::new();
    }
}
