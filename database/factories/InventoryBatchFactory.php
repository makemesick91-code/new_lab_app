<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryBatch>
 */
class InventoryBatchFactory extends Factory
{
    protected $model = InventoryBatch::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => fn (array $attributes) => Product::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'supplier_id' => fn (array $attributes) => Supplier::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'batch_number' => 'B-'.now()->format('Y').'-'.strtoupper(Str::random(6)),
            'lot_number' => fake()->optional()->bothify('LOT-####'),
            'expiry_date' => fake()->optional()->dateTimeBetween('+3 months', '+2 years')?->format('Y-m-d'),
            'received_date' => now()->toDateString(),
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withoutSupplier(): static
    {
        return $this->state(fn () => ['supplier_id' => null]);
    }

    public function withoutLot(): static
    {
        return $this->state(fn () => ['lot_number' => null]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expiry_date' => now()->subDays(7)->toDateString(),
            'received_date' => now()->subYear()->toDateString(),
        ]);
    }

    public function expiringSoon(int $withinDays = 30): static
    {
        return $this->state(fn () => [
            'expiry_date' => now()->addDays(fake()->numberBetween(1, $withinDays))->toDateString(),
        ]);
    }
}
