<?php

namespace Database\Factories;

use App\Modules\TreatmentCategory\Models\TreatmentCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TreatmentCategory>
 */
class TreatmentCategoryFactory extends Factory
{
    protected $model = TreatmentCategory::class;

    public function definition(): array
    {
        return [
            'code' => 'CAT-'.strtoupper(Str::random(6)),
            'name' => fake()->unique()->words(2, true).' '.strtoupper(Str::random(3)),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
