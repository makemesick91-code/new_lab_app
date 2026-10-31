<?php

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\LabCaseCandidate;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
use Database\Factories\RmeInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class RmeInvoice extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_UNPAID = 'UNPAID';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_PAID = 'PAID';

    public const STATUS_VOID = 'VOID';

    /**
     * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, owner decision S6-D2) —
     * "Tanpa Biaya": a LEGITIMATE Rp0 invoice of a Konsultasi Dokter or a Kontrol
     * closed by the explicit "Selesai Tanpa Biaya" action. Terminal. It is NOT
     * PAID (no money was received, no payment row exists, no receipt, no lab
     * candidate) and it is NOT a receivable. Only
     * RmePaymentService::closeZeroValueInvoice() writes it.
     */
    public const STATUS_NO_CHARGE = 'NO_CHARGE';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_UNPAID,
        self::STATUS_PARTIAL,
        self::STATUS_PAID,
        self::STATUS_VOID,
        self::STATUS_NO_CHARGE,
    ];

    protected $table = 'trx_rme_invoices';

    protected $fillable = [
        'branch_id',
        'clinic_visit_id',
        'patient_id',
        'medical_record_id',
        'cashier_id',
        'invoice_number',
        'status',
        'subtotal',
        'discount_total',
        'grand_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'medical_record_id' => 'integer',
            'cashier_id' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            // FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — deliberately
            // NOT fillable: only RmeInvoiceCorrectionService::apply() moves it,
            // through the repository, under the invoice row lock.
            'correction_version' => 'integer',
            // FEATURE-RME-RP0-CONTROL-CONSULTATION-1 — deliberately NOT fillable:
            // written only by the zero-value closure, through the repository,
            // under the invoice row lock (PostgreSQL CHECKs bind them to NO_CHARGE).
            'zero_value_closed_by' => 'integer',
            'zero_value_closed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    /**
     * True when this invoice was raised for the given visit.
     *
     * The cashier routes bind {clinicVisit} and {rmeInvoice} independently and
     * the policy authorizes the INVOICE only. Without this check a valid invoice
     * could be paired with any visit id in the URL, printing one patient's name,
     * RM and phone beside another patient's money. Every route that takes both
     * must refuse an unpaired request (404).
     */
    public function belongsToVisit(ClinicVisit $visit): bool
    {
        return $this->clinic_visit_id !== null
            && (int) $this->clinic_visit_id === (int) $visit->getKey();
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** FEATURE-RME-RP0-CONTROL-CONSULTATION-1 — who closed a NO_CHARGE invoice. */
    public function zeroValueClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'zero_value_closed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RmeInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RmePayment::class);
    }

    /** FEATURE-RME-REFUND-SETTLEMENT-1 — refund payouts recorded on this invoice. */
    public function refundSettlements(): HasMany
    {
        return $this->hasMany(RmeRefundSettlement::class, 'rme_invoice_id');
    }

    public function labCaseCandidates(): HasMany
    {
        return $this->hasMany(LabCaseCandidate::class, 'rme_invoice_id');
    }

    // Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation.
    public function followUps(): HasMany
    {
        return $this->hasMany(RmeReceivableFollowUp::class, 'rme_invoice_id');
    }

    public function latestFollowUp(): HasOne
    {
        return $this->hasOne(RmeReceivableFollowUp::class, 'rme_invoice_id')->latestOfMany();
    }

    /**
     * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — correction requests
     * filed against this invoice (any status).
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(RmeInvoiceCorrection::class, 'rme_invoice_id');
    }

    /**
     * Frozen document versions (v0 = original, vN = revision N). Empty until the
     * first correction is applied.
     */
    public function documentVersions(): HasMany
    {
        return $this->hasMany(RmeInvoiceDocumentVersion::class, 'rme_invoice_id')->orderBy('version_number');
    }

    /**
     * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — immutable receipt snapshots,
     * one per payment (owner decision PP-5).
     */
    public function receiptSnapshots(): HasMany
    {
        return $this->hasMany(RmeReceiptSnapshot::class, 'rme_invoice_id')->orderBy('rme_payment_id');
    }

    /**
     * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — money owed back after a
     * post-payment correction brought the total below what was paid (PP-3 A).
     */
    public function overpaymentObligations(): HasMany
    {
        return $this->hasMany(RmeOverpaymentObligation::class, 'rme_invoice_id')->orderBy('id');
    }

    /** True once at least one correction has been applied to this document. */
    public function isRevised(): bool
    {
        return (int) ($this->correction_version ?? 0) > 0;
    }

    public function recalculateTotals(): void
    {
        $subtotal = $this->items->sum('subtotal');
        $discountTotal = $this->items->sum('discount');
        $this->subtotal = $subtotal;
        $this->discount_total = $discountTotal;
        $this->grand_total = $subtotal - $discountTotal;
    }

    /** GROSS money received: Σ incoming payments, never reduced by a refund. */
    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /**
     * FEATURE-RME-REFUND-SETTLEMENT-1 (RFS-1) — money verifiably paid back on this
     * invoice: Σ SETTLED refund settlements. An in-flight, unknown or aborted
     * settlement is not money out.
     */
    public function settledRefundAmount(): float
    {
        return (float) $this->refundSettlements()->where('status', RmeRefundSettlementStatus::SETTLED)->sum('amount');
    }

    /**
     * RFS-1 for list queries: eager aggregates of gross payments and SETTLED refunds,
     * read back with aggregatedNetReceived() (one grouped query, no N+1).
     */
    public function scopeWithNetReceived($query)
    {
        return $query
            ->withSum('payments', 'amount')
            ->withSum(['refundSettlements as settled_refunds_sum_amount' => fn ($q) => $q->where('status', RmeRefundSettlementStatus::SETTLED)], 'amount');
    }

    /** NET received from the scopeWithNetReceived() aggregates. */
    public function aggregatedNetReceived(): float
    {
        return round((float) ($this->payments_sum_amount ?? 0) - (float) ($this->settled_refunds_sum_amount ?? 0), 2);
    }

    /** RFS-1 — net received = gross payments − settled refunds. */
    public function netReceivedAmount(): float
    {
        return round($this->paidAmount() - $this->settledRefundAmount(), 2);
    }

    /**
     * What is still receivable: max(0, total − NET received). A settled refund gave
     * money back, so it can never be counted again as paying a later balance
     * (e.g. after an upward post-payment correction).
     */
    public function remainingAmount(): float
    {
        return max(0, round((float) $this->grand_total - $this->netReceivedAmount(), 2));
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    /** FEATURE-RME-RP0-CONTROL-CONSULTATION-1 — closed "Tanpa Biaya" (never PAID). */
    public function isNoCharge(): bool
    {
        return $this->status === self::STATUS_NO_CHARGE;
    }

    public function isPayable(): bool
    {
        return in_array($this->status, [self::STATUS_UNPAID, self::STATUS_PARTIAL], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_UNPAID, self::STATUS_PARTIAL], true);
    }

    protected static function newFactory(): RmeInvoiceFactory
    {
        return RmeInvoiceFactory::new();
    }
}
