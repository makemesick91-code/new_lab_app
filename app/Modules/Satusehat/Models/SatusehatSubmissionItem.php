<?php

namespace App\Modules\Satusehat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One FHIR resource (Encounter/Condition/Procedure) prepared from a candidate.
 * Carries dependency ordering + a unique idempotency key for a FUTURE
 * submission. In SATUSEHAT-1 it never reaches "sent"; only pending/prepared/blocked.
 */
class SatusehatSubmissionItem extends Model
{
    use SoftDeletes;

    protected $table = 'trx_satusehat_submission_items';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_BLOCKED = 'blocked';

    // SATUSEHAT-2 lifecycle states.
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';

    public const STATUS_PERMANENT_FAILED = 'permanent_failed';

    public const STATUS_UNKNOWN_OUTCOME = 'unknown_outcome';

    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const STATUS_RECONCILED = 'reconciled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PREPARED,
        self::STATUS_BLOCKED,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_SUCCEEDED,
        self::STATUS_RETRYABLE_FAILED,
        self::STATUS_PERMANENT_FAILED,
        self::STATUS_UNKNOWN_OUTCOME,
        self::STATUS_RECONCILIATION_REQUIRED,
        self::STATUS_RECONCILED,
        self::STATUS_CANCELLED,
    ];

    /** Terminal states — a successfully-sent resource is never resent. */
    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_RECONCILED,
        self::STATUS_PERMANENT_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_BLOCKED,
    ];

    public const OPERATION_CREATE = 'create';

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_GET = 'get';

    public const RESOURCE_ENCOUNTER = 'Encounter';

    public const RESOURCE_CONDITION = 'Condition';

    public const RESOURCE_PROCEDURE = 'Procedure';

    protected $fillable = [
        'submission_batch_id',
        'satusehat_candidate_id',
        'dependency_order',
        'resource_type',
        'operation_type',
        'local_source_type',
        'local_source_id',
        'source_hash',
        'payload_hash',
        'request_payload_hash',
        'response_hash',
        'idempotency_key',
        'correlation_id',
        'status',
        'attempt_count',
        'remote_resource_id',
        'remote_version_id',
        'http_status',
        'outcome_classification',
        'operation_outcome',
        'result_summary',
        'error_summary',
        'reconciliation_note',
        'next_attempt_at',
        'locked_at',
        'locked_by',
        'submitted_at',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'submission_batch_id' => 'integer',
            'satusehat_candidate_id' => 'integer',
            'dependency_order' => 'integer',
            'local_source_id' => 'integer',
            'attempt_count' => 'integer',
            'http_status' => 'integer',
            'operation_outcome' => 'array',
            'next_attempt_at' => 'datetime',
            'locked_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isSucceeded(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_RECONCILED], true);
    }

    public function needsReconciliation(): bool
    {
        return in_array($this->status, [self::STATUS_UNKNOWN_OUTCOME, self::STATUS_RECONCILIATION_REQUIRED], true);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SatusehatSubmissionBatch::class, 'submission_batch_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SatusehatCandidate::class, 'satusehat_candidate_id');
    }
}
