<?php

namespace App\Modules\Satusehat\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATUSEHAT-4D — governance change-control request.
 *
 * No change request may enable production or external send during 4D. Payload
 * is scalar / PII-free.
 */
class SatusehatChangeRequest extends Model
{
    protected $table = 'trx_satusehat_change_requests';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'environment',
        'category',
        'reason',
        'scope',
        'risk',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'effective_date',
        'rollback_plan',
        'audit_reference',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'requested_by' => 'integer',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'effective_date' => 'date',
            'payload' => 'array',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
