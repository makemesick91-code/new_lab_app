<?php

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — the durable record of one
 * committed payment submission (one payment intent).
 *
 * The row exists if and only if the submission's payment effect committed: it is
 * written inside the same transaction as the payment row(s), after them. It is
 * what turns a replayed form (double click, browser retry after a lost response,
 * resubmission after "back") into the ORIGINAL result instead of a second payment.
 *
 * IMMUTABLE, like the payments it describes. Changing or deleting a row would
 * either break the link to the payment it recorded or let the same form record
 * the payment again — so the Eloquent layer refuses both.
 */
class RmePaymentSubmission extends Model
{
    protected $table = 'trx_rme_payment_submissions';

    protected $fillable = [
        'intent_key',
        'rme_invoice_id',
        'branch_id',
        'cashier_id',
        'payment_path',
        'request_fingerprint',
        'amount',
        'rme_payment_id',
        'payment_batch_uuid',
        'payment_count',
        'committed_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmePaymentSubmission $submission): void {
            throw new \LogicException(sprintf(
                'RME payment submission #%d is immutable; a committed payment intent is never rewritten.',
                (int) $submission->getKey(),
            ));
        });

        static::deleting(function (RmePaymentSubmission $submission): void {
            throw new \LogicException(sprintf(
                'RME payment submission #%d can never be deleted: a replay of its form would record the payment again.',
                (int) $submission->getKey(),
            ));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_invoice_id' => 'integer',
            'branch_id' => 'integer',
            'cashier_id' => 'integer',
            'rme_payment_id' => 'integer',
            'payment_count' => 'integer',
            'amount' => 'decimal:2',
            'committed_at' => 'datetime',
        ];
    }

    public function rmeInvoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(RmePayment::class, 'rme_payment_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
