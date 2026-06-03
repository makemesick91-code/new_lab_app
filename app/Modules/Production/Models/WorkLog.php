<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable production work event. Only created_at is managed (no updated_at).
 */
class WorkLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_WORK_STARTED = 'WORK_STARTED';

    public const EVENT_WORK_PAUSED = 'WORK_PAUSED';

    public const EVENT_WORK_RESUMED = 'WORK_RESUMED';

    public const EVENT_WORK_COMPLETED = 'WORK_COMPLETED';

    public const EVENT_STATUS_CHANGED = 'STATUS_CHANGED';

    public const EVENTS = ['WORK_STARTED', 'WORK_PAUSED', 'WORK_RESUMED', 'WORK_COMPLETED', 'STATUS_CHANGED'];

    protected $table = 'trx_lab_work_logs';

    protected $fillable = [
        'assignment_id',
        'event_type',
        'started_at',
        'ended_at',
        'duration_minutes',
        'notes',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LabOrderAssignment::class, 'assignment_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
