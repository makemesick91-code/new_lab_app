<?php

namespace Database\Factories;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Models\Odontogram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Odontogram>
 */
class OdontogramFactory extends Factory
{
    protected $model = Odontogram::class;

    public function definition(): array
    {
        $visit = ClinicVisit::factory()->create();

        return [
            'clinic_visit_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'medical_record_id' => null,
            'status' => Odontogram::STATUS_DRAFT,
            'summary_notes' => null,
            'tooth_map_payload' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
