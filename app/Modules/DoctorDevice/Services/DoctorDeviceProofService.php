<?php

namespace App\Modules\DoctorDevice\Services;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceChallenge;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — challenge/response.
 *
 * The server issues a random nonce; the device signs it with the private key
 * that never leaves its Android Keystore; the server verifies the signature
 * against the enrolled public key.
 *
 * WHAT A SUCCESSFUL PROOF MEANS
 *   "This request came from registered clinic hardware."
 * It does NOT mean a Doctor is authenticated. Device identity and user session
 * are deliberately separate concerns — merging them is Phase 4/5 work, and
 * Phase 3 enforcement is OFF.
 *
 * FAIL-CLOSED MATRIX — a proof succeeds only when ALL hold:
 *   device is registered            AND
 *   a public key is bound           AND
 *   the challenge is for THAT device AND
 *   the challenge is unexpired and unconsumed AND
 *   the signature verifies          AND
 *   status === ACTIVE (not disabled, not revoked)
 * Anything else denies.
 */
class DoctorDeviceProofService
{
    public const CHALLENGE_TTL_SECONDS = 120;

    /** 32 random bytes, hex encoded. */
    private const NONCE_BYTES = 32;

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Issue a challenge for an enrolled device.
     *
     * Administrative status is checked HERE too, so a disabled or revoked device
     * cannot even obtain a nonce — not merely fail at the end.
     */
    public function issueChallenge(DoctorDevice $device, string $purpose = DeviceProofMessage::PURPOSE_DEVICE_PROOF): DoctorDeviceChallenge
    {
        if ($device->public_key === null || $device->public_key_fingerprint === null) {
            throw ValidationException::withMessages([
                'device' => 'Perangkat belum memiliki identitas kunci terdaftar.',
            ]);
        }

        $this->assertAdministrativelyUsable($device);

        $challenge = new DoctorDeviceChallenge;
        $challenge->forceFill([
            'uuid' => (string) Str::uuid(),
            'doctor_device_id' => $device->id,
            'nonce' => bin2hex(random_bytes(self::NONCE_BYTES)),
            'purpose' => $purpose,
            'expires_at' => now()->addSeconds(self::CHALLENGE_TTL_SECONDS),
        ])->save();

        return $challenge->refresh();
    }

    /**
     * Verify a signature over a challenge.
     *
     * TWO PHASES, AND THE SPLIT IS THE POINT.
     *
     * Phase A claims the nonce in its OWN committed transaction. Phase B then
     * verifies outside it. Doing the burn and the verification in one
     * transaction looks tidier but is a real vulnerability: a denial throws, the
     * transaction rolls back, the burn is undone, and the attacker may retry
     * signatures against the same fixed nonce forever. A test pinned exactly
     * that regression — keep these phases separate.
     *
     * `lockForUpdate` inside phase A also means two concurrent submissions of
     * one challenge cannot both claim it.
     */
    public function verifyProof(string $nonce, string $signatureBase64): DoctorDevice
    {
        /** @var array{challenge: DoctorDeviceChallenge, claimed: bool}|null $claim */
        $claim = DB::transaction(function () use ($nonce) {
            $challenge = DoctorDeviceChallenge::query()
                ->lockForUpdate()
                ->where('nonce', $nonce)
                ->first();

            if ($challenge === null) {
                return null;
            }

            if (! $challenge->isUsable()) {
                return ['challenge' => $challenge, 'claimed' => false];
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            return ['challenge' => $challenge, 'claimed' => true];
        });

        if ($claim === null) {
            $this->deny(null, 'unknown_challenge');
        }

        /** @var DoctorDeviceChallenge $challenge */
        $challenge = $claim['challenge'];

        if ($claim['claimed'] !== true) {
            // Replay of an already-consumed challenge, or an expired one.
            $this->deny($challenge->device, $challenge->isConsumed() ? 'challenge_replayed' : 'challenge_expired');
        }

        // ---- Phase B: the nonce is already burned; every path below denies or
        // succeeds, and none of them can give the attempt back.
        $device = DoctorDevice::query()->find($challenge->doctor_device_id);

        if ($device === null || $device->public_key === null) {
            $this->deny($device, 'device_missing_key');
        }

        $pem = DeviceKeyMaterial::toPem((string) $device->public_key);

        if ($pem === null) {
            $this->deny($device, 'unreadable_public_key');
        }

        $message = DeviceProofMessage::build(
            (string) $challenge->purpose,
            (string) $challenge->nonce,
            (string) $device->public_key_fingerprint,
        );

        $signature = base64_decode(preg_replace('/\s+/', '', $signatureBase64) ?? '', true);

        if ($signature === false || $signature === '') {
            $this->deny($device, 'malformed_signature');
        }

        // Verification is delegated to OpenSSL; nothing here is hand-rolled.
        // openssl_verify returns 1 valid, 0 invalid, -1 error — only 1 passes.
        if (openssl_verify($message, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            $this->deny($device, 'signature_invalid');
        }

        // Administrative status is re-asserted AFTER cryptography: proving
        // possession of a key never overrides a disable or a revocation.
        $this->assertAdministrativelyUsable($device);

        return DB::transaction(function () use ($device, $challenge) {
            $locked = DoctorDevice::query()->lockForUpdate()->find($device->id);

            // Re-check under the lock: the device may have been revoked between
            // the signature check and this write.
            $this->assertAdministrativelyUsable($locked);

            $locked->forceFill([
                'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
                'enrollment_status' => DoctorDevice::ENROLLMENT_VERIFIED,
                'last_verified_at' => now(),
                'verified_at' => $locked->verified_at ?? now(),
                'last_seen_at' => now(),
            ])->save();

            DoctorDeviceEnrollment::query()
                ->where('doctor_device_id', $locked->id)
                ->where('status', DoctorDeviceEnrollment::STATUS_APPROVED)
                ->update([
                    'status' => DoctorDeviceEnrollment::STATUS_CONSUMED,
                    'consumed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->auditLogs->log('mst_doctor_devices', (int) $locked->id, 'DOCTOR_DEVICE_PROOF_VERIFIED', null, [
                'purpose' => $challenge->purpose,
            ], null);

            return $locked->refresh();
        });
    }

    /**
     * Would a proof be accepted right now? Used by the device status endpoint so
     * the Android client can render blocked/revoked screens without guessing.
     */
    public function isTrustworthy(DoctorDevice $device): bool
    {
        return $device->isActive()
            && $device->isCryptographicallyVerified()
            && $device->public_key !== null;
    }

    private function assertAdministrativelyUsable(DoctorDevice $device): void
    {
        if ($device->isRevoked()) {
            $this->deny($device, 'device_revoked');
        }

        if (! $device->isActive()) {
            $this->deny($device, 'device_disabled');
        }
    }

    private function consume(DoctorDeviceChallenge $challenge): void
    {
        $challenge->forceFill(['consumed_at' => now()])->save();
    }

    /**
     * Every denial is the same opaque error to the client — the reason is only
     * ever recorded in the audit trail, so the endpoint cannot be used to probe
     * which devices exist or what state they are in.
     */
    private function deny(?DoctorDevice $device, string $reason): never
    {
        if ($device !== null) {
            $this->auditLogs->log('mst_doctor_devices', (int) $device->id, 'DOCTOR_DEVICE_PROOF_REJECTED', null, [
                'reason' => $reason,
            ], null);
        }

        throw ValidationException::withMessages([
            'proof' => 'Verifikasi perangkat gagal.',
        ]);
    }
}
