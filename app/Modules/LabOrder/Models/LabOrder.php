<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Invoice\Models\InvoiceItem;
use App\Modules\Patient\Models\Patient;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Production\Models\ProductionStep;
use App\Modules\QualityControl\Models\QualityControl;
use App\Modules\QualityControl\Models\RemakeRequest;
use Database\Factories\LabOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabOrder extends Model
{
    use HasFactory, SoftDeletes;

    /** Polymorphic identifier used by sys_attachments / sys_audit_logs. */
    public const ENTITY_TYPE = 'trx_lab_orders';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_ASSIGNED = 'ASSIGNED';

    public const STATUS_IN_PRODUCTION = 'IN_PRODUCTION';

    public const STATUS_ON_HOLD = 'ON_HOLD';

    public const STATUS_QC_PENDING = 'QC_PENDING';

    public const STATUS_QC_PASSED = 'QC_PASSED';

    public const STATUS_READY_FOR_DELIVERY = 'READY_FOR_DELIVERY';

    public const STATUS_IN_DELIVERY = 'IN_DELIVERY';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_REMAKE = 'REMAKE';

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
        'branch_id',
        'clinic_id',
        'doctor_id',
        'patient_id',
        'medical_record_number',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function assignments(): HasMany
    {
        return $this->hasMany(LabOrderAssignment::class, 'lab_order_id');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(LabOrderAssignment::class, 'lab_order_id')
            ->whereIn('status', LabOrderAssignment::ACTIVE_STATUSES)
            ->latestOfMany();
    }

    public function productionSteps(): HasMany
    {
        return $this->hasMany(ProductionStep::class, 'lab_order_id');
    }

    public function qualityControls(): HasMany
    {
        return $this->hasMany(QualityControl::class, 'lab_order_id');
    }

    public function remakeRequests(): HasMany
    {
        return $this->hasMany(RemakeRequest::class, 'lab_order_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'lab_order_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'lab_order_id');
    }

    public function rmeLabCaseCandidate(): HasOne
    {
        return $this->hasOne(LabCaseCandidate::class, 'converted_lab_order_id');
    }

    protected static function newFactory(): LabOrderFactory
    {
        return LabOrderFactory::new();
    }
}
