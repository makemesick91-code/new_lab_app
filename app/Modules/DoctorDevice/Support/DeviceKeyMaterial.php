<?php

namespace App\Modules\DoctorDevice\Support;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — public key handling.
 *
 * The ONLY key material this application ever sees is PUBLIC: an X.509
 * SubjectPublicKeyInfo, exactly what Android's `PublicKey.getEncoded()` returns,
 * transported as base64 DER. The private key is generated inside the Android
 * Keystore, is non-exportable, and is never transmitted, stored or logged.
 *
 * Nothing here is hand-rolled cryptography — verification is delegated to
 * OpenSSL. This class only normalises encodings and computes the fingerprint.
 */
final class DeviceKeyMaterial
{
    /** EC P-256 with SHA-256. Supported by Android Keystore and OpenSSL alike. */
    public const ALGORITHM_EC_P256_SHA256 = 'EC_P256_SHA256';

    public const SUPPORTED_ALGORITHMS = [self::ALGORITHM_EC_P256_SHA256];

    /**
     * SHA-256 over the DER bytes, lowercase hex. Stable across encodings because
     * it is computed on the decoded DER, never on whitespace-sensitive base64.
     */
    public static function fingerprint(string $base64Der): string
    {
        return hash('sha256', self::decodeDer($base64Der));
    }

    /**
     * Wrap base64 DER into PEM so OpenSSL can read it. Returns null when the
     * input is not a usable public key — callers must fail closed on null and
     * must never fall back to trusting unverified input.
     */
    public static function toPem(string $base64Der): ?string
    {
        $der = self::decodeDer($base64Der);

        if ($der === '') {
            return null;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";

        $key = @openssl_pkey_get_public($pem);

        if ($key === false) {
            return null;
        }

        $details = @openssl_pkey_get_details($key);

        // Only EC keys are accepted. A device offering an RSA or DSA key is not
        // speaking this protocol and is refused rather than silently verified
        // under different assumptions.
        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            return null;
        }

        return $pem;
    }

    public static function isSupportedAlgorithm(?string $algorithm): bool
    {
        return is_string($algorithm) && in_array($algorithm, self::SUPPORTED_ALGORITHMS, true);
    }

    /**
     * Strict base64 decode. Rejects malformed input instead of silently
     * producing partial bytes that would hash to a stable-but-wrong fingerprint.
     */
    private static function decodeDer(string $base64Der): string
    {
        $clean = preg_replace('/\s+/', '', $base64Der) ?? '';

        if ($clean === '') {
            return '';
        }

        $der = base64_decode($clean, true);

        return $der === false ? '' : $der;
    }
}
