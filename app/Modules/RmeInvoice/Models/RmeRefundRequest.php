<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (Sprint 4/7, rule 215) — a request
 * to pay back an OPEN overpayment obligation, and its decision. APPROVED never
 * means money left the clinic: settlement is Sprint 5 and has no state here.
 *
 * Nothing that decides is mass-assignable: status, the decision and cancellation
 * columns and the Owner approval reference are written only by the repository's
 * allow-listed writes. Everything that describes what was requested is immutable
 * once stored; the row can never be deleted.
 */
class RmeRefundRequest extends Model
{
    protected $table = 'trx_rme_refund_requests';

    /** Shape only; decisions and references never come from an attribute array. */
    protected $fillable = [
        'reason_note',
        'patient_request_reference',
    ];

    /** Written once at creation, never again. */
    public const IMMUTABLE_ATTRIBUTES = [
        'uuid',
        'branch_id',
        'rme_invoice_id',
        'rme_overpayment_obligation_id',
        'clinic_visit_id',
        'patient_id',
        'amount',
        'obligation_amount_snapshot',
        'invoice_version',
        'request_snapshot',
        'request_snapshot_sha256',
        'reason_code',
        'reason_note',
        'patient_request_reference',
        'intent_key',
        'request_fingerprint',
        'requested_by',
        'requested_at',
        'owner_approval_reference',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeRefundRequest $request): void {
            foreach (self::IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($request->isDirty($attribute)) {
                    throw new LogicException(sprintf('RME refund request #%d: %s is immutable.', (int) $request->getKey(), $attribute));
                }
            }

            if ($request->isDirty('request_number') && $request->getOriginal('request_number') !== null) {
                throw new LogicException(sprintf('RME refund request #%d already has a number.', (int) $request->getKey()));
            }
        });

        static::deleting(function (RmeRefundRequest $request): void {
            throw new LogicException(sprintf('RME refund request #%d can never be deleted.', (int) $request->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'rme_invoice_id' => 'integer',
            'rme_overpayment_obligation_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'amount' => 'decimal:2',
            'obligation_amount_snapshot' => 'decimal:2',
            'invoice_version' => 'integer',
            'request_snapshot' => 'array',
            'requested_by' => 'integer',
            'requested_at' => 'datetime',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'datetime',
            'staled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, RmeRefundRequestStatus::ACTIVE, true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class, 'rme_invoice_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(RmeOverpaymentObligation::class, 'rme_overpayment_obligation_id');
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RmeRefundRequestEvent::class, 'rme_refund_request_id')->orderBy('occurred_at')->orderBy('id');
    }
}
