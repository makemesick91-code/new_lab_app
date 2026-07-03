<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Disposal/return/adjustment evidence request for inventory batches (Sprint 68.42).
 * available_quantity_snapshot is audit-only — never use for stock calculation.
 */
class InventoryBatchDisposalRequest extends Model
{
    protected $table = 'trx_inventory_batch_disposal_requests';

    protected $fillable = [
        'branch_id',
        'inventory_batch_id',
        'inventory_batch_action_log_id',
        'inventory_location_id',
        'product_id',
        'request_type',
        'status',
        'quantity_requested',
        'available_quantity_snapshot',
        'evidence_note',
        'evidence_reference',
        'rejection_reason',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'finalized_by',
        'finalized_at',
        'inventory_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:4',
            'available_quantity_snapshot' => 'decimal:4',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }

    public function actionLog(): BelongsTo
    {
        return $this->belongsTo(InventoryBatchActionLog::class, 'inventory_batch_action_log_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }

    public function isDraft(): bool
    {
        return $this->status === InventoryBatchDisposalRequestStatus::DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === InventoryBatchDisposalRequestStatus::SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === InventoryBatchDisposalRequestStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === InventoryBatchDisposalRequestStatus::REJECTED;
    }

    public function isFinalized(): bool
    {
        return $this->status === InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED;
    }

    public function isCancelled(): bool
    {
        return $this->status === InventoryBatchDisposalRequestStatus::CANCELLED;
    }

    public function canSubmit(): bool
    {
        return $this->isDraft();
    }

    public function canApprove(): bool
    {
        return $this->isSubmitted();
    }

    public function canReject(): bool
    {
        return $this->isSubmitted();
    }

    public function canFinalize(): bool
    {
        return $this->isApproved() && $this->inventory_movement_id === null;
    }

    public function canCancel(): bool
    {
        return $this->isDraft() || $this->isSubmitted();
    }

    public function statusLabel(): string
    {
        return InventoryBatchDisposalRequestStatus::label((string) $this->status);
    }

    public function requestTypeLabel(): string
    {
        return InventoryBatchDisposalRequestType::label((string) $this->request_type);
    }
}
