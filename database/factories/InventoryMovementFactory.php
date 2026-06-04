<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'supplier_id' => fn (array $attributes) => Supplier::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'movement_type' => InventoryMovement::TYPE_OPENING,
            'movement_date' => now()->toDateString(),
            'quantity_in' => fake()->randomFloat(2, 1, 100),
            'quantity_out' => 0,
            'unit_cost' => fake()->randomFloat(2, 10000, 500000),
            'reference_type' => null,
            'reference_id' => null,
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function opening(): static
    {
        return $this->state(fn () => [
            'movement_type' => InventoryMovement::TYPE_OPENING,
            'quantity_in' => fake()->randomFloat(2, 1, 100),
            'quantity_out' => 0,
        ]);
    }

    public function purchase(): static
    {
        return $this->state(fn () => [
            'movement_type' => InventoryMovement::TYPE_PURCHASE,
            'quantity_in' => fake()->randomFloat(2, 1, 100),
            'quantity_out' => 0,
        ]);
    }

    public function adjustmentIn(): static
    {
        return $this->state(fn () => [
            'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
            'quantity_in' => fake()->randomFloat(2, 1, 100),
            'quantity_out' => 0,
        ]);
    }

    public function adjustmentOut(): static
    {
        return $this->state(fn () => [
            'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
            'quantity_in' => 0,
            'quantity_out' => fake()->randomFloat(2, 1, 20),
        ]);
    }
}
