<?php

use App\Support\Android\AndroidReleaseArtifactVerifier;
use App\Support\Android\SignerFingerprintResolver;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1 — the direct-APK
 * release contract.
 *
 * With Google Play removed from the chain, nothing between the build and the
 * clinic tablet checks that the artifact is the one DaengtisiaMS signed. This
 * verifier is what replaces it, so these tests are the security boundary rather
 * than a formatting check.
 *
 * The signer fingerprint is read through an injected resolver. That keeps a
 * signed APK out of the repository — which is exactly where signed artifacts
 * must not live — and makes the "tooling absent" path testable, since that path
 * is a security decision (fail closed) rather than an implementation detail.
 */
function fakeSigner(?string $fingerprint, bool $available = true): SignerFingerprintResolver
{
    return new class($fingerprint, $available) implements SignerFingerprintResolver
    {
        public function __construct(private ?string $fp, private bool $available) {}

        public function fingerprintFor(string $apkPath): ?string
        {
            return $this->fp;
        }

        public function isAvailable(): bool
        {
            return $this->available;
        }
    };
}

/** A throwaway "APK" plus its manifest. Returns [apkPath, manifestPath, fingerprint]. */
function stageRelease(array $overrides = [], string $body = 'clinic-apk-bytes'): array
{
    $dir = sys_get_temp_dir().'/revision-apk-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    $apkName = $overrides['apk_filename'] ?? 'DaengtisiaMS-Clinic-v1.0.0.apk';
    $apkPath = $dir.'/'.$apkName;
    file_put_contents($apkPath, $body);

    $fingerprint = str_repeat('a1', 32);

    $manifest = array_merge([
        'package_name' => 'com.daengtisia.clinic',
        'version_name' => '1.0.0',
        'version_code' => 2,
        'git_commit' => str_repeat('c', 40),
        'ci_run_id' => '12345',
        'build_variant' => 'release',
        'apk_filename' => $apkName,
        'artifact_sha256' => hash_file('sha256', $apkPath),
        'signing_certificate_fingerprint_sha256' => $fingerprint,
        'release_channel' => 'direct_admin_managed_apk',
        'approval_status' => 'approved',
    ], $overrides);

    $manifestPath = $dir.'/release.json';
    file_put_contents($manifestPath, json_encode($manifest));

    return [$apkPath, $manifestPath, $fingerprint, $dir];
}

function cleanupRelease(string $dir): void
{
    // This test created the directory, so it owns every path in it.
    foreach (glob($dir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($dir);
}

/** The pinned production certificate. Config is the trust anchor, never the manifest. */
function pinAnchor(?string $fingerprint): void
{
    config(['android_release.signing.production_certificate_sha256' => $fingerprint]);
}

function verifyWith(SignerFingerprintResolver $signer, string $apk, string $manifest): array
{
    return (new AndroidReleaseArtifactVerifier($signer))->verify($apk, $manifest);
}

// ---------------------------------------------------------------------------
// The happy path, and then every way it must fail
// ---------------------------------------------------------------------------

it('passes an artifact that matches its manifest', function () {
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        expect(verifyWith(fakeSigner($fp), $apk, $manifest)['status'])->toBe('PASS');
    } finally {
        cleanupRelease($dir);
    }
});

it('rejects an artifact whose SHA-256 does not match the manifest', function () {
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        // Substitute the bytes after the manifest was written — the exact shape
        // of a swapped artifact in the release channel.
        file_put_contents($apk, 'substituted-bytes');

        $result = verifyWith(fakeSigner($fp), $apk, $manifest);

        expect($result['status'])->toBe('FAIL');
        expect($result['failures'])->toContain('apk_sha256');
    } finally {
        cleanupRelease($dir);
    }
});

it('cannot be fooled by a manifest that vouches for the attacker key', function () {
    // Security review, CRITICAL. The first version read the expected
    // fingerprint OUT OF THE MANIFEST that ships beside the APK. Whoever can
    // replace the artifact replaces the manifest with it: digest set to their
    // build, fingerprint set to their own key, every config-pinned constant
    // copied from this public repo — and the command exited 0.
    //
    // That is a checksum, not a signature check, and with Google Play gone it
    // was the ONLY control between a substituted artifact and a clinic tablet.
    $attacker = str_repeat('ee', 32);

    // A fully self-consistent forgery: the manifest names the attacker's key.
    [$apk, $manifest, , $dir] = stageRelease([
        'signing_certificate_fingerprint_sha256' => $attacker,
    ]);

    // The real authority, pinned in configuration.
    pinAnchor(str_repeat('a1', 32));

    try {
        // The APK genuinely IS signed by the attacker key, and the manifest
        // genuinely agrees with it. Self-consistency is total.
        $result = verifyWith(fakeSigner($attacker), $apk, $manifest);

        expect($result['status'])->toBe('FAIL', 'A self-consistent forgery was accepted.');
        expect($result['failures'])->toContain('signer_fingerprint');
        expect($result['failures'])->toContain('manifest_matches_trust_anchor');
    } finally {
        cleanupRelease($dir);
    }
});

it('fails closed while no production certificate is pinned', function () {
    // Today there is no production key, so there is no authority to verify
    // against. Verifying against the manifest alone would authenticate any key
    // at all, so the honest behaviour is to refuse.
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor(null);

    try {
        $result = verifyWith(fakeSigner($fp), $apk, $manifest);

        expect($result['status'])->toBe('FAIL');
        expect($result['failures'])->toContain('signer_trust_anchor');

        // And it must not silently continue to a fingerprint comparison that
        // has nothing trustworthy to compare against.
        expect($result['failures'])->not->toContain('signer_fingerprint');
    } finally {
        cleanupRelease($dir);
    }

    // A malformed pin is not a pin.
    [$apk2, $manifest2, $fp2, $dir2] = stageRelease();
    pinAnchor('not-a-fingerprint');

    try {
        expect(verifyWith(fakeSigner($fp2), $apk2, $manifest2)['failures'])
            ->toContain('signer_trust_anchor');
    } finally {
        cleanupRelease($dir2);
    }
});

it('ships with the trust anchor pinned to the one recorded production certificate', function () {
    // This test used to assert the opposite, and it was right to: while no
    // production key existed, claiming a pinned authority would have been the
    // most damaging thing this config could assert.
    //
    // The key exists now, and PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1
    // armed the anchor. The damaging assertion has therefore INVERTED: the
    // dangerous config today is one that pins something other than the
    // certificate custody recorded, because an installer trusts this value and
    // nothing downstream of it asks a second time.
    $pin = config('android_release.signing.production_certificate_sha256');

    expect($pin)->toBe(config('android_release.signing.production_certificate_sha256_recorded'));
    expect($pin)->toMatch('/^[0-9a-f]{64}$/');
    expect(config('android_release.signing.production_certificate_pin_required_before_install'))->toBeTrue();
});

it('rejects an artifact signed by a different key', function () {
    // The drill made this concrete: an attacker-signed artifact VERIFIES as a
    // validly signed package. Validity is not authority — only comparing the
    // signer fingerprint against the release authority catches it.
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        $result = verifyWith(fakeSigner(str_repeat('ff', 32)), $apk, $manifest);

        expect($result['status'])->toBe('FAIL');
        expect($result['failures'])->toContain('signer_fingerprint');
    } finally {
        cleanupRelease($dir);
    }
});

it('fails closed when the signer certificate cannot be read at all', function () {
    // "Could not determine" is never "matches". An unsigned or corrupt APK, or
    // a missing apksigner, must stop the install rather than skip the check.
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        expect(verifyWith(fakeSigner(null), $apk, $manifest)['failures'])
            ->toContain('signer_fingerprint');

        $unavailable = verifyWith(fakeSigner(null, available: false), $apk, $manifest);

        expect($unavailable['status'])->toBe('FAIL');
        expect($unavailable['failures'])->toContain('signer_fingerprint');
    } finally {
        cleanupRelease($dir);
    }
});

it('rejects a debug-variant artifact as a production release', function () {
    // A debug build is signed by a publicly known key that anyone can sign an
    // update over.
    [$apk, $manifest, $fp, $dir] = stageRelease(['build_variant' => 'debug']);
    pinAnchor($fp);

    try {
        $result = verifyWith(fakeSigner($fp), $apk, $manifest);

        expect($result['status'])->toBe('FAIL');
        expect($result['failures'])->toContain('build_variant');
    } finally {
        cleanupRelease($dir);
    }
});

it('rejects an unapproved release, a wrong package and a wrong channel', function () {
    foreach ([
        ['approval_status' => 'draft', 'expect' => 'approval_status'],
        ['package_name' => 'com.example.other', 'expect' => 'package_name'],
        ['release_channel' => 'managed_google_play_private', 'expect' => 'release_channel'],
    ] as $case) {
        $expected = $case['expect'];
        unset($case['expect']);

        [$apk, $manifest, $fp, $dir] = stageRelease($case);
        pinAnchor($fp);

        try {
            $result = verifyWith(fakeSigner($fp), $apk, $manifest);

            expect($result['status'])->toBe('FAIL');
            expect($result['failures'])->toContain($expected);
        } finally {
            cleanupRelease($dir);
        }
    }
});

it('rejects a manifest that is missing fields rather than passing checks that never ran', function () {
    // Comparing an APK against a manifest missing the field you compare on
    // reports a pass for a check that did not happen.
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        $partial = json_decode((string) file_get_contents($manifest), true);
        unset($partial['signing_certificate_fingerprint_sha256']);
        file_put_contents($manifest, json_encode($partial));

        $result = verifyWith(fakeSigner($fp), $apk, $manifest);

        expect($result['status'])->toBe('FAIL');
        expect($result['failures'])->toContain('manifest_schema');
    } finally {
        cleanupRelease($dir);
    }
});

it('rejects a missing or unparseable manifest', function () {
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        file_put_contents($manifest, 'not json at all');
        expect(verifyWith(fakeSigner($fp), $apk, $manifest)['failures'])->toContain('manifest_readable');

        expect(verifyWith(fakeSigner($fp), $apk, $manifest.'-absent')['status'])->toBe('FAIL');
    } finally {
        cleanupRelease($dir);
    }
});

it('rejects a filename that does not match the manifest', function () {
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        $renamed = dirname($apk).'/SomethingElse.apk';
        rename($apk, $renamed);

        $result = verifyWith(fakeSigner($fp), $renamed, $manifest);

        expect($result['status'])->toBe('FAIL');
        expect($result['failures'])->toContain('apk_filename');
    } finally {
        cleanupRelease($dir);
    }
});

// ---------------------------------------------------------------------------
// The command contract — its exit code is what an installer acts on
// ---------------------------------------------------------------------------

it('exits zero only for an artifact that may be installed', function () {
    [$apk, $manifest, $fp, $dir] = stageRelease();
    pinAnchor($fp);

    try {
        $this->app->bind(SignerFingerprintResolver::class, fn () => fakeSigner($fp));

        $this->artisan('android:verify-release', ['apk' => $apk, 'manifest' => $manifest])
            ->assertExitCode(0);

        // A wrong signer must fail the COMMAND, not merely be mentioned in its
        // output. Phase 3.5 shipped a false-green of exactly this shape, where
        // a failing manifest left the exit code at zero.
        $this->app->bind(SignerFingerprintResolver::class, fn () => fakeSigner(str_repeat('ff', 32)));

        $this->artisan('android:verify-release', ['apk' => $apk, 'manifest' => $manifest])
            ->assertExitCode(1);

        // Including in --json mode, where it would be easy to lose.
        $this->artisan('android:verify-release', ['apk' => $apk, 'manifest' => $manifest, '--json' => true])
            ->assertExitCode(1);
    } finally {
        cleanupRelease($dir);
    }
});

// ---------------------------------------------------------------------------
// Standing contract: the decision itself
// ---------------------------------------------------------------------------

it('states the update contract that makes possession of an APK worthless', function () {
    // Trust comes from the Keystore enrolment, never from holding the file.
    expect(config('android_release.update_contract.in_place_update_preserves_enrolment'))->toBeTrue();
    expect(config('android_release.update_contract.uninstall_destroys_keystore_identity'))->toBeTrue();
    expect(config('android_release.update_contract.clear_app_data_destroys_keystore_identity'))->toBeTrue();
    expect(config('android_release.update_contract.reinstall_requires_fresh_enrolment'))->toBeTrue();
    expect(config('android_release.update_contract.trust_restored_by_installation_alone'))->toBeFalse();

    // And the reason a signer mismatch must never be "fixed" by uninstalling.
    expect(config('android_release.update_contract.signer_mismatch_blocks_update'))->toBeTrue();
    expect(config('android_release.update_contract.signer_mismatch_requires_uninstall'))->toBeTrue();
});

it('separates installer authority from application super admin', function () {
    // Approving a device in Master Data and installing an OS package on clinic
    // hardware are different powers.
    expect(config('android_release.installation.application_super_admin_implies_install_authority'))->toBeFalse();
    expect(config('android_release.installation.authorized_installer_role'))->toBe('daengtisiams_admin_it');
    expect(config('android_release.installation.installer_workstation_holds_signing_key'))->toBeFalse();

    // ADB is for provisioning, not fleet management. Leaving debugging on turns
    // a kiosk tablet into an open install surface.
    expect(config('android_release.installation.adb_debugging_disabled_after_provisioning'))->toBeTrue();
});

it('keeps the historical Play decision readable but marked superseded', function () {
    // Supersession, not deletion. Rewriting the record would hide that the
    // Play-era risk argument was accepted as a cost rather than refuted.
    // Mutant M11 survived a check that accepted the marker ANYWHERE in the
    // file: rewriting ADR 0009's status line to "Accepted, current authority"
    // still passed, because an incidental body paragraph contained the word.
    // A document that asserts it is live authority must not satisfy a
    // supersession check, so the marker has to be DECLARED on a status or
    // heading line rather than merely mentioned.
    $pattern = (string) config('android_release.scanner.supersession_declaration_pattern');

    expect($pattern)->not->toBe('', 'The supersession declaration pattern is unarmed.');

    foreach (config('android_release.scanner.play_historical_paths') as $relative) {
        $contents = file_get_contents(base_path($relative));

        expect(preg_match($pattern, $contents))
            ->toBe(1, $relative.' does not DECLARE its supersession on a status or heading line.');
    }

    // Non-vacuity: prose alone must NOT satisfy the pattern.
    expect(preg_match($pattern, "**Status:** Accepted, current authority\n\nSomething was SUPERSEDED once.\n"))
        ->toBe(0, 'A mere mention of the marker satisfies the declaration check.');

    expect(androidCheck('historical_authority_marked_superseded')['status'])->toBe('PASS');
    expect(androidCheck('active_authority_acknowledges_supersession')['status'])->toBe('PASS');
});

it('keeps device enforcement off across the distribution change', function () {
    // That revision changed distribution, not authentication, and enforcement
    // is STILL off after REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1
    // shipped the gate — which is the whole point of asserting it here again.
    expect(config('android_release.enforcement.active'))->toBeFalse();
    expect(config('android_release.enforcement.doctor_browser_login_denied'))->toBeFalse();

    expect(androidCheck('enforcement_off')['status'])->toBe('PASS');
    expect(androidCheck('authentication_coupled_only_through_the_gate')['status'])->toBe('PASS');
});

it('registers the direct-APK suite in the critical gate so something selects it', function () {
    // A control the gate never selects is not a control.
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/DoctorDevice/DoctorDeviceAndroidDirectApkReleaseTest.php');
});
