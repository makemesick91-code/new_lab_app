<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryLocation>
 */
class InventoryLocationFactory extends Factory
{
    protected $model = InventoryLocation::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->randomElement(['Gudang Utama', 'Ruang Produksi', 'Ruang QC', 'Area Delivery', 'Ruang Klinik']),
            'code' => 'LOC-'.strtoupper(Str::random(6)),
            'type' => fake()->randomElement(InventoryLocation::TYPES),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
