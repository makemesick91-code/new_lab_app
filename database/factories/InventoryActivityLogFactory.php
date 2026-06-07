<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryActivityLog>
 */
class InventoryActivityLogFactory extends Factory
{
    protected $model = InventoryActivityLog::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement(InventoryActivityAction::all()),
            'subject_type' => (new PurchaseRequest)->getTable(),
            'subject_id' => fn (array $attributes) => PurchaseRequest::factory()->create([
                'branch_id' => $attributes['branch_id'],
            ])->id,
            'correlation_id' => fake()->optional(0.4)->uuid(),
            'description' => fake()->optional()->sentence(),
            'metadata' => [
                'document_number' => 'PR-'.fake()->numerify('####'),
                'status_before' => 'draft',
                'status_after' => 'submitted',
            ],
            'ip_address' => fake()->optional()->ipv4(),
            'user_agent' => fake()->optional()->userAgent(),
            'created_at' => now(),
        ];
    }

    public function withoutUser(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
        ]);
    }

    public function withCorrelationId(?string $correlationId = null): static
    {
        return $this->state(fn () => [
            'correlation_id' => $correlationId ?? (string) Str::uuid(),
        ]);
    }

    public function forAction(string $action): static
    {
        return $this->state(fn () => [
            'action' => $action,
        ]);
    }
}
