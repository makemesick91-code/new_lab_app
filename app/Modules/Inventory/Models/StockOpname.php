<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\StockOpnameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 13 — trx_stock_opnames (physical stock-count document header).
 *
 * Branch- and location-aware. Records a count event only; live stock stays
 * ledger-derived from trx_inventory_movements (no running balance is stored).
 */
class StockOpname extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_COUNTING = 'COUNTING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_COUNTING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'trx_stock_opnames';

    protected $fillable = [
        'branch_id',
        'inventory_location_id',
        'opname_number',
        'opname_date',
        'status',
        'notes',
        'counted_by',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'opname_date' => 'date',
            'completed_at' => 'datetime',
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

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'stock_opname_id');
    }

    protected static function newFactory(): StockOpnameFactory
    {
        return StockOpnameFactory::new();
    }
}
