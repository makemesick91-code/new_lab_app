<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 16.2 — trx_purchase_order_items (per-product purchase order lines).
 */
class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $table = 'trx_purchase_order_items';

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'inventory_location_id',
        'purchase_request_item_id',
        'quantity_ordered',
        'unit_price',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:2',
            'quantity_received' => 'decimal:2',
            'unit_price' => 'decimal:2',
        ];
    }

    public function quantityRemaining(): float
    {
        return (float) $this->quantity_ordered - (float) ($this->quantity_received ?? 0);
    }

    public function lineTotal(): float
    {
        return (float) $this->quantity_ordered * (float) ($this->unit_price ?? 0);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function purchaseRequestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'purchase_order_item_id');
    }

    protected static function newFactory(): PurchaseOrderItemFactory
    {
        return PurchaseOrderItemFactory::new();
    }
}
