<?php

namespace App\Modules\DoctorDevice\Policies;

use App\Models\User;
use App\Modules\DoctorDevice\Models\DoctorDevice;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 2.
 *
 * Device management is a security-administration surface, not clinical work.
 * It is gated by its own dedicated permissions which RoleSeeder grants to NO
 * role — so in practice only Super Admin reaches it, through the single global
 * `Gate::before` bypass. That mirrors the ENT-7 `view_developer_console`
 * precedent and keeps Doctor / Kasir / Admin Klinik / Perawat / Owner out
 * without inventing a new authorization mechanism.
 *
 * Deliberately absent: `delete`. A device that has ever been trusted keeps its
 * security history; withdrawal of trust is REVOKED, never deletion.
 */
class DoctorDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny(['view_doctor_devices', 'manage_doctor_devices']);
    }

    public function view(User $user, DoctorDevice $device): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('manage_doctor_devices');
    }

    public function update(User $user, DoctorDevice $device): bool
    {
        return $user->can('manage_doctor_devices');
    }

    /** Disable / reactivate / revoke all share the management authority. */
    public function manageLifecycle(User $user, DoctorDevice $device): bool
    {
        return $user->can('manage_doctor_devices');
    }
}
