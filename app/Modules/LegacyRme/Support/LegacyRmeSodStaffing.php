<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Can the separation-of-duties rules actually be STAFFED on this deployment?
 *
 * WHY THIS EXISTS
 *
 * `LegacyRmeSteadyStateOpsService::checkSeparationOfDuties()` used to answer a
 * narrower question than the one it appeared to answer. It read two config
 * booleans and, when both were on, reported GO — "Both separation-of-duties
 * requirements are enforced." True, and misleading: a deployment can enforce
 * approver-is-not-creator while having no second account able to approve
 * anything. The rule then blocks every approval instead of separating two
 * people, and readiness still says GO.
 *
 * That is exactly what production looked like. The switches were on, the
 * governance role's canonical permission had never been reconciled onto the
 * server, and an operator opening a routine batch would have discovered it
 * only when the approval failed.
 *
 * WHAT IS AND IS NOT VERIFIABLE
 *
 * Two distinct HUMANS behind two accounts is a governance control no
 * application can observe, and the steady-state service has always said so
 * rather than pretending otherwise. That honest disclaimer is preserved.
 *
 * Two distinct ACCOUNTS, each actually able to perform its half of the pair,
 * IS observable — and it is the precondition without which the human control
 * cannot exist at all. This class answers only that question.
 *
 * HOW CAPABILITY IS DECIDED
 *
 * By asking the authorization layer, not by reading a role name. `can()`
 * honours direct grants, role grants and the single global `Gate::before`
 * Super Admin bypass, so the answer matches what the account could really do.
 *
 * Candidates are narrowed first — holders of the permission plus Super Admins,
 * mirroring that one bypass — so this stays a bounded query on a deployment
 * with many users instead of a scan of every account.
 *
 * Deliberately NOT used: the wave policy. `LegacyRmeMigrationWavePolicy::
 * approve()` also requires a non-terminal wave, so probing it against whatever
 * wave happens to be around would report "nobody can approve" whenever the
 * most recent batch was completed or cancelled. Staffing is a property of the
 * deployment, not of one row.
 *
 * PRIVACY. Counts and booleans only. Never a name, an email or an id — a
 * readiness report is read and pasted around, and it does not need to say who
 * the approver is to say that one exists.
 */
final class LegacyRmeSodStaffing
{
    /**
     * Mirrors the single global bypass in
     * `RepositoryServiceProvider::boot()`: `hasRole('Super Admin') ? true`.
     * Used only to WIDEN the candidate set; the verdict still comes from
     * `can()`, so this never grants capability on its own.
     */
    private const BYPASS_ROLE = 'Super Admin';

    public const PERMISSION_WAVE_MANAGE = 'manage_legacy_rme_migration_operations';

    public const PERMISSION_WAVE_APPROVE = 'approve_legacy_rme_migration_wave';

    public const PERMISSION_IMPORT_CREATE = 'create_legacy_rme_imports';

    /**
     * Review is part of the separated chain, not a formality.
     * `SeparatePublisherGuard::GUARDED_ACTIONS` is `[REVIEW, PUBLISH]`, and
     * `LegacyRmeImportStatus::TRANSITIONS` only permits
     * READY_FOR_REVIEW → REVIEWED → PUBLISHED — so an import nobody can review
     * can never be published, by anyone. Counting only create-vs-publish would
     * report a staffed chain that in fact dead-ends at review.
     */
    public const PERMISSION_IMPORT_REVIEW = 'review_legacy_rme_imports';

    public const PERMISSION_IMPORT_PUBLISH = 'publish_legacy_rme_imports';

    /**
     * Structural evidence for the readiness report.
     *
     * @return array{
     *     wave_creator_accounts: int,
     *     wave_approver_accounts: int,
     *     distinct_creator_approver_pair_available: bool,
     *     import_maker_accounts: int,
     *     import_reviewer_accounts: int,
     *     import_publisher_accounts: int,
     *     distinct_maker_reviewer_pair_available: bool,
     *     distinct_maker_publisher_pair_available: bool,
     *     document_chain_staffed: bool
     * }
     */
    public function evaluate(): array
    {
        $creators = $this->accountIdsAbleTo(self::PERMISSION_WAVE_MANAGE);
        $approvers = $this->accountIdsAbleTo(self::PERMISSION_WAVE_APPROVE);
        $makers = $this->accountIdsAbleTo(self::PERMISSION_IMPORT_CREATE);
        $reviewers = $this->accountIdsAbleTo(self::PERMISSION_IMPORT_REVIEW);
        $publishers = $this->accountIdsAbleTo(self::PERMISSION_IMPORT_PUBLISH);

        $makerReviewer = $this->distinctPairExists($makers, $reviewers);
        $makerPublisher = $this->distinctPairExists($makers, $publishers);

        return [
            'wave_creator_accounts' => count($creators),
            'wave_approver_accounts' => count($approvers),
            'distinct_creator_approver_pair_available' => $this->distinctPairExists($creators, $approvers),
            'import_maker_accounts' => count($makers),
            'import_reviewer_accounts' => count($reviewers),
            'import_publisher_accounts' => count($publishers),
            'distinct_maker_reviewer_pair_available' => $makerReviewer,
            'distinct_maker_publisher_pair_available' => $makerPublisher,
            // The whole chain, or it is not staffed: a document has to be
            // filed, then certified by someone else, then published by someone
            // other than whoever filed it.
            'document_chain_staffed' => $makerReviewer && $makerPublisher,
        ];
    }

    /**
     * Is there some account in $a and some DIFFERENT account in $b?
     *
     * The case that matters is a single omnipotent account: one Super Admin
     * can create and approve, which satisfies both halves individually and
     * satisfies separation not at all. That is the only way two non-empty
     * sets fail to contain a distinct pair — when each holds exactly the same
     * single account.
     *
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private function distinctPairExists(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        if (count($a) === 1 && count($b) === 1) {
            return $a[0] !== $b[0];
        }

        return true;
    }

    /**
     * Accounts that could really exercise $permission right now.
     *
     * @return list<int>
     */
    private function accountIdsAbleTo(string $permission): array
    {
        return $this->candidates($permission)
            ->filter(static fn (User $user): bool => $user->can($permission))
            ->map(static fn (User $user): int => (int) $user->getKey())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function candidates(string $permission): Collection
    {
        return User::query()
            // Soft-deleted accounts are excluded by the model's global scope;
            // a deactivated one cannot sign in, so neither can staff a duty.
            ->where('is_active', true)
            ->where(function (Builder $query) use ($permission): void {
                $query
                    ->whereHas('permissions', static fn (Builder $q) => $q->where('name', $permission))
                    ->orWhereHas('roles.permissions', static fn (Builder $q) => $q->where('name', $permission))
                    ->orWhereHas('roles', static fn (Builder $q) => $q->where('name', self::BYPASS_ROLE));
            })
            ->limit($this->scanLimit())
            ->get();
    }

    /**
     * A rail, not a policy. The candidate query is already narrow; this only
     * stops a pathological deployment from materialising an unbounded set.
     */
    private function scanLimit(): int
    {
        $limit = (int) config('legacy_rme_operations.sod_staffing.max_accounts_scanned', 500);

        return $limit > 0 ? $limit : 500;
    }
}
