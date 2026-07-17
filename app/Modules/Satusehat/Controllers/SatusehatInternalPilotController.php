<?php

namespace App\Modules\Satusehat\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Satusehat\Requests\InternalPilotActionRequest;
use App\Modules\Satusehat\Requests\ReadinessThresholdRequest;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotConfigurationService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotRehearsalService;
use App\Modules\Satusehat\Services\Pilot\SatusehatReadinessThresholdService;
use App\Modules\Satusehat\Support\SatusehatWorkspaceScope;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SATUSEHAT-4C — internal pilot lifecycle write actions.
 *
 * Every action is branch-scoped (the branch must be inside the actor's
 * server-resolved scope — never trusts a raw request branch id to cross a
 * boundary), policy-gated per least-privilege ability, and delegated to a
 * transactional+audited service. Nothing here ever enables external submission
 * or production; a rehearsal is credential-independent and network-silent.
 */
class SatusehatInternalPilotController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SatusehatPilotConfigurationService $config,
        private readonly SatusehatReadinessThresholdService $thresholds,
        private readonly SatusehatPilotRehearsalService $rehearsal,
        private readonly SatusehatBranchReadinessProfileService $profiles,
        private readonly BranchService $branches,
        private readonly SatusehatWorkspaceScope $scope,
    ) {}

    public function select(Request $request, int $branch): RedirectResponse
    {
        $model = $this->resolveBranch($request, $branch, 'configure');
        $this->config->selectCandidate($model, $request->user());

        return back()->with('status', 'Cabang dipilih sebagai kandidat pilot internal.');
    }

    public function approve(Request $request, int $branch): RedirectResponse
    {
        $model = $this->resolveBranch($request, $branch, 'approve');
        $this->config->approvePilot($model, $request->user());

        return back()->with('status', 'Cabang disetujui untuk pilot internal (INTERNAL GO). Kesiapan eksternal tetap terpisah.');
    }

    public function suspend(InternalPilotActionRequest $request, int $branch): RedirectResponse
    {
        $model = $this->resolveBranch($request, $branch, 'configure');
        $this->config->suspendPilot($model, (string) $request->validated('reason'), $request->user());

        return back()->with('status', 'Pilot cabang ditangguhkan.');
    }

    public function resume(Request $request, int $branch): RedirectResponse
    {
        $model = $this->resolveBranch($request, $branch, 'configure');
        $this->config->resumePilot($model, $request->user());

        return back()->with('status', 'Pilot cabang dilanjutkan. Perlu persetujuan ulang.');
    }

    public function setThresholds(ReadinessThresholdRequest $request, int $branch): RedirectResponse
    {
        $this->resolveBranch($request, $branch, 'configure');
        $profile = $this->profiles->profileFor($branch);
        $this->thresholds->setOverrides(
            $profile,
            (array) $request->validated('thresholds', []),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return back()->with('status', 'Ambang batas kesiapan cabang diperbarui.');
    }

    public function rehearse(InternalPilotActionRequest $request, int $branch): RedirectResponse
    {
        $model = $this->resolveBranch($request, $branch, 'rehearse');
        $dryRun = (bool) ($request->validated('dry_run') ?? false);

        $result = $this->rehearsal->run($model, $request->user(), $dryRun);

        return back()->with('status', sprintf(
            'Rehearsal pilot %s selesai — status akhir: %s (tidak ada pengiriman eksternal).',
            $dryRun ? '(dry-run)' : 'tercatat',
            $result['result'],
        ));
    }

    private function resolveBranch(Request $request, int $branch, string $ability): Branch
    {
        $branchIds = $this->scope->branchIdsFor($request->user());
        abort_unless(in_array($branch, $branchIds, true), 404);

        $profile = $this->profiles->profileFor($branch);
        $this->authorize($ability, $profile);

        $model = $this->branches->find($branch);
        abort_if($model === null, 404);

        return $model;
    }
}
