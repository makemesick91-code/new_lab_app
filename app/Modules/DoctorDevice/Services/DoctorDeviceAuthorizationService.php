<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceAuthorizationRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the doctor/device
 * authorization lifecycle.
 *
 *      (none) ──► PENDING ──► ACTIVE ──► REVOKED   (terminal)
 *                    └──────► REJECTED ──┐
 *                                        │ allow re-request (privileged)
 *                             PENDING ◄──┘
 *
 * FOUR RULES THIS FILE EXISTS TO ENFORCE
 *
 *  1. Automatic request creation is IDEMPOTENT. Ten login taps produce one
 *     PENDING row. The guarantee is the unique index on (doctor_id,
 *     doctor_device_id) plus a caught constraint violation — not a read-then-
 *     write check, which two concurrent requests would both pass.
 *
 *  2. REJECTED never reopens itself. A rejected pair reports rejected and
 *     writes nothing; only `allowReRequest` — a privileged act — makes the pair
 *     requestable again, and even then the doctor still has to attempt a login.
 *
 *  3. REVOKED is terminal for ordinary actions. Withdrawn trust is not handed
 *     back by a login attempt or by an approve button.
 *
 *  4. Approval is a SINGLE operator decision that covers both halves. The
 *     device is promoted `pending_approval → active` in the same transaction as
 *     the authorization becoming ACTIVE, so an approver is never asked to make
 *     the same judgement twice — and a stale screen cannot approve a device
 *     that has since been revoked, because everything is re-read under a lock.
 */
class DoctorDeviceAuthorizationService
{
    public function __construct(
        private readonly DoctorDeviceAuthorizationRepositoryInterface $authorizations,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Resolve the authorization for a (doctor, device) pair, creating a PENDING
     * one when the pair is genuinely new.
     *
     * The caller is responsible for having already proved the device's key and
     * validated the doctor's credentials — this method assumes neither and
     * grants nothing.
     */
    public function resolveOrRequest(
        Doctor $doctor,
        DoctorDevice $device,
        ?User $requestedBy = null,
        string $source = DoctorDeviceAuthorization::SOURCE_APP_LOGIN,
    ): DoctorDeviceAuthorization {
        $existing = $this->authorizations->findPair((int) $doctor->id, (int) $device->id);

        if ($existing !== null) {
            return $this->reopenIfPermitted($existing);
        }

        try {
            return DB::transaction(function () use ($doctor, $device, $requestedBy, $source) {
                // Re-read under the lock: another concurrent login may have
                // created the row between the check above and here.
                $locked = $this->authorizations->findPairForUpdate((int) $doctor->id, (int) $device->id);

                if ($locked !== null) {
                    return $locked;
                }

                $created = $this->authorizations->create([
                    'uuid' => (string) Str::uuid(),
                    'doctor_id' => $doctor->id,
                    'doctor_device_id' => $device->id,
                    'status' => DoctorDeviceAuthorization::STATUS_PENDING,
                    'request_source' => in_array($source, DoctorDeviceAuthorization::SOURCES, true)
                        ? $source
                        : DoctorDeviceAuthorization::SOURCE_APP_LOGIN,
                    'requested_at' => now(),
                    'requested_by' => $requestedBy?->id,
                ]);

                $this->audit($created, 'DOCTOR_DEVICE_AUTHORIZATION_PENDING', null, [
                    'doctor_id' => $created->doctor_id,
                    'doctor_device_id' => $created->doctor_device_id,
                    'request_source' => $created->request_source,
                ], $requestedBy);

                return $created;
            });
        } catch (QueryException $exception) {
            // The unique index fired: two requests raced past the lock on a
            // driver that does not gap-lock a non-existent row. The row that
            // won is the canonical one, and that is the correct answer here.
            $winner = $this->authorizations->findPair((int) $doctor->id, (int) $device->id);

            if ($winner === null) {
                throw $exception;
            }

            return $this->reopenIfPermitted($winner);
        }
    }

    /**
     * A rejected pair becomes PENDING again only when a privileged approver has
     * allowed it (see DoctorDeviceAuthorization::isReRequestAllowed). Anything
     * else is returned untouched — in particular REJECTED without an allowance
     * and REVOKED, which is terminal.
     */
    private function reopenIfPermitted(DoctorDeviceAuthorization $authorization): DoctorDeviceAuthorization
    {
        if (! $authorization->isReRequestAllowed()) {
            return $authorization;
        }

        return DB::transaction(function () use ($authorization) {
            $locked = $this->lock($authorization);

            // Re-check under the lock: the allowance may have been spent by a
            // concurrent attempt, or the pair rejected again.
            if (! $locked->isReRequestAllowed()) {
                return $locked;
            }

            $updated = $this->authorizations->update($locked, [
                'status' => DoctorDeviceAuthorization::STATUS_PENDING,
                'requested_at' => now(),
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_AUTHORIZATION_PENDING',
                ['status' => DoctorDeviceAuthorization::STATUS_REJECTED],
                ['status' => $updated->status, 'after_re_request_allowance' => true],
                null);

            return $updated;
        });
    }

    /**
     * Approve — the single operator decision.
     *
     * Everything is re-read under a lock and re-validated, so a screen that was
     * rendered minutes ago cannot approve a doctor who has since been
     * deactivated or a device that has since been revoked. That is the
     * difference between an approval and a rubber stamp.
     */
    public function approve(DoctorDeviceAuthorization $authorization, User $actor): DoctorDeviceAuthorization
    {
        return DB::transaction(function () use ($authorization, $actor) {
            $locked = $this->lock($authorization);

            if ($locked->isActive()) {
                return $locked;
            }

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => $locked->isRevoked()
                        ? 'Otorisasi yang sudah dicabut tidak dapat disetujui. Diperlukan permintaan baru.'
                        : 'Permintaan ini sudah tidak menunggu persetujuan.',
                ]);
            }

            $device = DoctorDevice::query()->lockForUpdate()->find($locked->doctor_device_id);
            $doctor = Doctor::query()->find($locked->doctor_id);

            if ($device === null || $doctor === null) {
                throw ValidationException::withMessages([
                    'authorization' => 'Dokter atau perangkat tidak ditemukan.',
                ]);
            }

            if ($doctor->is_active !== true) {
                throw ValidationException::withMessages([
                    'doctor' => 'Dokter ini tidak aktif.',
                ]);
            }

            if ($device->isRevoked()) {
                throw ValidationException::withMessages([
                    'device' => 'Perangkat sudah dicabut permanen (revoked).',
                ]);
            }

            if ($device->isDisabled()) {
                throw ValidationException::withMessages([
                    'device' => 'Perangkat sedang dinonaktifkan. Aktifkan perangkat sebelum menyetujui akses dokter.',
                ]);
            }

            if (! $device->isCryptographicallyVerified()) {
                throw ValidationException::withMessages([
                    'device' => 'Perangkat belum membuktikan identitas kunci kriptografinya.',
                ]);
            }

            if ($device->branch_id === null) {
                throw ValidationException::withMessages([
                    'device' => 'Perangkat belum terikat pada cabang.',
                ]);
            }

            // The second half of the single decision: admit the hardware. Only
            // ever from `pending_approval` — an ACTIVE device is left alone, and
            // disabled/revoked were already refused above, so this can never
            // resurrect withdrawn trust.
            if ($device->isPendingApproval()) {
                $device->forceFill(['status' => DoctorDevice::STATUS_ACTIVE])->save();

                $this->auditLogs->log('mst_doctor_devices', (int) $device->id, 'DOCTOR_DEVICE_ADMITTED', [
                    'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
                ], [
                    'status' => DoctorDevice::STATUS_ACTIVE,
                    'via' => 'doctor_device_authorization_approval',
                ], $actor);
            }

            $updated = $this->authorizations->update($locked, [
                'status' => DoctorDeviceAuthorization::STATUS_ACTIVE,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_AUTHORIZATION_APPROVED',
                ['status' => DoctorDeviceAuthorization::STATUS_PENDING],
                ['status' => $updated->status, 'doctor_device_id' => $updated->doctor_device_id],
                $actor);

            return $updated;
        });
    }

    /** Reject. A reason is mandatory — a refusal nobody can explain is not one. */
    public function reject(DoctorDeviceAuthorization $authorization, ?string $reason, User $actor): DoctorDeviceAuthorization
    {
        $reason = $this->requireReason($reason, 'Alasan penolakan wajib diisi.');

        return DB::transaction(function () use ($authorization, $reason, $actor) {
            $locked = $this->lock($authorization);

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini sudah tidak menunggu persetujuan.',
                ]);
            }

            $updated = $this->authorizations->update($locked, [
                'status' => DoctorDeviceAuthorization::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejected_by' => $actor->id,
                'rejected_reason' => $reason,
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_AUTHORIZATION_REJECTED',
                ['status' => DoctorDeviceAuthorization::STATUS_PENDING],
                ['status' => $updated->status, 'reason' => $reason],
                $actor);

            return $updated;
        });
    }

    /**
     * Revoke a previously approved authorization. Terminal: nothing in this
     * service returns a revoked pair to ACTIVE, and a login attempt on one
     * creates no new request.
     */
    public function revoke(DoctorDeviceAuthorization $authorization, ?string $reason, User $actor): DoctorDeviceAuthorization
    {
        $reason = $this->requireReason($reason, 'Alasan pencabutan wajib diisi.');

        return DB::transaction(function () use ($authorization, $reason, $actor) {
            $locked = $this->lock($authorization);

            if ($locked->isRevoked()) {
                return $locked;
            }

            if (! $locked->isActive()) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya otorisasi berstatus ACTIVE yang dapat dicabut.',
                ]);
            }

            $updated = $this->authorizations->update($locked, [
                'status' => DoctorDeviceAuthorization::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoked_reason' => $reason,
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_AUTHORIZATION_REVOKED',
                ['status' => DoctorDeviceAuthorization::STATUS_ACTIVE],
                ['status' => $updated->status, 'reason' => $reason],
                $actor);

            return $updated;
        });
    }

    /**
     * Let a rejected pair be requested again.
     *
     * This does NOT approve anything and does not itself create a PENDING row:
     * it records that the refusal may be revisited, and the doctor still has to
     * attempt a login from that device. The rejection stamps are deliberately
     * preserved — a decision is not erased by being reconsidered.
     */
    public function allowReRequest(DoctorDeviceAuthorization $authorization, User $actor): DoctorDeviceAuthorization
    {
        return DB::transaction(function () use ($authorization, $actor) {
            $locked = $this->lock($authorization);

            if (! $locked->isRejected()) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya permintaan yang DITOLAK dapat diizinkan untuk diajukan ulang.',
                ]);
            }

            $updated = $this->authorizations->update($locked, [
                're_request_allowed_at' => now(),
                're_request_allowed_by' => $actor->id,
                // Name the rejection being forgiven, so a later one spends this.
                're_request_allowed_for_rejected_at' => $locked->rejected_at,
            ]);

            $this->audit($updated, 'DOCTOR_DEVICE_REREQUEST_ALLOWED', null, [
                'status' => $updated->status,
                'doctor_device_id' => $updated->doctor_device_id,
            ], $actor);

            return $updated;
        });
    }

    /** Stamped only on a login that enforcement actually permitted. */
    public function markAuthorizedLogin(DoctorDeviceAuthorization $authorization): void
    {
        $this->authorizations->update($authorization, ['last_authorized_login_at' => now()]);
    }

    public function countPending(): int
    {
        return $this->authorizations->countPending();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DoctorDeviceAuthorization>
     */
    public function paginate(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->authorizations->paginate($filters, $perPage);
    }

    private function requireReason(?string $reason, string $message): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => $message]);
        }

        return $reason;
    }

    private function lock(DoctorDeviceAuthorization $authorization): DoctorDeviceAuthorization
    {
        $locked = $this->authorizations->findForUpdate((int) $authorization->id);

        if ($locked === null) {
            throw ValidationException::withMessages([
                'authorization' => 'Otorisasi perangkat tidak ditemukan.',
            ]);
        }

        return $locked;
    }

    /**
     * Identifiers and decisions only. No credential, no key material, no
     * signature, no patient data — an audit row is read by more people than the
     * thing it describes.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(
        DoctorDeviceAuthorization $authorization,
        string $action,
        ?array $old,
        ?array $new,
        ?User $actor,
    ): void {
        $this->auditLogs->log(
            'mst_doctor_device_authorizations',
            (int) $authorization->id,
            $action,
            $old,
            $new,
            $actor,
        );
    }
}
