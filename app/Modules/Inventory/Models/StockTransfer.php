<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 14.2 - trx_stock_transfers (transfer document header).
 *
 * This model records transfer identity and workflow state only. Stock remains
 * ledger-derived from trx_inventory_movements.
 */
class StockTransfer extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'trx_stock_transfers';

    protected $fillable = [
        'branch_id',
        'transfer_number',
        'source_inventory_location_id',
        'destination_inventory_location_id',
        'transfer_date',
        'status',
        'notes',
        'requested_by',
        'approved_by',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceInventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'source_inventory_location_id');
    }

    public function destinationInventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'destination_inventory_location_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'stock_transfer_id');
    }

    protected static function newFactory(): StockTransferFactory
    {
        return StockTransferFactory::new();
    }
}
