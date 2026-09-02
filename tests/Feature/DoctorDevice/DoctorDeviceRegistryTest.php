<?php

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 2.
 *
 * The clinic device registry: lifecycle, branch binding, audit and the trust
 * honesty rule that a hand-entered row can never claim to be a proven device.
 *
 * Phase 2 is CAPABILITY ONLY. Enforcement is not switched on here, and the
 * companion DoctorDeviceAccessTest pins that a doctor's login and Phase-1
 * clinical restrictions are completely unaffected.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Services\DoctorDeviceService;
use App\Modules\LabOrder\Models\AuditLog;
use Illuminate\Validation\ValidationException;

/** A Super Admin actor plus one active, RME-enabled branch. */
function deviceFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create([
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);

    return ['admin' => superAdmin(), 'branch' => $branch];
}

function deviceService(): DoctorDeviceService
{
    return app(DoctorDeviceService::class);
}

// ---------------------------------------------------------------------------
// Creation + branch binding
// ---------------------------------------------------------------------------

it('registers a device as active and unverified', function () {
    $f = deviceFixture();

    $device = deviceService()->register([
        'device_name' => 'Tablet Ruang A',
        'branch_id' => $f['branch']->id,
        'platform' => 'android',
    ], $f['admin']);

    expect($device->status)->toBe(DoctorDevice::STATUS_ACTIVE)
        ->and($device->identity_state)->toBe(DoctorDevice::IDENTITY_UNVERIFIED)
        ->and($device->uuid)->not->toBeEmpty()
        ->and($device->registered_by)->toBe($f['admin']->id)
        ->and($device->registered_at)->not->toBeNull();
});

it('never lets a hand-entered device claim cryptographic verification', function () {
    $f = deviceFixture();

    // Even if the payload asks for it, Phase 2 has no key material and must
    // not manufacture a trusted-looking record.
    $device = deviceService()->register([
        'device_name' => 'Tablet Palsu',
        'branch_id' => $f['branch']->id,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'public_key_fingerprint' => 'deadbeef',
        'status' => DoctorDevice::STATUS_REVOKED,
    ], $f['admin']);

    expect($device->identity_state)->toBe(DoctorDevice::IDENTITY_UNVERIFIED)
        ->and($device->public_key_fingerprint)->toBeNull()
        ->and($device->status)->toBe(DoctorDevice::STATUS_ACTIVE);
});

it('rejects a device on a branch that is not RME eligible', function () {
    $f = deviceFixture();
    $disabled = Branch::factory()->create(['is_active' => false, 'is_rme_enabled' => false]);

    deviceService()->register([
        'device_name' => 'Tablet Nakal',
        'branch_id' => $disabled->id,
    ], $f['admin']);
})->throws(ValidationException::class);

it('rejects a device on a branch that does not exist', function () {
    $f = deviceFixture();

    deviceService()->register([
        'device_name' => 'Tablet Hantu',
        'branch_id' => 999999,
    ], $f['admin']);
})->throws(ValidationException::class);

it('rejects a duplicate device name within the same branch', function () {
    $f = deviceFixture();

    deviceService()->register(['device_name' => 'Tablet Kembar', 'branch_id' => $f['branch']->id], $f['admin']);
    deviceService()->register(['device_name' => 'Tablet Kembar', 'branch_id' => $f['branch']->id], $f['admin']);
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

it('disables an active device with a reason', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);

    $updated = deviceService()->disable($device, 'Dipinjam ke cabang lain', $f['admin']);

    expect($updated->status)->toBe(DoctorDevice::STATUS_DISABLED)
        ->and($updated->disabled_reason)->toBe('Dipinjam ke cabang lain')
        ->and($updated->disabled_by)->toBe($f['admin']->id)
        ->and($updated->disabled_at)->not->toBeNull();
});

it('reactivates a disabled device', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->disabled()->create(['branch_id' => $f['branch']->id]);

    $updated = deviceService()->reactivate($device, $f['admin']);

    expect($updated->status)->toBe(DoctorDevice::STATUS_ACTIVE)
        ->and($updated->disabled_at)->toBeNull()
        ->and($updated->disabled_reason)->toBeNull();
});

it('revokes an active device with a reason', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);

    $updated = deviceService()->revoke($device, 'Perangkat hilang di perjalanan', $f['admin']);

    expect($updated->status)->toBe(DoctorDevice::STATUS_REVOKED)
        ->and($updated->revoked_reason)->toBe('Perangkat hilang di perjalanan')
        ->and($updated->revoked_by)->toBe($f['admin']->id)
        ->and($updated->revoked_at)->not->toBeNull();
});

it('revokes a disabled device', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->disabled()->create(['branch_id' => $f['branch']->id]);

    expect(deviceService()->revoke($device, 'Tidak pernah kembali', $f['admin'])->status)
        ->toBe(DoctorDevice::STATUS_REVOKED);
});

it('refuses to reactivate a revoked device', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->revoked()->create(['branch_id' => $f['branch']->id]);

    deviceService()->reactivate($device, $f['admin']);
})->throws(ValidationException::class);

it('refuses to disable a revoked device', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->revoked()->create(['branch_id' => $f['branch']->id]);

    deviceService()->disable($device, 'apa pun', $f['admin']);
})->throws(ValidationException::class);

it('requires a reason to revoke', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);

    deviceService()->revoke($device, '   ', $f['admin']);
})->throws(ValidationException::class);

it('requires a reason to disable', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);

    deviceService()->disable($device, '', $f['admin']);
})->throws(ValidationException::class);

it('keeps a revoked device readable rather than deleting its history', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);

    deviceService()->revoke($device, 'Hilang', $f['admin']);

    expect(DoctorDevice::query()->whereKey($device->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Metadata updates must not become a status backdoor
// ---------------------------------------------------------------------------

it('updates safe metadata without touching lifecycle state', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);

    $updated = deviceService()->updateMetadata($device, [
        'device_name' => 'Tablet Ruang B',
        'app_version' => '1.2.3',
        // all of the following must be ignored
        'status' => DoctorDevice::STATUS_REVOKED,
        'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
        'public_key_fingerprint' => 'forged',
        'revoked_at' => now(),
    ], $f['admin']);

    expect($updated->device_name)->toBe('Tablet Ruang B')
        ->and($updated->app_version)->toBe('1.2.3')
        ->and($updated->status)->toBe(DoctorDevice::STATUS_ACTIVE)
        ->and($updated->identity_state)->toBe(DoctorDevice::IDENTITY_UNVERIFIED)
        ->and($updated->public_key_fingerprint)->toBeNull()
        ->and($updated->revoked_at)->toBeNull();
});

it('rejects moving a device to an ineligible branch', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);
    $bad = Branch::factory()->create(['is_active' => false, 'is_rme_enabled' => false]);

    deviceService()->updateMetadata($device, ['branch_id' => $bad->id], $f['admin']);
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Audit
// ---------------------------------------------------------------------------

it('audits registration', function () {
    $f = deviceFixture();

    $device = deviceService()->register(
        ['device_name' => 'Tablet Audit', 'branch_id' => $f['branch']->id],
        $f['admin']
    );

    expect(AuditLog::query()
        ->where('action', 'DOCTOR_DEVICE_CREATED')
        ->where('entity_id', $device->id)
        ->exists())->toBeTrue();
});

it('audits disable, reactivate and revoke with their reasons', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id]);

    deviceService()->disable($device, 'Servis layar', $f['admin']);
    deviceService()->reactivate($device->fresh(), $f['admin']);
    deviceService()->revoke($device->fresh(), 'Dijual', $f['admin']);

    foreach (['DOCTOR_DEVICE_DISABLED', 'DOCTOR_DEVICE_REACTIVATED', 'DOCTOR_DEVICE_REVOKED'] as $action) {
        expect(AuditLog::query()->where('action', $action)->where('entity_id', $device->id)->exists())
            ->toBeTrue();
    }

    $revoked = AuditLog::query()->where('action', 'DOCTOR_DEVICE_REVOKED')->latest('id')->first();
    expect(json_encode($revoked->new_values))->toContain('Dijual');
});

it('keeps secrets and PII out of the device audit payload', function () {
    $f = deviceFixture();
    $device = DoctorDevice::factory()->create([
        'branch_id' => $f['branch']->id,
        'public_key_fingerprint' => str_repeat('a', 64),
    ]);

    deviceService()->revoke($device, 'Hilang', $f['admin']);

    $log = AuditLog::query()->where('action', 'DOCTOR_DEVICE_REVOKED')->latest('id')->first();
    $payload = json_encode([$log->old_values, $log->new_values]);

    expect($payload)->not->toContain(str_repeat('a', 64))
        ->and(strtolower($payload))->not->toContain('password')
        ->and(strtolower($payload))->not->toContain('private_key');
});
