<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\LocationProductMinimum;
use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LocationProductMinimum>
 */
class LocationProductMinimumFactory extends Factory
{
    protected $model = LocationProductMinimum::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            // Location and product are pinned to the same branch as the threshold row
            // so the factory never produces cross-branch configuration data.
            'inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'minimum_stock' => 5.0000,
            'maximum_stock' => 20.0000,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withoutMaximum(): static
    {
        return $this->state(fn () => ['maximum_stock' => null]);
    }

    public function withoutCreator(): static
    {
        return $this->state(fn () => ['created_by' => null]);
    }
}
