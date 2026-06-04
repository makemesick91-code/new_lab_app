<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\StockOpname;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockOpname>
 */
class StockOpnameFactory extends Factory
{
    protected $model = StockOpname::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'opname_number' => 'OPN-'.now()->format('Ym').'-'.strtoupper(Str::random(6)),
            'opname_date' => now()->toDateString(),
            'status' => StockOpname::STATUS_DRAFT,
            'notes' => fake()->optional()->sentence(),
            'counted_by' => User::factory(),
            'created_by' => User::factory(),
            'completed_at' => null,
        ];
    }

    public function counting(): static
    {
        return $this->state(fn () => ['status' => StockOpname::STATUS_COUNTING]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => StockOpname::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => StockOpname::STATUS_CANCELLED]);
    }
}
