<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_category_id' => fn (array $attributes) => ProductCategory::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'product_unit_id' => ProductUnit::factory(),
            'name' => fake()->randomElement(['Zirconia Block', 'Acrylic Resin', 'CoCr Alloy', 'Glove Box', 'Polishing Paste']),
            'code' => 'PRD-'.strtoupper(Str::random(6)),
            'description' => fake()->optional()->sentence(),
            'minimum_stock' => fake()->randomFloat(2, 1, 20),
            'reorder_point' => 0,
            'reorder_quantity' => 0,
            'alert_enabled' => true,
            'average_cost' => fake()->randomFloat(2, 10000, 500000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function requiresBatchTracking(): static
    {
        return $this->state(fn () => ['requires_batch_tracking' => true]);
    }
}
