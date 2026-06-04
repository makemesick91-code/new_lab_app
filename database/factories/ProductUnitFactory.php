<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductUnit>
 */
class ProductUnitFactory extends Factory
{
    protected $model = ProductUnit::class;

    public function definition(): array
    {
        $symbol = strtolower(Str::random(6));

        return [
            'name' => 'Unit '.$symbol,
            'symbol' => $symbol,
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
