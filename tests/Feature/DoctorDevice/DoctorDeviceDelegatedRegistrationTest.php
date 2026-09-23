<?php

/**
 * DAENGTISIAMS-SUNU-FINAL-RELEASE-CANDIDATE-1 / D11 — opening the new-device
 * registration workflow WITHOUT opening the decision to trust a device.
 *
 * WHY THIS EXISTS. D10 locks all ten Cabang Sunu doctors to PWA-only login, so
 * a doctor can only work from a tablet the registry knows. Before D11 the
 * registry half was reachable by Super Admin alone — `manage_doctor_devices`
 * is granted to no role — which meant a replacement tablet on a clinic morning
 * waited on a developer. That is the thing being opened.
 *
 * WHAT IS NOT BEING OPENED. `RoleSeeder` documents a split in prose: "is this
 * tablet trusted?" (`manage_doctor_devices`, nobody) is a different authority
 * from "may this doctor use this tablet?" (`manage_doctor_device_authorizations`,
 * Supervisor RME). Handing Supervisor RME the management permission would have
 * collapsed it — one person could file a tablet, enrol its credential and
 * authorise a doctor onto it with no second party anywhere in the chain.
 *
 * SO FILING IS ITS OWN, WEAKER AUTHORITY. `register_doctor_devices` files a
 * tablet as PENDING_APPROVAL and nothing else. It is safe precisely because
 * `DoctorAppLoginGate::deviceProofDenyReason()` opens with a strict allowlist
 * — `if (! $device->isActive())` — so a filed row is refused at the FIRST
 * check, for both proof types. That property is asserted below against the
 * real gate rather than assumed, because the entire design rests on it.
 *
 * THE TRAP THIS FILE ALSO GUARDS. `ApproveDoctorDeviceEnrollmentRequest`
 * authorises against `can('create', DoctorDevice::class)` — the same ability
 * the registry form used. Widening `create` to admit the filing authority
 * would have handed Supervisor RME the Android pairing approval, which binds a
 * public key and produces an immediately ACTIVE device, through a sibling that
 * merely happened to reuse the ability. Filing therefore got a NEW ability
 * (`register`) and `create` was left alone. The last test here is what stops
 * that coupling from quietly re-forming.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceService;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

function d11Branch(): Branch
{
    return Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
}

/** The filing authority: Supervisor RME, and deliberately nothing more. */
function d11Filer(): User
{
    $user = User::factory()->create(['name' => 'Supervisor RME Sunu']);
    $user->assignRole('Supervisor RME');

    return $user->fresh();
}

function d11ActingAs(User $user): TestResponse|TestCase
{
    return test()->actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class);
}

beforeEach(function () {
    seedAccessControl();
    $this->branch = d11Branch();
});

// ─── The workflow is genuinely open ─────────────────────────────────────────

it('lets the filing authority reach the registry and the filing form', function () {
    $filer = d11Filer();

    d11ActingAs($filer)->get(route('settings.doctor-devices.index'))->assertOk();
    d11ActingAs($filer)->get(route('settings.doctor-devices.create'))->assertOk();
});

it('files a new tablet as pending approval, recording who filed it', function () {
    $filer = d11Filer();

    d11ActingAs($filer)->post(route('settings.doctor-devices.store'), [
        'device_name' => 'Tablet Sunu Cadangan',
        'branch_id' => $this->branch->id,
        'platform' => 'web',
    ])->assertRedirect();

    $device = DoctorDevice::query()->where('device_name', 'Tablet Sunu Cadangan')->firstOrFail();

    expect($device->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL)
        ->and($device->identity_state)->toBe(DoctorDevice::IDENTITY_UNVERIFIED)
        ->and((int) $device->registered_by)->toBe((int) $filer->id)
        ->and($device->public_key_fingerprint)->toBeNull();
});

// ─── …and it grants nobody anything. The load-bearing property. ─────────────

it('refuses a filed tablet at the login gate for BOTH proof types', function () {
    $filer = d11Filer();

    d11ActingAs($filer)->post(route('settings.doctor-devices.store'), [
        'device_name' => 'Tablet Belum Disetujui',
        'branch_id' => $this->branch->id,
    ]);

    $device = DoctorDevice::query()->where('device_name', 'Tablet Belum Disetujui')->firstOrFail();
    $gate = app(DoctorAppLoginGate::class);

    // The gate's FIRST check is `! isActive()`, so both proofs die there. If
    // this ever passes, filing has become a way to admit hardware and the
    // whole delegation is unsafe.
    //
    // Asserted on the REASON, not merely on refusal. An unverified device is
    // refused for an Android proof whatever its status, so a bare `toBeFalse`
    // here would have passed even if the status check were deleted — it would
    // be testing the missing key, not the missing approval.
    expect($gate->deviceProofDenyReason($device, DoctorSessionProof::androidKeystore()))
        ->toBe(DoctorAppLoginGate::DENY_DEVICE_NOT_USABLE)
        ->and($gate->deviceProofDenyReason($device, DoctorSessionProof::webAuthn(1)))
        ->toBe(DoctorAppLoginGate::DENY_DEVICE_NOT_USABLE);

    // And the status is provably what is doing the refusing: approve the very
    // same row and the WebAuthn proof now travels PAST the status check and
    // dies further down, on the credential it still does not have.
    app(DoctorDeviceService::class)->approveRegistration($device, superAdmin());

    expect($gate->deviceProofDenyReason($device->fresh(), DoctorSessionProof::webAuthn(1)))
        ->not->toBe(DoctorAppLoginGate::DENY_DEVICE_NOT_USABLE);
});

// ─── The filer holds filing authority and nothing adjacent ──────────────────

it('denies the filing authority every trust decision on the device surface', function (string $route, string $verb) {
    $filer = d11Filer();
    $device = DoctorDevice::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
    ]);

    $response = $verb === 'get'
        ? d11ActingAs($filer)->get(route($route, $device))
        : d11ActingAs($filer)->post(route($route, $device), ['reason' => 'percobaan']);

    $response->assertForbidden();
})->with([
    // Its own filed tablet — it may not wave it through.
    'approve its own filing' => ['settings.doctor-devices.approve-registration', 'post'],
    // The actual trust ceremony: binding a credential to the hardware.
    'enrol a credential' => ['settings.doctor-devices.webauthn.create', 'get'],
    'edit metadata of a live device' => ['settings.doctor-devices.edit', 'get'],
    'disable' => ['settings.doctor-devices.disable', 'post'],
    'reactivate' => ['settings.doctor-devices.reactivate', 'post'],
    'revoke' => ['settings.doctor-devices.revoke', 'post'],
]);

it('denies the filing authority the Android pairing approval', function () {
    // THE COUPLING GUARD. This route authorises on `create`, which the filing
    // form used to share. `approveIntoNewDevice` binds the device's public key
    // and produces an ACTIVE tablet, so reaching it from the filing authority
    // would defeat every other assertion in this file.
    $filer = d11Filer();
    $enrollment = DoctorDeviceEnrollment::factory()->create();

    d11ActingAs($filer)->post(route('settings.doctor-device-enrollments.approve', $enrollment), [
        'device_name' => 'Tablet Selundupan',
        'branch_id' => $this->branch->id,
    ])->assertForbidden();

    expect($enrollment->fresh()->status)->toBe(DoctorDeviceEnrollment::STATUS_PENDING);
});

// ─── The second party, and the pre-D11 operator, are unchanged ──────────────

it('still files straight to active for a management authority', function () {
    // No regression for whoever had this screen before D11: Super Admin
    // passes `can('manage_doctor_devices')` through the global Gate::before,
    // so their filing lands ACTIVE exactly as it always did.
    $admin = superAdmin();

    d11ActingAs($admin)->post(route('settings.doctor-devices.store'), [
        'device_name' => 'Tablet Super Admin',
        'branch_id' => $this->branch->id,
    ])->assertRedirect();

    expect(DoctorDevice::query()->where('device_name', 'Tablet Super Admin')->value('status'))
        ->toBe(DoctorDevice::STATUS_ACTIVE);
});

it('admits a filed tablet when the second party approves it', function () {
    $admin = superAdmin();
    $device = DoctorDevice::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
        'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
    ]);

    d11ActingAs($admin)
        ->post(route('settings.doctor-devices.approve-registration', $device))
        ->assertRedirect();

    $device->refresh();

    // Approving the paperwork is not proving the hardware: the tablet still
    // has to enrol a credential before anyone can sign in on it.
    expect($device->status)->toBe(DoctorDevice::STATUS_ACTIVE)
        ->and($device->identity_state)->toBe(DoctorDevice::IDENTITY_UNVERIFIED)
        ->and($device->public_key_fingerprint)->toBeNull();
});

// ─── Approval is a door for ONE status, not a laundering path ───────────────

it('refuses to approve anything that was not filed', function (string $status) {
    $admin = superAdmin();
    $device = DoctorDevice::factory()->create(['branch_id' => $this->branch->id, 'status' => $status]);

    expect(fn () => app(DoctorDeviceService::class)->approveRegistration($device, $admin))
        ->toThrow(ValidationException::class);

    expect($device->fresh()->status)->toBe($status);
})->with([
    'disabled' => [DoctorDevice::STATUS_DISABLED],
    'revoked' => [DoctorDevice::STATUS_REVOKED],
]);

it('keeps reactivate shut as a side door into service', function () {
    // `reactivate` undoes a DISABLE and nothing else. D11 makes
    // PENDING_APPROVAL reachable for the first time, so the assertion that
    // guarded a status nothing produced now guards a real one.
    $admin = superAdmin();
    $device = DoctorDevice::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
    ]);

    expect(fn () => app(DoctorDeviceService::class)->reactivate($device, $admin))
        ->toThrow(ValidationException::class);

    expect($device->fresh()->status)->toBe(DoctorDevice::STATUS_PENDING_APPROVAL);
});

it('is idempotent when the same approval is submitted twice', function () {
    $admin = superAdmin();
    $device = DoctorDevice::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
    ]);

    $service = app(DoctorDeviceService::class);
    $service->approveRegistration($device, $admin);
    $service->approveRegistration($device->fresh(), $admin);

    expect($device->fresh()->status)->toBe(DoctorDevice::STATUS_ACTIVE);
});
