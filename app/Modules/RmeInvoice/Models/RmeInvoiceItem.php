<?php

namespace App\Modules\RmeInvoice\Models;

use App\Modules\Doctor\Models\Doctor;
use App\Modules\Tariff\Models\Tariff;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RmeInvoiceItem extends Model
{
    protected $table = 'trx_rme_invoice_items';

    protected $fillable = [
        'rme_invoice_id',
        'treatment_id',
        'description',
        'qty',
        'unit_price',
        'discount',
        'subtotal',
        'doctor_id',
        // FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — price provenance (D21).
        'pricing_mode',
        'price_source',
        'tariff_id',
        'tariff_unit_price',
        'price_exception_reason',
        'price_exception_by',
    ];

    protected function casts(): array
    {
        return [
            'rme_invoice_id' => 'integer',
            'treatment_id' => 'integer',
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'doctor_id' => 'integer',
            'tariff_id' => 'integer',
            'tariff_unit_price' => 'decimal:2',
            'price_exception_by' => 'integer',
        ];
    }

    public function rmeInvoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class)->withTrashed();
    }

    /**
     * Whether this line's price was recorded under tariff authority at all.
     * NULL = billed before FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 (never backfilled).
     */
    public function hasPriceProvenance(): bool
    {
        return $this->price_source !== null;
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
