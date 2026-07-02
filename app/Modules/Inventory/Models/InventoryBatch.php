<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\InventoryBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sprint 15.3 — inv_inventory_batches (batch/lot identity master).
 *
 * Descriptive attributes only; quantity is ledger-derived from movements
 * (no mutable stock columns on this table).
 */
class InventoryBatch extends Model
{
    use HasFactory;

    protected $table = 'inv_inventory_batches';

    protected $fillable = [
        'branch_id',
        'product_id',
        'supplier_id',
        'batch_number',
        'lot_number',
        'expiry_date',
        'received_date',
        'notes',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_batch_id');
    }

    public function actionLogs(): HasMany
    {
        return $this->hasMany(InventoryBatchActionLog::class, 'inventory_batch_id');
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    public function scopeExpiringBefore(Builder $query, Carbon|string $date): Builder
    {
        $date = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query->whereNotNull('expiry_date')->where('expiry_date', '<=', $date);
    }

    public function scopeNotExpired(Builder $query, Carbon|string|null $asOfDate = null): Builder
    {
        $asOfDate = $asOfDate instanceof Carbon
            ? $asOfDate->toDateString()
            : ($asOfDate ?? now()->toDateString());

        return $query->where(function (Builder $query) use ($asOfDate) {
            $query->whereNull('expiry_date')
                ->orWhere('expiry_date', '>=', $asOfDate);
        });
    }

    protected static function newFactory(): InventoryBatchFactory
    {
        return InventoryBatchFactory::new();
    }
}
