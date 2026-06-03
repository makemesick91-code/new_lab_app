<?php

namespace Database\Factories;

use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'doctor_id' => Doctor::factory(),
            'medical_record_number' => 'MRN-'.strtoupper(Str::random(8)),
            'name' => fake()->name(),
            'gender' => fake()->randomElement(['Male', 'Female']),
            'date_of_birth' => fake()->dateTimeBetween('-80 years', '-5 years')->format('Y-m-d'),
            'phone' => fake()->numerify('08##########'),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
