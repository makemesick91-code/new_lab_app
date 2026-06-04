<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->company(),
            'phone' => fake()->optional()->numerify('08##########'),
            'email' => fake()->optional()->companyEmail(),
            'address' => fake()->optional()->address(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
