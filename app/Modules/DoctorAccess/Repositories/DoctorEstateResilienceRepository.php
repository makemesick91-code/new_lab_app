<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\DoctorAccess\Interfaces\DoctorEstateResilienceRepositoryInterface;
use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only estate reads. See the interface for each method's reasoning — in
 * particular why the treatment-room count decides nothing while the room
 * PROFILE decides Level 2, which are different questions rather than a reversal.
 */
class DoctorEstateResilienceRepository implements DoctorEstateResilienceRepositoryInterface
{
    /**
     * ONE query for every branch.
     *
     * `(branch_id, type)` and `(branch_id, status)` are both indexed on
     * `mst_clinic_rooms`, and the grouping happens in the database — a
     * resilience report has no use for the rooms themselves, only for how many
     * of them a branch runs.
     *
     * The model's own constants name the type and status rather than string
     * literals appearing here, so a renamed room type is a compile-time
     * concern rather than a silently empty count.
     *
     * @return Collection<int, int>
     */
    public function activeTreatmentRoomCountsByBranch(): Collection
    {
        return ClinicRoom::query()
            ->selectRaw('branch_id, count(*) as room_count')
            ->where('type', ClinicRoom::TYPE_TREATMENT_ROOM)
            ->where('status', ClinicRoom::STATUS_ACTIVE)
            ->groupBy('branch_id')
            ->pluck('room_count', 'branch_id')
            /*
             * pluck over a raw aggregate returns driver-dependent scalars —
             * postgres hands back a string, sqlite an int. Both are cast so a
             * consumer comparing against 0 cannot be surprised by '0'.
             */
            ->mapWithKeys(fn ($count, $branchId): array => [(int) $branchId => (int) $count]);
    }

    /**
     * ONE query for both counts.
     *
     * A conditional SUM rather than two round trips, because the two numbers
     * are only meaningful beside each other — the whole point of returning them
     * together is that Level 2 reports when they disagree, and two queries
     * could disagree because the estate moved between them rather than because
     * a room type does.
     *
     * `SUM(CASE WHEN ...)` is the portable form; `FILTER (WHERE ...)` is
     * postgres-only and this suite runs on sqlite. Both drivers return the
     * aggregate as a driver-dependent scalar, so both are cast.
     *
     * @return Collection<int, array{doctor_facing:int, assignable:int}>
     */
    public function activeDoctorRoomProfileByBranch(): Collection
    {
        $doctorFacing = [ClinicRoom::TYPE_TREATMENT_ROOM, ClinicRoom::TYPE_CONSULTATION_ROOM];

        $placeholders = implode(',', array_fill(0, count($doctorFacing), '?'));

        return ClinicRoom::query()
            ->selectRaw(
                'branch_id, count(*) as assignable_count, '
                ."sum(case when type in ({$placeholders}) then 1 else 0 end) as doctor_facing_count",
                $doctorFacing,
            )
            ->where('status', ClinicRoom::STATUS_ACTIVE)
            ->groupBy('branch_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->branch_id => [
                    'doctor_facing' => (int) $row->doctor_facing_count,
                    'assignable' => (int) $row->assignable_count,
                ],
            ]);
    }

    /**
     * The predicate lives on the model, not here.
     *
     * `coversInstant()` already encodes approval plus the half-open window, and
     * it is the same predicate the effective-branch resolver uses. Restating it
     * as a pair of SQL comparisons would be a second copy of an access rule —
     * the thing this module has paid for before. The status narrowing IS done
     * in SQL so the row set stays small.
     *
     * @return array<int, string|null>
     */
    public function activeCoverTargetBranches(CarbonInterface $at): array
    {
        $branches = [];

        DoctorBranchCover::query()
            ->with('targetBranch')
            ->where('status', DoctorBranchCover::STATUS_APPROVED)
            ->get()
            ->filter(fn (DoctorBranchCover $cover): bool => $cover->coversInstant($at))
            ->each(function (DoctorBranchCover $cover) use (&$branches): void {
                $branchId = (int) $cover->target_branch_id;

                if ($branchId <= 0) {
                    return;
                }

                $code = $cover->targetBranch?->code;

                // First non-null code wins; a second cover onto the same branch
                // must not blank a code the first one resolved.
                $branches[$branchId] = $branches[$branchId] ?? ($code === null ? null : (string) $code);
            });

        ksort($branches);

        return $branches;
    }
}
