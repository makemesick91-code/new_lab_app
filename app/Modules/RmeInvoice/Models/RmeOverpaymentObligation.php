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
 * no money leaves the system in this release and the obligation stays OPEN until
 * the refund sprints exist. It is never updated (except the one-time number
 * assignment) and never deleted.
 */
class RmeOverpaymentObligation extends Model
{
    public const STATUS_OPEN = 'OPEN';

    /** Statuses this release can write. Later sprints widen it additively. */
    public const STATUSES = [self::STATUS_OPEN];

    public const LABELS = [
        self::STATUS_OPEN => 'Kelebihan Bayar — Belum Dikembalikan',
    ];

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
            // The only permitted write after insert: assigning the number, once,
            // to a row that has none (the number embeds the id).
            $dirty = array_keys($obligation->getDirty());
            $onlyNumber = array_diff($dirty, ['obligation_number', 'updated_at']) === [];

            if (! $onlyNumber || $obligation->getOriginal('obligation_number') !== null) {
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
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
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

    public function events(): HasMany
    {
        return $this->hasMany(RmeOverpaymentObligationEvent::class, 'rme_overpayment_obligation_id')->orderBy('id');
    }
}
