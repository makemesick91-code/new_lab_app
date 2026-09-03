<?php

namespace App\Support\Android;

/**
 * REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1.
 *
 * Verifies a signed APK against its release manifest before anyone installs it.
 *
 * With Google Play removed from the chain there is no third party checking that
 * the artifact reaching a clinic tablet is the artifact DaengtisiaMS built and
 * signed. This is what replaces it, and it runs twice: once by the publisher
 * before upload, once by the installer before touching the device.
 *
 * Everything here reads PUBLIC material — the APK bytes and a certificate
 * fingerprint. Verifying a release must never require the ability to produce
 * one, or the verification step becomes something only the signer can perform.
 */
class AndroidReleaseArtifactVerifier
{
    public function __construct(private readonly SignerFingerprintResolver $signer) {}

    /**
     * @return array{status:string, checks:array<int,array<string,mixed>>, failures:array<int,string>}
     */
    public function verify(string $apkPath, string $manifestPath): array
    {
        $checks = [];

        $manifest = $this->readManifest($manifestPath);

        if ($manifest === null) {
            return $this->result([$this->check('manifest_readable', 'FAIL', 'Manifest is missing or is not valid JSON.')]);
        }

        $checks[] = $this->check('manifest_readable', 'PASS', 'Manifest parsed.');

        // Schema first. Comparing an APK against a manifest that is missing the
        // field you are comparing on reports a pass for a check that never ran.
        $required = (array) config('android_release.versioning.release_metadata_required');
        $missing = array_values(array_filter(
            $required,
            function (string $key) use ($manifest): bool {
                $value = $manifest[$key] ?? null;

                return $value === null || ! is_scalar($value) || trim((string) $value) === '';
            },
        ));

        $checks[] = $this->check(
            'manifest_schema',
            $required !== [] && $missing === [] ? 'PASS' : 'FAIL',
            $required === []
                ? 'No required manifest fields declared; the check would pass on nothing.'
                : ($missing === [] ? 'Manifest carries every required field.' : 'Missing manifest fields: '.implode(', ', $missing)),
        );

        if ($missing !== [] || $required === []) {
            return $this->result($checks);
        }

        $checks[] = $this->apkPresenceCheck($apkPath);

        if (! is_file($apkPath)) {
            return $this->result($checks);
        }

        $checks[] = $this->filenameCheck($apkPath, $manifest);
        $checks[] = $this->digestCheck($apkPath, $manifest);
        $checks[] = $this->packageCheck($manifest);
        $checks[] = $this->buildVariantCheck($manifest);
        $checks[] = $this->approvalCheck($manifest);
        $checks[] = $this->channelCheck($manifest);
        $checks[] = $this->versionCodeCheck($manifest);
        $checks = array_merge($checks, $this->signerChecks($apkPath, $manifest));

        return $this->result($checks);
    }

    private function apkPresenceCheck(string $apkPath): array
    {
        return $this->check(
            'apk_present',
            is_file($apkPath) ? 'PASS' : 'FAIL',
            is_file($apkPath) ? 'APK found.' : "APK not found at {$apkPath}.",
        );
    }

    private function filenameCheck(string $apkPath, array $manifest): array
    {
        $expected = (string) $manifest['apk_filename'];
        $actual = basename($apkPath);

        return $this->check(
            'apk_filename',
            $actual === $expected ? 'PASS' : 'FAIL',
            $actual === $expected
                ? "Filename matches the manifest ({$actual})."
                : "Filename is {$actual}; the manifest names {$expected}.",
        );
    }

    private function digestCheck(string $apkPath, array $manifest): array
    {
        $expected = strtolower((string) $manifest['artifact_sha256']);
        $actual = hash_file('sha256', $apkPath);

        return $this->check(
            'apk_sha256',
            is_string($actual) && hash_equals($expected, $actual) ? 'PASS' : 'FAIL',
            is_string($actual) && hash_equals($expected, $actual)
                ? 'APK SHA-256 matches the manifest.'
                : 'APK SHA-256 does NOT match the manifest. This artifact is not the one that was released.',
        );
    }

    private function packageCheck(array $manifest): array
    {
        $expected = (string) config('android_release.distribution.package_id');
        $actual = (string) $manifest['package_name'];

        return $this->check(
            'package_name',
            $actual === $expected ? 'PASS' : 'FAIL',
            $actual === $expected
                ? "Package is {$actual}."
                : "Manifest package is {$actual}; the canonical package is {$expected}.",
        );
    }

    private function buildVariantCheck(array $manifest): array
    {
        $allowed = (array) config('android_release.versioning.approved_build_variants');
        $actual = (string) $manifest['build_variant'];

        return $this->check(
            'build_variant',
            $allowed !== [] && in_array($actual, $allowed, true) ? 'PASS' : 'FAIL',
            $allowed === []
                ? 'No approved build variants declared; the check would pass on nothing.'
                : (in_array($actual, $allowed, true)
                    ? "Build variant is {$actual}."
                    : "Build variant is {$actual}. A debug artifact is signed by a publicly known key and is never a production release."),
        );
    }

    private function approvalCheck(array $manifest): array
    {
        $allowed = (array) config('android_release.versioning.approved_release_states');
        $actual = (string) $manifest['approval_status'];

        return $this->check(
            'approval_status',
            $allowed !== [] && in_array($actual, $allowed, true) ? 'PASS' : 'FAIL',
            $allowed === []
                ? 'No approved release states declared; the check would pass on nothing.'
                : (in_array($actual, $allowed, true)
                    ? "Release is {$actual}."
                    : "Release status is {$actual}; only ".implode('/', $allowed).' may be installed.'),
        );
    }

    private function channelCheck(array $manifest): array
    {
        $expected = (string) config('android_release.versioning.release_channel_value');
        $actual = (string) $manifest['release_channel'];

        return $this->check(
            'release_channel',
            $actual === $expected ? 'PASS' : 'FAIL',
            $actual === $expected
                ? "Channel is {$actual}."
                : "Channel is {$actual}; expected {$expected}.",
        );
    }

    private function versionCodeCheck(array $manifest): array
    {
        $raw = $manifest['version_code'];
        // is_bool first: ctype_digit((string) true) is "1" and would pass.
        $valid = ! is_bool($raw) && (is_int($raw) || (is_string($raw) && ctype_digit($raw))) && (int) $raw >= 1;

        return $this->check(
            'version_code',
            $valid ? 'PASS' : 'FAIL',
            $valid
                ? 'versionCode is a positive integer.'
                : 'versionCode is not a positive integer; it cannot be compared against what is installed.',
        );
    }

    /**
     * The check with no substitute — and the one the first version got wrong.
     *
     * The expected fingerprint comes from PINNED CONFIGURATION, never from the
     * manifest. Security review caught the original reading it out of the
     * manifest that ships beside the APK: an attacker replacing the artifact
     * replaces the manifest with it, setting the digest to their build and the
     * fingerprint to their own key, and every check passed. That verified
     * self-consistency, not authenticity.
     *
     * Android will refuse an update signed by a different key, but by then the
     * artifact is on the tablet and the operator is staring at an
     * INSTALL_FAILED_UPDATE_INCOMPATIBLE whose tempting "fix" is to uninstall —
     * which erases app data, destroys the Keystore identity, and costs the
     * device its enrolment. Catching a wrong signer BEFORE install is the point.
     *
     * @return array<int,array<string,mixed>>
     */
    private function signerChecks(string $apkPath, array $manifest): array
    {
        $checks = [];
        $pinned = config('android_release.signing.production_certificate_sha256');
        $anchored = is_string($pinned) && preg_match('/^[0-9a-f]{64}$/i', $pinned) === 1;

        $checks[] = $this->check(
            'signer_trust_anchor',
            $anchored ? 'PASS' : 'FAIL',
            $anchored
                ? 'A production certificate fingerprint is pinned in configuration.'
                : 'No production certificate fingerprint is pinned, so authenticity cannot be established. '
                    .'The manifest cannot vouch for itself: whoever can replace the APK can replace the manifest with it. '
                    .'Pin android_release.signing.production_certificate_sha256 before any production install.',
        );

        if (! $anchored) {
            // Fail closed. Comparing the APK against the manifest alone would
            // report PASS for an artifact signed by any key at all.
            return $checks;
        }

        $expected = strtolower((string) $pinned);
        $actual = $this->signer->fingerprintFor($apkPath);

        if ($actual === null) {
            // "Could not determine" is never "matches".
            $checks[] = $this->check(
                'signer_fingerprint',
                'FAIL',
                $this->signer->isAvailable()
                    ? 'Could not read a signer certificate from this APK. It may be unsigned or corrupt.'
                    : 'apksigner is not available, so the signer certificate could not be verified. Install Android SDK build-tools; do not install an unverified APK.',
            );

            return $checks;
        }

        $checks[] = $this->check(
            'signer_fingerprint',
            hash_equals($expected, $actual) ? 'PASS' : 'FAIL',
            hash_equals($expected, $actual)
                ? 'Signer certificate matches the pinned DaengtisiaMS release authority.'
                : 'Signer certificate does NOT match the pinned release authority. This APK was signed by a different key — '
                    .'do not install it, and do not uninstall the existing app to force it.',
        );

        // Cross-check, not the authority: a manifest that names a different
        // certificate than the pin describes some other release, which means
        // the paperwork and the artifact disagree even if the APK itself is
        // authentic.
        $declared = strtolower((string) $manifest['signing_certificate_fingerprint_sha256']);

        $checks[] = $this->check(
            'manifest_matches_trust_anchor',
            hash_equals($expected, $declared) ? 'PASS' : 'FAIL',
            hash_equals($expected, $declared)
                ? 'Manifest names the pinned release authority.'
                : 'Manifest names a certificate that is not the pinned release authority.',
        );

        return $checks;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array{status:string, checks:array<int,array<string,mixed>>, failures:array<int,string>}
     */
    private function result(array $checks): array
    {
        $failures = array_values(array_map(
            fn (array $c): string => (string) $c['id'],
            array_filter($checks, fn (array $c): bool => $c['status'] === 'FAIL'),
        ));

        return [
            'status' => $failures === [] ? 'PASS' : 'FAIL',
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /** @return array<string,mixed>|null */
    private function readManifest(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        // Bounded, like the sibling scanner. This file was just fetched from
        // a release source; a multi-gigabyte manifest must not OOM the command
        // an operator runs at the tablet.
        $limit = (int) config('android_release.scanner.max_scanned_file_bytes', 1048576);
        $size = filesize($path);

        if ($size === false || $size > $limit) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function check(string $id, string $status, string $detail): array
    {
        return ['id' => $id, 'status' => $status, 'detail' => $detail];
    }
}
