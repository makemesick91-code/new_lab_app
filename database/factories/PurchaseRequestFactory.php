<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequest>
 */
class PurchaseRequestFactory extends Factory
{
    protected $model = PurchaseRequest::class;

    public function definition(): array
    {
        $branch = Branch::factory();

        return [
            'branch_id' => $branch,
            'purchase_request_number' => 'PR-'.now()->format('Ymd').'-1-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'request_date' => now()->toDateString(),
            'status' => PurchaseRequest::STATUS_DRAFT,
            'requested_by' => User::factory(),
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => ['status' => PurchaseRequest::STATUS_SUBMITTED]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseRequest::STATUS_APPROVED,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseRequest::STATUS_REJECTED,
            'rejected_by' => User::factory(),
            'rejected_at' => now(),
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => PurchaseRequest::STATUS_CANCELLED]);
    }
}
