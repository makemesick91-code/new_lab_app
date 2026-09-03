<?php

namespace App\Modules\DoctorDevice\Support;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — the canonical signed
 * message.
 *
 * Both the Android client and the server must build byte-identical input, so
 * the format is a fixed, pipe-delimited, versioned string rather than JSON:
 * JSON key ordering and whitespace are not guaranteed across platforms, and a
 * signature over a non-deterministic encoding is a verification bug waiting to
 * happen.
 *
 *     daengtisiams-device-proof|v1|<purpose>|<nonce>|<fingerprint>
 *
 * Binding BOTH the nonce and the device fingerprint means a signature captured
 * from device A cannot be presented for device B even if an attacker could
 * somehow obtain B's challenge — the message itself would differ.
 *
 * The `purpose` is included so an enrolment proof can never be replayed as an
 * ongoing device proof, or vice versa.
 *
 * Keep this file and `DeviceIdentityRepository.kt` in the Android app in step.
 * Changing the format is a protocol version bump, not an edit.
 */
final class DeviceProofMessage
{
    public const PREFIX = 'daengtisiams-device-proof';

    public const VERSION = 'v1';

    public const PURPOSE_ENROLLMENT = 'enrollment';

    public const PURPOSE_DEVICE_PROOF = 'device_proof';

    /**
     * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — a doctor login
     * attempt from the Clinic App.
     *
     * A DISTINCT purpose on purpose. Because the purpose is part of the signed
     * bytes, a signature captured from an ongoing device proof cannot be
     * replayed as a login attempt, and vice versa. Reusing `device_proof` here
     * would have made those two interchangeable.
     */
    public const PURPOSE_DOCTOR_LOGIN = 'doctor_login';

    public static function build(string $purpose, string $nonce, string $fingerprint): string
    {
        return implode('|', [
            self::PREFIX,
            self::VERSION,
            $purpose,
            $nonce,
            $fingerprint,
        ]);
    }
}
