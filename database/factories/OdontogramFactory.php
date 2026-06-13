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
        return [
            'clinic_visit_id' => ClinicVisit::factory(),
            'branch_id' => null,
            'medical_record_id' => null,
            'status' => Odontogram::STATUS_DRAFT,
            'summary_notes' => null,
            'additional_conditions' => null,
            'tooth_map_payload' => null,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Odontogram $odontogram) {
            if (is_null($odontogram->branch_id) && $odontogram->clinic_visit_id) {
                $odontogram->branch_id = ClinicVisit::find($odontogram->clinic_visit_id)?->branch_id;
            }
        });
    }
}
