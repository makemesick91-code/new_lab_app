<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InventoryMovement extends Model
{
    use HasFactory;

    public const TYPE_OPENING = 'OPENING';

    public const TYPE_PURCHASE = 'PURCHASE';

    public const TYPE_ADJUSTMENT_IN = 'ADJUSTMENT_IN';

    public const TYPE_ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';

    public const TYPE_TRANSFER_IN = 'TRANSFER_IN';

    public const TYPE_TRANSFER_OUT = 'TRANSFER_OUT';

    public const TYPES = [
        self::TYPE_OPENING,
        self::TYPE_PURCHASE,
        self::TYPE_ADJUSTMENT_IN,
        self::TYPE_ADJUSTMENT_OUT,
        self::TYPE_TRANSFER_IN,
        self::TYPE_TRANSFER_OUT,
    ];

    /**
     * The only columns a movement may change after it is written (owner decision
     * D-Q4-a): a batch is linked once to a movement that has none, through the
     * governed batch backfill, and updated_at follows. Stock is the ledger
     * (CG-10), so nothing else changes and no movement is deleted. PostgreSQL
     * enforces the same by trigger, and also checks the batch's product and branch.
     */
    public const MUTABLE_COLUMNS = ['inventory_batch_id', 'updated_at'];

    protected $table = 'trx_inventory_movements';

    protected $fillable = [
        'branch_id',
        'inventory_location_id',
        'product_id',
        'supplier_id',
        'inventory_batch_id',
        'movement_type',
        'movement_date',
        'quantity_in',
        'quantity_out',
        'unit_cost',
        'reference_type',
        'reference_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'quantity_in' => 'decimal:4',
            'quantity_out' => 'decimal:4',
            'unit_cost' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (InventoryMovement $movement): void {
            $changed = array_keys($movement->getDirty());
            $outside = array_values(array_diff($changed, self::MUTABLE_COLUMNS));

            if ($outside !== []) {
                throw new LogicException(sprintf(
                    'Inventory movement #%d is a ledger entry: %s may not change (CG-10).',
                    (int) $movement->getKey(),
                    implode(', ', $outside),
                ));
            }

            if (in_array('inventory_batch_id', $changed, true)
                && ($movement->getOriginal('inventory_batch_id') !== null || $movement->inventory_batch_id === null)) {
                throw new LogicException(sprintf(
                    'Inventory movement #%d: a linked batch is never changed or removed (CG-10).',
                    (int) $movement->getKey(),
                ));
            }
        });

        static::deleting(function (InventoryMovement $movement): void {
            throw new LogicException(sprintf('Inventory movement #%d is a ledger entry and can never be deleted (CG-10).', (int) $movement->getKey()));
        });
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function inventoryBatch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): InventoryMovementFactory
    {
        return InventoryMovementFactory::new();
    }
}
