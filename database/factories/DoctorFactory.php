<?php

namespace Database\Factories;

use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'code' => 'DOC-'.strtoupper(Str::random(6)),
            'name' => 'Dr. '.fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->safeEmail(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
