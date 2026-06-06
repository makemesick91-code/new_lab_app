<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferItem>
 */
class StockTransferItemFactory extends Factory
{
    protected $model = StockTransferItem::class;

    public function definition(): array
    {
        return [
            'stock_transfer_id' => StockTransfer::factory(),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'branch_id' => StockTransfer::find($attributes['stock_transfer_id'])->branch_id,
            ]),
            'quantity' => fake()->randomFloat(2, 1, 100),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
