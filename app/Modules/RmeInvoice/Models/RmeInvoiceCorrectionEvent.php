<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — append-only transition history.
 * Never updated, never deleted; there is no `updated_at` column.
 */
class RmeInvoiceCorrectionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_rme_invoice_correction_events';

    protected $fillable = [
        'rme_invoice_correction_id',
        'from_status',
        'to_status',
        'actor_id',
        'occurred_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeInvoiceCorrectionEvent $event): void {
            throw new LogicException(sprintf('RME invoice correction event #%d is append-only.', (int) $event->getKey()));
        });

        static::deleting(function (RmeInvoiceCorrectionEvent $event): void {
            throw new LogicException(sprintf('RME invoice correction event #%d can never be deleted.', (int) $event->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_invoice_correction_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(RmeInvoiceCorrection::class, 'rme_invoice_correction_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
