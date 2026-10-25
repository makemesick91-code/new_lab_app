<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — append-only history of an
 * overpayment obligation. Metadata carries ids, amounts and codes only (no PII).
 */
class RmeOverpaymentObligationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_rme_overpayment_obligation_events';

    protected $fillable = [
        'rme_overpayment_obligation_id',
        'from_status',
        'to_status',
        'actor_id',
        'occurred_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeOverpaymentObligationEvent $event): void {
            throw new LogicException(sprintf('RME overpayment obligation event #%d is append-only.', (int) $event->getKey()));
        });

        static::deleting(function (RmeOverpaymentObligationEvent $event): void {
            throw new LogicException(sprintf('RME overpayment obligation event #%d can never be deleted.', (int) $event->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_overpayment_obligation_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RmeOverpaymentObligation::class, 'rme_overpayment_obligation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
