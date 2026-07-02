<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryBatchActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only operational action log for inventory batches (Sprint 68.41).
 * ledger_quantity_snapshot is audit-only — never use for stock calculation.
 */
class InventoryBatchActionLog extends Model
{
    protected $table = 'trx_inventory_batch_action_logs';

    protected $fillable = [
        'branch_id',
        'inventory_batch_id',
        'action_type',
        'note',
        'ledger_quantity_snapshot',
        'acted_by',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'ledger_quantity_snapshot' => 'decimal:4',
            'acted_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function actionTypeLabel(): string
    {
        return InventoryBatchActionType::label($this->action_type);
    }
}
