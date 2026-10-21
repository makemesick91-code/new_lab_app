<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\RmeInvoice\Interfaces\RmeInvoiceCorrectionRepositoryInterface;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPilotScope;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Services\Foundation\FeatureFlagService;
use App\Support\AccessControl\FrontOfficeBranchPinResolver;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2 (rule 195) — read-only
 * activation readiness of the pre-payment invoice correction.
 *
 * It answers, from the SAME authorities the service enforces (flag guard, pilot
 * allowlist, effective permissions, working branch scope), without logging in
 * and without a REPL:
 *   - is the capability DORMANT (flag off) or PILOT_ACTIVE, and where;
 *   - does every role hold exactly the correction permissions rule 195 grants
 *     (D1 + the owner's Front Office decision; no separate apply grant, D1a);
 *   - for named accounts: may they request / approve inside the pilot;
 *   - for a maker/checker pair: are they two distinct accounts that can each
 *     perform their half in a pilot branch;
 *   - are there active corrections (they block payment, D10) and where.
 *
 * WRITES NOTHING. Resolving an operator's working branch can reach code that
 * lazily expires a stale online-context row (rule 165 item 6a), so that part
 * runs inside a transaction that is ALWAYS rolled back — never DB::transaction(),
 * which commits on success. Counts only; no patient field, amount of a named
 * patient or credential is ever reported.
 */
class RmeInvoiceCorrectionReadinessService
{
    public const GO = 'GO';

    public const WATCH = 'WATCH';

    public const FAIL = 'FAIL';

    public const STATE_DORMANT = 'DORMANT';

    public const STATE_PILOT_ACTIVE = 'PILOT_ACTIVE';

    /**
     * Rule 195 — the exact correction permissions each role must hold. Every
     * other role must hold none. Super Admin reaches all three through the
     * RoleSeeder wildcard. `apply_rme_invoice_correction` is granted to NO
     * operational role: under owner D1a the checker's approval applies.
     *
     * @var array<string, list<string>>
     */
    public const EXPECTED_ROLE_GRANTS = [
        'Super Admin' => [
            RmeInvoiceCorrectionPermission::REQUEST,
            RmeInvoiceCorrectionPermission::APPROVE,
            RmeInvoiceCorrectionPermission::APPLY,
        ],
        'Kasir' => [RmeInvoiceCorrectionPermission::REQUEST],
        'Admin Klinik' => [RmeInvoiceCorrectionPermission::APPROVE],
        'Front Office' => [
            RmeInvoiceCorrectionPermission::REQUEST,
            RmeInvoiceCorrectionPermission::APPROVE,
        ],
    ];

    public function __construct(
        private readonly RmeInvoiceCorrectionFeatureGuard $guard,
        private readonly FeatureFlagService $flags,
        private readonly RmeInvoiceCorrectionPilotScope $pilot,
        private readonly BranchService $branches,
        private readonly RmeWorkingBranchScope $workingScope,
        private readonly RmeInvoiceCorrectionRepositoryInterface $corrections,
        private readonly FrontOfficeBranchPinResolver $frontOfficePins,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<string, mixed>
     */
    public function report(array $userIds = [], ?int $makerId = null, ?int $checkerId = null): array
    {
        $findings = [];
        $enabled = $this->guard->enabled();
        $flag = $this->flagState();

        $branchCodes = [];
        foreach ($this->branches->listRmeEnabled() as $branch) {
            $branchCodes[(int) $branch->id] = (string) $branch->code;
        }

        $declared = $this->pilot->declaredCodes();
        $admittedIds = $this->pilot->admittedBranchIds();
        $admittedCodes = array_values(array_map(fn (int $id) => $branchCodes[$id] ?? (string) $id, $admittedIds));
        $unresolved = array_values(array_diff($declared, $admittedCodes));

        foreach ($unresolved as $code) {
            $findings[] = ['level' => self::FAIL, 'code' => 'PILOT_CODE_UNRESOLVED', 'detail' => $code];
        }

        if ($enabled && $admittedIds === []) {
            $findings[] = ['level' => self::WATCH, 'code' => 'FLAG_ON_NO_PILOT_BRANCH', 'detail' => 'flag ON but the allowlist admits no branch: inert'];
        }

        $roles = $this->roleGrants($findings);

        $active = $this->corrections->activeCountByBranch();
        $activeOutsidePilot = 0;
        foreach ($active as $branchId => $count) {
            if (! in_array((int) $branchId, $admittedIds, true)) {
                $activeOutsidePilot += $count;
            }
        }
        if ($activeOutsidePilot > 0) {
            $findings[] = ['level' => self::WATCH, 'code' => 'ACTIVE_CORRECTION_OUTSIDE_PILOT', 'detail' => (string) $activeOutsidePilot];
        }

        $users = [];
        foreach (array_values(array_unique(array_merge($userIds, array_filter([$makerId, $checkerId])))) as $id) {
            $users[(int) $id] = $this->userCapability((int) $id, $admittedIds, $branchCodes);
        }

        $pair = null;
        if ($makerId !== null || $checkerId !== null) {
            $pair = $this->pair($makerId, $checkerId, $users, $findings);
        }

        $decision = self::GO;
        foreach ($findings as $finding) {
            if ($finding['level'] === self::FAIL) {
                $decision = self::FAIL;
                break;
            }
            if ($finding['level'] === self::WATCH) {
                $decision = self::WATCH;
            }
        }

        return [
            'state' => $enabled && $admittedIds !== [] ? self::STATE_PILOT_ACTIVE : self::STATE_DORMANT,
            'flag' => $flag,
            'pilot' => [
                'declared_codes' => $declared,
                'admitted_branch_codes' => $admittedCodes,
                'unresolved_codes' => $unresolved,
                'source' => 'config/rme_invoice_correction.php (source-controlled, no environment read)',
            ],
            'application_authority' => 'AUTO_ON_APPROVAL (owner D1a): the checker\'s approval applies the correction in the same transaction',
            'roles' => $roles,
            'active_corrections_by_branch' => $this->byCode($active, $branchCodes),
            'positive_unpaid_unsettled_invoices_in_pilot' => $this->corrections->positiveUnpaidUnsettledInvoiceCount($admittedIds),
            'users' => array_values($users),
            'maker_checker_pair' => $pair,
            'findings' => $findings,
            'decision' => $decision,
        ];
    }

    /**
     * @return array{enabled: bool, env_resolution: string|null}
     */
    private function flagState(): array
    {
        try {
            $hydrated = $this->flags->get(RmeInvoiceCorrectionFeatureGuard::FLAG);
        } catch (Throwable) {
            $hydrated = null;
        }

        return [
            'enabled' => $this->guard->enabled(),
            'env_resolution' => is_array($hydrated) ? ($hydrated['env_resolution'] ?? null) : null,
        ];
    }

    /**
     * @param  list<array{level: string, code: string, detail: string}>  $findings
     * @return array<string, list<string>>
     */
    private function roleGrants(array &$findings): array
    {
        $grants = [];

        foreach (Role::query()->with('permissions:id,name')->orderBy('name')->get() as $role) {
            $held = $role->permissions
                ->pluck('name')
                ->intersect(RmeInvoiceCorrectionPermission::OPERATIONAL)
                ->sort()
                ->values()
                ->all();

            $expected = self::EXPECTED_ROLE_GRANTS[$role->name] ?? [];
            sort($expected);

            if ($held !== $expected) {
                $findings[] = [
                    'level' => self::FAIL,
                    'code' => 'ROLE_GRANT_DRIFT',
                    'detail' => sprintf('%s holds [%s], expected [%s]', $role->name, implode(',', $held), implode(',', $expected)),
                ];
            }

            if ($held !== [] || isset(self::EXPECTED_ROLE_GRANTS[$role->name])) {
                $grants[$role->name] = $held;
            }
        }

        foreach (array_keys(self::EXPECTED_ROLE_GRANTS) as $name) {
            if (! isset($grants[$name])) {
                $findings[] = ['level' => self::FAIL, 'code' => 'ROLE_MISSING', 'detail' => $name];
            }
        }

        return $grants;
    }

    /**
     * @param  list<int>  $admittedIds
     * @param  array<int, string>  $branchCodes
     * @return array<string, mixed>
     */
    private function userCapability(int $id, array $admittedIds, array $branchCodes): array
    {
        $user = User::query()->find($id);

        if ($user === null) {
            return ['id' => $id, 'exists' => false];
        }

        $scope = $this->scopeWithoutPersisting($user);
        $inPilot = array_values(array_intersect($scope, $admittedIds));
        $contextBound = $this->workingScope->isContextBound($user);
        $eligible = $this->eligiblePilotBranches($user, $contextBound, $inPilot, $admittedIds);
        $codes = fn (array $ids) => array_values(array_map(fn (int $b) => $branchCodes[$b] ?? (string) $b, $ids));

        return [
            'id' => $id,
            'exists' => true,
            'active' => (bool) ($user->is_active ?? true),
            'roles' => $user->getRoleNames()->values()->all(),
            'can_request' => $user->can(RmeInvoiceCorrectionPermission::REQUEST),
            'can_approve' => $user->can(RmeInvoiceCorrectionPermission::APPROVE),
            'can_apply_recovery' => $user->can(RmeInvoiceCorrectionPermission::APPLY),
            'working_scope' => array_values(array_map(fn (int $b) => $branchCodes[$b] ?? (string) $b, $scope)),
            'pilot_branches_in_scope' => array_values(array_map(fn (int $b) => $branchCodes[$b] ?? (string) $b, $inPilot)),
            // LIVE: what the account may do in the pilot RIGHT NOW (its current
            // working scope). A context-bound account that is not logged in has
            // an empty scope, so this is false for every off-shift operator.
            'may_request_in_pilot' => $user->can(RmeInvoiceCorrectionPermission::REQUEST) && $inPilot !== [],
            'may_approve_in_pilot' => $user->can(RmeInvoiceCorrectionPermission::APPROVE) && $inPilot !== [],
            'context_bound' => $contextBound,
            'on_shift_in_pilot' => $inPilot !== [],
            // ELIGIBLE: what the account may do in the pilot once it is on shift.
            // This — never the live scope — decides whether the pair is valid.
            'eligible_pilot_branches' => $codes($eligible),
            'may_request_when_on_shift' => $user->can(RmeInvoiceCorrectionPermission::REQUEST) && $eligible !== [],
            'may_approve_when_on_shift' => $user->can(RmeInvoiceCorrectionPermission::APPROVE) && $eligible !== [],
        ];
    }

    /**
     * The pilot branches this account may act in ONCE IT IS ON SHIFT.
     *
     * A context-bound account (Front Office, Admin Klinik, Kasir, Perawat) works
     * from ONE branch it selects at login, so its live scope is empty whenever it
     * is not logged in. Reporting that as "cannot act in the pilot" names an
     * authorization defect where there is only an operator who is off shift —
     * the false FAIL production showed on 2026-10-21 for the owner-designated
     * maker. Eligibility is therefore derived from what the account COULD
     * select, through the same authorities the login path uses, never guessed:
     *
     *  - not context-bound (Super Admin, Owner, Supervisor RME): the live scope
     *    does not depend on a shift — eligibility is the live answer;
     *  - pinned (FrontOfficeBranchPinResolver): exactly the pinned branch, when
     *    it is in the pilot. An armed but UNDECIDABLE pin admits NOTHING — the
     *    pin fails closed, so this does too;
     *  - context-bound and unpinned: any active RME-enabled pilot branch, which
     *    is what the online-context selector offers at login.
     *
     * @param  list<int>  $inPilot
     * @param  list<int>  $admittedIds
     * @return list<int>
     */
    private function eligiblePilotBranches(User $user, bool $contextBound, array $inPilot, array $admittedIds): array
    {
        if (! $contextBound) {
            return $inPilot;
        }

        if ($this->frontOfficePins->appliesTo($user)) {
            $pinned = $this->frontOfficePins->requiredBranchIdFor($user);

            return $pinned === null ? [] : array_values(array_intersect([$pinned], $admittedIds));
        }

        return $admittedIds;
    }

    /**
     * @param  array<int, array<string, mixed>>  $users
     * @param  list<array{level: string, code: string, detail: string}>  $findings
     * @return array<string, mixed>
     */
    private function pair(?int $makerId, ?int $checkerId, array $users, array &$findings): array
    {
        $maker = $makerId !== null ? ($users[$makerId] ?? null) : null;
        $checker = $checkerId !== null ? ($users[$checkerId] ?? null) : null;

        $distinct = $makerId !== null && $checkerId !== null && $makerId !== $checkerId;
        // Validity is decided by ELIGIBILITY (could act once on shift); being on
        // shift right now is reported separately, as a WATCH (see userCapability).
        $makerOk = is_array($maker) && ($maker['exists'] ?? false) && ($maker['active'] ?? false) && ($maker['may_request_when_on_shift'] ?? false);
        $checkerOk = is_array($checker) && ($checker['exists'] ?? false) && ($checker['active'] ?? false) && ($checker['may_approve_when_on_shift'] ?? false);
        $shared = $makerOk && $checkerOk
            ? array_values(array_intersect((array) $maker['eligible_pilot_branches'], (array) $checker['eligible_pilot_branches']))
            : [];
        $sharedLive = $makerOk && $checkerOk && ($maker['may_request_in_pilot'] ?? false) && ($checker['may_approve_in_pilot'] ?? false)
            ? array_values(array_intersect((array) $maker['pilot_branches_in_scope'], (array) $checker['pilot_branches_in_scope']))
            : [];

        if (! $distinct) {
            $findings[] = ['level' => self::FAIL, 'code' => 'PAIR_NOT_DISTINCT_ACCOUNTS', 'detail' => 'maker and checker must be two different accounts'];
        }
        if (! $makerOk) {
            $findings[] = ['level' => self::FAIL, 'code' => 'PAIR_MAKER_CANNOT_REQUEST_IN_PILOT', 'detail' => (string) $makerId];
        }
        if (! $checkerOk) {
            $findings[] = ['level' => self::FAIL, 'code' => 'PAIR_CHECKER_CANNOT_APPROVE_IN_PILOT', 'detail' => (string) $checkerId];
        }
        if ($makerOk && $checkerOk && $shared === []) {
            $findings[] = ['level' => self::FAIL, 'code' => 'PAIR_NO_SHARED_PILOT_BRANCH', 'detail' => 'no pilot branch both accounts may act in'];
        }
        if ($makerOk && ! ($maker['may_request_in_pilot'] ?? false)) {
            $findings[] = ['level' => self::WATCH, 'code' => 'PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT', 'detail' => $makerId.' is eligible for '.implode(',', (array) $maker['eligible_pilot_branches']).' but holds no working context there now'];
        }
        if ($checkerOk && ! ($checker['may_approve_in_pilot'] ?? false)) {
            $findings[] = ['level' => self::WATCH, 'code' => 'PAIR_CHECKER_NOT_ON_SHIFT_IN_PILOT', 'detail' => $checkerId.' is eligible for '.implode(',', (array) $checker['eligible_pilot_branches']).' but holds no working context there now'];
        }

        return [
            'maker_id' => $makerId,
            'checker_id' => $checkerId,
            'distinct_accounts' => $distinct,
            'maker_may_request_in_pilot' => $makerOk,
            'checker_may_approve_in_pilot' => $checkerOk,
            'shared_pilot_branches' => $shared,
            'valid' => $distinct && $makerOk && $checkerOk && $shared !== [],
            // Both accounts on shift, right now, in a pilot branch they share.
            'live_now' => $distinct && $sharedLive !== [],
        ];
    }

    /**
     * @return list<int>
     */
    private function scopeWithoutPersisting(User $user): array
    {
        DB::beginTransaction();

        try {
            return array_values(array_map('intval', $this->workingScope->branchIdsFor($user)));
        } catch (Throwable) {
            return [];
        } finally {
            // ROLLBACK ALWAYS — never commit, not even on the happy path.
            DB::rollBack();
        }
    }

    /**
     * @param  array<int, int>  $counts
     * @param  array<int, string>  $branchCodes
     * @return array<string, int>
     */
    private function byCode(array $counts, array $branchCodes): array
    {
        $out = [];
        foreach ($counts as $branchId => $count) {
            $out[$branchCodes[(int) $branchId] ?? ('#'.$branchId)] = (int) $count;
        }
        ksort($out);

        return $out;
    }
}
