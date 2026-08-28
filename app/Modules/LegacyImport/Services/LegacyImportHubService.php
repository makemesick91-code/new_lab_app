<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Services\LegacyRmeActivationStateService;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1 — what the hub page reports, computed server-side.
 *
 * THE PAGE IS A REPORT, NOT AN AUTHORITY. Every number here is read-only and
 * advisory. The ceiling that actually admits a record is
 * {@see LegacyImportDailyQuotaService::reserve()}, inside the transaction that
 * writes it; by the time a viewer reads this page another operator may already
 * have taken the slot it shows as free. Nothing may be decided from this output.
 *
 * "ACTIVE" IS REPORTED HONESTLY. A capability is only called active when its
 * feature flag is on, its route is registered, AND every further gate that
 * governs it is currently open. Legacy RME carries two more — ROLL-3 branch
 * admission and a matching ACTIVE ROLL-4 wave — and they are EVALUATED here
 * (FEATURE-LEGACY-IMPORT-HUB-1A) rather than merely disclaimed.
 *
 * That distinction is the whole point. The first version of this page shipped a
 * hard-coded "there are other gates" caveat, which read identically whether
 * those gates were wide open or completely shut — and production then ran for a
 * release with the capability ON, no branch admitted and no wave, while this
 * page reported "Aktif". Claiming "Aktif" for a surface that will refuse every
 * upload is exactly the failure this page exists to prevent, so a permanently
 * true caveat could not be the answer: only a state can be.
 *
 * THE EVALUATION IS BORROWED, NOT REBUILT. {@see LegacyRmeActivationStateService}
 * composes the services that already decide admission and wave state; this class
 * adds no rule of its own and remains a report with no authority.
 *
 * PII POLICY. Counts, limits, branch codes and labels. Never a patient name, a
 * Nomor RM, a KTP/NIK, a filename or a document path.
 */
class LegacyImportHubService
{
    /**
     * Holding any of these means the operator governs legacy imports across the
     * whole RME branch set rather than a single clinic. The union of the two
     * archive capabilities' own governance sets — this page invents no new
     * authority, it only reads the ones that already exist.
     *
     * @var list<string>
     */
    public const GOVERNANCE_PERMISSIONS = [
        'review_legacy_rme_imports',
        'publish_legacy_rme_imports',
        'void_legacy_rme_imports',
        'review_legacy_odontogram_imports',
        'publish_legacy_odontogram_imports',
        'void_legacy_odontogram_records',
    ];

    public function __construct(
        private readonly LegacyImportDailyQuotaService $quota,
        private readonly BranchService $branches,
        private readonly BranchContext $context,
        private readonly FeatureFlagService $flags,
        private readonly LegacyRmeActivationStateService $legacyRmeActivation,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('legacy_import_hub.enabled', true);
    }

    /**
     * The branches whose counters this actor may see.
     *
     * Governance tier sees the whole RME branch set; anyone else sees the single
     * branch their server-resolved context puts them in, and only when that
     * branch is RME-enabled. An empty result denies rather than falling back to
     * "all" — the failure mode of a scope resolver must be less access, never
     * more.
     *
     * @return list<int>
     */
    public function branchIdsFor(User $user): array
    {
        $all = $this->branches->rmeEnabledIds();

        if ($user->canAny(self::GOVERNANCE_PERMISSIONS)) {
            return $all;
        }

        $own = $this->context->forUser($user);

        return $own !== null && in_array((int) $own, $all, true) ? [(int) $own] : [];
    }

    /**
     * Does this actor have any reason to be on the hub page at all?
     */
    public function isReachableBy(User $user): bool
    {
        foreach ($this->typeKeys() as $type) {
            if ($user->canAny($this->permissionsFor($type))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole page, in one pass.
     *
     * @return array{
     *     clinical_date: string,
     *     timezone: string,
     *     branches: list<array{id:int, code:string, name:string}>,
     *     types: list<array<string, mixed>>
     * }
     */
    public function overview(User $user): array
    {
        $branchIds = $this->branchIdsFor($user);

        /** @var list<Branch> $branches */
        $branches = $branchIds === []
            ? []
            : Branch::query()
                ->whereIn('id', $branchIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->all();

        $types = $this->typeKeys();

        // ONE query for every (type, branch) pair on the page, so adding a
        // branch cannot turn this into an N+1.
        $matrix = $branchIds === []
            ? []
            : $this->quota->consumedMatrixToday($types, $branchIds);

        // Evaluated ONCE for the page, not once per card and never once per
        // branch row: the admission/wave state is deployment-wide, and the
        // per-branch verdicts are resolved in a single pass inside the state
        // service. Recomputing it per card would multiply queries for an
        // answer that cannot differ between them.
        $legacyRmeState = $this->legacyRmeActivationState($branches);

        $cards = [];

        foreach ($types as $type) {
            $cards[] = $this->card($user, $type, $branches, $matrix, $legacyRmeState);
        }

        return [
            'clinical_date' => $this->quota->today()->toDateString(),
            'timezone' => $this->quota->timezone(),
            'branches' => array_map(
                static fn (Branch $branch): array => [
                    'id' => (int) $branch->id,
                    'code' => (string) $branch->code,
                    'name' => (string) $branch->name,
                ],
                $branches,
            ),
            'types' => $cards,
        ];
    }

    /**
     * @param  list<Branch>  $branches
     * @param  array<string, int>  $matrix
     * @param  array<string, mixed>|null  $legacyRmeState  evaluated once by the
     *                                                     caller; null for types
     *                                                     that carry no extra gates
     * @return array<string, mixed>
     */
    private function card(
        User $user,
        string $type,
        array $branches,
        array $matrix,
        ?array $legacyRmeState = null,
    ): array {
        $registry = $this->registry($type);

        $limit = $this->quota->limitFor($type);
        $permissions = $this->permissionsFor($type);
        $createPermission = is_string($registry['create_permission'] ?? null) ? $registry['create_permission'] : null;

        $flagKey = is_string($registry['feature_flag'] ?? null) ? $registry['feature_flag'] : null;
        // No flag means the capability has never had one — its availability is
        // its permission. Reporting "off" for that would be a lie.
        $flagEnabled = $flagKey === null ? true : $this->flags->enabled($flagKey);

        $indexRoute = is_string($registry['index_route'] ?? null) ? $registry['index_route'] : null;
        $createRoute = is_string($registry['create_route'] ?? null) ? $registry['create_route'] : null;

        $routeRegistered = $indexRoute !== null && Route::has($indexRoute);

        $mayView = $user->canAny($permissions);
        $mayCreate = $createPermission !== null && $user->can($createPermission);

        $rows = [];
        $usedTotal = 0;
        // The aggregate is the SUM OF PER-BRANCH REMAINDERS, not
        // `limit - usedTotal`. The ceiling is per branch, so a branch that is
        // full contributes zero rather than borrowing headroom from a quiet one.
        $remainingTotal = 0;

        foreach ($branches as $branch) {
            $branchId = (int) $branch->id;
            $used = (int) ($matrix[$type.'|'.$branchId] ?? 0);
            $usedTotal += $used;

            $remaining = $limit === null ? null : max(0, $limit - $used);

            if ($remaining !== null) {
                $remainingTotal += $remaining;
            }

            $rows[] = [
                'branch_id' => $branchId,
                'branch_code' => (string) $branch->code,
                'branch_name' => (string) $branch->name,
                'used' => $used,
                'limit' => $limit,
                'remaining' => $remaining,
            ];
        }

        $hasAdditionalGates = $type === LegacyImportType::LEGACY_RME;
        // Least disclosure: an actor who may not VIEW this capability is not
        // told which branches its wave admitted or what that wave is doing.
        // Gating it here rather than in the view keeps the payload and the page
        // in step — a template-only guard is one edit away from leaking, and
        // the open/closed branches of that template already disagreed once.
        $gates = $hasAdditionalGates && $mayView ? $legacyRmeState : null;

        return [
            'type' => $type,
            'label' => LegacyImportType::label($type),
            'description' => (string) ($registry['description'] ?? ''),
            'unit' => (string) ($registry['unit'] ?? 'data'),

            'limit' => $limit,
            'limit_clamped' => $this->quota->limitIsClamped($type),
            'used_today' => $usedTotal,
            'remaining_today' => $limit === null ? null : $remainingTotal,

            'capability_enabled' => $flagEnabled && $routeRegistered,
            'feature_flag' => $flagKey,
            'flag_enabled' => $flagEnabled,
            'route_registered' => $routeRegistered,

            'may_view' => $mayView,
            'may_create' => $mayCreate,
            'index_route' => $routeRegistered ? $indexRoute : null,
            'create_route' => $createRoute !== null && Route::has($createRoute) ? $createRoute : null,

            'status' => $this->status($flagEnabled, $routeRegistered, $mayView, $gates),

            // Whether this TYPE is governed by gates beyond the flag and the
            // route. A property of the capability, fixed for its lifetime.
            'has_additional_gates' => $hasAdditionalGates,

            // What those gates SAY RIGHT NOW, or null when the type has none.
            // The pair matters: the first answers "could this be shut for a
            // reason this page used to be unable to see?", the second answers
            // "is it?". Publishing only the first is what let a fully closed
            // migration read as "Aktif".
            'additional_gates' => $gates,

            'rows' => $rows,
        ];
    }

    /**
     * The evaluated legacy RME activation state, or null when the archive
     * module is not installed on this deployment.
     *
     * Guarded because the hub also serves Legacy Patient, which predates the
     * archive entirely: a hub page must not 500 because a capability it merely
     * links to cannot be resolved.
     *
     * @param  list<Branch>  $branches
     * @return array<string, mixed>|null
     */
    private function legacyRmeActivationState(array $branches): ?array
    {
        if (! in_array(LegacyImportType::LEGACY_RME, $this->typeKeys(), true)) {
            return null;
        }

        try {
            return $this->legacyRmeActivation->state(array_map(
                static fn (Branch $branch): string => (string) $branch->code,
                $branches,
            ));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $gates  evaluated extra gates, or null
     */
    private function status(bool $flagEnabled, bool $routeRegistered, bool $mayView, ?array $gates = null): string
    {
        if (! $routeRegistered) {
            return 'tidak_tersedia';
        }

        if (! $flagEnabled) {
            return 'nonaktif';
        }

        if (! $mayView) {
            return 'tanpa_akses';
        }

        // A capability whose own gates are shut cannot accept a single upload,
        // so calling it "aktif" would be the same lie the flag check above
        // already refuses to tell — one gate further down the chain.
        if ($gates !== null && $gates['open'] === false) {
            return 'belum_dibuka';
        }

        return 'aktif';
    }

    /**
     * @return list<string>
     */
    public function typeKeys(): array
    {
        $configured = array_keys((array) config('legacy_import_hub.types', []));

        // The registry drives the page, but the vocabulary is the type class.
        // Filtering here means a stray config key can never open an uncounted
        // capability card.
        return array_values(array_filter(
            $configured,
            static fn (mixed $key): bool => is_string($key) && LegacyImportType::isValid($key),
        ));
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(string $type): array
    {
        $permissions = $this->registry($type)['permissions'] ?? [];

        return is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function registry(string $type): array
    {
        $registry = config('legacy_import_hub.types.'.$type, []);

        return is_array($registry) ? $registry : [];
    }
}
