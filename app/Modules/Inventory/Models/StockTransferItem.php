<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\StockTransferItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 14.2 - trx_stock_transfer_items (per-product transfer line).
 *
 * Quantity is a transfer document quantity only, not a stored stock balance.
 */
class StockTransferItem extends Model
{
    use HasFactory;

    protected $table = 'trx_stock_transfer_items';

    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }

    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    protected static function newFactory(): StockTransferItemFactory
    {
        return StockTransferItemFactory::new();
    }
}
