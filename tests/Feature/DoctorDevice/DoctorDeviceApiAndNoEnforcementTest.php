<?php

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 3.
 *
 * The device HTTP channel end to end, and the non-negotiable guarantee that
 * building it changed NOTHING about how a Doctor logs in.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Database\Factories\DoctorDeviceEnrollmentFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function apiFixture(): array
{
    seedAccessControl();
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    [$pub, $priv] = DoctorDeviceEnrollmentFactory::generateKeyPair();

    return ['admin' => superAdmin(), 'branch' => $branch, 'publicKey' => $pub, 'privateKey' => $priv];
}

function apiSign(string $purpose, string $nonce, string $fingerprint, $privateKey): string
{
    $sig = '';
    openssl_sign(DeviceProofMessage::build($purpose, $nonce, $fingerprint), $sig, $privateKey, OPENSSL_ALGO_SHA256);

    return base64_encode($sig);
}

// ---------------------------------------------------------------------------
// The whole pairing journey over HTTP
// ---------------------------------------------------------------------------

it('walks an android install from enrollment request to verified device', function () {
    $f = apiFixture();

    // 1. The install asks to pair and is handed a one-time code.
    $req = postJson(route('device-api.v1.enrollment.request'), [
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
        'platform' => 'android',
        'device_model' => 'Pixel Tablet',
    ])->assertCreated();

    $uuid = $req->json('enrollment_uuid');
    expect($req->json('pairing_code'))->toHaveLength(8)
        ->and($req->json('status'))->toBe(DoctorDeviceEnrollment::STATUS_PENDING);

    // 2. It polls: still pending, no device bound.
    getJson(route('device-api.v1.enrollment.status', $uuid))
        ->assertOk()
        ->assertJsonPath('status', DoctorDeviceEnrollment::STATUS_PENDING)
        ->assertJsonPath('device', null);

    // 3. The administrator pairs it to a registry device.
    $device = DoctorDevice::factory()->create([
        'branch_id' => $f['branch']->id, 'public_key_fingerprint' => null,
    ]);
    $enrollment = DoctorDeviceEnrollment::query()->where('uuid', $uuid)->firstOrFail();

    actingAs($f['admin'])->post(route('settings.doctor-device-enrollments.approve', $enrollment), [
        'doctor_device_id' => $device->id,
    ])->assertRedirect();

    // Approved, bound — but NOT yet trustworthy: no proof has happened.
    getJson(route('device-api.v1.enrollment.status', $uuid))
        ->assertOk()
        ->assertJsonPath('status', DoctorDeviceEnrollment::STATUS_APPROVED)
        ->assertJsonPath('device.enrollment_status', DoctorDevice::ENROLLMENT_PENDING)
        ->assertJsonPath('device.trustworthy', false);

    // 4. Challenge / response.
    $fingerprint = $device->fresh()->public_key_fingerprint;
    $challenge = postJson(route('device-api.v1.challenge'), ['fingerprint' => $fingerprint])->assertOk();

    postJson(route('device-api.v1.proof'), [
        'nonce' => $challenge->json('nonce'),
        'signature' => apiSign($challenge->json('purpose'), $challenge->json('nonce'), $fingerprint, $f['privateKey']),
    ])
        ->assertOk()
        ->assertJsonPath('verified', true)
        ->assertJsonPath('device.identity_state', DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED);

    getJson(route('device-api.v1.enrollment.status', $uuid))
        ->assertJsonPath('device.trustworthy', true);
});

it('never returns key material or a signature in an api response', function () {
    $f = apiFixture();

    $body = postJson(route('device-api.v1.enrollment.request'), [
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ])->assertCreated()->content();

    expect($body)->not->toContain($f['publicKey']);
});

// ---------------------------------------------------------------------------
// The device channel must not leak the estate
// ---------------------------------------------------------------------------

it('answers identically for unknown, disabled and revoked devices', function () {
    $f = apiFixture();

    $unknown = postJson(route('device-api.v1.challenge'), ['fingerprint' => str_repeat('a', 64)]);

    $disabled = DoctorDevice::factory()->disabled()->create([
        'branch_id' => $f['branch']->id,
        'public_key' => $f['publicKey'],
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($f['publicKey']),
    ]);
    $disabledResponse = postJson(route('device-api.v1.challenge'), [
        'fingerprint' => $disabled->public_key_fingerprint,
    ]);

    [$pub2] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $revoked = DoctorDevice::factory()->revoked()->create([
        'branch_id' => $f['branch']->id,
        'public_key' => $pub2,
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($pub2),
    ]);
    $revokedResponse = postJson(route('device-api.v1.challenge'), [
        'fingerprint' => $revoked->public_key_fingerprint,
    ]);

    // Same status AND same body: the endpoint cannot be used to enumerate.
    $unknown->assertStatus(422);
    $disabledResponse->assertStatus(422);
    $revokedResponse->assertStatus(422);
    expect($disabledResponse->content())->toBe($unknown->content())
        ->and($revokedResponse->content())->toBe($unknown->content());
});

it('rejects a malformed fingerprint or signature at the boundary', function () {
    apiFixture();

    postJson(route('device-api.v1.challenge'), ['fingerprint' => 'nope'])->assertStatus(422);
    postJson(route('device-api.v1.proof'), ['nonce' => 'nope', 'signature' => 'x'])->assertStatus(422);
});

it('requires no session or csrf token on the device channel', function () {
    $f = apiFixture();

    // No actingAs, no CSRF: a device has no session. Must still work.
    postJson(route('device-api.v1.enrollment.request'), [
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ])->assertCreated();
});

it('denies enrollment approval to a non-super-admin', function () {
    $f = apiFixture();
    $enrollment = DoctorDeviceEnrollment::factory()->create();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id, 'public_key_fingerprint' => null]);

    $user = User::factory()->create();
    $user->assignRole('Admin Klinik');

    actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->post(route('settings.doctor-device-enrollments.approve', $enrollment), [
            'doctor_device_id' => $device->id,
        ])->assertForbidden();

    expect($enrollment->fresh()->status)->toBe(DoctorDeviceEnrollment::STATUS_PENDING);
});

// ---------------------------------------------------------------------------
// NON-NEGOTIABLE — enforcement is still OFF (the Phase 2 M7 contract, extended)
// ---------------------------------------------------------------------------

it('keeps authentication completely decoupled from the device registry', function () {
    // Building a device channel must not have leaked into the auth path. A
    // passing login test would not prove this — a coupling that has not fired
    // yet still passes — so the assertion is structural.
    $authSurfaces = array_merge(
        glob(base_path('app/Http/Controllers/Auth/*.php')) ?: [],
        glob(base_path('app/Http/Middleware/*.php')) ?: [],
        glob(base_path('app/Services/Auth/*.php')) ?: [],
        [base_path('app/Http/Requests/Auth/LoginRequest.php')],
    );

    foreach ($authSurfaces as $file) {
        if (! is_file($file)) {
            continue;
        }
        expect(file_get_contents($file))
            ->not->toContain('DoctorDevice')
            ->not->toContain('DeviceProof');
    }
});

it('never gates a web route behind device proof', function () {
    // No web/session route may carry a device middleware. Phase 4 is where that
    // changes, deliberately and with its own review.
    foreach (app('router')->getRoutes() as $route) {
        $middleware = implode(' ', $route->gatherMiddleware());
        $uri = $route->uri();

        if (str_starts_with($uri, 'device-api')) {
            continue; // the device channel itself is allowed to be device-aware
        }

        expect($middleware)->not->toContain('DoctorDevice')
            ->not->toContain('device.proof')
            ->not->toContain('trusted.device');
    }
});

it('lets a doctor log in normally with devices enrolled and verified', function () {
    $f = apiFixture();

    // A fully verified device exists — and changes nothing about Doctor login.
    DoctorDevice::factory()->create([
        'branch_id' => $f['branch']->id,
        'public_key' => $f['publicKey'],
        'public_key_fingerprint' => DeviceKeyMaterial::fingerprint($f['publicKey']),
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ]);

    $doctor = Doctor::factory()->withAllowedBranches([$f['branch']])->create();
    $user = rmeMakeDoctorOnline($doctor, $f['branch']);
    $user->forceFill(['password' => bcrypt('password123')])->save();

    test()->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasNoErrors();

    test()->assertAuthenticatedAs($user->fresh());
});

it('lets a doctor log in when no device is enrolled at all', function () {
    seedAccessControl();
    expect(DoctorDevice::query()->count())->toBe(0);

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create();
    $user = rmeMakeDoctorOnline($doctor, $branch);
    $user->forceFill(['password' => bcrypt('password123')])->save();

    test()->post(route('login'), ['email' => $user->email, 'password' => 'password123'])
        ->assertSessionHasNoErrors();

    test()->assertAuthenticatedAs($user->fresh());
});

it('keeps phase 1 room isolation and print denial intact', function () {
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $roomA = ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $roomB = ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    $doctorRecord = Doctor::factory()->withAllowedBranches([$branch])->create();
    $doctorUser = rmeMakeDoctorOnline($doctorRecord, $branch, $roomA);

    $mine = ClinicVisit::factory()->create([
        'branch_id' => $branch->id, 'clinic_room_id' => $roomA->id,
        'doctor_id' => $doctorRecord->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
    $other = ClinicVisit::factory()->create([
        'branch_id' => $branch->id, 'clinic_room_id' => $roomB->id,
        'doctor_id' => $doctorRecord->id, 'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);

    actingAs($doctorUser)->get(route('rme.visits.show', $other))->assertForbidden();
    actingAs($doctorUser)->get(route('rme.visits.show', $mine))->assertOk();
    actingAs($doctorUser)->get(route('rme.visits.print', $mine))->assertForbidden();
    actingAs($doctorUser)->get(route('rme.visits.pdf', $mine))->assertForbidden();
});
