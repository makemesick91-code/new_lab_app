<?php

namespace App\Modules\DoctorDevice\Services;

use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — why a credential cannot carry
 * a doctor, in ONE place.
 *
 * This logic was private to {@see DoctorGlobalRolloutReadinessService}, which
 * answers per DOCTOR across the whole fleet. The guided registration workflow
 * has to answer the same question per DEVICE, and the obvious shortcut — a
 * second `if ($credential->isRevoked())` chain next to the first — is exactly
 * how two implementations of one security decision start agreeing and end up
 * drifting. The fleet engine already makes that argument about the login gate
 * in its own `gateWouldAdmit()`; it applies here too.
 *
 * So the chain moved here and BOTH engines call it. The reason vocabulary is
 * deliberately NOT redefined: the constants stay on the fleet engine and this
 * class returns them, because two names for one refusal is the same drift in a
 * different disguise.
 *
 * READ-ONLY. It takes a loaded model and returns a string or null. It has no
 * repository, performs no query and writes nothing.
 */
final class DoctorDeviceCredentialUsabilityPolicy
{
    /**
     * Why this credential cannot carry a doctor, or null if it can.
     *
     * `backup_eligible` is NULLABLE on purpose — an authenticator that reported
     * neither flag has told us nothing, and a null is an honest record of that.
     * So the test is `=== false`, never `!== true`: an unstated property is not
     * a measured one, and unknown must not be admitted.
     *
     * The device-bound verdict is then checked SEPARATELY even though it is
     * derived from the same flag. The two agreeing is the normal case; the two
     * disagreeing means a stored verdict has drifted from the flags it was
     * derived from, which is the only route by which a syncable passkey could
     * reach a clinical session. Collapsing them would remove the only place
     * that drift is visible.
     */
    public function refusalReason(DoctorDeviceWebAuthnCredential $credential): ?string
    {
        if ($credential->isRevoked()) {
            return DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_REVOKED;
        }

        if ($credential->user_verified !== true) {
            return DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_USER_VERIFIED;
        }

        if ($credential->backup_eligible !== false) {
            return DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_DEVICE_BOUND;
        }

        if (! $credential->isDeviceBound()) {
            return DoctorGlobalRolloutReadinessService::REASON_CREDENTIAL_NOT_DEVICE_BOUND;
        }

        return null;
    }

    public function usable(DoctorDeviceWebAuthnCredential $credential): bool
    {
        return $this->refusalReason($credential) === null;
    }
}
