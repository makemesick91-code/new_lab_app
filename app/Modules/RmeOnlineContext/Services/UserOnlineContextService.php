<?php

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\RmeOnlineContext\Interfaces\UserOnlineContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UserOnlineContextService
{
    public const INACTIVITY_MINUTES = 30;

    public function __construct(
        private readonly UserOnlineContextRepositoryInterface $contexts,
        private readonly BranchService $branches,
        private readonly BranchRepositoryInterface $branchRepository,
        private readonly DoctorUserResolver $doctorResolver,
    ) {}

    public function requiresDoctorContext(User $user): bool
    {
        return $user->hasRole('Doctor') && ! $this->isExemptFromContext($user);
    }

    public function requiresAdminClinicContext(User $user): bool
    {
        return $user->hasRole('Admin Klinik') && ! $this->isExemptFromContext($user);
    }

    public function isExemptFromContext(User $user): bool
    {
        return $user->hasRole(['Owner', 'Super Admin', 'Supervisor RME']);
    }

    public function hasSatisfiedContext(User $user): bool
    {
        if ($this->requiresDoctorContext($user)) {
            return $this->isDoctorOnline($user);
        }

        if ($this->requiresAdminClinicContext($user)) {
            return $this->isAdminClinicActive($user);
        }

        return true;
    }

    public function currentContextFor(User $user): ?UserOnlineContext
    {
        $context = $this->contexts->findForUser((int) $user->id);

        if ($context === null) {
            return null;
        }

        if ($this->isExpired($context)) {
            $this->markExpiredInactive($context);

            return $this->contexts->findForUser((int) $user->id);
        }

        return $context;
    }

    public function isDoctorOnline(User $user): bool
    {
        if (! $this->requiresDoctorContext($user)) {
            return false;
        }

        $context = $this->currentContextFor($user);

        return $context !== null
            && $context->role_context === UserOnlineContext::ROLE_DOCTOR
            && $context->status === UserOnlineContext::STATUS_ONLINE
            && $context->branch_id !== null
            && $context->clinic_room_id !== null
            && $this->branchIsRmeEnabled((int) $context->branch_id)
            && $this->roomIsActiveInBranch((int) $context->clinic_room_id, (int) $context->branch_id);
    }

    public function isAdminClinicActive(User $user): bool
    {
        if (! $this->requiresAdminClinicContext($user)) {
            return false;
        }

        $context = $this->currentContextFor($user);

        return $context !== null
            && $context->role_context === UserOnlineContext::ROLE_ADMIN_CLINIC
            && $context->status === UserOnlineContext::STATUS_ONLINE
            && $context->branch_id !== null
            && $this->branchIsRmeEnabled((int) $context->branch_id);
    }

    public function resolveActiveBranchForAdmin(User $user): ?int
    {
        if (! $this->isAdminClinicActive($user)) {
            return null;
        }

        return (int) $this->currentContextFor($user)?->branch_id;
    }

    public function startDoctorSession(User $user, int $branchId, int $clinicRoomId): UserOnlineContext
    {
        if (! $this->requiresDoctorContext($user)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Akun ini tidak memerlukan konteks dokter online.',
            ]);
        }

        $doctor = $this->doctorResolver->resolveForUser($user);

        if ($doctor === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Akun dokter belum terhubung ke data master dokter. Hubungi admin klinik.',
            ]);
        }

        $this->assertRmeBranch($branchId);
        $this->assertActiveRoomInBranch($clinicRoomId, $branchId);

        $now = now();

        return $this->contexts->upsertForUser((int) $user->id, [
            'branch_id' => $branchId,
            'clinic_room_id' => $clinicRoomId,
            'role_context' => UserOnlineContext::ROLE_DOCTOR,
            'status' => UserOnlineContext::STATUS_ONLINE,
            'online_since' => $now,
            'last_seen_at' => $now,
            'offline_at' => null,
        ]);
    }

    public function startAdminClinicSession(User $user, int $branchId): UserOnlineContext
    {
        if (! $this->requiresAdminClinicContext($user)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Akun ini tidak memerlukan konteks admin klinik.',
            ]);
        }

        $this->assertRmeBranch($branchId);

        $now = now();

        return $this->contexts->upsertForUser((int) $user->id, [
            'branch_id' => $branchId,
            'clinic_room_id' => null,
            'role_context' => UserOnlineContext::ROLE_ADMIN_CLINIC,
            'status' => UserOnlineContext::STATUS_ONLINE,
            'online_since' => $now,
            'last_seen_at' => $now,
            'offline_at' => null,
        ]);
    }

    public function markOffline(User $user): void
    {
        $context = $this->contexts->findForUser((int) $user->id);

        if ($context === null) {
            return;
        }

        $this->contexts->upsertForUser((int) $user->id, [
            'status' => UserOnlineContext::STATUS_OFFLINE,
            'offline_at' => now(),
            'clinic_room_id' => $context->role_context === UserOnlineContext::ROLE_DOCTOR
                ? null
                : $context->clinic_room_id,
        ]);
    }

    public function touchLastSeen(User $user): void
    {
        $context = $this->contexts->findForUser((int) $user->id);

        if ($context === null || $context->status !== UserOnlineContext::STATUS_ONLINE) {
            return;
        }

        $context->update(['last_seen_at' => now()]);
    }

    /**
     * @return Collection<int, Doctor>
     */
    public function activeDoctorsForBranch(int $branchId): Collection
    {
        if (! $this->branchIsRmeEnabled($branchId)) {
            return collect();
        }

        $doctors = collect();

        foreach ($this->contexts->onlineDoctorsForBranch($branchId) as $context) {
            if ($this->isExpired($context)) {
                $this->markExpiredInactive($context);

                continue;
            }

            $user = $context->user;

            if ($user === null) {
                continue;
            }

            $doctor = $this->doctorResolver->resolveForUser($user);

            if ($doctor !== null && $doctor->is_active) {
                $doctors->push($doctor);
            }
        }

        return $doctors->unique('id')->sortBy('name')->values();
    }

    public function isDoctorOnlineInBranch(int $doctorId, int $branchId): bool
    {
        $doctor = Doctor::query()->find($doctorId);

        if ($doctor === null || ! $doctor->is_active) {
            return false;
        }

        $user = $this->doctorResolver->resolveUserForDoctor($doctor);

        if ($user === null) {
            return false;
        }

        $context = $this->currentContextFor($user);

        return $context !== null
            && $context->role_context === UserOnlineContext::ROLE_DOCTOR
            && $context->status === UserOnlineContext::STATUS_ONLINE
            && (int) $context->branch_id === $branchId
            && $context->clinic_room_id !== null
            && $this->roomIsActiveInBranch((int) $context->clinic_room_id, $branchId);
    }

    public function assertDoctorSelectableForVisit(int $doctorId, int $branchId): void
    {
        if (! $this->isDoctorOnlineInBranch($doctorId, $branchId)) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Dokter yang dipilih tidak online di cabang kunjungan ini.',
            ]);
        }
    }

    private function assertRmeBranch(int $branchId): void
    {
        if (! $this->branchIsRmeEnabled($branchId)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang yang dipilih harus cabang RME aktif.',
            ]);
        }
    }

    private function assertActiveRoomInBranch(int $roomId, int $branchId): void
    {
        if (! $this->roomIsActiveInBranch($roomId, $branchId)) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Ruangan harus aktif dan berasal dari cabang yang dipilih.',
            ]);
        }
    }

    private function branchIsRmeEnabled(int $branchId): bool
    {
        $branch = $this->branchRepository->findById($branchId);

        return $branch !== null
            && $branch->is_active
            && $branch->is_rme_enabled;
    }

    private function roomIsActiveInBranch(int $roomId, int $branchId): bool
    {
        $room = ClinicRoom::query()->find($roomId);

        return $room !== null
            && $room->status === ClinicRoom::STATUS_ACTIVE
            && (int) $room->branch_id === $branchId;
    }

    private function isExpired(UserOnlineContext $context): bool
    {
        if ($context->status !== UserOnlineContext::STATUS_ONLINE || $context->last_seen_at === null) {
            return false;
        }

        return $context->last_seen_at->lt(Carbon::now()->subMinutes(self::INACTIVITY_MINUTES));
    }

    private function markExpiredInactive(UserOnlineContext $context): void
    {
        $context->update([
            'status' => UserOnlineContext::STATUS_INACTIVE,
            'clinic_room_id' => $context->role_context === UserOnlineContext::ROLE_DOCTOR
                ? null
                : $context->clinic_room_id,
        ]);
    }
}
