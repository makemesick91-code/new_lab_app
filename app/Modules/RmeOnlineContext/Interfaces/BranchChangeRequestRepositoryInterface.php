<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Interfaces;

use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use Illuminate\Support\Collection;

interface BranchChangeRequestRepositoryInterface
{
    public function findById(int $id): ?BranchChangeRequest;

    /**
     * The request read `FOR UPDATE`, so two approvers racing on the same row
     * serialise. Must be called inside a transaction.
     */
    public function lockById(int $id): ?BranchChangeRequest;

    public function findPendingForUser(int $userId, string $clinicalDate): ?BranchChangeRequest;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): BranchChangeRequest;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(BranchChangeRequest $request, array $attributes): BranchChangeRequest;

    /**
     * Pending requests for the given clinical date, oldest first — the Super
     * Admin queue. Scoped by date so a stale previous-day row can never be
     * approved from the list.
     *
     * @return Collection<int, BranchChangeRequest>
     */
    public function pendingForDate(string $clinicalDate): Collection;

    /**
     * Decided requests, newest first — the audit trail surface.
     *
     * @return Collection<int, BranchChangeRequest>
     */
    public function recentlyDecided(int $limit = 50): Collection;

    /**
     * The requester's own history for a clinical date, newest first.
     *
     * @return Collection<int, BranchChangeRequest>
     */
    public function forUserAndDate(int $userId, string $clinicalDate): Collection;

    /**
     * Stamp every PENDING request whose clinical day has passed as EXPIRED.
     *
     * A convenience for the queue surface only. Nothing depends on it for
     * correctness: {@see BranchChangeRequest::isStaleForClinicalDay()} refuses a
     * stale row whether or not this has run.
     */
    public function expireStale(string $clinicalToday): int;
}
