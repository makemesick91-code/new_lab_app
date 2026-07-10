<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Sprint 16.2 — trx_purchase_orders (purchase order document header).
 *
 * This model records purchase order identity and approval workflow only.
 * Stock remains ledger-derived from trx_inventory_movements.
 */
class PurchaseOrder extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_FULLY_RECEIVED = 'fully_received';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_SENT,
        self::STATUS_PARTIALLY_RECEIVED,
        self::STATUS_FULLY_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_FULLY_RECEIVED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'trx_purchase_orders';

    protected $fillable = [
        'branch_id',
        'purchase_order_number',
        'order_date',
        'status',
        'supplier_id',
        'supplier_snapshot_name',
        'supplier_reference_number',
        'currency',
        'purchase_request_id',
        'expected_delivery_date',
        'notes',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'sent_by',
        'sent_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
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

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isPartiallyReceived(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_RECEIVED;
    }

    public function isFullyReceived(): bool
    {
        return $this->status === self::STATUS_FULLY_RECEIVED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    public function displaySupplierName(): string
    {
        if (filled($this->supplier_snapshot_name)) {
            return $this->supplier_snapshot_name;
        }

        $this->loadMissing('supplier');

        if (filled($this->supplier?->name)) {
            return $this->supplier->name;
        }

        return '—';
    }

    public function totalAmount(): float
    {
        $this->loadMissing('items');

        return (float) $this->items->sum(fn (PurchaseOrderItem $item): float => $item->lineTotal());
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->totalAmount();
    }

    /**
     * Vendor-grouped view of the purchase order lines.
     *
     * One PO can carry items from several suppliers. Each group exposes the
     * supplier, its lines, and its server-derived subtotal. Items without a
     * resolvable supplier are grouped under a null-supplier bucket so the UI
     * can flag them rather than silently hide them.
     *
     * @return Collection<int, array{supplier_id: int|null, supplier: Supplier|null, supplier_name: string, items: Collection<int, PurchaseOrderItem>, subtotal: float, item_count: int}>
     */
    public function supplierGroups(): Collection
    {
        $this->loadMissing('items.supplier', 'items.product');

        return $this->items
            ->groupBy(fn (PurchaseOrderItem $item): string => $item->supplier_id !== null ? (string) $item->supplier_id : 'none')
            ->map(function ($items): array {
                /** @var PurchaseOrderItem $first */
                $first = $items->first();

                return [
                    'supplier_id' => $first->supplier_id !== null ? (int) $first->supplier_id : null,
                    'supplier' => $first->supplier,
                    'supplier_name' => $first->displaySupplierName(),
                    'items' => $items->values(),
                    'subtotal' => (float) $items->sum(fn (PurchaseOrderItem $item): float => $item->lineTotal()),
                    'item_count' => $items->count(),
                ];
            })
            ->sortBy('supplier_name')
            ->values();
    }

    /**
     * Distinct suppliers referenced by this PO's items (canonical), oldest-first.
     *
     * @return Collection<int, Supplier>
     */
    public function suppliersInvolved(): Collection
    {
        $this->loadMissing('items.supplier');

        return $this->items
            ->filter(fn (PurchaseOrderItem $item): bool => $item->supplier !== null)
            ->map(fn (PurchaseOrderItem $item): Supplier => $item->supplier)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    public function subtotalForSupplier(int $supplierId): float
    {
        $this->loadMissing('items');

        return (float) $this->items
            ->filter(fn (PurchaseOrderItem $item): bool => (int) $item->supplier_id === $supplierId)
            ->sum(fn (PurchaseOrderItem $item): float => $item->lineTotal());
    }

    public function hasItemsForSupplier(int $supplierId): bool
    {
        $this->loadMissing('items');

        return $this->items->contains(fn (PurchaseOrderItem $item): bool => (int) $item->supplier_id === $supplierId);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class, 'purchase_order_id');
    }

    protected static function newFactory(): PurchaseOrderFactory
    {
        return PurchaseOrderFactory::new();
    }
}
