<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\PurchaseRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 16.1 — trx_purchase_requests (purchase request document header).
 *
 * This model records purchase request identity and approval workflow only.
 * Stock remains ledger-derived from trx_inventory_movements.
 */
class PurchaseRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    // FIX-PRE-68-45 Scope G — branch PR type (Kepala Cabang flow). NULL = legacy /
    // unclassified (treated as regular).
    public const PR_TYPE_REGULER = 'reguler';

    public const PR_TYPE_DARURAT = 'darurat';

    public const PR_TYPES = [
        self::PR_TYPE_REGULER,
        self::PR_TYPE_DARURAT,
    ];

    protected $table = 'trx_purchase_requests';

    protected $fillable = [
        'branch_id',
        'purchase_request_number',
        'request_date',
        'status',
        'pr_type',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    // FIX-PRE-68-45 Scope G — a Darurat (emergency) branch PR.
    public function isEmergency(): bool
    {
        return $this->pr_type === self::PR_TYPE_DARURAT;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class, 'purchase_request_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'purchase_request_id');
    }

    public function hasActivePurchaseOrder(): bool
    {
        return $this->purchaseOrders()
            ->where('status', '!=', PurchaseOrder::STATUS_CANCELLED)
            ->exists();
    }

    protected static function newFactory(): PurchaseRequestFactory
    {
        return PurchaseRequestFactory::new();
    }
}
