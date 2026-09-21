<?php

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — the steps themselves.
 *
 * WHAT THIS FILE IS DEFENDING. The workflow is a GUIDE over surfaces that
 * already exist, and the failure mode of a guide is that it grows its own
 * shortcuts: a convenience endpoint that approves while it files, a step that
 * unlocks because the previous one was drawn as finished, a wizard state column
 * that says "done" after somebody revoked the credential elsewhere.
 *
 * So the assertions below are about what is in the DATABASE after each step,
 * not about what the page said. In particular:
 *
 *   - filing a tablet must leave it PENDING_APPROVAL and unusable;
 *   - enrolling a credential must NOT approve anything;
 *   - step 4 must produce PENDING requests and never an ACTIVE authorization;
 *   - one device carries many doctors on ONE credential;
 *   - a hand-typed URL must be refused at the WRITE, whatever the page renders.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Tests\Support\FakeWebAuthnAuthenticator;

const RW_ORIGIN = 'https://clinic.example.test';
const RW_RP_ID = 'clinic.example.test';

beforeEach(function () {
    seedAccessControl();

    config()->set('app.url', RW_ORIGIN);
    config()->set('webauthn.relying_party.id', RW_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', RW_ORIGIN);
    config()->set('webauthn.device_binding.require_device_bound', true);
    config()->set('doctor_webauthn_live_proof.freshness_window_days', 7);

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
});

function rwUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function rwActingAs(User $user)
{
    return test()->actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class);
}

function rwDevice(string $status = DoctorDevice::STATUS_PENDING_APPROVAL): DoctorDevice
{
    return DoctorDevice::factory()->create([
        'branch_id' => test()->branch->id,
        'status' => $status,
    ]);
}

/** A doctor that could actually log in: active record, linked account. */
function rwDoctor(string $name = 'drg. Uji'): Doctor
{
    $account = User::factory()->create(['name' => $name, 'is_active' => true]);
    $account->assignRole('Doctor');

    return Doctor::factory()->create([
        'name' => $name,
        'user_id' => $account->id,
        'is_active' => true,
    ]);
}

/** A real ceremony against the real endpoints — no credential is hand-inserted. */
function rwEnrolCredential(DoctorDevice $device): DoctorDeviceWebAuthnCredential
{
    $authenticator = new FakeWebAuthnAuthenticator(RW_RP_ID, RW_ORIGIN);

    rwActingAs(rwUser('Super Admin'));

    $options = test()->postJson(route('settings.doctor-devices.webauthn.options', $device))->json();

    test()->post(route('settings.doctor-devices.webauthn.store', $device), [
        'registration_workflow' => '1',
        'credential' => $authenticator->attestation($options['challenge']),
    ]);

    auth()->logout();

    return DoctorDeviceWebAuthnCredential::query()
        ->where('credential_id', $authenticator->credentialIdBase64Url())
        ->firstOrFail();
}

// ---------------------------------------------------------------------------
// 4 — step 1 happy path
// ---------------------------------------------------------------------------

it('files a tablet from the workflow and lands it PENDING_APPROVAL, not in service', function () {
    $response = rwActingAs(rwUser('Supervisor RME'))
        ->post(route('settings.doctor-devices.store'), [
            'registration_workflow' => '1',
            'device_name' => 'Tablet Ruang A',
            'branch_id' => $this->branch->id,
        ]);

    $device = DoctorDevice::query()->where('device_name', 'Tablet Ruang A')->firstOrFail();

    expect($device->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL);

    // And it comes back INTO the workflow rather than ejecting the operator
    // onto the device registry.
    $response->assertRedirect(route('settings.doctor-device-registration.show', $device));
});

it('leaves the device registry redirect untouched when the flag is absent', function () {
    // The return-to-workflow flag must not change the behaviour of the
    // existing page it is shared with.
    $response = rwActingAs(rwUser('Supervisor RME'))
        ->post(route('settings.doctor-devices.store'), [
            'device_name' => 'Tablet Ruang B',
            'branch_id' => $this->branch->id,
        ]);

    $device = DoctorDevice::query()->where('device_name', 'Tablet Ruang B')->firstOrFail();

    $response->assertRedirect(route('settings.doctor-devices.show', $device));
});

it('refuses a filed tablet at the login gate, so filing grants nobody access', function () {
    $device = rwDevice();
    $credential = rwEnrolCredential($device);

    $gate = app(DoctorAppLoginGate::class);

    expect($gate->deviceUsableForProof($device->fresh(), DoctorSessionProof::webAuthn((int) $credential->id)))
        ->toBeFalse();
});

// ---------------------------------------------------------------------------
// 5 / 6 — enrolment, and what it must NOT do
// ---------------------------------------------------------------------------

it('enrols a device-bound credential through the workflow and returns to step 2', function () {
    $device = rwDevice();
    $authenticator = new FakeWebAuthnAuthenticator(RW_RP_ID, RW_ORIGIN);

    $operator = rwUser('Super Admin');
    $options = rwActingAs($operator)
        ->postJson(route('settings.doctor-devices.webauthn.options', $device))
        ->json();

    $response = rwActingAs($operator)
        ->post(route('settings.doctor-devices.webauthn.store', $device), [
            'registration_workflow' => '1',
            'credential' => $authenticator->attestation($options['challenge']),
        ]);

    $response->assertRedirect(route('settings.doctor-device-registration.webauthn', $device));

    $credential = DoctorDeviceWebAuthnCredential::query()
        ->where('credential_id', $authenticator->credentialIdBase64Url())
        ->firstOrFail();

    expect($credential->user_verified)->toBeTrue()
        ->and($credential->device_bound_verdict)
        ->toBe(DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND);
});

it('does NOT approve the device when a credential is enrolled on it', function () {
    $device = rwDevice();

    rwEnrolCredential($device);

    // The one assertion this whole step exists to make.
    expect($device->fresh()->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL);
});

it('refuses to enrol a credential for the filing authority', function () {
    $device = rwDevice();

    rwActingAs(rwUser('Supervisor RME'))
        ->postJson(route('settings.doctor-devices.webauthn.options', $device))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// 7 — step 3, the trust decision
// ---------------------------------------------------------------------------

it('admits a filed tablet only for the management authority', function () {
    $device = rwDevice();

    rwActingAs(rwUser('Supervisor RME'))
        ->post(route('settings.doctor-devices.approve-registration', $device), ['registration_workflow' => '1'])
        ->assertForbidden();

    expect($device->fresh()->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL);

    rwActingAs(rwUser('Super Admin'))
        ->post(route('settings.doctor-devices.approve-registration', $device), ['registration_workflow' => '1'])
        ->assertRedirect(route('settings.doctor-device-registration.approval', $device));

    expect($device->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);
});

// ---------------------------------------------------------------------------
// 8 / 9 — one device, many doctors, one credential
// ---------------------------------------------------------------------------

it('authorizes several doctors onto one shared tablet', function () {
    $device = rwDevice(DoctorDevice::STATUS_ACTIVE);
    $a = rwDoctor('drg. A');
    $b = rwDoctor('drg. B');

    rwActingAs(rwUser('Supervisor RME'))
        ->post(route('settings.doctor-device-registration.doctors.store', $device), [
            'doctor_ids' => [$a->id, $b->id],
        ])
        ->assertRedirect(route('settings.doctor-device-registration.doctors', $device));

    expect(DoctorDeviceAuthorization::query()->where('doctor_device_id', $device->id)->count())->toBe(2);
});

it('files requests as PENDING and never approves them itself', function () {
    $device = rwDevice(DoctorDevice::STATUS_ACTIVE);
    $doctor = rwDoctor();

    rwActingAs(rwUser('Supervisor RME'))
        ->post(route('settings.doctor-device-registration.doctors.store', $device), [
            'doctor_ids' => [$doctor->id],
        ]);

    $authorization = DoctorDeviceAuthorization::query()
        ->where('doctor_device_id', $device->id)
        ->where('doctor_id', $doctor->id)
        ->firstOrFail();

    // The D-3 invariant, asserted on the row rather than on the page.
    expect($authorization->status)->toBe(DoctorDeviceAuthorization::STATUS_PENDING)
        ->and($authorization->request_source)->toBe(DoctorDeviceAuthorization::SOURCE_ADMIN)
        ->and($authorization->approved_at)->toBeNull();
});

it('does not recreate the credential when another doctor is added to the same device', function () {
    $device = rwDevice(DoctorDevice::STATUS_ACTIVE);
    $credential = rwEnrolCredential($device);

    $before = DoctorDeviceWebAuthnCredential::query()
        ->where('doctor_device_id', $device->id)
        ->pluck('credential_id')
        ->sort()
        ->values()
        ->all();

    $a = rwDoctor('drg. Satu');
    $b = rwDoctor('drg. Dua');

    $operator = rwUser('Supervisor RME');
    rwActingAs($operator)->post(route('settings.doctor-device-registration.doctors.store', $device), [
        'doctor_ids' => [$a->id],
    ]);
    rwActingAs($operator)->post(route('settings.doctor-device-registration.doctors.store', $device), [
        'doctor_ids' => [$b->id],
    ]);

    $after = DoctorDeviceWebAuthnCredential::query()
        ->where('doctor_device_id', $device->id)
        ->pluck('credential_id')
        ->sort()
        ->values()
        ->all();

    // Byte-identical, not merely "still one": a credential belongs to the
    // DEVICE, so adding a doctor must not touch it at all.
    expect($after)->toBe($before)
        ->and($credential->fresh()->revoked_at)->toBeNull();
});

it('is idempotent when the same doctor is filed twice', function () {
    $device = rwDevice(DoctorDevice::STATUS_ACTIVE);
    $doctor = rwDoctor();
    $operator = rwUser('Supervisor RME');

    rwActingAs($operator)->post(route('settings.doctor-device-registration.doctors.store', $device), [
        'doctor_ids' => [$doctor->id],
    ]);
    rwActingAs($operator)->post(route('settings.doctor-device-registration.doctors.store', $device), [
        'doctor_ids' => [$doctor->id],
    ]);

    expect(DoctorDeviceAuthorization::query()
        ->where('doctor_device_id', $device->id)
        ->where('doctor_id', $doctor->id)
        ->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// 19 — prerequisites fail CLOSED, whatever the page renders
// ---------------------------------------------------------------------------

it('refuses to authorize a doctor onto a tablet that is not approved yet', function () {
    $device = rwDevice(); // still PENDING_APPROVAL
    $doctor = rwDoctor();

    $response = rwActingAs(rwUser('Supervisor RME'))
        ->post(route('settings.doctor-device-registration.doctors.store', $device), [
            'doctor_ids' => [$doctor->id],
        ]);

    $response->assertRedirect(route('settings.doctor-device-registration.approval', $device))
        ->assertSessionHasErrors('doctor_ids');

    // The refusal is asserted in the DATABASE: a redirect with a message is
    // not evidence that nothing was written.
    expect(DoctorDeviceAuthorization::query()->where('doctor_device_id', $device->id)->count())->toBe(0);
});

it('refuses the step 4 write to an operator who may only file tablets', function () {
    $device = rwDevice(DoctorDevice::STATUS_ACTIVE);
    $doctor = rwDoctor();

    // A user holding register_doctor_devices but NOT the authorization
    // permission: the route middleware refuses before the controller runs.
    $filer = User::factory()->create();
    $filer->givePermissionTo('register_doctor_devices');

    rwActingAs($filer->fresh())
        ->post(route('settings.doctor-device-registration.doctors.store', $device), [
            'doctor_ids' => [$doctor->id],
        ])
        ->assertForbidden();

    expect(DoctorDeviceAuthorization::query()->where('doctor_device_id', $device->id)->count())->toBe(0);
});

it('refuses a doctor id that belongs to an inactive doctor record', function () {
    $device = rwDevice(DoctorDevice::STATUS_ACTIVE);
    $doctor = rwDoctor();
    $doctor->forceFill(['is_active' => false])->save();

    rwActingAs(rwUser('Supervisor RME'))
        ->post(route('settings.doctor-device-registration.doctors.store', $device), [
            'doctor_ids' => [$doctor->id],
        ]);

    expect(DoctorDeviceAuthorization::query()->where('doctor_device_id', $device->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// 17 — step 7 is not reachable by typing its URL
// ---------------------------------------------------------------------------

it('redirects step 7 back to readiness while the tablet is not ready', function () {
    $device = rwDevice();

    rwActingAs(rwUser('Super Admin'))
        ->get(route('settings.doctor-device-registration.complete', $device))
        ->assertRedirect(route('settings.doctor-device-registration.readiness', $device))
        ->assertSessionHasErrors('readiness');
});

it('never claims READY FOR CLINICAL USE for an unfinished tablet', function () {
    $device = rwDevice();

    rwActingAs(rwUser('Super Admin'))
        ->get(route('settings.doctor-device-registration.readiness', $device))
        ->assertOk()
        ->assertDontSee('READY FOR CLINICAL USE');
});
