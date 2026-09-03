<?php

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 — Phase 3.
 *
 * The cryptographic enrolment protocol and challenge/response, exercised with
 * REAL EC P-256 keys and real signatures — never a stubbed verifier, because a
 * stub would prove nothing about the thing that actually guards the door.
 *
 * A successful proof means only "this is registered clinic hardware". It does
 * not authenticate a Doctor; Phase 3 enforcement is OFF and
 * DoctorDeviceNoEnforcementTest pins that separately.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceChallenge;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Services\DoctorDeviceEnrollmentService;
use App\Modules\DoctorDevice\Services\DoctorDeviceProofService;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use App\Modules\LabOrder\Models\AuditLog;
use Database\Factories\DoctorDeviceEnrollmentFactory;
use Illuminate\Validation\ValidationException;

function enrollSvc(): DoctorDeviceEnrollmentService
{
    return app(DoctorDeviceEnrollmentService::class);
}

function proofSvc(): DoctorDeviceProofService
{
    return app(DoctorDeviceProofService::class);
}

/** A Super Admin, an RME branch, and a fresh real EC keypair. */
function enrollFixture(): array
{
    seedAccessControl();

    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    [$publicKey, $privateKey] = DoctorDeviceEnrollmentFactory::generateKeyPair();

    return [
        'admin' => superAdmin(),
        'branch' => $branch,
        'publicKey' => $publicKey,
        'privateKey' => $privateKey,
    ];
}

/** Sign the canonical message exactly as the Android client will. */
function signProof(string $purpose, string $nonce, string $fingerprint, $privateKey): string
{
    $message = DeviceProofMessage::build($purpose, $nonce, $fingerprint);
    $signature = '';
    openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return base64_encode($signature);
}

/** Request → approve → the device is bound but NOT yet verified. */
function approvedDevice(array $f): array
{
    ['enrollment' => $enrollment] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
        'platform' => 'android',
    ]);

    $device = DoctorDevice::factory()->create([
        'branch_id' => $f['branch']->id,
        'public_key_fingerprint' => null,
    ]);

    enrollSvc()->approve($enrollment, $device, $f['admin']);

    return ['enrollment' => $enrollment->refresh(), 'device' => $device->refresh()];
}

// ---------------------------------------------------------------------------
// Enrollment request
// ---------------------------------------------------------------------------

it('creates a pending enrollment with a hashed single-use pairing code', function () {
    $f = enrollFixture();

    ['enrollment' => $e, 'pairing_code' => $code] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);

    expect($e->status)->toBe(DoctorDeviceEnrollment::STATUS_PENDING)
        ->and($e->expires_at)->not->toBeNull()
        ->and($code)->toHaveLength(8);

    // The raw code must never be persisted anywhere on the row.
    $row = json_encode($e->fresh()->toArray());
    expect($row)->not->toContain($code)
        ->and($e->pairing_code_hash)->not->toBe($code);
});

it('rejects an unsupported key algorithm', function () {
    $f = enrollFixture();

    enrollSvc()->request(['public_key' => $f['publicKey'], 'key_algorithm' => 'RSA_1024_MD5']);
})->throws(ValidationException::class);

it('rejects a malformed public key', function () {
    enrollFixture();

    enrollSvc()->request([
        'public_key' => 'not-a-real-key',
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);
})->throws(ValidationException::class);

it('refuses to enroll a key already bound to a registered device', function () {
    $f = enrollFixture();
    approvedDevice($f);

    // Same key, second attempt — this is the device-impersonation path.
    enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);
})->throws(ValidationException::class);

it('supersedes an earlier pending enrollment for the same key', function () {
    $f = enrollFixture();

    ['enrollment' => $first] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);
    enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);

    expect($first->fresh()->status)->toBe(DoctorDeviceEnrollment::STATUS_REJECTED);
});

// ---------------------------------------------------------------------------
// Approval
// ---------------------------------------------------------------------------

it('binds the key on approval but does not mark the device verified', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    expect($device->public_key_fingerprint)->toBe(DeviceKeyMaterial::fingerprint($f['publicKey']))
        ->and($device->enrollment_status)->toBe(DoctorDevice::ENROLLMENT_PENDING)
        // Approval authorises an attempt; it is NOT proof.
        ->and($device->identity_state)->toBe(DoctorDevice::IDENTITY_UNVERIFIED);
});

it('refuses to approve an expired enrollment', function () {
    $f = enrollFixture();
    ['enrollment' => $e] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);
    $e->forceFill(['expires_at' => now()->subMinute()])->save();

    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id, 'public_key_fingerprint' => null]);
    enrollSvc()->approve($e->fresh(), $device, $f['admin']);
})->throws(ValidationException::class);

it('refuses to approve onto a disabled or revoked device', function () {
    $f = enrollFixture();
    ['enrollment' => $e] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);
    $revoked = DoctorDevice::factory()->revoked()->create([
        'branch_id' => $f['branch']->id, 'public_key_fingerprint' => null,
    ]);

    enrollSvc()->approve($e, $revoked, $f['admin']);
})->throws(ValidationException::class);

it('rejects an enrollment with a reason', function () {
    $f = enrollFixture();
    ['enrollment' => $e] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);

    $rejected = enrollSvc()->reject($e, 'Perangkat tidak dikenali', $f['admin']);

    expect($rejected->status)->toBe(DoctorDeviceEnrollment::STATUS_REJECTED)
        ->and($rejected->rejected_reason)->toBe('Perangkat tidak dikenali');
});

it('requires a reason to reject an enrollment', function () {
    $f = enrollFixture();
    ['enrollment' => $e] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);

    enrollSvc()->reject($e, '  ', $f['admin']);
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Challenge / response — the heart of the phase
// ---------------------------------------------------------------------------

it('verifies a valid signature and marks the device cryptographically verified', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $f['privateKey']);

    $verified = proofSvc()->verifyProof($challenge->nonce, $sig);

    expect($verified->identity_state)->toBe(DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED)
        ->and($verified->enrollment_status)->toBe(DoctorDevice::ENROLLMENT_VERIFIED)
        ->and($verified->last_verified_at)->not->toBeNull()
        ->and($challenge->fresh()->consumed_at)->not->toBeNull();
});

it('denies a signature made with the wrong key', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    [, $attackerKey] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    $challenge = proofSvc()->issueChallenge($device);
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $attackerKey);

    proofSvc()->verifyProof($challenge->nonce, $sig);
})->throws(ValidationException::class);

it('denies a signature over an altered message', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);
    // Signed with the right key, but over a different nonce than the server holds.
    $sig = signProof($challenge->purpose, 'tampered-nonce', $device->public_key_fingerprint, $f['privateKey']);

    proofSvc()->verifyProof($challenge->nonce, $sig);
})->throws(ValidationException::class);

it('denies an expired challenge', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);
    $challenge->forceFill(['expires_at' => now()->subSecond()])->save();
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $f['privateKey']);

    proofSvc()->verifyProof($challenge->nonce, $sig);
})->throws(ValidationException::class);

it('denies replay of a consumed challenge', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $f['privateKey']);

    proofSvc()->verifyProof($challenge->nonce, $sig);

    // Exactly the same valid signature, replayed.
    proofSvc()->verifyProof($challenge->nonce, $sig);
})->throws(ValidationException::class);

it('burns the nonce even when the proof fails, so it cannot be retried', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);

    try {
        proofSvc()->verifyProof($challenge->nonce, base64_encode('garbage'));
    } catch (ValidationException) {
        // expected
    }

    expect($challenge->fresh()->consumed_at)->not->toBeNull();
});

it('denies a challenge issued for another device', function () {
    $f = enrollFixture();
    ['device' => $deviceA] = approvedDevice($f);

    // A second, independently enrolled device.
    [$pubB, $privB] = DoctorDeviceEnrollmentFactory::generateKeyPair();
    ['enrollment' => $eB] = enrollSvc()->request([
        'public_key' => $pubB,
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);
    $deviceB = DoctorDevice::factory()->create([
        'branch_id' => $f['branch']->id, 'public_key_fingerprint' => null,
    ]);
    enrollSvc()->approve($eB, $deviceB, $f['admin']);

    // B signs A's challenge — the message binds A's fingerprint, so it cannot match.
    $challengeA = proofSvc()->issueChallenge($deviceA);
    $sig = signProof($challengeA->purpose, $challengeA->nonce, $deviceB->fresh()->public_key_fingerprint, $privB);

    proofSvc()->verifyProof($challengeA->nonce, $sig);
})->throws(ValidationException::class);

it('denies an unknown challenge nonce', function () {
    enrollFixture();

    proofSvc()->verifyProof('nonce-that-was-never-issued', base64_encode('x'));
})->throws(ValidationException::class);

// ---------------------------------------------------------------------------
// Administrative status always outranks cryptography
// ---------------------------------------------------------------------------

it('refuses to issue a challenge to a disabled device', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);
    $device->forceFill(['status' => DoctorDevice::STATUS_DISABLED])->save();

    proofSvc()->issueChallenge($device->fresh());
})->throws(ValidationException::class);

it('refuses to issue a challenge to a revoked device', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);
    $device->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    proofSvc()->issueChallenge($device->fresh());
})->throws(ValidationException::class);

it('denies a proof when the device is disabled after the challenge was issued', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $f['privateKey']);

    // Disabled in the window between issue and proof: cryptography must not win.
    $device->forceFill(['status' => DoctorDevice::STATUS_DISABLED])->save();

    proofSvc()->verifyProof($challenge->nonce, $sig);
})->throws(ValidationException::class);

it('denies a proof when the device is revoked after the challenge was issued', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $f['privateKey']);

    $device->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    proofSvc()->verifyProof($challenge->nonce, $sig);
})->throws(ValidationException::class);

it('audits a revoked denial distinctly from a disabled one', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    // Both are refused, but the RECORDED REASON must differ: an incident
    // responder reading the trail has to tell "temporarily disabled" apart from
    // "trust permanently withdrawn". A revoked device is also not active, so
    // without this the revoked branch would be indistinguishable.
    $device->forceFill(['status' => DoctorDevice::STATUS_REVOKED])->save();

    try {
        proofSvc()->issueChallenge($device->fresh());
    } catch (ValidationException) {
        // expected
    }

    $log = AuditLog::query()->where('action', 'DOCTOR_DEVICE_PROOF_REJECTED')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and(json_encode($log->new_values))->toContain('device_revoked')
        ->and(json_encode($log->new_values))->not->toContain('device_disabled');
});

it('reports trustworthiness only for an active verified device', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    expect(proofSvc()->isTrustworthy($device))->toBeFalse();

    $challenge = proofSvc()->issueChallenge($device);
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $f['privateKey']);
    $verified = proofSvc()->verifyProof($challenge->nonce, $sig);

    expect(proofSvc()->isTrustworthy($verified))->toBeTrue();

    $verified->forceFill(['status' => DoctorDevice::STATUS_DISABLED])->save();
    expect(proofSvc()->isTrustworthy($verified->fresh()))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Audit — and what must never appear in it
// ---------------------------------------------------------------------------

it('audits the enrollment lifecycle and proof outcomes', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $challenge = proofSvc()->issueChallenge($device);
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->public_key_fingerprint, $f['privateKey']);
    proofSvc()->verifyProof($challenge->nonce, $sig);

    foreach (['DOCTOR_DEVICE_REGISTRATION_REQUESTED', 'DOCTOR_DEVICE_REGISTRATION_APPROVED', 'DOCTOR_DEVICE_PROOF_VERIFIED'] as $action) {
        expect(AuditLog::query()->where('action', $action)->exists())->toBeTrue();
    }
});

it('never writes key material, signatures or pairing codes into the audit trail', function () {
    $f = enrollFixture();

    ['enrollment' => $e, 'pairing_code' => $code] = enrollSvc()->request([
        'public_key' => $f['publicKey'],
        'key_algorithm' => DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
    ]);
    $device = DoctorDevice::factory()->create(['branch_id' => $f['branch']->id, 'public_key_fingerprint' => null]);
    enrollSvc()->approve($e, $device, $f['admin']);

    $challenge = proofSvc()->issueChallenge($device->fresh());
    $sig = signProof($challenge->purpose, $challenge->nonce, $device->fresh()->public_key_fingerprint, $f['privateKey']);
    proofSvc()->verifyProof($challenge->nonce, $sig);

    $payload = AuditLog::query()->get()->map(fn ($l) => json_encode([$l->old_values, $l->new_values]))->implode(' ');

    expect($payload)->not->toContain($code)
        ->and($payload)->not->toContain($f['publicKey'])
        ->and($payload)->not->toContain($sig)
        ->and($payload)->not->toContain($challenge->nonce);
});

it('issues a high-entropy nonce that is never reused', function () {
    $f = enrollFixture();
    ['device' => $device] = approvedDevice($f);

    $nonces = [];
    for ($i = 0; $i < 5; $i++) {
        $c = proofSvc()->issueChallenge($device);
        expect(strlen($c->nonce))->toBe(64); // 32 random bytes, hex
        $nonces[] = $c->nonce;
    }

    expect(array_unique($nonces))->toHaveCount(5);
    expect(DoctorDeviceChallenge::query()->count())->toBe(5);
});
