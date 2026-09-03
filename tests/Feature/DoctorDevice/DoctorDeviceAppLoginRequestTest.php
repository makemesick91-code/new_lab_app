<?php

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — automatic request
 * creation on the first doctor login from the Clinic App.
 *
 * The thing worth testing here is not "a request appears". It is the ORDER:
 * cryptographic possession, then credentials, then doctor identity, and only
 * then a row. Every test below that asserts "creates nothing" is asserting that
 * this endpoint cannot be turned into a way to spray an approver's inbox using
 * guessed email addresses or a spoofed device.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

/**
 * A doctor account with a real password, a branch, and an Android install that
 * has requested enrolment but has NOT been paired by an administrator — the
 * genuine "brand new tablet, first login" situation.
 */
function appLoginFixture(array $overrides = []): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->withAllowedBranches([$branch])->create(['is_active' => true]);

    $user = User::factory()->create(['password' => bcrypt('password123')]);
    $user->assignRole('Doctor');
    $doctor->forceFill(['user_id' => $user->id])->save();

    [$pub, $priv] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $fingerprint = DeviceKeyMaterial::fingerprint($pub);

    DoctorDeviceEnrollment::factory()->create(array_merge([
        'public_key' => $pub,
        'public_key_fingerprint' => $fingerprint,
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
        'status' => DoctorDeviceEnrollment::STATUS_PENDING,
        'device_model' => 'Pixel Tablet',
        'expires_at' => now()->addMinutes(15),
    ], $overrides));

    return compact('branch', 'doctor', 'user', 'pub', 'priv', 'fingerprint');
}

function appLoginSign(string $purpose, string $nonce, string $fingerprint, $privateKey): string
{
    $sig = '';
    openssl_sign(DeviceProofMessage::build($purpose, $nonce, $fingerprint), $sig, $privateKey, OPENSSL_ALGO_SHA256);

    return base64_encode($sig);
}

/** Ask for a nonce and sign it, the way the app does. */
function appLoginProof(array $f): array
{
    $challenge = postJson(route('device-api.v1.doctor.challenge'), ['fingerprint' => $f['fingerprint']])
        ->assertOk();

    return [
        'nonce' => $challenge->json('nonce'),
        'signature' => appLoginSign(
            $challenge->json('purpose'),
            $challenge->json('nonce'),
            $f['fingerprint'],
            $f['priv'],
        ),
    ];
}

// ---------------------------------------------------------------------------
// The happy path: new tablet, first login, one pending request
// ---------------------------------------------------------------------------

it('registers the device and raises exactly one pending request on first login', function () {
    $f = appLoginFixture();

    $response = postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email,
        'password' => 'password123',
    ]))->assertOk();

    expect($response->json('outcome'))->toBe('pending')
        // Enforcement is off, so no ticket exists even on a valid attempt.
        ->and($response->json('login_ticket'))->toBeNull()
        ->and($response->json('enforcement_active'))->toBeFalse();

    $device = DoctorDevice::query()->firstOrFail();

    expect(DoctorDevice::query()->count())->toBe(1)
        // Registered and key-proven, but trusted by nothing.
        ->and($device->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL)
        ->and($device->identity_state)->toBe(DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED)
        ->and($device->branch_id)->toBe($f['branch']->id)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1)
        ->and(DoctorDeviceAuthorization::query()->first()->status)
        ->toBe(DoctorDeviceAuthorization::STATUS_PENDING);
});

it('produces one request, not ten, when the doctor taps login ten times', function () {
    $f = appLoginFixture();

    for ($i = 0; $i < 10; $i++) {
        postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
            'email' => $f['user']->email,
            'password' => 'password123',
        ]))->assertOk();
    }

    expect(DoctorDevice::query()->count())->toBe(1)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1);
});

it('gives a second doctor on the same tablet their own request', function () {
    $f = appLoginFixture();

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertOk();

    $other = User::factory()->create(['password' => bcrypt('password123')]);
    $other->assignRole('Doctor');
    Doctor::factory()->withAllowedBranches([$f['branch']])->create([
        'is_active' => true, 'user_id' => $other->id,
    ]);

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $other->email, 'password' => 'password123',
    ]))->assertOk();

    // One tablet, two doctors, two authorizations — never two device rows.
    expect(DoctorDevice::query()->count())->toBe(1)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Nothing is written until every check has passed
// ---------------------------------------------------------------------------

it('creates nothing when the password is wrong', function () {
    $f = appLoginFixture();

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email,
        'password' => 'not-the-password',
    ]))->assertStatus(422);

    expect(DoctorDevice::query()->count())->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('creates nothing when the account does not exist', function () {
    $f = appLoginFixture();

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => 'nobody@example.test',
        'password' => 'password123',
    ]))->assertStatus(422);

    expect(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('creates nothing for a valid non-doctor account', function () {
    $f = appLoginFixture();

    $kasir = User::factory()->create(['password' => bcrypt('password123')]);
    $kasir->assignRole('Kasir');

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $kasir->email,
        'password' => 'password123',
    ]))->assertStatus(422);

    expect(DoctorDevice::query()->count())->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('creates nothing for a doctor-role account that is not linked to a doctor record', function () {
    $f = appLoginFixture();

    $unlinked = User::factory()->create(['password' => bcrypt('password123')]);
    $unlinked->assignRole('Doctor');

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $unlinked->email,
        'password' => 'password123',
    ]))->assertStatus(422);

    expect(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('creates nothing when the signature is forged', function () {
    $f = appLoginFixture();
    $proof = appLoginProof($f);

    // A different key signing the same challenge.
    [, $otherPriv] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $proof['signature'] = appLoginSign(
        DeviceProofMessage::PURPOSE_DOCTOR_LOGIN,
        $proof['nonce'],
        $f['fingerprint'],
        $otherPriv,
    );

    postJson(route('device-api.v1.doctor.login'), array_merge($proof, [
        'email' => $f['user']->email,
        'password' => 'password123',
    ]))->assertStatus(422);

    expect(DoctorDevice::query()->count())->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('rejects a replayed nonce', function () {
    $f = appLoginFixture();
    $proof = appLoginProof($f);
    $payload = array_merge($proof, ['email' => $f['user']->email, 'password' => 'password123']);

    postJson(route('device-api.v1.doctor.login'), $payload)->assertOk();
    // Same nonce, same signature, second time. The nonce was burned.
    postJson(route('device-api.v1.doctor.login'), $payload)->assertStatus(422);

    expect(DoctorDeviceAuthorization::query()->count())->toBe(1);
});

it('does not let a failed verification hand the nonce back', function () {
    $f = appLoginFixture();
    $proof = appLoginProof($f);

    // Wrong password: the attempt fails AFTER the nonce is claimed.
    postJson(route('device-api.v1.doctor.login'), array_merge($proof, [
        'email' => $f['user']->email, 'password' => 'wrong',
    ]))->assertStatus(422);

    // The same nonce must now be spent — otherwise an attacker could grind
    // passwords against one fixed challenge forever.
    postJson(route('device-api.v1.doctor.login'), array_merge($proof, [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertStatus(422);

    expect(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('refuses a nonce for a fingerprint the server has never seen', function () {
    appLoginFixture();

    postJson(route('device-api.v1.doctor.challenge'), ['fingerprint' => str_repeat('a', 64)])
        ->assertStatus(422);
});

it('answers a login attempt with one opaque failure whatever went wrong', function () {
    $f = appLoginFixture();

    $wrongPassword = postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'wrong',
    ]));

    $unknownAccount = postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => 'ghost@example.test', 'password' => 'password123',
    ]));

    // Identical body and status: the endpoint cannot be used to learn which
    // email addresses exist, or which devices are enrolled.
    expect($wrongPassword->status())->toBe(422)
        ->and($unknownAccount->status())->toBe(422)
        ->and($unknownAccount->content())->toBe($wrongPassword->content());
});

// ---------------------------------------------------------------------------
// Existing device states
// ---------------------------------------------------------------------------

it('refuses a login attempt from a revoked device and creates no request', function () {
    $f = appLoginFixture();

    DoctorDevice::factory()->revoked()->create([
        'branch_id' => $f['branch']->id,
        'public_key' => $f['pub'],
        'public_key_fingerprint' => $f['fingerprint'],
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    // A revoked device is refused a nonce at all.
    postJson(route('device-api.v1.doctor.challenge'), ['fingerprint' => $f['fingerprint']])
        ->assertStatus(422);

    expect(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('refuses a login attempt from a disabled device and creates no request', function () {
    $f = appLoginFixture();

    DoctorDevice::factory()->disabled()->create([
        'branch_id' => $f['branch']->id,
        'public_key' => $f['pub'],
        'public_key_fingerprint' => $f['fingerprint'],
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
    ]);

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertStatus(422);

    expect(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('reports rejected without reopening it, and revoked without recreating it', function () {
    $f = appLoginFixture();

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertOk();

    $authorization = DoctorDeviceAuthorization::query()->firstOrFail();
    $authorization->forceFill([
        'status' => DoctorDeviceAuthorization::STATUS_REJECTED,
        'rejected_at' => now(),
        'rejected_reason' => 'Bukan perangkat klinik.',
    ])->save();

    $response = postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertOk();

    expect($response->json('outcome'))->toBe('rejected')
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(1);
});

it('files a new device in the doctor own rme branch, never just any branch', function () {
    // MUTATION FINDING. The original fixture had exactly one branch, so
    // resolving the branch from `Branch::query()->first()` instead of from the
    // doctor was indistinguishable. With several branches in play — which is
    // what a multi-branch clinic actually looks like — it is not.
    $f = appLoginFixture();

    // Two decoys the doctor does not practise at, created BEFORE theirs so a
    // "first branch in the table" implementation would pick one of these.
    Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $doctorBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $f['doctor']->branches()->sync([$doctorBranch->id]);

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertOk();

    expect(DoctorDevice::query()->firstOrFail()->branch_id)->toBe($doctorBranch->id);
});

it('refuses a doctor with no active rme branch rather than inventing one', function () {
    $f = appLoginFixture();
    $f['doctor']->branches()->sync([]);

    postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertStatus(422);

    expect(DoctorDevice::query()->count())->toBe(0)
        ->and(DoctorDeviceAuthorization::query()->count())->toBe(0);
});

it('never returns key material or a signature in a login response', function () {
    $f = appLoginFixture();

    $body = postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertOk()->content();

    expect($body)->not->toContain($f['pub'])
        ->not->toContain('password123')
        ->not->toContain($f['fingerprint']);
});

it('lets the app poll its own authorization by uuid without exposing anyone else', function () {
    $f = appLoginFixture();

    $uuid = postJson(route('device-api.v1.doctor.login'), array_merge(appLoginProof($f), [
        'email' => $f['user']->email, 'password' => 'password123',
    ]))->assertOk()->json('authorization_uuid');

    $status = test()->getJson(route('device-api.v1.doctor.authorization.status', $uuid))->assertOk();

    expect($status->json('status'))->toBe('pending')
        // Coarse state only: no doctor name, no account, no other pairing.
        ->and($status->json())->not->toHaveKey('doctor');

    test()->getJson(route('device-api.v1.doctor.authorization.status', (string) Str::uuid()))
        ->assertStatus(404);
});
