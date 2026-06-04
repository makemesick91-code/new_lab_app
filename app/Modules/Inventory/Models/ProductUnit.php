<?php

namespace App\Modules\Inventory\Models;

use Database\Factories\ProductUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUnit extends Model
{
    use HasFactory;

    protected $table = 'inv_product_units';

    protected $fillable = [
        'name',
        'symbol',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_unit_id');
    }

    protected static function newFactory(): ProductUnitFactory
    {
        return ProductUnitFactory::new();
    }
}
