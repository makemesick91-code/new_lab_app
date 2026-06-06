<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\StockTransfer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'transfer_number' => 'TRF-'.now()->format('Ym').'-'.strtoupper(Str::random(6)),
            'source_inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'destination_inventory_location_id' => fn (array $attributes) => InventoryLocation::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ]),
            'transfer_date' => now()->toDateString(),
            'status' => StockTransfer::STATUS_DRAFT,
            'notes' => fake()->optional()->sentence(),
            'requested_by' => User::factory(),
            'approved_by' => null,
            'completed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => StockTransfer::STATUS_SUBMITTED]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => StockTransfer::STATUS_COMPLETED,
            'approved_by' => User::factory(),
            'completed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => StockTransfer::STATUS_CANCELLED]);
    }
}
