<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    protected $model = ProductCategory::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->randomElement(['Zirconia', 'Acrylic', 'Metal', 'Consumable', 'Ceramic', 'Implant Parts']),
            'code' => 'CAT-'.strtoupper(Str::random(6)),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
