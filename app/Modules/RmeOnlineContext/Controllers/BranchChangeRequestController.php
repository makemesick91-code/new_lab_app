<?php

declare(strict_types=1);

namespace App\Modules\RmeOnlineContext\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\RmeOnlineContext\Interfaces\BranchChangeRequestRepositoryInterface;
use App\Modules\RmeOnlineContext\Models\BranchChangeRequest;
use App\Modules\RmeOnlineContext\Requests\DecideBranchChangeRequestRequest;
use App\Modules\RmeOnlineContext\Requests\StoreBranchChangeRequestRequest;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\DailyBranchContextService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 — the request and approval surfaces.
 *
 * Thin by contract: authorize, validate, delegate. Every rule about what may
 * move, when, and by whom lives in
 * {@see BranchChangeApprovalService} and {@see DailyBranchContextService}, so
 * the console and the HTTP surface cannot diverge.
 */
class BranchChangeRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly BranchChangeApprovalService $approvals,
        private readonly DailyBranchContextService $daily,
        private readonly BranchChangeRequestRepositoryInterface $requests,
        private readonly BranchService $branches,
    ) {}

    /**
     * The operator's own view: today's locked branch, their pending request if
     * any, and the form to file a new one.
     */
    public function create(Request $request): View
    {
        $user = $request->user();
        $this->authorize('create', BranchChangeRequest::class);

        $context = $this->daily->currentFor($user);
        $clinicalDate = $this->daily->clinicalToday();

        return view('rme.branch-change-requests.create', [
            'dailyContext' => $context,
            'currentBranch' => $context
                ? $this->branches->listRmeEnabled()->firstWhere('id', (int) $context->current_branch_id)
                : null,
            // Only branches other than the one they are already locked to; the
            // service refuses a same-branch request anyway.
            'destinations' => $this->branches->listRmeEnabled()
                ->reject(fn ($branch) => (int) $branch->id === (int) $context?->current_branch_id)
                ->values(),
            'pendingRequest' => $this->requests->findPendingForUser((int) $user->id, $clinicalDate),
            'history' => $this->requests->forUserAndDate((int) $user->id, $clinicalDate),
            'clinicalDate' => $clinicalDate,
        ]);
    }

    public function store(StoreBranchChangeRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', BranchChangeRequest::class);

        $this->approvals->request(
            $request->user(),
            (int) $request->validated('destination_branch_id'),
            (string) $request->validated('reason'),
        );

        return redirect()
            ->route('rme.branch-change-requests.create')
            ->with('status', 'Permintaan perpindahan cabang dikirim. Menunggu persetujuan Super Admin.');
    }

    public function cancel(Request $request, BranchChangeRequest $branchChangeRequest): RedirectResponse
    {
        $this->authorize('cancel', $branchChangeRequest);

        $this->approvals->cancel((int) $branchChangeRequest->id, $request->user());

        return redirect()
            ->route('rme.branch-change-requests.create')
            ->with('status', 'Permintaan perpindahan cabang dibatalkan.');
    }

    /**
     * The Super Admin queue.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', BranchChangeRequest::class);

        $clinicalDate = $this->daily->clinicalToday();

        // Housekeeping only. A stale row is refused by the service whether or
        // not this ran — see BranchChangeApprovalService::lockPendingRequest().
        $this->requests->expireStale($clinicalDate);

        return view('rme.branch-change-requests.index', [
            'pending' => $this->requests->pendingForDate($clinicalDate),
            'decided' => $this->requests->recentlyDecided(),
            'clinicalDate' => $clinicalDate,
        ]);
    }

    public function approve(
        DecideBranchChangeRequestRequest $request,
        BranchChangeRequest $branchChangeRequest,
    ): RedirectResponse {
        $this->authorize('decide', $branchChangeRequest);

        $approved = $this->approvals->approve(
            (int) $branchChangeRequest->id,
            $request->user(),
            $request->validated('decision_note'),
        );

        return redirect()
            ->route('rme.branch-change-requests.index')
            ->with('status', 'Permintaan disetujui. Cabang kerja pemohon dipindahkan ke '
                .($approved->destinationBranch?->name ?? 'cabang tujuan').'.');
    }

    public function reject(
        DecideBranchChangeRequestRequest $request,
        BranchChangeRequest $branchChangeRequest,
    ): RedirectResponse {
        $this->authorize('decide', $branchChangeRequest);

        $this->approvals->reject(
            (int) $branchChangeRequest->id,
            $request->user(),
            $request->validated('decision_note'),
        );

        return redirect()
            ->route('rme.branch-change-requests.index')
            ->with('status', 'Permintaan ditolak. Cabang kerja pemohon tidak berubah.');
    }
}
