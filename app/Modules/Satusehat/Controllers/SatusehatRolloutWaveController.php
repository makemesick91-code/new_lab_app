<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Requests\SatusehatRolloutWaveRequest;
use App\Modules\Satusehat\Services\Pilot\SatusehatMultiBranchRehearsalService;
use App\Modules\Satusehat\Services\Pilot\SatusehatRolloutWaveService;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SATUSEHAT-4D — rollout wave lifecycle (governance). Every write delegates to
 * the transactional+audited wave service; branch enrollment is validated against
 * the actor's server-resolved RME scope. Nothing enables external send/production.
 */
class SatusehatRolloutWaveController extends Controller
{
    public function __construct(
        private readonly SatusehatRolloutWaveService $waves,
        private readonly SatusehatMultiBranchRehearsalService $rehearsal,
        private readonly BranchService $branches,
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function index(): View
    {
        $env = (string) config('satusehat.environment');
        $waves = SatusehatRolloutWave::query()
            ->where('environment', $env)
            ->withCount(['activeMemberships as enrolled_branches'])
            ->orderBy('sequence')->orderByDesc('id')
            ->paginate(25);

        return view('satusehat.waves.index', ['waves' => $waves]);
    }

    public function show(int $wave): View
    {
        $model = $this->findWave($wave);
        $model->load(['activeMemberships.branch']);

        return view('satusehat.waves.show', ['wave' => $model]);
    }

    public function store(SatusehatRolloutWaveRequest $request): RedirectResponse
    {
        $this->waves->createWave($request->validated(), $request->user());

        return redirect()->route('satusehat.waves.index')->with('status', 'Wave rollout dibuat (draf).');
    }

    public function enroll(SatusehatRolloutWaveRequest $request, int $wave): RedirectResponse
    {
        $model = $this->findWave($wave);
        $branchId = (int) $request->validated('branch_id');
        abort_unless(in_array($branchId, $this->scope->branchIdsFor($request->user()), true), 404);

        $branch = $this->branches->find($branchId);
        abort_if($branch === null, 404);

        $this->waves->enrollBranch($model, $branch, $request->user());

        return back()->with('status', 'Cabang didaftarkan ke wave.');
    }

    public function removeBranch(SatusehatRolloutWaveRequest $request, int $wave): RedirectResponse
    {
        $model = $this->findWave($wave);
        $branchId = (int) $request->validated('branch_id');
        abort_unless(in_array($branchId, $this->scope->branchIdsFor($request->user()), true), 404);
        $branch = $this->branches->find($branchId);
        abort_if($branch === null, 404);

        $this->waves->removeBranch($model, $branch, (string) $request->validated('reason'), $request->user());

        return back()->with('status', 'Cabang dikeluarkan dari wave.');
    }

    public function approve(Request $request, int $wave): RedirectResponse
    {
        $this->waves->approveWave($this->findWave($wave), $request->user());

        return back()->with('status', 'Wave disetujui (kesiapan eksternal tetap terpisah).');
    }

    public function changeStatus(SatusehatRolloutWaveRequest $request, int $wave): RedirectResponse
    {
        $this->waves->changeStatus($this->findWave($wave), (string) $request->validated('status'), $request->user());

        return back()->with('status', 'Status wave diperbarui.');
    }

    public function suspend(SatusehatRolloutWaveRequest $request, int $wave): RedirectResponse
    {
        $this->waves->suspendWave($this->findWave($wave), (string) $request->validated('reason'), $request->user());

        return back()->with('status', 'Wave ditangguhkan.');
    }

    public function resume(Request $request, int $wave): RedirectResponse
    {
        $this->waves->resumeWave($this->findWave($wave), $request->user());

        return back()->with('status', 'Wave dilanjutkan.');
    }

    public function close(Request $request, int $wave): RedirectResponse
    {
        $this->waves->closeWave($this->findWave($wave), $request->user());

        return back()->with('status', 'Wave ditutup.');
    }

    public function rehearse(Request $request, int $wave): RedirectResponse
    {
        $result = $this->rehearsal->run($this->findWave($wave), $request->user(), true);

        return back()->with('status', sprintf(
            'Rehearsal multi-cabang (dry-run) selesai — status: %s (tanpa pengiriman eksternal).',
            $result['final_wave_state'],
        ));
    }

    private function findWave(int $wave): SatusehatRolloutWave
    {
        $model = SatusehatRolloutWave::query()
            ->where('environment', (string) config('satusehat.environment'))
            ->find($wave);
        abort_if($model === null, 404);

        return $model;
    }
}
