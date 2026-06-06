<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sprint 16.3 — trx_goods_receipts (goods receipt document header).
 *
 * This model records goods receipt identity and workflow state only.
 * Stock remains ledger-derived from trx_inventory_movements.
 */
class GoodsReceipt extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_POSTED,
        self::STATUS_CANCELLED,
        self::STATUS_VOID,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_POSTED,
        self::STATUS_CANCELLED,
        self::STATUS_VOID,
    ];

    protected $table = 'trx_goods_receipts';

    protected $fillable = [
        'branch_id',
        'purchase_order_id',
        'receipt_number',
        'receipt_date',
        'supplier_delivery_number',
        'supplier_invoice_number',
        'status',
        'notes',
        'cancellation_reason',
        'submitted_at',
        'posted_at',
        'cancelled_at',
        'voided_at',
        'created_by',
        'submitted_by',
        'posted_by',
        'cancelled_by',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'submitted_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'voided_at' => 'datetime',
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

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canBeEdited(): bool
    {
        return $this->isDraft();
    }

    public function canBePosted(): bool
    {
        return $this->isDraft() || $this->isSubmitted();
    }

    public function canBeCancelled(): bool
    {
        return $this->isDraft() || $this->isSubmitted();
    }

    public function canBeVoided(): bool
    {
        return $this->isPosted() && $this->posted_at !== null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'goods_receipt_id');
    }

    protected static function newFactory(): GoodsReceiptFactory
    {
        return GoodsReceiptFactory::new();
    }
}
