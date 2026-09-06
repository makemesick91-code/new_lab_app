<?php

use App\Support\Android\AndroidReleaseGovernanceScanner;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * EVIDENCE-PHASE4A-REAL-DEVICE-KEYINFO-PREFLIGHT-1.
 *
 * The Phase 4A pilot rests on a claim that had never been measured: that the
 * device identity key the Clinic App generates lives in secure hardware and
 * cannot leave the device. Phases 1-3.5 assumed it. The Phase 3 instrumentation
 * suite proves the enrolment PROTOCOL works, and every one of its assertions
 * would also pass against a software-backed key.
 *
 * The specific trap these tests exist to keep shut is the one that reads a
 * DEVICE CAPABILITY FLAG as proof. `android.hardware.hardware_keystore`
 * describes the platform, not the key: a keystore can issue a particular key in
 * software — unsupported algorithm, refused spec, StrongBox asked of hardware
 * that has none — while the flag reads exactly the same. The authoritative
 * answer is a property of the generated key, `KeyInfo.getSecurityLevel()`.
 *
 * So the assertions below are deliberately not "the recorded evidence says
 * PASS". They mutate the recorded evidence into each way it could be wrong and
 * require the scanner to go red — because a gate that only ever sees the
 * correct value has never been shown to reject anything.
 *
 * Nothing here activates anything. The last block is the one that matters
 * clinically: a hardware gate closing must move signing, enrolment and
 * enforcement not at all.
 */
function preflightScan(): array
{
    return app(AndroidReleaseGovernanceScanner::class)->scan();
}

function preflightCheck(string $id): array
{
    $found = collect(preflightScan()['checks'])->firstWhere('id', $id);

    expect($found)->not->toBeNull("Check {$id} disappeared from the scanner.");

    return $found;
}

/** Overwrite one key inside the recorded evidence and rescan. */
function preflightWithEvidence(array $overrides): array
{
    $preflight = (array) config('android_release.real_device_preflight');
    $preflight['evidence'] = array_merge((array) $preflight['evidence'], $overrides);
    config()->set('android_release.real_device_preflight', $preflight);

    return preflightScan();
}

function preflightStatus(array $report, string $id): string
{
    return (string) collect($report['checks'])->firstWhere('id', $id)['status'];
}

/** Delete one key from the recorded evidence and rescan. */
function preflightWithoutEvidenceKey(string $key): array
{
    $preflight = (array) config('android_release.real_device_preflight');
    $evidence = (array) $preflight['evidence'];
    unset($evidence[$key]);
    $preflight['evidence'] = $evidence;
    config()->set('android_release.real_device_preflight', $preflight);

    return preflightScan();
}

// ---------------------------------------------------------------------------
// The rule
// ---------------------------------------------------------------------------

it('refuses to treat a device capability flag as proof of a hardware-backed key', function () {
    // The whole point. If this ever flips to true the gate below becomes
    // decorative, because every device that declares the feature would pass.
    expect(config('android_release.real_device_preflight.capability_flag_is_sufficient_proof'))->toBeFalse();

    expect(config('android_release.real_device_preflight.authoritative_proof'))
        ->toBe('android_keystore_keyinfo_security_level');
});

it('prefers the key-level security level over the deprecated secure-hardware call', function () {
    // `isInsideSecureHardware()` is the pre-API-31 compatibility path and is
    // deprecated. It may exist as a fallback; it may not be the preferred proof
    // on a device that can answer the newer question.
    expect(config('android_release.real_device_preflight.preferred_api'))->toBe('KeyInfo.getSecurityLevel');
    expect(config('android_release.real_device_preflight.legacy_api_is_preferred_proof'))->toBeFalse();
});

it('accepts the trusted execution environment and strongbox, and nothing softer', function () {
    $accepted = config('android_release.real_device_preflight.accepted_security_levels');
    $rejected = config('android_release.real_device_preflight.rejected_security_levels');

    expect($accepted)->toContain('TRUSTED_ENVIRONMENT');
    expect($accepted)->toContain('STRONGBOX');

    // SOFTWARE is the failure mode. UNKNOWN_SECURE is the subtler one: an
    // unattested claim of security is not a measurement of it.
    expect($accepted)->not->toContain('SOFTWARE');
    expect($accepted)->not->toContain('UNKNOWN_SECURE');
    expect($rejected)->toContain('SOFTWARE');
    expect($rejected)->toContain('UNKNOWN_SECURE');
});

it('requires a non-exportable private key and keeps strongbox preferred rather than mandatory', function () {
    expect(config('android_release.real_device_preflight.private_key_must_be_non_exportable'))->toBeTrue();

    // Mandating a discrete secure element would refuse most acceptable clinic
    // hardware; the app already falls back from StrongBox to the TEE.
    expect(config('android_release.real_device_preflight.strongbox_required'))->toBeFalse();
    expect(config('android_release.real_device_preflight.strongbox_preferred'))->toBeTrue();
    expect(config('android_release.device_specification.strongbox_mandatory'))->toBeFalse();
});

it('requires a verified, locked, physical device', function () {
    expect(config('android_release.real_device_preflight.verified_boot_state_required'))->toBe('green');
    expect(config('android_release.real_device_preflight.bootloader_must_be_locked'))->toBeTrue();
    expect(config('android_release.real_device_preflight.vbmeta_must_be_locked'))->toBeTrue();
    expect(config('android_release.real_device_preflight.emulator_permitted'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The recorded evidence
// ---------------------------------------------------------------------------

it('records the measurement rather than the capability, and passes its own gates', function () {
    $evidence = config('android_release.real_device_preflight.evidence');

    expect($evidence['key_security_level'])->toBe('TRUSTED_ENVIRONMENT');
    expect($evidence['hardware_backed_private_key'])->toBeTrue();
    expect($evidence['private_key_exportable'])->toBeFalse();

    // StrongBox is absent on this tablet and that is not a failure.
    expect($evidence['strongbox_available'])->toBeFalse();
    expect($evidence['strongbox_backed'])->toBeFalse();

    expect($evidence['physical_device'])->toBeTrue();
    expect($evidence['emulator'])->toBeFalse();
    expect($evidence['verified_boot_state'])->toBe('green');
    expect($evidence['bootloader_locked'])->toBeTrue();
    expect($evidence['vbmeta_locked'])->toBeTrue();

    expect($evidence['protocol_tests_run'])->toBe(7);
    expect($evidence['protocol_tests_failed'])->toBe(0);
    expect($evidence['keyinfo_tests_run'])->toBe(1);
    expect($evidence['keyinfo_tests_failed'])->toBe(0);

    expect(preflightCheck('real_device_preflight_baseline')['status'])->toBe('PASS');
    expect(preflightCheck('key_security_level_accepted')['status'])->toBe('PASS');
    expect(preflightCheck('preflight_unlocks_nothing')['status'])->toBe('PASS');
});

it('names the device by a logical label and commits no serial', function () {
    $evidence = (array) config('android_release.real_device_preflight.evidence');

    expect(config('android_release.real_device_preflight.device_serial_may_be_committed'))->toBeFalse();
    expect(config('android_release.real_device_preflight.device_reference_style'))->toBe('logical_label');
    expect($evidence['device_label'])->toBe('PHASE4A_PILOT_TABLET_01');

    // E9. A serial is a handle on one physical object a person carries in a
    // bag; the MODEL is the class of device and is exactly what governance
    // needs. Two ways this could regress, so both are closed.

    // First, by name — nobody adds a field for it.
    foreach (array_keys($evidence) as $key) {
        expect((bool) preg_match('/serial|imei|meid|iccid|android_id|hardware_id/i', (string) $key))
            ->toBeFalse("Evidence key '{$key}' names a hardware instance identifier.");
    }

    // Second, by shape — a serial smuggled into some other field. Tokenise on
    // non-alphanumerics so an UPPER-KEBAB revision id decomposes into harmless
    // words, then flag any 8-20 character token carrying BOTH a digit and an
    // uppercase letter, which is what a Samsung ADB serial looks like and what
    // none of the legitimate values here are.
    $allowed = ['SHA256withECDSA'];

    foreach ($evidence as $key => $value) {
        if (! is_string($value)) {
            continue;
        }

        foreach (preg_split('/[^A-Za-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (in_array($token, $allowed, true)) {
                continue;
            }

            $serialShaped = strlen($token) >= 8
                && strlen($token) <= 20
                && preg_match('/\d/', $token) === 1
                && preg_match('/[A-Z]/', $token) === 1;

            expect($serialShaped)->toBeFalse("Serial-shaped token '{$token}' in evidence field '{$key}'.");
        }
    }

    // And the durable prose says so out loud, so a future editor knows the
    // omission is deliberate rather than an oversight to be helpfully fixed.
    $doc = file_get_contents(base_path('docs/sprints/evidence-phase4a-real-device-keyinfo-preflight-1.md'));
    expect(strtolower($doc))->toContain('serial');
    expect($doc)->toContain('deliberately not recorded');
});

it('keeps the temporary probe out of the tree and the production alias untouched', function () {
    $evidence = config('android_release.real_device_preflight.evidence');

    expect($evidence['keyinfo_probe_committed'])->toBeFalse();
    expect($evidence['production_device_identity_created'])->toBeFalse();
    expect($evidence['test_key_alias'])->toBe('daengtisiams_instrumented_test_key');
    expect($evidence['production_key_alias_untouched'])->toBe('daengtisiams_device_identity_v1');

    // The probe was an evidence probe. It is not in the tree, and neither is a
    // KeyInfo call in the shipped app: `DeviceIdentityManager` reads no
    // security level, so the deprecation warning the probe emitted belongs to
    // the probe alone.
    $manager = base_path('android/daengtisia-clinic/app/src/main/java/com/daengtisia/clinic/identity/DeviceIdentityManager.kt');
    expect(is_file($manager))->toBeTrue();
    expect(file_get_contents($manager))->not->toContain('isInsideSecureHardware');

    $suite = base_path('android/daengtisia-clinic/app/src/androidTest/java/com/daengtisia/clinic/DeviceIdentityInstrumentedTest.kt');
    $source = file_get_contents($suite);

    // The recorded 7 is the suite's real size, not a number typed into a doc.
    expect(substr_count($source, '@Test'))->toBe($evidence['protocol_tests_run']);
    expect($source)->not->toContain('getSecurityLevel');

    // Both setUp and tearDown destroy the alias, which is why the probe left
    // no key behind.
    expect(substr_count($source, 'clearIdentity()'))->toBeGreaterThanOrEqual(2);
});

// ---------------------------------------------------------------------------
// Adversarial — each of these is a way the evidence could be wrong
// ---------------------------------------------------------------------------

it('fails when the measured security level is downgraded to software', function () {
    // E1. The single most important rejection in this file.
    $report = preflightWithEvidence(['key_security_level' => 'SOFTWARE']);

    expect(preflightStatus($report, 'key_security_level_accepted'))->toBe('FAIL');
    expect($report['status'])->toBe('FAIL');
    expect($report['summary']['real_device_hardware_preflight'])->toBeFalse();
});

it('fails on an unattested security level as firmly as on a software one', function () {
    $report = preflightWithEvidence(['key_security_level' => 'UNKNOWN_SECURE']);

    expect(preflightStatus($report, 'key_security_level_accepted'))->toBe('FAIL');
});

it('fails when no security level is recorded at all', function () {
    // E2. The capability flags stay true here — which is the point: they are
    // present and they are not enough.
    $report = preflightWithEvidence(['key_security_level' => null]);

    expect(preflightStatus($report, 'key_security_level_accepted'))->toBe('FAIL');
    expect(collect($report['checks'])->firstWhere('id', 'key_security_level_accepted')['detail'])
        ->toContain('capability flag is not a substitute');
});

it('fails when the private key is exportable, whatever the security level says', function () {
    $report = preflightWithEvidence(['private_key_exportable' => true]);

    expect(preflightStatus($report, 'key_security_level_accepted'))->toBe('FAIL');
});

it('fails when strongbox is claimed on hardware that has none', function () {
    // E4. Not because StrongBox is required — it is not — but because the
    // record would then be false, and a false record is worse than an absent
    // capability.
    expect(config('android_release.real_device_preflight.evidence.strongbox_available'))->toBeFalse();
    expect(config('android_release.real_device_preflight.evidence.strongbox_backed'))->toBeFalse();

    // Absent StrongBox must not itself fail anything.
    expect(preflightCheck('key_security_level_accepted')['status'])->toBe('PASS');
});

it('fails on an emulator or an unlocked boot chain', function () {
    expect(preflightStatus(preflightWithEvidence(['emulator' => true]), 'real_device_preflight_baseline'))->toBe('FAIL');
});

it('fails when verified boot is not green', function () {
    expect(preflightStatus(preflightWithEvidence(['verified_boot_state' => 'orange']), 'real_device_preflight_baseline'))->toBe('FAIL');
});

it('fails when the bootloader or vbmeta is unlocked', function () {
    expect(preflightStatus(preflightWithEvidence(['bootloader_locked' => false]), 'real_device_preflight_baseline'))->toBe('FAIL');
    expect(preflightStatus(preflightWithEvidence(['vbmeta_locked' => false]), 'real_device_preflight_baseline'))->toBe('FAIL');
});

it('fails when the probe is claimed to have been committed or an identity enrolled', function () {
    // E5. A production identity generated during a preflight would mean the
    // tablet was enrolled, which this task must never do.
    expect(preflightStatus(preflightWithEvidence(['production_device_identity_created' => true]), 'preflight_unlocks_nothing'))->toBe('FAIL');
    expect(preflightStatus(preflightWithEvidence(['keyinfo_probe_committed' => true]), 'preflight_unlocks_nothing'))->toBe('FAIL');
});

it('fails when a preflight pass is read as unlocking signing or enforcement', function () {
    // E6/E7/E8. Each of these is a separate false claim, and each is refused.
    foreach (['production_signing_key_provisioning', 'certificate_pinning', 'production_apk_installation', 'production_enrollment', 'pilot_enforcement_activation', 'global_enforcement_activation', 'signing_custody_completion'] as $key) {
        $preflight = (array) config('android_release.real_device_preflight');
        $preflight['unlocks'][$key] = true;
        config()->set('android_release.real_device_preflight', $preflight);

        expect(preflightStatus(preflightScan(), 'preflight_unlocks_nothing'))
            ->toBe('FAIL', "Claiming '{$key}' was unlocked did not fail the gate.");

        config()->set('android_release.real_device_preflight', null);
        $this->refreshApplication();
    }
});

// ---------------------------------------------------------------------------
// Vacuity — the ways a clause can pass without meaning anything
//
// Found by adversarial review of this very gate, which is the point: a check
// that is only ever fed the correct value has not been shown to reject
// anything, and the three shapes below all reported PASS on a claim that was
// not met.
// ---------------------------------------------------------------------------

it('fails when the boot state is deleted rather than answered', function () {
    // The boot clause is the only one comparing config to config, so before
    // the fix `null === null` let the check PASS with NO boot state recorded —
    // an unknown or orange device passing by having the field removed. The
    // wrong-value case was already covered; deletion was not.
    expect(preflightStatus(preflightWithoutEvidenceKey('verified_boot_state'), 'real_device_preflight_baseline'))->toBe('FAIL');

    // The requirement side must not be deletable either.
    $preflight = (array) config('android_release.real_device_preflight');
    unset($preflight['verified_boot_state_required']);
    config()->set('android_release.real_device_preflight', $preflight);

    expect(preflightStatus(preflightScan(), 'real_device_preflight_baseline'))->toBe('FAIL');
});

it('fails when any other baseline fact is deleted rather than answered', function () {
    foreach (['physical_device', 'emulator', 'bootloader_locked', 'vbmeta_locked', 'device_label'] as $key) {
        expect(preflightStatus(preflightWithoutEvidenceKey($key), 'real_device_preflight_baseline'))
            ->toBe('FAIL', "Deleting '{$key}' left the baseline check green.");

        $this->refreshApplication();
    }
});

it('refuses a probe count that is not actually a count', function () {
    // PHP 8 makes 'many' >= 1 and true >= 1 both true, so a loose comparison
    // let an arbitrary string assert that the KeyInfo probe had run — the one
    // clause separating a measurement from an assertion.
    foreach (['many', '9x', true, '1 (approximately)'] as $bogus) {
        expect(preflightStatus(preflightWithEvidence(['keyinfo_tests_run' => $bogus]), 'key_security_level_accepted'))
            ->toBe('FAIL', 'A non-integer probe count was accepted as proof the probe ran.');

        $this->refreshApplication();
    }

    expect(preflightStatus(preflightWithoutEvidenceKey('keyinfo_tests_run'), 'key_security_level_accepted'))->toBe('FAIL');
});

it('refuses a truthy unlock, not merely a boolean true one', function () {
    // A strict search for `true` misses 1, 1.0, 'true' and 'yes' — every one
    // of which reads to a human reviewer as unlocked while leaving the record
    // green. The containment record must be exactly false, or nothing.
    foreach ([1, 1.0, 'true', 'yes', 'pending'] as $truthy) {
        $preflight = (array) config('android_release.real_device_preflight');
        $preflight['unlocks']['pilot_enforcement_activation'] = $truthy;
        config()->set('android_release.real_device_preflight', $preflight);

        $report = preflightScan();

        expect(preflightStatus($report, 'preflight_unlocks_nothing'))
            ->toBe('FAIL', 'A truthy non-boolean unlock claim was not detected.');

        // And it says which one, so the operator is not left guessing.
        expect(collect($report['checks'])->firstWhere('id', 'preflight_unlocks_nothing')['detail'])
            ->toContain('pilot_enforcement_activation');

        $this->refreshApplication();
    }
});

it('fails when the containment record is emptied instead of answered', function () {
    $preflight = (array) config('android_release.real_device_preflight');
    $preflight['unlocks'] = [];
    config()->set('android_release.real_device_preflight', $preflight);

    expect(preflightStatus(preflightScan(), 'preflight_unlocks_nothing'))->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// Containment — what a hardware pass must not have moved
// ---------------------------------------------------------------------------

it('provisions no production key and pins no certificate', function () {
    // E7/E8. The hardware preflight unlocked no signing step, and that is what
    // this asserts.
    //
    // PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 later generated the key,
    // on a separate authority, so `production_signing_key_provisioned` is now
    // true and asserting it false here would pin a stale fact rather than this
    // suite's own invariant.
    //
    // PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1 then armed the PIN, also on
    // a separate authority, and the same reasoning applies a second time —
    // more sharply, because this suite had explicitly named the pin as the
    // thing it would keep asserting. Keeping `toBeNull()` here would not have
    // been conservatism: it would have made an authorised pinning decision
    // read as a hardware preflight that unlocked signing, which is a false
    // accusation on the exact message an operator would act on hardest.
    //
    // What this suite can still honestly assert is that the anchor in force is
    // the AUTHORISED one — the certificate custody records — and not something
    // this preflight introduced. That is the invariant, and it is the same one
    // `preflight_unlocks_nothing` now enforces.
    expect(config('android_release.signing.production_certificate_sha256'))
        ->toBe(config('android_release.signing.production_certificate_sha256_recorded'));
    expect(config('android_release.signing.production_certificate_pin_required_before_install'))->toBeTrue();
    expect(preflightCheck('preflight_unlocks_nothing')['status'])->toBe('PASS');

    // The preflight's own containment record: it unlocked none of these,
    // whatever a later authorised task went on to do.
    expect(config('android_release.real_device_preflight.unlocks'))
        ->toMatchArray([
            'production_signing_key_provisioning' => false,
            'certificate_pinning' => false,
            'production_apk_build' => false,
        ]);
});

it('keeps every enforcement switch off after the hardware gate closes', function () {
    // E6. drg Karmila is authorized, and nobody is enforced.
    expect(config('android_release.enforcement.active'))->toBeFalse();
    expect(config('android_release.enforcement.doctor_browser_login_denied'))->toBeFalse();
    expect(config('android_release.enforcement.current_stage'))->toBe('off');
    expect(config('android_release.enforcement.owner_signoff.phase_4a_pilot_authorized'))->toBeTrue();
    expect(config('android_release.enforcement.owner_signoff.pilot_activated'))->toBeFalse();
    expect(config('feature_flags.flags')['doctor.trusted_device_enforcement']['default'])->toBeFalse();

    // Global stays a phase away, and the pilot bound is not the global one.
    expect(config('android_release.enforcement.global_may_be_enabled_in_phase'))->toBe(5);
    expect(config('android_release.enforcement.pilot_may_be_enabled_in_phase'))->toBe('4A');
});

it('does not let the hardware preflight be read as the real-device pilot validation', function () {
    // The two lines sit next to each other in the command output and mean very
    // different things. The pilot — signed release installed, device enrolled,
    // pilot run — has NOT happened, and must keep saying so.
    $summary = preflightScan()['summary'];

    expect($summary['real_device_validation'])->toBeFalse();
    expect($summary['real_device_hardware_preflight'])->toBeTrue();
    expect($summary['device_enforcement_active'])->toBeFalse();
});

it('keeps the historical phase 4 and 4A closures blocked rather than rewritten', function () {
    // E10. A later gate passing does not retroactively turn an earlier
    // blocked attempt into a GO.
    foreach ([
        'docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-4-blocked.md',
        'docs/sprints/feature-doctor-trusted-android-device-lock-1-phase-4a-blocked.md',
    ] as $doc) {
        expect(is_file(base_path($doc)))->toBeTrue("Historical closure {$doc} was removed.");
        expect(strtolower(file_get_contents(base_path($doc))))->toContain('blocked');
    }
});

it('keeps the evidence document present and required', function () {
    expect(config('android_release.scanner.required_documents'))
        ->toContain('docs/sprints/evidence-phase4a-real-device-keyinfo-preflight-1.md');

    expect(preflightCheck('governance_documents_present')['status'])->toBe('PASS');
});

it('registers this suite in the critical gate so something selects it', function () {
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/DoctorDevice/DoctorDeviceAndroidRealDevicePreflightTest.php');
});
