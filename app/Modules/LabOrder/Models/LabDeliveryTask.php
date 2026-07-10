<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\LabDeliveryTaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-WORKFLOW-V2 — courier delivery task (one per V2 lab order).
 *
 * Status changes only via LabDeliveryWorkflowService (never mass-updated from
 * a request). The evidence gates live on the order's typed workflow evidence.
 */
class LabDeliveryTask extends Model
{
    use HasFactory;

    public const ENTITY_TYPE = 'trx_lab_delivery_tasks';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_READY_FOR_TRANSIT = 'READY_FOR_TRANSIT';

    public const STATUS_IN_TRANSIT = 'IN_TRANSIT';

    public const STATUS_ARRIVED = 'ARRIVED';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_READY_FOR_TRANSIT,
        self::STATUS_IN_TRANSIT,
        self::STATUS_ARRIVED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses in which the task still needs courier action. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_READY_FOR_TRANSIT,
        self::STATUS_IN_TRANSIT,
        self::STATUS_ARRIVED,
    ];

    protected $table = 'trx_lab_delivery_tasks';

    protected $fillable = [
        'lab_order_id',
        'branch_id',
        'status',
        'courier_id',
        'accepted_at',
        'ready_at',
        'in_transit_at',
        'arrived_at',
        'delivered_at',
        'recipient_name',
        'recipient_role',
        'delivery_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'in_transit_at' => 'datetime',
            'arrived_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClaimedBy(User $user): bool
    {
        return $this->courier_id !== null && (int) $this->courier_id === (int) $user->id;
    }

    protected static function newFactory(): LabDeliveryTaskFactory
    {
        return LabDeliveryTaskFactory::new();
    }
}
