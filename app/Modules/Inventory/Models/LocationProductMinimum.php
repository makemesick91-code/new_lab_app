<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\LocationProductMinimumFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sprint 17.8 — inv_location_product_minimums (per-room minimum/maximum stock configuration).
 *
 * Threshold CONFIGURATION ONLY. This is not a stock balance table.
 * Current stock remains ledger-derived from trx_inventory_movements:
 *   current_stock = SUM(quantity_in) - SUM(quantity_out)
 *
 * No mutable stock / current_stock / qty_on_hand / balance columns exist here.
 */
class LocationProductMinimum extends Model
{
    use HasFactory;

    protected $table = 'inv_location_product_minimums';

    protected $fillable = [
        'branch_id',
        'inventory_location_id',
        'product_id',
        'minimum_stock',
        'maximum_stock',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:4',
            'maximum_stock' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForLocation(Builder $query, int $inventoryLocationId): Builder
    {
        return $query->where('inventory_location_id', $inventoryLocationId);
    }

    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): LocationProductMinimumFactory
    {
        return LocationProductMinimumFactory::new();
    }
}
