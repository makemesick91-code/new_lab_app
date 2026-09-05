<?php

use App\Support\Android\AndroidReleaseGovernanceScanner;

uses()->group('DoctorDevice', 'Android', 'Security');

/**
 * PRODUCTION-ANDROID-SIGNING-CUSTODY-READINESS-1.
 *
 * The owner has designated three custody destinations for the production
 * Android signing identity. That designation is what these tests pin, and the
 * distinction they exist to defend is a narrow one that is very easy to lose:
 *
 *   READY_FOR_PROVISIONING  is not  KEY_PROVISIONED
 *   KEY_PROVISIONED         is not  BACKUPS_CREATED
 *   BACKUPS_CREATED         is not  RECOVERY_VERIFIED
 *
 * At the time of writing NO production signing key exists. No keystore has
 * been generated, no backup copy has been written to any medium, no recovery
 * has been rehearsed and `production_certificate_sha256` is still null. What
 * exists is a decision about WHO holds WHAT, WHERE, under WHICH controls — so
 * that a later, separate provisioning task has somewhere lawful to put the key
 * the moment it is created.
 *
 * The failure mode this suite refuses is a future reader — human or agent —
 * finding "three custodians designated, custody ready" and concluding that
 * three encrypted backups already sit in three buildings. They do not. Every
 * assertion below that says "ready" is paired with one that says "and nothing
 * has been created yet".
 *
 * As with the preflight suite, the checks are not "the config says PASS".
 * Each control is mutated into the way it could be wrong and the scanner is
 * required to go red, because a gate that has only ever seen the correct value
 * has never been shown to reject anything.
 */
function custodyScan(): array
{
    return app(AndroidReleaseGovernanceScanner::class)->scan();
}

function custodyCheck(string $id): array
{
    $found = collect(custodyScan()['checks'])->firstWhere('id', $id);

    expect($found)->not->toBeNull("Scanner check '{$id}' does not exist.");

    return $found;
}

/**
 * Mutate one custody config key, scan, restore. Returns the scanner status for
 * the named check so a test can assert the gate actually rejected the mutation.
 */
function custodyMutate(string $configKey, mixed $badValue, string $checkId): string
{
    $original = config($configKey);

    config()->set($configKey, $badValue);

    try {
        $found = collect(custodyScan()['checks'])->firstWhere('id', $checkId);
        $status = $found['status'] ?? 'MISSING';
    } finally {
        config()->set($configKey, $original);
    }

    return $status;
}

// ---------------------------------------------------------------------------
// C1-C9 — the owner's designation is actually recorded
// ---------------------------------------------------------------------------

it('records the custody state as ready for provisioning, not as provisioned', function () {
    expect(config('android_release.signing.custody.status'))->toBe('ready_for_provisioning');

    expect(custodyCheck('signing_custody_status_recorded')['status'])->toBe('PASS');
    expect(custodyCheck('signing_custody_ready_for_provisioning')['status'])->toBe('PASS');
});

it('designates at least the minimum number of custody destinations', function () {
    $custodians = config('android_release.signing.custody.custodians');
    $minimum = config('android_release.signing.minimum_custodians');

    expect($custodians)->toBeArray();
    expect(count($custodians))->toBeGreaterThanOrEqual($minimum);

    expect(custodyCheck('custody_minimum_custodians_designated')['status'])->toBe('PASS');
});

it('names custodian 1 as the primary signing authority', function () {
    $one = config('android_release.signing.custody.custodians.custodian_1');

    expect($one['role'])->toBe('primary_signing_authority');
    expect($one['responsible_party'])->toContain('Raushan Fikri Ridha');
    expect($one['os'])->toBe('ubuntu');
    expect($one['location'])->toBe('Cabang Pusat');

    expect(custodyCheck('custody_primary_signing_authority_designated')['status'])->toBe('PASS');
});

it('makes custodian 1 the only permitted initial key generation location', function () {
    $custodians = config('android_release.signing.custody.custodians');

    $generators = array_keys(array_filter(
        $custodians,
        fn (array $c): bool => ($c['initial_key_generation_authority'] ?? false) === true,
    ));

    expect($generators)->toBe(['custodian_1']);

    expect(custodyCheck('custody_key_generation_hosts_restricted')['status'])->toBe('PASS');
});

it('records custodian 2 as an encrypted backup destination with endpoint controls', function () {
    $two = config('android_release.signing.custody.custodians.custodian_2');

    expect($two['roles'])->toContain('encrypted_backup_destination');
    expect($two['media'])->toBe('admin_klinik_workstation');
    expect($two['os'])->toBe('windows');
    expect($two['location'])->toBe('Klinik Daengtisia');

    expect($two['disk_encryption'])->toBeTrue();
    expect($two['login_password'])->toBeTrue();
    expect($two['screen_lock'])->toBeTrue();

    expect(custodyCheck('custody_endpoint_controls_recorded')['status'])->toBe('PASS');
});

it('restricts custodian 2 access to IT and Admin Klinik', function () {
    $access = config('android_release.signing.custody.custodians.custodian_2.authorized_access');

    expect($access)->toContain('IT');
    expect($access)->toContain('Admin Klinik');
    expect($access)->toHaveCount(2);
});

it('records custodian 3 as an IT-held USB medium', function () {
    $three = config('android_release.signing.custody.custodians.custodian_3');

    expect($three['media'])->toBe('usb');
    expect($three['responsible_party'])->toBe('IT');
    expect($three['location'])->toBe('Kantor Management Klinik');
});

it('designates custodian 3 as both the sealed-cold and the offsite destination', function () {
    $three = config('android_release.signing.custody.custodians.custodian_3');

    expect($three['roles'])->toContain('sealed_cold_destination');
    expect($three['roles'])->toContain('offsite_destination');
    expect($three['roles'])->toContain('encrypted_backup_destination');

    expect(custodyCheck('custody_sealed_cold_destination_designated')['status'])->toBe('PASS');
    expect(custodyCheck('custody_offsite_destination_designated')['status'])->toBe('PASS');
});

// ---------------------------------------------------------------------------
// C10-C13 — media handling rules
// ---------------------------------------------------------------------------

it('forbids plaintext signing material and co-located passwords on custody media', function () {
    $rules = config('android_release.signing.custody.media_rules');

    expect($rules['plaintext_signing_material_permitted'])->toBeFalse();
    expect($rules['password_stored_with_key_permitted'])->toBeFalse();

    expect(custodyCheck('custody_media_secret_handling_rules')['status'])->toBe('PASS');
});

it('does not require wiping unrelated data already on the custody USB', function () {
    // The owner authorised an existing USB. Demanding a wipe would be this
    // task inventing a requirement its authority never granted, and would
    // destroy data belonging to someone else.
    expect(config('android_release.signing.custody.media_rules.existing_unrelated_data_wipe_required'))
        ->toBeFalse();
});

it('requires the custody USB to be offline outside an approved operation', function () {
    $rules = config('android_release.signing.custody.media_rules');

    expect($rules['offline_when_not_in_approved_use'])->toBeTrue();
    expect($rules['general_daily_use_after_custody_permitted'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// C14-C18 — readiness is NOT provisioning. The core of this suite.
// ---------------------------------------------------------------------------

it('does not claim a production signing key exists', function () {
    $custody = config('android_release.signing.custody');

    expect($custody['production_signing_key_provisioned'])->toBeFalse();
    expect(config('android_release.signing.production_certificate_sha256'))->toBeNull();

    $summary = custodyScan()['summary'];

    expect($summary['production_signing_key_provisioned'])->toBeFalse();
    expect($summary['signing_custody_ready_for_provisioning'])->toBeTrue();

    expect(custodyCheck('custody_readiness_does_not_claim_provisioning')['status'])->toBe('PASS');
});

it('does not claim any backup copy has been created', function () {
    $custody = config('android_release.signing.custody');

    expect($custody['backup_1_key_copy_created'])->toBeFalse();
    expect($custody['backup_2_key_copy_created'])->toBeFalse();
});

it('does not claim recovery has been verified', function () {
    expect(config('android_release.signing.custody.recovery_verified'))->toBeFalse();
});

it('keeps the certificate pin fail-closed while no key exists', function () {
    expect(config('android_release.signing.production_certificate_pin_required_before_install'))
        ->toBeTrue();
    expect(config('android_release.signing.production_certificate_sha256'))->toBeNull();
});

it('separates a ready destination from an established backup in the recorded vocabulary', function () {
    // "ready" describes a place that is prepared to receive a copy.
    // "created" describes a copy that exists. Conflating them is the single
    // most damaging misreading this record can produce.
    $custody = config('android_release.signing.custody');

    expect($custody['sealed_cold_destination_ready'])->toBeTrue();
    expect($custody['sealed_cold_backup_created'])->toBeFalse();

    expect($custody['offsite_destination_ready'])->toBeTrue();
    expect($custody['offsite_backup_created'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// C19-C20 — this record activates nothing
// ---------------------------------------------------------------------------

it('activates neither the pilot nor global enforcement', function () {
    expect(config('android_release.enforcement.active'))->toBeFalse();
    expect(config('android_release.enforcement.current_stage'))->toBe('off');

    $report = custodyScan();

    expect($report['summary']['device_enforcement_active'])->toBeFalse();
    expect(collect($report['checks'])->firstWhere('id', 'enforcement_off')['status'])->toBe('PASS');
    expect(collect($report['checks'])->firstWhere('id', 'global_enforcement_deferred')['status'])->toBe('PASS');
});

it('leaves the whole release readiness report green without inventing a key', function () {
    $report = custodyScan();

    expect($report['status'])->toBe('GO');
    expect($report['summary']['failed'])->toBe(0);
    expect($report['summary']['production_signing_key_provisioned'])->toBeFalse();
    expect($report['summary']['real_device_validation'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// C21-C22 — privacy, and history stays history
// ---------------------------------------------------------------------------

it('records no secret or personally identifying custody material', function () {
    // A blunt substring sweep over the encoded block is the wrong instrument
    // here, and the first version of this test proved it by failing on the
    // compensating control named `passphrase_held_separately_from_every_medium`
    // — a policy statement that must be written down, not a leaked passphrase.
    //
    // What actually matters is the shape of the data: a leaf KEY that names a
    // secret, or a VALUE that looks like one. That is what the scanner checks,
    // so it is what this asserts, rather than banning the vocabulary needed to
    // describe the control.
    $forbiddenLeafKeys = [
        'serial', 'serial_number', 'hardware_serial', 'uuid', 'filesystem_uuid',
        'passphrase', 'password', 'key_password', 'keystore_password',
        'secret', 'token', 'credential', 'private_key', 'recovery_key',
        'street', 'home_address', 'phone', 'email',
    ];

    $walk = function (array $node, string $path) use (&$walk, $forbiddenLeafKeys): array {
        $found = [];

        foreach ($node as $key => $value) {
            $here = $path.'.'.$key;

            if (in_array(strtolower((string) $key), $forbiddenLeafKeys, true)) {
                $found[] = $here;

                continue;
            }

            if (is_array($value)) {
                $found = array_merge($found, $walk($value, $here));

                continue;
            }

            // Long unbroken hex is a fingerprint, a serial or key material.
            if (is_string($value) && preg_match('/^[A-Fa-f0-9]{16,}$/', $value) === 1) {
                $found[] = $here;
            }
        }

        return $found;
    };

    expect($walk(config('android_release.signing.custody'), 'custody'))->toBe([]);

    expect(custodyCheck('custody_records_no_secret_material')['status'])->toBe('PASS');
});

it('records only organisation-level locations, never a private address', function () {
    // The owner gave organisation-level locations. Recording a street, a house
    // number or a postcode would be this file volunteering a person's home to
    // anyone who clones the repository.
    $encoded = strtolower((string) json_encode(config('android_release.signing.custody')));

    foreach (['jalan ', 'jl. ', ' no. ', 'rt ', 'rw ', 'kode pos', 'postcode'] as $addressish) {
        expect($encoded)->not->toContain($addressish);
    }

    $locations = collect(config('android_release.signing.custody.custodians'))
        ->pluck('location')
        ->all();

    expect($locations)->toBe(['Cabang Pusat', 'Klinik Daengtisia', 'Kantor Management Klinik']);
});

it('declares that one party can reach every copy, with compensating controls', function () {
    // IT is responsible for custodian 1 and custodian 3 and has access to
    // custodian 2. That is a real concentration and it is recorded as such
    // rather than being hidden behind the phrase "three custodians".
    $custody = config('android_release.signing.custody');

    expect($custody['single_party_can_reach_all_copies'])->toBeTrue();
    expect($custody['single_party_access_compensating_controls'])->not->toBeEmpty();

    expect(custodyCheck('custody_shared_access_declared')['status'])->toBe('PASS');
});

it('preserves the earlier PARTIAL custody record as history rather than rewriting it', function () {
    $sprintDoc = base_path('docs/sprints/evidence-phase4a-real-device-keyinfo-preflight-1.md');

    expect(is_file($sprintDoc))->toBeTrue();

    $contents = (string) file_get_contents($sprintDoc);

    // The preceding record said custody was PARTIAL. It was, at that time.
    // Superseding it forward is correct; deleting it is falsifying history.
    expect($contents)->toContain('PARTIAL');
});

// ---------------------------------------------------------------------------
// M1-M20 — adversarial. Every control is mutated and must be rejected.
// ---------------------------------------------------------------------------

it('rejects a custody model with the primary signing authority removed', function () {
    $custodians = config('android_release.signing.custody.custodians');
    unset($custodians['custodian_1']);

    expect(custodyMutate(
        'android_release.signing.custody.custodians',
        $custodians,
        'custody_primary_signing_authority_designated',
    ))->toBe('FAIL');
});

it('rejects a backup destination whose disk encryption is off', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_2.disk_encryption',
        false,
        'custody_endpoint_controls_recorded',
    ))->toBe('FAIL');
});

it('rejects a backup destination whose screen lock is off', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_2.screen_lock',
        false,
        'custody_endpoint_controls_recorded',
    ))->toBe('FAIL');
});

it('rejects a backup destination whose login password is off', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_2.login_password',
        false,
        'custody_endpoint_controls_recorded',
    ))->toBe('FAIL');
});

it('rejects a custody model with no offsite destination', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_3.roles',
        ['encrypted_backup_destination', 'sealed_cold_destination'],
        'custody_offsite_destination_designated',
    ))->toBe('FAIL');
});

it('rejects a custody model with no sealed-cold destination', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_3.roles',
        ['encrypted_backup_destination', 'offsite_destination'],
        'custody_sealed_cold_destination_designated',
    ))->toBe('FAIL');
});

it('rejects permitting plaintext signing material on custody media', function () {
    expect(custodyMutate(
        'android_release.signing.custody.media_rules.plaintext_signing_material_permitted',
        true,
        'custody_media_secret_handling_rules',
    ))->toBe('FAIL');
});

it('rejects storing the key password on the same medium as the key', function () {
    expect(custodyMutate(
        'android_release.signing.custody.media_rules.password_stored_with_key_permitted',
        true,
        'custody_media_secret_handling_rules',
    ))->toBe('FAIL');
});

it('rejects claiming the key is provisioned while no certificate is pinned', function () {
    expect(custodyMutate(
        'android_release.signing.custody.production_signing_key_provisioned',
        true,
        'custody_state_machine_consistent',
    ))->toBe('FAIL');
});

it('rejects claiming a backup exists before the key is provisioned', function () {
    expect(custodyMutate(
        'android_release.signing.custody.backup_1_key_copy_created',
        true,
        'custody_state_machine_consistent',
    ))->toBe('FAIL');
});

it('rejects claiming recovery is verified before any backup exists', function () {
    expect(custodyMutate(
        'android_release.signing.custody.recovery_verified',
        true,
        'custody_state_machine_consistent',
    ))->toBe('FAIL');
});

it('rejects a sealed-cold backup claimed as created while the key does not exist', function () {
    expect(custodyMutate(
        'android_release.signing.custody.sealed_cold_backup_created',
        true,
        'custody_state_machine_consistent',
    ))->toBe('FAIL');
});

it('rejects a readiness status that also claims provisioning', function () {
    // Status stays ready_for_provisioning while a downstream fact says the key
    // exists. Either the status or the fact is a lie; the gate must not pick.
    $custody = config('android_release.signing.custody');
    $custody['production_signing_key_provisioned'] = true;

    expect(custodyMutate(
        'android_release.signing.custody',
        $custody,
        'custody_readiness_does_not_claim_provisioning',
    ))->toBe('FAIL');
});

it('rejects an unknown custody state', function () {
    expect(custodyMutate(
        'android_release.signing.custody.status',
        'totally_ready_honest',
        'signing_custody_status_recorded',
    ))->toBe('FAIL');
});

it('rejects the production VPS as an initial key generation host', function () {
    $custodians = config('android_release.signing.custody.custodians');
    $custodians['production_vps'] = [
        'role' => 'backup_destination',
        'roles' => ['encrypted_backup_destination'],
        'media' => 'production_vps',
        'responsible_party' => 'IT',
        'location' => 'Hostinger',
        'initial_key_generation_authority' => true,
    ];

    expect(custodyMutate(
        'android_release.signing.custody.custodians',
        $custodians,
        'custody_key_generation_hosts_restricted',
    ))->toBe('FAIL');
});

it('rejects a backup destination promoted to initial key generation authority', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_2.initial_key_generation_authority',
        true,
        'custody_key_generation_hosts_restricted',
    ))->toBe('FAIL');
});

it('rejects the USB medium as an initial key generation authority', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_3.initial_key_generation_authority',
        true,
        'custody_key_generation_hosts_restricted',
    ))->toBe('FAIL');
});

it('rejects fewer designated custody destinations than the stated minimum', function () {
    $custodians = config('android_release.signing.custody.custodians');
    unset($custodians['custodian_3']);

    expect(custodyMutate(
        'android_release.signing.custody.custodians',
        $custodians,
        'custody_minimum_custodians_designated',
    ))->toBe('FAIL');
});

it('rejects an undeclared shared-access posture', function () {
    expect(custodyMutate(
        'android_release.signing.custody.single_party_can_reach_all_copies',
        null,
        'custody_shared_access_declared',
    ))->toBe('FAIL');
});

it('rejects a declared shared-access posture with no compensating controls', function () {
    expect(custodyMutate(
        'android_release.signing.custody.single_party_access_compensating_controls',
        [],
        'custody_shared_access_declared',
    ))->toBe('FAIL');
});

it('rejects a hardware serial recorded against a custody medium', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_3.serial',
        'A1B2C3D4E5',
        'custody_records_no_secret_material',
    ))->toBe('FAIL');
});

it('rejects a passphrase recorded anywhere in the custody block', function () {
    expect(custodyMutate(
        'android_release.signing.custody.custodians.custodian_1.passphrase',
        'correct-horse-battery-staple',
        'custody_records_no_secret_material',
    ))->toBe('FAIL');
});

it('registers this suite in the critical gate so something selects it', function () {
    // A `DoctorDevice` token match would run this file today. It is declared
    // explicitly for the reason the registry itself gives: a token match is an
    // accident of naming, and nothing would announce the day it stopped
    // selecting. The drift this suite catches — config claiming backups that
    // were never written — is silent, and would first surface as a lost key
    // with no recoverable copy.
    expect(config('ci_runner.critical_gate_mandatory_suites'))
        ->toContain('tests/Feature/DoctorDevice/DoctorDeviceAndroidSigningCustodyReadinessTest.php');
});
