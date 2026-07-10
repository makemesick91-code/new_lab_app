<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\LabPickupTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-WORKFLOW-V2 — courier pickup task (one per V2 lab order).
 *
 * Task status mirrors the pickup leg of the order state machine but carries
 * the courier assignment + milestone timestamps. All status changes go through
 * LabPickupWorkflowService (never mass-updated from a request).
 */
class LabPickupTask extends Model
{
    use HasFactory;

    public const ENTITY_TYPE = 'trx_lab_pickup_tasks';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_PICKED_UP = 'PICKED_UP';

    public const STATUS_IN_TRANSIT = 'IN_TRANSIT';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_PICKED_UP,
        self::STATUS_IN_TRANSIT,
        self::STATUS_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses in which the task still needs courier action. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_PICKED_UP,
        self::STATUS_IN_TRANSIT,
    ];

    protected $table = 'trx_lab_pickup_tasks';

    protected $fillable = [
        'lab_order_id',
        'branch_id',
        'status',
        'courier_id',
        'accepted_at',
        'picked_up_at',
        'in_transit_at',
        'received_at',
        'received_by',
        'pickup_notes',
        'discrepancy_note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClaimedBy(User $user): bool
    {
        return $this->courier_id !== null && (int) $this->courier_id === (int) $user->id;
    }

    protected static function newFactory(): LabPickupTaskFactory
    {
        return LabPickupTaskFactory::new();
    }
}
