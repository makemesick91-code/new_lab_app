<?php

namespace App\Modules\RmeOnlineContext\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\RmeOnlineContext\Interfaces\UserOnlineContextRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Support\AccessControl\FrontOfficeBranchPinResolver;
use App\Support\AccessControl\FrontOfficeRole;
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
        private readonly DailyBranchContextService $dailyBranchContext,
    ) {}

    public function requiresDoctorContext(User $user): bool
    {
        return $user->hasRole('Doctor') && ! $this->isExemptFromContext($user);
    }

    /**
     * D2 — Front Office is resolved through this SAME context, deliberately.
     *
     * `admin_clinic` is already inside
     * `DailyBranchContextService::LOCKED_ROLE_CONTEXTS`, so reusing it is what
     * keeps the daily branch lock engaging for the merged role. A context of
     * its own would have had to be taught to the lock, to
     * `resolveActiveBranchForAdmin()` and to the selector, and a miss in any
     * one of those fails OPEN — no lock, and a fallback to `users.branch_id`,
     * which is NULL for three of the four migrated users.
     */
    public function requiresAdminClinicContext(User $user): bool
    {
        return $user->hasAnyRole(FrontOfficeRole::ADMIN_CLINIC_CONTEXT)
            && ! $this->isExemptFromContext($user);
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
        /*
         * REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — an armed account whose
         * live context row points somewhere other than its pin has NO satisfied
         * context, so the middleware returns it to the selector instead of
         * letting it carry a context that resolves to nothing.
         *
         * Without this the account keeps satisfying the gate on the strength of
         * a row the branch chokepoint has already refused: never bounced,
         * never able to re-select, and 403'd on the first registration attempt
         * with nothing on screen explaining why.
         *
         * Narrowing only: `appliesTo()` is false for every account outside the
         * cohort and while both flags are off, and this method is unreachable
         * for a user whose role needs no context at all.
         */
        $pin = app(FrontOfficeBranchPinResolver::class);

        if ($pin->appliesTo($user) && $this->activeContextBranchId($user) === null) {
            return false;
        }

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

        // FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — resolved through the same
        // authority as every other branch read, so registration and the queue
        // cannot end up on a different branch from the rest of the workspace.
        return $this->activeContextBranchId($user);
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

        // FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — THE DATABASE IS THE AUTHORITY.
        //
        // For a locked role the durable daily context outranks this session row.
        // Without this the lock would only be as good as the row a session start
        // happened to leave behind, and any path that wrote `branch_id` without
        // going through the guard would silently become a bypass. It also makes
        // an approved switch take effect immediately in every one of the
        // operator's existing sessions, not just the one that was realigned.
        //
        // It only ever REPLACES a branch, never resurrects a null: an offline
        // operator still has no working context and must go through the
        // selector, where the lock holds them to this same branch.
        //
        // Scoped to the role context ACTUALLY IN USE. A user who worked as a
        // Kasir this morning and whose account later became Perawat-only would
        // otherwise be pinned by a daily context that no longer governs them —
        // Perawat is deliberately not a locked role.
        if (DailyBranchContextService::isLockedRoleContext((string) $context->role_context)) {
            $lockedBranchId = $this->dailyBranchContext->lockedBranchIdFor($user);

            if ($lockedBranchId !== null) {
                // FAIL CLOSED. If the branch the day is committed to has since
                // been deactivated or lost its RME flag, there is no working
                // context at all. Falling back to the session row here would
                // turn a deactivated branch into a route back to whatever that
                // row happens to say — the precise bypass the override exists
                // to close.
                return $this->branchIsRmeEnabled($lockedBranchId)
                    ? $this->narrowToFrontOfficePin($user, $lockedBranchId)
                    : null;
            }
        }

        return $this->narrowToFrontOfficePin($user, (int) $context->branch_id);
    }

    /**
     * REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — the pin, applied at the ONE
     * chokepoint every branch read shares.
     *
     * WHY IT IS HERE AND NOT ONLY IN BranchContext::forUser().
     *
     * `BranchContext` is not the only authority on an operator's working branch.
     * `resolveActiveBranchForAdmin()` (which decides the branch a NEW CLINIC
     * VISIT is registered at) and `RmeWorkingBranchScope` both read
     * `activeContextBranchId()` directly. Pinning only `BranchContext` therefore
     * produced a split brain: an armed account holding an online-context row
     * selected BEFORE it was armed resolved to its pinned branch everywhere
     * `BranchContext` was consulted, while registration and the workspace list
     * still followed the stale row — so the pin read as "narrowing" while
     * clinical records were still being created on the wider branch.
     *
     * A CONFLICT RESOLVES TO NULL, NOT TO THE PIN.
     *
     * Returning the pin here would look tidier and would be wrong twice over.
     * It would resurrect a working context the daily branch lock had already
     * decided (the override above runs first), handing an armed account a
     * same-day branch move that `FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1` requires
     * Super Admin approval for. And "no working context" is the state this
     * service already models for exactly this situation: the operator is sent
     * back to the selector, which offers only the pinned branch, and the
     * selection guard re-asserts both rules on the way in.
     *
     * NULL IS NOT SELF-EVIDENTLY SAFE FOR REGISTRATION, SO IT IS MADE SAFE.
     *
     * `StoreClinicVisitRequest::applyAdminClinicBranchContext()` early-returns
     * on null, leaving the form's own `branch_id` intact to validate against ANY
     * RME-enabled branch — wider than the stale-branch bug this narrowing fixes.
     * Two guards close that, and BOTH are required:
     *
     *   1. `hasSatisfiedContext()` is pin-aware, so `EnsureRmeOnlineContext`
     *      returns the operator to the selector before any of it is reached.
     *   2. `StoreClinicVisitRequest::authorize()` refuses a pinned account whose
     *      working branch is null — because a route guard is not where a write
     *      should be refused, and exemption lists grow.
     *
     * Do not remove either as redundant.
     *
     * An armed account whose mapping is UNDECIDABLE has no pinned branch, so the
     * comparison below is against null and every branch is refused — the same
     * fail-closed answer `BranchContext` and the selection guard give.
     */
    private function narrowToFrontOfficePin(User $user, int $branchId): ?int
    {
        $pin = app(FrontOfficeBranchPinResolver::class);

        if (! $pin->appliesTo($user)) {
            return $branchId;
        }

        return $pin->requiredBranchIdFor($user) === $branchId ? $branchId : null;
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

        // DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — a locked doctor may only
        // go online at their effective branch. Ordered AFTER the practice-branch
        // eligibility assert on purpose, exactly as the daily-branch-context
        // guard below is: the lock can then never become a path to a branch the
        // doctor was not entitled to work in, only a narrowing of one they were.
        //
        // A disabled <select> is not a security boundary. The selector renders
        // one option for a locked doctor; THIS is what refuses a crafted POST.
        // Resolved through the container, not the constructor: the resolver
        // depends on this service, so injecting it would close a cycle.
        $this->assertWithinDoctorEffectiveBranch($user, $branchId);

        $this->assertRmeBranch($branchId);

        // REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — refuse a widening
        // selection for an armed front-desk account. A no-op for everyone else.
        // Decided by the BRANCH-PIN predicate, never by the device predicate:
        // pinning must hold with WebAuthn enforcement switched off.
        $this->assertFrontOfficeBranchLock($user, $branchId);

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

        // REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — refuse a widening
        // selection for an armed front-desk account. A no-op for everyone else.
        // Decided by the BRANCH-PIN predicate, never by the device predicate:
        // pinning must hold with WebAuthn enforcement switched off.
        $this->assertFrontOfficeBranchLock($user, $branchId);

        // FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the day's branch is committed on
        // the FIRST selection. Ordered after the eligibility assert on purpose:
        // an approved switch can then never become a path to a branch the user
        // was not entitled to work in.
        $this->dailyBranchContext->assertSelectable(
            $user,
            $branchId,
            UserOnlineContext::ROLE_ADMIN_CLINIC,
        );

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

        // REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — refuse a widening
        // selection for an armed front-desk account. A no-op for everyone else.
        // Decided by the BRANCH-PIN predicate, never by the device predicate:
        // pinning must hold with WebAuthn enforcement switched off.
        $this->assertFrontOfficeBranchLock($user, $branchId);

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

        // REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — refuse a widening
        // selection for an armed front-desk account. A no-op for everyone else.
        // Decided by the BRANCH-PIN predicate, never by the device predicate:
        // pinning must hold with WebAuthn enforcement switched off.
        $this->assertFrontOfficeBranchLock($user, $branchId);

        // FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — see startAdminClinicSession().
        // The cashier's day is committed here, which is why a logout, a second
        // browser or a raw POST cannot move the financial scope.
        $this->dailyBranchContext->assertSelectable(
            $user,
            $branchId,
            UserOnlineContext::ROLE_KASIR,
        );

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

    /**
     * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — an armed front-desk account
     * may only ever select the branch it is pinned to.
     *
     * PLACED ON THE MUTATION, NOT ON THE ROUTE.
     *
     * Hiding the selector is presentation, and presentation is not a security
     * boundary: a crafted `POST online-context/admin-clinic` with another
     * `branch_id` reaches this service directly. So the refusal lives where the
     * write happens, which also means every current and future caller inherits
     * it — no enumerated list of branch-changing endpoints to keep in step.
     *
     * The required branch is resolved SERVER-SIDE from the cohort mapping. The
     * submitted `branch_id` is only ever the thing being CHECKED; it never
     * becomes the thing that decides.
     *
     * A no-op while the flag is off, and a no-op for every account outside the
     * four-id cohort — the other four Front Office accounts keep selecting
     * branches exactly as they do today.
     *
     * Resolved lazily rather than constructor-injected: this service is itself
     * resolved during branch resolution, and a lazy lookup keeps that graph
     * acyclic.
     */
    private function assertFrontOfficeBranchLock(User $user, int $branchId): void
    {
        $pin = app(FrontOfficeBranchPinResolver::class);

        if (! $pin->appliesTo($user)) {
            return;
        }

        $requiredBranchId = $pin->requiredBranchIdFor($user);

        /*
         * An armed account whose mapping is unusable selects NOTHING.
         *
         * Under the device layer such an account had already been denied login,
         * so this was belt-and-braces. Under the branch-context layer it is the
         * ENFORCEMENT: this layer never denies a login, so a misconfigured armed
         * account reaches here with a live session and must be refused a branch
         * here. Identical to the fail-closed NULL in BranchContext::forUser().
         */
        if ($requiredBranchId === null || $requiredBranchId !== $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'Akun Front Office ini terkunci pada cabangnya sendiri dan tidak dapat memilih cabang lain.',
            ]);
        }
    }

    /**
     * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the server-side half of the
     * doctor branch selector.
     *
     * ONE question, to the ONE authority. Null means every non-locking state —
     * capability off, non-doctor, exempt governance account, unlinked doctor,
     * UNSET doctor, retired locked branch — and every one of them keeps the
     * free branch choice this system had before the sprint (owner decision O1).
     */
    private function assertWithinDoctorEffectiveBranch(User $user, int $branchId): void
    {
        $effectiveBranchId = app(DoctorEffectiveBranchResolver::class)->branchIdFor($user);

        if ($effectiveBranchId === null || $effectiveBranchId === $branchId) {
            return;
        }

        $branchName = $this->branches->find($effectiveBranchId)?->name
            ?? 'cabang yang terkunci untuk Anda';

        throw ValidationException::withMessages([
            'branch_id' => 'Cabang klinis Anda terkunci di '.$branchName.'. '
                .'Anda hanya dapat online di cabang tersebut. '
                .'Ajukan perpindahan cabang atau cover sementara untuk mendapatkan '
                .'persetujuan Super Admin atau Supervisor RME.',
        ]);
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
