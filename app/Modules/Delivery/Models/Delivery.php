<?php

namespace App\Modules\Delivery\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\Attachment;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use HasFactory, SoftDeletes;

    public const ENTITY_TYPE = 'trx_lab_deliveries';

    public const STATUS_READY_FOR_DELIVERY = 'READY_FOR_DELIVERY';

    public const STATUS_IN_DELIVERY = 'IN_DELIVERY';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUSES = [
        self::STATUS_READY_FOR_DELIVERY,
        self::STATUS_IN_DELIVERY,
        self::STATUS_DELIVERED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'trx_lab_deliveries';

    protected $fillable = [
        'lab_order_id',
        'branch_id',
        'delivery_number',
        'courier_id',
        'status',
        'delivery_notes',
        'receiver_name',
        'receiver_signature_path',
        'receiver_photo_path',
        'received_at',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'entity');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'entity');
    }

    public function hasCompletePod(): bool
    {
        return filled($this->receiver_name)
            && filled($this->receiver_signature_path)
            && filled($this->receiver_photo_path)
            && $this->received_at !== null;
    }

    protected static function newFactory(): DeliveryFactory
    {
        return DeliveryFactory::new();
    }
}
