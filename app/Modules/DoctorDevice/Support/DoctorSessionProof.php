<?php

namespace App\Modules\DoctorDevice\Support;

/**
 * DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — WHICH proof authenticated this session.
 *
 * THE DISTINCTION THIS TYPE EXISTS TO MAKE
 *
 * "Does this device hold some acceptable proof?" is a question about hardware.
 * "Is the exact proof that established THIS session still valid?" is a question
 * about a ceremony that happened at a moment in time. They are not the same
 * question, and answering the second with the first is how a session outlives
 * the trust it was built on.
 *
 * The pilot tablet holds BOTH an Android Keystore key and a WebAuthn
 * credential. Before this type existed, a browser session established by
 * WebAuthn was re-checked against "the device has some proof" — so the Android
 * key kept it alive after the WebAuthn flag was switched off and after the
 * credential was revoked. Neither of those is a rollback anybody would call a
 * rollback if they saw it happen.
 *
 * So the proof is recorded when it is USED, and re-verified as itself on every
 * later request. It is never inferred afterwards from what the device happens
 * to carry — that inference is precisely the defect.
 *
 * WHY THE CREDENTIAL REFERENCE IS THE PRIMARY KEY AND NOT THE CREDENTIAL ID
 *
 * `credential_id` is the base64url public handle the browser sends. The row id
 * is a stable internal reference that names one credential and nothing else, is
 * already what the audit trail records, and puts no WebAuthn key material into
 * a session. Nothing about the credential's secret, signature, challenge or
 * client data belongs here; a reference is all that a re-check needs.
 */
final class DoctorSessionProof
{
    /** The Clinic App's hardware keystore key, verified over a server nonce. */
    public const TYPE_ANDROID_KEYSTORE = 'android_keystore';

    /** A WebAuthn credential registered against an approved device. */
    public const TYPE_WEBAUTHN = 'webauthn';

    /**
     * Every proof this deployment can validate.
     *
     * A value outside this list is not a proof to be interpreted generously —
     * it is a binding this build cannot check, which is a denial.
     */
    public const TYPES = [
        self::TYPE_ANDROID_KEYSTORE,
        self::TYPE_WEBAUTHN,
    ];

    private function __construct(
        private readonly string $type,
        private readonly ?int $webAuthnCredentialId,
    ) {}

    public static function androidKeystore(): self
    {
        return new self(self::TYPE_ANDROID_KEYSTORE, null);
    }

    public static function webAuthn(int $credentialId): self
    {
        return new self(self::TYPE_WEBAUTHN, $credentialId);
    }

    /**
     * Rebuild a proof from what a session actually holds, or refuse to.
     *
     * Returns null — never a default, never a guess — when the values are
     * absent, the wrong shape, or name a proof type this build does not know.
     * A session bound before this sprint carries no type at all and lands here,
     * and the honest answer for it is "this build cannot verify how you got in".
     *
     * A WebAuthn proof without a credential reference is also null: there would
     * be nothing to re-check, and a proof that cannot be re-checked is exactly
     * the thing this class was written to stop.
     */
    public static function fromSessionValues(mixed $type, mixed $webAuthnCredentialId): ?self
    {
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            return null;
        }

        if ($type === self::TYPE_ANDROID_KEYSTORE) {
            return self::androidKeystore();
        }

        if (! is_int($webAuthnCredentialId) || $webAuthnCredentialId <= 0) {
            return null;
        }

        return self::webAuthn($webAuthnCredentialId);
    }

    public function type(): string
    {
        return $this->type;
    }

    /** The credential this session was authenticated by, or null for Android. */
    public function webAuthnCredentialId(): ?int
    {
        return $this->webAuthnCredentialId;
    }

    public function isWebAuthn(): bool
    {
        return $this->type === self::TYPE_WEBAUTHN;
    }

    public function isAndroidKeystore(): bool
    {
        return $this->type === self::TYPE_ANDROID_KEYSTORE;
    }
}
