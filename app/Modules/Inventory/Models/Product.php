<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branch\Models\Branch;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $table = 'inv_products';

    protected $fillable = [
        'branch_id',
        'product_category_id',
        'product_unit_id',
        'name',
        'code',
        'description',
        'minimum_stock',
        'reorder_point',
        'reorder_quantity',
        'alert_enabled',
        'average_cost',
        'is_active',
        'requires_batch_tracking',
    ];

    protected function casts(): array
    {
        return [
            'minimum_stock' => 'decimal:2',
            'reorder_point' => 'decimal:2',
            'reorder_quantity' => 'decimal:2',
            'alert_enabled' => 'boolean',
            'average_cost' => 'decimal:2',
            'is_active' => 'boolean',
            'requires_batch_tracking' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'product_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class, 'product_id');
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
