<?php

namespace App\Models\Foundation;

use Illuminate\Database\Eloquent\Model;

/**
 * QUEUE-1 — Outbox event record.
 *
 * Governance-only foundation: no domain code creates real events in this
 * sprint, and payload must only ever hold safe, non-PII, non-secret data —
 * enforced by App\Services\Foundation\OutboxService before insert.
 */
class OutboxEvent extends Model
{
    protected $table = 'sys_outbox_events';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'event_uuid',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'event_version',
        'status',
        'payload',
        'payload_classification',
        'idempotency_key_hash',
        'available_at',
        'locked_until',
        'attempts',
        'max_attempts',
        'last_error_code',
        'last_error_summary',
        'dispatched_at',
        'failed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'locked_until' => 'datetime',
        'dispatched_at' => 'datetime',
        'failed_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
    ];
}
