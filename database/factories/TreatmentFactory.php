<?php

namespace Database\Factories;

use App\Modules\Treatment\Models\Treatment;
use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Treatment>
 */
class TreatmentFactory extends Factory
{
    protected $model = Treatment::class;

    public function definition(): array
    {
        return [
            'treatment_category_id' => TreatmentCategory::factory(),
            'code' => 'TRT-'.strtoupper(Str::random(6)),
            'name' => fake()->unique()->words(2, true).' '.strtoupper(Str::random(3)),
            'description' => fake()->optional()->sentence(),
            'default_duration_minutes' => fake()->optional()->numberBetween(15, 120),
            'requires_doctor' => true,
            'requires_room' => true,
            'requires_lab' => false,
            'is_active' => true,
        ];
    }

    public function requiresLab(): static
    {
        return $this->state(fn () => ['requires_lab' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
