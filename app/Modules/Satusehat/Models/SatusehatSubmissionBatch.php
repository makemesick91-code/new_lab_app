<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A submission batch groups approved candidates prepared for a FUTURE
 * SATUSEHAT-2 submission. In SATUSEHAT-1 a batch never reaches a "sent" state —
 * it is prepared or blocked (external integration disabled).
 */
class SatusehatSubmissionBatch extends Model
{
    use SoftDeletes;

    protected $table = 'trx_satusehat_submission_batches';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_CANCELLED = 'cancelled';

    // SATUSEHAT-2 lifecycle states.
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PREPARING,
        self::STATUS_PREPARED,
        self::STATUS_BLOCKED,
        self::STATUS_CANCELLED,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_PARTIAL,
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_RECONCILIATION_REQUIRED,
    ];

    protected $fillable = [
        'environment',
        'branch_id',
        'requested_by',
        'status',
        'correlation_id',
        'candidate_count',
        'resource_count',
        'succeeded_count',
        'failed_count',
        'unknown_count',
        'notes',
        'prepared_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'requested_by' => 'integer',
            'candidate_count' => 'integer',
            'resource_count' => 'integer',
            'succeeded_count' => 'integer',
            'failed_count' => 'integer',
            'unknown_count' => 'integer',
            'prepared_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SatusehatSubmissionItem::class, 'submission_batch_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Draf',
            self::STATUS_PREPARING => 'Menyiapkan',
            self::STATUS_PREPARED => 'Siap (Menunggu Integrasi)',
            self::STATUS_BLOCKED => 'Terblokir',
            self::STATUS_CANCELLED => 'Dibatalkan',
            self::STATUS_QUEUED => 'Antre Pengiriman',
            self::STATUS_PROCESSING => 'Mengirim',
            self::STATUS_PARTIAL => 'Sebagian Terkirim',
            self::STATUS_SUCCEEDED => 'Terkirim',
            self::STATUS_FAILED => 'Gagal',
            self::STATUS_RECONCILIATION_REQUIRED => 'Perlu Rekonsiliasi',
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_PREPARED => 'info',
            self::STATUS_PREPARING, self::STATUS_QUEUED, self::STATUS_PROCESSING => 'warning',
            self::STATUS_SUCCEEDED => 'success',
            self::STATUS_PARTIAL, self::STATUS_RECONCILIATION_REQUIRED => 'warning',
            self::STATUS_BLOCKED, self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'neutral',
            default => 'neutral',
        };
    }
}
