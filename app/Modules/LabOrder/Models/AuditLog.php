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
