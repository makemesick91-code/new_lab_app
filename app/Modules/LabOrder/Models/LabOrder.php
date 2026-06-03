<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Factories\LabOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabOrder extends Model
{
    use HasFactory, SoftDeletes;

    /** Polymorphic identifier used by sys_attachments / sys_audit_logs. */
    public const ENTITY_TYPE = 'trx_lab_orders';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** Official Lab Order status enum (single source of truth). */
    public const STATUSES = [
        'DRAFT',
        'RECEIVED',
        'ASSIGNED',
        'IN_PRODUCTION',
        'QC_PENDING',
        'QC_PASSED',
        'READY_FOR_DELIVERY',
        'IN_DELIVERY',
        'DELIVERED',
        'COMPLETED',
        'ON_HOLD',
        'CANCELLED',
        'REMAKE',
    ];

    /** Statuses with an active transition in Sprint 3. */
    public const ACTIVE_STATUSES = ['RECEIVED', 'CANCELLED'];

    public const PRIORITIES = ['NORMAL', 'URGENT', 'SUPER_URGENT'];

    protected $table = 'trx_lab_orders';

    protected $fillable = [
        'order_number',
        'clinic_id',
        'doctor_id',
        'patient_id',
        'order_date',
        'due_date',
        'priority',
        'status',
        'notes',
        'delivery_signature_path',
        'delivery_photo_path',
        'received_by_name',
        'received_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'due_date' => 'date',
            'received_at' => 'datetime',
        ];
    }

    public function isEditable(): bool
    {
        return ! in_array($this->status, [self::STATUS_CANCELLED, 'COMPLETED'], true);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'clinic_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class, 'lab_order_id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(LabOrderStatusLog::class, 'lab_order_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'entity');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'entity');
    }

    protected static function newFactory(): LabOrderFactory
    {
        return LabOrderFactory::new();
    }
}
