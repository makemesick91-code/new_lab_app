<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Rule 203 — the append-only timeline of a billing dispute: the report, every
 * transition and every follow-up note. Never updated, never deleted; there is no
 * `updated_at` column. The detail screen reads this table, so the history never
 * depends on lifecycle columns that a later transition overwrites.
 */
class RmeBillingDisputeEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_REPORTED = 'REPORTED';

    public const TYPE_REVIEW_STARTED = 'REVIEW_STARTED';

    public const TYPE_ESCALATED = 'ESCALATED';

    public const TYPE_NOTE_ADDED = 'NOTE_ADDED';

    public const TYPE_RESOLVED = 'RESOLVED';

    public const LABELS = [
        self::TYPE_REPORTED => 'Dilaporkan',
        self::TYPE_REVIEW_STARTED => 'Mulai ditinjau',
        self::TYPE_ESCALATED => 'Dieskalasi ke Owner',
        self::TYPE_NOTE_ADDED => 'Catatan tindak lanjut',
        self::TYPE_RESOLVED => 'Diselesaikan',
    ];

    protected $table = 'trx_rme_billing_dispute_events';

    protected $fillable = [
        'rme_billing_dispute_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_id',
        'note',
        'occurred_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeBillingDisputeEvent $event): void {
            throw new LogicException(sprintf('RME billing dispute event #%d is append-only.', (int) $event->getKey()));
        });

        static::deleting(function (RmeBillingDisputeEvent $event): void {
            throw new LogicException(sprintf('RME billing dispute event #%d can never be deleted.', (int) $event->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_billing_dispute_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function label(): string
    {
        return self::LABELS[(string) $this->event_type] ?? (string) $this->event_type;
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(RmeBillingDispute::class, 'rme_billing_dispute_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
