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
            'shipped_at' => null,
            'shipped_by' => null,
            'completed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => StockTransfer::STATUS_SUBMITTED]);
    }

    public function inTransit(): static
    {
        return $this->state(fn () => [
            'status' => StockTransfer::STATUS_IN_TRANSIT,
            'shipped_by' => User::factory(),
            'shipped_at' => now(),
        ]);
    }

    public function received(): static
    {
        $shipper = User::factory()->create();

        return $this->state(fn () => [
            'status' => StockTransfer::STATUS_RECEIVED,
            'shipped_by' => $shipper->id,
            'shipped_at' => now()->subHour(),
            'approved_by' => User::factory(),
            'completed_at' => now(),
        ]);
    }

    /**
     * @deprecated Sprint 15.2 — use received()
     */
    public function completed(): static
    {
        return $this->received();
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => StockTransfer::STATUS_CANCELLED]);
    }
}
