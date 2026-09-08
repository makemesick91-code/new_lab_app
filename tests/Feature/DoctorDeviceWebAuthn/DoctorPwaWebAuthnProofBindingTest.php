<?php

/**
 * DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — a session remembers the proof that
 * authenticated it.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM DoctorPwaWebAuthnTest
 *
 * That suite proves the WebAuthn path on a device whose ONLY proof is a
 * WebAuthn credential. Every fixture there is a `DoctorDevice::factory()`
 * device: `identity_state = unverified`, `public_key = null`. So its two
 * containment tests — flag off ends the session, credential revoke ends the
 * session — passed for a reason that does not hold in production.
 *
 * The pilot tablet is a DUAL-PROOF device. `PHASE4A_PILOT_TABLET_02` is ACTIVE,
 * `cryptographically_verified`, and carries an Android Keystore public key. On
 * such a device the old `deviceIdentityProven()` answered "yes" from the
 * Android branch before it ever looked at the WebAuthn flag or the credential —
 * so a browser session established by WebAuthn would have survived both the
 * kill switch and a credential revocation.
 *
 * Every fixture in this file is therefore dual-proof, and both regressions
 * below FAIL on the pre-sprint implementation.
 *
 * DEVICE IDENTITY PROOF IS NOT SESSION AUTHENTICATION PROOF
 *
 * "Does this device hold some acceptable proof?" and "is the exact proof that
 * established THIS session still valid?" are different questions. Conflating
 * them is the defect. These tests pin them apart.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Tests\Support\FakeWebAuthnAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

const PB_ORIGIN = 'https://clinic.example.test';
const PB_RP_ID = 'clinic.example.test';

/**
 * Both switches, plus the fleet-wide scope stated explicitly.
 *
 * The whole `feature_flags.flags` array is rewritten rather than reached with
 * dot notation: the flag KEY contains a dot, so `config()->set()` would build a
 * nested structure FeatureFlagService never reads and the test would pass with
 * the flag quietly off.
 */
function pbFlags(bool $enforcement, bool $webauthn): void
{
    $flags = config('feature_flags.flags', []);

    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $enforcement;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $enforcement;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['default'] = $webauthn;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['env_value'] = $webauthn;

    config()->set('feature_flags.flags', $flags);

    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => 'unscoped'],
    ));

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => true],
    ));
}

function pbRelyingParty(): void
{
    config()->set('app.url', PB_ORIGIN);
    config()->set('webauthn.relying_party.id', PB_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', PB_ORIGIN);
}

/**
 * The production shape: one ACTIVE device carrying BOTH proofs.
 *
 * @return array{branch: Branch, room: ClinicRoom, doctor: Doctor, user: User, device: DoctorDevice, authorization: DoctorDeviceAuthorization, priv: string}
 */
function pbDualProofFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $room = ClinicRoom::factory()->create([
        'branch_id' => $branch->id,
        'status' => ClinicRoom::STATUS_ACTIVE,
    ]);

    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);
    $user = rmeMakeDoctorOnline($doctor, $branch, $room);
    $user->forceFill(['password' => bcrypt('rahasia-klinik')])->save();
    $doctor->forceFill(['user_id' => $user->id])->save();

    // Proof 1 — the Android Keystore enrolment. This is what makes the fixture
    // dual-proof, and it is the exact state of the live pilot tablet.
    [$pub, $priv] = DoctorDeviceEnrollmentFactory::generateKeyPair();

    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'public_key' => $pub,
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($pub),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ]);

    $authorization = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);

    return compact('branch', 'room', 'doctor', 'user', 'device', 'authorization', 'priv');
}

/**
 * Proof 2 — register a real WebAuthn credential against the same device.
 *
 * @return array{credential: DoctorDeviceWebAuthnCredential, authenticator: FakeWebAuthnAuthenticator}
 */
function pbEnroll(DoctorDevice $device): array
{
    $operator = superAdmin();
    $authenticator = new FakeWebAuthnAuthenticator(PB_RP_ID, PB_ORIGIN, false, false);

    actingAs($operator);

    $options = postJson(route('settings.doctor-devices.webauthn.options', $device))->json();

    post(route('settings.doctor-devices.webauthn.store', $device), [
        'credential' => $authenticator->attestation($options['challenge']),
    ]);

    auth()->logout();
    session()->flush();

    return [
        'credential' => DoctorDeviceWebAuthnCredential::query()
            ->where('credential_id', $authenticator->credentialIdBase64Url())
            ->first(),
        'authenticator' => $authenticator,
    ];
}

/** A real browser session, earned by asserting the WebAuthn credential. */
function pbWebAuthnSignIn(array $f, FakeWebAuthnAuthenticator $authenticator): void
{
    post(route('login'), ['email' => $f['user']->email, 'password' => 'rahasia-klinik']);

    $challenge = postJson(route('doctor-device-webauthn.options'))->json('challenge');

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $f['device']->uuid),
    ]);

    expect(auth()->check())->toBeTrue();
}

/** A real Clinic App session on the SAME device, earned by the keystore key. */
function pbAndroidSignIn(array $f): void
{
    $challenge = postJson(route('device-api.v1.doctor.challenge'), [
        'fingerprint' => $f['device']->public_key_fingerprint,
    ])->assertOk();

    $signature = '';
    openssl_sign(
        DeviceProofMessage::build(
            $challenge->json('purpose'),
            $challenge->json('nonce'),
            (string) $f['device']->public_key_fingerprint,
        ),
        $signature,
        $f['priv'],
        OPENSSL_ALGO_SHA256,
    );

    $ticket = postJson(route('device-api.v1.doctor.login'), [
        'nonce' => $challenge->json('nonce'),
        'signature' => base64_encode($signature),
        'email' => $f['user']->email,
        'password' => 'rahasia-klinik',
    ])->assertOk()->json('login_ticket');

    expect($ticket)->not->toBeNull();

    get(route('doctor-device-login.redeem', $ticket))->assertRedirect();

    expect(auth()->check())->toBeTrue();
}

beforeEach(function () {
    pbRelyingParty();
});

/* ---------------------------------------------------------------------------
 | The binding records WHICH proof ran, not which proofs the device holds
 |-------------------------------------------------------------------------- */

it('binds a webauthn login to the webauthn proof, on a device that also holds an android key', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    // The device has an Android keystore key sitting right there. The session
    // still records the proof that actually authenticated it.
    expect($f['device']->fresh()->public_key)->not->toBeNull()
        ->and(session(DoctorAppLoginGate::SESSION_PROOF_TYPE))->toBe(DoctorSessionProof::TYPE_WEBAUTHN)
        ->and(session(DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID))->toBe($credential->id)
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($f['device']->id);
});

it('binds an android login to the android keystore proof, on the same dual-proof device', function () {
    $f = pbDualProofFixture();
    pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbAndroidSignIn($f);

    // A usable WebAuthn credential exists on this device. The session is still
    // an Android session, because that is the proof that ran.
    expect(session(DoctorAppLoginGate::SESSION_PROOF_TYPE))->toBe(DoctorSessionProof::TYPE_ANDROID_KEYSTORE)
        ->and(session(DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID))->toBeNull();
});

/* ---------------------------------------------------------------------------
 | THE TWO REGRESSIONS — both fail on the pre-sprint implementation
 |-------------------------------------------------------------------------- */

it('ends an open webauthn session when the flag is switched off, even though the device holds an android key', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    // The kill switch. Before this sprint the Android keystore key answered
    // `deviceIdentityProven()` first and this session survived — the rollback
    // was a rollback in name only on exactly the device the pilot runs on.
    pbFlags(enforcement: true, webauthn: false);

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

it('ends an open webauthn session when the exact credential is revoked, even though the device holds an android key', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    $credential->forceFill(['revoked_at' => now(), 'revoked_reason' => 'tablet hilang'])->save();

    // Revoking the credential must stop the session it authenticated. The
    // Android key on the same device is a different proof for a different
    // path; it is not a substitute for this one.
    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse()
        ->and($f['device']->fresh()->public_key)->not->toBeNull();
});

/* ---------------------------------------------------------------------------
 | ...and the Android path is not collateral damage
 |-------------------------------------------------------------------------- */

it('leaves an android session alive when the webauthn flag is switched off', function () {
    $f = pbDualProofFixture();
    pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbAndroidSignIn($f);

    pbFlags(enforcement: true, webauthn: false);

    get(route('profile.edit'));

    expect(auth()->check())->toBeTrue()
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($f['device']->id);
});

it('leaves an android session alive when the webauthn credential is revoked', function () {
    $f = pbDualProofFixture();
    ['credential' => $credential] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbAndroidSignIn($f);

    $credential->forceFill(['revoked_at' => now(), 'revoked_reason' => 'kredensial browser ditarik'])->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeTrue();
});

/* ---------------------------------------------------------------------------
 | Everything else that could withdraw the trust behind a webauthn session
 |-------------------------------------------------------------------------- */

it('ends an open webauthn session when the device itself is revoked', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    $f['device']->forceFill([
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
        'revoked_reason' => 'perangkat hilang',
    ])->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

it('ends an open webauthn session when the authorization is revoked', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    $f['authorization']->forceFill([
        'status' => DoctorDeviceAuthorization::STATUS_REVOKED,
        'revoked_at' => now(),
    ])->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

/* ---------------------------------------------------------------------------
 | A binding that cannot be read is not a binding
 |-------------------------------------------------------------------------- */

it('refuses a session whose binding carries no proof type at all', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    // The shape of every session bound before this sprint. An unknown proof is
    // not assumed to be the permissive one — the doctor re-authenticates.
    session()->forget(DoctorAppLoginGate::SESSION_PROOF_TYPE);
    session()->forget(DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID);
    session()->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

it('refuses a session whose proof type is not a value this deployment knows', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    session([DoctorAppLoginGate::SESSION_PROOF_TYPE => 'trust_me']);
    session()->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

it('refuses a webauthn session whose credential reference names nothing', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    session([DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID => $credential->id + 9999]);
    session()->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

it('refuses a webauthn session bound to a credential registered on a different device', function () {
    $f = pbDualProofFixture();
    ['authenticator' => $authenticator] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: true);

    pbWebAuthnSignIn($f, $authenticator);

    // A second approved tablet, with its own perfectly valid credential.
    $other = DoctorDevice::factory()->create([
        'branch_id' => $f['branch']->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);
    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $other->id,
    ]);
    ['credential' => $otherCredential] = pbEnroll($other);

    // Pointing this session at that credential must not keep it alive: the
    // credential has to belong to the device the session is bound to.
    session([DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID => $otherCredential->id]);
    session()->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

/* ---------------------------------------------------------------------------
 | The predicate itself, stated directly
 |-------------------------------------------------------------------------- */

it('answers the two proof questions independently for one dual-proof device', function () {
    $f = pbDualProofFixture();
    ['credential' => $credential] = pbEnroll($f['device']);
    pbFlags(enforcement: true, webauthn: false);

    $gate = app(DoctorAppLoginGate::class);
    $device = $f['device']->fresh();

    // With the WebAuthn flag off the Android proof is still good and the
    // WebAuthn proof is not. One device, two answers — which is the whole
    // point: a proof is not a property of the hardware, it is a property of
    // the ceremony that ran.
    expect($gate->deviceUsableForProof($device, DoctorSessionProof::androidKeystore()))->toBeTrue()
        ->and($gate->deviceUsableForProof($device, DoctorSessionProof::webAuthn((int) $credential->id)))->toBeFalse();

    pbFlags(enforcement: true, webauthn: true);

    expect($gate->deviceUsableForProof($device, DoctorSessionProof::webAuthn((int) $credential->id)))->toBeTrue();

    $credential->forceFill(['revoked_at' => now()])->save();

    expect($gate->deviceUsableForProof($device, DoctorSessionProof::webAuthn((int) $credential->id)))->toBeFalse()
        ->and($gate->deviceUsableForProof($device, DoctorSessionProof::androidKeystore()))->toBeTrue();
});
