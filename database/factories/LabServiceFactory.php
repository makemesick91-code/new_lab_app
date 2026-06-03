<?php

namespace Database\Factories;

use App\Modules\LabService\Models\LabService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabService>
 */
class LabServiceFactory extends Factory
{
    protected $model = LabService::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Crown', 'Bridge', 'Denture', 'Retainer', 'Implant', 'Veneer', 'Night Guard', 'Inlay']);

        return [
            'code' => 'SVC-'.strtoupper(Str::random(6)),
            'name' => $name,
            'category' => fake()->randomElement(['Fixed', 'Removable', 'Orthodontic', 'Implant']),
            'description' => fake()->sentence(),
            'turnaround_days' => fake()->numberBetween(1, 14),
            'price' => fake()->randomFloat(2, 100000, 5000000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
