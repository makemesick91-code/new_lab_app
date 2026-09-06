<?php

use App\Support\Android\AndroidCertificateFingerprint;
use App\Support\Android\AndroidReleaseArtifactVerifier;
use App\Support\Android\AndroidReleaseGovernanceScanner;
use App\Support\Android\SignerFingerprintResolver;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * PRODUCTION-ANDROID-SIGNING-CERTIFICATE-PIN-1.
 *
 * The production certificate is now PINNED. That single config line is the only
 * authority an installer verifies an artifact against, so this suite is the
 * security boundary rather than a formatting check.
 *
 * Three things had to be true before the pin could be armed, and each of them is
 * a test below rather than a claim in a document.
 *
 * ONE SEMANTIC, NOT THREE. Before this task the question "is a certificate
 * pinned?" had three different answers living in three places: the scanner's
 * `isCertificateFingerprint()` (case-insensitive regex), the verifier's own
 * inline copy of that regex, and the custody state machine's
 * `is_string($v) && $v !== ''`. Security review had already caught what that
 * costs — `'TBD'` reported PRODUCTION_CERTIFICATE_PINNED=true while the verifier
 * rejected it and failed closed, so the report and the control disagreed about
 * the one fact the report exists to convey. The fix then was to make two of the
 * three agree by hand. Hand-alignment does not survive the next edit, so the
 * semantics now live in ONE class and both callers ask it.
 *
 * The third predicate SURVIVES ON PURPOSE, and collapsing it would have been a
 * fail-open regression. `isPresent()` asks "does this field carry a claim?" and
 * `isValid()` asks "is that claim a fingerprint?". The custody state machine
 * needs the first: a pin of `'TBD'` set while no key is provisioned MUST trip
 * "a certificate is pinned while no key is claimed provisioned", and a
 * validity-only predicate would let that junk through silently. Two questions,
 * two predicates, named.
 *
 * WHAT THE PREFLIGHT DID NOT DO. `preflight_unlocks_nothing` used to assert the
 * pin was literally null. That was correct while the pin could only be null, and
 * it is the same shape of mistake the predecessor already had to correct once:
 * that check also used to claim "no production key", read no key flag, and went
 * silently untrue the moment a key was generated under a separate authority.
 * Asserting `null` forever would fail this task with the message "a real-device
 * preflight pass has been read as unlocking signing" — a false accusation about
 * a legitimate, separately authorised decision.
 *
 * It is NOT relaxed to nothing, because Rule 147 is that relaxing a predicate
 * orphans the gap a sibling was quietly covering. It is REPLACED by a stricter
 * state assertion: the pin must be absent OR be exactly the recorded production
 * certificate. A rogue anchor still fails. The test proving that is below.
 *
 * NO PRIVATE KEY. Every assertion here runs from public data. The certificate
 * fingerprint ships inside every signed APK; the private key, the keystore and
 * its passphrases are not in this repository, not on the production server and
 * not in CI, and nothing in this suite reads them.
 */

/** The one production signer. Recorded by the predecessor; pinned by this task. */
const PIN_CANONICAL = '79db269b7cd38e920b80efbcf2f59142721f1e57924d3048d07a862f34fea2d9';

function pinScan(): array
{
    return app(AndroidReleaseGovernanceScanner::class)->scan();
}

function pinCheckStatus(string $id, ?string $pin = PIN_CANONICAL): string
{
    $original = config('android_release.signing.production_certificate_sha256');

    config()->set('android_release.signing.production_certificate_sha256', $pin);

    try {
        $found = collect(pinScan()['checks'])->firstWhere('id', $id);

        expect($found)->not->toBeNull("Scanner check '{$id}' does not exist.");

        return $found['status'];
    } finally {
        config()->set('android_release.signing.production_certificate_sha256', $original);
    }
}

/** Whether the scanner REPORTS a pin, for an arbitrary configured value. */
function pinReported(mixed $value): bool
{
    $original = config('android_release.signing.production_certificate_sha256');

    config()->set('android_release.signing.production_certificate_sha256', $value);

    try {
        return pinScan()['summary']['production_certificate_pinned'];
    } finally {
        config()->set('android_release.signing.production_certificate_sha256', $original);
    }
}

function pinFakeSigner(?string $fingerprint, bool $available = true): SignerFingerprintResolver
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

/**
 * Stage a throwaway release, run the verifier against it, and delete every path
 * this function created. The directory is named and owned here rather than
 * discovered by a glob, so cleanup can never reach a file it did not write.
 *
 * @return array<int,array<string,mixed>>
 */
function pinVerify(mixed $configuredPin, ?string $signerFingerprint): array
{
    $dir = sys_get_temp_dir().'/pin1-apk-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    $apkName = 'DaengtisiaMS-Clinic-v1.0.0.apk';
    $apkPath = $dir.'/'.$apkName;
    $manifestPath = $dir.'/release.json';

    $original = config('android_release.signing.production_certificate_sha256');

    try {
        file_put_contents($apkPath, 'clinic-apk-bytes');
        file_put_contents($manifestPath, json_encode([
            'package_name' => 'com.daengtisia.clinic',
            'version_name' => '1.0.0',
            'version_code' => 2,
            'release_source_sha' => str_repeat('c', 40),
            'release_source_tree' => str_repeat('e', 40),
            'ci_run_id' => '12345',
            'build_variant' => 'release',
            'apk_filename' => $apkName,
            'artifact_sha256' => hash_file('sha256', $apkPath),
            'signing_certificate_fingerprint_sha256' => PIN_CANONICAL,
            'release_channel' => 'direct_admin_managed_apk',
            'approval_status' => 'approved',
        ]));

        config()->set('android_release.signing.production_certificate_sha256', $configuredPin);

        return (new AndroidReleaseArtifactVerifier(pinFakeSigner($signerFingerprint)))
            ->verify($apkPath, $manifestPath);
    } finally {
        config()->set('android_release.signing.production_certificate_sha256', $original);
        @unlink($apkPath);
        @unlink($manifestPath);
        @rmdir($dir);
    }
}

/** Whether ENFORCEMENT considers the anchor armed, for an arbitrary value. */
function pinArmed(mixed $value): bool
{
    return ! in_array('signer_trust_anchor', pinVerify($value, PIN_CANONICAL)['failures'], true);
}

/** Add one undeclared field to the signing namespace and return the verdict. */
function pinSigningField(string $field, mixed $value): string
{
    $original = config('android_release.signing');
    $mutated = $original;
    $mutated[$field] = $value;

    config()->set('android_release.signing', $mutated);

    try {
        $found = collect(pinScan()['checks'])->firstWhere('id', 'custody_records_no_secret_material');

        return $found['status'] ?? 'MISSING';
    } finally {
        config()->set('android_release.signing', $original);
    }
}

/**
 * Every value that must NOT arm the anchor.
 *
 * `'TBD'` is here because it is not hypothetical: security review set the pin to
 * exactly that and the report and the verifier disagreed about what it meant.
 *
 * @return array<string,mixed>
 */
function pinRejectedVectors(): array
{
    return [
        'null' => null,
        'empty string' => '',
        'whitespace' => '   ',
        'TBD' => 'TBD',
        'lowercase tbd' => 'tbd',
        'TODO' => 'TODO',
        'PENDING' => 'PENDING',
        'the word none' => 'none',
        'the word null' => 'null',
        'the word yes' => 'yes',
        'boolean true' => true,
        'an integer' => 1,
        'an array' => [PIN_CANONICAL],
        '63 hex characters' => substr(PIN_CANONICAL, 0, 63),
        '65 hex characters' => PIN_CANONICAL.'a',
        'non-hex character' => substr(PIN_CANONICAL, 0, 63).'g',
        'embedded space' => substr(PIN_CANONICAL, 0, 32).' '.substr(PIN_CANONICAL, 33),
        'leading space' => ' '.PIN_CANONICAL,
        'trailing space' => PIN_CANONICAL.' ',
        'trailing newline' => PIN_CANONICAL."\n",
        'leading newline' => "\n".PIN_CANONICAL,
        'prefix junk' => 'sha256:'.PIN_CANONICAL,
        'suffix junk' => PIN_CANONICAL.' (production)',
        'SHA-1 length' => str_repeat('ab', 20),
        'partial fingerprint' => substr(PIN_CANONICAL, 0, 32),
        'colon separated' => strtoupper(implode(':', str_split(PIN_CANONICAL, 2))),
        '0x prefixed' => '0x'.PIN_CANONICAL,
    ];
}

// ---------------------------------------------------------------------------
// A. Not pinned — every one of these must fail closed
// ---------------------------------------------------------------------------

it('treats every non-fingerprint value as unpinned, in both the report and the control', function () {
    foreach (pinRejectedVectors() as $label => $value) {
        expect(AndroidCertificateFingerprint::isValid($value))
            ->toBeFalse("{$label} must not be a valid fingerprint.");

        expect(AndroidCertificateFingerprint::normalize($value))
            ->toBeNull("{$label} must not normalize to anything.");

        expect(pinReported($value))->toBeFalse("The scanner must not report {$label} as pinned.");
    }
});

it('refuses to authenticate an artifact when the anchor is not a fingerprint', function () {
    // Sampled rather than exhaustive: each of these runs a full verify() over a
    // staged artifact, and the semantics are already proven exhaustively above
    // through the shared helper both callers now use.
    foreach ([null, '', '   ', 'TBD', substr(PIN_CANONICAL, 0, 63), PIN_CANONICAL."\n"] as $value) {
        expect(pinArmed($value))->toBeFalse('The anchor must not arm on '.var_export($value, true));
    }
});

// ---------------------------------------------------------------------------
// B. The canonical pin
// ---------------------------------------------------------------------------

it('pins exactly the one production signing certificate', function () {
    expect(config('android_release.signing.production_certificate_sha256'))->toBe(PIN_CANONICAL);

    // The pin is THE certificate, not A certificate. If these two ever diverge,
    // the artifact an installer trusts is not the one the custody record
    // describes, and there is no benign reading of that.
    expect(config('android_release.signing.production_certificate_sha256_recorded'))->toBe(PIN_CANONICAL);

    expect(config('android_release.signing.production_certificate_pin_required_before_install'))->toBeTrue();
});

it('reports the pin as armed and lets the verifier authenticate', function () {
    expect(AndroidCertificateFingerprint::isValid(PIN_CANONICAL))->toBeTrue();
    expect(AndroidCertificateFingerprint::normalize(PIN_CANONICAL))->toBe(PIN_CANONICAL);
    expect(pinScan()['summary']['production_certificate_pinned'])->toBeTrue();
    expect(pinArmed(PIN_CANONICAL))->toBeTrue();

    expect(pinVerify(PIN_CANONICAL, PIN_CANONICAL)['failures'])->toBe([]);
});

// ---------------------------------------------------------------------------
// C. Case is representation, not identity
// ---------------------------------------------------------------------------

it('accepts the same fingerprint in any case without raising a substitution alarm', function () {
    $upper = strtoupper(PIN_CANONICAL);
    $mixed = ucfirst(PIN_CANONICAL);

    foreach ([$upper, $mixed] as $variant) {
        expect(AndroidCertificateFingerprint::isValid($variant))->toBeTrue();
        expect(AndroidCertificateFingerprint::normalize($variant))->toBe(PIN_CANONICAL);
        expect(AndroidCertificateFingerprint::matches($variant, PIN_CANONICAL))->toBeTrue();
        expect(AndroidCertificateFingerprint::matches(PIN_CANONICAL, $variant))->toBeTrue();
        expect(pinReported($variant))->toBeTrue();
        expect(pinArmed($variant))->toBeTrue();
    }

    // keytool prints SHA-256 in upper case, so an upper-case paste is the most
    // likely one. A correct pin differing only in case must not be reported as
    // "the wrong key or a substituted one" — that is the single message an
    // operator would act on hardest, and acting on it means not shipping.
    expect(pinCheckStatus('custody_state_machine_consistent', $upper))->toBe('PASS');
});

it('compares the recorded certificate case-insensitively too', function () {
    // The scanner's summary already accepted upper case here while the custody
    // state machine used a case-SENSITIVE regex on the same field. An
    // upper-case recorded value therefore reported RECORDED=true and, in the
    // same scan, "key claimed provisioned while no valid certificate
    // fingerprint is recorded". One field, two verdicts, both printed.
    $original = config('android_release.signing.production_certificate_sha256_recorded');

    config()->set(
        'android_release.signing.production_certificate_sha256_recorded',
        strtoupper(PIN_CANONICAL),
    );

    try {
        $scan = pinScan();
        $check = collect($scan['checks'])->firstWhere('id', 'custody_state_machine_consistent');

        expect($scan['summary']['production_certificate_recorded'])->toBeTrue();
        expect($check['status'])->toBe('PASS');
    } finally {
        config()->set('android_release.signing.production_certificate_sha256_recorded', $original);
    }
});

// ---------------------------------------------------------------------------
// D/E. Format is not negotiable
// ---------------------------------------------------------------------------

it('accepts only 64 contiguous hex characters, never a colon-separated form', function () {
    $colons = strtoupper(implode(':', str_split(PIN_CANONICAL, 2)));

    // The colon form is what keytool prints, so it WILL be pasted one day. The
    // accepted input is deliberately not widened to take it: a normalizer that
    // strips separators also silently accepts values a reviewer would reject,
    // and the config comment states the required form. It fails closed and the
    // operator reformats — which is the outcome that keeps one representation
    // canonical everywhere.
    expect(AndroidCertificateFingerprint::isValid($colons))->toBeFalse();
    expect(pinReported($colons))->toBeFalse();
    expect(pinArmed($colons))->toBeFalse();
});

// ---------------------------------------------------------------------------
// F. A substituted signer is not a near miss
// ---------------------------------------------------------------------------

it('rejects a fingerprint that differs by a single nibble', function () {
    $last = substr(PIN_CANONICAL, -1);
    $substituted = substr(PIN_CANONICAL, 0, 63).($last === '9' ? '8' : '9');

    expect($substituted)->not->toBe(PIN_CANONICAL);
    expect(AndroidCertificateFingerprint::isValid($substituted))->toBeTrue();
    expect(AndroidCertificateFingerprint::matches($substituted, PIN_CANONICAL))->toBeFalse();

    // Well-formed but not ours: the report says "pinned" because something is
    // pinned, and the state machine says it is the wrong thing.
    expect(pinCheckStatus('custody_state_machine_consistent', $substituted))->toBe('FAIL');

    // And at install time the artifact is refused.
    expect(pinVerify(PIN_CANONICAL, $substituted)['failures'])->toContain('signer_fingerprint');
});

it('never matches on a prefix, a suffix or a substring', function () {
    $prefix = substr(PIN_CANONICAL, 0, 32);

    expect(AndroidCertificateFingerprint::matches($prefix, PIN_CANONICAL))->toBeFalse();
    expect(AndroidCertificateFingerprint::matches(PIN_CANONICAL, $prefix))->toBeFalse();
    expect(AndroidCertificateFingerprint::matches(PIN_CANONICAL.'ab', PIN_CANONICAL))->toBeFalse();
    expect(AndroidCertificateFingerprint::matches('', ''))->toBeFalse();
    expect(AndroidCertificateFingerprint::matches(null, null))->toBeFalse();
});

// ---------------------------------------------------------------------------
// G. The report and the control cannot disagree
// ---------------------------------------------------------------------------

it('reports pinned if and only if the verifier is armed', function () {
    $vectors = ['TBD', '', '   ', null, PIN_CANONICAL, strtoupper(PIN_CANONICAL), substr(PIN_CANONICAL, 0, 63)];

    foreach ($vectors as $value) {
        expect(pinReported($value))->toBe(
            pinArmed($value),
            'Report and enforcement disagree about '.var_export($value, true),
        );
    }
});

// ---------------------------------------------------------------------------
// H. Nothing here needs the private key
// ---------------------------------------------------------------------------

it('verifies the pin from public data alone, with no keystore anywhere in reach', function () {
    // A fingerprint is derived from the certificate, and the certificate ships
    // inside every signed APK. Committing it discloses nothing. What this
    // asserts is the stronger property: the whole control evaluates with no
    // keystore, passphrase or PKCS12 anywhere in reach.
    //
    // It deliberately does NOT ban the WORD "passphrase" from the config. The
    // custody rules have to be able to say that signing material may never sit
    // beside its passphrase, and a test that forbids the vocabulary would
    // forbid the config from stating the rule. The scanner already owns the
    // question of secret VALUES, so this asks it rather than reinventing a
    // weaker version of it.
    expect(collect(pinScan()['checks'])->firstWhere('id', 'custody_records_no_secret_material')['status'])
        ->toBe('PASS');

    // No config key in the signing namespace names key material. The allowlist
    // is what enforces this; this is the standing assertion that the allowlist
    // never grows an entry that would make a key path lawful to record.
    foreach (['keystore_path', 'keystore_password', 'key_password', 'storepass', 'keypass', 'private_key_path'] as $forbiddenField) {
        expect(config('android_release.signing.'.$forbiddenField))->toBeNull(
            "The signing config must not carry a '{$forbiddenField}'.",
        );
    }

    // Config declares where a key may never live, and a repository is on it —
    // so the strongest available form of this assertion is that git agrees.
    expect(config('android_release.signing.forbidden_storage'))->toContain('git_repository');

    $tracked = shell_exec('cd '.escapeshellarg(base_path()).' && git ls-files "*.p12" "*.jks" "*.keystore" "*.pfx" 2>/dev/null');

    expect(trim((string) $tracked))->toBe('', 'A keystore is tracked in the repository.');

    // And the scan itself is a pure read: it reaches a verdict about the pin
    // with no key present in this process, this checkout or this environment.
    expect(pinScan()['summary']['production_certificate_pinned'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// I. The signing namespace stays an allowlist
// ---------------------------------------------------------------------------

it('refuses an undeclared field in the signing namespace', function () {
    // A denylist of exact leaf keys let `primary_workstation_serial` walk past
    // an entry named `serial`. These are the shapes that did it.
    foreach ([
        'primary_workstation_serial' => 'SN-0001',
        'vault_uuid' => '0d5f0000-0000-0000-0000-000000000000',
        'passphrase_hint' => 'the usual one',
        'keystore_secret' => 'hunter2',
        'private_key_hint' => 'in the drawer',
        'production_certificate_sha256_backup' => PIN_CANONICAL,
    ] as $field => $value) {
        expect(pinSigningField($field, $value))->toBe(
            'FAIL',
            "An undeclared signing field '{$field}' must fail closed.",
        );
    }
});

it('still accepts the declared fields, including the two public fingerprints', function () {
    expect(pinSigningField('production_certificate_sha256', PIN_CANONICAL))->toBe('PASS');
    expect(pinSigningField('production_certificate_sha256_recorded', PIN_CANONICAL))->toBe('PASS');
});

// ---------------------------------------------------------------------------
// J. The custody regressions the predecessor paid for stay fixed
// ---------------------------------------------------------------------------

it('keeps a certificate from being pinned ahead of the key it belongs to', function () {
    $original = config('android_release.signing.custody');

    config()->set(
        'android_release.signing.custody',
        array_replace($original, ['production_signing_key_provisioned' => false]),
    );

    try {
        $check = collect(pinScan()['checks'])->firstWhere('id', 'custody_state_machine_consistent');

        expect($check['status'])->toBe('FAIL');
        expect($check['detail'])->toContain('pinned while no key is claimed provisioned');
    } finally {
        config()->set('android_release.signing.custody', $original);
    }
});

it('counts junk in the pin field as a claim, so it cannot be written ahead of the key', function () {
    // The mutation that survived the first campaign, and the reason the
    // lifecycle keeps `isPresent()` instead of reusing `isValid()`.
    //
    // Tightening that one predicate to validity looks like a cleanup and is a
    // fail-open regression: `'TBD'` in the trust anchor stops being a CLAIM,
    // so "a certificate is pinned while no key is claimed provisioned" never
    // fires, and junk can be parked in the install trust root of a system with
    // no key at all. The earlier ordering test could not see it, because it
    // used a well-formed pin — for which both predicates agree.
    //
    // The recorded fingerprint is cleared too. Leaving it set would let the
    // sibling rule ("recorded while no key is provisioned") fail the check for
    // a different reason and mask the hole entirely, which is how a mutation
    // survives a suite that looks like it covers the case.
    $originalCustody = config('android_release.signing.custody');
    $originalRecorded = config('android_release.signing.production_certificate_sha256_recorded');
    $originalPin = config('android_release.signing.production_certificate_sha256');

    config()->set('android_release.signing.custody', array_replace($originalCustody, [
        'production_signing_key_provisioned' => false,
    ]));
    config()->set('android_release.signing.production_certificate_sha256_recorded', null);
    config()->set('android_release.signing.production_certificate_sha256', 'TBD');

    try {
        $check = collect(pinScan()['checks'])->firstWhere('id', 'custody_state_machine_consistent');

        expect($check['status'])->toBe('FAIL');
        expect($check['detail'])->toContain('pinned while no key is claimed provisioned');
    } finally {
        config()->set('android_release.signing.custody', $originalCustody);
        config()->set('android_release.signing.production_certificate_sha256_recorded', $originalRecorded);
        config()->set('android_release.signing.production_certificate_sha256', $originalPin);
    }
});

it('still fails an unknown custody state closed rather than passing sixteen checks on nothing', function () {
    // The HIGH finding: a relaxed status predicate let a state at or past
    // readiness with no requirements defined report ALL PASS and GO for a record
    // claiming zero key, zero backups and zero recovery.
    $original = config('android_release.signing.custody');

    config()->set('android_release.signing.custody', array_replace($original, [
        'status' => 'a_state_nobody_defined',
        'production_signing_key_provisioned' => false,
        'backup_1_key_copy_created' => false,
        'backup_2_key_copy_created' => false,
        'sealed_cold_backup_created' => false,
        'offsite_backup_created' => false,
        'recovery_verified' => false,
    ]));

    try {
        $scan = pinScan();

        expect($scan['status'])->not->toBe('GO');
    } finally {
        config()->set('android_release.signing.custody', $original);
    }
});

// ---------------------------------------------------------------------------
// The containment check, and the gap it must not orphan (Rule 147)
// ---------------------------------------------------------------------------

it('lets the hardware preflight stay contained now that a pin legitimately exists', function () {
    expect(pinCheckStatus('preflight_unlocks_nothing'))->toBe('PASS');
});

it('still fails containment for an anchor the preflight had no authority to introduce', function () {
    // This is the assertion that earns the right to stop reading `=== null`.
    // A well-formed pin that is not the recorded production certificate is
    // exactly what "the preflight introduced a trust anchor" would look like,
    // and it must still go red.
    $rogue = str_repeat('ab', 32);

    expect($rogue)->not->toBe(PIN_CANONICAL);
    expect(AndroidCertificateFingerprint::isValid($rogue))->toBeTrue();
    expect(pinCheckStatus('preflight_unlocks_nothing', $rogue))->toBe('FAIL');

    // And junk in the field is not "absent".
    expect(pinCheckStatus('preflight_unlocks_nothing', 'TBD'))->toBe('FAIL');
});

it('still fails containment if the preflight record claims it unlocked pinning', function () {
    $original = config('android_release.real_device_preflight.unlocks');

    config()->set(
        'android_release.real_device_preflight.unlocks',
        array_replace($original, ['certificate_pinning' => true]),
    );

    try {
        $check = collect(pinScan()['checks'])->firstWhere('id', 'preflight_unlocks_nothing');

        expect($check['status'])->toBe('FAIL');
        expect($check['detail'])->toContain('certificate_pinning');
    } finally {
        config()->set('android_release.real_device_preflight.unlocks', $original);
    }
});

// ---------------------------------------------------------------------------
// K. Pinning is one decision, and it is not the other five
// ---------------------------------------------------------------------------

it('arms the trust anchor and moves nothing downstream of it', function () {
    $summary = pinScan()['summary'];

    expect($summary['production_certificate_pinned'])->toBeTrue();

    // An APK has not been built, a tablet has not been touched, the pilot is
    // authorized but not activated, and global enforcement is not in force.
    // Each of these is a separate authorised decision and none of them is this.
    expect($summary['real_device_validation'])->toBeFalse();
    expect($summary['device_enforcement_active'])->toBeFalse();
    expect(config('android_release.enforcement.active'))->toBeFalse();
    expect(config('android_release.enforcement.current_stage'))->toBe('off');
    expect(config('android_release.enforcement.owner_signoff.pilot_activated'))->toBeFalse();

    expect(config('android_release.real_device_preflight.unlocks'))->toMatchArray([
        'production_apk_build' => false,
        'production_apk_installation' => false,
        'production_enrollment' => false,
        'pilot_enforcement_activation' => false,
        'global_enforcement_activation' => false,
    ]);
});

it('reaches a GO decision with the pin armed', function () {
    $scan = pinScan();

    expect($scan['summary']['failed'])->toBe(0);
    expect($scan['summary']['watch'])->toBe(0);
    expect($scan['status'])->toBe('GO');
});

// ---------------------------------------------------------------------------
// The gate has to select this suite, or none of the above protects anything
// ---------------------------------------------------------------------------

it('registers this suite in the critical gate so something selects it', function () {
    // A token match is an accident of naming. What this pins is the control that
    // stands between a substituted APK and a clinic tablet, so the gate has to
    // name the file rather than hope a filter catches it.
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/DoctorDevice/DoctorDeviceAndroidCertificatePinTest.php');
});
