<?php

/**
 * DOCTOR-PWA-WEBAUTHN-CREDENTIAL-REVOKE-CONTAINMENT-1 — revoking ONE credential
 * contains the session it authenticated, and nothing else.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM DoctorPwaWebAuthnProofBindingTest
 *
 * That suite proved the proof BINDING: a WebAuthn session is re-checked as
 * WebAuthn, so an Android key on the same dual-proof tablet cannot keep it
 * alive. It reaches revocation by writing `revoked_at` onto the model.
 *
 * That is the right shape for a binding test and the wrong shape for a
 * containment test, because the thing an operator actually does in the clinic
 * is post to the revoke route. Between the route and the column sit a policy
 * check, a device-ownership check, a mandatory reason, a transaction and an
 * audit write — and the question this sprint has to answer is not only "does a
 * revoked column end the session" but "does the operator's actual action end
 * exactly that session and leave the rest of the estate standing".
 *
 * So every revocation below goes through `settings.doctor-devices.webauthn.
 * revoke`. What that buys is the four blast-radius properties the live ceremony
 * on PHASE4A_PILOT_TABLET_02 depends on and which nothing pinned before:
 *
 *   - a SECOND usable credential on the SAME device does not rescue a session
 *     bound to the revoked one (the binding suite only ever proved a credential
 *     on a DIFFERENT device does not);
 *   - the device and the doctor-device authorization survive untouched, and no
 *     replacement row is conjured for either;
 *   - the revoked credential does not block re-enrolment, and does not sit in
 *     `excludeCredentials` blocking the same authenticator from enrolling again;
 *   - a fresh credential never resurrects the old row.
 *
 * The last two are why revocation is survivable at all. A containment control
 * that cannot be recovered from is not a control anybody will use twice.
 *
 * EVERY FIXTURE IS DUAL-PROOF, FOR THE SAME REASON AS THE BINDING SUITE
 *
 * The pilot tablet is ACTIVE, `cryptographically_verified` and carries an
 * Android Keystore key. A single-proof fixture would let these tests pass for a
 * reason that does not hold in production — which is precisely how the defect
 * that sprint fixed survived two tests named after the property.
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
use App\Modules\LabOrder\Models\AuditLog;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Illuminate\Support\Facades\Auth;
use Tests\Support\FakeWebAuthnAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

const CRV_ORIGIN = 'https://clinic.example.test';
const CRV_RP_ID = 'clinic.example.test';

/**
 * Helpers are declared here rather than reused from the binding suite.
 *
 * A test file that borrows a top-level function from a sibling only works while
 * both files happen to be loaded, so `--filter=CredentialRevocation` — the exact
 * command a reviewer runs to check this sprint — would fail with an undefined
 * function. Self-contained is worth the repetition.
 */

/** Both switches, with enforcement scope stated explicitly rather than inherited. */
function crvFlags(bool $enforcement, bool $webauthn): void
{
    $flags = config('feature_flags.flags', []);

    // The whole array is rewritten because the flag KEY contains a dot:
    // `config()->set('feature_flags.flags.doctor.x')` would build a nested
    // structure FeatureFlagService never reads, and the test would pass with
    // the flag quietly off.
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

function crvRelyingParty(): void
{
    config()->set('app.url', CRV_ORIGIN);
    config()->set('webauthn.relying_party.id', CRV_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', CRV_ORIGIN);
}

/**
 * The production shape: one ACTIVE device carrying BOTH proofs.
 *
 * @return array{branch: Branch, room: ClinicRoom, doctor: Doctor, user: User, device: DoctorDevice, authorization: DoctorDeviceAuthorization, priv: string}
 */
function crvDualProofFixture(): array
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

    // Proof 1 — the Android Keystore enrolment. This is what the live tablet
    // carries, and what makes "the credential was revoked" a different question
    // from "the device stopped being trusted".
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
 * Called more than once per device on purpose: a second call is a second
 * authenticator, which is how "another usable credential on the same device"
 * and "the replacement enrolled after a revocation" are both built.
 *
 * @return array{credential: DoctorDeviceWebAuthnCredential, authenticator: FakeWebAuthnAuthenticator}
 */
function crvEnroll(DoctorDevice $device): array
{
    // Enrolment is an operator action in another browser too, and it is
    // performed while a doctor session is open in several tests below. Losing
    // that session here would make those tests pass on a logout this helper
    // caused — which a mutation run caught doing exactly that.
    $doctorSession = session()->all();

    $authenticator = new FakeWebAuthnAuthenticator(CRV_RP_ID, CRV_ORIGIN, false, false);

    actingAs(superAdmin());

    $options = postJson(route('settings.doctor-devices.webauthn.options', $device))->json();

    post(route('settings.doctor-devices.webauthn.store', $device), [
        'credential' => $authenticator->attestation($options['challenge']),
    ])->assertSessionHasNoErrors();

    session()->flush();
    session()->replace($doctorSession);

    Auth::guard('web')->forgetUser();

    return [
        'credential' => DoctorDeviceWebAuthnCredential::query()
            ->where('credential_id', $authenticator->credentialIdBase64Url())
            ->first(),
        'authenticator' => $authenticator,
    ];
}

/**
 * Revoke through the route an operator actually posts to, WITHOUT disturbing
 * the doctor session under test.
 *
 * WHY THIS SNAPSHOTS AND RESTORES THE SESSION
 *
 * In the clinic these are two browsers: the operator revokes at the front desk
 * while the doctor's tablet sits there holding a session nobody has touched.
 * The test client has exactly one session, so acting as the operator inside it
 * would tear down the very session whose fate is the thing being measured — and
 * the test would then "prove" containment by having logged the doctor out
 * itself. The first draft of this file did precisely that, and it showed up as
 * a denial reason of `no_device_session` instead of the credential, and as a
 * dead Android session. Both were the harness, not the application.
 *
 * So the doctor's session is captured verbatim, the operator acts, and the
 * doctor's session is put back exactly as it was — which is what "a different
 * browser was used" means when there is only one session store. The resolved
 * user is forgotten too, so the next request re-reads the guard from the
 * restored session rather than from the operator still cached in memory.
 */
function crvRevokeViaRoute(DoctorDevice $device, DoctorDeviceWebAuthnCredential $credential): void
{
    $doctorSession = session()->all();

    actingAs(superAdmin());

    post(route('settings.doctor-devices.webauthn.revoke', [$device, $credential]), [
        'reason' => 'DOCTOR-PWA-WEBAUTHN-CREDENTIAL-REVOKE-CONTAINMENT-1 containment test',
    ])->assertRedirect();

    session()->flush();
    session()->replace($doctorSession);

    Auth::guard('web')->forgetUser();
}

/** A real browser session, earned by asserting a WebAuthn credential. */
function crvWebAuthnSignIn(array $f, FakeWebAuthnAuthenticator $authenticator): void
{
    post(route('login'), ['email' => $f['user']->email, 'password' => 'rahasia-klinik']);

    $challenge = postJson(route('doctor-device-webauthn.options'))->json('challenge');

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $f['device']->uuid),
    ]);

    expect(auth()->check())->toBeTrue();
}

/** A real Clinic App session on the SAME device, earned by the keystore key. */
function crvAndroidSignIn(array $f): void
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
    crvRelyingParty();
});

/* ---------------------------------------------------------------------------
 | The control: an unrevoked credential keeps its session
 |-------------------------------------------------------------------------- */

it('keeps a webauthn session alive across a protected request while its credential is active', function () {
    $f = crvDualProofFixture();
    ['authenticator' => $authenticator] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    crvWebAuthnSignIn($f, $authenticator);

    // Stated before the revocation tests so that a later denial is readable as
    // the revocation and not as "these fixtures never worked".
    get(route('profile.edit'))->assertOk();

    expect(auth()->check())->toBeTrue();
});

/* ---------------------------------------------------------------------------
 | Containment, through the route an operator actually uses
 |-------------------------------------------------------------------------- */

it('ends an open webauthn session on the next protected request after the operator revokes the credential', function () {
    $f = crvDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    crvWebAuthnSignIn($f, $authenticator);

    crvRevokeViaRoute($f['device'], $credential);

    // Nobody logged this session out. The next request it makes is where the
    // revocation has to land, because "revoked at 09:00, effective at logout"
    // is not containment.
    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse()
        ->and($credential->fresh()->revoked_at)->not->toBeNull()
        ->and($credential->fresh()->isRevoked())->toBeTrue();
});

it('names the credential, not the flag or the device, as the reason it ended the session', function () {
    $f = crvDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    crvWebAuthnSignIn($f, $authenticator);
    crvRevokeViaRoute($f['device'], $credential);

    get(route('profile.edit'));

    // Attribution is the whole evidentiary value. A session that ends because
    // the flag went off, or the device was revoked, proves nothing about
    // credential revocation — so the deny code is asserted, not just the logout.
    $invalidation = AuditLog::query()
        ->where('action', 'DOCTOR_SESSION_DEVICE_INVALIDATED')
        ->where('entity_type', 'users')
        ->where('entity_id', $f['user']->id)
        ->latest('id')
        ->first();

    expect($invalidation)->not->toBeNull()
        ->and(data_get($invalidation->new_values, 'reason'))
        ->toBe(DoctorAppLoginGate::DENY_WEBAUTHN_CREDENTIAL_NOT_USABLE);

    // ...and the things that did NOT cause it are demonstrably still in place.
    expect(app(DoctorAppLoginGate::class)->enforcementEnabled())->toBeTrue()
        ->and(app(DoctorDeviceWebAuthnLoginService::class)->enabled())->toBeTrue()
        ->and($f['device']->fresh()->isActive())->toBeTrue()
        ->and($f['authorization']->fresh()->isActive())->toBeTrue();
});

/* ---------------------------------------------------------------------------
 | A session is bound to ONE credential — not to "a credential on this device"
 |-------------------------------------------------------------------------- */

it('does not let a second usable credential on the same device rescue a session bound to the revoked one', function () {
    $f = crvDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $revoked] = crvEnroll($f['device']);

    // A second, entirely valid credential on the SAME approved device. The
    // binding suite only ever proved a credential on a DIFFERENT device cannot
    // stand in; this is the case an operator will actually create, because
    // recovery means enrolling a replacement.
    ['credential' => $survivor] = crvEnroll($f['device']);

    crvFlags(enforcement: true, webauthn: true);
    crvWebAuthnSignIn($f, $authenticator);

    expect(session(DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID))->toBe($revoked->id);

    crvRevokeViaRoute($f['device'], $revoked);

    get(route('profile.edit'));

    // The session does not silently migrate onto the credential that is still
    // good. It was earned by one ceremony, and that ceremony's credential is
    // gone.
    expect(auth()->check())->toBeFalse()
        ->and($survivor->fresh()->revoked_at)->toBeNull()
        ->and($survivor->id)->not->toBe($revoked->id);
});

it('does not let a credential enrolled after the revocation rescue the old session', function () {
    $f = crvDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $revoked] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    crvWebAuthnSignIn($f, $authenticator);
    crvRevokeViaRoute($f['device'], $revoked);

    // The recovery enrolment. Ordering matters: the replacement arrives while
    // the denied session is still open, which is exactly the clinic's sequence.
    ['credential' => $replacement] = crvEnroll($f['device']);

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse()
        ->and($replacement->fresh()->revoked_at)->toBeNull();
});

/* ---------------------------------------------------------------------------
 | The Android proof is not collateral damage
 |-------------------------------------------------------------------------- */

it('leaves a pre-existing android session usable after the operator revokes the webauthn credential', function () {
    $f = crvDualProofFixture();
    ['credential' => $credential] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    // Established BEFORE the revocation, and never re-authenticated. A session
    // created afterwards would prove only that login still works.
    crvAndroidSignIn($f);
    get(route('profile.edit'))->assertOk();

    crvRevokeViaRoute($f['device'], $credential);

    get(route('profile.edit'))->assertOk();

    expect(auth()->check())->toBeTrue()
        ->and(session(DoctorAppLoginGate::SESSION_PROOF_TYPE))
        ->toBe(DoctorSessionProof::TYPE_ANDROID_KEYSTORE);
});

/* ---------------------------------------------------------------------------
 | Blast radius: one credential row, and nothing else
 |-------------------------------------------------------------------------- */

it('leaves the device and the authorization untouched, and conjures no replacement for either', function () {
    $f = crvDualProofFixture();
    ['credential' => $credential] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    $devicesBefore = DoctorDevice::query()->count();
    $authorizationsBefore = DoctorDeviceAuthorization::query()->count();

    crvRevokeViaRoute($f['device'], $credential);

    // Device revocation is terminal and a replacement device means a
    // re-enrolment ceremony on site. If credential revocation quietly did
    // either, it would be the more destructive control wearing the safer name.
    expect($f['device']->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE)
        ->and($f['device']->fresh()->isActive())->toBeTrue()
        ->and($f['device']->fresh()->public_key)->not->toBeNull()
        ->and($f['authorization']->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE)
        ->and($f['authorization']->fresh()->isActive())->toBeTrue()
        ->and(DoctorDevice::query()->count())->toBe($devicesBefore)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe($authorizationsBefore);
});

/* ---------------------------------------------------------------------------
 | Recovery: revocation has to be survivable, or nobody will use it
 |-------------------------------------------------------------------------- */

it('lets a fresh device-bound credential enrol on the same device after a revocation', function () {
    $f = crvDualProofFixture();
    ['credential' => $revoked] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    crvRevokeViaRoute($f['device'], $revoked);

    $credentialsBefore = DoctorDeviceWebAuthnCredential::query()->count();

    ['credential' => $replacement] = crvEnroll($f['device']);

    expect($replacement)->not->toBeNull()
        ->and($replacement->id)->not->toBe($revoked->id)
        ->and((int) $replacement->doctor_device_id)->toBe((int) $f['device']->id)
        ->and($replacement->revoked_at)->toBeNull()
        // The same policy the original had to satisfy. A replacement admitted
        // on looser terms would be a downgrade dressed as a recovery.
        ->and($replacement->user_verified)->toBeTrue()
        ->and($replacement->backup_eligible)->toBeFalse()
        ->and(DoctorDeviceWebAuthnCredential::query()->count())->toBe($credentialsBefore + 1);
});

it('does not offer a revoked credential for exclusion, so the same authenticator can enrol again', function () {
    $f = crvDualProofFixture();
    ['credential' => $revoked] = crvEnroll($f['device']);

    crvRevokeViaRoute($f['device'], $revoked);

    actingAs(superAdmin());
    $options = postJson(route('settings.doctor-devices.webauthn.options', $f['device']))->json();

    // `excludeCredentials` is what stops an authenticator re-enrolling. Leaving
    // a revoked entry in it would mean the tablet whose credential you just
    // withdrew is the one tablet that can no longer be re-enrolled — recovery
    // blocked by the containment action itself.
    $excluded = collect($options['excludeCredentials'] ?? [])->pluck('id')->all();

    expect($excluded)->not->toContain($revoked->credential_id);
});

it('does not resurrect the revoked credential when a replacement is registered', function () {
    $f = crvDualProofFixture();
    ['credential' => $revoked] = crvEnroll($f['device']);

    crvRevokeViaRoute($f['device'], $revoked);
    $revokedAt = $revoked->fresh()->revoked_at;

    ['credential' => $replacement] = crvEnroll($f['device']);

    // Recovery is a NEW row. The revoked one stays exactly as revocation left
    // it, because it is the record of a security decision — reusing or clearing
    // it would erase the reason the decision was made.
    expect($revoked->fresh()->revoked_at)->not->toBeNull()
        ->and($revoked->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue()
        ->and($revoked->fresh()->revoked_reason)->not->toBeNull()
        ->and($revoked->fresh()->isRevoked())->toBeTrue()
        ->and($replacement->id)->toBeGreaterThan($revoked->id);
});

it('binds a recovery login to the replacement credential and never to the revoked one', function () {
    $f = crvDualProofFixture();
    ['credential' => $revoked] = crvEnroll($f['device']);
    crvFlags(enforcement: true, webauthn: true);

    crvRevokeViaRoute($f['device'], $revoked);

    ['authenticator' => $replacementAuthenticator, 'credential' => $replacement] = crvEnroll($f['device']);

    crvWebAuthnSignIn($f, $replacementAuthenticator);

    expect(session(DoctorAppLoginGate::SESSION_PROOF_TYPE))->toBe(DoctorSessionProof::TYPE_WEBAUTHN)
        ->and(session(DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID))->toBe($replacement->id)
        ->and(session(DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID))->not->toBe($revoked->id)
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($f['device']->id);

    // ...and the recovered session survives the per-request check.
    get(route('profile.edit'))->assertOk();

    expect(auth()->check())->toBeTrue();
});

/* ---------------------------------------------------------------------------
 | Scope: containment reaches the pilot doctor and stops there
 |-------------------------------------------------------------------------- */

it('ends only the pilot doctor\'s session when their credential is revoked', function () {
    $pilot = crvDualProofFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = crvEnroll($pilot['device']);
    crvFlags(enforcement: true, webauthn: true);

    crvWebAuthnSignIn($pilot, $authenticator);

    $other = crvDualProofFixture();

    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        [
            'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
            'pilot' => ['doctor_user_id' => $pilot['user']->id],
        ],
    ));

    crvRevokeViaRoute($pilot['device'], $credential);

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();

    // A containment action that reaches a doctor nobody armed anything against
    // is a clinical outage, not a containment action.
    actingAs($other['user']);
    get(route('profile.edit'))->assertOk();

    expect(auth()->check())->toBeTrue()
        ->and(app(DoctorAppLoginGate::class)->inEnforcementScope($other['user']->fresh()))->toBeFalse();
});
