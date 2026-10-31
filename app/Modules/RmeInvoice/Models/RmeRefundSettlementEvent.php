<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — one transition of a refund
 * settlement. Append-only: never updated, never deleted. Metadata carries ids,
 * cents and codes only.
 */
class RmeRefundSettlementEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_rme_refund_settlement_events';

    protected $fillable = [
        'rme_refund_settlement_id',
        'from_status',
        'to_status',
        'actor_id',
        'occurred_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeRefundSettlementEvent $event): void {
            throw new LogicException(sprintf('RME refund settlement event #%d is append-only.', (int) $event->getKey()));
        });

        static::deleting(function (RmeRefundSettlementEvent $event): void {
            throw new LogicException(sprintf('RME refund settlement event #%d can never be deleted.', (int) $event->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_refund_settlement_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RmeRefundSettlement::class, 'rme_refund_settlement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
