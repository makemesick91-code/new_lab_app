<?php

declare(strict_types=1);

/**
 * DOCTOR-ACCESS-PR-A-SINGLE-SESSION-FOUNDATION (owner decision O4) — one
 * doctor, several trusted tablets.
 *
 * THE CLAIM UNDER TEST. Every eligible doctor must be able to authenticate from
 * every ACTIVE, approved clinic tablet — and single-active-session may not take
 * that away. It bounds how many sessions exist AT ONCE; it must not bound WHICH
 * tablet a doctor may walk to next.
 *
 * WHAT THIS PULL REQUEST SCOPES OUT, SAID HERE SO NO TEST BELOW OVERSTATES ITS
 * RESULT. PR-A ships the lease only. The branch lock — what a doctor may SEE and
 * WRITE from a borrowed tablet, and the rule that the tablet never decides the
 * branch — arrives in PR-B, with the resolver and the tables it needs. So the
 * cross-branch case below asserts only that the login is NOT REFUSED for being
 * on another branch's tablet; it deliberately asserts nothing about which branch
 * the session then operates in, because on this base nothing records that and a
 * test that implied otherwise would be asserting a capability that is not here.
 *
 * WHAT EACH TEST ACTUALLY EXERCISES. Real cryptography, through the real login
 * route, with `FakeWebAuthnAuthenticator` — a genuine EC P-256 key pair signing
 * real authenticator data, verified by the library with no idea it came from a
 * test. Nothing about the device path is mocked or bypassed, because the
 * property being defended is a refusal, and a mocked verifier cannot show that
 * a refusal happened for the right reason.
 *
 * THE TWO AXES OF "TRUSTED", NAMED HERE SO NO TEST BELOW OVERSTATES ITS RESULT.
 * `DoctorAppLoginGate::deviceProofDenyReason()` judges a WebAuthn session on
 * (a) the device being administratively ACTIVE and (b) a usable, device-bound
 * credential belonging to THAT device, plus an ACTIVE authorization for THIS
 * doctor. `identity_state = cryptographically_verified` is the ANDROID KEYSTORE
 * axis and is deliberately NOT a WebAuthn predicate — a browser-only tablet has
 * no keystore key, and the credential is its own cryptographic proof. Identity
 * verification is enforced where it belongs, at the AUTHORIZATION boundary,
 * which is not this pull request's surface. So "an unverified device is rejected"
 * is proven below as "a device that was never approved into ACTIVE cannot mint a
 * session", which is what the login path actually decides.
 *
 * WHAT THIS FILE DOES NOT CLAIM. The CONCURRENT case — tablet B refused while
 * tablet A's session is genuinely live — is the lease suite's subject, because
 * proving it needs a hand-inserted `sessions` row: every login in one test
 * process migrates the shared session id, which destroys the incumbent's row and
 * turns the refusal into a legitimate dead-session reclaim. Asserting it here
 * would prove the fixture, not the rule. This file proves the SERIAL case, which
 * is the one owner decision O4 is about.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Models\DoctorSessionLease;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeWebAuthnAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

require_once __DIR__.'/helpers.php';

const DMD_ORIGIN = 'https://tablet.daengtisia.test';
const DMD_RP_ID = 'tablet.daengtisia.test';

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

/**
 * A usable relying party.
 *
 * TRAP: with no `webauthn.relying_party.id` the id is derived from `app.url`,
 * and `assertUsable()` refuses a plaintext origin — so the ceremony would fail
 * for a configuration reason and the test would look like a device refusal.
 */
function dmdRelyingParty(): void
{
    config()->set('app.url', DMD_ORIGIN);
    config()->set('webauthn.relying_party.id', DMD_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', DMD_ORIGIN);
}

/**
 * Arm the doctor device lock.
 *
 * TRAP, the same one helpers.php documents for this sprint's own flags: the flag
 * KEY contains a dot, so `config()->set('feature_flags.flags.doctor.…')` builds
 * a nested structure FeatureFlagService never reads and the test then passes
 * with enforcement quietly OFF — which for a DENIAL test means it proves
 * nothing. The whole `flags` array is rewritten, and BOTH `default` and
 * `env_value` are written because a captured `env_value` wins.
 *
 * The committed scope is a pilot scope with a null target, which covers NOBODY,
 * so fleet-wide scope is stated explicitly here. That is a test-local override
 * of two committed values; that they are still committed OFF is asserted in
 * DoctorSessionLeaseGovernanceTest, so this override can never quietly become the
 * production posture, and DoctorSessionLeaseGovernanceTest is where that is
 * asserted.
 */
function dmdArmDeviceLock(bool $webauthn = true): void
{
    $flags = config('feature_flags.flags', []);

    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = true;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = true;
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

/**
 * A clinic tablet that has actually been approved into service.
 *
 * TRAP: `DoctorDeviceFactory` defaults to `identity_state = unverified` with a
 * null `public_key`, which is NOT trustworthy. Both are set here so the tablet
 * is the real thing: ACTIVE, cryptographically verified, holding a key — the
 * state a tablet that has actually been approved into service is in.
 */
function dmdTablet(Branch $branch, string $name): DoctorDevice
{
    return DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'device_name' => $name,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
        'public_key' => base64_encode('dmd-fixture-public-key-'.$name),
        'key_algorithm' => 'EC',
    ]);
}

/**
 * The explicit per-pair audit boundary: MODEL A, never fleet-wide implicit
 * trust. A trusted tablet on its own admits nobody.
 */
function dmdAuthorize(Doctor $doctor, DoctorDevice $device): DoctorDeviceAuthorization
{
    return DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
    ]);
}

/**
 * Enrol a browser credential against a tablet, through the real registration
 * route.
 *
 * TRAP: registration is an OPERATOR action, so it runs as a Super Admin and not
 * as the doctor. `actingAs()` fires Authenticated rather than Login, so it never
 * claims a session lease — which is exactly why enrolling here cannot disturb
 * the lease assertions further down.
 *
 * @return array{credential: ?DoctorDeviceWebAuthnCredential, authenticator: FakeWebAuthnAuthenticator}
 */
function dmdEnrol(DoctorDevice $device, bool $backupEligible = false): array
{
    $authenticator = new FakeWebAuthnAuthenticator(DMD_RP_ID, DMD_ORIGIN, $backupEligible, $backupEligible);

    actingAs(daSuperAdmin());

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

/**
 * Walk a doctor from the password step to the point where an assertion may be
 * posted, and hand back the challenge.
 *
 * The password step alone must never yield a session, so this asserts that on
 * the way through rather than trusting it.
 */
function dmdReachAssertionStep(User $user): string
{
    daLoginPost($user)->assertRedirect(route('doctor-device-webauthn.show'));

    expect(auth()->check())->toBeFalse();

    return (string) postJson(route('doctor-device-webauthn.options'))->json('challenge');
}

/**
 * Complete a login from one tablet: password step, options, assertion.
 */
function dmdLoginFromTablet(
    User $user,
    DoctorDevice $device,
    FakeWebAuthnAuthenticator $authenticator,
    bool $userVerified = true,
): TestResponse {
    $challenge = dmdReachAssertionStep($user);

    return post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion(
            $challenge,
            (string) $device->uuid,
            userVerified: $userVerified,
        ),
    ]);
}

/**
 * The last recorded refusal reason for a WebAuthn login attempt.
 *
 * The refusal MESSAGE is deliberately one opaque sentence for every cause, so
 * the reason a login was refused is only knowable from the audit trail. A test
 * that asserted the message alone could not tell "wrong tablet" from "no
 * authorization", which are the two findings this file has to keep apart.
 */
function dmdLastRejectionReason(): ?string
{
    $log = AuditLog::query()
        ->where('action', 'DOCTOR_DEVICE_WEBAUTHN_LOGIN_REJECTED')
        ->orderByDesc('id')
        ->first();

    return $log === null ? null : ($log->new_values['reason'] ?? null);
}

beforeEach(function () {
    dmdRelyingParty();
});

/*
|--------------------------------------------------------------------------
| Serial access across every trusted tablet — owner decision O4
|--------------------------------------------------------------------------
*/

it('lets a doctor authenticate from each active trusted tablet in turn', function () {
    $branch = daBranch('TLK1');
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$branch]);

    $ward = dmdTablet($branch, 'Tablet Bangsal');
    $office = dmdTablet($branch, 'Tablet Ruang Dokter');

    $wardAuthorization = dmdAuthorize($doctor, $ward);
    $officeAuthorization = dmdAuthorize($doctor, $office);

    ['authenticator' => $wardKey] = dmdEnrol($ward);
    ['authenticator' => $officeKey] = dmdEnrol($office);

    // BOTH capabilities live at once. Enforcement is what makes the ceremony
    // happen; the lease is what could wrongly bound it to one tablet.
    daArmDoctorAccess();
    dmdArmDeviceLock();

    // --- tablet one ------------------------------------------------------
    dmdLoginFromTablet($user, $ward, $wardKey)->assertRedirect();

    expect(auth()->id())->toBe($user->id)
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($ward->id)
        ->and(session(DoctorAppLoginGate::SESSION_DOCTOR_ID))->toBe($doctor->id);

    daAssertActiveLeaseCount(1, $user);

    // --- and out again ---------------------------------------------------
    post(route('logout'))->assertRedirect();

    expect(auth()->check())->toBeFalse();

    daAssertActiveLeaseCount(0, $user);

    // The lease was handed back as a LOGOUT, not reclaimed as a dead session:
    // a doctor who signs out has ended their session, and the trail must say
    // so rather than leaving the next login to infer it.
    expect(DoctorSessionLease::query()
        ->where('user_id', $user->id)
        ->whereNotNull('released_at')
        ->orderByDesc('id')
        ->value('released_reason'))->toBe(DoctorSessionLease::RELEASE_LOGOUT);

    // --- tablet two, same doctor, no re-approval of anything -------------
    dmdLoginFromTablet($user, $office, $officeKey)->assertRedirect();

    expect(auth()->id())->toBe($user->id)
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($office->id);

    daAssertActiveLeaseCount(1, $user);

    // Walking between tablets revokes nothing. If moving devices quietly
    // retired an authorization, the doctor would be locked out of the tablet
    // they started on and the fleet would degrade one shift at a time.
    expect($wardAuthorization->refresh()->isActive())->toBeTrue()
        ->and($officeAuthorization->refresh()->isActive())->toBeTrue()
        ->and(DoctorDeviceAuthorization::query()->whereNotNull('revoked_at')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The tablet's branch is not a login predicate, and never the doctor's branch
|--------------------------------------------------------------------------
*/

it('does not refuse a login merely because the tablet belongs to another branch', function () {
    /*
     * HALF A TEST ON PURPOSE, and the missing half is named rather than left
     * implicit. What PR-A can prove is that the tablet's branch is not a login
     * predicate: a doctor who picks up a colleague's spare tablet from another
     * branch authenticates, gets a session, and is bound to THAT tablet.
     *
     * What it deliberately does NOT assert is which branch the session then
     * operates in. The lease carries no effective branch on this base — the
     * column, the resolver and the home lock all arrive in PR-B — so an
     * assertion about branch authority here could only be written against
     * something that does not exist, and would either fail or, worse, pass
     * vacuously against a null.
     */
    $home = daBranch('TLK1');
    $elsewhere = daBranch('LDK2');

    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$home]);

    // The tablet the doctor is holding sits in a DIFFERENT branch from the one
    // they practise in — a doctor collecting a colleague's spare tablet.
    $visiting = dmdTablet($elsewhere, 'Tablet Cabang Lain');
    dmdAuthorize($doctor, $visiting);

    ['authenticator' => $key] = dmdEnrol($visiting);

    daArmDoctorAccess();
    dmdArmDeviceLock();

    dmdLoginFromTablet($user, $visiting, $key)->assertRedirect();

    // AUTHENTICATED IDENTITY, and DEVICE BINDING. Both halves matter: a login
    // that succeeded but bound the session to no device would pass an identity
    // assertion while leaving the tablet unaccounted for.
    expect(auth()->id())->toBe($user->id)
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($visiting->id)
        ->and(session(DoctorAppLoginGate::SESSION_DOCTOR_ID))->toBe($doctor->id);

    // And the lease was claimed for the doctor rather than refused, which is the
    // single-session engine agreeing that a borrowed tablet is still one session.
    daAssertActiveLeaseCount(1, $user);
});

/*
|--------------------------------------------------------------------------
| The refusals that must survive multi-tablet access
|--------------------------------------------------------------------------
*/

it('still refuses a revoked tablet while the doctor other tablet keeps working', function () {
    $branch = daBranch('TLK1');
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$branch]);

    $good = dmdTablet($branch, 'Tablet Aktif');
    $stolen = dmdTablet($branch, 'Tablet Hilang');

    dmdAuthorize($doctor, $good);
    dmdAuthorize($doctor, $stolen);

    // A credential on the still-active tablet, so the ceremony is reachable and
    // the refusal below is about the STOLEN tablet rather than about the doctor
    // having nothing to assert with.
    dmdEnrol($good);
    ['authenticator' => $stolenKey] = dmdEnrol($stolen);

    dmdArmDeviceLock();

    $stolen->forceFill([
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
        'revoked_reason' => 'Hilang di perjalanan.',
    ])->save();

    dmdLoginFromTablet($user, $stolen, $stolenKey)->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse()
        ->and(dmdLastRejectionReason())->toBe('device_not_usable')
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull();
});

it('still refuses a tablet that was never approved into service', function () {
    $branch = daBranch('TLK1');
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$branch]);

    $approved = dmdTablet($branch, 'Tablet Disetujui');

    // Registered, unverified, awaiting a human decision — the state every new
    // tablet starts in. It carries an ACTIVE authorization and a real
    // credential on purpose: neither of those may substitute for approval.
    $awaiting = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'device_name' => 'Tablet Baru',
        'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_PENDING,
    ]);

    dmdAuthorize($doctor, $approved);
    dmdAuthorize($doctor, $awaiting);

    dmdEnrol($approved);
    ['authenticator' => $awaitingKey, 'credential' => $awaitingCredential] = dmdEnrol($awaiting);

    // Stated rather than assumed: enrolment refuses only a REVOKED device, so
    // the credential really does exist and the refusal below is the login gate
    // doing its job, not registration having quietly declined.
    expect($awaitingCredential)->not->toBeNull();

    dmdArmDeviceLock();

    dmdLoginFromTablet($user, $awaiting, $awaitingKey)->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse()
        ->and(dmdLastRejectionReason())->toBe('device_not_usable');
});

it('still requires the exact doctor to device authorization', function () {
    $branch = daBranch('TLK1');
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$branch]);
    ['doctor' => $colleague] = daDoctorAccount([$branch]);

    $mine = dmdTablet($branch, 'Tablet Saya');
    $theirs = dmdTablet($branch, 'Tablet Rekan');

    // Our doctor is authorized on ONE tablet. The other tablet is perfectly
    // trusted and perfectly usable — by somebody else.
    dmdAuthorize($doctor, $mine);
    dmdAuthorize($colleague, $theirs);

    dmdEnrol($mine);
    ['authenticator' => $theirKey] = dmdEnrol($theirs);

    dmdArmDeviceLock();

    dmdLoginFromTablet($user, $theirs, $theirKey)->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse()
        // NOT `device_not_usable`: the tablet is fine. The pair is what is
        // missing, which is the whole point of keeping the authorization table.
        ->and(dmdLastRejectionReason())->toBe('authorization_not_active')
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull();
});

it('still refuses a doctor whose only authorization has been revoked', function () {
    $branch = daBranch('TLK1');
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$branch]);

    $tablet = dmdTablet($branch, 'Tablet Satu-satunya');
    $authorization = dmdAuthorize($doctor, $tablet);

    dmdEnrol($tablet);
    dmdArmDeviceLock();

    $authorization->forceFill([
        'status' => DoctorDeviceAuthorization::STATUS_REVOKED,
        'revoked_at' => now(),
        'revoked_reason' => 'Dokter pindah cabang.',
    ])->save();

    // The ceremony is not even offered: with no ACTIVE authorization there is no
    // usable credential to advertise, so the password step ends in a denial
    // rather than a dead-end biometric prompt.
    daLoginPost($user)->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The WebAuthn predicates are unchanged — asserted, not assumed
|--------------------------------------------------------------------------
*/

it('keeps user verification, device binding and the refusal of syncable credentials', function () {
    // The three committed predicates. Read from the configuration and the
    // canonical support class rather than restated, so loosening either one
    // fails here instead of failing quietly on a tablet.
    expect(config('webauthn.ceremony.user_verification'))->toBe('required')
        ->and(WebAuthnRelyingParty::fromConfig()->requiresUserVerification())->toBeTrue()
        ->and(config('webauthn.device_binding.require_device_bound'))->toBeTrue()
        ->and(WebAuthnDeviceBinding::isRequired())->toBeTrue();

    // A credential the hardware says may leave the device is not a clinic
    // device, whatever it is registered against.
    expect(WebAuthnDeviceBinding::isAcceptable(
        WebAuthnDeviceBinding::verdict(true)
    ))->toBeFalse()
        ->and(WebAuthnDeviceBinding::isAcceptable(
            WebAuthnDeviceBinding::verdict(null)
        ))->toBeFalse()
        ->and(WebAuthnDeviceBinding::isAcceptable(
            WebAuthnDeviceBinding::verdict(false)
        ))->toBeTrue();
});

it('refuses to admit a second tablet on a syncable credential', function () {
    $branch = daBranch('TLK1');
    ['doctor' => $doctor] = daDoctorAccount([$branch]);

    $first = dmdTablet($branch, 'Tablet Satu');
    $second = dmdTablet($branch, 'Tablet Dua');

    dmdAuthorize($doctor, $first);
    dmdAuthorize($doctor, $second);

    ['credential' => $bound] = dmdEnrol($first);

    // BE=1: non-exportable from the hardware AND present in the signed-in
    // account, therefore also on the doctor's personal phone. Extending a
    // doctor to a second tablet must never be a way in for one.
    ['credential' => $syncable] = dmdEnrol($second, backupEligible: true);

    expect($bound)->not->toBeNull()
        ->and($bound->device_bound_verdict)->toBe(DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND)
        ->and($syncable)->toBeNull()
        ->and(DoctorDeviceWebAuthnCredential::query()->count())->toBe(1);
});

it('refuses an assertion from a trusted tablet that skipped user verification', function () {
    $branch = daBranch('TLK1');
    ['user' => $user, 'doctor' => $doctor] = daDoctorAccount([$branch]);

    $tablet = dmdTablet($branch, 'Tablet Tanpa Biometrik');
    dmdAuthorize($doctor, $tablet);

    ['authenticator' => $key] = dmdEnrol($tablet);

    dmdArmDeviceLock();

    // Everything else about this login is correct: approved tablet, active
    // authorization, device-bound credential, genuine signature. Only the
    // human is missing.
    dmdLoginFromTablet($user, $tablet, $key, userVerified: false)
        ->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse()
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull();
});
