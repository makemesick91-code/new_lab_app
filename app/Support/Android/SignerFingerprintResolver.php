<?php

namespace App\Support\Android;

/**
 * REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.
 *
 * Reads the signer certificate SHA-256 fingerprint out of a signed APK.
 *
 * A seam rather than an inline shell call, for two reasons. The verifier has to
 * be testable without shipping a signed APK fixture into the repository — and a
 * repository is exactly where signed artifacts must not live. And the real
 * implementation depends on `apksigner`, which is an Android SDK build-tool that
 * is legitimately absent on a CI runner; the behaviour when it IS absent is a
 * security decision (fail closed), not an implementation detail, so it deserves
 * its own test rather than being unreachable.
 */
interface SignerFingerprintResolver
{
    /**
     * Lower-case hex SHA-256 of the signer certificate, or null when it cannot
     * be determined.
     *
     * Null means "unknown", never "no signature". A caller that treats those as
     * the same thing installs an unsigned APK.
     */
    public function fingerprintFor(string $apkPath): ?string;

    /** Whether the underlying tooling is present at all. */
    public function isAvailable(): bool;
}
