<?php

namespace App\Modules\RmeInvoice\Models;

use App\Modules\Doctor\Models\Doctor;
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

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
