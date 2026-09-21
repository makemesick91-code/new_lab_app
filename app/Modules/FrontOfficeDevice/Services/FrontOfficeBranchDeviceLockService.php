<?php

namespace App\Modules\FrontOfficeDevice\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\FrontOfficeDevice\Support\FrontOfficeDeviceLockDecision;
use App\Services\Foundation\FeatureFlagService;
use App\Support\AccessControl\FrontOfficeBranchDeviceCohort;
use Illuminate\Http\Request;

/**
 * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — the ONE place the lock is decided.
 *
 * ACCOUNT + APPROVED DEVICE + DEVICE BRANCH + REQUIRED ACCOUNT BRANCH, and all
 * four have to agree. Every caller — the login controller, the per-request
 * middleware, the branch-context guard — asks this method and acts on what it
 * returns. None of them re-implements a condition, because four-account
 * conditions scattered across controllers and views is exactly the failure this
 * class exists to prevent.
 *
 * WHY THE DEVICE COMES FROM THE SESSION AND NOT FROM THE REQUEST.
 *
 * The bound device id is written server-side, once, by a completed WebAuthn
 * assertion against a credential registered to a registered device. It is never
 * read from a form field, a header, a query string, a cookie chosen by the
 * browser, or anything in localStorage. A client cannot assert which device it
 * is; it can only PROVE it, and the proof is the credential.
 *
 * The device's BRANCH is likewise never supplied by the request: it is
 * `mst_doctor_devices.branch_id`, set when an administrator registered that
 * hardware. So the comparison this class performs is between two server-side
 * facts, and a crafted request cannot move either side of it.
 *
 * WHILE THE FLAG IS OFF THIS RETURNS `notInScope()` BEFORE ITS FIRST QUERY.
 *
 * That is what makes the capability safe to deploy into a production where no
 * Front Office browser holds a credential yet: an empty credential registry
 * cannot lock anybody out, because nothing in the login path asks it anything.
 */
class FrontOfficeBranchDeviceLockService
{
    /**
     * The enforcement switch. A feature flag rather than a bare config value so
     * it carries the governance registry's risk level and recorded rollback.
     */
    public const FLAG = 'front_office.branch_device_lock';

    /**
     * Session keys, deliberately in their OWN namespace rather than reusing
     * `doctor_device.*`.
     *
     * Sharing the doctor keys would have saved a few lines and created a real
     * hazard: `EnsureDoctorDeviceSession` and `DoctorAppLoginGate` read those
     * keys and interpret them through doctor authorizations. A Front Office
     * session carrying them would be asking the doctor gate to reason about an
     * account that has no doctor record — and the honest answer to "which
     * doctor authorized this?" for a front-desk clerk is "none, and that is
     * correct", which is not something the doctor gate is built to say.
     */
    public const SESSION_DEVICE_ID = 'front_office_device.device_id';

    public const SESSION_CREDENTIAL_ID = 'front_office_device.credential_id';

    public const SESSION_BOUND_AT = 'front_office_device.bound_at';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly FrontOfficeBranchDeviceCohort $cohort,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    public function enforcementEnabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }

    /**
     * Is this account armed?
     *
     * Role AND cohort, never either alone. The role check keeps a mistyped id
     * from device-locking a doctor; the cohort check keeps the lock off the four
     * Front Office accounts the owner did not approve.
     */
    public function appliesTo(User $user): bool
    {
        if (! $this->enforcementEnabled()) {
            return false;
        }

        $requiredRole = (string) config('front_office_device_lock.policy.required_role', 'Front Office');

        if (! $user->hasRole($requiredRole)) {
            return false;
        }

        return $this->cohort->covers((int) $user->id);
    }

    /**
     * The branch this armed account is pinned to, or NULL when it is not armed
     * or its mapping is unusable.
     *
     * Used by the branch-context guard to refuse widening. A NULL here means
     * "do not pin", which is safe ONLY because a NULL for a COVERED account has
     * already denied the login in evaluate() — an account that cannot log in
     * cannot switch a branch.
     */
    public function requiredBranchIdFor(User $user): ?int
    {
        if (! $this->appliesTo($user)) {
            return null;
        }

        $code = $this->cohort->requiredBranchCodeFor((int) $user->id);

        if ($code === null) {
            return null;
        }

        $branch = $this->branches->findByCode($code);

        if ($branch === null || ! $branch->is_active || ! $branch->is_rme_enabled) {
            return null;
        }

        return (int) $branch->id;
    }

    /**
     * The whole decision, in the order that denies soonest and queries least.
     */
    public function evaluate(User $user, Request $request): FrontOfficeDeviceLockDecision
    {
        // Two cheap, query-free refusals to be in scope at all. While the flag
        // is off this is the entire method.
        if (! $this->appliesTo($user)) {
            return FrontOfficeDeviceLockDecision::notInScope();
        }

        $code = $this->cohort->requiredBranchCodeFor((int) $user->id);

        // Covered but undecidable — duplicated, off-policy, or an oversized
        // cohort. In scope, so it DENIES rather than falling through.
        if ($code === null) {
            return FrontOfficeDeviceLockDecision::deny(
                FrontOfficeDeviceLockDecision::DENY_ACCOUNT_BRANCH_INVALID,
            );
        }

        $branch = $this->branches->findByCode($code);

        if ($branch === null) {
            return FrontOfficeDeviceLockDecision::deny(
                FrontOfficeDeviceLockDecision::DENY_ACCOUNT_BRANCH_INVALID,
                requiredBranchCode: $code,
            );
        }

        // A branch that has lost is_active, or that is not RME-enabled, cannot
        // host a front desk. MAIN is the one this most often means in practice:
        // it is the fallback a branchless account lands on and it is not
        // RME-enabled, so it can never satisfy the lock.
        if (! $branch->is_active || ! $branch->is_rme_enabled) {
            return FrontOfficeDeviceLockDecision::deny(
                FrontOfficeDeviceLockDecision::DENY_BRANCH_INACTIVE,
                requiredBranchCode: $code,
                requiredBranchId: (int) $branch->id,
                requiredBranchName: $branch->name,
            );
        }

        $deviceId = $request->hasSession()
            ? $request->session()->get(self::SESSION_DEVICE_ID)
            : null;

        // No proof at all. Also the landing place for every credential-level
        // refusal, since a failed assertion never writes a binding.
        if ($deviceId === null || ! is_numeric($deviceId)) {
            return FrontOfficeDeviceLockDecision::deny(
                FrontOfficeDeviceLockDecision::DENY_UNKNOWN_DEVICE,
                requiredBranchCode: $code,
                requiredBranchId: (int) $branch->id,
                requiredBranchName: $branch->name,
            );
        }

        $device = DoctorDevice::query()->find((int) $deviceId);

        // A binding whose device row has since been deleted is not a device.
        if ($device === null) {
            return FrontOfficeDeviceLockDecision::deny(
                FrontOfficeDeviceLockDecision::DENY_UNKNOWN_DEVICE,
                requiredBranchCode: $code,
                requiredBranchId: (int) $branch->id,
                requiredBranchName: $branch->name,
            );
        }

        // Approval is composed from the model's own predicates rather than
        // re-derived here: administratively ACTIVE (so pending_approval,
        // disabled and revoked all fail), identity proved by key rather than
        // typed in by an administrator, and enrolment actually completed.
        $approved = $device->isActive()
            && $device->isCryptographicallyVerified()
            && $device->isEnrollmentVerified();

        if (! $approved) {
            return FrontOfficeDeviceLockDecision::deny(
                FrontOfficeDeviceLockDecision::DENY_DEVICE_NOT_APPROVED,
                requiredBranchCode: $code,
                requiredBranchId: (int) $branch->id,
                deviceId: (int) $device->id,
                deviceBranchId: $device->branch_id !== null ? (int) $device->branch_id : null,
                requiredBranchName: $branch->name,
            );
        }

        // A device with no branch cannot match one. Reported as a mismatch
        // because that is what it is: the comparison has no left-hand side.
        if ($device->branch_id === null || (int) $device->branch_id !== (int) $branch->id) {
            return FrontOfficeDeviceLockDecision::deny(
                FrontOfficeDeviceLockDecision::DENY_DEVICE_BRANCH_MISMATCH,
                requiredBranchCode: $code,
                requiredBranchId: (int) $branch->id,
                deviceId: (int) $device->id,
                deviceBranchId: $device->branch_id !== null ? (int) $device->branch_id : null,
                deviceBranchCode: $device->branch_id !== null
                    ? $this->branches->findById((int) $device->branch_id)?->code
                    : null,
                requiredBranchName: $branch->name,
            );
        }

        return FrontOfficeDeviceLockDecision::allow(
            requiredBranchCode: $code,
            requiredBranchId: (int) $branch->id,
            deviceId: (int) $device->id,
        );
    }

    /**
     * Write the binding. Called ONLY by a completed assertion, after the
     * session has been regenerated, so it lands in the new session.
     */
    public function bind(Request $request, int $deviceId, int $credentialId): void
    {
        $request->session()->put(self::SESSION_DEVICE_ID, $deviceId);
        $request->session()->put(self::SESSION_CREDENTIAL_ID, $credentialId);
        $request->session()->put(self::SESSION_BOUND_AT, now()->toIso8601String());
    }

    public function forgetBinding(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget([
            self::SESSION_DEVICE_ID,
            self::SESSION_CREDENTIAL_ID,
            self::SESSION_BOUND_AT,
        ]);
    }
}
