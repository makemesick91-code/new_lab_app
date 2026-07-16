<?php

namespace Database\Factories;

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClinicalDiagnosisFactory extends Factory
{
    protected $model = ClinicalDiagnosis::class;

    public function definition(): array
    {
        return [
            'code_system' => 'ICD-10',
            'code' => 'K'.fake()->unique()->numerify('##.#'),
            'display' => 'Diagnosis '.fake()->words(3, true),
            'status' => ClinicalDiagnosis::STATUS_ACTIVE,
            'version' => 1,
            'source' => 'WHO ICD-10',
        ];
    }

    public function deprecated(): static
    {
        return $this->state(fn () => ['status' => ClinicalDiagnosis::STATUS_DEPRECATED]);
    }
}
