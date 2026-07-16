<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only SATUSEHAT audit entry. There is no updated_at, no soft delete, and
 * no normal UI action ever updates or deletes a row. Context is sanitized and
 * PII-free (never NIK/token/raw payload).
 */
class SatusehatAuditLog extends Model
{
    protected $table = 'trx_satusehat_audit_logs';

    // Append-only: keep created_at, disable updated_at.
    public const UPDATED_AT = null;

    // --- Canonical event vocabulary ---
    public const EVENT_CANDIDATE_CREATED = 'candidate_created';

    public const EVENT_CANDIDATE_REFRESHED = 'candidate_refreshed';

    public const EVENT_READINESS_CALCULATED = 'readiness_calculated';

    public const EVENT_PREVIEW_OPENED = 'preview_opened';

    public const EVENT_APPROVED = 'approved';

    public const EVENT_EXCLUDED = 'excluded';

    public const EVENT_APPROVAL_REVOKED = 'approval_revoked';

    public const EVENT_QUEUE_ATTEMPTED = 'queue_attempted';

    public const EVENT_QUEUE_BLOCKED = 'queue_blocked';

    public const EVENT_CANCELLED = 'cancelled';

    public const EVENT_MAPPING_CREATED = 'mapping_created';

    public const EVENT_MAPPING_REVIEWED = 'mapping_reviewed';

    public const EVENT_MAPPING_ACTIVATED = 'mapping_activated';

    public const EVENT_MAPPING_DEPRECATED = 'mapping_deprecated';

    public const EVENT_IDENTIFIER_CREATED = 'identifier_created';

    public const EVENT_IDENTIFIER_UPDATED = 'identifier_updated';

    public const EVENT_UNAUTHORIZED_ATTEMPT = 'unauthorized_attempt';

    protected $fillable = [
        'environment',
        'branch_id',
        'entity_type',
        'entity_id',
        'event',
        'actor_id',
        'actor_role',
        'summary',
        'context',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'entity_id' => 'integer',
            'actor_id' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
