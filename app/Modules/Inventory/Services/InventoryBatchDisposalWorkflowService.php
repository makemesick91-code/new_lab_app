<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
use App\Modules\Inventory\Interfaces\InventoryBatchActionLogRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchDisposalRequestRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryBatchRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\InventoryMovementRepositoryInterface;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryBatchDisposalWorkflowService
{
    public function __construct(
        private readonly InventoryBatchDisposalRequestRepositoryInterface $requests,
        private readonly InventoryBatchRepositoryInterface $batches,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly InventoryMovementRepositoryInterface $movements,
        private readonly InventoryBatchActionLogRepositoryInterface $actionLogs,
        private readonly InventoryStockService $stock,
        private readonly BranchContext $branchContext,
    ) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->requests->paginateForBranch($this->branchContext->requireId(), $filters, $perPage);
    }

    public function findForShow(int $id): ?InventoryBatchDisposalRequest
    {
        $request = $this->requests->findForBranch($this->branchContext->requireId(), $id);

        if ($request !== null) {
            $request->load(['batch', 'product', 'location', 'actionLog.actor', 'submittedBy', 'approvedBy', 'rejectedBy', 'finalizedBy', 'movement']);
        }

        return $request;
    }

    /**
     * @return Collection<int, InventoryBatchDisposalRequest>
     */
    public function forBatch(InventoryBatch $batch): Collection
    {
        $branchId = $this->branchContext->requireId();
        $this->assertBatchAccessible($branchId, $batch->id);

        return $this->requests->forBatch($branchId, $batch->id);
    }

    public function latestForBatch(InventoryBatch $batch): ?InventoryBatchDisposalRequest
    {
        $branchId = $this->branchContext->requireId();
        $this->assertBatchAccessible($branchId, $batch->id);

        return $this->requests->latestForBatch($branchId, $batch->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submitRequest(InventoryBatch $batch, array $data, ?User $actor = null): InventoryBatchDisposalRequest
    {
        $branchId = $this->branchContext->requireId();
        $batch = $this->assertBatchAccessible($branchId, $batch->id);

        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('Pengguna tidak terautentikasi.');
        }

        $locationId = (int) $data['inventory_location_id'];
        $this->assertLocationInBranch($branchId, $locationId);

        $productId = (int) $batch->product_id;
        $quantityRequested = (float) $data['quantity_requested'];

        if ($quantityRequested <= 0) {
            throw ValidationException::withMessages([
                'quantity_requested' => 'Jumlah permintaan harus lebih dari nol.',
            ]);
        }

        $available = $this->movements->currentStockByBatch($branchId, $productId, $locationId, $batch->id);

        if ($quantityRequested > $available) {
            throw ValidationException::withMessages([
                'quantity_requested' => 'Jumlah permintaan melebihi stok batch tersedia pada lokasi ini.',
            ]);
        }

        $actionLogId = isset($data['inventory_batch_action_log_id']) ? (int) $data['inventory_batch_action_log_id'] : null;
        if ($actionLogId !== null) {
            $this->assertActionLogForBatch($branchId, $batch->id, $actionLogId);
        }

        return $this->requests->create([
            'branch_id' => $branchId,
            'inventory_batch_id' => $batch->id,
            'inventory_batch_action_log_id' => $actionLogId,
            'inventory_location_id' => $locationId,
            'product_id' => $productId,
            'request_type' => $data['request_type'],
            'status' => InventoryBatchDisposalRequestStatus::SUBMITTED,
            'quantity_requested' => $quantityRequested,
            'available_quantity_snapshot' => $available,
            'evidence_note' => $data['evidence_note'],
            'evidence_reference' => $data['evidence_reference'] ?? null,
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);
    }

    public function approve(InventoryBatchDisposalRequest $request, ?User $actor = null): InventoryBatchDisposalRequest
    {
        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('Pengguna tidak terautentikasi.');
        }

        $request = $this->assertRequestAccessible($request);

        if (! $request->canApprove()) {
            throw ValidationException::withMessages([
                'status' => 'Permintaan tidak dapat disetujui pada status saat ini.',
            ]);
        }

        return $this->requests->update($request, [
            'status' => InventoryBatchDisposalRequestStatus::APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(InventoryBatchDisposalRequest $request, string $rejectionReason, ?User $actor = null): InventoryBatchDisposalRequest
    {
        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('Pengguna tidak terautentikasi.');
        }

        $request = $this->assertRequestAccessible($request);

        if (! $request->canReject()) {
            throw ValidationException::withMessages([
                'status' => 'Permintaan tidak dapat ditolak pada status saat ini.',
            ]);
        }

        return $this->requests->update($request, [
            'status' => InventoryBatchDisposalRequestStatus::REJECTED,
            'rejection_reason' => $rejectionReason,
            'rejected_by' => $actor->id,
            'rejected_at' => now(),
        ]);
    }

    public function cancel(InventoryBatchDisposalRequest $request, ?User $actor = null): InventoryBatchDisposalRequest
    {
        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('Pengguna tidak terautentikasi.');
        }

        $request = $this->assertRequestAccessible($request);

        if (! $request->canCancel()) {
            throw ValidationException::withMessages([
                'status' => 'Permintaan tidak dapat dibatalkan pada status saat ini.',
            ]);
        }

        return $this->requests->update($request, [
            'status' => InventoryBatchDisposalRequestStatus::CANCELLED,
        ]);
    }

    public function finalizeAdjustment(InventoryBatchDisposalRequest $request, ?User $actor = null): InventoryBatchDisposalRequest
    {
        $actor ??= Auth::user();
        if (! $actor instanceof User) {
            throw new AuthorizationException('Pengguna tidak terautentikasi.');
        }

        $branchId = $this->branchContext->requireId();

        return DB::transaction(function () use ($request, $actor, $branchId) {
            $locked = InventoryBatchDisposalRequest::query()
                ->where('branch_id', $branchId)
                ->whereKey($request->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new AuthorizationException('Permintaan tidak ditemukan di cabang aktif.');
            }

            if ($locked->inventory_movement_id !== null) {
                return $locked->load(['movement', 'batch', 'product', 'location']);
            }

            if (! $locked->isApproved()) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya permintaan yang disetujui dapat difinalisasi.',
                ]);
            }

            $available = $this->movements->currentStockByBatch(
                $branchId,
                (int) $locked->product_id,
                (int) $locked->inventory_location_id,
                (int) $locked->inventory_batch_id,
            );

            $qty = (float) $locked->quantity_requested;

            if ($qty > $available) {
                throw ValidationException::withMessages([
                    'quantity_requested' => 'Stok batch pada lokasi ini tidak lagi mencukupi untuk finalisasi.',
                ]);
            }

            $notes = sprintf(
                'Finalisasi permintaan disposal/adjustment #%d — %s',
                $locked->id,
                $locked->evidence_note,
            );

            $movement = $this->stock->adjustOut(
                (int) $locked->product_id,
                (int) $locked->inventory_location_id,
                $qty,
                $notes,
                (int) $locked->inventory_batch_id,
                $locked->getTable(),
                $locked->id,
                allowExpiredBatch: true,
            );

            return $this->requests->update($locked, [
                'status' => InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED,
                'inventory_movement_id' => $movement->id,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ]);
        });
    }

    public function currentBatchLocationStock(InventoryBatch $batch, int $locationId): float
    {
        $branchId = $this->branchContext->requireId();
        $batch = $this->assertBatchAccessible($branchId, $batch->id);
        $this->assertLocationInBranch($branchId, $locationId);

        return $this->movements->currentStockByBatch(
            $branchId,
            (int) $batch->product_id,
            $locationId,
            $batch->id,
        );
    }

    private function assertBatchAccessible(int $branchId, int $batchId): InventoryBatch
    {
        $batch = $this->batches->findForBranch($branchId, $batchId);
        if ($batch === null) {
            throw new AuthorizationException('Batch tidak ditemukan di cabang aktif.');
        }

        return $batch;
    }

    private function assertRequestAccessible(InventoryBatchDisposalRequest $request): InventoryBatchDisposalRequest
    {
        $branchId = $this->branchContext->requireId();

        if ($request->branch_id !== $branchId) {
            throw new AuthorizationException('Permintaan tidak ditemukan di cabang aktif.');
        }

        return $request;
    }

    private function assertLocationInBranch(int $branchId, int $locationId): void
    {
        $location = $this->locations->findInBranch($branchId, $locationId);
        if ($location === null) {
            throw ValidationException::withMessages([
                'inventory_location_id' => 'Lokasi tidak ditemukan di cabang aktif.',
            ]);
        }
    }

    private function assertActionLogForBatch(int $branchId, int $batchId, int $actionLogId): void
    {
        $exists = DB::table('trx_inventory_batch_action_logs')
            ->where('branch_id', $branchId)
            ->where('inventory_batch_id', $batchId)
            ->where('id', $actionLogId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'inventory_batch_action_log_id' => 'Log tindakan batch tidak valid.',
            ]);
        }
    }
}
