<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branch\Models\Branch;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $table = 'inv_suppliers';

    protected $fillable = [
        'branch_id',
        'name',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'supplier_id');
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }
}
