<?php

namespace App\Modules\DoctorDevice\Support;

use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — deciding whether a credential is confined to ONE
 * piece of hardware.
 *
 * THE MISTAKE THIS CLASS EXISTS TO PREVENT
 *
 * It is very easy to believe this:
 *
 *     authenticatorAttachment === 'platform'  =>  the key cannot leave this device
 *
 * It is false, and it has been false since synced passkeys shipped. A platform
 * authenticator on a modern Android tablet routinely creates a key that is
 * non-exportable from that tablet's hardware AND simultaneously present in the
 * signed-in Google account — and therefore on the owner's phone, laptop, and
 * any device they later sign into. "Platform" describes where the ceremony
 * happened. It says nothing about how many devices hold the key.
 *
 * For a clinic tablet that distinction is the entire feature. If a doctor's
 * personal phone can silently receive a working credential for the approved
 * clinic device, then "approved physical device" is not a security property,
 * it is a label.
 *
 * WHAT ACTUALLY ANSWERS THE QUESTION
 *
 * Two bits in the authenticator data:
 *
 *   BE (Backup Eligible) — may this credential ever be synced? Fixed at
 *                          creation, immutable for the credential's life.
 *   BS (Backup State)    — is it currently backed up?
 *
 * BE=0 is the only signal that a credential is single-device. It is an
 * authenticator's claim rather than a cryptographic proof — nothing in WebAuthn
 * can prove a negative about key custody — but it is the strongest signal the
 * platform defines, it is what the spec defines *for this question*, and the
 * library already refuses inconsistent combinations (BS=1 with BE=0) before we
 * see them.
 *
 * BS IS NOT USED FOR THE VERDICT, AND THAT IS DELIBERATE
 *
 * BS is mutable: a backup-eligible credential reports BS=0 until the moment it
 * syncs. Judging on BS would therefore PASS a syncable credential that simply
 * had not synced yet, and then silently keep passing it after it had. BE is the
 * durable property, so BE is the gate.
 *
 * SILENCE IS NOT A PASS
 *
 * An authenticator that reports no backup flags has not told us the credential
 * is device-bound. That is `unknown`, and under a policy that requires device
 * binding `unknown` is refused exactly like `backup_eligible`. Treating absent
 * evidence as good evidence is how a requirement quietly becomes optional.
 */
final class WebAuthnDeviceBinding
{
    /**
     * Classify a credential from its backup flags.
     *
     * @param  bool|null  $backupEligible  BE, or null when the authenticator did not report it
     */
    public static function verdict(?bool $backupEligible): string
    {
        if ($backupEligible === null) {
            return DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN;
        }

        return $backupEligible
            ? DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE
            : DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND;
    }

    /** Is the deployment demanding single-device credentials? */
    public static function isRequired(): bool
    {
        return (bool) config('webauthn.device_binding.require_device_bound', true);
    }

    /**
     * May a credential with this verdict be registered and used?
     *
     * When the policy is relaxed every verdict is acceptable — but the verdict
     * is still RECORDED, so a deployment that accepted syncable credentials can
     * always be asked afterwards which of its credentials are syncable.
     */
    public static function isAcceptable(string $verdict): bool
    {
        if (! self::isRequired()) {
            return true;
        }

        return $verdict === DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND;
    }
}
