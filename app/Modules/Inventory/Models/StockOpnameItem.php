<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\StockOpnameItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 13 — trx_stock_opname_items (per-product count line of a stock opname).
 *
 * Each line is an immutable snapshot: system_quantity (ledger-derived balance at
 * count time), counted_quantity (physical count), variance_quantity (counted −
 * system), and unit_cost (valuation snapshot). Not a mutable stock balance.
 */
class StockOpnameItem extends Model
{
    use HasFactory;

    protected $table = 'trx_stock_opname_items';

    protected $fillable = [
        'stock_opname_id',
        'product_id',
        'system_quantity',
        'counted_quantity',
        'variance_quantity',
        'unit_cost',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'variance_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    protected static function newFactory(): StockOpnameItemFactory
    {
        return StockOpnameItemFactory::new();
    }
}
