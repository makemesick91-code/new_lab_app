<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Repositories;

use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeReviewerRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeBillingDisputeReviewer;
use Illuminate\Support\Collection;

class RmeBillingDisputeReviewerRepository implements RmeBillingDisputeReviewerRepositoryInterface
{
    public function create(array $data): RmeBillingDisputeReviewer
    {
        return RmeBillingDisputeReviewer::query()->create($data);
    }

    public function find(int $id): ?RmeBillingDisputeReviewer
    {
        return RmeBillingDisputeReviewer::query()->find($id);
    }

    public function findForUpdate(int $id): ?RmeBillingDisputeReviewer
    {
        return RmeBillingDisputeReviewer::query()->lockForUpdate()->find($id);
    }

    public function activeFor(int $userId, int $branchId, bool $shareLock = false): ?RmeBillingDisputeReviewer
    {
        $query = RmeBillingDisputeReviewer::query()
            ->where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->whereNull('revoked_at');

        if ($shareLock) {
            $query->sharedLock();
        }

        return $query->first();
    }

    public function activeBranchIdsForUser(int $userId): array
    {
        return RmeBillingDisputeReviewer::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function activeUserIdsForBranch(int $branchId): array
    {
        return RmeBillingDisputeReviewer::query()
            ->where('branch_id', $branchId)
            ->whereNull('revoked_at')
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function hasAnyActive(int $userId): bool
    {
        return RmeBillingDisputeReviewer::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function revoke(RmeBillingDisputeReviewer $locked, int $revokedBy, string $reason): RmeBillingDisputeReviewer
    {
        // Revocation columns are deliberately not mass-assignable.
        $locked->forceFill([
            'revoked_by' => $revokedBy,
            'revoked_at' => now(),
            'revoke_reason' => $reason,
        ])->save();

        return $locked;
    }

    public function listing(int $limit = 200): Collection
    {
        return RmeBillingDisputeReviewer::query()
            ->with(['user:id,name,email,is_active', 'branch:id,code,name', 'granter:id,name', 'revoker:id,name'])
            ->orderByRaw('CASE WHEN revoked_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
