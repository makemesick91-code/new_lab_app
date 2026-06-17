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
