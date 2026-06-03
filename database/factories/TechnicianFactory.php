<?php

namespace Database\Factories;

use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Technician>
 */
class TechnicianFactory extends Factory
{
    protected $model = Technician::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'code' => 'TECH-'.strtoupper(Str::random(6)),
            'name' => fake()->name(),
            'phone' => fake()->numerify('08##########'),
            'email' => fake()->unique()->safeEmail(),
            'specialization' => fake()->randomElement(['Crown & Bridge', 'Denture', 'Orthodontic', 'Ceramic', 'Implant']),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
