<?php

namespace Database\Factories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalRecord>
 */
class MedicalRecordFactory extends Factory
{
    protected $model = MedicalRecord::class;

    public function definition(): array
    {
        $visit = ClinicVisit::factory()->create();

        return [
            'clinic_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'subjective' => null,
            'objective' => null,
            'assessment' => null,
            'plan' => null,
            'notes' => null,
            'status' => MedicalRecord::STATUS_DRAFT,
            'recorded_by' => null,
        ];
    }

    public function final(): static
    {
        return $this->state(fn () => [
            'status' => MedicalRecord::STATUS_FINAL,
        ]);
    }
}
