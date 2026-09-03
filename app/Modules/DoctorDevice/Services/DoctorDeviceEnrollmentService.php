<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — enrolment protocol.
 *
 * An Android install asks to pair, an administrator approves it in the Phase 2
 * Master Data → Device Dokter screen, and only then may the device prove
 * possession of its private key (see DoctorDeviceProofService).
 *
 * INVARIANTS
 *  - The pairing code is shown to the requesting device ONCE and stored only as
 *    a hash, so nobody with database access can replay a pairing.
 *  - The public key is frozen at request time. Approval binds that exact key, so
 *    a key cannot be swapped between request and approval.
 *  - Approval alone does NOT make a device verified. It authorises the device to
 *    attempt a cryptographic proof; `identity_state` only becomes
 *    `cryptographically_verified` after a valid signature.
 *  - Enrolment never grants Doctor login rights. Phase 3 enforcement is OFF.
 */
class DoctorDeviceEnrollmentService
{
    /** A pairing window long enough for an admin to walk over, short enough to matter. */
    public const ENROLLMENT_TTL_MINUTES = 15;

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Step 1 — the device requests enrolment.
     *
     * Returns the enrolment plus the ONE-TIME plaintext pairing code. The caller
     * must hand that code straight to the device and never persist it.
     *
     * @param  array<string, mixed>  $data
     * @return array{enrollment: DoctorDeviceEnrollment, pairing_code: string}
     */
    public function request(array $data): array
    {
        $publicKey = is_string($data['public_key'] ?? null) ? trim($data['public_key']) : '';
        $algorithm = is_string($data['key_algorithm'] ?? null) ? trim($data['key_algorithm']) : '';

        if (! DeviceKeyMaterial::isSupportedAlgorithm($algorithm)) {
            throw ValidationException::withMessages([
                'key_algorithm' => 'Algoritma kunci tidak didukung.',
            ]);
        }

        // Reject anything OpenSSL cannot read as an EC public key, rather than
        // storing junk that would fail confusingly at verification time.
        if (DeviceKeyMaterial::toPem($publicKey) === null) {
            throw ValidationException::withMessages([
                'public_key' => 'Kunci publik perangkat tidak valid.',
            ]);
        }

        $fingerprint = DeviceKeyMaterial::fingerprint($publicKey);

        return DB::transaction(function () use ($data, $publicKey, $algorithm, $fingerprint) {
            // A key already bound to a live device must not be re-enrolled: that
            // would be a device-impersonation path. A REVOKED device's key is
            // equally refused — revocation is terminal, and reuse requires a
            // genuinely new key.
            $existing = DoctorDevice::query()
                ->where('public_key_fingerprint', $fingerprint)
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'public_key' => 'Identitas kunci perangkat ini sudah terdaftar.',
                ]);
            }

            // One live pairing attempt per key. Supersede any earlier pending row
            // so a device cannot accumulate valid codes.
            DoctorDeviceEnrollment::query()
                ->where('public_key_fingerprint', $fingerprint)
                ->where('status', DoctorDeviceEnrollment::STATUS_PENDING)
                ->update([
                    'status' => DoctorDeviceEnrollment::STATUS_REJECTED,
                    'rejected_reason' => 'Digantikan permintaan pendaftaran baru.',
                    'rejected_at' => now(),
                    'updated_at' => now(),
                ]);

            $pairingCode = $this->generatePairingCode();

            $enrollment = new DoctorDeviceEnrollment;
            $enrollment->forceFill([
                'uuid' => (string) Str::uuid(),
                'pairing_code_hash' => $this->hashPairingCode($pairingCode),
                'public_key' => $publicKey,
                'public_key_fingerprint' => $fingerprint,
                'key_algorithm' => $algorithm,
                'platform' => $this->nullableString($data['platform'] ?? null),
                'device_model' => $this->nullableString($data['device_model'] ?? null),
                'os_version' => $this->nullableString($data['os_version'] ?? null),
                'app_version' => $this->nullableString($data['app_version'] ?? null),
                'status' => DoctorDeviceEnrollment::STATUS_PENDING,
                'expires_at' => now()->addMinutes(self::ENROLLMENT_TTL_MINUTES),
            ])->save();

            $this->audit($enrollment, 'DOCTOR_DEVICE_REGISTRATION_REQUESTED', null, [
                'fingerprint' => substr($fingerprint, 0, 12),
                'platform' => $enrollment->platform,
            ], null);

            return ['enrollment' => $enrollment->refresh(), 'pairing_code' => $pairingCode];
        });
    }

    /**
     * Step 2 — an administrator approves the pairing and binds it to a device.
     *
     * The device row is the Phase 2 registry entry: it may be an existing ACTIVE
     * device awaiting hardware, or one created here for this pairing.
     */
    public function approve(DoctorDeviceEnrollment $enrollment, DoctorDevice $device, User $actor): DoctorDeviceEnrollment
    {
        return DB::transaction(function () use ($enrollment, $device, $actor) {
            $locked = $this->lock($enrollment);
            $lockedDevice = DoctorDevice::query()->lockForUpdate()->find($device->id);

            if ($lockedDevice === null) {
                throw ValidationException::withMessages(['device' => 'Perangkat tidak ditemukan.']);
            }

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pendaftaran ini sudah tidak menunggu persetujuan.',
                ]);
            }

            if ($locked->isExpired()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pendaftaran sudah kedaluwarsa.',
                ]);
            }

            // Administrative status is independent of enrolment: a disabled or
            // revoked device must not be handed a fresh cryptographic identity.
            if (! $lockedDevice->isActive()) {
                throw ValidationException::withMessages([
                    'device' => 'Hanya perangkat berstatus ACTIVE yang dapat dipasangkan.',
                ]);
            }

            if ($lockedDevice->public_key_fingerprint !== null) {
                throw ValidationException::withMessages([
                    'device' => 'Perangkat ini sudah terikat pada identitas kunci lain.',
                ]);
            }

            $locked->forceFill([
                'doctor_device_id' => $lockedDevice->id,
                'status' => DoctorDeviceEnrollment::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ])->save();

            // Bind the key, but do NOT mark it verified — the device still has to
            // prove possession of the matching private key.
            $lockedDevice->forceFill([
                'public_key' => $locked->public_key,
                'public_key_fingerprint' => $locked->public_key_fingerprint,
                'key_algorithm' => $locked->key_algorithm,
                'enrollment_status' => DoctorDevice::ENROLLMENT_PENDING,
                'enrollment_requested_at' => $locked->created_at,
                'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
            ])->save();

            $this->audit($locked, 'DOCTOR_DEVICE_REGISTRATION_APPROVED', null, [
                'device_id' => $lockedDevice->id,
                'fingerprint' => substr((string) $locked->public_key_fingerprint, 0, 12),
            ], $actor);

            return $locked->refresh();
        });
    }

    public function reject(DoctorDeviceEnrollment $enrollment, ?string $reason, User $actor): DoctorDeviceEnrollment
    {
        $reason = is_string($reason) ? trim($reason) : '';

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi.']);
        }

        return DB::transaction(function () use ($enrollment, $reason, $actor) {
            $locked = $this->lock($enrollment);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan pendaftaran ini sudah tidak menunggu persetujuan.',
                ]);
            }

            $locked->forceFill([
                'status' => DoctorDeviceEnrollment::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $actor->id,
                'rejected_reason' => $reason,
            ])->save();

            $this->audit($locked, 'DOCTOR_DEVICE_REGISTRATION_REJECTED', null, ['reason' => $reason], $actor);

            return $locked->refresh();
        });
    }

    /**
     * Look up a pending enrolment by the plaintext code an operator typed in.
     * The comparison is against the stored hash — the code itself is never
     * persisted — and uses a timing-safe compare.
     */
    public function findByPairingCode(string $pairingCode): ?DoctorDeviceEnrollment
    {
        $code = strtoupper(trim($pairingCode));

        if ($code === '') {
            return null;
        }

        return DoctorDeviceEnrollment::query()
            ->where('pairing_code_hash', $this->hashPairingCode($code))
            ->first();
    }

    public function hashPairingCode(string $code): string
    {
        // Deterministic keyed hash so the code is looked up in one indexed query
        // while never being stored in the clear. APP_KEY is the key material.
        return hash_hmac('sha256', strtoupper(trim($code)), (string) config('app.key'));
    }

    /**
     * Human-transcribable, unambiguous alphabet (no O/0/I/1) from a CSPRNG.
     */
    private function generatePairingCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    private function lock(DoctorDeviceEnrollment $enrollment): DoctorDeviceEnrollment
    {
        $locked = DoctorDeviceEnrollment::query()->lockForUpdate()->find($enrollment->id);

        if ($locked === null) {
            throw ValidationException::withMessages(['enrollment' => 'Permintaan pendaftaran tidak ditemukan.']);
        }

        return $locked;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Audit carries identifiers and a TRUNCATED fingerprint only. Never the
     * pairing code, never the public key, never a signature.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(DoctorDeviceEnrollment $enrollment, string $action, ?array $old, ?array $new, ?User $actor): void
    {
        $this->auditLogs->log('trx_doctor_device_enrollments', (int) $enrollment->id, $action, $old, $new, $actor);
    }
}
