<?php

namespace App\Modules\Doctor\Services;

use App\Models\User;
use App\Modules\Doctor\Interfaces\DoctorRepositoryInterface;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
 *
 * Creates and removes the explicit `mst_doctors.user_id` link between a login
 * account and a doctor master record.
 *
 * This is a security-sensitive operation: the link decides whose clinical
 * history and whose income a doctor account can read. Every write therefore
 * happens inside one transaction with the doctor row locked, re-asserts every
 * precondition under that lock, and is written to the immutable audit trail.
 *
 * What this service deliberately does NOT do:
 *   - it never assigns or removes a role (RBAC stays a separate decision);
 *   - it never touches visits, medical records, invoices, payments or earnings
 *     (history stays attached to the doctor identity and simply becomes
 *     reachable through the link);
 *   - it never guesses a link from a name, email or phone number;
 *   - it never silently replaces an existing link.
 */
class DoctorAccountLinkService
{
    public const ROLE = 'Doctor';

    public const ACTION_LINK = 'DOCTOR_ACCOUNT_LINK';

    public const ACTION_RELINK = 'DOCTOR_ACCOUNT_RELINK';

    public const ACTION_UNLINK = 'DOCTOR_ACCOUNT_UNLINK';

    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Doctor records with their linked account eagerly loaded, for the
     * management screen.
     */
    public function listForManagement(array $filters = [], int $perPage = 15)
    {
        return $this->doctors->paginateWithLinkedAccount($filters, $perPage);
    }

    /**
     * Accounts that may legitimately be offered as link targets: active, holding
     * the Doctor role, and not already linked to another doctor record.
     *
     * @return Collection<int, User>
     */
    public function linkableAccounts(): Collection
    {
        return $this->doctors->linkableUserCandidates(self::ROLE);
    }

    /**
     * Link (or, when explicitly confirmed, relink) a doctor to an account.
     *
     * @throws ValidationException when the pair is not eligible.
     */
    public function link(Doctor $doctor, int $userId, bool $confirmRelink = false): Doctor
    {
        return DB::transaction(function () use ($doctor, $userId, $confirmRelink): Doctor {
            // Re-read both sides under a lock: the checks below and the write
            // must see the same state, or two concurrent operators could each
            // pass validation and race to the same account.
            $locked = $this->doctors->findForUpdate((int) $doctor->id);

            if ($locked === null) {
                $this->reject('Data dokter tidak ditemukan.');
            }

            $user = User::query()->whereKey($userId)->lockForUpdate()->first();

            if ($user === null) {
                $this->reject('Akun pengguna tidak ditemukan.');
            }

            if (! (bool) $user->is_active) {
                $this->reject('Akun pengguna tidak aktif. Aktifkan akun terlebih dahulu.');
            }

            // The role is a prerequisite, never a side effect: linking must not
            // silently turn an ordinary account into a doctor account.
            if (! $user->hasRole(self::ROLE)) {
                $this->reject(
                    'Akun ini tidak memiliki role '.self::ROLE.
                    '. Tetapkan role melalui manajemen pengguna terlebih dahulu, lalu hubungkan.'
                );
            }

            // One account may represent at most one doctor.
            $conflict = $this->doctors->findLinkedByUserId((int) $user->id, (int) $locked->id);

            if ($conflict !== null) {
                $this->reject($conflict->trashed()
                    ? 'Akun ini masih terhubung ke data dokter yang sudah dihapus ('.$conflict->name.
                      '). Pulihkan dokter tersebut lalu putuskan hubungannya terlebih dahulu.'
                    : 'Akun ini sudah terhubung ke dokter lain ('.$conflict->name.
                      '). Putuskan hubungan tersebut terlebih dahulu.');
            }

            $previousUserId = $locked->user_id === null ? null : (int) $locked->user_id;

            // Already the requested link — nothing to change, nothing to audit.
            if ($previousUserId === (int) $user->id) {
                return $locked;
            }

            // One doctor may have at most one active account, and replacing an
            // existing link must be a deliberate act.
            if ($previousUserId !== null && ! $confirmRelink) {
                $this->reject(
                    'Dokter ini sudah terhubung ke akun lain. Centang konfirmasi penggantian akun untuk melanjutkan.'
                );
            }

            $updated = $this->doctors->setLinkedUser($locked, (int) $user->id);

            $this->auditLog->log(
                Doctor::class,
                (int) $updated->id,
                $previousUserId === null ? self::ACTION_LINK : self::ACTION_RELINK,
                ['user_id' => $previousUserId],
                ['user_id' => (int) $user->id],
            );

            return $updated;
        });
    }

    /**
     * Remove the link. The doctor record, the account, and all clinical and
     * financial history are preserved — only self-service access is withdrawn.
     */
    public function unlink(Doctor $doctor): Doctor
    {
        return DB::transaction(function () use ($doctor): Doctor {
            $locked = $this->doctors->findForUpdate((int) $doctor->id);

            if ($locked === null) {
                $this->reject('Data dokter tidak ditemukan.');
            }

            $previousUserId = $locked->user_id === null ? null : (int) $locked->user_id;

            if ($previousUserId === null) {
                return $locked;
            }

            $updated = $this->doctors->setLinkedUser($locked, null);

            $this->auditLog->log(
                Doctor::class,
                (int) $updated->id,
                self::ACTION_UNLINK,
                ['user_id' => $previousUserId],
                ['user_id' => null],
            );

            return $updated;
        });
    }

    /**
     * Every rejection is reported against `user_id` so the management form shows
     * it next to the account selector.
     *
     * @throws ValidationException
     */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['user_id' => $message]);
    }
}
