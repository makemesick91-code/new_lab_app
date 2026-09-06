<?php

use App\Support\Android\AndroidReleaseGovernanceScanner;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * REVISION-PRODUCTION-SIGNING-CUSTODIAN1-ENCRYPTED-VAULT-1.
 *
 * The custody readiness record used to say `disk_encryption => true` for
 * custodian 1, and the release gate reported PASS on the strength of it. The
 * machine was then measured during the provisioning attempt and the claim did
 * not survive contact: `/` and `/home` mount straight from raw ext4
 * partitions, there is no `/dev/mapper` device, no LUKS signature, no eCryptfs
 * and no fscrypt. One ambiguous boolean had been standing in for a control
 * nobody had ever checked.
 *
 * This suite pins the correction, which is a SPLIT rather than a flip:
 *
 *   host_full_disk_encryption = false    <- the true fact about the host
 *   primary_secret_storage    = LUKS2    <- the control that protects the key
 *
 * Both halves matter. Recording only the first would say the workstation is
 * unfit to hold the key; recording only the second would repeat the original
 * sin of implying the whole machine is encrypted. The production keystore does
 * not exist yet, and every assertion here that says "storage is ready" is
 * paired with one that says "and no key, no backup and no pin exist".
 *
 * As with the sibling custody suite, correctness is not "the config says
 * PASS". Each clause of the vault record is mutated into the way it could be
 * wrong and the scanner is required to go red, because a gate that has only
 * ever seen the correct value has never been shown to reject anything.
 */
function vaultScan(): array
{
    return app(AndroidReleaseGovernanceScanner::class)->scan();
}

function vaultCheck(string $id): array
{
    $found = collect(vaultScan()['checks'])->firstWhere('id', $id);

    expect($found)->not->toBeNull("Scanner check '{$id}' does not exist.");

    return $found;
}

/**
 * Mutate one config key, scan, restore. Returns the scanner status for the
 * named check so a test can assert the gate actually rejected the mutation.
 */
function vaultMutate(string $configKey, mixed $badValue, string $checkId): string
{
    $original = config($configKey);

    config()->set($configKey, $badValue);

    try {
        $found = collect(vaultScan()['checks'])->firstWhere('id', $checkId);
        $status = $found['status'] ?? 'MISSING';
    } finally {
        config()->set($configKey, $original);
    }

    return $status;
}

function custodian1(): array
{
    return config('android_release.signing.custody.custodians.custodian_1');
}

const VAULT_PATH = 'android_release.signing.custody.custodians.custodian_1.primary_secret_storage';

// ---------------------------------------------------------------------------
// V1-V6 — the host/vault distinction, and what a vault has to prove
// ---------------------------------------------------------------------------

it('records the custodian 1 host as NOT full-disk encrypted', function () {
    // The whole point of the revision. If this ever reads true again, either
    // somebody encrypted the disk and updated the runbook, or somebody
    // reintroduced the original defect. The test forces that conversation.
    expect(custodian1()['host_full_disk_encryption'])->toBeFalse();

    expect(custodian1())->not->toHaveKey('disk_encryption');
});

it('does not fail endpoint controls merely because the host is unencrypted', function () {
    // V1. Host FDE false must be survivable when a verified vault exists,
    // otherwise the truthful record would be unusable and the pressure would
    // be to lie again.
    expect(custodian1()['host_full_disk_encryption'])->toBeFalse();

    expect(vaultCheck('custody_endpoint_controls_recorded')['status'])->toBe('PASS');
    expect(vaultCheck('custody_primary_secret_storage_encrypted')['status'])->toBe('PASS');
});

it('fails when the host is unencrypted and no vault is recorded', function () {
    // V2 / M1. The combination that must never pass.
    expect(vaultMutate(VAULT_PATH, null, 'custody_endpoint_controls_recorded'))->toBe('FAIL');
    expect(vaultMutate(VAULT_PATH, null, 'custody_primary_secret_storage_encrypted'))->toBe('FAIL');
});

it('fails when the vault record omits its type', function () {
    // V3.
    $vault = custodian1()['primary_secret_storage'];
    unset($vault['type']);

    expect(vaultMutate(VAULT_PATH, $vault, 'custody_primary_secret_storage_encrypted'))->toBe('FAIL');
});

it('rejects a vault encryption outside the approved list', function () {
    // V4 / M2 / M9. "Encrypted" must not be satisfiable by a value that only
    // sounds like it.
    foreach (['plaintext', 'plain', 'luks1', 'ecryptfs', 'zip', 'none', '', null] as $bogus) {
        $vault = custodian1()['primary_secret_storage'];
        $vault['encryption'] = $bogus;

        expect(vaultMutate(VAULT_PATH, $vault, 'custody_primary_secret_storage_encrypted'))
            ->toBe('FAIL', 'Encryption '.var_export($bogus, true).' was accepted.');
    }
});

it('rejects an unverified vault', function () {
    // V5 / M3. A vault nobody has opened, mounted, closed and reopened is a
    // belief, not a control.
    foreach ([false, null, 'true', 1] as $notVerified) {
        $vault = custodian1()['primary_secret_storage'];
        $vault['verified'] = $notVerified;

        expect(vaultMutate(VAULT_PATH, $vault, 'custody_primary_secret_storage_encrypted'))
            ->toBe('FAIL', 'verified='.var_export($notVerified, true).' was accepted.');
    }
});

it('rejects a vault that is open at rest, auto-unlocking or keyfile-backed', function () {
    // V6 / M4 / M16 / M17. Each of these turns an encrypted container back
    // into an ordinary directory in practice.
    $cases = [
        ['default_state', 'open'],
        ['default_state', 'mounted'],
        ['auto_unlock', true],
        ['plaintext_keyfile', true],
        ['outside_repository', false],
    ];

    foreach ($cases as [$field, $bad]) {
        $vault = custodian1()['primary_secret_storage'];
        $vault[$field] = $bad;

        expect(vaultMutate(VAULT_PATH, $vault, 'custody_primary_secret_storage_encrypted'))
            ->toBe('FAIL', "{$field}=".var_export($bad, true).' was accepted.');
    }
});

it('records the vault as closed at rest with no auto-unlock and no keyfile', function () {
    $vault = custodian1()['primary_secret_storage'];

    expect($vault['type'])->toBe('dedicated_encrypted_vault');
    expect($vault['encryption'])->toBe('luks2');
    expect($vault['verified'])->toBeTrue();
    expect($vault['default_state'])->toBe('closed');
    expect($vault['auto_unlock'])->toBeFalse();
    expect($vault['plaintext_keyfile'])->toBeFalse();
    expect($vault['outside_repository'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// V7-V12 — storage readiness implies nothing downstream
// ---------------------------------------------------------------------------

/**
 * PRODUCTION-ANDROID-SIGNING-KEY-PROVISIONING-1 rewrote V7-V9.
 *
 * They asserted "storage readiness implies nothing downstream" by pinning the
 * downstream facts to false. That worked only while they happened to be false;
 * once a key genuinely existed the assertions failed without anything about
 * the vault having changed, which is a stale-fact test rather than a claim
 * about the vault.
 *
 * The claim they were reaching for is INDEPENDENCE: the vault check must reach
 * its verdict from the vault record alone, and never borrow confidence from a
 * key, a backup or a recovery. That is testable directly and stays true in
 * every future programme state, including the one where all three are true.
 *
 * @param  array<string,mixed>  $overrides
 */
function vaultCheckWithCustody(array $overrides, string $id = 'custody_primary_secret_storage_encrypted'): string
{
    $original = config('android_release.signing.custody');

    config()->set('android_release.signing.custody', array_replace($original, $overrides));

    try {
        $found = collect(vaultScan()['checks'])->firstWhere('id', $id);

        return $found['status'] ?? 'MISSING';
    } finally {
        config()->set('android_release.signing.custody', $original);
    }
}

it('is valid for primary secret storage to be ready while no key is provisioned', function () {
    // V7. The vault verdict must be identical whether or not a key exists —
    // both directions, so the check is shown not to read the flag at all.
    expect(vaultCheck('custody_primary_secret_storage_encrypted')['status'])->toBe('PASS');

    expect(vaultCheckWithCustody(['production_signing_key_provisioned' => false]))->toBe('PASS');
    expect(vaultCheckWithCustody(['production_signing_key_provisioned' => true]))->toBe('PASS');
});

it('does not let storage readiness imply a production key exists', function () {
    // V8 / M11. Storage readiness is not a key, so the vault check must not
    // move when the lifecycle status does — and the readiness-honesty check
    // must still refuse a record that is ready_for_provisioning while claiming
    // a key, which is the misreading this pairing exists to prevent.
    foreach (['designated', 'ready_for_provisioning', 'key_provisioned', 'recovery_verified'] as $status) {
        expect(vaultCheckWithCustody(['status' => $status]))->toBe('PASS');
    }

    expect(vaultCheckWithCustody([
        'status' => 'ready_for_provisioning',
        'production_signing_key_provisioned' => true,
    ], 'custody_readiness_does_not_claim_provisioning'))->toBe('FAIL');
});

it('does not let storage readiness imply any backup or verified recovery', function () {
    // V9. Flip every artifact flag underneath the vault check, in both
    // directions. A vault is a place to put a key; it is never evidence that
    // one was written, copied or restored.
    $artifacts = [
        'backup_1_key_copy_created',
        'backup_2_key_copy_created',
        'sealed_cold_backup_created',
        'offsite_backup_created',
        'recovery_verified',
    ];

    foreach ($artifacts as $flag) {
        expect(vaultCheckWithCustody([$flag => false]))->toBe('PASS');
        expect(vaultCheckWithCustody([$flag => true]))->toBe('PASS');
    }
});

it('does not let storage readiness pin the production certificate', function () {
    // V10 / M12. The install trust root stays fail-closed.
    expect(config('android_release.signing.production_certificate_sha256'))->toBeNull();
    expect(config('android_release.signing.production_certificate_pin_required_before_install'))->toBeTrue();
});

it('does not let storage readiness activate the pilot or global enforcement', function () {
    // V11 / V12 / M13 / M14.
    expect(vaultCheck('enforcement_off')['status'])->toBe('PASS');
    expect(vaultCheck('global_enforcement_deferred')['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// V13 — the retired field cannot come back
// ---------------------------------------------------------------------------

it('refuses to accept the retired disk_encryption field on any custodian', function () {
    // V13. Not merely renamed: a config still carrying the ambiguous boolean
    // is an unrecognised field and must be rejected loudly, so the original
    // record cannot quietly satisfy the endpoint gate a second time.
    $custodian = custodian1();
    $custodian['disk_encryption'] = true;

    $original = config('android_release.signing.custody.custodians.custodian_1');
    config()->set('android_release.signing.custody.custodians.custodian_1', $custodian);

    try {
        $check = collect(vaultScan()['checks'])->firstWhere('id', 'custody_records_no_secret_material');
    } finally {
        config()->set('android_release.signing.custody.custodians.custodian_1', $original);
    }

    expect($check['status'])->toBe('FAIL');

    // The rejection must SAY it is a retired ambiguous claim rather than read
    // as a typo, otherwise the next person to meet it "fixes" the spelling.
    // Asserting the message is also what makes the retired-field list
    // load-bearing: without this, emptying it changes nothing observable,
    // because the allowlist rejects the field anyway.
    expect($check['detail'])->toContain('retired');
    expect($check['detail'])->toContain('host_full_disk_encryption');
});

it('rejects an unrecognised field nested inside the vault record', function () {
    // The allowlist walk only ever covered the TOP level of a custodian.
    // primary_secret_storage is the first nested array, so it would have been
    // an unpoliced surface underneath the very check that exists to keep
    // unlock hints out of committed files.
    // Probed with names on NEITHER the permitted list NOR the secret-key
    // denylist. The obvious choice, `unlock_hint`, proves nothing: it is
    // already caught by the denylist, so the test would pass with the nested
    // walk deleted. The preceding sprint hit exactly this and its note is the
    // reason this one is written the careful way.
    foreach (['notes', 'cabinet_number', 'vendor'] as $field) {
        $vault = custodian1()['primary_secret_storage'];
        $vault[$field] = 'anything';

        expect(vaultMutate(VAULT_PATH, $vault, 'custody_records_no_secret_material'))
            ->toBe('FAIL', "Nested field '{$field}' was accepted.");
    }

    // And the denylisted one still fails too, by the other mechanism.
    $vault = custodian1()['primary_secret_storage'];
    $vault['unlock_hint'] = 'anything';

    expect(vaultMutate(VAULT_PATH, $vault, 'custody_records_no_secret_material'))->toBe('FAIL');
});

// ---------------------------------------------------------------------------
// V14-V16 — history, scope and secrecy
// ---------------------------------------------------------------------------

it('preserves the custody readiness record rather than rewriting it', function () {
    // V14. The earlier task's designation stands; this revision corrects one
    // control's semantics, it does not erase what was decided.
    $custody = config('android_release.signing.custody');

    expect($custody['model'])->toBe('three_destination_custody');
    expect($custody['states'])->toContain('ready_for_provisioning');
    expect(config('android_release.signing.minimum_custodians'))->toBe(3);

    expect(custodian1()['initial_key_generation_authority'])->toBeTrue();
    expect(config('android_release.signing.custody.custodians.custodian_2.role'))
        ->toBe('encrypted_backup_destination');
    expect(config('android_release.signing.custody.custodians.custodian_3.roles'))
        ->toContain('sealed_cold_destination', 'offsite_destination');
});

it('keeps custodian 1 the only permitted key generation host', function () {
    // Storage got safer; the generation rule did not move.
    expect(vaultCheck('custody_key_generation_hosts_restricted')['status'])->toBe('PASS');
});

it('records no vault path, identifier or secret material in committed config', function () {
    // V16 / M15. The container's location, UUID and size are all deliberately
    // absent: none improves the governance claim and each widens what a
    // committed file discloses about the operator's machine.
    // Scoped to the DESIGNATION, not the whole custody block: the policy above
    // it legitimately discusses passphrases in prose ("the passphrase never
    // travels on the same medium"), and a scan crude enough to match that word
    // would be measuring vocabulary rather than leakage.
    $encoded = strtolower(json_encode(config('android_release.signing.custody.custodians')));

    // Filesystem location, container identity and volume identity. Each is
    // something a reader of a committed file should not learn about the
    // operator's machine, and none makes the governance claim any stronger.
    foreach (['.luks', '/home/', '.local/share', 'mapper', 'mountpoint'] as $located) {
        expect($encoded)->not->toContain($located);
    }

    // A LUKS UUID, if one were ever pasted in.
    expect($encoded)->not->toMatch('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/');

    // And no secret-bearing names. Deliberately NOT 'password' or 'keyfile':
    // `login_password` and `plaintext_keyfile` are legitimate control records
    // whose whole job is to assert a control is present or absent, and a scan
    // that flagged them would be matching spelling rather than secrecy.
    foreach (['passphrase', 'unlock_hint', 'recovery_key', 'private_key'] as $forbidden) {
        expect($encoded)->not->toContain($forbidden);
    }

    expect(vaultCheck('custody_records_no_secret_material')['status'])->toBe('PASS');
});

it('leaves the whole release readiness report green without inventing a key', function () {
    $scan = vaultScan();

    $failed = collect($scan['checks'])->where('status', 'FAIL')->pluck('id')->all();
    $watch = collect($scan['checks'])->where('status', 'WATCH')->pluck('id')->all();

    expect($failed)->toBe([], 'Failing checks: '.implode(', ', $failed));
    expect($watch)->toBe([], 'WATCH checks: '.implode(', ', $watch));

    expect($scan['status'])->toBe('GO');

    // Custody cleared readiness ONLY because the primary secret storage gap is
    // genuinely closed. That precondition still holds after provisioning, and
    // the check now says so without re-asserting that no key exists.
    expect($scan['summary']['signing_custody_ready_for_provisioning'])->toBeTrue();
    // The vault sprint did not create a key, and this suite must not be the
    // place that asserts whether one exists now — the custody suite owns that
    // fact. What stays pinned here is everything the STORAGE gap closing must
    // not have moved on its own.
    expect($scan['summary']['production_certificate_pinned'])->toBeFalse();
    expect($scan['summary']['real_device_validation'])->toBeFalse();
    expect($scan['summary']['device_enforcement_active'])->toBeFalse();
});
