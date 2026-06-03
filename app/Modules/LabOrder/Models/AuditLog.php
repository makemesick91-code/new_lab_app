<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic, immutable audit trail (sys_audit_logs). Only created_at managed.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    public const ACTION_CANCEL = 'CANCEL';

    public const ACTION_UPLOAD_ATTACHMENT = 'UPLOAD_ATTACHMENT';

    public const ACTION_DELETE_ATTACHMENT = 'DELETE_ATTACHMENT';

    public const ACTION_STATUS_CHANGE = 'STATUS_CHANGE';

    // Sprint 4 — Production Workflow actions
    public const ACTION_ASSIGN_TECHNICIAN = 'ASSIGN_TECHNICIAN';

    public const ACTION_REASSIGN_TECHNICIAN = 'REASSIGN_TECHNICIAN';

    public const ACTION_START_WORK = 'START_WORK';

    public const ACTION_PAUSE_WORK = 'PAUSE_WORK';

    public const ACTION_RESUME_WORK = 'RESUME_WORK';

    public const ACTION_COMPLETE_WORK = 'COMPLETE_WORK';

    public const ACTION_SEND_TO_QC = 'SEND_TO_QC';

    public const ACTION_UPDATE_PRODUCTION_STEP = 'UPDATE_PRODUCTION_STEP';

    protected $table = 'sys_audit_logs';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'old_values',
        'new_values',
        'performed_by',
        'performed_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
