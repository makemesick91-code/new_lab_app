<?php

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use Database\Factories\RmeInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RmeInvoice extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_UNPAID = 'UNPAID';

    public const STATUS_VOID = 'VOID';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_UNPAID,
        self::STATUS_VOID,
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

    public function items(): HasMany
    {
        return $this->hasMany(RmeInvoiceItem::class);
    }

    public function recalculateTotals(): void
    {
        $subtotal = $this->items->sum('subtotal');
        $discountTotal = $this->items->sum('discount');
        $this->subtotal = $subtotal;
        $this->discount_total = $discountTotal;
        $this->grand_total = $subtotal - $discountTotal;
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_UNPAID]);
    }

    protected static function newFactory(): RmeInvoiceFactory
    {
        return RmeInvoiceFactory::new();
    }
}
