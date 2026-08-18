<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use App\Modules\LegacyRme\Requests\AssignLegacyRmeWaveOperatorRequest;
use App\Modules\LegacyRme\Requests\StoreLegacyRmeMigrationWaveRequest;
use App\Modules\LegacyRme\Requests\TransitionLegacyRmeMigrationWaveRequest;
use App\Modules\LegacyRme\Requests\UpdateLegacyRmeWaveBranchRequest;
use App\Modules\LegacyRme\Services\LegacyRmeMigrationOperationsService;
use App\Modules\LegacyRme\Services\LegacyRmeWaveGovernanceService;
use App\Modules\LegacyRme\Support\LegacyRmeBatchWindowRule;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LEGACY-RME-PDF-ROLL-4 — the migration operations control plane.
 *
 * THIN, as the enterprise baseline requires: authorize, delegate, redirect.
 * Every decision (transition legality, quota bounds, reconciliation, operator
 * eligibility, IDOR re-checks) lives in LegacyRmeWaveGovernanceService, so the
 * CLI reaches exactly the same rules.
 *
 * READ SURFACE IS COUNTS ONLY. No patient, no Nomor RM, no KTP/NIK, no filename,
 * no path — an operations dashboard is not somewhere clinical identity may leak.
 *
 * THE SURFACE FOLLOWS THE FEATURE FLAG, LIKE THE REST OF THE MODULE. With the
 * capability off the routes 404 rather than 403, so a disabled deployment does
 * not advertise that a migration control plane exists.
 */
class LegacyRmeMigrationOperationsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly LegacyRmeMigrationOperationsService $operations,
        private readonly LegacyRmeWaveGovernanceService $governance,
        private readonly LegacyRmeFeatureGuard $feature,
    ) {}

    public function index(): View
    {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('viewAny', LegacyRmeMigrationWave::class);

        return view('settings.rme.migration-operations.index', [
            'overview' => $this->operations->overview(),
            'waves' => $this->operations->waves(),
            // The form marks the batch window required only when the service
            // actually requires it. The rule itself is enforced server-side in
            // createWave(); this just stops the browser from demanding a field
            // a deployment has deliberately made optional.
            'batchWindowRequired' => LegacyRmeBatchWindowRule::requiredByPolicy(),
        ]);
    }

    public function show(LegacyRmeMigrationWave $wave): View
    {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('view', $wave);

        return view('settings.rme.migration-operations.show', [
            'wave' => $wave,
            'overview' => $this->operations->overview($wave),
            'branches' => $this->operations->enrolledBranches($wave),
            'operators' => $wave->operators()->with(['user', 'branch'])->orderBy('branch_code')->get(),
            'qaSample' => $this->operations->qaSample($wave),
        ]);
    }

    public function store(StoreLegacyRmeMigrationWaveRequest $request): RedirectResponse
    {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('create', LegacyRmeMigrationWave::class);

        /** @var User $actor */
        $actor = $request->user();

        $wave = $this->governance->createWave(
            actor: $actor,
            code: (string) $request->string('code'),
            name: (string) $request->string('name'),
            branchCodes: array_map('strval', (array) $request->input('branch_codes', [])),
            dailyQuota: $this->nullableInt($request, 'daily_quota'),
            perBranchDailyQuota: $this->nullableInt($request, 'per_branch_daily_quota'),
            plannedStartDate: $request->input('planned_start_date') ?: null,
            plannedEndDate: $request->input('planned_end_date') ?: null,
        );

        return redirect()
            ->route('settings.rme.migration-operations.show', $wave)
            ->with('success', sprintf('Gelombang migrasi %s terdaftar.', $wave->code));
    }

    /** Approve a wave — a separate permission from managing it. */
    public function approve(Request $request, LegacyRmeMigrationWave $wave): RedirectResponse
    {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('approve', $wave);

        /** @var User $actor */
        $actor = $request->user();

        $this->governance->approve($actor, $wave);

        return back()->with('success', sprintf('Gelombang migrasi %s disetujui.', $wave->code));
    }

    public function activate(Request $request, LegacyRmeMigrationWave $wave): RedirectResponse
    {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('update', $wave);

        /** @var User $actor */
        $actor = $request->user();

        $this->governance->activate($actor, $wave);

        return back()->with('success', sprintf('Gelombang migrasi %s dijalankan.', $wave->code));
    }

    /**
     * Pause, resume, drain, cancel or complete a wave.
     *
     * One endpoint with a closed action list rather than five near-identical
     * routes: they share authorization, the row lock and the audit shape, and
     * the transition machine is what actually distinguishes them.
     */
    public function transition(
        TransitionLegacyRmeMigrationWaveRequest $request,
        LegacyRmeMigrationWave $wave,
    ): RedirectResponse {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('update', $wave);

        /** @var User $actor */
        $actor = $request->user();
        $reason = (string) $request->input('reason', '');

        match ((string) $request->input('action')) {
            'pause' => $this->governance->pause($actor, $wave, $reason),
            'resume' => $this->governance->resume($actor, $wave),
            'drain' => $this->governance->drain($actor, $wave, $reason),
            'cancel' => $this->governance->cancelWave($actor, $wave, $reason),
            'complete' => $this->governance->completeWave($actor, $wave, $reason),
            default => null,
        };

        return back()->with('success', sprintf('Status gelombang migrasi %s diperbarui.', $wave->code));
    }

    /** Change one branch's quota, plan or state inside a wave. */
    public function updateBranch(
        UpdateLegacyRmeWaveBranchRequest $request,
        LegacyRmeMigrationWave $wave,
        LegacyRmeWaveBranch $branch,
    ): RedirectResponse {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('update', $wave);

        // IDOR boundary: a branch id from the URL is only ever operated on when
        // it genuinely belongs to the wave in the same URL.
        abort_unless((int) $branch->wave_id === (int) $wave->getKey(), 404);

        /** @var User $actor */
        $actor = $request->user();
        $reason = (string) $request->input('reason', '');

        match ((string) $request->input('action')) {
            'quota' => $this->governance->setBranchQuota(
                $actor,
                $branch,
                $this->nullableInt($request, 'daily_quota'),
                $this->nullableInt($request, 'planned_document_count'),
            ),
            'pause' => $this->governance->transitionBranch($actor, $branch, LegacyRmeWaveBranchStatus::PAUSED, $reason),
            'resume' => $this->governance->transitionBranch($actor, $branch, LegacyRmeWaveBranchStatus::ACTIVE),
            'drain' => $this->governance->transitionBranch($actor, $branch, LegacyRmeWaveBranchStatus::DRAINING, $reason),
            'cancel' => $this->governance->transitionBranch($actor, $branch, LegacyRmeWaveBranchStatus::CANCELLED, $reason),
            'complete' => $this->governance->completeBranch($actor, $branch, $reason),
            default => null,
        };

        return back()->with('success', sprintf('Cabang %s diperbarui.', $branch->branch_code));
    }

    public function assignOperator(
        AssignLegacyRmeWaveOperatorRequest $request,
        LegacyRmeMigrationWave $wave,
    ): RedirectResponse {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('update', $wave);

        /** @var User $actor */
        $actor = $request->user();

        /** @var LegacyRmeWaveBranch $branch */
        $branch = LegacyRmeWaveBranch::query()->findOrFail((int) $request->integer('wave_branch_id'));
        abort_unless((int) $branch->wave_id === (int) $wave->getKey(), 404);

        /** @var User $operator */
        $operator = User::query()->findOrFail((int) $request->integer('user_id'));

        $this->governance->assignOperator($actor, $wave, $operator, $branch);

        return back()->with('success', 'Operator migrasi ditugaskan.');
    }

    public function revokeOperator(
        Request $request,
        LegacyRmeMigrationWave $wave,
        LegacyRmeWaveOperator $assignment,
    ): RedirectResponse {
        abort_unless($this->feature->migrationEnabled(), 404);
        $this->authorize('update', $wave);

        abort_unless((int) $assignment->wave_id === (int) $wave->getKey(), 404);

        /** @var User $actor */
        $actor = $request->user();

        $this->governance->revokeOperator($actor, $assignment);

        return back()->with('success', 'Penugasan operator dicabut.');
    }

    /**
     * A blank numeric field means "no ceiling declared" (NULL), not zero (a
     * ceiling that admits nothing). `$request->integer()` casts '' to 0, which
     * would silently close a branch, so the distinction is made explicitly.
     */
    private function nullableInt(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        return ($value === null || $value === '') ? null : (int) $value;
    }
}
