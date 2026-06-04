<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockOpnameItem>
 */
class StockOpnameItemFactory extends Factory
{
    protected $model = StockOpnameItem::class;

    public function definition(): array
    {
        $system = fake()->randomFloat(2, 0, 100);
        $counted = fake()->randomFloat(2, 0, 100);

        return [
            'stock_opname_id' => StockOpname::factory(),
            // Keep the counted product in the same branch as its opname (mirrors
            // InventoryMovementFactory's branch-consistency closure pattern).
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'branch_id' => StockOpname::find($attributes['stock_opname_id'])->branch_id,
            ]),
            'system_quantity' => $system,
            'counted_quantity' => $counted,
            'variance_quantity' => round($counted - $system, 2),
            'unit_cost' => fake()->randomFloat(2, 10000, 500000),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    /**
     * A line whose physical count matches the system (zero variance).
     */
    public function balanced(): static
    {
        return $this->state(function () {
            $qty = fake()->randomFloat(2, 1, 100);

            return [
                'system_quantity' => $qty,
                'counted_quantity' => $qty,
                'variance_quantity' => 0,
            ];
        });
    }
}
