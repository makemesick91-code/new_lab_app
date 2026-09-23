<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use App\Modules\DoctorAccess\Models\DoctorBranchLockRequest;
use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the boundary for the two
 * approval workflows that may move a home lock: INITIAL ASSIGNMENT and
 * TRANSFER.
 *
 * There is NO `expireStale()`. A lock request has no day boundary to expire
 * against — it stays pending until somebody decides or the requester cancels.
 */
interface DoctorBranchLockRequestRepositoryInterface
{
    public function findById(int $id): ?DoctorBranchLockRequest;

    /**
     * The same row read `FOR UPDATE`. MUST be inside a transaction.
     *
     * Copied in shape from BranchChangeRequestRepository::lockById (:18-24):
     * every decision that may stamp a request takes this first, so two
     * approvers cannot both see it PENDING.
     */
    public function lockById(int $id): ?DoctorBranchLockRequest;

    /**
     * The doctor's one open request, if any.
     *
     * At most one can exist: the partial unique index
     * `trx_doctor_branch_lock_req_pending_uq` permits a single row per doctor
     * WHERE status = 'pending'. This read is the friendly pre-check; the index
     * is the guarantee, and the caller must still handle a unique violation
     * from a lost race.
     */
    public function findPendingForDoctor(int $doctorId): ?DoctorBranchLockRequest;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): DoctorBranchLockRequest;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(DoctorBranchLockRequest $request, array $attributes): DoctorBranchLockRequest;

    /**
     * The approver queue, oldest first, eager-loaded so reading presence for
     * the whole queue costs no N+1.
     *
     * @return Collection<int, DoctorBranchLockRequest>
     */
    public function pending(): Collection;

    /**
     * @return Collection<int, DoctorBranchLockRequest>
     */
    public function recentlyDecided(int $limit = 50): Collection;

    /**
     * One doctor's own history, newest first.
     *
     * @return Collection<int, DoctorBranchLockRequest>
     */
    public function forDoctor(int $doctorId, int $limit = 20): Collection;
}
