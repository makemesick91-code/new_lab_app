<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Repositories;

use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\DoctorAccess\Interfaces\DoctorEstateResilienceRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1 — see the interface for why
 * this count is reported and deliberately not used to decide a verdict.
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
}
