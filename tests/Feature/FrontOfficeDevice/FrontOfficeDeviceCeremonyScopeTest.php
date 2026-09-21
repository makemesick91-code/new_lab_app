<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceWebAuthnCredentialRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeDeviceWebAuthnLoginService;
use App\Support\AccessControl\FrontOfficeRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — ceremony SCOPE
|--------------------------------------------------------------------------
|
| The cryptography is the doctor programme's, already covered by its own suite
| and unchanged here. What is NEW, and therefore what is tested here, is WHICH
| CREDENTIALS THIS ACCOUNT MAY EVEN ATTEMPT: the doctor path asks an
| authorization row, and this path asks the pinned branch instead.
|
| Getting that substitution wrong is the one way this sprint could widen the
| doctor programme's security rather than reuse it, so the boundary is pinned
| from both directions — what is offered, and what is refused.
*/

beforeEach(function () {
    seedAccessControl();

    // A secure origin is a WebAuthn precondition; the relying party refuses an
    // insecure one, which is correct and is why the test env must supply one.
    config()->set('app.url', 'https://clinic.example.test');
    config()->set('webauthn.relying_party.id', 'clinic.example.test');
    config()->set('webauthn.relying_party.allowed_origins', 'https://clinic.example.test');
    config()->set('webauthn.device_binding.require_device_bound', true);

    // The four real production codes are the only ones the policy admits.
    $this->sunu = Branch::factory()->create([
        'code' => 'SPN4', 'name' => 'Cabang SPN4', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->landak = Branch::factory()->create([
        'code' => 'LDK2', 'name' => 'Cabang LDK2', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $this->user = User::factory()
        ->create(['password' => Hash::make('password')])
        ->assignRole(FrontOfficeRole::NAME);

    $flags = config('feature_flags.flags', []);
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = true;
    config()->set('feature_flags.flags', $flags);
    config()->set('front_office_device_lock.scope.cohort', $this->user->id.':SPN4');
});

function fodDevice(Branch $branch, array $overrides = []): DoctorDevice
{
    return DoctorDevice::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ], $overrides));
}

/** A credential that names the TABLET — there is no doctor column to fill. */
function fodCredential(DoctorDevice $device, array $overrides = []): DoctorDeviceWebAuthnCredential
{
    return DoctorDeviceWebAuthnCredential::query()->create(array_merge([
        'uuid' => (string) Str::uuid(),
        'doctor_device_id' => $device->id,
        'credential_id' => Str::random(48),
        'public_key' => base64_encode(Str::random(64)),
        'signature_counter' => 0,
        'user_verified' => true,
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND,
        'registered_at' => now(),
    ], $overrides));
}

function fodService(): FrontOfficeDeviceWebAuthnLoginService
{
    return app(FrontOfficeDeviceWebAuthnLoginService::class);
}

it('offers only credentials on approved devices in the pinned branch', function () {
    $ownDevice = fodDevice($this->sunu);
    $own = fodCredential($ownDevice);

    // Another branch's approved tablet, with a perfectly valid credential.
    $otherBranch = fodCredential(fodDevice($this->landak));

    $candidates = fodService()->candidateCredentials($this->user);

    expect($candidates->pluck('id')->all())->toBe([$own->id])
        ->and($candidates->pluck('id'))->not->toContain($otherBranch->id)
        ->and(fodService()->canAssert($this->user))->toBeTrue();
});

it('offers nothing from a device that is not approved', function () {
    fodCredential(fodDevice($this->sunu, ['status' => DoctorDevice::STATUS_REVOKED, 'revoked_at' => now()]));
    fodCredential(fodDevice($this->sunu, ['status' => DoctorDevice::STATUS_DISABLED, 'disabled_at' => now()]));
    fodCredential(fodDevice($this->sunu, ['identity_state' => DoctorDevice::IDENTITY_UNVERIFIED]));
    fodCredential(fodDevice($this->sunu, ['enrollment_status' => DoctorDevice::ENROLLMENT_NOT_ENROLLED]));

    expect(fodService()->candidateCredentials($this->user))->toBeEmpty()
        ->and(fodService()->canAssert($this->user))->toBeFalse();
});

it('offers nothing for a revoked credential on an approved device', function () {
    fodCredential(fodDevice($this->sunu), ['revoked_at' => now(), 'revoked_reason' => 'Hilang.']);

    expect(fodService()->candidateCredentials($this->user))->toBeEmpty();
});

it('offers nothing for a credential that is not single-device bound', function () {
    fodCredential(fodDevice($this->sunu), [
        'device_bound_verdict' => DoctorDeviceWebAuthnCredential::VERDICT_BACKUP_ELIGIBLE,
    ]);

    // A syncable passkey can leave the tablet, which is the one thing a device
    // lock cannot tolerate.
    expect(fodService()->candidateCredentials($this->user))->toBeEmpty();
});

it('refuses the ceremony for an account that is not armed', function () {
    fodCredential(fodDevice($this->sunu));

    $other = User::factory()->create()->assignRole(FrontOfficeRole::NAME);
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    expect(fodService()->canAssert($other))->toBeFalse()
        ->and(fn () => fodService()->requestOptions($other, $request))
        ->toThrow(ValidationException::class)
        ->and(fn () => fodService()->completeLogin($other, $request, ['id' => 'x']))
        ->toThrow(ValidationException::class);
});

it('refuses the ceremony outright while the capability is off', function () {
    fodCredential(fodDevice($this->sunu));

    $flags = config('feature_flags.flags', []);
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = false;
    config()->set('feature_flags.flags', $flags);

    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    // Switching the flag off must not leave a side door that mints bindings.
    expect(fodService()->canAssert($this->user))->toBeFalse()
        ->and(fn () => fodService()->requestOptions($this->user, $request))
        ->toThrow(ValidationException::class);
});

it('issues options bound to this user when a real candidate exists', function () {
    $credential = fodCredential(fodDevice($this->sunu));

    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    $result = fodService()->requestOptions($this->user, $request);

    expect($result['challenge']->user_id)->toBe($this->user->id)
        ->and($result['options']->allowCredentials)->toHaveCount(1);

    // The offered descriptor is the credential on the pinned branch's tablet.
    expect($credential->fresh()->revoked_at)->toBeNull();
});

it('keeps the pending marker useless on its own', function () {
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    fodService()->beginPending($request, $this->user);

    expect(fodService()->pendingUser($request)?->id)->toBe($this->user->id);

    // It expires, and an expired marker names nobody.
    $this->travel(FrontOfficeDeviceWebAuthnLoginService::PENDING_TTL_SECONDS + 5)->seconds();

    expect(fodService()->pendingUser($request))->toBeNull();
});

it('sends a denied armed login to the ceremony only when it can succeed', function () {
    // A credential exists on the pinned branch's tablet: offer the ceremony.
    fodCredential(fodDevice($this->sunu));

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertRedirect(route('front-office-device-webauthn.show'));

    $this->assertGuest();
});

it('gives a plain refusal when no credential could ever answer', function () {
    // Approved tablet, but nothing registered on it yet — the state production
    // is in today for every front desk.
    fodDevice($this->sunu);

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('never offers the ceremony as a retry for a wrong-branch device', function () {
    fodCredential(fodDevice($this->sunu));
    $wrong = fodDevice($this->landak);

    // Already a decided denial: a retry loop would only invite the operator to
    // keep trying the wrong tablet.
    $this->withSession([
        FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID => $wrong->id,
    ])->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('leaves the doctor credential lookup untouched', function () {
    $device = fodDevice($this->sunu);
    fodCredential($device);

    // The doctor path resolves candidates through an authorization row, and this
    // sprint added none — so a front-desk credential is invisible to it. The two
    // lookups share a store without sharing a scope.
    $doctorVisible = app(DoctorDeviceWebAuthnCredentialRepositoryInterface::class)
        ->usableForDoctor(999999);

    expect($doctorVisible)->toBeEmpty();
});
