<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\Interfaces\InventoryLocationRepositoryInterface;
use App\Modules\Inventory\Interfaces\ProductRepositoryInterface;
use App\Modules\Inventory\Interfaces\PurchaseRequestRepositoryInterface;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseRequestService
{
    public function __construct(
        private readonly PurchaseRequestRepositoryInterface $purchaseRequests,
        private readonly ProductRepositoryInterface $products,
        private readonly InventoryLocationRepositoryInterface $locations,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator
     */
    public function listForBranch(int $branchId, array $filters = [])
    {
        return $this->purchaseRequests->paginate($branchId, $filters);
    }

    /**
     * @param  array{request_date: string, notes?: string|null, items: array<int, array{product_id: int, inventory_location_id?: int|null, quantity_requested: float, estimated_unit_price?: float|null, notes?: string|null}>}  $data
     */
    public function createDraft(array $data, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $user) {
            $branchId = $this->branchContext->requireId();
            $normalizedItems = $this->normalizeAndValidateItems($branchId, $data['items']);

            $purchaseRequest = $this->purchaseRequests->create([
                'branch_id' => $branchId,
                'purchase_request_number' => $this->generatePurchaseRequestNumber($branchId, $data['request_date']),
                'request_date' => $data['request_date'],
                'status' => PurchaseRequest::STATUS_DRAFT,
                'requested_by' => $user->id,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->purchaseRequests->replaceItems($purchaseRequest, $normalizedItems);

            return $this->purchaseRequests->loadDetails($purchaseRequest->refresh());
        });
    }

    /**
     * @param  array{request_date: string, notes?: string|null, items: array<int, array{product_id: int, inventory_location_id?: int|null, quantity_requested: float, estimated_unit_price?: float|null, notes?: string|null}>}  $data
     */
    public function updateDraft(PurchaseRequest $purchaseRequest, array $data, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $data) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseRequestInBranch($branchId, $purchaseRequest->id);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pembelian hanya bisa diubah saat masih Draft.',
                ]);
            }

            $normalizedItems = $this->normalizeAndValidateItems($branchId, $data['items']);

            $updated = $this->purchaseRequests->update($locked, [
                'request_date' => $data['request_date'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->purchaseRequests->replaceItems($updated, $normalizedItems);

            return $this->purchaseRequests->loadDetails($updated->refresh());
        });
    }

    public function submit(PurchaseRequest $purchaseRequest, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseRequestInBranch($branchId, $purchaseRequest->id);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pembelian hanya bisa diajukan dari status Draft.',
                ]);
            }

            $locked->loadCount('items');

            if ($locked->items_count < 1) {
                throw ValidationException::withMessages([
                    'items' => 'Permintaan pembelian harus memiliki minimal satu item sebelum diajukan.',
                ]);
            }

            return $this->purchaseRequests->update($locked, [
                'status' => PurchaseRequest::STATUS_SUBMITTED,
                'requested_by' => $user->id,
            ]);
        });
    }

    public function approve(PurchaseRequest $purchaseRequest, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $user) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseRequestInBranch($branchId, $purchaseRequest->id);

            if (! $locked->isSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pembelian hanya bisa disetujui dari status Diajukan.',
                ]);
            }

            return $this->purchaseRequests->update($locked, [
                'status' => PurchaseRequest::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
        });
    }

    public function reject(PurchaseRequest $purchaseRequest, User $user, string $reason): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest, $user, $reason) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseRequestInBranch($branchId, $purchaseRequest->id);

            if (! $locked->isSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pembelian hanya bisa ditolak dari status Diajukan.',
                ]);
            }

            return $this->purchaseRequests->update($locked, [
                'status' => PurchaseRequest::STATUS_REJECTED,
                'rejected_by' => $user->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);
        });
    }

    public function cancel(PurchaseRequest $purchaseRequest, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($purchaseRequest) {
            $branchId = $this->branchContext->requireId();
            $locked = $this->lockPurchaseRequestInBranch($branchId, $purchaseRequest->id);

            if (! $locked->isDraft() && ! $locked->isSubmitted()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pembelian hanya bisa dibatalkan dari status Draft atau Diajukan.',
                ]);
            }

            return $this->purchaseRequests->update($locked, [
                'status' => PurchaseRequest::STATUS_CANCELLED,
            ]);
        });
    }

    /**
     * @param  array<int, array{product_id: int, inventory_location_id?: int|null, quantity_requested: float, estimated_unit_price?: float|null, notes?: string|null}>  $items
     * @return array<int, array{product_id: int, inventory_location_id: int|null, quantity_requested: float, estimated_unit_price: float|null, notes: string|null}>
     */
    private function normalizeAndValidateItems(int $branchId, array $items): array
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Minimal satu item diperlukan.',
            ]);
        }

        $normalized = [];

        foreach ($items as $index => $item) {
            $quantity = (float) ($item['quantity_requested'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity_requested" => 'Jumlah yang diminta harus lebih dari nol.',
                ]);
            }

            $product = $this->assertProductInBranch($branchId, (int) $item['product_id'], $index);
            $locationId = isset($item['inventory_location_id']) && $item['inventory_location_id'] !== ''
                ? (int) $item['inventory_location_id']
                : null;

            if ($locationId !== null) {
                $this->assertLocationInBranch($branchId, $locationId, $index);
            }

            $normalized[] = [
                'product_id' => $product->id,
                'inventory_location_id' => $locationId,
                'quantity_requested' => $quantity,
                'estimated_unit_price' => isset($item['estimated_unit_price']) && $item['estimated_unit_price'] !== ''
                    ? (float) $item['estimated_unit_price']
                    : null,
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $normalized;
    }

    private function assertProductInBranch(int $branchId, int $productId, int $index): Product
    {
        $product = $this->products->findInBranch($branchId, $productId);

        if ($product === null) {
            throw ValidationException::withMessages([
                "items.{$index}.product_id" => 'Produk tidak ditemukan di cabang aktif.',
            ]);
        }

        return $product;
    }

    private function assertLocationInBranch(int $branchId, int $locationId, int $index): InventoryLocation
    {
        $location = $this->locations->findInBranch($branchId, $locationId);

        if ($location === null) {
            throw ValidationException::withMessages([
                "items.{$index}.inventory_location_id" => 'Lokasi persediaan tidak ditemukan di cabang aktif.',
            ]);
        }

        return $location;
    }

    private function lockPurchaseRequestInBranch(int $branchId, int $id): PurchaseRequest
    {
        $purchaseRequest = PurchaseRequest::query()
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->find($id);

        if ($purchaseRequest === null) {
            throw ValidationException::withMessages([
                'purchase_request' => 'Permintaan pembelian tidak ditemukan di cabang aktif.',
            ]);
        }

        return $purchaseRequest;
    }

    private function generatePurchaseRequestNumber(int $branchId, string $requestDate): string
    {
        $datePart = str_replace('-', '', $requestDate);
        $prefix = sprintf('PR-%s-%d-', $datePart, $branchId);

        $latest = $this->purchaseRequests->latestNumberForDateAndBranch($prefix, $branchId);
        $sequence = $latest ? ((int) substr($latest, -6)) + 1 : 1;

        do {
            $candidate = sprintf('%s%06d', $prefix, $sequence);
            $sequence++;
        } while ($this->purchaseRequests->existsNumber($candidate));

        return $candidate;
    }
}
