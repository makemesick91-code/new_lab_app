<?php

namespace App\Models\Foundation;

use Illuminate\Database\Eloquent\Model;

/**
 * QUEUE-1 — Idempotency reservation record.
 *
 * key_hash is a SHA-256 hash of the caller-supplied raw key — the raw key
 * itself is never persisted. metadata must never contain PII/secrets.
 */
class IdempotencyKey extends Model
{
    protected $table = 'sys_idempotency_keys';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'key_hash',
        'scope',
        'owner_type',
        'owner_id',
        'status',
        'request_fingerprint_hash',
        'response_fingerprint_hash',
        'locked_until',
        'expires_at',
        'completed_at',
        'failed_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'locked_until' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
