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

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PREPARING,
        self::STATUS_PREPARED,
        self::STATUS_BLOCKED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'environment',
        'branch_id',
        'requested_by',
        'status',
        'candidate_count',
        'resource_count',
        'notes',
        'prepared_at',
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
            'prepared_at' => 'datetime',
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
            default => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_PREPARED => 'info',
            self::STATUS_PREPARING => 'warning',
            self::STATUS_BLOCKED => 'danger',
            self::STATUS_CANCELLED => 'neutral',
            default => 'neutral',
        };
    }
}
