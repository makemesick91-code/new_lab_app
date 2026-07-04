<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatchBackfillLog extends Model
{
    protected $table = 'trx_inventory_batch_backfill_logs';

    protected $fillable = [
        'inventory_movement_id',
        'inventory_batch_id',
        'strategy',
        'command',
        'source_document_type',
        'source_document_item_id',
        'evidence',
        'approval_reference',
        'approved_by',
        'approved_at',
        'approval_reason',
        'old_inventory_batch_id',
        'dry_run',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'dry_run' => 'boolean',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
