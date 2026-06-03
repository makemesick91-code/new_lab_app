<?php

namespace App\Modules\LabOrder\Models;

use App\Modules\LabService\Models\LabService;
use Database\Factories\LabOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabOrderItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'trx_lab_order_items';

    protected $fillable = [
        'lab_order_id',
        'lab_service_id',
        'tooth_number',
        'shade_color_text',
        'material_text',
        'quantity',
        'unit_price',
        'subtotal',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function labService(): BelongsTo
    {
        return $this->belongsTo(LabService::class, 'lab_service_id');
    }

    protected static function newFactory(): LabOrderItemFactory
    {
        return LabOrderItemFactory::new();
    }
}
