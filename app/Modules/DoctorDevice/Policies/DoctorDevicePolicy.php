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
 *
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D11 — a THIRD, weaker
 * authority now exists: `register_doctor_devices`, held by Supervisor RME.
 * It may file a new tablet and read the registry, and nothing else. The
 * abilities split three ways:
 *
 *   register           — file a tablet; it lands PENDING_APPROVAL and can log
 *                        nobody in. `register_doctor_devices` OR management.
 *   create             — bring a device into service DIRECTLY, with no second
 *                        party. Management only. See the warning below.
 *   update / lifecycle — credential enrolment, disable, reactivate, revoke,
 *   / approveRegistration  and approving a filed tablet. Management only.
 */
class DoctorDevicePolicy
{
    public function viewAny(User $user): bool
    {
        // The filer must be able to see the registry, or they cannot tell
        // whether the tablet they are about to file is already there — and
        // duplicate hardware rows are exactly what the name uniqueness check
        // in DoctorDeviceService::register() exists to prevent.
        return $user->canAny([
            'view_doctor_devices',
            'manage_doctor_devices',
            'register_doctor_devices',
        ]);
    }

    public function view(User $user, DoctorDevice $device): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Bring a device into service directly — NOT merely filing one.
     *
     * DO NOT widen this to `register_doctor_devices`. It is deliberately
     * shared with `ApproveDoctorDeviceEnrollmentRequest`, which approves an
     * Android pairing code: that path binds the device's public key and
     * creates an ACTIVE, immediately usable tablet via `approveIntoNewDevice`.
     * Widening `create` to admit the filing authority would hand that
     * key-binding decision to whoever may file a tablet, silently, through a
     * sibling that merely happens to reuse the same ability.
     *
     * Filing is `register`. This is trust.
     */
    public function create(User $user): bool
    {
        return $user->can('manage_doctor_devices');
    }

    /**
     * File a new tablet into the registry as PENDING_APPROVAL.
     *
     * Grants nothing on its own: `DoctorAppLoginGate::deviceProofDenyReason()`
     * opens with `if (! $device->isActive())`, a strict allowlist, so a filed
     * row is refused at the first check for both proof types.
     */
    public function register(User $user): bool
    {
        return $user->canAny(['manage_doctor_devices', 'register_doctor_devices']);
    }

    /** Approve a filed tablet into service — the trust decision itself. */
    public function approveRegistration(User $user, DoctorDevice $device): bool
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
