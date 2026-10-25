<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the immutable copy of a payment
 * receipt (kwitansi) as it was issued (owner decision PP-5: D7 extends to
 * receipts).
 *
 * One row per payment. A reprint renders THIS snapshot, never the live invoice, so
 * a later correction can never change a receipt already handed to the patient; it
 * is stamped "DIREVISI" instead. A RECONSTRUCTED snapshot (a payment recorded
 * before receipt snapshots existed) is labelled as such and never presented as an
 * identical reprint of the paper receipt.
 */
class RmeReceiptSnapshot extends Model
{
    /** Written inside the payment transaction. */
    public const BASIS_ISSUED_AT_PAYMENT = 'ISSUED_AT_PAYMENT';

    /** Written by the first post-payment correction, before it mutated the invoice. */
    public const BASIS_RECONSTRUCTED = 'RECONSTRUCTED_BEFORE_POSTPAYMENT_CORRECTION';

    public const BASES = [self::BASIS_ISSUED_AT_PAYMENT, self::BASIS_RECONSTRUCTED];

    protected $table = 'trx_rme_receipt_snapshots';

    protected $fillable = [
        'uuid',
        'rme_payment_id',
        'rme_invoice_id',
        'branch_id',
        'receipt_number',
        'invoice_version',
        'invoice_status',
        'capture_basis',
        'snapshot_schema_version',
        'snapshot',
        'snapshot_sha256',
        'issued_by',
        'issued_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeReceiptSnapshot $snapshot): void {
            throw new LogicException(sprintf('RME receipt snapshot #%d is immutable.', (int) $snapshot->getKey()));
        });

        static::deleting(function (RmeReceiptSnapshot $snapshot): void {
            throw new LogicException(sprintf('RME receipt snapshot #%d can never be deleted.', (int) $snapshot->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_payment_id' => 'integer',
            'rme_invoice_id' => 'integer',
            'branch_id' => 'integer',
            'invoice_version' => 'integer',
            'snapshot_schema_version' => 'integer',
            'snapshot' => 'array',
            'issued_by' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /** The stored snapshot still hashes to the value recorded when it was written. */
    public function integrityHolds(): bool
    {
        $snapshot = $this->snapshot;

        return is_array($snapshot)
            && hash_equals((string) $this->snapshot_sha256, RmeInvoiceSnapshot::documentHash($snapshot));
    }

    public function isReconstructed(): bool
    {
        return $this->capture_basis === self::BASIS_RECONSTRUCTED;
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(RmePayment::class, 'rme_payment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class, 'rme_invoice_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
