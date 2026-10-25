<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — an invoice correction request.
 *
 * IMMUTABLE CONTENT. Everything the checker reviewed — the original snapshot, the
 * proposal, the totals, the fingerprint, who asked and why — is written once at
 * submission and can never change afterwards ({@see IMMUTABLE_ATTRIBUTES}). Only
 * the lifecycle columns move, and only through RmeInvoiceCorrectionService under
 * the invoice row lock. A correction is never deleted.
 *
 * LIMIT: like the RmePayment guard, this is an application-layer control. Raw SQL
 * and query-builder mass updates bypass model events; never add a code path that
 * edits these columns through either.
 */
class RmeInvoiceCorrection extends Model
{
    protected $table = 'trx_rme_invoice_corrections';

    /**
     * Written once at submission; refused on every later save. `patient_id` is
     * deliberately absent — as on RmePayment, a patient merge re-points it
     * (PatientOwnedRecordRegistry); the snapshots keep the id they were taken with.
     */
    public const IMMUTABLE_ATTRIBUTES = [
        'uuid',
        'branch_id',
        'rme_invoice_id',
        'clinic_visit_id',
        'correction_type',
        'reason',
        'snapshot_schema_version',
        'invoice_version_at_request',
        'invoice_fingerprint',
        'original_snapshot',
        'proposed_snapshot',
        'original_grand_total',
        'proposed_grand_total',
        'difference_amount',
        'paid_total_at_request',
        'requested_by',
        'requested_at',
        'idempotency_key',
        // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — written once at
        // submission: the ledger the checker reviews, and the owner's case
        // approval reference (mandatory for POST_PAYMENT, PP-2/PP-7).
        'invoice_status_at_request',
        'paid_payment_count_at_request',
        'owner_approval_reference',
    ];

    protected $fillable = [
        'uuid',
        'branch_id',
        'rme_invoice_id',
        'clinic_visit_id',
        'patient_id',
        'correction_type',
        'status',
        'reason',
        'snapshot_schema_version',
        'invoice_version_at_request',
        'invoice_fingerprint',
        'original_snapshot',
        'proposed_snapshot',
        'original_grand_total',
        'proposed_grand_total',
        'difference_amount',
        'paid_total_at_request',
        'requested_by',
        'requested_at',
        'idempotency_key',
        'invoice_status_at_request',
        'paid_payment_count_at_request',
        'owner_approval_reference',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeInvoiceCorrection $correction): void {
            $dirty = array_keys($correction->getDirty());
            $changed = array_values(array_intersect($dirty, self::IMMUTABLE_ATTRIBUTES));

            // The reference number is assigned once, right after the insert
            // that produced the id it is derived from.
            if (in_array('correction_number', $dirty, true) && $correction->getOriginal('correction_number') !== null) {
                $changed[] = 'correction_number';
            }

            // A terminal status is final at the model layer too, not only in the
            // service's transition map: APPLIED / REJECTED / CANCELLED / STALE
            // never move again, whoever writes the model.
            $fromStatus = (string) $correction->getOriginal('status');
            if (in_array('status', $dirty, true) && RmeInvoiceCorrectionStatus::isTerminal($fromStatus)) {
                $changed[] = 'status (terminal '.$fromStatus.')';
            }

            if ($changed !== []) {
                throw new LogicException(sprintf(
                    'RME invoice correction #%d content is immutable; refusing to change [%s].',
                    (int) $correction->getKey(),
                    implode(', ', $changed),
                ));
            }
        });

        static::deleting(function (RmeInvoiceCorrection $correction): void {
            throw new LogicException(sprintf(
                'RME invoice correction #%d is a financial record and can never be deleted.',
                (int) $correction->getKey(),
            ));
        });
    }

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'rme_invoice_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'snapshot_schema_version' => 'integer',
            'invoice_version_at_request' => 'integer',
            'original_snapshot' => 'array',
            'proposed_snapshot' => 'array',
            'original_grand_total' => 'decimal:2',
            'proposed_grand_total' => 'decimal:2',
            'difference_amount' => 'decimal:2',
            'paid_total_at_request' => 'decimal:2',
            'paid_payment_count_at_request' => 'integer',
            'outstanding_after' => 'decimal:2',
            'overpaid_after' => 'decimal:2',
            'requested_by' => 'integer',
            'requested_at' => 'datetime',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'applied_by' => 'integer',
            'applied_at' => 'datetime',
            'applied_invoice_version' => 'integer',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'datetime',
            'stale_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class, 'rme_invoice_id');
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RmeInvoiceCorrectionLine::class)->orderBy('line_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RmeInvoiceCorrectionEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function revisionVersion(): HasOne
    {
        return $this->hasOne(RmeInvoiceDocumentVersion::class, 'source_correction_id');
    }

    /** FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the obligation this correction opened, if any. */
    public function overpaymentObligation(): HasOne
    {
        return $this->hasOne(RmeOverpaymentObligation::class, 'source_correction_id');
    }

    public function isPostPayment(): bool
    {
        return $this->correction_type === RmeInvoiceCorrectionType::POST_PAYMENT;
    }

    public function isActive(): bool
    {
        return RmeInvoiceCorrectionStatus::isActive((string) $this->status);
    }

    public function isApplied(): bool
    {
        return $this->status === RmeInvoiceCorrectionStatus::APPLIED;
    }

    public function isRequestedBy(?User $user): bool
    {
        return $user !== null && (int) $this->requested_by === (int) $user->getKey();
    }
}
