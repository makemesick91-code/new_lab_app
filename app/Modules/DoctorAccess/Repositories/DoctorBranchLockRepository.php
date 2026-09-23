<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use Illuminate\Support\Collection;

class DoctorBranchLockRepository implements DoctorBranchLockRepositoryInterface
{
    public function findForDoctor(int $doctorId): ?DoctorBranchLock
    {
        return DoctorBranchLock::query()
            ->where('doctor_id', $doctorId)
            ->first();
    }

    public function lockedBranchIdFor(int $doctorId): ?int
    {
        $branchId = DoctorBranchLock::query()
            ->where('doctor_id', $doctorId)
            ->value('home_branch_id');

        return $branchId === null ? null : (int) $branchId;
    }

    public function lockForDoctor(int $doctorId): ?DoctorBranchLock
    {
        return DoctorBranchLock::query()
            ->where('doctor_id', $doctorId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The write is a forceFill and never `update()`.
     *
     * `$fillable` on the model is empty by design, and `update()` honours
     * `$fillable` — it would silently discard the entire decision, which is the
     * exact failure documented at
     * app/Modules/RmeOnlineContext/Repositories/BranchChangeRequestRepository.php:56-69.
     */
    public function assign(
        int $doctorId,
        int $homeBranchId,
        ?int $establishedByUserId,
        string $establishedVia,
    ): DoctorBranchLock {
        $lock = $this->lockForDoctor($doctorId);

        if ($lock === null) {
            $fresh = new DoctorBranchLock;
            $fresh->forceFill([
                'doctor_id' => $doctorId,
                'home_branch_id' => $homeBranchId,
                'previous_branch_id' => null,
                'established_via' => $establishedVia,
                'established_at' => now(),
                'established_by_user_id' => $establishedByUserId,
                'last_transferred_at' => null,
                'transfer_count' => 0,
            ])->save();

            return $fresh->refresh();
        }

        $previousBranchId = (int) $lock->home_branch_id;

        // Re-approving the branch the doctor already holds is not a transfer:
        // it moves nobody, so it must not inflate transfer_count or overwrite
        // previous_branch_id with the current branch.
        $isMove = $previousBranchId !== $homeBranchId;

        $lock->forceFill([
            'home_branch_id' => $homeBranchId,
            'previous_branch_id' => $isMove ? $previousBranchId : $lock->previous_branch_id,
            'established_via' => $establishedVia,
            'established_by_user_id' => $establishedByUserId,
            'last_transferred_at' => $isMove ? now() : $lock->last_transferred_at,
            'transfer_count' => (int) $lock->transfer_count + ($isMove ? 1 : 0),
        ])->save();

        return $lock->refresh();
    }

    /**
     * Ordered by doctor name in PHP rather than by a join, because the row
     * count is one per locked doctor and a join would drag the ordering rule
     * into SQL for no measurable gain.
     */
    public function withDoctorAndBranch(): Collection
    {
        return DoctorBranchLock::query()
            ->with(['doctor', 'homeBranch', 'previousBranch', 'establishedBy'])
            ->get()
            ->sortBy(fn (DoctorBranchLock $lock) => (string) $lock->doctor?->name)
            ->values();
    }
}
