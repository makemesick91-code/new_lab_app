<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    public function definition(): array
    {
        $branch = Branch::factory();

        return [
            'branch_id' => $branch,
            'purchase_order_id' => fn (array $attributes) => PurchaseOrder::factory()->create([
                'branch_id' => $this->resolveBranchId($attributes['branch_id']),
                'status' => PurchaseOrder::STATUS_SENT,
            ])->id,
            'receipt_number' => 'GR-'.now()->format('Ymd').'-1-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'receipt_date' => now()->toDateString(),
            'supplier_delivery_number' => fake()->optional()->bothify('SJ-####'),
            'supplier_invoice_number' => fake()->optional()->bothify('INV-####'),
            'status' => GoodsReceipt::STATUS_DRAFT,
            'notes' => fake()->optional()->sentence(),
            'submitted_at' => null,
            'posted_at' => null,
            'cancelled_at' => null,
            'created_by' => User::factory(),
            'submitted_by' => null,
            'posted_by' => null,
            'cancelled_by' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => GoodsReceipt::STATUS_DRAFT]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => GoodsReceipt::STATUS_SUBMITTED,
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ]);
    }

    public function posted(): static
    {
        $poster = User::factory()->create();

        return $this->state(fn () => [
            'status' => GoodsReceipt::STATUS_POSTED,
            'posted_by' => $poster->id,
            'posted_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => GoodsReceipt::STATUS_CANCELLED,
            'cancelled_by' => User::factory(),
            'cancelled_at' => now(),
        ]);
    }

    public function voided(): static
    {
        $voider = User::factory()->create();

        return $this->state(fn () => [
            'status' => GoodsReceipt::STATUS_VOID,
            'posted_by' => $voider->id,
            'posted_at' => now()->subHour(),
            'voided_by' => $voider->id,
            'voided_at' => now(),
        ]);
    }

    public function forPurchaseOrder(PurchaseOrder $purchaseOrder): static
    {
        return $this->state(fn () => [
            'branch_id' => $purchaseOrder->branch_id,
            'purchase_order_id' => $purchaseOrder->id,
        ]);
    }

    private function resolveBranchId(mixed $branchId): int
    {
        if ($branchId instanceof Branch) {
            return $branchId->id;
        }

        return (int) $branchId;
    }
}
