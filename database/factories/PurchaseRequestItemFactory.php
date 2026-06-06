<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\PurchaseRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequestItem>
 */
class PurchaseRequestItemFactory extends Factory
{
    protected $model = PurchaseRequestItem::class;

    public function definition(): array
    {
        return [
            'purchase_request_id' => PurchaseRequest::factory(),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'branch_id' => PurchaseRequest::find($attributes['purchase_request_id'])->branch_id,
            ]),
            'inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => PurchaseRequest::find($attributes['purchase_request_id'])->branch_id,
            ]),
            'quantity_requested' => fake()->randomFloat(2, 1, 50),
            'estimated_unit_price' => fake()->optional()->randomFloat(2, 1000, 500000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
