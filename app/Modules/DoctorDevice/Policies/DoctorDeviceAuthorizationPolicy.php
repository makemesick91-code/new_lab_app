<?php

namespace App\Modules\DoctorDevice\Policies;

use App\Models\User;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1.
 *
 * Who may look at the approval inbox, and who may decide.
 *
 * Authority is expressed as PERMISSIONS, never as a role-name comparison. The
 * product asks for Super Admin and Supervisor RME, and both arrive here the
 * ordinary way: Super Admin through the single global `Gate::before` bypass,
 * Supervisor RME because RoleSeeder grants it these two permissions and
 * nothing else. Writing `if ($user->hasRole('Supervisor RME'))` would have
 * worked today and quietly become wrong the first time the estate needed a
 * third approver.
 *
 * SUPERVISOR RME GETS EXACTLY THIS CAPABILITY. It is not handed a general
 * bypass, and it gains no device-registry, enforcement or production authority.
 *
 * Deliberately absent: `delete`. An authorization is security history. Trust is
 * withdrawn with REVOKE, never by deletion.
 */
class DoctorDeviceAuthorizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            'view_doctor_device_authorizations',
            'manage_doctor_device_authorizations',
        ]);
    }

    public function view(User $user, DoctorDeviceAuthorization $authorization): bool
    {
        return $this->viewAny($user);
    }

    /** Approve / reject / revoke / allow-re-request all share one authority. */
    public function decide(User $user, DoctorDeviceAuthorization $authorization): bool
    {
        return $user->can('manage_doctor_device_authorizations');
    }
}
