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

    /**
     * A patient is "legacy" for RME purposes when it has no branch assigned yet
     * (registered before "Klinik" = "Cabang RME"). These patients are still
     * allowed to create visits; the branch comes from the selected Cabang RME on
     * the visit, and patient.branch_id is never rewritten automatically
     * (Sprint 23 Phase 23.10).
     */
    public function isLegacyWithoutBranch(): bool
    {
        return $this->branch_id === null;
    }

    /**
     * Short branch indicator for selectors/tables: "{CODE} — {Name}" when the
     * patient already has a Cabang RME, otherwise a legacy marker. Never falls
     * back to clinic_id for the operational label.
     */
    public function branchLabel(): string
    {
        if ($this->branch) {
            return $this->branch->code.' — '.$this->branch->name;
        }

        return 'Legacy / belum ada cabang pasien';
    }

    /**
     * Identity label for patient selectors: RM number (or a "no RM" marker) with
     * the patient name, so cashiers/admins can disambiguate patients reliably.
     */
    public function selectorLabel(): string
    {
        $rm = $this->medical_record_number ?: 'Belum ada RM';

        return $rm.' — '.$this->name;
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
