<?php

namespace Database\Factories;

use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientDoctorAssignment>
 */
class PatientDoctorAssignmentFactory extends Factory
{
    protected $model = PatientDoctorAssignment::class;

    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'from_doctor_id' => null,
            'branch_id' => null,
            'source_visit_id' => null,
            'assigned_by' => null,
            'assigned_at' => now(),
            'unassigned_at' => null,
            'assignment_type' => PatientDoctorAssignment::TYPE_MANUAL,
            'reason' => null,
            'notes' => null,
        ];
    }

    public function unassigned(): static
    {
        return $this->state(fn () => ['unassigned_at' => now()]);
    }
}
