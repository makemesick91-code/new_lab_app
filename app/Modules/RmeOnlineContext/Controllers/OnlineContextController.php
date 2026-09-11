<?php

namespace App\Modules\RmeOnlineContext\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\DoctorAccess\Services\DoctorEffectiveBranchResolver;
use App\Modules\RmeOnlineContext\Requests\StartAdminClinicOnlineContextRequest;
use App\Modules\RmeOnlineContext\Requests\StartDoctorOnlineContextRequest;
use App\Modules\RmeOnlineContext\Requests\StartKasirOnlineContextRequest;
use App\Modules\RmeOnlineContext\Requests\StartPerawatOnlineContextRequest;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use App\Modules\RmeOnlineContext\Services\DoctorUserResolver;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnlineContextController extends Controller
{
    public function __construct(
        private readonly UserOnlineContextService $onlineContext,
        private readonly BranchService $branches,
        private readonly ClinicVisitService $visits,
        private readonly DoctorUserResolver $doctorResolver,
        private readonly DailyBranchContextService $dailyBranchContext,
        private readonly DoctorEffectiveBranchResolver $doctorBranches,
    ) {}

    public function select(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($this->onlineContext->hasSatisfiedContext($user)) {
            return redirect()->route('dashboard');
        }

        $requiresDoctor = $this->onlineContext->requiresDoctorContext($user);
        $requiresAdmin = $this->onlineContext->requiresAdminClinicContext($user);
        $requiresPerawat = $this->onlineContext->requiresPerawatContext($user);
        $requiresKasir = $this->onlineContext->requiresKasirContext($user);

        abort_unless($requiresDoctor || $requiresAdmin || $requiresPerawat || $requiresKasir, 403);

        $linkedDoctor = $requiresDoctor ? $this->doctorResolver->resolveForUser($user) : null;

        if ($linkedDoctor !== null) {
            $linkedDoctor->load(['branches' => fn ($query) => $query
                ->where('is_active', true)
                ->where('is_rme_enabled', true)
                ->orderBy('name'),
            ]);
        }

        $doctorAllowedBranches = $linkedDoctor?->branches ?? collect();

        /*
         * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — a LOCKED doctor is not
         * offered a free branch choice. Asked once, of the one authority; null
         * covers every non-locking state (capability off, unlinked, UNSET,
         * retired locked branch) and leaves the selector exactly as it was.
         *
         * Filtering the collection rather than replacing it makes the Alpine
         * seed follow automatically: the room map below is derived from
         * $doctorAllowedBranches, so the component can only ever be handed
         * rooms of a branch this doctor may actually select. It is presentation
         * only — UserOnlineContextService::startDoctorSession() re-asserts the
         * same rule server-side, because a one-option dropdown refuses nothing.
         *
         * The intersection can leave it EMPTY, when the locked branch is not
         * one of the doctor's practice branches. The view says so plainly
         * instead of pretending the doctor has no practice branch at all.
         */
        $doctorEffectiveBranchId = $requiresDoctor
            ? $this->doctorBranches->branchIdFor($user)
            : null;

        if ($doctorEffectiveBranchId !== null) {
            $doctorAllowedBranches = $doctorAllowedBranches
                ->filter(fn ($branch) => (int) $branch->id === $doctorEffectiveBranchId)
                ->values();
        }

        // FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — a Kasir or Admin Klinik who has
        // already committed today sees the locked branch and the request route,
        // not a free dropdown that would only fail on submit. The selector is
        // still rendered for the roles whose day is open, and the server-side
        // guard remains the boundary either way.
        $dailyContext = $this->dailyBranchContext->currentFor($user);
        $lockedBranchId = $dailyContext ? (int) $dailyContext->current_branch_id : null;
        $rmeBranches = $this->branches->listRmeEnabled();

        return view('rme.online-context.select', [
            'requiresDoctor' => $requiresDoctor,
            'requiresAdmin' => $requiresAdmin,
            'requiresPerawat' => $requiresPerawat,
            'requiresKasir' => $requiresKasir,
            'linkedDoctor' => $linkedDoctor,
            'doctorAllowedBranches' => $doctorAllowedBranches,
            'doctorEffectiveBranchId' => $doctorEffectiveBranchId,
            'doctorEffectiveBranch' => $doctorEffectiveBranchId
                ? $rmeBranches->firstWhere('id', $doctorEffectiveBranchId)
                : null,
            'rmeBranches' => $rmeBranches,
            'roomsByBranch' => $this->visits->activeRoomsByRmeBranch(),
            'currentContext' => $this->onlineContext->currentContextFor($user),
            'dailyContext' => $dailyContext,
            'lockedBranch' => $lockedBranchId
                ? $rmeBranches->firstWhere('id', $lockedBranchId)
                : null,
        ]);
    }

    public function rooms(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id');

        abort_if($branchId <= 0, 422, 'branch_id wajib diisi.');

        $rooms = $this->visits->activeRoomsForBranch($branchId);

        return response()->json([
            'rooms' => $rooms->map(fn ($room) => [
                'id' => $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ])->values(),
        ]);
    }

    public function storeDoctor(StartDoctorOnlineContextRequest $request): RedirectResponse
    {
        abort_unless($this->onlineContext->requiresDoctorContext($request->user()), 403);

        $this->onlineContext->startDoctorSession(
            $request->user(),
            (int) $request->validated('branch_id'),
            (int) $request->validated('clinic_room_id'),
        );

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Status dokter online aktif.');
    }

    public function storeAdminClinic(StartAdminClinicOnlineContextRequest $request): RedirectResponse
    {
        abort_unless($this->onlineContext->requiresAdminClinicContext($request->user()), 403);

        $this->onlineContext->startAdminClinicSession(
            $request->user(),
            (int) $request->validated('branch_id'),
        );

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Konteks cabang admin klinik aktif.');
    }

    public function storePerawat(StartPerawatOnlineContextRequest $request): RedirectResponse
    {
        abort_unless($this->onlineContext->requiresPerawatContext($request->user()), 403);

        $this->onlineContext->startPerawatSession(
            $request->user(),
            (int) $request->validated('branch_id'),
        );

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Konteks cabang perawat aktif.');
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-03) — start the cashier working
     * branch context. The cashier workspace and every payment mutation are
     * scoped to this branch server-side.
     */
    public function storeKasir(StartKasirOnlineContextRequest $request): RedirectResponse
    {
        abort_unless($this->onlineContext->requiresKasirContext($request->user()), 403);

        $this->onlineContext->startKasirSession(
            $request->user(),
            (int) $request->validated('branch_id'),
        );

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Konteks cabang kasir aktif.');
    }

    public function offline(Request $request): RedirectResponse
    {
        $this->onlineContext->markOffline($request->user());

        return redirect()
            ->route('rme.online-context.select')
            ->with('status', 'Status online dinonaktifkan.');
    }
}
