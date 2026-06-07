<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryActivityAction;
use Database\Factories\InventoryActivityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Append-only inventory / procurement activity log (inv_inventory_activity_logs).
 */
class InventoryActivityLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'inv_inventory_activity_logs';

    protected $fillable = [
        'branch_id',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'correlation_id',
        'description',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function displayActionLabel(): string
    {
        $metadata = $this->metadata ?? [];
        $statusTo = $metadata['status_to'] ?? null;

        if ($this->action === InventoryActivityAction::STOCK_TRANSFER_APPROVED
            && $statusTo === StockTransfer::STATUS_IN_TRANSIT) {
            return 'Stock Transfer Shipped (In Transit)';
        }

        if ($this->action === InventoryActivityAction::GOODS_RECEIPT_COMPLETED
            && $statusTo === GoodsReceipt::STATUS_POSTED) {
            return 'Goods Receipt Posted';
        }

        return Str::of($this->action)->replace('_', ' ')->title()->toString();
    }

    public function metadataSummary(): ?string
    {
        $metadata = $this->metadata;

        if (! is_array($metadata) || $metadata === []) {
            return null;
        }

        $parts = [];

        if (! empty($metadata['document_number'])) {
            $parts[] = (string) $metadata['document_number'];
        }

        if (isset($metadata['status_from'], $metadata['status_to'])) {
            $parts[] = $metadata['status_from'].' → '.$metadata['status_to'];
        } elseif (isset($metadata['status_to'])) {
            $parts[] = (string) $metadata['status_to'];
        }

        if (isset($metadata['item_count'])) {
            $parts[] = $metadata['item_count'].' item';
        }

        if (! empty($metadata['movement_ids']) && is_array($metadata['movement_ids'])) {
            $parts[] = count($metadata['movement_ids']).' movement';
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    protected static function newFactory(): InventoryActivityLogFactory
    {
        return InventoryActivityLogFactory::new();
    }
}
