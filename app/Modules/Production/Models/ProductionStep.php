<?php

namespace App\Modules\Production\Models;

use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionStep extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const STATUS_ON_HOLD = 'ON_HOLD';

    public const STATUSES = ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'SKIPPED', 'ON_HOLD'];

    /** Default production steps created when an order is first assigned. */
    public const DEFAULT_STEPS = [
        'MODEL_PREPARATION',
        'WAX_DESIGN',
        'MILLING',
        'PRINTING',
        'FINISHING',
        'POLISHING',
        'PACKAGING',
    ];

    protected $table = 'trx_lab_production_steps';

    protected $fillable = [
        'lab_order_id',
        'step_name',
        'status',
        'started_at',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }
}
