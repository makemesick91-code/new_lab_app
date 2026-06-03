<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Technician\Models\Technician;
use Database\Factories\LabOrderAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabOrderAssignment extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ASSIGNED = 'ASSIGNED';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_DONE = 'DONE';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_REASSIGNED = 'REASSIGNED';

    public const STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'DONE', 'CANCELLED', 'REASSIGNED'];

    /** Statuses that represent the single currently-active assignment. */
    public const ACTIVE_STATUSES = ['ASSIGNED', 'IN_PROGRESS'];

    protected $table = 'trx_lab_order_assignments';

    protected $fillable = [
        'lab_order_id',
        'technician_id',
        'assigned_by',
        'assigned_at',
        'started_at',
        'completed_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class, 'assignment_id');
    }

    protected static function newFactory(): LabOrderAssignmentFactory
    {
        return LabOrderAssignmentFactory::new();
    }
}
