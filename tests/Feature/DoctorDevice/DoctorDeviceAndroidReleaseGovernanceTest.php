<?php

use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidReleaseGovernanceScanner;
use App\Support\Android\KotlinSourceScanner;
use Illuminate\Support\Facades\Process;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5 — Android production
 * release governance contract.
 *
 * Phase 3 named five unresolved decisions and blocked Phase 4 on them. These
 * tests pin the answers so the resolution cannot quietly rot back into an
 * absence: an unset config value is exactly how an unresolved decision passes
 * for a made one.
 *
 * They also pin the guards themselves. Phase 3's expensive lesson was that a
 * source scanner which matches its own documentation is worse than no scanner,
 * because a control that reddens on a codebase obeying it trains people to
 * delete the control. Every absence assertion here has a presence assertion
 * beside it.
 */
function androidScanner(): AndroidReleaseGovernanceScanner
{
    return app(AndroidReleaseGovernanceScanner::class);
}

function androidCheck(string $id): array
{
    $checks = collect(androidScanner()->scan()['checks'])->keyBy('id');

    expect($checks->has($id))->toBeTrue("Check {$id} disappeared from the scanner.");

    return $checks->get($id);
}

// ---------------------------------------------------------------------------
// The five decisions Phase 3 left open
// ---------------------------------------------------------------------------

it('records a self-managed signing authority and names the risk it accepted', function () {
    // REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1 supersedes the
    // Play App Signing decision. ADR 0009's argument — an upload key is
    // resettable, an app signing key is not — was NOT refuted; it was accepted
    // as a cost. The config has to say so, because every custody rule (three
    // custodians, three copies, a 90-day drill) exists because of it.
    expect(config('android_release.signing.app_signing_authority'))->toBe('self_managed_daengtisiams');
    expect(config('android_release.signing.key_loss_recoverable'))->toBeFalse();

    expect(androidCheck('signing_authority_decided')['status'])->toBe('PASS');
    expect(androidCheck('production_key_custody_decided')['status'])->toBe('PASS');
    expect(androidCheck('signing_authority_is_self_managed')['status'])->toBe('PASS');
});

it('requires three signing custodians, not the Play-era two', function () {
    // Two was enough while a lost upload key could be reset. Nothing can reset
    // this one, so two simultaneous losses would be terminal.
    expect(config('android_release.signing.minimum_custodians'))->toBeGreaterThanOrEqual(3);
    expect(config('android_release.signing.backup.copies'))->toBeGreaterThanOrEqual(3);
    expect(config('android_release.signing.backup.restore_test_cadence_days'))->toBeLessThanOrEqual(90);
});

it('records direct admin-managed APK distribution with no Google Play dependency', function () {
    expect(config('android_release.distribution.canonical_channel'))->toBe('direct_admin_managed_apk');

    // An .aab is a Play UPLOAD format that Google converts into device APKs.
    // With no Play in the chain nothing performs that conversion, so a bundle
    // is not installable and cannot be the release artifact.
    expect(config('android_release.distribution.artifact_format'))->toBe('apk');

    // Explicit negatives. Prose cannot satisfy these, which is the point:
    // the documents necessarily NAME Play in order to reject it.
    foreach (config('android_release.scanner.required_distribution_negatives') as $key) {
        expect(config('android_release.distribution.'.$key))
            ->toBeFalse("android_release.distribution.{$key} must be false");
    }

    // The Phase 3.5 pilot exception is DELETED, not widened. It existed only
    // because a Device Owner tablet has no Google account and so could not
    // install from Managed Google Play; with direct install canonical there is
    // no mismatch to except.
    expect(config('android_release.distribution.pilot_exception'))->toBeNull();

    expect(androidCheck('apk_is_canonical_release_artifact')['status'])->toBe('PASS');
    expect(androidCheck('google_play_not_required')['status'])->toBe('PASS');
});

it('keeps the release source access-controlled rather than a chat attachment', function () {
    expect(config('android_release.distribution.public_unauthenticated_artifact_hosting_permitted'))->toBeFalse();
    expect(config('android_release.distribution.release_source_requirements'))
        ->toContain('sha256_published')
        ->toContain('access_controlled')
        ->toContain('version_history');
});

it('records a device management model and refuses dismissible kiosk mechanisms', function () {
    expect(config('android_release.device_management.pilot_model'))->toBe('self_owned_device_owner');
    // The Phase-3.5 "EMM required at 5 devices" rule was a Managed Google Play
    // dependency wearing a device costume — it came from the distribution
    // channel, not from kiosk needs. Advisory now, and never mandatory.
    expect(config('android_release.device_management.fleet_model'))->toBe('self_owned_device_owner');
    expect(config('android_release.device_management.mdm_required'))->toBeFalse();
    expect(config('android_release.device_management.scale_model_required_at_devices'))->toBeNull();

    // Screen pinning is dismissible by the user, so it is not a security
    // control. A hard-coded exit PIN is a shared secret on every device.
    expect(config('android_release.device_management.forbidden_kiosk_mechanisms'))
        ->toContain('screen_pinning')
        ->toContain('hardcoded_exit_pin');

    // A QR payload is readable by anyone who can photograph the screen.
    expect(config('android_release.device_management.qr_provisioning_may_carry_secrets'))->toBeFalse();
});

it('records a recovery mechanism that matches what the PLATFORM permits', function () {
    // "Halt the rollout" was a Play Console button and there is no Play
    // Console. The forward-fix half survives because it rests on platform
    // behaviour rather than on Play.
    expect(config('android_release.versioning.rollback_mechanism'))
        ->toBe('stop_distribution_then_forward_fix_with_higher_version_code');

    // The precision the Play-era wording hid, and which matters more now:
    // Android enforces >=, Play additionally enforced strictly >. Removing
    // Play removed that enforcement, so it has to be OURS explicitly — or two
    // different builds could share a versionCode and Android would install one
    // over the other without complaint.
    expect(config('android_release.versioning.platform_version_code_rule'))
        ->toBe('update_must_be_greater_or_equal');
    expect(config('android_release.versioning.governance_version_code_rule'))
        ->toBe('new_release_must_be_strictly_greater');

    expect(config('android_release.versioning.version_code_decrement_permitted'))->toBeFalse();
    expect(config('android_release.versioning.version_code_reuse_permitted'))->toBeFalse();

    // There is no supported production downgrade: it needs an uninstall, and
    // uninstall erases the app data that holds the device identity.
    expect(config('android_release.versioning.production_downgrade_supported'))->toBeFalse();
    expect(config('android_release.versioning.uninstall_destroys_device_identity'))->toBeTrue();

    expect(androidCheck('version_code_policy_monotonic')['status'])->toBe('PASS');
});

it('never lets the release build type fall back to the debug signing identity', function () {
    // The debug key is publicly known: anyone can sign an update over a
    // debug-signed release.
    expect(androidCheck('release_not_debug_signed')['status'])->toBe('PASS');

    // Non-vacuity. Without this, a stripper that ate the file — or a release
    // block that moved — would let the assertion above pass on nothing.
    expect(androidCheck('release_block_readable')['status'])->toBe('PASS');
});

it('detects a debug-signed release block rather than trusting the current file', function () {
    // The assertion above is only worth anything if the scan can actually see
    // the violation. Prove it on a synthetic build file.
    $kotlin = new KotlinSourceScanner;

    $violating = <<<'KOTLIN'
    android {
        buildTypes {
            release {
                isMinifyEnabled = true
                proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"))
                signingConfig = signingConfigs.getByName("debug")
            }
        }
    }
    KOTLIN;

    $body = $kotlin->blockBody($kotlin->stripComments($violating), 'release');

    $found = collect(config('android_release.scanner.forbidden_release_signing_tokens'))
        ->filter(fn (string $token): bool => str_contains((string) $body, $token));

    expect($found)->not->toBeEmpty('The scan cannot see a debug-signed release block.');
});

it('cannot be pointed at a decoy release block by a string literal', function () {
    // Security review, HIGH-1. String literals are preserved on purpose (the
    // forbidden token contains one), so a file-wide search for `release {` is
    // hijackable: one innocuous Kotlin string wins the match, the scan reads a
    // decoy, and the tool then prints "does not fall back to the debug signing
    // identity" about a build that does exactly that.
    $kotlin = new KotlinSourceScanner;

    $hijacked = <<<'KOTLIN'
    val releaseNotes = "release { see the wiki }"
    android {
        buildTypes {
            release {
                isMinifyEnabled = true
                proguardFiles("proguard-rules.pro")
                signingConfig = signingConfigs.getByName("debug")
            }
        }
    }
    KOTLIN;

    $stripped = $kotlin->stripComments($hijacked);
    $path = (array) config('android_release.scanner.release_block_path');

    // The decoy wins a file-wide search...
    expect($kotlin->blockBody($stripped, 'release'))->not->toContain('signingConfig');

    // ...and loses the anchored one, which is what the scanner uses.
    $anchored = $kotlin->nestedBlockBody($stripped, $path);

    expect($anchored)->not->toBeNull();
    expect($anchored)->toContain('signingConfigs.getByName("debug")');
});

it('reports FAIL on a synthetic tree whose release block was hijacked', function () {
    // Mutant M14 survived a suite that only asserted against the real build
    // file: on a clean tree both markers exist at file level AND inside the
    // release block, so scoping the check to the block makes no observable
    // difference. The difference only appears when extraction is hijacked —
    // which is precisely the case that matters. So drive the REAL scanner over
    // a synthetic tree, the way the security review proved the defect.
    $base = sys_get_temp_dir().'/phase35-synthetic-'.bin2hex(random_bytes(6));
    $buildFile = $base.'/'.config('android_release.scanner.app_build_file');

    mkdir(dirname($buildFile), 0777, true);

    // The decoy yields an EMPTY extraction under a file-wide search, while
    // `isMinifyEnabled` and `proguardFiles` still resolve elsewhere in the file.
    // A whole-file marker check therefore reports "readable" over zero bytes.
    file_put_contents($buildFile, <<<'KOTLIN'
    val notes = mapOf("k" to "release {}")
    android {
        buildTypes {
            release {
                isMinifyEnabled = true
                proguardFiles("proguard-rules.pro")
                signingConfig = signingConfigs.getByName("debug")
            }
        }
    }
    KOTLIN);

    try {
        $scanner = new AndroidReleaseGovernanceScanner(new KotlinSourceScanner, $base);
        $checks = collect($scanner->scan()['checks'])->keyBy('id');

        // The whole point: the debug-signing fallback is FOUND, not hidden
        // behind the decoy.
        expect($checks['release_not_debug_signed']['status'])
            ->toBe('FAIL', 'A debug-signed release block went undetected.');

        // And the marker check must be scoped to the EXTRACTED BLOCK.
        //
        // The distinguishing shape is a NON-EMPTY release block that lacks the
        // markers while they still resolve elsewhere in the file. An empty
        // block does not distinguish anything, because emptiness is checked
        // separately — which is exactly why mutant M14 kept surviving until
        // this case existed.
        file_put_contents($buildFile, <<<'KOTLIN'
        val docs = "isMinifyEnabled and proguardFiles are configured elsewhere"
        android {
            buildTypes {
                release {
                    versionNameSuffix = "-unreviewed"
                }
            }
        }
        KOTLIN);

        $emptyBlockChecks = collect(
            (new AndroidReleaseGovernanceScanner(new KotlinSourceScanner, $base))->scan()['checks']
        )->keyBy('id');

        expect($emptyBlockChecks['release_block_readable']['status'])
            ->toBe('FAIL', 'An empty release block was reported as readable.');
    } finally {
        // This test created the tree, so it owns every path in it.
        @unlink($buildFile);
        foreach ([dirname($buildFile), $base.'/android/daengtisia-clinic', $base.'/android', $base] as $dir) {
            @rmdir($dir);
        }
    }
});

it('refuses an empty release block instead of passing on nothing', function () {
    // The second half of HIGH-1: a decoy that yields an EMPTY extraction. An
    // absence assertion over "" reports PASS while examining zero bytes, so the
    // markers are asserted against the extracted block and emptiness is a FAIL.
    expect(config('android_release.scanner.required_release_block_markers'))
        ->toContain('isMinifyEnabled')
        ->toContain('proguardFiles')
        // `release` would be useless as a marker: the extraction already
        // matched it, so it can never fail.
        ->not->toContain('release');

    expect(androidCheck('release_block_readable')['status'])->toBe('PASS');
});

it('keeps signing key material out of the git index', function () {
    expect(androidCheck('no_committed_key_material')['status'])->toBe('PASS');
    expect(androidCheck('keystore_ignored')['status'])->toBe('PASS');
});

it('keeps the key-material guard armed rather than passing on an empty list', function () {
    // Found by mutation M2, which survived the first version of this suite.
    // Both checks above are absence assertions, and an absence assertion over
    // an emptied pattern list reports PASS while enforcing nothing — the guard
    // disarms itself silently. So the armed state is asserted directly.
    expect(androidCheck('key_material_guard_armed')['status'])->toBe('PASS');

    // Security review, MEDIUM-1: the armed set must be the CANONICAL list, not
    // a hardcoded pair. Guarding two of six patterns let the other four — .p12,
    // .pfx and the two properties files that carry the keystore passwords — be
    // deleted in silence.
    $declared = config('android_release.scanner.forbidden_tracked_path_patterns');

    foreach (config('android_release.scanner.key_material_guard_minimum') as $pattern) {
        expect($declared)->toContain($pattern);
    }

    expect(config('android_release.scanner.key_material_guard_minimum'))
        ->toContain('*.jks')
        ->toContain('*.keystore')
        ->toContain('*.p12')
        ->toContain('keystore.properties');

    expect(config('android_release.scanner.required_gitignore_patterns'))
        ->toContain('android/**/*.jks')
        ->toContain('android/**/*.keystore');
});

it('does not accept a comment or a negation as an ignore rule', function () {
    // Security review, MEDIUM-2. A raw substring match over .gitignore is
    // satisfied by `# never commit android/**/*.jks` (ignores nothing) and —
    // far worse — by `!android/**/*.jks`, which explicitly UN-ignores keystores
    // while the check reports that they are ignored.
    $ignore = file_get_contents(base_path('.gitignore'));

    foreach (config('android_release.scanner.gitignore_guard_minimum') as $pattern) {
        expect($ignore)->toContain($pattern);
        expect($ignore)->not->toContain('!'.$pattern, 'A negation would un-ignore '.$pattern);
    }

    expect(androidCheck('keystore_ignored')['status'])->toBe('PASS');
});

it('fails closed when a declared enforcement surface disappears', function () {
    // Security review, MEDIUM-3. The coupling scan silently skipped a missing
    // directory, so renaming one reduced coverage to nothing while the check
    // kept reporting "no authentication surface references the device
    // registry" — the single invariant this phase most needs to be true.
    expect(androidCheck('enforcement_scan_surfaces_present')['status'])->toBe('PASS');

    $surfaces = config('android_release.scanner.enforcement_coupling_surfaces');

    expect($surfaces)->not->toBeEmpty();

    foreach ($surfaces as $surface) {
        expect(file_exists(base_path($surface)))
            ->toBeTrue("Declared enforcement surface {$surface} does not exist.");
    }

    // Phase 3 ruled that device middleware, if ever added, lives here and never
    // in app/Http/Middleware. It does not exist yet — which is why it is
    // WATCHED rather than required — but it must be scanned the day it appears.
    expect(config('android_release.scanner.enforcement_coupling_watch_surfaces'))
        ->toContain('app/Modules/DoctorDevice/Middleware');
});

it('gives pull-request CI no path to the signing identity', function () {
    // PR CI executes untrusted code. A key it can reach makes every
    // contributor — and every fork — a release authority.
    expect(config('android_release.signing.pull_request_ci_may_sign'))->toBeFalse();
    expect(androidCheck('pull_request_ci_cannot_sign')['status'])->toBe('PASS');
});

it('keeps the gradle wrapper committed executable', function () {
    // Git stores 100644 or 100755 and nothing else. Phase 3's Android gate died
    // at exit 126 before running a single test because the wrapper was
    // committed 100644 while being executable on the developer's disk.
    expect(androidCheck('gradle_wrapper_executable')['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// The scanner's own honesty
// ---------------------------------------------------------------------------

it('strips kotlin comments without matching the prose that explains the rule', function () {
    $kotlin = new KotlinSourceScanner;

    $source = <<<'KOTLIN'
    // Never write signingConfig = signingConfigs.getByName("debug") here.
    /* Nor here: signingConfigs.getByName("debug")
       /* and nested block comments must not end early */
       still inside the outer comment: signingConfigs.getByName("debug") */
    android {
        buildTypes {
            release {
                proguardFiles("proguard-rules.pro")
            }
        }
    }
    KOTLIN;

    $stripped = $kotlin->stripComments($source);

    // Absence: the prose must not trip the guard.
    expect($stripped)->not->toContain('signingConfigs.getByName("debug")');

    // Presence: a stripper that removed too much would satisfy the line above
    // while proving nothing. This is the assertion that makes the scan
    // non-vacuous.
    expect($stripped)->toContain('buildTypes')->toContain('proguardFiles');
});

it('preserves string literals, because the forbidden token contains one', function () {
    // The over-correction that would have broken this guard. Phase 3's lesson
    // was "strip comments"; applying it as "blank the strings too" makes
    // `signingConfigs.getByName("debug")` unmatchable and the guard passes on a
    // genuinely debug-signed release. Literal awareness means a // inside a
    // string does not open a comment — not that the string is deleted.
    $kotlin = new KotlinSourceScanner;

    $stripped = $kotlin->stripComments('val x = signingConfigs.getByName("debug") // comment');

    expect($stripped)->toContain('signingConfigs.getByName("debug")');
    expect($stripped)->not->toContain('comment');
});

it('does not open a comment inside a string literal', function () {
    $kotlin = new KotlinSourceScanner;

    $stripped = $kotlin->stripComments('val url = "https://example.test/a" ; val keep = 1');

    // "//" inside the URL must not swallow the rest of the line.
    expect($stripped)->toContain('val keep = 1');
});

it('returns null for an unbalanced block rather than a truncated one', function () {
    // A truncated read that looks clean is how an absence assertion passes on
    // half a file.
    $kotlin = new KotlinSourceScanner;

    expect($kotlin->blockBody('release { isMinifyEnabled = true', 'release'))->toBeNull();
    expect($kotlin->blockBody('debug { }', 'release'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Enforcement stays off
// ---------------------------------------------------------------------------

it('keeps device enforcement off, now with a real switch that defaults to false', function () {
    expect(config('android_release.enforcement.active'))->toBeFalse();
    expect(config('android_release.enforcement.doctor_browser_login_denied'))->toBeFalse();
    expect(config('android_release.enforcement.current_stage'))->toBe('off');

    // PHASE 2 decided "the flag is created by the phase that needs one", and
    // Phase 3.5 therefore pinned `flag_exists => false`.
    // REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 is that phase: it
    // ships the whole app-only gate, and a capability with no switch can only
    // be released by editing code under pressure.
    //
    // The rule that stance protected — no half-wired switch — is kept and
    // strengthened. The flag is fully wired, and its OFF state is asserted
    // against the real feature-flag registry, not against a config file that
    // could claim anything.
    expect(config('android_release.enforcement.flag_exists'))->toBeTrue();

    expect(app(FeatureFlagService::class)
        ->enabled((string) config('android_release.enforcement.flag_key')))->toBeFalse();

    expect(androidCheck('enforcement_off')['status'])->toBe('PASS');
    expect(androidCheck('authentication_coupled_only_through_the_gate')['status'])->toBe('PASS');
});

it('never claims a production key or a real device it does not have', function () {
    $summary = androidScanner()->scan()['summary'];

    // The single most damaging thing this tooling could do is let a reader
    // infer readiness it has not proven.
    //
    // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 made the key line true, so
    // this now asserts the reporting CONTRACT rather than a frozen value: the
    // summary must state the key fact from the custody record instead of a
    // literal, and the two facts most likely to be inferred from it — that an
    // artifact can be verified, and that a device has been validated — must
    // still be false and reported separately.
    expect($summary['production_signing_key_provisioned'])
        ->toBe(config('android_release.signing.custody.production_signing_key_provisioned') === true);

    // A key exists; nothing downstream of it does.
    expect($summary['production_certificate_pinned'])->toBeFalse();
    expect($summary['real_device_validation'])->toBeFalse();
    expect($summary['device_enforcement_active'])->toBeFalse();
});

it('requires a spare device and full doctor coverage before global enforcement', function () {
    // Removing browser login before spares exist is how a clinic loses a day.
    expect(config('android_release.enforcement.global_prerequisites'))
        ->toContain('real_device_pilot_passed')
        ->toContain('every_enforced_doctor_has_an_active_device')
        ->toContain('spare_device_available_per_branch')
        ->toContain('rollback_to_browser_login_proven');
});

// ---------------------------------------------------------------------------
// Documents, manifest, command
// ---------------------------------------------------------------------------

it('keeps every governance document the decisions depend on', function () {
    $check = androidCheck('governance_documents_present');

    expect($check['status'])->toBe('PASS', $check['detail']);
});

it('validates a release manifest for the provenance a rollback needs', function () {
    $scanner = androidScanner();

    expect($scanner->verifyReleaseManifest([])['status'])->toBe('FAIL');

    $complete = [
        'package_name' => 'com.daengtisia.clinic',
        'version_name' => '1.0.0',
        'version_code' => 2,
        'build_variant' => 'release',
        'apk_filename' => 'DaengtisiaMS-Clinic-v1.0.0.apk',
        'approval_status' => 'approved',
        'git_commit' => str_repeat('a', 40),
        'ci_run_id' => '123456',
        'artifact_sha256' => str_repeat('b', 64),
        // A certificate fingerprint is a public identifier, not a secret. It is
        // how "is this the build we tested?" stays answerable months later.
        'signing_certificate_fingerprint_sha256' => str_repeat('c', 64),
        'release_channel' => 'direct_admin_managed_apk',
    ];

    expect($scanner->verifyReleaseManifest($complete)['status'])->toBe('PASS');

    unset($complete['signing_certificate_fingerprint_sha256']);
    expect($scanner->verifyReleaseManifest($complete)['missing'])
        ->toContain('signing_certificate_fingerprint_sha256');
});

it('rejects structurally junk provenance rather than counting the key as present', function () {
    // Security review, LOW-3. "Present" was `isset && !== '' && !== null`, so a
    // manifest whose every field held [] was reported as carrying every
    // required provenance field. A placeholder manifest would gate a release as
    // though it identified a real build.
    $scanner = androidScanner();

    $junk = array_fill_keys(
        (array) config('android_release.versioning.release_metadata_required'),
        []
    );

    expect($scanner->verifyReleaseManifest($junk)['status'])->toBe('FAIL');

    // A digest field must look like a digest. "abcd" is present, non-empty and
    // scalar — and identifies nothing.
    $shortDigest = [
        'package_name' => 'com.daengtisia.clinic',
        'version_name' => '1.0.0',
        'version_code' => 2,
        'build_variant' => 'release',
        'apk_filename' => 'DaengtisiaMS-Clinic-v1.0.0.apk',
        'approval_status' => 'approved',
        'git_commit' => str_repeat('a', 40),
        'ci_run_id' => '123456',
        'artifact_sha256' => 'abcd',
        'signing_certificate_fingerprint_sha256' => str_repeat('c', 64),
        'release_channel' => 'direct_admin_managed_apk',
    ];

    $result = $scanner->verifyReleaseManifest($shortDigest);

    expect($result['status'])->toBe('FAIL');
    expect($result['malformed'])->toContain('artifact_sha256');
});

it('exposes the readiness command and exits zero on a governed tree', function () {
    $exit = $this->artisan('android:release-readiness', ['--strict' => true]);

    $exit->assertExitCode(0);
});

it('fails the command when a supplied release manifest is incomplete', function () {
    // The release runbook tells the operator to run this exact command as a
    // gate before publishing. If a bad manifest exited 0 because the repository
    // posture happened to be GO, the gate would wave through a release nobody
    // can later identify — the false-green this phase exists to prevent.
    $path = tempnam(sys_get_temp_dir(), 'phase35-manifest-');
    file_put_contents($path, json_encode(['version_name' => '1.0.0']));

    try {
        $this->artisan('android:release-readiness', ['--manifest' => $path])
            ->assertExitCode(1);

        // A file that is not JSON at all must fail too, rather than being read
        // as an empty manifest and quietly reported as missing fields.
        file_put_contents($path, 'not json');
        $this->artisan('android:release-readiness', ['--manifest' => $path])
            ->assertExitCode(1);
    } finally {
        // tempnam creates the file it names, so this test owns that path and
        // has to remove it — a leaked temp file per run is still a leak.
        @unlink($path);
    }

    $this->artisan('android:release-readiness', ['--manifest' => $path.'-absent'])
        ->assertExitCode(1);
});

it('registers the governance suite in the critical gate so something selects it', function () {
    // A control the gate never selects is not a control. Phase 1 lost 24 of 28
    // cases to exactly this, silently, because no filter token matched.
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/DoctorDevice/DoctorDeviceAndroidReleaseGovernanceTest.php');
});

it('keeps the readiness command free of any credential requirement', function () {
    // It must be safe to run on a fork pull request: if it ever needs a secret,
    // PR CI either loses the check or gains a key.
    //
    // The first version of this test asserted the absence of two arbitrary
    // camelCase strings in ONE file and called that proof. It would have passed
    // unchanged if the command read a service-account credential from the
    // environment — an assurance claim stronger than its evidence, which is the
    // exact failure class this phase exists to avoid. Assert the real property:
    // no environment read and no credential-bearing config read, across every
    // file in the path.
    $files = [
        'app/Support/Android/AndroidReleaseGovernanceScanner.php',
        'app/Support/Android/KotlinSourceScanner.php',
        'app/Console/Commands/AndroidReleaseReadinessCommand.php',
        'config/android_release.php',
    ];

    foreach ($files as $relative) {
        $source = file_get_contents(base_path($relative));

        // Presence assertion first: prove the file was actually read, so a
        // typo'd path cannot make the absence assertions vacuous.
        expect($source)->toStartWith('<?php', $relative.' was not read.');

        expect($source)->not->toMatch('/\benv\s*\(/', $relative.' reads the environment.');
        expect($source)->not->toMatch("/config\s*\(\s*['\"]services\./", $relative.' reads service credentials.');
    }
});

it('shells out only to read-only git subcommands', function () {
    // A "read-only" auditor that can mutate the tree is a liability during an
    // incident — the moment you most want to run it is the moment you least
    // want it writing anything.
    Process::fake();

    androidScanner()->scan();

    // REVISION-ANDROID-RELEASE-READINESS-PHASE4A-PILOT-AUTHORITY-1 — the
    // scanner now passes an invocation-scoped `-c safe.directory=<this repo>`
    // so the audit is runnable by the unprivileged production runtime identity
    // against a root-owned tree. That puts option pairs before the subcommand,
    // so the subcommand is resolved rather than read off a fixed index.
    //
    // Resolving it also makes this control stricter than the version it
    // replaces: the injected configuration is constrained to exactly one key
    // with exactly one value, so `-c core.hooksPath=...` or a wildcard trust
    // is now a failure here rather than an unnoticed extra argument.
    $subcommand = function ($process): array {
        $command = is_array($process->command)
            ? $process->command
            : explode(' ', (string) $process->command);

        $binary = array_shift($command) ?? null;

        while (($command[0] ?? null) === '-c') {
            array_shift($command);

            expect(array_shift($command))->toBe(
                'safe.directory='.base_path(),
                'git was given configuration other than this repository\'s scoped trust.',
            );
        }

        return [$binary, $command[0] ?? null];
    };

    // Presence: if the scan stops invoking git at all, the index checks have
    // gone vacuous and this assertion is what notices.
    Process::assertRan(fn ($process): bool => $subcommand($process) === ['git', 'ls-files']);

    // Absence: ls-files reads the index and cannot modify it. Constrain EVERY
    // process, not just git — an earlier version only forbade non-ls-files git
    // subcommands, so a future `sh -c` or a gradle invocation would have walked
    // straight through it.
    Process::assertNotRan(fn ($process): bool => $subcommand($process) !== ['git', 'ls-files']);
});
