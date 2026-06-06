<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        $branch = Branch::factory();

        return [
            'branch_id' => $branch,
            'purchase_order_number' => 'PO-'.now()->format('Ymd').'-1-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrder::STATUS_DRAFT,
            'supplier_id' => fn (array $attributes) => Supplier::factory()->create([
                'branch_id' => $this->resolveBranchId($attributes['branch_id']),
            ])->id,
            'supplier_snapshot_name' => fn (array $attributes) => Supplier::find($attributes['supplier_id'])?->name,
            'supplier_reference_number' => fake()->optional()->bothify('SUP-REF-####'),
            'currency' => 'IDR',
            'purchase_request_id' => null,
            'expected_delivery_date' => fake()->optional()->dateTimeBetween('now', '+1 month')?->format('Y-m-d'),
            'notes' => fake()->optional()->sentence(),
            'submitted_by' => null,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'sent_by' => null,
            'sent_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrder::STATUS_SUBMITTED,
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => PurchaseOrder::STATUS_SENT,
            'sent_by' => User::factory(),
            'sent_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => PurchaseOrder::STATUS_CANCELLED]);
    }

    public function forPurchaseRequest(PurchaseRequest $purchaseRequest): static
    {
        return $this->state(function () use ($purchaseRequest) {
            $supplier = Supplier::factory()->create([
                'branch_id' => $purchaseRequest->branch_id,
            ]);

            return [
                'branch_id' => $purchaseRequest->branch_id,
                'purchase_request_id' => $purchaseRequest->id,
                'supplier_id' => $supplier->id,
                'supplier_snapshot_name' => $supplier->name,
                'currency' => 'IDR',
            ];
        });
    }

    private function resolveBranchId(mixed $branchId): int
    {
        if ($branchId instanceof Branch) {
            return $branchId->id;
        }

        return (int) $branchId;
    }
}
