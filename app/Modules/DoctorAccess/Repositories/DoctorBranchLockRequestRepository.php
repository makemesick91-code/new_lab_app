<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRequestRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchLockRequest;
use Illuminate\Support\Collection;

class DoctorBranchLockRequestRepository implements DoctorBranchLockRequestRepositoryInterface
{
    /**
     * Eager-loaded on every list read so the approver queue can render the
     * subject, both branches and the requester without an N+1.
     *
     * @var array<int, string>
     */
    private const LIST_RELATIONS = [
        'doctor.user',
        'sourceBranch',
        'destinationBranch',
        'requester',
        'decidedBy',
    ];

    public function findById(int $id): ?DoctorBranchLockRequest
    {
        return DoctorBranchLockRequest::query()->find($id);
    }

    public function lockById(int $id): ?DoctorBranchLockRequest
    {
        return DoctorBranchLockRequest::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }

    public function findPendingForDoctor(int $doctorId): ?DoctorBranchLockRequest
    {
        return DoctorBranchLockRequest::query()
            ->where('doctor_id', $doctorId)
            ->where('status', DoctorBranchLockRequest::STATUS_PENDING)
            ->first();
    }

    /**
     * The initial status is written EXPLICITLY.
     *
     * `status` is not fillable, so a mass-assigned create returns a model
     * carrying no status attribute at all: `isPending()` would answer false on
     * the object the caller is holding while the row is pending in the
     * database. The reasoning is spelled out at
     * app/Modules/RmeOnlineContext/Repositories/BranchChangeRequestRepository.php:35-44.
     */
    public function create(array $attributes): DoctorBranchLockRequest
    {
        $request = new DoctorBranchLockRequest;

        $request->forceFill($attributes + [
            'status' => DoctorBranchLockRequest::STATUS_PENDING,
        ])->save();

        return $request;
    }

    public function update(DoctorBranchLockRequest $request, array $attributes): DoctorBranchLockRequest
    {
        $request->forceFill($attributes)->save();

        return $request->refresh();
    }

    public function pending(): Collection
    {
        return DoctorBranchLockRequest::query()
            ->with(self::LIST_RELATIONS)
            ->where('status', DoctorBranchLockRequest::STATUS_PENDING)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();
    }

    public function recentlyDecided(int $limit = 50): Collection
    {
        return DoctorBranchLockRequest::query()
            ->with(self::LIST_RELATIONS)
            ->whereNotNull('decided_at')
            ->orderByDesc('decided_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function forDoctor(int $doctorId, int $limit = 20): Collection
    {
        return DoctorBranchLockRequest::query()
            ->with(self::LIST_RELATIONS)
            ->where('doctor_id', $doctorId)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
