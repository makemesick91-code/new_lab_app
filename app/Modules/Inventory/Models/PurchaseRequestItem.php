<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\PurchaseRequestItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 16.1 — trx_purchase_request_items (per-product purchase request lines).
 */
class PurchaseRequestItem extends Model
{
    use HasFactory;

    protected $table = 'trx_purchase_request_items';

    protected $fillable = [
        'purchase_request_id',
        'product_id',
        'inventory_location_id',
        'quantity_requested',
        'estimated_unit_price',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
        ];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_request_item_id');
    }

    protected static function newFactory(): PurchaseRequestItemFactory
    {
        return PurchaseRequestItemFactory::new();
    }
}
