<?php

namespace App\Support\AccessControl;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Support\Carbon;

/**
 * SUNU-GO-LIVE-READINESS-1 — read-only audit of the Front Office branch pin.
 *
 * WHY THIS EXISTS.
 *
 * `front_office.branch_context_lock` and `front_office.branch_device_lock` are
 * both CRITICAL-risk capabilities whose scope lives in an environment string
 * (`FRONT_OFFICE_BRANCH_DEVICE_LOCK_COHORT`). Before this auditor the only ways
 * to answer "who is actually armed, and to which branch?" on a running host were
 * to read that string and reason about it, or to open a REPL on production. The
 * first is inference, not measurement; the second is forbidden here. Every
 * sibling capability in this codebase (Admin-Lab role scope, doctor performance
 * access, lab pilot readiness) already has a read-only audit command, and this
 * one did not.
 *
 * WHAT IT MEASURES, AND WHY EACH COLUMN IS PRESENT.
 *
 * The cohort string is reported through `FrontOfficeBranchDeviceCohort`, never
 * re-parsed here — a second parser would be a second source of truth and could
 * disagree with the one that actually enforces.
 *
 * For every Front Office account it then records THREE independently resolved
 * branches:
 *
 *   - `pinned_branch_id`            — `FrontOfficeBranchPinResolver`
 *   - `branch_context_branch_id`    — `BranchContext::forUser()`
 *   - `operational_branch_id`       — `UserOnlineContextService::resolveActiveBranchForAdmin()`,
 *                                     the branch a NEW CLINIC VISIT is registered at
 *
 * They are compared rather than assumed equal because they have disagreed
 * before: REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 closed a split brain in
 * which an armed account holding an online-context row selected BEFORE it was
 * armed resolved to its pinned branch everywhere `BranchContext` was consulted,
 * while registration still followed the stale row. A test proves that is closed
 * in the code; this proves it is closed on the host that is actually running.
 *
 * NOTHING IS WRITTEN. No flag is set, no cohort entry is added, no session is
 * touched, no context is started. The report is derived entirely from
 * configuration plus already-persisted rows.
 *
 * PRIVACY. Staff account names only — the same operational labels the other
 * access-control audits print. No patient data is reachable from here at all.
 */
class FrontOfficeBranchPinAuditor
{
    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly FrontOfficeBranchDeviceCohort $cohort,
        private readonly FrontOfficeBranchPinResolver $pin,
        private readonly BranchContext $branchContext,
        private readonly UserOnlineContextService $onlineContexts,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $contextFlag = $this->flags->enabled(FrontOfficeBranchPinResolver::FLAG);
        $deviceFlag = $this->flags->enabled(FrontOfficeBranchDeviceLockService::FLAG);

        $requiredRole = (string) config('front_office_device_lock.policy.required_role', 'Front Office');
        $maxCohortSize = (int) config('front_office_device_lock.policy.max_cohort_size', 4);

        $accounts = [];
        $anomalies = [];

        foreach ($this->frontOfficeUsers($requiredRole) as $user) {
            $userId = (int) $user->id;

            $armed = $this->cohort->covers($userId);
            $requiredCode = $this->cohort->requiredBranchCodeFor($userId);

            $pinned = $this->pin->requiredBranchIdFor($user);
            $applies = $this->pin->appliesTo($user);
            $misconfigured = $this->pin->isMisconfiguredFor($user);

            $contextBranch = $this->branchContext->forUser($user);
            $operationalBranch = $this->onlineContexts->resolveActiveBranchForAdmin($user);

            /*
             * AGREEMENT IS ONLY MEANINGFUL FOR A PINNED ACCOUNT.
             *
             * An unarmed account legitimately resolves through the ordinary
             * tiers, and `resolveActiveBranchForAdmin()` is legitimately NULL
             * for anyone who is simply offline. Comparing those would
             * manufacture anomalies out of normal states, so the comparison is
             * scoped to accounts the pin actually governs and, for the
             * operational resolver, to sessions that actually have a branch.
             */
            $contextAgrees = ! $applies || $contextBranch === $pinned;
            $operationalAgrees = ! $applies
                || $operationalBranch === null
                || $operationalBranch === $pinned;

            $accounts[] = [
                'user_id' => $userId,
                'name' => (string) $user->name,
                'is_active' => (bool) $user->is_active,
                'armed' => $armed,
                'pin_applies' => $applies,
                'required_branch_code' => $requiredCode,
                'pinned_branch_id' => $pinned,
                'pinned_branch_code' => $this->branchCode($pinned),
                'branch_context_branch_id' => $contextBranch,
                'operational_branch_id' => $operationalBranch,
                'misconfigured' => $misconfigured,
                'branch_context_agrees' => $contextAgrees,
                'operational_branch_agrees' => $operationalAgrees,
                'device_proof_required' => $deviceFlag && $applies,
            ];

            if ($misconfigured) {
                $anomalies[] = "user {$userId} is armed but resolves to NO branch (fails closed)";
            }

            if (! $contextAgrees) {
                $anomalies[] = "user {$userId} SPLIT BRAIN: BranchContext="
                    .var_export($contextBranch, true).' but pin='.var_export($pinned, true);
            }

            if (! $operationalAgrees) {
                $anomalies[] = "user {$userId} SPLIT BRAIN: registration branch="
                    .var_export($operationalBranch, true).' but pin='.var_export($pinned, true);
            }
        }

        foreach ($this->cohort->configErrors() as $error) {
            $anomalies[] = 'cohort config: '.$error;
        }

        if ($this->cohort->isOversized()) {
            $anomalies[] = 'cohort is OVERSIZED (> '.$maxCohortSize.') and fails closed for every armed account';
        }

        foreach ($this->cohort->ambiguousUserIds() as $ambiguousId) {
            $anomalies[] = "user {$ambiguousId} has an ambiguous cohort entry and resolves to NO branch";
        }

        /*
         * An armed id that is not a Front Office account never appears in the
         * account rows above, so it would otherwise vanish from the report
         * entirely — the one state where "nothing is listed" could be mistaken
         * for "nothing is wrong".
         */
        $frontOfficeIds = array_column($accounts, 'user_id');

        foreach (array_keys($this->cohort->armed()) as $armedId) {
            if (! in_array((int) $armedId, $frontOfficeIds, true)) {
                $anomalies[] = "armed id {$armedId} is not an active {$requiredRole} account and is NOT pinned";
            }
        }

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'pinning' => [
                'context_flag' => $contextFlag,
                'device_flag' => $deviceFlag,
                'pinning_enabled' => $contextFlag || $deviceFlag,
                'device_proof_required_for_armed' => $deviceFlag,
            ],
            'cohort' => [
                'armed' => $this->cohort->armed(),
                'armed_count' => count($this->cohort->armed()),
                'max_cohort_size' => $maxCohortSize,
                'oversized' => $this->cohort->isOversized(),
                'empty' => $this->cohort->isEmpty(),
                'ambiguous_user_ids' => $this->cohort->ambiguousUserIds(),
                'config_errors' => $this->cohort->configErrors(),
                'allowed_branch_codes' => (array) config('front_office_device_lock.policy.allowed_branch_codes', []),
                'required_role' => $requiredRole,
            ],
            'accounts' => $accounts,
            'anomalies' => $anomalies,
            'decision' => $anomalies === [] ? 'GO' : 'FAIL',
        ];
    }

    /**
     * @return iterable<int, User>
     */
    private function frontOfficeUsers(string $requiredRole): iterable
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', $requiredRole))
            ->orderBy('id')
            ->get();
    }

    private function branchCode(?int $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        return $this->branches->findById($branchId)?->code;
    }
}
