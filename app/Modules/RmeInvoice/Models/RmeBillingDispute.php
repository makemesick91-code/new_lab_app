<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (owner OPS-OQ-1, rule
 * 203) — a billing dispute (laporan ketidaksesuaian tagihan).
 *
 * IMMUTABLE REPORT. What was reported — the invoice, its branch, who reported it,
 * when, why, and the invoice figures at that moment — is written once and never
 * changes ({@see IMMUTABLE_ATTRIBUTES}). Only the lifecycle columns move, and only
 * through RmeBillingDisputeService under the invoice row lock. A dispute is never
 * deleted. A resolved dispute is never reopened (a new problem is a new dispute).
 *
 * LIMIT: an application-layer control, like RmePayment's guard. Raw SQL and
 * query-builder mass updates bypass model events; on PostgreSQL the table's CHECK
 * constraints and the one-active partial unique index still hold.
 */
class RmeBillingDispute extends Model
{
    protected $table = 'trx_rme_billing_disputes';

    /** Written at report time and never again. */
    public const IMMUTABLE_ATTRIBUTES = [
        'rme_invoice_id',
        'branch_id',
        'reason_category',
        'description',
        'invoice_status_at_report',
        'invoice_grand_total_at_report',
        'invoice_paid_total_at_report',
        'invoice_correction_version_at_report',
        'reported_by',
        'reported_at',
    ];

    protected $fillable = [
        'rme_invoice_id',
        'branch_id',
        'status',
        'reason_category',
        'description',
        'invoice_status_at_report',
        'invoice_grand_total_at_report',
        'invoice_paid_total_at_report',
        'invoice_correction_version_at_report',
        'reported_by',
        'reported_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeBillingDispute $dispute): void {
            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($dispute->isDirty($attribute)) {
                    throw new LogicException(sprintf(
                        'RME billing dispute #%d: "%s" is part of the immutable report.',
                        (int) $dispute->getKey(),
                        $attribute,
                    ));
                }
            }

            // A dispute number is assigned once, right after insert.
            if ($dispute->isDirty('dispute_number') && $dispute->getOriginal('dispute_number') !== null) {
                throw new LogicException(sprintf('RME billing dispute #%d: the dispute number never changes.', (int) $dispute->getKey()));
            }

            // RESOLVED is terminal.
            if ($dispute->getOriginal('status') === RmeBillingDisputeStatus::RESOLVED) {
                throw new LogicException(sprintf('RME billing dispute #%d is resolved and can never change.', (int) $dispute->getKey()));
            }
        });

        static::deleting(function (RmeBillingDispute $dispute): void {
            throw new LogicException(sprintf('RME billing dispute #%d can never be deleted.', (int) $dispute->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_invoice_id' => 'integer',
            'branch_id' => 'integer',
            'invoice_grand_total_at_report' => 'decimal:2',
            'invoice_paid_total_at_report' => 'decimal:2',
            'invoice_correction_version_at_report' => 'integer',
            'reported_by' => 'integer',
            'reported_at' => 'datetime',
            'review_started_by' => 'integer',
            'review_started_at' => 'datetime',
            'escalated_by' => 'integer',
            'escalated_at' => 'datetime',
            'resolution_correction_id' => 'integer',
            'resolved_by' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return RmeBillingDisputeStatus::isActive((string) $this->status);
    }

    public function isReportedBy(User $user): bool
    {
        return (int) $this->reported_by === (int) $user->id;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class, 'rme_invoice_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_started_by');
    }

    public function escalator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function resolutionCorrection(): BelongsTo
    {
        return $this->belongsTo(RmeInvoiceCorrection::class, 'resolution_correction_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RmeBillingDisputeEvent::class, 'rme_billing_dispute_id');
    }
}
