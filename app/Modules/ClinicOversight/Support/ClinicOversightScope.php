<?php

namespace App\Modules\ClinicOversight\Support;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use Illuminate\Support\Collection;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 (rule 186) — the ONE branch
 * authority of the read-only oversight module (the "AuthorizedClinicalReadScope").
 *
 * WHY A SCOPE OF ITS OWN. The existing branch scopes cannot be reused without
 * widening them:
 *
 *   - RmeWorkingBranchScope pins context-bound roles to one branch and lets
 *     every other role fall through to "all RME branches" IMPLICITLY. The
 *     oversight roles must never depend on that fall-through.
 *   - LegacyRmeWorkspaceScope / LegacyOdontogramWorkspaceScope only give the
 *     whole RME set to holders of a GOVERNANCE permission (review/publish/void),
 *     and rules 173/174 forbid adding a read permission to those lists.
 *
 * So this scope grants the cross-branch read set EXPLICITLY, keyed on a named
 * oversight permission, and nothing else consults it. A user without that
 * permission resolves to the EMPTY set — never MAIN, never "the first branch",
 * never the whole estate.
 *
 * ELIGIBLE BRANCHES = active AND RME-enabled AND NOT MAIN. MAIN is excluded by
 * code, not only by its `is_rme_enabled` value in the data, because that
 * column defaults to true and nothing else would keep MAIN out.
 *
 * A REQUEST branch filter is never authority: narrow() intersects it with the
 * server-resolved set, so a forged/foreign/disabled id yields NO branch (fail
 * closed) rather than the whole set.
 */
class ClinicOversightScope
{
    public const PERMISSION_DASHBOARD = 'view_finance_analytics_dashboard';

    public const PERMISSION_CLINICAL = 'view_clinical_records_readonly';

    public const PERMISSION_MONITORING = 'view_operational_monitoring';

    public const PERMISSION_EXPORT = 'export_oversight_data';

    /** The three VIEW areas. Export is never an area of its own. */
    public const VIEW_PERMISSIONS = [
        self::PERMISSION_DASHBOARD,
        self::PERMISSION_CLINICAL,
        self::PERMISSION_MONITORING,
    ];

    /** Every oversight permission (view areas + export). */
    public const ALL_PERMISSIONS = [
        self::PERMISSION_DASHBOARD,
        self::PERMISSION_CLINICAL,
        self::PERMISSION_MONITORING,
        self::PERMISSION_EXPORT,
    ];

    /** @var Collection<int, Branch>|null */
    private ?Collection $eligible = null;

    public function __construct(private readonly BranchService $branches) {}

    /**
     * Branches the user may read for the given VIEW area. Empty when the user
     * does not hold that area's permission.
     *
     * @return Collection<int, Branch>
     */
    public function branchesFor(?User $user, string $permission): Collection
    {
        if (! $this->holds($user, $permission)) {
            return collect();
        }

        return $this->eligibleBranches();
    }

    /**
     * @return list<int>
     */
    public function branchIdsFor(?User $user, string $permission): array
    {
        return $this->branchesFor($user, $permission)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Narrow the authorized set by an optional request filter. The filter may
     * only NARROW: an id outside the authorized set yields an empty set.
     *
     * @param  list<int>  $authorized
     * @return list<int>
     */
    public function narrow(array $authorized, mixed $requested): array
    {
        if ($requested === null || $requested === '') {
            return array_values($authorized);
        }

        if (! is_int($requested) && ! (is_string($requested) && ctype_digit($requested))) {
            return [];
        }

        $id = (int) $requested;

        return in_array($id, $authorized, true) ? [$id] : [];
    }

    /**
     * Is a record owned by $branchId readable by $user in this area? A NULL
     * branch is never readable (fail closed).
     */
    public function allowsBranch(?User $user, string $permission, ?int $branchId): bool
    {
        if ($branchId === null) {
            return false;
        }

        return in_array($branchId, $this->branchIdsFor($user, $permission), true);
    }

    public function holds(?User $user, string $permission): bool
    {
        return $user !== null
            && in_array($permission, self::VIEW_PERMISSIONS, true)
            && $user->can($permission);
    }

    public function mayExport(?User $user, string $permission): bool
    {
        return $this->holds($user, $permission) && $user->can(self::PERMISSION_EXPORT);
    }

    /**
     * Active + RME-enabled branches, MAIN excluded by code.
     *
     * @return Collection<int, Branch>
     */
    public function eligibleBranches(): Collection
    {
        return $this->eligible ??= $this->branches->listRmeEnabled()
            ->reject(fn (Branch $branch) => $branch->code === Branch::MAIN_CODE)
            ->values();
    }
}
