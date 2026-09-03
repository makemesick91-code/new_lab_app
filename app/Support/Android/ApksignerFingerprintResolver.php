<?php

namespace App\Support\Android;

use Illuminate\Support\Facades\Process;

/**
 * REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.
 *
 * `apksigner verify --print-certs` is the canonical way to read an APK's signer
 * certificate. It reads a public artifact and needs no private key — verifying
 * a release must never require the ability to produce one.
 */
class ApksignerFingerprintResolver implements SignerFingerprintResolver
{
    public function __construct(private readonly string $binary = 'apksigner') {}

    public function isAvailable(): bool
    {
        // Array form, so nothing here is shell-interpreted.
        return Process::run(['which', $this->binary])->successful();
    }

    public function fingerprintFor(string $apkPath): ?string
    {
        if (! is_file($apkPath) || ! $this->isAvailable()) {
            return null;
        }

        // `--` so a path beginning with a dash cannot be read as an option.
        // apksigner is a wrapper script that forwards -J… to the JVM.
        $result = Process::run([$this->binary, 'verify', '--print-certs', '--', $apkPath]);

        if (! $result->successful()) {
            // An APK that fails verification has no trustworthy fingerprint to
            // report. Returning null keeps the caller failing closed rather
            // than comparing against something meaningless.
            return null;
        }

        // Anchored to a signer line, and multi-signer aware. An unanchored
        // first-match would silently return signer #1 and ignore the rest, or
        // pick up a digest printed earlier in the output. Under v3 key rotation
        // a lineage prints several certificates, and choosing one arbitrarily
        // is not a verification result.
        if (preg_match_all('/^Signer #\d+ certificate SHA-256 digest:\s*([0-9a-f]{64})\s*$/mi', $result->output(), $m) < 1) {
            return null;
        }

        $digests = array_unique(array_map('strtolower', $m[1]));

        if (count($digests) !== 1) {
            // More than one distinct signer. Ambiguous is not verified.
            return null;
        }

        return (string) reset($digests);
    }
}
