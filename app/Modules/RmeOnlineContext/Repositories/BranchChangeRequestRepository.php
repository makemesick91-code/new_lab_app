<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Repositories;

use App\Modules\RmeOnlineContext\Interfaces\BranchChangeRequestRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use Illuminate\Support\Collection;

class BranchChangeRequestRepository implements BranchChangeRequestRepositoryInterface
{
    public function findById(int $id): ?BranchChangeRequest
    {
        return BranchChangeRequest::query()->find($id);
    }

    public function lockById(int $id): ?BranchChangeRequest
    {
        return BranchChangeRequest::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    public function findPendingForUser(int $userId, string $clinicalDate): ?BranchChangeRequest
    {
        return BranchChangeRequest::query()
            ->where('requester_user_id', $userId)
            ->whereDate('clinical_date', $clinicalDate)
            ->where('status', BranchChangeRequest::STATUS_PENDING)
            ->first();
    }

    /**
     * The initial status is written EXPLICITLY rather than left to the column
     * default.
     *
     * `status` is not fillable, so a mass-assigned create leaves the returned
     * model with no `status` attribute at all — the row would be `pending` in
     * the database while `isPending()` answered false on the object the caller
     * holds. Stating it here keeps the in-memory model and the row in agreement
     * and puts the starting state where a reader looks for it.
     */
    public function create(array $attributes): BranchChangeRequest
    {
        $request = new BranchChangeRequest;

        $request->forceFill($attributes + [
            'status' => BranchChangeRequest::STATUS_PENDING,
        ])->save();

        return $request;
    }

    /**
     * WHY `forceFill` AND NOT `update`.
     *
     * `status`, `decided_by_user_id`, `decided_at`, `decision_note` and
     * `applied_at` are deliberately absent from the model's `$fillable`: they are
     * the entire security value of the row, and a forged `status=approved` in a
     * request payload must have nowhere to land. `update()` honours that list,
     * so it would silently DISCARD exactly the fields a decision has to write —
     * a decision that appeared to succeed and changed nothing.
     *
     * The guard belongs on the request boundary, not here. Every caller of this
     * method is a service that has already validated, locked and derived the
     * values it passes; nothing reaches it straight from a payload.
     */
    public function update(BranchChangeRequest $request, array $attributes): BranchChangeRequest
    {
        $request->forceFill($attributes)->save();

        return $request->refresh();
    }

    public function pendingForDate(string $clinicalDate): Collection
    {
        return BranchChangeRequest::query()
            ->with(['requester', 'sourceBranch', 'destinationBranch'])
            ->where('status', BranchChangeRequest::STATUS_PENDING)
            ->whereDate('clinical_date', $clinicalDate)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();
    }

    public function recentlyDecided(int $limit = 50): Collection
    {
        return BranchChangeRequest::query()
            ->with(['requester', 'decidedBy', 'sourceBranch', 'destinationBranch'])
            ->whereIn('status', [
                BranchChangeRequest::STATUS_APPROVED,
                BranchChangeRequest::STATUS_REJECTED,
                BranchChangeRequest::STATUS_CANCELLED,
                BranchChangeRequest::STATUS_EXPIRED,
            ])
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function forUserAndDate(int $userId, string $clinicalDate): Collection
    {
        return BranchChangeRequest::query()
            ->with(['sourceBranch', 'destinationBranch', 'decidedBy'])
            ->where('requester_user_id', $userId)
            ->whereDate('clinical_date', $clinicalDate)
            ->orderByDesc('id')
            ->get();
    }

    public function expireStale(string $clinicalToday): int
    {
        return BranchChangeRequest::query()
            ->where('status', BranchChangeRequest::STATUS_PENDING)
            ->whereDate('clinical_date', '<', $clinicalToday)
            ->update([
                'status' => BranchChangeRequest::STATUS_EXPIRED,
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
