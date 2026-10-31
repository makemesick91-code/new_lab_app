<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — money the clinic owes back after
 * a post-payment correction brought an invoice's total below what was paid
 * (owner decision PP-3, option A).
 *
 * Created once, atomically, by the transaction that applies the correction; the
 * amount is derived by the server (`max(0, paid − new total)`). It is NOT a refund:
 * the obligation records what is owed; the refund request (Sprint 4) decides to pay
 * it back and the refund settlement (Sprint 5, rule 216) records that it was paid.
 *
 * It is never updated except (a) the one-time number assignment and (b) the
 * one-time OPEN → SETTLED move written by the settlement transaction, which sets
 * settled_at and rme_refund_settlement_id and nothing else. A SETTLED obligation is
 * frozen and never re-opens; it is never deleted.
 */
class RmeOverpaymentObligation extends Model
{
    public const STATUS_OPEN = 'OPEN';

    /** FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-4): the refund was verified as paid out. */
    public const STATUS_SETTLED = 'SETTLED';

    /** Statuses this release can write. */
    public const STATUSES = [self::STATUS_OPEN, self::STATUS_SETTLED];

    public const LABELS = [
        self::STATUS_OPEN => 'Kelebihan Bayar — Belum Dikembalikan',
        self::STATUS_SETTLED => 'Kelebihan Bayar — Telah Dikembalikan',
    ];

    /** The only columns the settlement transaction may write, once, on an OPEN row. */
    public const SETTLEMENT_COLUMNS = ['status', 'settled_at', 'rme_refund_settlement_id'];

    protected $table = 'trx_rme_overpayment_obligations';

    protected $fillable = [
        'uuid',
        'branch_id',
        'rme_invoice_id',
        'clinic_visit_id',
        'patient_id',
        'source_correction_id',
        'amount',
        'invoice_version',
        'status',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeOverpaymentObligation $obligation): void {
            // The permitted writes after insert: assigning the number, once, to a
            // row that has none (the number embeds the id); and the one-time
            // OPEN → SETTLED move of the settlement transaction (RFS-4).
            $dirty = array_keys($obligation->getDirty());
            $onlyNumber = array_diff($dirty, ['obligation_number', 'updated_at']) === [];

            if ($onlyNumber && $obligation->getOriginal('obligation_number') === null) {
                return;
            }

            $settling = $obligation->getOriginal('status') === self::STATUS_OPEN
                && $obligation->status === self::STATUS_SETTLED
                && array_diff($dirty, array_merge(self::SETTLEMENT_COLUMNS, ['updated_at'])) === []
                && $obligation->settled_at !== null
                && (int) $obligation->rme_refund_settlement_id > 0;

            if (! $settling) {
                throw new LogicException(sprintf('RME overpayment obligation #%d is immutable.', (int) $obligation->getKey()));
            }
        });

        static::deleting(function (RmeOverpaymentObligation $obligation): void {
            throw new LogicException(sprintf('RME overpayment obligation #%d can never be deleted.', (int) $obligation->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'rme_invoice_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'source_correction_id' => 'integer',
            'amount' => 'decimal:2',
            'invoice_version' => 'integer',
            'created_by' => 'integer',
            'settled_at' => 'datetime',
            'rme_refund_settlement_id' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? (string) $this->status;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class, 'rme_invoice_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sourceCorrection(): BelongsTo
    {
        return $this->belongsTo(RmeInvoiceCorrection::class, 'source_correction_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(RmeRefundSettlement::class, 'rme_refund_settlement_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RmeOverpaymentObligationEvent::class, 'rme_overpayment_obligation_id')->orderBy('id');
    }
}
