<?php

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Database\Factories\RmePaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RmePayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'trx_rme_payments';

    protected $fillable = [
        'branch_id',
        'rme_invoice_id',
        'clinic_visit_id',
        'patient_id',
        'cashier_id',
        'payment_method_id',
        'payment_number',
        'amount',
        'paid_at',
        'reference_number',
        'notes',
        'payment_batch_uuid',
    ];

    /**
     * FEATURE-RME-BILLING-REFUND-1 / Phase 0 (audit BCF-09, rule 188 §3) — a
     * recorded payment is an immutable historical fact.
     *
     * The ledger is append-only: invoice status, receivables, collections KPIs
     * and every future refund balance are all derived from SUM(amount) of the
     * rows in this table. Re-amounting, re-pointing, re-dating or soft-deleting a
     * row would silently rewrite all of them — `RmeInvoice::paidAmount()` excludes
     * soft-deleted rows, so a delete is indistinguishable from "never paid". A
     * correction or refund is ALWAYS its own record; it never edits this one.
     *
     * Rule 188 stated this as a contract with nothing enforcing it. This guard
     * makes the Eloquent layer refuse it. `patient_id` is the single exception:
     * patient merge legitimately re-points ownership (registry rule BCF-13) — and
     * does so through the query builder, which does not raise model events.
     *
     * LIMIT, stated honestly: a raw SQL statement or a query-builder mass update
     * bypasses model events and is NOT stopped here. This guards the application
     * path; it is not a database constraint.
     *
     * @var list<string>
     */
    public const MUTABLE_ATTRIBUTES = ['patient_id', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (RmePayment $payment): void {
            $changed = array_diff(array_keys($payment->getDirty()), self::MUTABLE_ATTRIBUTES);

            if ($changed !== []) {
                throw new \LogicException(sprintf(
                    'RME payment #%d is immutable; refusing to change [%s]. Record a correction or refund instead.',
                    (int) $payment->getKey(),
                    implode(', ', $changed),
                ));
            }
        });

        static::deleting(function (RmePayment $payment): void {
            throw new \LogicException(sprintf(
                'RME payment #%d is immutable and can never be deleted. Record a correction or refund instead.',
                (int) $payment->getKey(),
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
            'cashier_id' => 'integer',
            'payment_method_id' => 'integer',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function rmeInvoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    protected static function newFactory(): RmePaymentFactory
    {
        return RmePaymentFactory::new();
    }
}
