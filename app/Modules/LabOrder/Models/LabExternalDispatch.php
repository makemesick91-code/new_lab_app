<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-WORKFLOW-V2 — one physical send-out to an external lab.
 *
 * Row status mirrors the external leg of the order state machine; rejected
 * results spawn a NEW dispatch on resend (append-only history).
 */
class LabExternalDispatch extends Model
{
    use HasFactory;

    public const STATUS_PREPARATION = 'PREPARATION';

    public const STATUS_SENT = 'SENT';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_RETURNED = 'RETURNED';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const RESULT_ACCEPTED = 'ACCEPTED';

    public const RESULT_REJECTED = 'REJECTED';

    /** Statuses in which the dispatch is still the order's active dispatch. */
    public const ACTIVE_STATUSES = [
        self::STATUS_PREPARATION,
        self::STATUS_SENT,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RETURNED,
    ];

    protected $table = 'trx_lab_external_dispatches';

    protected $fillable = [
        'lab_order_id',
        'external_lab_id',
        'status',
        'reason',
        'expected_return_date',
        'shipping_method',
        'reference_number',
        'cost',
        'sent_at',
        'returned_at',
        'review_result',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expected_return_date' => 'date',
            'cost' => 'decimal:2',
            'sent_at' => 'datetime',
            'returned_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function externalLab(): BelongsTo
    {
        return $this->belongsTo(ExternalLab::class, 'external_lab_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
