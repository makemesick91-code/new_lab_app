<?php

/**
 * DOCTOR-PWA-WEBAUTHN-PARENT-CLOSURE-1 — the pilot shape, exercised.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM THE OTHER WEBAUTHN SUITES
 *
 * Every existing successful-admission test arms the feature FLEET-WIDE. The
 * shared `waFlags()` / `pbFlags()` / `crvFlags()` helpers each set
 * `doctor_device_enforcement.scope.mode = 'unscoped'` AND
 * `android_release.enforcement.scope.global_permitted = true`, because those
 * suites are about the credential mechanics and want the scope out of the way.
 *
 * That left a real hole. The two suites that DO set `MODE_PILOT` sign the
 * doctor in first under unscoped mode and only then narrow the scope, so they
 * prove containment, not admission. Before this file, NO test completed a
 * WebAuthn login while the enforcement scope actually named the pilot doctor —
 * which is the one configuration production runs.
 *
 * So these tests deliberately never touch `global_permitted`. It stays at its
 * committed `false`, exactly as it ships. A pilot that only works when the
 * fleet-wide escape hatch is also open would not be a pilot.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Tests\Support\FakeWebAuthnAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

const PC_ORIGIN = 'https://clinic.example.test';
const PC_RP_ID = 'clinic.example.test';

/* --------------------------------------------------------------------------
 | Helpers — prefixed `pc`, following the `wa`, `pb` and `crv` convention. Pest
 | loads test files in an order this file cannot rely on, so it never calls
 | another file's helpers.
 * ------------------------------------------------------------------------ */

function pcRelyingParty(): void
{
    config()->set('app.url', PC_ORIGIN);
    config()->set('webauthn.relying_party.id', PC_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', PC_ORIGIN);
}

/**
 * Arm the two switches the operator actually flips on the VPS.
 *
 * The flag KEY contains a dot, so the whole `feature_flags.flags` array is
 * rewritten rather than reached with dot notation — `config()->set()` would
 * build a nested structure FeatureFlagService never reads, and the test would
 * pass with the flag quietly still off.
 */
function pcFlags(bool $enforcement, bool $webauthn): void
{
    $flags = config('feature_flags.flags', []);

    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $enforcement;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $enforcement;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['default'] = $webauthn;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['env_value'] = $webauthn;

    config()->set('feature_flags.flags', $flags);
}

/**
 * Point the enforcement scope at exactly one doctor — the production shape.
 *
 * `global_permitted` is deliberately NOT set here. It ships false, and every
 * assertion in this file depends on it staying false.
 */
function pcPilotScope(User $pilot): void
{
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        [
            'mode' => AndroidDoctorEnforcementScope::MODE_PILOT,
            'pilot' => ['doctor_user_id' => $pilot->id],
        ],
    ));
}

/**
 * A doctor, a linked account, an ACTIVE device and an ACTIVE authorization.
 *
 * @return array{user: User, doctor: Doctor, device: DoctorDevice, branch: Branch, authorization: DoctorDeviceAuthorization}
 */
function pcClinicFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $user = User::factory()->create(['password' => bcrypt('rahasia-klinik')]);
    $user->assignRole('Doctor');

    $doctor = Doctor::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    $authorization = DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => DoctorDeviceAuthorization::STATUS_ACTIVE,
    ]);

    return [
        'user' => $user,
        'doctor' => $doctor,
        'device' => $device,
        'branch' => $branch,
        'authorization' => $authorization,
    ];
}

/**
 * Enrol a device-bound credential through the real operator HTTP path.
 *
 * @return array{credential: DoctorDeviceWebAuthnCredential, authenticator: FakeWebAuthnAuthenticator}
 */
function pcEnroll(DoctorDevice $device): array
{
    $operator = superAdmin();
    $authenticator = new FakeWebAuthnAuthenticator(PC_RP_ID, PC_ORIGIN, false, false);

    actingAs($operator);

    $options = postJson(route('settings.doctor-devices.webauthn.options', $device))->json();

    post(route('settings.doctor-devices.webauthn.store', $device), [
        'credential' => $authenticator->attestation($options['challenge']),
    ]);

    auth()->logout();

    return [
        'credential' => DoctorDeviceWebAuthnCredential::query()
            ->where('credential_id', $authenticator->credentialIdBase64Url())
            ->first(),
        'authenticator' => $authenticator,
    ];
}

/** Walk a doctor to the point where an assertion may be posted. */
function pcReachAssertionStep(array $fixture): string
{
    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik']);

    return postJson(route('doctor-device-webauthn.options'))->json('challenge');
}

beforeEach(function () {
    pcRelyingParty();
});

/* --------------------------------------------------------------------------
 | PC-1 — the gap this file was written to close.
 * ------------------------------------------------------------------------ */

it('completes a webauthn login for the pilot doctor while the scope names only that doctor', function () {
    $pilot = pcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = pcEnroll($pilot['device']);

    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($pilot['user']);

    // The fleet-wide escape hatch stays shut for the whole ceremony.
    expect(config('android_release.enforcement.scope.global_permitted'))->toBeFalse();

    $challenge = pcReachAssertionStep($pilot);

    // Password alone did not admit anyone.
    expect(auth()->check())->toBeFalse();

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $pilot['device']->uuid),
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($pilot['user']->id)
        ->and(session(DoctorAppLoginGate::SESSION_PROOF_TYPE))->toBe(DoctorSessionProof::TYPE_WEBAUTHN)
        ->and(session(DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID))->toBe($credential->id)
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($pilot['device']->id)
        ->and(session(DoctorAppLoginGate::SESSION_DOCTOR_ID))->toBe($pilot['doctor']->id)
        ->and(session(DoctorAppLoginGate::SESSION_AUTHORIZATION_ID))->toBe($pilot['authorization']->id);
});

it('keeps the pilot doctor authenticated across a protected request after a scoped webauthn login', function () {
    $pilot = pcClinicFixture();
    ['authenticator' => $authenticator] = pcEnroll($pilot['device']);

    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($pilot['user']);

    $challenge = pcReachAssertionStep($pilot);
    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $pilot['device']->uuid),
    ]);

    // Revalidation runs on every protected request; it must not end the session
    // it just admitted.
    get(route('profile.edit'))->assertSuccessful();

    expect(auth()->check())->toBeTrue();
});

/* --------------------------------------------------------------------------
 | PC-9 / PC-10 — isolation, proven while the pilot is genuinely armed.
 * ------------------------------------------------------------------------ */

it('leaves a non-pilot doctor on ordinary browser login while the pilot doctor is enforced', function () {
    $pilot = pcClinicFixture();
    pcEnroll($pilot['device']);

    $other = pcClinicFixture();

    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($pilot['user']);

    $gate = app(DoctorAppLoginGate::class);

    expect($gate->inEnforcementScope($pilot['user']->fresh()))->toBeTrue()
        ->and($gate->inEnforcementScope($other['user']->fresh()))->toBeFalse();

    // The non-pilot doctor signs in with a password and is simply in.
    post(route('login'), ['email' => $other['user']->email, 'password' => 'rahasia-klinik']);

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($other['user']->id)
        // and crucially carries no device proof at all
        ->and(session(DoctorAppLoginGate::SESSION_PROOF_TYPE))->toBeNull();

    get(route('profile.edit'))->assertSuccessful();
    expect(auth()->check())->toBeTrue();
});

/* --------------------------------------------------------------------------
 | PC-11 — the fleet-wide boundary, asserted behaviourally.
 * ------------------------------------------------------------------------ */

it('refuses to cover anybody when the scope mode is widened without the source-controlled permit', function () {
    $pilot = pcClinicFixture();
    $other = pcClinicFixture();

    pcFlags(enforcement: true, webauthn: true);

    // A host flipping the scope mode alone — the env-reachable half.
    config()->set('doctor_device_enforcement.scope', array_replace_recursive(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => AndroidDoctorEnforcementScope::MODE_UNSCOPED],
    ));

    $scope = app(AndroidDoctorEnforcementScope::class);

    // Unusable, therefore covering nobody — not covering everybody.
    expect($scope->isUsable())->toBeFalse()
        ->and($scope->coversUser($pilot['user']->id))->toBeFalse()
        ->and($scope->coversUser($other['user']->id))->toBeFalse();

    // And the doctor still logs in with a password, which is the clinical
    // consequence that matters: widening the mode fails OPEN for availability,
    // never closed for the whole fleet.
    post(route('login'), ['email' => $pilot['user']->email, 'password' => 'rahasia-klinik']);

    expect(auth()->check())->toBeTrue();
});

/* --------------------------------------------------------------------------
 | PC-12 — rollback, both switches named explicitly.
 * ------------------------------------------------------------------------ */

it('restores ordinary browser login for the pilot doctor when both switches go off', function () {
    $pilot = pcClinicFixture();
    pcEnroll($pilot['device']);

    // Armed first, so this proves a transition rather than a fresh boot.
    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($pilot['user']);

    expect(app(DoctorAppLoginGate::class)->inEnforcementScope($pilot['user']->fresh()))->toBeTrue();

    // The canonical rollback: both flags off. The scope is deliberately left
    // pointing at the pilot doctor, because rollback must not depend on also
    // remembering to unwind the scope.
    pcFlags(enforcement: false, webauthn: false);

    post(route('login'), ['email' => $pilot['user']->email, 'password' => 'rahasia-klinik']);

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($pilot['user']->id);

    get(route('profile.edit'))->assertSuccessful();
    expect(auth()->check())->toBeTrue();
});

/* --------------------------------------------------------------------------
 | PC-14 — registration is not gated by the login-admission switch.
 * ------------------------------------------------------------------------ */

it('enrols a credential identically whether or not the login admission switch is on', function () {
    // Admission OFF — enrolment is an operator action, not a doctor login.
    $withFlagOff = pcClinicFixture();
    pcFlags(enforcement: false, webauthn: false);
    ['credential' => $offCredential] = pcEnroll($withFlagOff['device']);

    // Admission ON — same operator path, same outcome.
    $withFlagOn = pcClinicFixture();
    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($withFlagOn['user']);
    ['credential' => $onCredential] = pcEnroll($withFlagOn['device']);

    foreach ([$offCredential, $onCredential] as $credential) {
        expect($credential)->not->toBeNull()
            ->and($credential->revoked_at)->toBeNull()
            ->and((bool) $credential->user_verified)->toBeTrue()
            ->and((bool) $credential->backup_eligible)->toBeFalse()
            ->and($credential->device_bound_verdict)
            ->toBe(DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND);
    }
});

/* --------------------------------------------------------------------------
 | PC-5 (assertion half) — found by mutation, not by reading.
 |
 | Registration already refuses a syncable credential, and that was mistaken for
 | full coverage of "the credential must be device-bound". It is not. Deleting
 | the device-binding check in `DoctorAppLoginGate::deviceProofDenyReason()` —
 | the one the revalidation path runs on EVERY protected request — left all 80
 | WebAuthn tests green.
 |
 | The gap matters because the check exists precisely for the case registration
 | cannot cover: a credential stored while the policy was loose must stop
 | working when the policy is tightened, not merely stop being issued. That is
 | parent rule P-22, and until this test nothing held it.
 * ------------------------------------------------------------------------ */

it('ends a webauthn session whose stored credential is no longer device-bound', function () {
    $pilot = pcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = pcEnroll($pilot['device']);

    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($pilot['user']);

    $challenge = pcReachAssertionStep($pilot);
    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $pilot['device']->uuid),
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue();

    // The policy tightens underneath a live session: this stored credential is
    // now judged backup-eligible, i.e. reachable from the doctor's personal
    // phone. Nothing about the device, the authorization or the revocation
    // state changes — only the binding verdict.
    $credential->forceFill([
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE,
    ])->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();

    // And it is terminal for the session, not a one-off redirect.
    get(route('profile.edit'))->assertRedirect(route('login'));

    // The identity records are untouched — this is a proof failure, not a
    // revocation.
    expect($pilot['device']->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE)
        ->and($pilot['authorization']->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE)
        ->and($credential->fresh()->revoked_at)->toBeNull();
});

it('refuses an unknown binding verdict as firmly as a backup-eligible one', function () {
    $pilot = pcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = pcEnroll($pilot['device']);

    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($pilot['user']);

    $challenge = pcReachAssertionStep($pilot);
    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $pilot['device']->uuid),
    ]);

    expect(auth()->check())->toBeTrue();

    // BE absent entirely — the authenticator never said. Fail closed, because
    // "we could not tell" is not "device-bound".
    $credential->forceFill([
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN,
    ])->save();

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

/* --------------------------------------------------------------------------
 | PC-15 — arming and a successful login mutate no identity row.
 * ------------------------------------------------------------------------ */

it('leaves the device and the authorization untouched across arming and a pilot login', function () {
    $pilot = pcClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = pcEnroll($pilot['device']);

    $deviceBefore = $pilot['device']->fresh()->only(['id', 'uuid', 'status', 'branch_id', 'revoked_at']);
    $authBefore = $pilot['authorization']->fresh()->only(['id', 'uuid', 'status', 'doctor_id', 'doctor_device_id', 'revoked_at']);

    pcFlags(enforcement: true, webauthn: true);
    pcPilotScope($pilot['user']);

    $challenge = pcReachAssertionStep($pilot);
    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($challenge, (string) $pilot['device']->uuid),
    ])->assertRedirect();

    expect($pilot['device']->fresh()->only(['id', 'uuid', 'status', 'branch_id', 'revoked_at']))->toBe($deviceBefore)
        ->and($pilot['authorization']->fresh()->only(['id', 'uuid', 'status', 'doctor_id', 'doctor_device_id', 'revoked_at']))->toBe($authBefore);

    // The credential is used, never replaced: one credential, still the same row.
    expect(DoctorDeviceWebAuthnCredential::query()->where('doctor_device_id', $pilot['device']->id)->count())->toBe(1)
        ->and($credential->fresh()->revoked_at)->toBeNull();
});
