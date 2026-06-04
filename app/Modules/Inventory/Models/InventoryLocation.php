<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Branch\Models\Branch;
use Database\Factories\InventoryLocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLocation extends Model
{
    use HasFactory;

    public const TYPE_WAREHOUSE = 'WAREHOUSE';

    public const TYPE_PRODUCTION_ROOM = 'PRODUCTION_ROOM';

    public const TYPE_QC_ROOM = 'QC_ROOM';

    public const TYPE_DELIVERY_AREA = 'DELIVERY_AREA';

    public const TYPE_CLINIC_ROOM = 'CLINIC_ROOM';

    public const TYPE_OTHER = 'OTHER';

    public const TYPES = [
        self::TYPE_WAREHOUSE,
        self::TYPE_PRODUCTION_ROOM,
        self::TYPE_QC_ROOM,
        self::TYPE_DELIVERY_AREA,
        self::TYPE_CLINIC_ROOM,
        self::TYPE_OTHER,
    ];

    protected $table = 'inv_inventory_locations';

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'type',
        'description',
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
        return $this->hasMany(InventoryMovement::class, 'inventory_location_id');
    }

    protected static function newFactory(): InventoryLocationFactory
    {
        return InventoryLocationFactory::new();
    }
}
