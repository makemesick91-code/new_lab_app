<?php

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 1 — the canonical
 * trusted-device identity proof.
 *
 * The rule under test: a tablet has proved its identity if it carries EITHER a
 * valid Android keystore proof OR an acceptable device-bound WebAuthn
 * credential. Until Stage 1 the second half did not exist, so a tablet
 * enrolled through the PWA could never be authorized for any doctor — and
 * retiring the Android path, the only writer of the flag the gate demanded,
 * would have made new devices permanently unprovisionable.
 *
 * Tested through the policy and through the authorization service, so a
 * failure names the rule rather than the screen.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorDeviceAuthorizationService;
use App\Modules\DoctorDevice\Services\DoctorDeviceIdentityProofPolicy;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A credential row on this hardware.
 *
 * Built directly rather than through a factory: there is no credential factory
 * yet, and inventing one here would put the verdict — the thing under test —
 * behind a default.
 */
function proofCredential(
    DoctorDevice $device,
    string $verdict = DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
    bool $revoked = false,
): DoctorDeviceWebAuthnCredential {
    return DoctorDeviceWebAuthnCredential::create([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => 'cred-'.Str::random(24),
        'public_key' => 'stub-cose-public-key',
        'user_verified' => true,
        'device_bound_verdict' => $verdict,
        'registered_at' => now(),
        'revoked_at' => $revoked ? now() : null,
    ]);
}

function identityProofPolicy(): DoctorDeviceIdentityProofPolicy
{
    return app(DoctorDeviceIdentityProofPolicy::class);
}

/*
|--------------------------------------------------------------------------
| §105 — the mandatory policy regression test
|--------------------------------------------------------------------------
*/

it('accepts a device with no android proof but an acceptable device-bound webauthn credential', function () {
    $device = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    proofCredential($device);

    // The precondition that used to make this impossible.
    expect($device->isCryptographicallyVerified())->toBeFalse();

    expect(identityProofPolicy()->acceptable($device->fresh()))->toBeTrue();
    expect(identityProofPolicy()->proof($device->fresh()))
        ->toBe(DoctorDeviceIdentityProofPolicy::PROOF_WEBAUTHN_DEVICE_BOUND);
});

it('refuses a device with neither android proof nor any webauthn credential', function () {
    $device = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    expect(identityProofPolicy()->acceptable($device))->toBeFalse();
    expect(identityProofPolicy()->proof($device))
        ->toBe(DoctorDeviceIdentityProofPolicy::PROOF_NONE);
});

/*
|--------------------------------------------------------------------------
| §141 / §103 — Android compatibility is preserved, history is not rewritten
|--------------------------------------------------------------------------
*/

it('still accepts an android keystore device that holds no webauthn credential', function () {
    $device = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    expect($device->webAuthnCredentials()->count())->toBe(0);
    expect(identityProofPolicy()->acceptable($device))->toBeTrue();
    expect(identityProofPolicy()->proof($device))
        ->toBe(DoctorDeviceIdentityProofPolicy::PROOF_ANDROID_KEYSTORE);
});

it('reports the android proof for a dual-proof device so historical reporting stays stable', function () {
    $device = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    proofCredential($device);

    expect(identityProofPolicy()->proof($device->fresh()))
        ->toBe(DoctorDeviceIdentityProofPolicy::PROOF_ANDROID_KEYSTORE);
});

/*
|--------------------------------------------------------------------------
| The WebAuthn branch must not be weaker than the binding policy it stands in for
|--------------------------------------------------------------------------
*/

it('does not count a revoked credential as identity proof', function () {
    $device = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    proofCredential($device, revoked: true);

    expect(identityProofPolicy()->acceptable($device->fresh()))->toBeFalse();
});

it('does not count an unknown device-bound verdict as identity proof', function () {
    $device = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    proofCredential($device, DoctorDeviceWebAuthnCredential::VERDICT_UNKNOWN);

    // Silence is not a pass: an authenticator that declined to report its
    // backup flags has not said the key is confined to this hardware.
    expect(identityProofPolicy()->acceptable($device->fresh()))->toBeFalse();
});

it('does not count a backup-eligible (syncable) credential as identity proof', function () {
    $device = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    proofCredential($device, DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE);

    expect(identityProofPolicy()->acceptable($device->fresh()))->toBeFalse();
});

it('does not borrow another devices credential as proof', function () {
    $proven = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);
    $bare = DoctorDevice::factory()->create([
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);

    proofCredential($proven);

    expect(identityProofPolicy()->acceptable($proven->fresh()))->toBeTrue();
    expect(identityProofPolicy()->acceptable($bare->fresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| §106 core — the provisioning gate now admits a PWA-only device
|--------------------------------------------------------------------------
*/

it('approves an authorization for a pwa-only device that has no android proof', function () {
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);
    $actor = User::factory()->create();

    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);
    proofCredential($device);

    $authorization = DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => DoctorDeviceAuthorization::STATUS_PENDING,
    ]);

    $approved = app(DoctorDeviceAuthorizationService::class)->approve($authorization, $actor);

    expect($approved->status)->toBe(DoctorDeviceAuthorization::STATUS_ACTIVE);
});

it('still refuses an authorization for a device that has proved nothing', function () {
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);
    $actor = User::factory()->create();

    $device = DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
        'status' => DoctorDevice::STATUS_ACTIVE,
    ]);
    // No credential, no keystore proof.

    $authorization = DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => DoctorDeviceAuthorization::STATUS_PENDING,
    ]);

    expect(fn () => app(DoctorDeviceAuthorizationService::class)->approve($authorization, $actor))
        ->toThrow(ValidationException::class);

    expect($authorization->fresh()->status)->toBe(DoctorDeviceAuthorization::STATUS_PENDING);
});

it('still refuses an authorization on a revoked device even with a valid credential', function () {
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $doctor = Doctor::factory()->create(['is_active' => true]);
    $actor = User::factory()->create();

    $device = DoctorDevice::factory()->revoked()->create([
        'branch_id' => $branch->id,
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
    ]);
    proofCredential($device);

    $authorization = DoctorDeviceAuthorization::factory()->create([
        'doctor_id' => $doctor->id,
        'doctor_device_id' => $device->id,
        'status' => DoctorDeviceAuthorization::STATUS_PENDING,
    ]);

    // Identity proof is not a substitute for administrative trust: the revoked
    // check runs before it and must keep running.
    expect(fn () => app(DoctorDeviceAuthorizationService::class)->approve($authorization, $actor))
        ->toThrow(ValidationException::class);
});
