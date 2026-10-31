<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (Sprint 5/7, rule 216) — the record that money
 * owed back on an APPROVED refund request actually left the clinic. It is NOT a
 * payment: incoming payments are never written, re-amounted or reused.
 *
 * Nothing is mass-assignable; the repository writes allow-listed columns only.
 * What was claimed at start is immutable; a terminal row (SETTLED or ABORTED) is
 * frozen entirely, so its evidence, reference and actors can never be rewritten.
 * Status moves only along RmeRefundSettlementStatus::TRANSITIONS; the row can never
 * be deleted.
 */
class RmeRefundSettlement extends Model
{
    protected $table = 'trx_rme_refund_settlements';

    /** Nothing that describes money or authority comes from an attribute array. */
    protected $fillable = [];

    /** Written once at start, never again. */
    public const IMMUTABLE_ATTRIBUTES = [
        'uuid',
        'branch_id',
        'rme_invoice_id',
        'rme_overpayment_obligation_id',
        'rme_refund_request_id',
        'amount',
        'method',
        'intent_key',
        'request_fingerprint',
        'approved_by',
        'started_by',
        'started_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeRefundSettlement $settlement): void {
            $from = (string) $settlement->getOriginal('status');

            if (in_array($from, RmeRefundSettlementStatus::TERMINAL, true)) {
                $dirty = array_diff(array_keys($settlement->getDirty()), ['updated_at']);
                if ($dirty !== []) {
                    throw new LogicException(sprintf('RME refund settlement #%d is %s and frozen.', (int) $settlement->getKey(), $from));
                }
            }

            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($settlement->isDirty($attribute)) {
                    throw new LogicException(sprintf('RME refund settlement #%d: %s is immutable.', (int) $settlement->getKey(), $attribute));
                }
            }

            if ($settlement->isDirty('settlement_number') && $settlement->getOriginal('settlement_number') !== null) {
                throw new LogicException(sprintf('RME refund settlement #%d already has a number.', (int) $settlement->getKey()));
            }

            if ($settlement->isDirty('status') && ! RmeRefundSettlementStatus::canTransition($from, (string) $settlement->status)) {
                throw new LogicException(sprintf('RME refund settlement #%d cannot move from %s to %s.', (int) $settlement->getKey(), $from, (string) $settlement->status));
            }
        });

        static::deleting(function (RmeRefundSettlement $settlement): void {
            throw new LogicException(sprintf('RME refund settlement #%d can never be deleted.', (int) $settlement->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'rme_invoice_id' => 'integer',
            'rme_overpayment_obligation_id' => 'integer',
            'rme_refund_request_id' => 'integer',
            'amount' => 'decimal:2',
            'approved_by' => 'integer',
            'started_by' => 'integer',
            'started_at' => 'datetime',
            'outcome_unknown_by' => 'integer',
            'outcome_unknown_at' => 'datetime',
            'settled_by' => 'integer',
            'settled_at' => 'datetime',
            'settler_attested_at' => 'datetime',
            'aborted_by' => 'integer',
            'aborted_at' => 'datetime',
            'evidence_size_bytes' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, RmeRefundSettlementStatus::ACTIVE, true);
    }

    public function isSettled(): bool
    {
        return $this->status === RmeRefundSettlementStatus::SETTLED;
    }

    public function label(): string
    {
        return RmeRefundSettlementStatus::label($this->status);
    }

    public function hasEvidence(): bool
    {
        return is_string($this->evidence_path) && $this->evidence_path !== '';
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RmeRefundRequest::class, 'rme_refund_request_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class, 'rme_invoice_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RmeOverpaymentObligation::class, 'rme_overpayment_obligation_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function unknownReporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outcome_unknown_by');
    }

    public function aborter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aborted_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RmeRefundSettlementEvent::class, 'rme_refund_settlement_id')->orderBy('occurred_at')->orderBy('id');
    }
}
