<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabOrder>
 */
class LabOrderFactory extends Factory
{
    protected $model = LabOrder::class;

    public function definition(): array
    {
        $year = now()->format('Y');

        return [
            'order_number' => 'ADL-'.$year.'-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'clinic_id' => Clinic::factory(),
            'doctor_id' => Doctor::factory(),
            'patient_id' => Patient::factory(),
            'medical_record_number' => 'RM-'.fake()->unique()->numerify('######'),
            'order_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'priority' => 'NORMAL',
            'status' => LabOrder::STATUS_RECEIVED,
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
            'updated_by' => null,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => LabOrder::STATUS_CANCELLED]);
    }
}
