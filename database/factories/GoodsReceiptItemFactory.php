<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceiptItem>
 */
class GoodsReceiptItemFactory extends Factory
{
    protected $model = GoodsReceiptItem::class;

    public function definition(): array
    {
        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'purchase_order_item_id' => fn (array $attributes) => $this->resolvePurchaseOrderItemId($attributes),
            'product_id' => fn (array $attributes) => PurchaseOrderItem::query()
                ->find($attributes['purchase_order_item_id'])
                ->product_id,
            'inventory_location_id' => fn (array $attributes) => $this->resolveInventoryLocationId($attributes),
            'inventory_movement_id' => null,
            'ordered_qty' => fn (array $attributes) => (float) PurchaseOrderItem::query()
                ->find($attributes['purchase_order_item_id'])
                ->quantity_ordered,
            'previously_received_qty' => 0,
            'accepted_qty' => fn (array $attributes) => min(
                (float) PurchaseOrderItem::query()->find($attributes['purchase_order_item_id'])->quantity_ordered,
                fake()->randomFloat(2, 1, 10)
            ),
            'rejected_qty' => 0,
            'received_qty' => fn (array $attributes) => (float) $attributes['accepted_qty'] + (float) $attributes['rejected_qty'],
            'unit_cost' => fn (array $attributes) => PurchaseOrderItem::query()
                ->find($attributes['purchase_order_item_id'])
                ->unit_price,
            'line_total' => fn (array $attributes) => (float) $attributes['accepted_qty'] * (float) ($attributes['unit_cost'] ?? 0),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forGoodsReceipt(GoodsReceipt $goodsReceipt, PurchaseOrderItem $purchaseOrderItem): static
    {
        $acceptedQty = min((float) $purchaseOrderItem->quantity_ordered, 5.0);
        $rejectedQty = 0.0;
        $unitCost = (float) ($purchaseOrderItem->unit_price ?? 0);

        return $this->state(fn () => [
            'goods_receipt_id' => $goodsReceipt->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'product_id' => $purchaseOrderItem->product_id,
            'inventory_location_id' => $purchaseOrderItem->inventory_location_id
                ?? InventoryLocation::factory()->create(['branch_id' => $goodsReceipt->branch_id])->id,
            'ordered_qty' => $purchaseOrderItem->quantity_ordered,
            'previously_received_qty' => (float) ($purchaseOrderItem->quantity_received ?? 0),
            'accepted_qty' => $acceptedQty,
            'rejected_qty' => $rejectedQty,
            'received_qty' => $acceptedQty + $rejectedQty,
            'unit_cost' => $purchaseOrderItem->unit_price,
            'line_total' => $acceptedQty * $unitCost,
        ]);
    }

    private function resolvePurchaseOrderItemId(array $attributes): int
    {
        $goodsReceipt = GoodsReceipt::query()->find($attributes['goods_receipt_id']);

        $existingItem = PurchaseOrderItem::query()
            ->where('purchase_order_id', $goodsReceipt->purchase_order_id)
            ->first();

        if ($existingItem !== null) {
            return $existingItem->id;
        }

        return PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $goodsReceipt->purchase_order_id,
            'product_id' => Product::factory()->create(['branch_id' => $goodsReceipt->branch_id])->id,
            'inventory_location_id' => InventoryLocation::factory()->create(['branch_id' => $goodsReceipt->branch_id])->id,
        ])->id;
    }

    private function resolveInventoryLocationId(array $attributes): int
    {
        $purchaseOrderItem = PurchaseOrderItem::query()->find($attributes['purchase_order_item_id']);
        $goodsReceipt = GoodsReceipt::query()->find($attributes['goods_receipt_id']);

        if ($purchaseOrderItem->inventory_location_id !== null) {
            return $purchaseOrderItem->inventory_location_id;
        }

        return InventoryLocation::factory()->create([
            'branch_id' => $goodsReceipt->branch_id,
        ])->id;
    }
}
