<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — one transition of a
 * refund request. Append-only: never updated, never deleted. Metadata carries ids,
 * cents and codes only.
 */
class RmeRefundRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_rme_refund_request_events';

    protected $fillable = [
        'rme_refund_request_id',
        'from_status',
        'to_status',
        'actor_id',
        'occurred_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeRefundRequestEvent $event): void {
            throw new LogicException(sprintf('RME refund request event #%d is append-only.', (int) $event->getKey()));
        });

        static::deleting(function (RmeRefundRequestEvent $event): void {
            throw new LogicException(sprintf('RME refund request event #%d can never be deleted.', (int) $event->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_refund_request_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RmeRefundRequest::class, 'rme_refund_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
