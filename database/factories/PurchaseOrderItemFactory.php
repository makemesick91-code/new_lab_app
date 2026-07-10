<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\Supplier;
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
            'supplier_id' => fn (array $attributes) => Supplier::factory()->create([
                'branch_id' => PurchaseOrder::find($attributes['purchase_order_id'])->branch_id,
            ])->id,
            'inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => PurchaseOrder::find($attributes['purchase_order_id'])->branch_id,
            ]),
            'purchase_request_item_id' => null,
            'quantity_ordered' => fake()->randomFloat(2, 1, 50),
            'unit_price' => fake()->optional()->randomFloat(2, 1000, 500000),
            'estimated_arrival_date' => fn (array $attributes) => PurchaseOrder::find($attributes['purchase_order_id'])->order_date,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forSupplier(Supplier $supplier): static
    {
        return $this->state(fn () => ['supplier_id' => $supplier->id]);
    }
}
