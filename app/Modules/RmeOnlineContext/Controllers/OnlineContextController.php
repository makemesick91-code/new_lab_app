<?php

namespace App\Modules\RmeOnlineContext\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\RmeOnlineContext\Requests\StartAdminClinicOnlineContextRequest;
use App\Modules\RmeOnlineContext\Requests\StartDoctorOnlineContextRequest;
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
    ) {}

    public function select(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($this->onlineContext->hasSatisfiedContext($user)) {
            return redirect()->route('dashboard');
        }

        $requiresDoctor = $this->onlineContext->requiresDoctorContext($user);
        $requiresAdmin = $this->onlineContext->requiresAdminClinicContext($user);

        abort_unless($requiresDoctor || $requiresAdmin, 403);

        $linkedDoctor = $requiresDoctor ? $this->doctorResolver->resolveForUser($user) : null;

        if ($linkedDoctor !== null) {
            $linkedDoctor->load(['branches' => fn ($query) => $query
                ->where('is_active', true)
                ->where('is_rme_enabled', true)
                ->orderBy('name'),
            ]);
        }

        $doctorAllowedBranches = $linkedDoctor?->branches ?? collect();

        return view('rme.online-context.select', [
            'requiresDoctor' => $requiresDoctor,
            'requiresAdmin' => $requiresAdmin,
            'linkedDoctor' => $linkedDoctor,
            'doctorAllowedBranches' => $doctorAllowedBranches,
            'rmeBranches' => $this->branches->listRmeEnabled(),
            'roomsByBranch' => $this->visits->activeRoomsByRmeBranch(),
            'currentContext' => $this->onlineContext->currentContextFor($user),
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

    public function offline(Request $request): RedirectResponse
    {
        $this->onlineContext->markOffline($request->user());

        return redirect()
            ->route('rme.online-context.select')
            ->with('status', 'Status online dinonaktifkan.');
    }
}
