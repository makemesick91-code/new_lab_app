<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 2 — the ONLY authority for
 * the clinic device registry.
 *
 * Three rules give this class its shape:
 *
 *  1. STATUS IS NEVER A PAYLOAD. `status`, `identity_state`, the revoked/
 *     disabled stamps and `public_key_fingerprint` are all outside the model's
 *     `$fillable`, and every write here passes an explicit allow-list. A
 *     request can therefore never drive a lifecycle transition by mass
 *     assignment, however it is crafted.
 *
 *  2. REVOKED IS TERMINAL. Trust, once withdrawn, is not handed back by an
 *     admin click. Reusing the hardware means a fresh cryptographic identity
 *     in a later phase, not resurrecting the old row. History is preserved —
 *     there is no delete path at all.
 *
 *  3. A TYPED ROW IS NOT A PROVEN DEVICE. Phase 2 has no key material, so
 *     `identity_state` can only ever be `unverified` here. Only a real Android
 *     enrolment answering a server challenge (Phase 3) may set
 *     `cryptographically_verified`.
 *
 * ENFORCEMENT IS OFF. Nothing in this module is consulted by authentication,
 * session handling or any middleware, so an empty registry cannot lock a
 * doctor out.
 */
class DoctorDeviceService
{
    /** Metadata an administrator may edit. Deliberately excludes every lifecycle column. */
    private const EDITABLE_METADATA = [
        'device_name',
        'branch_id',
        'platform',
        'device_model',
        'os_version',
        'app_version',
        'notes',
    ];

    public function __construct(
        private readonly DoctorDeviceRepositoryInterface $devices,
        private readonly BranchService $branches,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DoctorDevice>
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->devices->paginate($filters, $perPage);
    }

    /**
     * Register a clinic device. Always ACTIVE + `unverified`: a hand-entered
     * row is a database record, never a proven device.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, User $actor): DoctorDevice
    {
        $branchId = $this->assertEligibleBranch($data['branch_id'] ?? null);
        $deviceName = $this->assertDeviceName($data['device_name'] ?? null);

        return DB::transaction(function () use ($branchId, $deviceName, $data, $actor) {
            if ($this->devices->existsWithNameInBranch($branchId, $deviceName)) {
                throw ValidationException::withMessages([
                    'device_name' => 'Nama perangkat sudah dipakai di cabang ini.',
                ]);
            }

            $device = $this->devices->create([
                'uuid' => (string) Str::uuid(),
                'device_name' => $deviceName,
                'branch_id' => $branchId,
                'platform' => $this->nullableString($data['platform'] ?? null),
                'device_model' => $this->nullableString($data['device_model'] ?? null),
                'os_version' => $this->nullableString($data['os_version'] ?? null),
                'app_version' => $this->nullableString($data['app_version'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
            ]);

            // Lifecycle columns are set here, never from the payload.
            $device = $this->devices->update($device, [
                'status' => DoctorDevice::STATUS_ACTIVE,
                'identity_state' => DoctorDevice::IDENTITY_UNVERIFIED,
                'public_key_fingerprint' => null,
                'registered_at' => now(),
                'registered_by' => $actor->id,
            ]);

            $this->audit($device, 'DOCTOR_DEVICE_CREATED', null, [
                'status' => $device->status,
                'identity_state' => $device->identity_state,
                'branch_id' => $device->branch_id,
            ], $actor);

            return $device;
        });
    }

    /**
     * Update safe metadata only. Any lifecycle key in the payload is ignored.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateMetadata(DoctorDevice $device, array $data, User $actor): DoctorDevice
    {
        $payload = array_intersect_key($data, array_flip(self::EDITABLE_METADATA));

        return DB::transaction(function () use ($device, $payload, $actor) {
            $locked = $this->lock($device);

            $changes = [];

            if (array_key_exists('branch_id', $payload)) {
                $changes['branch_id'] = $this->assertEligibleBranch($payload['branch_id']);
            }

            if (array_key_exists('device_name', $payload)) {
                $changes['device_name'] = $this->assertDeviceName($payload['device_name']);
            }

            foreach (['platform', 'device_model', 'os_version', 'app_version', 'notes'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $changes[$key] = $this->nullableString($payload[$key]);
                }
            }

            $targetBranch = $changes['branch_id'] ?? (int) $locked->branch_id;
            $targetName = $changes['device_name'] ?? (string) $locked->device_name;

            if ($this->devices->existsWithNameInBranch($targetBranch, $targetName, (int) $locked->id)) {
                throw ValidationException::withMessages([
                    'device_name' => 'Nama perangkat sudah dipakai di cabang ini.',
                ]);
            }

            $before = ['device_name' => $locked->device_name, 'branch_id' => $locked->branch_id];
            $updated = $changes === [] ? $locked : $this->devices->update($locked, $changes);

            $this->audit($updated, 'DOCTOR_DEVICE_UPDATED', $before, [
                'device_name' => $updated->device_name,
                'branch_id' => $updated->branch_id,
            ], $actor);

            return $updated;
        });
    }

    public function disable(DoctorDevice $device, ?string $reason, User $actor): DoctorDevice
    {
        $reason = $this->assertReason($reason, 'Alasan penonaktifan wajib diisi.');

        return DB::transaction(function () use ($device, $reason, $actor) {
            $locked = $this->lock($device);
            $this->assertNotRevoked($locked);

            if ($locked->isDisabled()) {
                return $locked;
            }

            $updated = $this->devices->update($locked, [
                'status' => DoctorDevice::STATUS_DISABLED,
                'disabled_at' => now(),
                'disabled_by' => $actor->id,
                'disabled_reason' => $reason,
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_DISABLED',
                ['status' => DoctorDevice::STATUS_ACTIVE],
                ['status' => $updated->status, 'reason' => $reason],
                $actor);

            return $updated;
        });
    }

    public function reactivate(DoctorDevice $device, User $actor): DoctorDevice
    {
        return DB::transaction(function () use ($device, $actor) {
            $locked = $this->lock($device);

            // The whole point of REVOKED: an administrator cannot hand trust
            // back to an identity that was withdrawn.
            if ($locked->isRevoked()) {
                throw ValidationException::withMessages([
                    'status' => 'Perangkat yang sudah dicabut (revoked) tidak dapat diaktifkan kembali. Daftarkan identitas perangkat baru.',
                ]);
            }

            if ($locked->isActive()) {
                return $locked;
            }

            $updated = $this->devices->update($locked, [
                'status' => DoctorDevice::STATUS_ACTIVE,
                'disabled_at' => null,
                'disabled_by' => null,
                'disabled_reason' => null,
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_REACTIVATED',
                ['status' => DoctorDevice::STATUS_DISABLED],
                ['status' => $updated->status],
                $actor);

            return $updated;
        });
    }

    public function revoke(DoctorDevice $device, ?string $reason, User $actor): DoctorDevice
    {
        $reason = $this->assertReason($reason, 'Alasan pencabutan wajib diisi.');

        return DB::transaction(function () use ($device, $reason, $actor) {
            $locked = $this->lock($device);

            if ($locked->isRevoked()) {
                return $locked;
            }

            $previous = $locked->status;

            $updated = $this->devices->update($locked, [
                'status' => DoctorDevice::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoked_reason' => $reason,
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_REVOKED',
                ['status' => $previous],
                ['status' => $updated->status, 'reason' => $reason],
                $actor);

            return $updated;
        });
    }

    // -----------------------------------------------------------------------

    private function lock(DoctorDevice $device): DoctorDevice
    {
        $locked = $this->devices->findForUpdate((int) $device->id);

        if ($locked === null) {
            throw ValidationException::withMessages([
                'device' => 'Perangkat tidak ditemukan.',
            ]);
        }

        return $locked;
    }

    private function assertNotRevoked(DoctorDevice $device): void
    {
        if ($device->isRevoked()) {
            throw ValidationException::withMessages([
                'status' => 'Perangkat yang sudah dicabut (revoked) tidak dapat diubah statusnya.',
            ]);
        }
    }

    /**
     * The branch is server-authoritative: it must be one of the active,
     * RME-enabled branches. A request may only ever SELECT from that set.
     */
    private function assertEligibleBranch(mixed $branchId): int
    {
        $branchId = is_numeric($branchId) ? (int) $branchId : 0;

        if ($branchId <= 0 || ! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak valid atau bukan Cabang RME aktif.',
            ]);
        }

        return $branchId;
    }

    private function assertDeviceName(mixed $name): string
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            throw ValidationException::withMessages([
                'device_name' => 'Nama perangkat wajib diisi.',
            ]);
        }

        return $name;
    }

    private function assertReason(?string $reason, string $message): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => $message]);
        }

        return $reason;
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
     * Audit payload is identifiers, status and the operator's reason only —
     * never the key fingerprint, never a secret, never patient data.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(DoctorDevice $device, string $action, ?array $old, ?array $new, User $actor): void
    {
        $this->auditLogs->log('mst_doctor_devices', (int) $device->id, $action, $old, $new, $actor);
    }
}
