<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Interfaces;

use App\Modules\RmeInvoice\Models\RmeBillingDisputeReviewer;
use Illuminate\Support\Collection;

/**
 * Rule 204 (OPS-OQ-1a) — persistence of explicit branch reviewer designations.
 * Decisions (who may designate, who may be designated, what a designation
 * authorizes) live in RmeBillingDisputeReviewerService, never here.
 */
interface RmeBillingDisputeReviewerRepositoryInterface
{
    /** @param array{user_id: int, branch_id: int, granted_by: int, granted_at: mixed, grant_reason: string} $data */
    public function create(array $data): RmeBillingDisputeReviewer;

    public function find(int $id): ?RmeBillingDisputeReviewer;

    public function findForUpdate(int $id): ?RmeBillingDisputeReviewer;

    /**
     * The ACTIVE designation of $userId for $branchId. With $shareLock it is read
     * FOR SHARE, so a concurrent revocation (FOR UPDATE) serializes against the
     * caller's transaction instead of committing underneath it.
     */
    public function activeFor(int $userId, int $branchId, bool $shareLock = false): ?RmeBillingDisputeReviewer;

    /** @return list<int> branches the account is actively designated for */
    public function activeBranchIdsForUser(int $userId): array;

    /** @return list<int> accounts actively designated for the branch */
    public function activeUserIdsForBranch(int $branchId): array;

    public function hasAnyActive(int $userId): bool;

    public function revoke(RmeBillingDisputeReviewer $locked, int $revokedBy, string $reason): RmeBillingDisputeReviewer;

    /** @return Collection<int, RmeBillingDisputeReviewer> active first, then newest */
    public function listing(int $limit = 200): Collection;
}
