<?php

namespace App\Modules\DoctorDevice\Repositories;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRolloutReadinessRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Support\Collection;

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — the fleet-wide read side.
 *
 * Four queries, whatever the size of the fleet. The engine above this asks
 * whether fifteen doctors can each reach a trusted device, and the naive shape
 * of that question is one lookup per doctor per relation; at five branches that
 * is already slow and at national scale it is a different program.
 */
class DoctorDeviceRolloutReadinessRepository implements DoctorDeviceRolloutReadinessRepositoryInterface
{
    public function doctorAccounts(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'Doctor'))
            ->orderBy('id')
            ->get();
    }

    public function doctorRecordsForUsers(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        /*
         * Deliberately NOT filtered on is_active.
         *
         * DoctorIdentityResolver::resolveForUser() does not filter it either,
         * so an inactive doctor still resolves at login time and the readiness
         * engine has to be able to see that. Filtering here would make an
         * inactive record indistinguishable from a missing one, and those are
         * different findings with different fixes: one is a switch, the other
         * is an unlinked account.
         */
        return Doctor::query()
            ->whereIn('user_id', $userIds)
            ->orderBy('id')
            ->get()
            ->keyBy('user_id');
    }

    public function authorizationsForDoctors(array $doctorIds): Collection
    {
        if ($doctorIds === []) {
            return collect();
        }

        return DoctorDeviceAuthorization::query()
            ->whereIn('doctor_id', $doctorIds)
            ->with(['device.webAuthnCredentials', 'device.branch'])
            ->orderBy('id')
            ->get()
            ->groupBy('doctor_id');
    }

    public function deviceEstate(): Collection
    {
        return DoctorDevice::query()
            ->with(['branch', 'webAuthnCredentials'])
            ->orderBy('id')
            ->get();
    }
}
