<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderItem>
 */
class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'branch_id' => PurchaseOrder::find($attributes['purchase_order_id'])->branch_id,
            ]),
            'inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => PurchaseOrder::find($attributes['purchase_order_id'])->branch_id,
            ]),
            'purchase_request_item_id' => null,
            'quantity_ordered' => fake()->randomFloat(2, 1, 50),
            'unit_price' => fake()->optional()->randomFloat(2, 1000, 500000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
