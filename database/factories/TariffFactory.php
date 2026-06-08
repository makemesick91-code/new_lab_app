<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    protected $model = Tariff::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'treatment_id' => Treatment::factory(),
            'price' => fake()->numberBetween(50_000, 3_000_000),
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
