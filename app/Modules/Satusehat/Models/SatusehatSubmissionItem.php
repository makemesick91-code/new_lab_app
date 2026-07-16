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

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PREPARED,
        self::STATUS_BLOCKED,
    ];

    public const RESOURCE_ENCOUNTER = 'Encounter';

    public const RESOURCE_CONDITION = 'Condition';

    public const RESOURCE_PROCEDURE = 'Procedure';

    protected $fillable = [
        'submission_batch_id',
        'satusehat_candidate_id',
        'dependency_order',
        'resource_type',
        'local_source_type',
        'local_source_id',
        'source_hash',
        'payload_hash',
        'idempotency_key',
        'status',
        'attempt_count',
        'remote_resource_id',
        'result_summary',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'submission_batch_id' => 'integer',
            'satusehat_candidate_id' => 'integer',
            'dependency_order' => 'integer',
            'local_source_id' => 'integer',
            'attempt_count' => 'integer',
        ];
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
