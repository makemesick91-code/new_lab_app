<?php

namespace App\Modules\DoctorDevice\Interfaces;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use Illuminate\Support\Collection;

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — fleet-wide reads, and why they are
 * behind a SECOND interface rather than added to the three that already exist.
 *
 * DoctorDeviceWebAuthnCredentialRepositoryInterface says, in its own words,
 * that it deliberately has no "all credentials" accessor, because "a credential
 * list is a map of the clinic's hardware estate, and the login path has no
 * reason to be able to enumerate it". That reasoning is correct and this
 * sprint does not weaken it.
 *
 * But a readiness engine has to enumerate — measuring whether fifteen doctors
 * can each reach a trusted device is exactly a map of the estate. Widening the
 * existing interface would hand that enumeration to DoctorAppLoginGate, which
 * is constructor-injected with it. So the enumeration lives here instead, in an
 * interface the gate is never given, and the boundary the login path relies on
 * stays exactly where it was.
 *
 * Everything here is a READ. There is no write method and there will not be
 * one: readiness reports on provisioning, it never performs it.
 */
interface DoctorDeviceRolloutReadinessRepositoryInterface
{
    /**
     * Every account that would be subject to fleet-wide doctor enforcement.
     *
     * Keyed on the Doctor ROLE, matching DoctorAppLoginGate::appliesTo() — a
     * permission would be a different population, and enforcement follows the
     * role.
     *
     * @return Collection<int, User>
     */
    public function doctorAccounts(): Collection;

    /**
     * The doctor records behind those accounts, keyed by user id.
     *
     * Soft-deleted rows are excluded by the model's own scope. INACTIVE rows
     * are deliberately still returned: the engine has to be able to say
     * "this account is linked to a doctor record that is switched off", which
     * it cannot do if the row never arrives.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Doctor>
     */
    public function doctorRecordsForUsers(array $userIds): Collection;

    /**
     * Every authorization for the given doctors, with its device and that
     * device's credentials already loaded, grouped by doctor id.
     *
     * Eager-loaded on purpose: a per-doctor lookup would turn a fifteen-doctor
     * fleet into fifty queries and a national one into thousands.
     *
     * @param  list<int>  $doctorIds
     * @return Collection<int, Collection<int, DoctorDeviceAuthorization>>
     */
    public function authorizationsForDoctors(array $doctorIds): Collection;

    /**
     * Every device, in any state, with branch and credentials loaded.
     *
     * Revoked and disabled devices are included because the estate report has
     * to count them. Nothing here decides whether a device may be used; that
     * stays with the gate.
     *
     * @return Collection<int, DoctorDevice>
     */
    public function deviceEstate(): Collection;
}
