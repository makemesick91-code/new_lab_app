<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\FrontOfficeDevice\Support\FrontOfficeDeviceLockDecision;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Support\AccessControl\FrontOfficeRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeWebAuthnAuthenticator;

/*
|--------------------------------------------------------------------------
| REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — the whole chain, end to end
|--------------------------------------------------------------------------
|
| No credential is hand-inserted here. Each one is enrolled through the REAL
| registration endpoints with a real EC key, and each login is a real assertion
| signed by that key. That matters because the claim being tested is not "the
| service returns ALLOW" — it is that a front desk can actually get in on its own
| tablet, and cannot get in on anybody else's.
|
| ENROLMENT NEEDS NO NEW PATH, WHICH IS A CORRECTION TO THIS SPRINT'S OWN
| EARLIER ASSUMPTION.
|
| `settings.doctor-devices.webauthn.*` registers a credential onto a DEVICE and
| authorizes the actor through the DoctorDevice policy. No doctor record is
| involved anywhere in it. So an administrator sitting at the front-desk tablet
| enrols that tablet exactly as they would enrol a clinical one, and this sprint
| adds no enrolment surface at all.
*/

const FO_ORIGIN = 'https://clinic.example.test';
const FO_RP_ID = 'clinic.example.test';

beforeEach(function () {
    seedAccessControl();

    config()->set('app.url', FO_ORIGIN);
    config()->set('webauthn.relying_party.id', FO_RP_ID);
    config()->set('webauthn.relying_party.allowed_origins', FO_ORIGIN);
    config()->set('webauthn.device_binding.require_device_bound', true);

    $this->sunu = Branch::factory()->create([
        'code' => 'SPN4', 'name' => 'Cabang SPN4', 'is_active' => true, 'is_rme_enabled' => true,
    ]);
    $this->landak = Branch::factory()->create([
        'code' => 'LDK2', 'name' => 'Cabang LDK2', 'is_active' => true, 'is_rme_enabled' => true,
    ]);

    $this->user = User::factory()->create([
        'name' => 'Admin Sunu',
        'email' => 'adminsunu@example.test',
        'password' => Hash::make('password'),
    ])->assignRole(FrontOfficeRole::NAME);

    $flags = config('feature_flags.flags', []);
    $flags[FrontOfficeBranchDeviceLockService::FLAG]['default'] = true;
    config()->set('feature_flags.flags', $flags);
    config()->set('front_office_device_lock.scope.cohort', $this->user->id.':SPN4');
});

function folDevice(Branch $branch): DoctorDevice
{
    return DoctorDevice::factory()->create([
        'branch_id' => $branch->id,
        'status' => DoctorDevice::STATUS_ACTIVE,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
    ]);
}

/**
 * Enrol a credential onto a device through the real endpoints, as an
 * administrator would at the tablet.
 *
 * @return array{authenticator: FakeWebAuthnAuthenticator, credential: DoctorDeviceWebAuthnCredential}
 */
function folEnrol(DoctorDevice $device): array
{
    $authenticator = new FakeWebAuthnAuthenticator(FO_RP_ID, FO_ORIGIN);

    $admin = User::factory()->create()->assignRole('Super Admin');

    test()->actingAs($admin)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->withServerVariables(['HTTP_ORIGIN' => FO_ORIGIN]);

    $options = test()->postJson(route('settings.doctor-devices.webauthn.options', $device))->json();

    test()->post(route('settings.doctor-devices.webauthn.store', $device), [
        'credential' => $authenticator->attestation($options['challenge']),
    ])->assertSessionHasNoErrors();

    auth()->logout();
    test()->flushSession();

    return [
        'authenticator' => $authenticator,
        'credential' => DoctorDeviceWebAuthnCredential::query()
            ->where('credential_id', $authenticator->credentialIdBase64Url())
            ->firstOrFail(),
    ];
}

/** Password step, then the assertion, exactly as the browser performs it. */
function folLogin(User $user, FakeWebAuthnAuthenticator $authenticator, DoctorDevice $device)
{
    test()->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('front-office-device-webauthn.show'));

    $options = test()->postJson(route('front-office-device-webauthn.options'))->json();

    return test()->post(route('front-office-device-webauthn.store'), [
        'credential' => $authenticator->assertion($options['challenge'], (string) $device->uuid),
    ]);
}

it('lets Admin Sunu in on the Sunu tablet, and binds the session to it', function () {
    $device = folDevice($this->sunu);
    $enrolled = folEnrol($device);

    folLogin($this->user, $enrolled['authenticator'], $device)
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($this->user);

    // The session records WHICH tablet proved itself, server-side.
    expect(session(FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID))->toBe($device->id)
        ->and(session(FrontOfficeBranchDeviceLockService::SESSION_CREDENTIAL_ID))
        ->toBe($enrolled['credential']->id);

    // The success is recorded once.
    expect(DB::table('sys_audit_logs')
        ->where('action', 'FRONT_OFFICE_DEVICE_WEBAUTHN_LOGIN_SUCCESS')->count())->toBe(1);

    /*
     * There is EXACTLY ONE denial row, and it is correct rather than a defect.
     *
     * The password step ran before any assertion had happened, so at that
     * moment the account genuinely had no device bound — the gate denied it,
     * tore the session down, recorded why, and sent the browser to the
     * ceremony. That row is the audit trail of a real refusal, and the doctor
     * flow records its equivalent the same way.
     *
     * What must NOT appear is a branch or approval denial: nothing about this
     * login was wrong once the tablet had proved itself.
     */
    $denials = DB::table('sys_audit_logs')
        ->where('action', 'FRONT_OFFICE_DEVICE_BRANCH_LOGIN_DENIED')
        ->pluck('new_values')
        ->map(fn ($payload) => json_decode((string) $payload, true)['reason'] ?? null);

    expect($denials->all())->toBe([FrontOfficeDeviceLockDecision::DENY_UNKNOWN_DEVICE]);
});

it('refuses Admin Sunu holding a credential enrolled on the Landak tablet', function () {
    // Both tablets are approved, key-proved and enrolled. The ONLY thing wrong
    // with the second one is that it belongs to another branch.
    folEnrol(folDevice($this->sunu));
    $wrongDevice = folDevice($this->landak);
    $wrong = folEnrol($wrongDevice);

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertRedirect(route('front-office-device-webauthn.show'));

    $options = $this->postJson(route('front-office-device-webauthn.options'))->json();

    $this->post(route('front-office-device-webauthn.store'), [
        'credential' => $wrong['authenticator']->assertion($options['challenge'], (string) $wrongDevice->uuid),
    ])->assertSessionHasErrors('credential');

    $this->assertGuest();
    expect(session(FrontOfficeBranchDeviceLockService::SESSION_DEVICE_ID))->toBeNull();
});

it('refuses a credential revoked between enrolment and login', function () {
    $device = folDevice($this->sunu);
    $enrolled = folEnrol($device);

    $enrolled['credential']->forceFill([
        'revoked_at' => now(),
        'revoked_reason' => 'Perangkat diganti.',
    ])->save();

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('refuses a forged signature on an otherwise perfect credential', function () {
    $device = folDevice($this->sunu);
    $enrolled = folEnrol($device);

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
        ->assertRedirect(route('front-office-device-webauthn.show'));

    $options = $this->postJson(route('front-office-device-webauthn.options'))->json();

    $this->post(route('front-office-device-webauthn.store'), [
        'credential' => $enrolled['authenticator']->assertion(
            $options['challenge'],
            (string) $device->uuid,
            forgeSignature: true,
        ),
    ])->assertSessionHasErrors('credential');

    $this->assertGuest();
});

it('refuses a replayed assertion', function () {
    $device = folDevice($this->sunu);
    $enrolled = folEnrol($device);

    $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);
    $options = $this->postJson(route('front-office-device-webauthn.options'))->json();
    $assertion = $enrolled['authenticator']->assertion($options['challenge'], (string) $device->uuid);

    $this->post(route('front-office-device-webauthn.store'), ['credential' => $assertion])
        ->assertSessionHasNoErrors();
    $this->post('/logout');

    // The same signed assertion a second time: the challenge is spent.
    $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);
    $this->post(route('front-office-device-webauthn.store'), ['credential' => $assertion])
        ->assertSessionHasErrors('credential');

    $this->assertGuest();
});

it('refuses the ceremony without the password step', function () {
    $device = folDevice($this->sunu);
    $enrolled = folEnrol($device);

    // No pending marker: the password was never accepted, so the assertion has
    // no account to attach to.
    $this->postJson(route('front-office-device-webauthn.options'))->assertStatus(419);

    $this->post(route('front-office-device-webauthn.store'), [
        'credential' => $enrolled['authenticator']->assertion('abc', (string) $device->uuid),
    ])->assertRedirect(route('login'));

    $this->assertGuest();
});

it('pins the branch context after a real device login', function () {
    $device = folDevice($this->sunu);
    $enrolled = folEnrol($device);

    folLogin($this->user, $enrolled['authenticator'], $device);

    $this->assertAuthenticatedAs($this->user);

    expect(app(BranchContext::class)->forUser($this->user->fresh()))
        ->toBe((int) $this->sunu->id);
});
