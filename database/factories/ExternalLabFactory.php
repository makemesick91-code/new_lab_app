<?php

namespace Database\Factories;

use App\Modules\LabOrder\Models\ExternalLab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalLab>
 */
class ExternalLabFactory extends Factory
{
    protected $model = ExternalLab::class;

    public function definition(): array
    {
        return [
            'name' => 'Lab '.fake()->unique()->company(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->safeEmail(),
            'address' => fake()->streetAddress(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
