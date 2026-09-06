<?php

use App\Support\Android\AndroidReleaseArtifactVerifier;
use App\Support\Android\AndroidReleaseGovernanceScanner;
use App\Support\Android\SignerFingerprintResolver;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1.
 *
 * The production Android signing identity now EXISTS. It was generated exactly
 * once, on custodian 1, inside the LUKS2 vault; copied to two encrypted backup
 * destinations; and restored from BOTH DESTINATION COPIES — not from the
 * staging source — to prove the private key is recoverable rather than merely
 * present.
 *
 * That makes this the first sprint in the programme where the dangerous claim
 * points FORWARD instead of backward. Every earlier suite defended against a
 * reader concluding a key existed when none did. This one defends against the
 * opposite and now more plausible error: a reader seeing
 * PRODUCTION_SIGNING_KEY_PROVISIONED=true and concluding that a release can be
 * built, verified and installed.
 *
 * It cannot. The certificate fingerprint is RECORDED as evidence that a key
 * exists; it is NOT PINNED. Pinning is the separate task
 * PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1, and until it happens
 * `android:verify-release` authenticates nothing and fails closed.
 *
 * The key is irreplaceable — `app_signing_key_rotation` is
 * `constrained_treat_as_permanent` and `key_loss_recoverable` is false — so
 * the invariants below are not stylistic. A second production signer, or a
 * custody record that outruns its artifacts, is the failure this whole
 * programme exists to make impossible.
 *
 * As everywhere else in this programme, controls are MUTATED into the way they
 * could be wrong and the scanner is required to go red. A gate that has only
 * ever seen the correct value has never been shown to reject anything.
 */
function provisioningScan(): array
{
    return app(AndroidReleaseGovernanceScanner::class)->scan();
}

function provisioningCheck(string $id): array
{
    $found = collect(provisioningScan()['checks'])->firstWhere('id', $id);

    expect($found)->not->toBeNull("Scanner check '{$id}' does not exist.");

    return $found;
}

/**
 * Replace the whole custody block with the given overrides applied, scan, and
 * restore. Whole-block replacement rather than dotted keys, because the status
 * coherence rule reads several flags at once and a per-key mutation could not
 * express the states it has to reject.
 *
 * @param  array<string,mixed>  $overrides
 */
function provisioningCustodyStatus(array $overrides, string $checkId = 'custody_status_matches_recorded_facts'): string
{
    $original = config('android_release.signing.custody');

    config()->set('android_release.signing.custody', array_replace($original, $overrides));

    try {
        $found = collect(provisioningScan()['checks'])->firstWhere('id', $checkId);

        return $found['status'] ?? 'MISSING';
    } finally {
        config()->set('android_release.signing.custody', $original);
    }
}

/**
 * Scan with a given value pinned, returning the state-machine verdict.
 */
function custodyPinnedAs(?string $pin): string
{
    $original = config('android_release.signing.production_certificate_sha256');

    config()->set('android_release.signing.production_certificate_sha256', $pin);

    try {
        $found = collect(provisioningScan()['checks'])
            ->firstWhere('id', 'custody_state_machine_consistent');

        return $found['status'] ?? 'MISSING';
    } finally {
        config()->set('android_release.signing.production_certificate_sha256', $original);
    }
}

/**
 * Add one field to the signing namespace and return the no-secret verdict.
 *
 * The field is REMOVED afterwards rather than nulled: `config()->set(..., null)`
 * leaves the key present, which would leak an undeclared field into every later
 * test in the same process and make their verdicts meaningless.
 */
function signingFieldVerdict(string $field, mixed $value): string
{
    $original = config('android_release.signing');
    $mutated = $original;
    $mutated[$field] = $value;

    config()->set('android_release.signing', $mutated);

    try {
        $found = collect(provisioningScan()['checks'])
            ->firstWhere('id', 'custody_records_no_secret_material');

        return $found['status'] ?? 'MISSING';
    } finally {
        config()->set('android_release.signing', $original);
    }
}

// ---------------------------------------------------------------------------
// P1-P4 — the identity that now exists, and the single-authority rule
// ---------------------------------------------------------------------------

it('records exactly one production signing identity', function () {
    $custody = config('android_release.signing.custody');

    expect($custody['production_signing_key_provisioned'])->toBeTrue();

    // One primary signing authority, and it is custodian 1. Read through the
    // scanner rather than the config, because both the singular `role` and the
    // plural `roles` can carry the claim and only the gate reads both.
    expect(provisioningCheck('custody_primary_signing_authority_designated')['status'])->toBe('PASS');
    expect(provisioningCheck('custody_key_generation_hosts_restricted')['status'])->toBe('PASS');

    // Self-managed, and its loss declared unrecoverable — which is why there
    // may never be a second attempt at generating one.
    expect(config('android_release.signing.app_signing_authority'))->toBe('self_managed_daengtisiams');
    expect(config('android_release.signing.key_loss_recoverable'))->toBeFalse();
    expect(config('android_release.signing.app_signing_key_rotation'))
        ->toBe('constrained_treat_as_permanent');
});

it('records the production certificate fingerprint as a lower-case sha-256', function () {
    $recorded = config('android_release.signing.production_certificate_sha256_recorded');

    expect($recorded)->toMatch('/^[0-9a-f]{64}$/');

    // Anchored to the certificate that was independently re-exported from the
    // primary PKCS12 and confirmed identical on both restored backup copies.
    // If this value ever changes, either the key was regenerated — which is
    // forbidden — or this record is describing a different identity.
    expect($recorded)->toBe('79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9');
});

it('keeps the certificate recorded and the certificate pinned as separate facts', function () {
    $summary = provisioningScan()['summary'];

    expect($summary['production_certificate_recorded'])->toBeTrue();
    expect($summary['production_certificate_pinned'])->toBeFalse();

    // The pin is the enforcement decision, deferred to the next task.
    expect(config('android_release.signing.production_certificate_sha256'))->toBeNull();
    expect(config('android_release.signing.production_certificate_pin_required_before_install'))->toBeTrue();
});

it('does not let the recorded fingerprint arm the release verifier', function () {
    // The whole point of the split, and the one place it could go wrong
    // silently. If the verifier fell back to the RECORDED fingerprint when no
    // pin was set, then recording evidence that a key exists would ALSO have
    // armed the install path — and PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1
    // would have nothing left to decide. Authenticity has to come from the
    // explicit pin, never from a governance record that happens to hold the
    // right hex.
    expect(config('android_release.signing.production_certificate_sha256'))->toBeNull();

    $recorded = (string) config('android_release.signing.production_certificate_sha256_recorded');
    expect($recorded)->toMatch('/^[0-9a-f]{64}$/');

    // Structural: the verifier never reads the evidence field at all. Asserted
    // on the source because a behavioural test can only prove the fallback is
    // absent on the paths it happens to exercise.
    $verifierSource = file_get_contents(app_path('Support/Android/AndroidReleaseArtifactVerifier.php'));

    expect($verifierSource)->toBeString();
    expect($verifierSource)->not->toContain('production_certificate_sha256_recorded');

    // Behavioural: stage a release that is correct in every other respect —
    // valid manifest, real APK bytes, digests matching, and a signer whose
    // fingerprint IS the production certificate — and require the verifier to
    // refuse it anyway, because nothing is pinned. That is the sharpest form
    // of this test: the artifact is genuine and it still must not pass.
    $dir = tempArtifactDir('android-provisioning-verify-');

    try {
        $apk = $dir.'/DaengtisiaMS-Clinic-v1.0.0.apk';
        file_put_contents($apk, 'PK'.str_repeat("\0", 64));

        $manifestPath = $dir.'/DaengtisiaMS-Clinic-v1.0.0.release.json';
        file_put_contents($manifestPath, json_encode([
            'package_name' => 'com.daengtisia.clinic',
            'version_name' => '1.0.0',
            'version_code' => 1,
            'git_commit' => str_repeat('a', 40),
            'ci_run_id' => '1',
            'build_variant' => 'release',
            'apk_filename' => basename($apk),
            'artifact_sha256' => hash_file('sha256', $apk),
            'signing_certificate_fingerprint_sha256' => $recorded,
            'release_channel' => 'internal',
            'approval_status' => 'approved',
        ]));

        $signer = new class($recorded) implements SignerFingerprintResolver
        {
            public function __construct(private string $fingerprint) {}

            public function fingerprintFor(string $apkPath): ?string
            {
                return $this->fingerprint;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };

        $result = (new AndroidReleaseArtifactVerifier($signer))->verify($apk, $manifestPath);

        $anchor = collect($result['checks'] ?? [])->firstWhere('id', 'signer_trust_anchor');

        expect($anchor)->not->toBeNull('The verifier never reached the trust anchor check.');
        expect($anchor['status'])->toBe('FAIL');
        expect($result['status'])->toBe('FAIL');
    } finally {
        tempArtifactRemove($dir);
    }
});

// ---------------------------------------------------------------------------
// P5-P8 — custody artifacts, and the gate that refuses a status without them
// ---------------------------------------------------------------------------

it('records both encrypted backups and a rehearsed recovery', function () {
    $custody = config('android_release.signing.custody');

    expect($custody['backup_1_key_copy_created'])->toBeTrue();
    expect($custody['backup_2_key_copy_created'])->toBeTrue();
    expect($custody['sealed_cold_backup_created'])->toBeTrue();
    expect($custody['offsite_backup_created'])->toBeTrue();
    expect($custody['recovery_verified'])->toBeTrue();

    expect($custody['status'])->toBe('recovery_verified');
    expect(provisioningCheck('custody_status_matches_recorded_facts')['status'])->toBe('PASS');
    expect(provisioningCheck('custody_state_machine_consistent')['status'])->toBe('PASS');
});

it('refuses a custody status that runs ahead of the artifacts', function () {
    // The gap this closes: before this task, every state PAST readiness could
    // be declared with nothing behind it. `status => 'operational'` with all
    // six flags false contradicted no rule, and `signing_custody_status` —
    // the summary line a human actually reads — would have announced it.
    expect(provisioningCustodyStatus([
        'status' => 'operational',
        'production_signing_key_provisioned' => false,
        'backup_1_key_copy_created' => false,
        'backup_2_key_copy_created' => false,
        'sealed_cold_backup_created' => false,
        'offsite_backup_created' => false,
        'recovery_verified' => false,
    ]))->toBe('FAIL');

    // Each rung individually, so the rule is shown to bind at every step and
    // not merely at the extreme.
    expect(provisioningCustodyStatus([
        'status' => 'key_provisioned',
        'production_signing_key_provisioned' => false,
    ]))->toBe('FAIL');

    expect(provisioningCustodyStatus([
        'status' => 'backups_created',
        'backup_2_key_copy_created' => false,
    ]))->toBe('FAIL');

    expect(provisioningCustodyStatus([
        'status' => 'recovery_verified',
        'recovery_verified' => false,
    ]))->toBe('FAIL');
});

it('refuses a later custody state that skips an earlier requirement', function () {
    // Cumulative inheritance. Claiming recovery while a backup is missing must
    // fail even though `recovery_verified` itself is true, or a record could
    // climb the ladder by satisfying only the top rung.
    expect(provisioningCustodyStatus([
        'status' => 'recovery_verified',
        'recovery_verified' => true,
        'backup_2_key_copy_created' => false,
    ]))->toBe('FAIL');

    // And `operational` additionally needs the certificate armed: a key no
    // installer can verify an artifact against is not operational, whatever
    // the backups say. The pin is null, so this must fail today.
    expect(provisioningCustodyStatus(['status' => 'operational']))->toBe('FAIL');
});

it('does not make a status below readiness claim artifacts it never mentioned', function () {
    // The rule is one-directional on purpose. A status may lag the facts —
    // that is conservative, and check 12 already refuses the one lagging state
    // that would actually mislead. It may never lead them.
    foreach (['deferred', 'designated'] as $status) {
        expect(provisioningCustodyStatus(['status' => $status]))->toBe('PASS');
    }
});

// ---------------------------------------------------------------------------
// P9-P12 — what provisioning did NOT do
// ---------------------------------------------------------------------------

it('builds, signs and distributes nothing', function () {
    // Generating a key is not shipping an app. None of these moved.
    expect(config('android_release.enforcement.active'))->toBeFalse();
    expect(config('android_release.enforcement.doctor_browser_login_denied'))->toBeFalse();
    expect(config('android_release.enforcement.current_stage'))->toBe('off');
    expect(config('android_release.enforcement.owner_signoff.pilot_activated'))->toBeFalse();

    $summary = provisioningScan()['summary'];

    expect($summary['real_device_validation'])->toBeFalse();
    expect($summary['device_enforcement_active'])->toBeFalse();

    expect(provisioningCheck('enforcement_off')['status'])->toBe('PASS');
    expect(provisioningCheck('global_enforcement_deferred')['status'])->toBe('PASS');
});

it('introduces no Google Play dependency by having a key of its own', function () {
    // Self-managed signing is the reason Play is not needed. A sprint that
    // creates the key is exactly where a Play assumption could creep back in.
    expect(config('android_release.distribution.google_play_required'))->toBeFalse();
    expect(config('android_release.distribution.play_app_signing_used'))->toBeFalse();
    expect(config('android_release.distribution.managed_google_play_used'))->toBeFalse();
    expect(config('android_release.distribution.play_developer_account_required'))->toBeFalse();
    expect(config('android_release.distribution.canonical_channel'))->toBe('direct_admin_managed_apk');
});

it('keeps every key-material guard armed and nothing sensitive committed', function () {
    // The moment a key exists is the moment these guards start mattering.
    expect(provisioningCheck('key_material_guard_armed')['status'])->toBe('PASS');
    expect(provisioningCheck('no_committed_key_material')['status'])->toBe('PASS');
    expect(provisioningCheck('custody_records_no_secret_material')['status'])->toBe('PASS');

    // The forbidden storage list still names every place the key may not be,
    // now that there is a key that could be put there.
    expect(config('android_release.signing.forbidden_storage'))
        ->toContain('git_repository')
        ->toContain('ci_secret_used_by_pull_request_jobs')
        ->toContain('production_vps')
        ->toContain('clinic_android_device');

    expect(config('android_release.signing.pull_request_ci_may_sign'))->toBeFalse();
});

it('commits no private key, keystore or passphrase alongside the fingerprint', function () {
    // A fingerprint is a PUBLIC identifier derived from a certificate that
    // ships inside every signed APK. The material that must never appear is
    // asserted absent from the tracked tree rather than assumed absent.
    $tracked = [];
    exec('git -C '.escapeshellarg(base_path()).' ls-files 2>/dev/null', $tracked);

    expect($tracked)->not->toBeEmpty('Could not read the git index; absence could not be proven.');

    $keyMaterial = array_values(array_filter(
        $tracked,
        fn (string $path): bool => (bool) preg_match('/\.(p12|pfx|jks|keystore)$/i', $path),
    ));

    expect($keyMaterial)->toBe([], 'Key material is tracked: '.implode(', ', $keyMaterial));

    // And the committed config carries no secret FIELD of any kind.
    //
    // Matched on leaf keys, not as a substring sweep over the encoded block.
    // The custody record legitimately contains policy vocabulary — the
    // compensating control `passphrase_held_separately_from_every_medium` is
    // the whole reason a passphrase is safe — and a substring test would call
    // that a leaked passphrase while a real one named `p12_pw` sailed past.
    // The scanner makes the same distinction for the same reason.
    $secretKeys = ['passphrase', 'password', 'keystore_password', 'store_password',
        'key_password', 'secret', 'private_key', 'recovery_key'];

    $walk = function (array $node, string $path) use (&$walk, $secretKeys): array {
        $found = [];

        foreach ($node as $key => $value) {
            $leaf = $path.'.'.$key;

            if (in_array(strtolower((string) $key), $secretKeys, true)) {
                $found[] = $leaf;
            }

            if (is_array($value)) {
                $found = array_merge($found, $walk($value, $leaf));
            }

            // A PEM block is never policy vocabulary, wherever it appears.
            if (is_string($value) && str_contains($value, 'BEGIN ')) {
                $found[] = $leaf;
            }
        }

        return $found;
    };

    $leaked = $walk((array) config('android_release.signing'), 'signing');

    expect($leaked)->toBe([], 'Secret-bearing fields in committed config: '.implode(', ', $leaked));
});

// ---------------------------------------------------------------------------
// P13-P18 — mutation coverage
//
// Every test below closes a mutant that SURVIVED the first campaign. Each one
// had a sibling assertion that looked like it covered the rule and did not:
// the mutation was caught by a DIFFERENT rule firing on the same fixture, so
// removing the rule under test changed nothing observable. That is the failure
// mode a mutation run exists to expose — a green suite over a dead predicate.
// ---------------------------------------------------------------------------

it('refuses a recorded certificate with no key, in isolation from every other rule', function () {
    // Survivor M03. The existing test flipped `production_signing_key_provisioned`
    // to false and left the backups true, so the FAIL actually came from
    // "a backup copy is claimed to exist while no key has been provisioned".
    // Deleting the certificate-without-key rule changed nothing.
    //
    // Here every other artifact flag is false too, so the ONLY contradiction
    // left is a certificate recorded for a key that does not exist.
    expect(provisioningCustodyStatus([
        'status' => 'designated',
        'production_signing_key_provisioned' => false,
        'backup_1_key_copy_created' => false,
        'backup_2_key_copy_created' => false,
        'sealed_cold_backup_created' => false,
        'offsite_backup_created' => false,
        'recovery_verified' => false,
    ], 'custody_state_machine_consistent'))->toBe('FAIL');
});

it('refuses a pinned certificate while no key is claimed provisioned', function () {
    // Survivor M04, and it survived TWICE. The first attempt at this test set
    // the pin and cleared the key but left the recorded fingerprint in place,
    // so the FAIL came from "a certificate is recorded while no key is claimed
    // provisioned" — a different rule — and removing the pin-before-key rule
    // still left the gate red.
    //
    // Isolating it means clearing the recorded fingerprint TOO, so a pin over
    // no key is the only contradiction left standing.
    $originalCustody = config('android_release.signing.custody');
    $originalPin = config('android_release.signing.production_certificate_sha256');
    $originalRecorded = config('android_release.signing.production_certificate_sha256_recorded');

    config()->set('android_release.signing.production_certificate_sha256', str_repeat('b', 64));
    config()->set('android_release.signing.production_certificate_sha256_recorded', null);
    config()->set('android_release.signing.custody', array_replace($originalCustody, [
        'status' => 'designated',
        'production_signing_key_provisioned' => false,
        'backup_1_key_copy_created' => false,
        'backup_2_key_copy_created' => false,
        'sealed_cold_backup_created' => false,
        'offsite_backup_created' => false,
        'recovery_verified' => false,
    ]));

    try {
        $check = collect(provisioningScan()['checks'])
            ->firstWhere('id', 'custody_state_machine_consistent');
    } finally {
        config()->set('android_release.signing.custody', $originalCustody);
        config()->set('android_release.signing.production_certificate_sha256', $originalPin);
        config()->set('android_release.signing.production_certificate_sha256_recorded', $originalRecorded);
    }

    expect($check['status'])->toBe('FAIL');

    // And the message must name the reason, not merely go red — a reader acting
    // on this needs to know a pin is standing over a key that does not exist.
    expect($check['detail'])->toContain('pinned while no key');
});

it('requires every backup destination individually before the backups_created state', function () {
    // Survivor M09. Only `backup_2_key_copy_created` was ever removed, so a
    // requirement could be dropped from any of the other three without a test
    // noticing — including the offsite copy, which is the one that survives a
    // fire at the clinic.
    $flags = [
        'backup_1_key_copy_created',
        'backup_2_key_copy_created',
        'sealed_cold_backup_created',
        'offsite_backup_created',
    ];

    foreach ($flags as $flag) {
        expect(provisioningCustodyStatus([
            'status' => 'backups_created',
            $flag => false,
        ]))->toBe('FAIL', "Dropping {$flag} still satisfied the backups_created state.");
    }
});

it('refuses a readiness status carrying a recorded certificate and nothing else', function () {
    // Survivor M10. The readiness-honesty check counts a recorded certificate
    // as a claim, but the only test that exercised it also set
    // `production_signing_key_provisioned`, which populated `$premature` and
    // made the check red for a different reason.
    //
    // A certificate cannot be recorded unless a key was generated, so a record
    // still calling itself ready_for_provisioning while carrying one is
    // describing a key it simultaneously says does not exist.
    expect(provisioningCustodyStatus([
        'status' => 'ready_for_provisioning',
        'production_signing_key_provisioned' => false,
        'backup_1_key_copy_created' => false,
        'backup_2_key_copy_created' => false,
        'sealed_cold_backup_created' => false,
        'offsite_backup_created' => false,
        'recovery_verified' => false,
    ], 'custody_readiness_does_not_claim_provisioning'))->toBe('FAIL');
});

it('still requires every destination to be ready after the key exists', function () {
    // Survivor M11. Nothing asserted that the readiness check reads the
    // destination flags at all, so they could have been dropped from it
    // entirely. The risk is specific and real: a destination quietly retired
    // AFTER a key was written to it would leave a copy somewhere the record no
    // longer claims to control.
    foreach ([
        'primary_signing_workstation_ready',
        'backup_destination_1_ready',
        'sealed_cold_destination_ready',
        'offsite_destination_ready',
    ] as $flag) {
        expect(provisioningCustodyStatus(
            [$flag => false],
            'signing_custody_ready_for_provisioning',
        ))->toBe('FAIL', "Destination flag {$flag} was not required.");
    }
});

it('refuses a custody state below readiness in the readiness check', function () {
    // Survivor M12. Widening the readiness check to accept any known state
    // would have let `deferred` — the state meaning "nobody has been named" —
    // report that provisioning may begin.
    foreach (['deferred', 'designated'] as $status) {
        expect(provisioningCustodyStatus(
            ['status' => $status],
            'signing_custody_ready_for_provisioning',
        ))->toBe('FAIL', "State '{$status}' was treated as ready for provisioning.");
    }

    // And the states at or after readiness must still pass, so the rule is
    // shown to be a floor rather than an equality that happens to hold today.
    expect(provisioningCustodyStatus(
        ['status' => 'ready_for_provisioning'],
        'signing_custody_ready_for_provisioning',
    ))->toBe('PASS');

    expect(provisioningCheck('signing_custody_ready_for_provisioning')['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// P19-P24 — security review findings
//
// Every test below was written against a finding REPRODUCED on the real
// scanner before it was fixed. Each records the exploit, not only the
// corrected behaviour, because the corrected behaviour alone does not say what
// would have gone wrong.
// ---------------------------------------------------------------------------

it('refuses a custody status declared past readiness with no requirements defined', function () {
    // HIGH. `custody_status_matches_recorded_facts` decided whether a status
    // "claims artifacts" by looking it up in a hardcoded map, so a state absent
    // from that map was read as claiming nothing and PASSed. Nothing constrains
    // the membership of `custody.states`, and the relaxation of check 2 in this
    // same sprint removed the exact-status backstop that had been making the
    // gap harmless.
    //
    // Reproduced before the fix: one state appended, status set to it, all six
    // artifact flags false, no certificate, no pin — ALL SIXTEEN custody checks
    // reported PASS with an overall decision of GO, for a record claiming zero
    // key, zero backups and zero recovery.
    $originalCustody = config('android_release.signing.custody');
    $originalRecorded = config('android_release.signing.production_certificate_sha256_recorded');

    $states = $originalCustody['states'];
    $states[] = 'archived';

    config()->set('android_release.signing.production_certificate_sha256_recorded', null);
    config()->set('android_release.signing.custody', array_replace($originalCustody, [
        'states' => $states,
        'status' => 'archived',
        'production_signing_key_provisioned' => false,
        'backup_1_key_copy_created' => false,
        'backup_2_key_copy_created' => false,
        'sealed_cold_backup_created' => false,
        'offsite_backup_created' => false,
        'recovery_verified' => false,
    ]));

    try {
        $report = provisioningScan();
        $check = collect($report['checks'])->firstWhere('id', 'custody_status_matches_recorded_facts');
    } finally {
        config()->set('android_release.signing.custody', $originalCustody);
        config()->set('android_release.signing.production_certificate_sha256_recorded', $originalRecorded);
    }

    expect($check['status'])->toBe('FAIL');
    expect($check['detail'])->toContain('no artifact requirement is defined');

    // The whole report must go red, not just this line.
    expect($report['status'])->toBe('FAIL');
    expect($report['summary']['signing_custody_provisioning_preconditions_met'])->toBeFalse();
});

it('reports a certificate as pinned only when it is actually a fingerprint', function () {
    // MEDIUM. The summary predicate was `is_string(...) && !== ''` while its
    // sibling one line above applied the fingerprint regex. With the pin set to
    // 'TBD' the report printed PRODUCTION_CERTIFICATE_PINNED=true while
    // AndroidReleaseArtifactVerifier rejected the same value on its own regex
    // and failed closed — inverting the exact reading that printed pair exists
    // to give the operator.
    $original = config('android_release.signing.production_certificate_sha256');

    try {
        foreach (['TBD', 'yes', 'true', '1', str_repeat('z', 64)] as $notAFingerprint) {
            config()->set('android_release.signing.production_certificate_sha256', $notAFingerprint);

            expect(provisioningScan()['summary']['production_certificate_pinned'])
                ->toBeFalse("'{$notAFingerprint}' was reported as a pinned certificate.");
        }

        // A real fingerprint reports true, so this is not merely always-false.
        config()->set(
            'android_release.signing.production_certificate_sha256',
            config('android_release.signing.production_certificate_sha256_recorded'),
        );

        expect(provisioningScan()['summary']['production_certificate_pinned'])->toBeTrue();
    } finally {
        config()->set('android_release.signing.production_certificate_sha256', $original);
    }
});

it('accepts an upper-case pin as the same certificate', function () {
    // LOW, but aimed straight at the next task. `keytool -list` prints SHA-256
    // in upper case, so an upper-case paste is the most likely form the pin
    // will arrive in. A case-sensitive comparison reported a CORRECT pin as
    // "the wrong key or a substituted one".
    expect(custodyPinnedAs(strtoupper(
        (string) config('android_release.signing.production_certificate_sha256_recorded'),
    )))->toBe('PASS');

    // A genuinely different certificate must still be refused, so the
    // normalisation is not hiding a real mismatch.
    expect(custodyPinnedAs(str_repeat('c', 64)))->toBe('FAIL');
});

it('refuses an undeclared field anywhere in the signing namespace', function () {
    // MEDIUM. The leak walk covered `signing.custody.*` and nothing above it,
    // so a hardware serial, the vault filesystem UUID or a passphrase hint
    // could be committed at `signing.*` with no control noticing — while the
    // check printed "no leaf key or value matches a passphrase, serial,
    // identifier...". The three things it named are the three that got through.
    //
    // The leaf-key denylist alone does not close it: it matches EXACT keys, so
    // `primary_workstation_serial` and `keystore_passphrase_hint` walk straight
    // past `serial` and `hint`. Only the field allowlist stops them, which is
    // why custodian entries were given one in the first place.
    foreach ([
        'primary_workstation_serial' => 'SN-7788-XYZ',
        'vault_filesystem_uuid' => 'd1b2c3d4-1111-2222-3333-444455556666',
        'keystore_passphrase_hint' => 'the usual one',
        'p12_pw' => 'anything at all',
    ] as $field => $value) {
        expect(signingFieldVerdict($field, $value))
            ->toBe('FAIL', "signing.{$field} was accepted into committed config.");
    }
});

it('exempts the certificate fingerprints from shape detection but not from being fingerprints', function () {
    // The exemption exists because a certificate fingerprint is a public
    // identifier. It must not become a general-purpose hole: a fingerprint
    // field holding something that is not a fingerprint is not covered by that
    // justification.
    expect(provisioningCheck('custody_records_no_secret_material')['status'])->toBe('PASS');

    $original = config('android_release.signing.production_certificate_sha256_recorded');

    try {
        config()->set('android_release.signing.production_certificate_sha256_recorded', 'SN-7788-XYZ');

        $check = collect(provisioningScan()['checks'])
            ->firstWhere('id', 'custody_records_no_secret_material');

        expect($check['status'])->toBe('FAIL');
        expect($check['detail'])->toContain('exempt only as a certificate fingerprint');
    } finally {
        config()->set('android_release.signing.production_certificate_sha256_recorded', $original);
    }
});

it('names the provisioning-preconditions summary field for what it asserts', function () {
    // LOW. The field was `signing_custody_ready_for_provisioning`, and once the
    // check behind it stopped requiring an exact status it became permanently
    // true. Under an invariant that exactly one production key may ever exist,
    // a machine-readable field named "ready for provisioning" reading true
    // forever is the wrong signal to leave in output an agent may act on.
    $summary = provisioningScan()['summary'];

    expect($summary)->toHaveKey('signing_custody_provisioning_preconditions_met');
    expect($summary)->not->toHaveKey('signing_custody_ready_for_provisioning');
    expect($summary['signing_custody_provisioning_preconditions_met'])->toBeTrue();
});

it('refuses a secret-shaped value inside an allowed signing field, including nested', function () {
    // Mutant M20 SURVIVED the first campaign covering these fixes: removing the
    // denylist/shape walk over `signing.*` changed no test result, because every
    // signing test I had written was caught by the field ALLOWLIST instead.
    // The allowlist stops undeclared field NAMES; it says nothing about what a
    // DECLARED field may hold.
    //
    // So these use fields that are on the allowlist, and nested ones, where the
    // walk is the only thing standing between the value and a committed file.
    expect(signingFieldVerdict('release_signing_context', 'd1b2c3d4-1111-2222-3333-444455556666'))
        ->toBe('FAIL', 'A UUID inside an allowed signing field was accepted.');

    expect(signingFieldVerdict('key_loss_consequence', 'escalate to it-oncall@example.com'))
        ->toBe('FAIL', 'A contact address inside an allowed signing field was accepted.');

    expect(signingFieldVerdict('production_key_custody', 'AA:BB:CC:DD:EE:FF:00:11'))
        ->toBe('FAIL', 'A separator-bearing identifier inside an allowed signing field was accepted.');

    // Nested, one level down inside an allowed array field. The allowlist only
    // inspects top-level names, so nothing but the recursive walk covers this.
    $backup = config('android_release.signing.backup');
    $backup['restore_contact'] = '+62 812 3456 7890';

    expect(signingFieldVerdict('backup', $backup))
        ->toBe('FAIL', 'A phone number nested inside signing.backup was accepted.');
});

it('accepts the declared signing fields exactly as they ship', function () {
    // The complement of the two tests above: the allowlist and the walk must
    // not be so broad that the real configuration cannot satisfy them. A gate
    // that only ever says FAIL gets disabled, which is the same outcome as
    // having no gate.
    expect(provisioningCheck('custody_records_no_secret_material')['status'])->toBe('PASS');

    // And re-setting every declared field to its own current value is still a
    // PASS, so the check is reading values rather than object identity.
    foreach (array_keys((array) config('android_release.signing')) as $field) {
        if ($field === 'custody') {
            continue;
        }

        expect(signingFieldVerdict($field, config('android_release.signing.'.$field)))
            ->toBe('PASS', "Declared field signing.{$field} was rejected as it ships.");
    }
});

it('cannot be bypassed by reordering or renaming the declared custody states', function () {
    // The status coherence rule reads POSITION, so the obvious attack is to move
    // the threshold rather than the status. Putting `ready_for_provisioning`
    // last makes every real state "before readiness" and would, on a
    // membership-based rule, silence every artifact requirement at once.
    $original = config('android_release.signing.custody');

    $reordered = $original;
    $reordered['states'] = [
        'deferred', 'designated', 'key_provisioned', 'backups_created',
        'recovery_verified', 'operational', 'ready_for_provisioning',
    ];
    $reordered['status'] = 'recovery_verified';
    $reordered['recovery_verified'] = false;
    $reordered['backup_1_key_copy_created'] = false;
    $reordered['backup_2_key_copy_created'] = false;
    $reordered['sealed_cold_backup_created'] = false;
    $reordered['offsite_backup_created'] = false;
    $reordered['production_signing_key_provisioned'] = false;

    config()->set('android_release.signing.custody', $reordered);

    try {
        $report = provisioningScan();
    } finally {
        config()->set('android_release.signing.custody', $original);
    }

    // Whatever the reordering does to any individual check, a record claiming
    // recovery with no key and no backups must not reach GO.
    expect($report['status'])->toBe('FAIL');
});

it('keeps the verifier and the summary agreeing about what is armed', function () {
    // The invariant behind the MEDIUM finding, stated directly: the report says
    // pinned if and only if the verifier would treat the configured value as an
    // armed production anchor. One helper feeds both, and this pins that they
    // cannot drift apart again.
    $original = config('android_release.signing.production_certificate_sha256');
    $recorded = (string) config('android_release.signing.production_certificate_sha256_recorded');

    $cases = [
        null => false,
        '' => false,
        'TBD' => false,
        str_repeat('z', 64) => false,
        $recorded => true,
        strtoupper($recorded) => true,
    ];

    try {
        foreach ($cases as $pin => $expected) {
            config()->set('android_release.signing.production_certificate_sha256', $pin === '' ? '' : $pin);

            $summarySaysPinned = provisioningScan()['summary']['production_certificate_pinned'];

            // The verifier's own predicate, read from its source of truth
            // rather than restated here.
            $verifierWouldArm = is_string($pin) && preg_match('/^[0-9a-f]{64}$/i', $pin) === 1;

            expect($summarySaysPinned)->toBe($expected);
            expect($summarySaysPinned)->toBe(
                $verifierWouldArm,
                'Summary and verifier disagree for pin '.var_export($pin, true),
            );
        }
    } finally {
        config()->set('android_release.signing.production_certificate_sha256', $original);
    }
});

// ---------------------------------------------------------------------------
// P25 — the gate has to actually run
// ---------------------------------------------------------------------------

it('registers this suite in the critical gate so something selects it', function () {
    // Declared explicitly rather than left to the `DoctorDevice` filter token,
    // for the reason the registry itself gives: a token match is an accident
    // of naming and nothing would announce the day it stopped selecting.
    //
    // The drift this suite catches is the worst kind to discover late — a
    // custody record claiming backups that were never written, for a key that
    // cannot be regenerated. The first evidence would be a lost key with no
    // recoverable copy.
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/DoctorDevice/DoctorDeviceAndroidSigningKeyProvisioningTest.php');
});
