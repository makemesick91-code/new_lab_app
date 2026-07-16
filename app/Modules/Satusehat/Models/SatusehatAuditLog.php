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

    // SATUSEHAT-2 — outbound submission lifecycle events (all PII-free).
    public const EVENT_SUBMISSION_QUEUED = 'submission_queued';

    public const EVENT_SUBMISSION_STARTED = 'submission_started';

    public const EVENT_RESOURCE_SUBMITTED = 'resource_submitted';

    public const EVENT_RESOURCE_RETRYABLE_FAILED = 'resource_retryable_failed';

    public const EVENT_RESOURCE_PERMANENT_FAILED = 'resource_permanent_failed';

    public const EVENT_RESOURCE_UNKNOWN_OUTCOME = 'resource_unknown_outcome';

    public const EVENT_RESOURCE_RECONCILED = 'resource_reconciled';

    public const EVENT_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const EVENT_SOURCE_DRIFT_ABORTED = 'source_drift_aborted';

    public const EVENT_SEND_BLOCKED = 'send_blocked';

    public const EVENT_BATCH_COMPLETED = 'batch_completed';

    public const EVENT_IDENTIFIER_VERIFIED = 'identifier_verified';

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
