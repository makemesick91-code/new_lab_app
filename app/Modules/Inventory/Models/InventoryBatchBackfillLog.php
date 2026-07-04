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
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'executed_at' => 'datetime',
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
