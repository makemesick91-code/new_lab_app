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

    /**
     * RME-BRANCH-SUN4 — Perawat uses the same branch-only online context
     * mechanism as Admin Klinik (no treatment room, no static users.branch_id).
     */
    public function requiresPerawatContext(User $user): bool
    {
        return $user->hasRole('Perawat') && ! $this->isExemptFromContext($user);
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-03) — Kasir uses the same
     * branch-only online context mechanism as Admin Klinik and Perawat, so the
     * cashier workspace and every payment mutation are pinned to the branch the
     * cashier is actually working in.
     */
    public function requiresKasirContext(User $user): bool
    {
        return $user->hasRole('Kasir') && ! $this->isExemptFromContext($user);
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

        if ($this->requiresPerawatContext($user)) {
            return $this->isPerawatActive($user);
        }

        if ($this->requiresKasirContext($user)) {
            return $this->isKasirActive($user);
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

    public function isPerawatActive(User $user): bool
    {
        if (! $this->requiresPerawatContext($user)) {
            return false;
        }

        $context = $this->currentContextFor($user);

        return $context !== null
            && $context->role_context === UserOnlineContext::ROLE_PERAWAT
            && $context->status === UserOnlineContext::STATUS_ONLINE
            && $context->branch_id !== null
            && $this->branchIsRmeEnabled((int) $context->branch_id);
    }

    public function isKasirActive(User $user): bool
    {
        if (! $this->requiresKasirContext($user)) {
            return false;
        }

        $context = $this->currentContextFor($user);

        return $context !== null
            && $context->role_context === UserOnlineContext::ROLE_KASIR
            && $context->status === UserOnlineContext::STATUS_ONLINE
            && $context->branch_id !== null
            && $this->branchIsRmeEnabled((int) $context->branch_id);
    }

    /**
     * Active branch-only online context branch (Admin Klinik or Perawat).
     * Registration/queue flows treat both roles identically: the visit branch is
     * always the online context branch, never a form-submitted branch_id.
     */
    public function resolveActiveBranchForAdmin(User $user): ?int
    {
        if (! $this->isAdminClinicActive($user) && ! $this->isPerawatActive($user)) {
            return null;
        }

        return (int) $this->currentContextFor($user)?->branch_id;
    }

    /**
     * RME-BRANCH-SUN4 — the active online context branch for BranchContext
     * resolution, regardless of role context. Fail closed: returns null unless
     * the context is online, matches the user's current role requirement, and
     * points at an active RME-enabled branch (MAIN can never qualify because
     * session start asserts an RME branch and MAIN is non-RME by definition).
     */
    public function activeContextBranchId(User $user): ?int
    {
        if ($this->isExemptFromContext($user)) {
            return null;
        }

        $context = $this->currentContextFor($user);

        if ($context === null
            || $context->status !== UserOnlineContext::STATUS_ONLINE
            || $context->branch_id === null) {
            return null;
        }

        $matchesRole = match ($context->role_context) {
            UserOnlineContext::ROLE_DOCTOR => $this->requiresDoctorContext($user),
            UserOnlineContext::ROLE_ADMIN_CLINIC => $this->requiresAdminClinicContext($user),
            UserOnlineContext::ROLE_PERAWAT => $this->requiresPerawatContext($user),
            UserOnlineContext::ROLE_KASIR => $this->requiresKasirContext($user),
            default => false,
        };

        if (! $matchesRole || ! $this->branchIsRmeEnabled((int) $context->branch_id)) {
            return null;
        }

        return (int) $context->branch_id;
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

        if (! $doctor->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Data dokter tidak aktif. Hubungi admin klinik.',
            ]);
        }

        $doctor->loadMissing('branches');

        if ($doctor->branches->isEmpty()) {
            throw ValidationException::withMessages([
                'branch_id' => 'Dokter belum memiliki Cabang Praktik. Hubungi admin.',
            ]);
        }

        if (! $doctor->branches->contains('id', $branchId)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang yang dipilih tidak termasuk Cabang Praktik yang Diizinkan.',
            ]);
        }

        $this->assertRmeBranch($branchId);
        $this->assertActiveRoomInBranch($clinicRoomId, $branchId);
        $this->assertRoomNotOccupiedByOtherDoctor($branchId, $clinicRoomId, (int) $user->id);

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

    public function startPerawatSession(User $user, int $branchId): UserOnlineContext
    {
        if (! $this->requiresPerawatContext($user)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Akun ini tidak memerlukan konteks perawat.',
            ]);
        }

        $this->assertRmeBranch($branchId);

        $now = now();

        return $this->contexts->upsertForUser((int) $user->id, [
            'branch_id' => $branchId,
            'clinic_room_id' => null,
            'role_context' => UserOnlineContext::ROLE_PERAWAT,
            'status' => UserOnlineContext::STATUS_ONLINE,
            'online_since' => $now,
            'last_seen_at' => $now,
            'offline_at' => null,
        ]);
    }

    public function startKasirSession(User $user, int $branchId): UserOnlineContext
    {
        if (! $this->requiresKasirContext($user)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Akun ini tidak memerlukan konteks kasir.',
            ]);
        }

        $this->assertRmeBranch($branchId);

        $now = now();

        return $this->contexts->upsertForUser((int) $user->id, [
            'branch_id' => $branchId,
            'clinic_room_id' => null,
            'role_context' => UserOnlineContext::ROLE_KASIR,
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

    /**
     * Sprint 66.1.4 — resolve the single online doctor for a branch+room when
     * Admin Klinik assigns a treatment room from the patient queue.
     */
    public function resolveDoctorIdForRoom(int $branchId, int $clinicRoomId): int
    {
        $contexts = $this->activeOnlineDoctorContextsInRoom($branchId, $clinicRoomId);

        if ($contexts->isEmpty()) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Belum ada dokter online di ruangan ini.',
            ]);
        }

        if ($contexts->count() > 1) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Terdapat lebih dari satu dokter online di ruangan ini. Pastikan hanya satu dokter aktif per ruangan.',
            ]);
        }

        $user = $contexts->first()->user;

        if ($user === null) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Belum ada dokter online di ruangan ini.',
            ]);
        }

        $doctor = $this->doctorResolver->resolveForUser($user);

        if ($doctor === null || ! $doctor->is_active) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Belum ada dokter online di ruangan ini.',
            ]);
        }

        return (int) $doctor->id;
    }

    private function assertRoomNotOccupiedByOtherDoctor(int $branchId, int $roomId, int $userId): void
    {
        $occupied = $this->activeOnlineDoctorContextsInRoom($branchId, $roomId)
            ->contains(fn (UserOnlineContext $context) => (int) $context->user_id !== $userId);

        if ($occupied) {
            throw ValidationException::withMessages([
                'clinic_room_id' => 'Ruangan ini sedang digunakan oleh dokter lain.',
            ]);
        }
    }

    /**
     * @return Collection<int, UserOnlineContext>
     */
    private function activeOnlineDoctorContextsInRoom(int $branchId, int $roomId): Collection
    {
        $active = collect();

        foreach ($this->contexts->onlineDoctorsInRoom($branchId, $roomId) as $context) {
            if ($this->isExpired($context)) {
                $this->markExpiredInactive($context);

                continue;
            }

            $user = $context->user;

            if ($user === null) {
                continue;
            }

            $doctor = $this->doctorResolver->resolveForUser($user);

            if ($doctor === null || ! $doctor->is_active) {
                continue;
            }

            $active->push($context);
        }

        return $active->values();
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
