<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\QualityControl\Models\QualityControl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QualityControl>
 */
class QualityControlFactory extends Factory
{
    protected $model = QualityControl::class;

    public function definition(): array
    {
        return [
            'lab_order_id' => LabOrder::factory(),
            'inspected_by' => User::factory(),
            'result' => null,
            'notes' => null,
            'started_at' => now(),
            'completed_at' => null,
        ];
    }

    public function passed(): static
    {
        return $this->state(fn () => ['result' => QualityControl::RESULT_PASSED, 'completed_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['result' => QualityControl::RESULT_REJECTED, 'completed_at' => now()]);
    }
}
