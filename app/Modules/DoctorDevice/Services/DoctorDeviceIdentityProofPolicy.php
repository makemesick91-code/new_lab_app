<?php

declare(strict_types=1);

namespace App\Modules\DoctorDevice\Services;

use App\Modules\DoctorDevice\Interfaces\DoctorDeviceWebAuthnCredentialRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 1 — the ONE place that
 * answers "has this tablet proved its own identity?".
 *
 * THE DEFECT THIS CLOSES.
 *
 * `identity_state = cryptographically_verified` is written by exactly one
 * thing: the Android keystore challenge-response in
 * DoctorDeviceEnrollmentService. WebAuthn registration never writes it, and
 * never will — a WebAuthn credential is a different proof of the same fact.
 *
 * Five services nevertheless read that flag DIRECTLY as a general
 * trusted-device prerequisite, across eight call sites. While they do, the
 * consequence of retiring the Android path is not that Android logins stop —
 * it is that NO NEW TABLET CAN EVER BE PROVISIONED AGAIN, because the only
 * writer of the flag those services demand has been removed. The estate would
 * freeze at whatever was enrolled on the last day Android worked, and no
 * quantity of new hardware could satisfy
 * `spare_device_available_per_branch`, because a device without the flag is
 * not counted as eligible at all.
 *
 * So the OR below is not a convenience. It is the thing that has to exist
 * before the Android path may be retired, and it must exist in ONE place
 * rather than as eight copies of the same condition drifting apart.
 *
 * WHAT COUNTS AS PROOF.
 *
 *     acceptable = valid Android keystore proof
 *                  OR an acceptable device-bound WebAuthn credential
 *
 * Both branches assert the same underlying claim — this physical hardware
 * demonstrated possession of a private key it cannot hand over. Neither is
 * weaker than the other; they are two protocols for one fact.
 *
 * DEVICE-BOUND IS NOT CONFIGURABLE HERE, ON PURPOSE.
 *
 * `config/webauthn.php` carries `require_device_bound` for the LOGIN
 * ceremony, where an operator may legitimately relax which credentials may
 * complete an assertion. This policy deliberately does NOT read it. Identity
 * proof is a provisioning-time trust decision about hardware, and wiring it to
 * a runtime-relaxable flag would mean that relaxing a login policy silently
 * widens what counts as a trusted device — a fail-OPEN reachable from the
 * environment of the machine an operator is already on. Being stricter than
 * the login policy can never contradict it; being looser could.
 *
 * `unknown` is likewise not a pass, which
 * DoctorDeviceWebAuthnCredential::isDeviceBound() already encodes: an
 * authenticator that declined to report its backup flags has not told us the
 * key is confined to it, and an unstated property is not a measured one. This
 * policy reuses that method rather than re-deriving the verdict.
 *
 * WHAT THIS DOES NOT DECIDE.
 *
 * Only identity. It says nothing about whether the device is active, approved,
 * revoked, branch-bound, or authorized for any particular doctor — every
 * caller still asserts those for itself, and this policy is deliberately
 * useless on its own. It also never writes: Android history stays exactly as
 * recorded, because policy interpretation changes and evidence does not.
 */
final class DoctorDeviceIdentityProofPolicy
{
    /** The Android keystore challenge-response proved possession. */
    public const PROOF_ANDROID_KEYSTORE = 'android_keystore';

    /** A device-bound WebAuthn credential proved possession. */
    public const PROOF_WEBAUTHN_DEVICE_BOUND = 'webauthn_device_bound';

    /** Nothing proved possession. Not an error — an absence. */
    public const PROOF_NONE = 'none';

    public function __construct(
        private readonly DoctorDeviceWebAuthnCredentialRepositoryInterface $credentials,
    ) {}

    /**
     * Has this device proved its identity by either accepted protocol?
     *
     * This is the predicate every eligibility and provisioning consumer should
     * call in place of `$device->isCryptographicallyVerified()`.
     */
    public function acceptable(DoctorDevice $device): bool
    {
        return $this->proof($device) !== self::PROOF_NONE;
    }

    /**
     * WHICH proof this device holds, for audit and operator-facing reporting.
     *
     * Android is checked first so that a tablet carrying both proofs reports
     * the one it was originally enrolled with, which keeps historical
     * reporting stable as the WebAuthn estate grows underneath it.
     */
    public function proof(DoctorDevice $device): string
    {
        if ($device->isCryptographicallyVerified()) {
            return self::PROOF_ANDROID_KEYSTORE;
        }

        if ($this->hasAcceptableWebAuthnProof($device)) {
            return self::PROOF_WEBAUTHN_DEVICE_BOUND;
        }

        return self::PROOF_NONE;
    }

    /**
     * A reason string for the caller that has to refuse, so each consumer does
     * not invent its own wording for the same refusal.
     */
    public function refusalReason(): string
    {
        return 'Perangkat belum membuktikan identitas kunci kriptografinya, '
            .'baik melalui kunci Android maupun kredensial WebAuthn yang terikat perangkat.';
    }

    /**
     * At least one unrevoked, device-bound credential on this hardware.
     *
     * Scoped to the DEVICE, not to a doctor: this asks whether the tablet has
     * proved itself, and a tablet legitimately serves several doctors. Using a
     * doctor-scoped lookup here would make identity depend on who happens to
     * be authorized, which would then make authorization depend on itself.
     */
    private function hasAcceptableWebAuthnProof(DoctorDevice $device): bool
    {
        $deviceId = (int) $device->id;

        if ($deviceId <= 0) {
            return false;
        }

        return $this->credentials
            ->usableForDevice($deviceId)
            ->contains(static fn (DoctorDeviceWebAuthnCredential $credential): bool => $credential->isUsable()
                && $credential->isDeviceBound());
    }
}
