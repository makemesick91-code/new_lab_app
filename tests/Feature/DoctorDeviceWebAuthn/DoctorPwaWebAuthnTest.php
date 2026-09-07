<?php

/**
 * DOCTOR-PWA-WEBAUTHN-1 — a browser earning the device-bound session.
 *
 * WHAT THESE TESTS ACTUALLY EXERCISE
 *
 * Real cryptography. `FakeWebAuthnAuthenticator` generates a genuine EC P-256
 * key pair, builds real authenticator data and real client data, and signs them
 * with OpenSSL; the library verifies them with no idea they came from a test.
 * Nothing here is mocked, because a mocked verifier proves only that a function
 * was called — it cannot show that a forged signature, a replayed challenge or
 * a foreign origin is refused, and those are the properties the feature is.
 *
 * THE MOST IMPORTANT TEST IN THIS FILE
 *
 * "a syncable credential is refused". A platform authenticator on a modern
 * Android tablet routinely creates a passkey that is non-exportable from the
 * hardware AND present in the signed-in Google account — and therefore on the
 * doctor's personal phone. If that credential were accepted, "approved physical
 * clinic device" would be a label rather than a security property. BE=0 is the
 * only signal that distinguishes the two, so it is a hard gate.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnChallenge;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeWebAuthnAuthenticator;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

const WA_ORIGIN = 'https://clinic.example.test';
const WA_RP_ID = 'clinic.example.test';

/**
 * Flip both switches.
 *
 * The whole `feature_flags.flags` array is rewritten rather than reached with
 * dot notation, because the flag KEY itself contains a dot — `config()->set()`
 * would silently build a nested structure FeatureFlagService never reads, and
 * the test would then pass with the flag quietly still off. Same trap the
 * enforcement-gate suite documents.
 */
function waFlags(bool $enforcement, bool $webauthn): void
{
    $flags = config('feature_flags.flags', []);

    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $enforcement;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $enforcement;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['default'] = $webauthn;
    $flags[DoctorDeviceWebAuthnLoginService::FLAG]['env_value'] = $webauthn;

    config()->set('feature_flags.flags', $flags);

    // Fleet-wide scope, stated explicitly: the committed default is a pilot
    // scope with no target, which covers nobody.
    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => 'unscoped'],
    ));

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => true],
    ));
}

function waRelyingParty(): void
{
    config()->set('app.url', WA_ORIGIN);
    config()->set('webauthn.relying_party.id', WA_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', WA_ORIGIN);
}

/**
 * A doctor, a linked account, an ACTIVE device and an ACTIVE authorization.
 *
 * @return array{user: User, doctor: Doctor, device: DoctorDevice, branch: Branch}
 */
function waClinicFixture(): array
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

    DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => DoctorDeviceAuthorization::STATUS_ACTIVE,
    ]);

    return ['user' => $user, 'doctor' => $doctor, 'device' => $device, 'branch' => $branch];
}

/**
 * Register a credential directly through the service, so login tests start from
 * an enrolled device without going through HTTP twice.
 *
 * @return array{credential: DoctorDeviceWebAuthnCredential, authenticator: FakeWebAuthnAuthenticator}
 */
function waEnroll(DoctorDevice $device, bool $backupEligible = false): array
{
    $operator = superAdmin();
    $authenticator = new FakeWebAuthnAuthenticator(WA_RP_ID, WA_ORIGIN, $backupEligible, $backupEligible);

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

beforeEach(function () {
    waRelyingParty();
});

/* ---------------------------------------------------------------------------
 | Relying party configuration
 |-------------------------------------------------------------------------- */

it('derives the relying party id from the application url', function () {
    config()->set('webauthn.relying_party.id', null);
    config()->set('webauthn.relying_party.allowed_origins', '');
    config()->set('app.url', 'https://daengtisia.example/');

    $rp = WebAuthnRelyingParty::fromConfig();

    expect($rp->id())->toBe('daengtisia.example')
        ->and($rp->allowedOrigins())->toBe(['https://daengtisia.example'])
        ->and($rp->usabilityFailure())->toBeNull();
});

it('refuses a plaintext origin outside local development', function () {
    config()->set('app.url', 'http://clinic.example.test');
    config()->set('webauthn.relying_party.allowed_origins', 'http://clinic.example.test');

    expect(WebAuthnRelyingParty::fromConfig()->usabilityFailure())->toBe('insecure_origin');
});

it('refuses an origin that is not under the relying party id', function () {
    config()->set('webauthn.relying_party.id', 'clinic.example.test');
    config()->set('webauthn.relying_party.allowed_origins', 'https://elsewhere.example');

    expect(WebAuthnRelyingParty::fromConfig()->usabilityFailure())->toBe('origin_outside_relying_party');
});

it('refuses to silently downgrade user verification', function () {
    config()->set('webauthn.ceremony.user_verification', 'discouraged');

    expect(fn () => WebAuthnRelyingParty::fromConfig()->userVerification())
        ->toThrow(RuntimeException::class);
});

/* ---------------------------------------------------------------------------
 | Registration
 |-------------------------------------------------------------------------- */

it('registers a device-bound credential against an approved device', function () {
    $fixture = waClinicFixture();

    ['credential' => $credential] = waEnroll($fixture['device']);

    expect($credential)->not->toBeNull()
        ->and($credential->device_bound_verdict)->toBe(DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND)
        ->and($credential->user_verified)->toBeTrue()
        ->and($credential->backup_eligible)->toBeFalse()
        ->and($credential->doctor_device_id)->toBe($fixture['device']->id)
        // The private key never reaches us; the stored material only verifies.
        ->and($credential->public_key)->not->toBeEmpty();
});

it('refuses a syncable credential because it is not confined to the clinic device', function () {
    $fixture = waClinicFixture();
    $operator = superAdmin();
    $authenticator = new FakeWebAuthnAuthenticator(WA_RP_ID, WA_ORIGIN, backupEligible: true, backupState: true);

    actingAs($operator);

    $options = postJson(route('settings.doctor-devices.webauthn.options', $fixture['device']))->json();

    post(route('settings.doctor-devices.webauthn.store', $fixture['device']), [
        'credential' => $authenticator->attestation($options['challenge']),
    ])->assertSessionHasErrors('credential');

    expect(DoctorDeviceWebAuthnCredential::query()->count())->toBe(0);
});

it('refuses a registration signed for a different origin', function () {
    $fixture = waClinicFixture();
    $operator = superAdmin();
    $authenticator = new FakeWebAuthnAuthenticator(WA_RP_ID, WA_ORIGIN);

    actingAs($operator);

    $options = postJson(route('settings.doctor-devices.webauthn.options', $fixture['device']))->json();

    post(route('settings.doctor-devices.webauthn.store', $fixture['device']), [
        'credential' => $authenticator->attestation($options['challenge'], originOverride: 'https://attacker.example'),
    ])->assertSessionHasErrors('credential');

    expect(DoctorDeviceWebAuthnCredential::query()->count())->toBe(0);
});

it('refuses a replayed registration challenge', function () {
    $fixture = waClinicFixture();
    $operator = superAdmin();
    $authenticator = new FakeWebAuthnAuthenticator(WA_RP_ID, WA_ORIGIN);

    actingAs($operator);

    $options = postJson(route('settings.doctor-devices.webauthn.options', $fixture['device']))->json();
    $payload = $authenticator->attestation($options['challenge']);

    post(route('settings.doctor-devices.webauthn.store', $fixture['device']), ['credential' => $payload]);

    // Same nonce, second time. The first use burned it.
    post(route('settings.doctor-devices.webauthn.store', $fixture['device']), ['credential' => $payload])
        ->assertSessionHasErrors('credential');

    expect(DoctorDeviceWebAuthnCredential::query()->count())->toBe(1);
});

it('burns a challenge even when the ceremony fails', function () {
    $fixture = waClinicFixture();
    $operator = superAdmin();
    $authenticator = new FakeWebAuthnAuthenticator(WA_RP_ID, WA_ORIGIN);

    actingAs($operator);

    $options = postJson(route('settings.doctor-devices.webauthn.options', $fixture['device']))->json();

    // A failing attempt. If the burn were rolled back with the failure, this
    // nonce would stay live and become an unlimited retry oracle.
    post(route('settings.doctor-devices.webauthn.store', $fixture['device']), [
        'credential' => $authenticator->attestation($options['challenge'], originOverride: 'https://attacker.example'),
    ]);

    $challenge = DoctorDeviceWebAuthnChallenge::query()
        ->where('challenge', $options['challenge'])
        ->first();

    expect($challenge->consumed_at)->not->toBeNull();
});

it('refuses to enrol a browser without device management permission', function () {
    $fixture = waClinicFixture();

    actingAs($fixture['user']);

    postJson(route('settings.doctor-devices.webauthn.options', $fixture['device']))->assertForbidden();
});

/* ---------------------------------------------------------------------------
 | Login — the flag boundary
 |-------------------------------------------------------------------------- */

it('leaves doctor login untouched while enforcement is off', function () {
    $fixture = waClinicFixture();
    waFlags(enforcement: false, webauthn: true);

    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik'])
        ->assertRedirect();

    expect(auth()->check())->toBeTrue()
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBeNull();
});

it('denies a doctor with no registered credential instead of showing a dead-end ceremony', function () {
    $fixture = waClinicFixture();
    waFlags(enforcement: true, webauthn: true);

    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('denies an enrolled doctor when the webauthn flag is off', function () {
    $fixture = waClinicFixture();
    waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: false);

    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('sends an enrolled doctor to the device step without logging them in', function () {
    $fixture = waClinicFixture();
    waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik'])
        ->assertRedirect(route('doctor-device-webauthn.show'));

    // The password alone must never yield a usable session.
    expect(auth()->check())->toBeFalse()
        ->and(session(DoctorDeviceWebAuthnLoginService::SESSION_PENDING_USER_ID))->toBe($fixture['user']->id);
});

/* ---------------------------------------------------------------------------
 | Login — the assertion
 |-------------------------------------------------------------------------- */

/**
 * Walk a doctor to the point where an assertion may be posted.
 *
 * @return array{authenticator: FakeWebAuthnAuthenticator, device: DoctorDevice, user: User, doctor: Doctor, challenge: string}
 */
function waReachAssertionStep(array $fixture, FakeWebAuthnAuthenticator $authenticator): array
{
    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik']);

    $options = postJson(route('doctor-device-webauthn.options'))->json();

    return [
        'authenticator' => $authenticator,
        'device' => $fixture['device'],
        'user' => $fixture['user'],
        'doctor' => $fixture['doctor'],
        'challenge' => $options['challenge'],
    ];
}

it('completes a doctor login from an approved device and binds the session', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid),
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($fixture['user']->id)
        // The SAME binding ticket redemption writes — which is what makes
        // revocation work for this session through existing machinery.
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($fixture['device']->id)
        ->and(session(DoctorAppLoginGate::SESSION_DOCTOR_ID))->toBe($fixture['doctor']->id);
});

it('refuses a forged signature', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion(
            $step['challenge'],
            (string) $fixture['device']->uuid,
            forgeSignature: true,
        ),
    ])->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse();
});

it('refuses an assertion signed for a different origin', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion(
            $step['challenge'],
            (string) $fixture['device']->uuid,
            originOverride: 'https://attacker.example',
        ),
    ])->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse();
});

it('refuses a replayed assertion challenge', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);
    $payload = $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid);

    post(route('doctor-device-webauthn.store'), ['credential' => $payload]);

    auth()->logout();
    session()->flush();

    post(route('doctor-device-webauthn.store'), ['credential' => $payload])->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('refuses an assertion once the credential is revoked', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    $credential->forceFill(['revoked_at' => now(), 'revoked_reason' => 'hilang'])->save();

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid),
    ])->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse();
});

it('refuses an assertion once the device is revoked', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    $fixture['device']->forceFill([
        'status' => DoctorDevice::STATUS_REVOKED,
        'revoked_at' => now(),
        'revoked_reason' => 'dicuri',
    ])->save();

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid),
    ])->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse();
});

it('refuses a doctor whose authorization on the device was revoked, without touching the device', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    DoctorDeviceAuthorization::query()
        ->where('doctor_id', $fixture['doctor']->id)
        ->update(['status' => DoctorDeviceAuthorization::STATUS_REVOKED, 'revoked_at' => now()]);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid),
    ])->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse()
        // The tablet itself is untouched: this is one doctor losing access, not
        // the device being withdrawn.
        ->and($fixture['device']->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);
});

it('refuses a credential belonging to a device the doctor is not authorized on', function () {
    $fixture = waClinicFixture();
    waFlags(enforcement: true, webauthn: true);

    // A second tablet, enrolled and approved, but with no authorization for
    // this doctor. Device trust and doctor authorization are separate answers.
    $otherDevice = DoctorDevice::factory()->create([
        'branch_id' => $fixture['branch']->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    ['authenticator' => $ownAuthenticator] = waEnroll($fixture['device']);
    ['authenticator' => $foreignAuthenticator] = waEnroll($otherDevice);

    $step = waReachAssertionStep($fixture, $ownAuthenticator);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $foreignAuthenticator->assertion($step['challenge'], (string) $otherDevice->uuid),
    ])->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse();
});

it('refuses an assertion without a live pending login', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    // No password step at all.
    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion('Zm9yZ2Vk', (string) $fixture['device']->uuid),
    ])->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('does not let one visitor complete a challenge issued to another', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);
    $payload = $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid);

    // A different browser: new session, no pending marker of its own beyond a
    // freshly minted one for the same account.
    session()->flush();
    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik']);

    post(route('doctor-device-webauthn.store'), ['credential' => $payload])
        ->assertSessionHasErrors('credential');

    expect(auth()->check())->toBeFalse();
});

/* ---------------------------------------------------------------------------
 | The PWA is not a security boundary
 |-------------------------------------------------------------------------- */

it('never lets the service worker cache a webauthn path', function () {
    $worker = file_get_contents(base_path('public/sw.js'));

    // The worker is deny-by-default: only the allowlist is cacheable, and every
    // WebAuthn path is outside it. Asserting the allowlist has not grown to
    // include one is stronger than adding a denylist, which would imply the
    // allowlist alone was not sufficient.
    expect($worker)->not->toContain('doctor-device-webauthn')
        ->and($worker)->not->toContain('webauthn');

    foreach (['/doctor-device-webauthn', '/doctor-devices/1/webauthn'] as $path) {
        expect(str_contains($worker, "'".$path."'"))->toBeFalse();
    }
});

it('does not make any trust decision from pwa installation state', function () {
    $module = file_get_contents(base_path('resources/js/doctor-device-webauthn.js'));
    $loginView = file_get_contents(base_path('resources/views/auth/doctor-device-webauthn.blade.php'));

    foreach ([$module, $loginView] as $source) {
        expect($source)->not->toContain('display-mode')
            ->and($source)->not->toContain('beforeinstallprompt')
            ->and($source)->not->toContain('localStorage');
    }
});

it('keeps the android device api untouched by this sprint', function () {
    // The Clinic App path is a valid transition and rollback route. Its routes
    // must still exist after a sprint that adds a browser alternative.
    expect(Route::has('device-api.v1.enrollment.request'))->toBeTrue()
        ->and(Route::has('doctor-device-login.redeem'))->toBeTrue();
});

/* ---------------------------------------------------------------------------
 | The session AFTER login — what the `deviceUsable()` change actually decides
 |-------------------------------------------------------------------------- */

it('lets a webauthn-bound session survive the per-request device check', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid),
    ]);

    expect(auth()->check())->toBeTrue();

    // EnsureDoctorDeviceSession runs on every protected request and calls
    // deviceUsable(). Before this sprint that demanded the ANDROID keystore
    // key, so a browser-bound session would have been torn down here on the
    // very next request — a login that appeared to work and then did not.
    get(route('profile.edit'));

    expect(auth()->check())->toBeTrue()
        ->and(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($fixture['device']->id);
});

it('ends an open browser session the moment the credential is revoked', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator, 'credential' => $credential] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid),
    ]);

    expect(auth()->check())->toBeTrue();

    $credential->forceFill(['revoked_at' => now(), 'revoked_reason' => 'tablet hilang'])->save();

    // Revocation has to stop the session that is open RIGHT NOW, not the next
    // one. This works through the existing middleware, unchanged by this
    // sprint — which is the payoff for reusing the binding rather than
    // inventing a second one.
    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

it('ends an open browser session when the webauthn flag is switched off', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    $step = waReachAssertionStep($fixture, $authenticator);

    post(route('doctor-device-webauthn.store'), [
        'credential' => $authenticator->assertion($step['challenge'], (string) $fixture['device']->uuid),
    ]);

    expect(auth()->check())->toBeTrue();

    // The documented rollback: turning the flag off returns the deployment to
    // exactly its previous behaviour, and that has to include a session that is
    // open at the time. A rollback that left browser sessions alive would be a
    // rollback in name only.
    waFlags(enforcement: true, webauthn: false);

    get(route('profile.edit'));

    expect(auth()->check())->toBeFalse();
});

/* ---------------------------------------------------------------------------
 | Enrolment authority and abuse ceilings
 |-------------------------------------------------------------------------- */

it('refuses enrolment to an operator who may only VIEW the device registry', function () {
    $fixture = waClinicFixture();

    // The route group admits `view_doctor_devices|manage_doctor_devices`, so a
    // read-only operator reaches the controller. The policy — not the route — is
    // what stops them, which is the layer that must be pinned.
    actingAs(userWith(['view_doctor_devices']));

    postJson(route('settings.doctor-devices.webauthn.options', $fixture['device']))->assertForbidden();

    post(route('settings.doctor-devices.webauthn.store', $fixture['device']), [
        'credential' => (new FakeWebAuthnAuthenticator(WA_RP_ID, WA_ORIGIN))->attestation('Y2hhbGxlbmdl'),
    ])->assertForbidden();

    expect(DoctorDeviceWebAuthnCredential::query()->count())->toBe(0);
});

it('puts a ceiling on assertion attempts without locking a clinic out', function () {
    $fixture = waClinicFixture();
    ['authenticator' => $authenticator] = waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik']);

    $accepted = 0;

    for ($attempt = 0; $attempt < 45; $attempt++) {
        if (postJson(route('doctor-device-webauthn.options'))->status() === 429) {
            break;
        }

        $accepted++;
    }

    // A ceiling exists…
    expect($accepted)->toBeLessThan(45)
        // …and it is not so tight that a clinic full of tablets trips it during
        // an ordinary morning. One sign-in costs two requests.
        ->and($accepted)->toBeGreaterThanOrEqual(20);
});

/* ---------------------------------------------------------------------------
 | The two pages actually render
 |-------------------------------------------------------------------------- */

it('renders the enrolment page for an operator who may manage the device', function () {
    $fixture = waClinicFixture();
    ['credential' => $credential] = waEnroll($fixture['device']);

    actingAs(superAdmin());

    // A Blade prop mismatch is invisible until a page is rendered, and this one
    // would only be rendered by an operator standing at a tablet.
    get(route('settings.doctor-devices.webauthn.create', $fixture['device']))
        ->assertOk()
        ->assertSee($fixture['device']->device_name)
        ->assertSee('Terikat perangkat')
        // The credential id and public key are the device's identity material.
        // They are not secrets, but the admin UI has no reason to spray them.
        ->assertDontSee($credential->credential_id)
        ->assertDontSee($credential->public_key);
});

it('renders the device step for a doctor who is mid-login', function () {
    $fixture = waClinicFixture();
    waEnroll($fixture['device']);
    waFlags(enforcement: true, webauthn: true);

    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik']);

    get(route('doctor-device-webauthn.show'))
        ->assertOk()
        ->assertSee($fixture['user']->name);
});

it('sends a visitor with no pending login back to the login form', function () {
    waFlags(enforcement: true, webauthn: true);

    get(route('doctor-device-webauthn.show'))->assertRedirect(route('login'));
});

/* ---------------------------------------------------------------------------
 | The wire contract between the server and the browser
 |-------------------------------------------------------------------------- */

it('serializes ceremony options in the exact shape the client decoder expects', function () {
    $fixture = waClinicFixture();
    waEnroll($fixture['device']);

    actingAs(superAdmin());

    $creation = postJson(route('settings.doctor-devices.webauthn.options', $fixture['device']))->json();

    auth()->logout();
    waFlags(enforcement: true, webauthn: true);
    post(route('login'), ['email' => $fixture['user']->email, 'password' => 'rahasia-klinik']);

    $request = postJson(route('doctor-device-webauthn.options'))->json();

    /*
     * `resources/js/doctor-device-webauthn.js` converts exactly these fields
     * from base64url to an ArrayBuffer before handing them to the browser. The
     * serializer is a library dependency, so this shape is not ours to assume:
     * if an upgrade emitted raw bytes or standard base64 instead, registration
     * would fail on the tablet with a signature error that reads like broken
     * hardware. This is the only place that contract is checked, and it is
     * checked here because the alternative is checking it in a clinic.
     */
    $isBase64Url = fn ($value) => is_string($value) && preg_match('/^[A-Za-z0-9_-]+$/', $value) === 1;

    expect($isBase64Url($creation['challenge']))->toBeTrue()
        ->and($isBase64Url($creation['user']['id']))->toBeTrue()
        ->and($isBase64Url($creation['excludeCredentials'][0]['id']))->toBeTrue()
        ->and($creation['rp']['id'])->toBe(WA_RP_ID)
        // The three ceremony properties the server asks the authenticator for.
        ->and($creation['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($creation['authenticatorSelection']['authenticatorAttachment'])->toBe('platform')
        ->and($creation['attestation'])->toBe('none');

    expect($isBase64Url($request['challenge']))->toBeTrue()
        ->and($isBase64Url($request['allowCredentials'][0]['id']))->toBeTrue()
        ->and($request['rpId'])->toBe(WA_RP_ID)
        ->and($request['userVerification'])->toBe('required');

    // The user handle is the DEVICE uuid — the modelling decision the whole
    // shared-tablet design rests on, pinned on the wire rather than only in a
    // service.
    expect(base64_decode(strtr($creation['user']['id'], '-_', '+/'), true))
        ->toBe((string) $fixture['device']->uuid);
});

it('ships defaults that refuse a syncable credential and demand a verified human', function () {
    // These two defaults ARE the security posture. A config edit that flipped
    // either would leave every other test passing.
    expect(config('webauthn.device_binding.require_device_bound'))->toBeTrue()
        ->and(config('webauthn.ceremony.user_verification'))->toBe('required')
        ->and(config('webauthn.relying_party.allow_insecure_localhost'))->toBeFalse();
});
