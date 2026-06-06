<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\GoodsReceiptItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 16.3 — trx_goods_receipt_items (per-product goods receipt lines).
 */
class GoodsReceiptItem extends Model
{
    use HasFactory;

    protected $table = 'trx_goods_receipt_items';

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'inventory_location_id',
        'inventory_batch_id',
        'batch_number',
        'lot_number',
        'batch_received_date',
        'expiry_date',
        'inventory_movement_id',
        'reversal_movement_id',
        'ordered_qty',
        'previously_received_qty',
        'received_qty',
        'accepted_qty',
        'rejected_qty',
        'unit_cost',
        'line_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'previously_received_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'accepted_qty' => 'decimal:4',
            'rejected_qty' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'batch_received_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function inventoryBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function reversalMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'reversal_movement_id');
    }

    protected static function newFactory(): GoodsReceiptItemFactory
    {
        return GoodsReceiptItemFactory::new();
    }
}
