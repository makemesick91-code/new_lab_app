<?php

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the app-only gate and
 * session/device binding.
 *
 * TWO CONTRACTS LIVE HERE, AND THE FIRST MATTERS MOST.
 *
 * 1. WITH ENFORCEMENT OFF — which is how this ships — nothing changes for a
 *    doctor working in production. That is not a nice-to-have: switching this
 *    on prematurely is a clinical lockout, so the "off" tests are as important
 *    as the "on" ones and are deliberately written first.
 *
 * 2. WITH ENFORCEMENT ON the gate is real: a browser is refused, a pending or
 *    refused pair is refused, and a session dies when the trust behind it is
 *    withdrawn.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceLoginTicket;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use App\Services\Foundation\FeatureFlagService;
use Database\Factories\DoctorDeviceEnrollmentFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * Flip the canonical enforcement flag for one test.
 *
 * The whole `feature_flags.flags` array is rewritten rather than reached with
 * dot notation, because the flag KEY itself contains a dot: a
 * `config()->set('feature_flags.flags.doctor.trusted_device_enforcement...')`
 * silently builds a nested `doctor` => `trusted_device_enforcement` structure
 * that FeatureFlagService never reads, and the test then passes while
 * enforcement is quietly still off. That is the same trap tests/Pest.php
 * documents for the legacy archive flag.
 */
function enforceDeviceLock(bool $on): void
{
    $flags = config('feature_flags.flags', []);
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['default'] = $on;
    $flags[DoctorAppLoginGate::ENFORCEMENT_FLAG]['env_value'] = $on;

    config()->set('feature_flags.flags', $flags);

    // PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1 — the scope this file's
    // assertions have always assumed, now stated.
    //
    // Every assertion below this helper is about enforcement applying to a
    // doctor because they hold the Doctor role. That used to be the only thing
    // the flag could mean. It no longer is: enforcement is now scoped, and the
    // committed default is a pilot scope with no target, which covers nobody.
    //
    // So this helper declares the FLEET-WIDE scope explicitly. Nothing here is
    // weakened — the same doctors are enforced for the same reasons — but a test
    // that asserts fleet-wide denial should have to say that it wants fleet-wide
    // denial, rather than getting it from a default. `Phase4aPilotPreparation
    // Test` owns the pilot-scoped behaviour.
    // Both halves: the mode is a runtime value, fleet-wide permission is a
    // source-controlled one. Fleet-wide denial needs both, which is why arming
    // the enforcement flag alone can no longer lock a fleet out.
    config()->set('doctor_device_enforcement.scope', array_merge(
        (array) config('doctor_device_enforcement.scope'),
        ['mode' => 'unscoped'],
    ));

    config()->set('android_release.enforcement.scope', array_merge(
        (array) config('android_release.enforcement.scope'),
        ['global_permitted' => true],
    ));
}

function gateFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $room = ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);
    $user = rmeMakeDoctorOnline($doctor, $branch, $room);
    $user->forceFill(['password' => bcrypt('password123')])->save();
    $doctor->forceFill(['user_id' => $user->id])->save();

    [$pub, $priv] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'public_key' => $pub,
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($pub),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ]);

    return compact('branch', 'room', 'doctor', 'user', 'device', 'pub', 'priv');
}

/** Sign a fresh doctor-login challenge with the device's private key. */
function gateProof(array $f): array
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

    return ['nonce' => $challenge->json('nonce'), 'signature' => base64_encode($signature)];
}

/** An ACTIVE pair plus a real, redeemed, device-bound session. */
function signInThroughApp(array $f): DoctorDeviceAuthorization
{
    $authorization = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    $ticket = postJson(route('device-api.v1.doctor.login'), array_merge(gateProof($f), [
        'email' => $f['user']->email,
        'password' => 'password123',
    ]))->assertOk()->json('login_ticket');

    expect($ticket)->not->toBeNull();

    test()->get(route('doctor-device-login.redeem', $ticket))->assertRedirect();

    return $authorization->fresh();
}

// ===========================================================================
// ENFORCEMENT OFF — the deployment safety contract
// ===========================================================================

it('lets a doctor log in through the browser exactly as before', function () {
    $f = gateFixture();
    enforceDeviceLock(false);

    test()->post(route('login'), ['email' => $f['user']->email, 'password' => 'password123'])
        ->assertSessionHasNoErrors();

    test()->assertAuthenticatedAs($f['user']->fresh());
});

it('lets a doctor log in when the device registry and authorizations are empty', function () {
    seedAccessControl();
    enforceDeviceLock(false);

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);
    $user = rmeMakeDoctorOnline($doctor, $branch);
    $user->forceFill(['password' => bcrypt('password123')])->save();

    expect(DoctorDevice::query()->count())->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);

    test()->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasNoErrors();

    test()->assertAuthenticatedAs($user->fresh());
});

it('lets a doctor log in while their own pair is only pending, or even refused', function () {
    $f = gateFixture();
    enforceDeviceLock(false);

    DoctorDeviceAuthorization::factory()->rejected()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    // With enforcement off a refusal is a note for later, not a lockout.
    test()->post(route('login'), ['email' => $f['user']->email, 'password' => 'password123'])
        ->assertSessionHasNoErrors();

    test()->assertAuthenticatedAs($f['user']->fresh());
});

it('does not require a device binding on any protected doctor request', function () {
    $f = gateFixture();
    enforceDeviceLock(false);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $f['branch']->id,
        'clinic_room_id' => $f['room']->id,
        'doctor_id' => $f['doctor']->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    // No ticket has ever been redeemed, so the session carries no binding.
    actingAs($f['user'])->get(route('rme.visits.show', $visit))->assertOk();
    actingAs($f['user'])->get(route('rme.visits.index'))->assertOk();
});

it('mints no login ticket at all while enforcement is off', function () {
    $f = gateFixture();
    enforceDeviceLock(false);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    $response = postJson(route('device-api.v1.doctor.login'), array_merge(gateProof($f), [
        'email' => $f['user']->email,
        'password' => 'password123',
    ]))->assertOk();

    // Everything is ACTIVE and the proof is valid — and still no ticket,
    // because the capability is not the rollout.
    expect($response->json('outcome'))->toBe('active')
        ->and($response->json('login_ticket'))->toBeNull()
        ->and(DoctorDeviceLoginTicket::query()->count())->toBe(0);
});

it('refuses ticket redemption outright while enforcement is off', function () {
    gateFixture();
    enforceDeviceLock(false);

    test()->get(route('doctor-device-login.redeem', str_repeat('a', 64)))
        ->assertRedirect(route('login'));

    test()->assertGuest();
});

it('ships with the enforcement flag off by default', function () {
    // The single most consequential assertion in this file: it reads the real
    // configuration, not a value the test set.
    expect(app(FeatureFlagService::class)
        ->enabled(DoctorAppLoginGate::ENFORCEMENT_FLAG))->toBeFalse();
});

// ===========================================================================
// ENFORCEMENT ON — the capability, exercised
// ===========================================================================

it('denies an ordinary browser login for a doctor', function () {
    $f = gateFixture();
    enforceDeviceLock(true);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    // Correct password, approved device — and still refused, because a browser
    // carries no server-verified device session. No User-Agent was consulted.
    test()->post(route('login'), ['email' => $f['user']->email, 'password' => 'password123'])
        ->assertSessionHasErrors('email');

    test()->assertGuest();
});

it('leaves every non-doctor login untouched', function () {
    gateFixture();
    enforceDeviceLock(true);

    foreach (['Kasir', 'Admin Klinik', 'Supervisor RME'] as $role) {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $user->assignRole($role);

        test()->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
            ->assertSessionHasNoErrors();

        test()->assertAuthenticatedAs($user->fresh());
        test()->post(route('logout'));
    }
});

it('lets an approved doctor in through the app and binds the session to the device', function () {
    $f = gateFixture();
    enforceDeviceLock(true);

    $authorization = signInThroughApp($f);

    test()->assertAuthenticatedAs($f['user']->fresh());

    expect(session(DoctorAppLoginGate::SESSION_DEVICE_ID))->toBe($f['device']->id)
        ->and(session(DoctorAppLoginGate::SESSION_AUTHORIZATION_ID))->toBe($authorization->id)
        ->and(session(DoctorAppLoginGate::SESSION_DOCTOR_ID))->toBe($f['doctor']->id)
        ->and($authorization->last_authorized_login_at)->not->toBeNull();
});

it('refuses a login ticket for a pending, refused or revoked pair', function () {
    $f = gateFixture();
    enforceDeviceLock(true);

    foreach (['pending', 'rejected', 'revoked'] as $state) {
        DoctorDeviceAuthorization::query()->delete();
        DoctorDeviceAuthorization::factory()->create([
            'doctor_id' => $f['doctor']->id,
            'doctor_device_id' => $f['device']->id,
            'status' => $state,
        ]);

        $response = postJson(route('device-api.v1.doctor.login'), array_merge(gateProof($f), [
            'email' => $f['user']->email,
            'password' => 'password123',
        ]))->assertOk();

        expect($response->json('outcome'))->toBe($state)
            ->and($response->json('login_ticket'))->toBeNull("state {$state} must not mint a ticket");
    }

    expect(DoctorDeviceLoginTicket::query()->count())->toBe(0);
});

it('makes a login ticket single-use', function () {
    // MUTATION FINDING. The first version of this test redeemed a MADE-UP
    // ticket the second time, so removing the single-use check survived — an
    // invented ticket is refused for being unknown, whatever the replay rule
    // says. The real ticket has to be replayed to test replay.
    $f = gateFixture();
    enforceDeviceLock(true);

    $authorization = DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    $ticket = postJson(route('device-api.v1.doctor.login'), array_merge(gateProof($f), [
        'email' => $f['user']->email,
        'password' => 'password123',
    ]))->assertOk()->json('login_ticket');

    test()->get(route('doctor-device-login.redeem', $ticket))->assertRedirect();
    test()->assertAuthenticatedAs($f['user']->fresh());

    expect(DoctorDeviceLoginTicket::query()->firstOrFail()->consumed_at)->not->toBeNull();

    test()->post(route('logout'));

    // The SAME ticket, a second time.
    test()->get(route('doctor-device-login.redeem', $ticket))
        ->assertRedirect(route('login'));

    test()->assertGuest();

    // And an invented one is refused just the same.
    test()->get(route('doctor-device-login.redeem', str_repeat('b', 64)))
        ->assertRedirect(route('login'));

    test()->assertGuest();
    expect($authorization->fresh()->isActive())->toBeTrue();
});

it('refuses a ticket minted before enforcement was switched off', function () {
    // MUTATION FINDING. Removing redemption's own enforcement check survived,
    // because with the flag off no ticket exists to redeem. That reasoning
    // holds only until an operator disables enforcement mid-flight — a real
    // rollback step — and a ticket issued seconds earlier is still in a
    // tablet's hand. Redemption has to refuse on its own account.
    $f = gateFixture();
    enforceDeviceLock(true);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    $ticket = postJson(route('device-api.v1.doctor.login'), array_merge(gateProof($f), [
        'email' => $f['user']->email,
        'password' => 'password123',
    ]))->assertOk()->json('login_ticket');

    expect($ticket)->not->toBeNull();

    enforceDeviceLock(false);

    test()->get(route('doctor-device-login.redeem', $ticket))
        ->assertRedirect(route('login'));

    test()->assertGuest();
    expect(DoctorDeviceLoginTicket::query()->firstOrFail()->consumed_at)->toBeNull();
});

it('stops an open doctor session when the authorization is revoked', function () {
    $f = gateFixture();
    enforceDeviceLock(true);

    $authorization = signInThroughApp($f);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $f['branch']->id,
        'clinic_room_id' => $f['room']->id,
        'doctor_id' => $f['doctor']->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    test()->get(route('rme.visits.show', $visit))->assertOk();

    // Revocation has to bite the session that is open RIGHT NOW, not the next
    // one — otherwise revoking a lost tablet does nothing until someone logs out.
    $authorization->forceFill(['status' => DoctorDeviceAuthorization::STATUS_REVOKED])->save();

    test()->get(route('rme.visits.show', $visit))->assertRedirect(route('login'));
    test()->assertGuest();
});

it('stops an open doctor session when the device is disabled', function () {
    $f = gateFixture();
    enforceDeviceLock(true);
    signInThroughApp($f);

    test()->get(route('rme.visits.index'))->assertOk();

    $f['device']->forceFill(['status' => DoctorDevice::STATUS_DISABLED])->save();

    test()->get(route('rme.visits.index'))->assertRedirect(route('login'));
    test()->assertGuest();
});

it('refuses a session whose device binding names a different doctor', function () {
    $f = gateFixture();
    enforceDeviceLock(true);
    signInThroughApp($f);

    // Copying another tablet's session values must not work: the binding is
    // checked against the doctor the ACCOUNT resolves to, not against itself.
    session([DoctorAppLoginGate::SESSION_DOCTOR_ID => $f['doctor']->id + 999]);

    test()->get(route('rme.visits.index'))->assertRedirect(route('login'));
    test()->assertGuest();
});

it('requires each doctor on a shared tablet to hold their own approval', function () {
    $f = gateFixture();
    enforceDeviceLock(true);

    $second = Doctor::factory()->withAllowedBranches([$f['branch']])->create(['is_active' => true]);
    // Their own room: two doctors cannot be online in one treatment room.
    $secondRoom = ClinicRoom::factory()->create([
        'branch_id' => $f['branch']->id, 'status' => ClinicRoom::STATUS_ACTIVE,
    ]);
    $secondUser = rmeMakeDoctorOnline($second, $f['branch'], $secondRoom);
    $secondUser->forceFill(['password' => bcrypt('password123')])->save();
    $second->forceFill(['user_id' => $secondUser->id])->save();

    // The first doctor is approved on this tablet. That says nothing about the
    // second one.
    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    $response = postJson(route('device-api.v1.doctor.login'), array_merge(gateProof($f), [
        'email' => $secondUser->email,
        'password' => 'password123',
    ]))->assertOk();

    expect($response->json('outcome'))->toBe('pending')
        ->and($response->json('login_ticket'))->toBeNull();
});

it('never accepts a header or user agent as proof of the clinic app', function () {
    $f = gateFixture();
    enforceDeviceLock(true);

    DoctorDeviceAuthorization::factory()->active()->create([
        'doctor_id' => $f['doctor']->id,
        'doctor_device_id' => $f['device']->id,
    ]);

    // Everything an attacker can assert from a browser, asserted at once.
    test()->withHeaders([
        'User-Agent' => 'DaengtisiaMS-Clinic/1.0 (Android 15; Pixel Tablet)',
        'X-Daengtisia-App' => 'true',
        'X-Requested-With' => 'com.daengtisia.clinic',
        'X-Device-Id' => (string) $f['device']->uuid,
    ])->post(route('login'), ['email' => $f['user']->email, 'password' => 'password123'])
        ->assertSessionHasErrors('email');

    test()->assertGuest();
});

// ===========================================================================
// The gate never became a second copy of itself
// ===========================================================================

it('keeps enforcement behind exactly one authority', function () {
    // The flag is read in ONE class. If a second reader appears, enforcement
    // can start disagreeing with itself, and the disagreement will be found by
    // a doctor who cannot see their patients.
    $readers = [];

    foreach ([base_path('app'), base_path('routes'), base_path('bootstrap')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), "'doctor.trusted_device_enforcement'")) {
                $readers[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($readers)->toBe(['app/Modules/DoctorDevice/Services/DoctorAppLoginGate.php']);
});
