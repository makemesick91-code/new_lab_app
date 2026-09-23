<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchCoverRepositoryInterface;
use App\Modules\DoctorAccess\Interfaces\DoctorBranchLockRepositoryInterface;
use App\Modules\DoctorAccess\Support\DoctorEffectiveBranch;
use App\Modules\DoctorAccess\Support\IncumbentSessionProbe;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Services\Foundation\FeatureFlagService;
use Carbon\CarbonImmutable;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — THE single canonical answer to
 * EFFECTIVE_CLINICAL_BRANCH.
 *
 *     an approved cover that is current  ->  cover.target_branch_id
 *     otherwise                          ->  the home lock
 *
 * THERE IS EXACTLY ONE RESOLVER. Every consumer — the operational list scope,
 * the visit-creation write assertion, the branch selector, the session lease —
 * asks this class. A second implementation of "is a cover active" is the single
 * worst outcome available here, which is why the SQL predicate lives in one
 * repository method and the PHP predicate lives on one model, and both are
 * pinned equal by test.
 *
 * IT IS PURE. It takes a User and nothing else: no Request, no device id, no
 * branch argument. It issues at most three bounded reads and performs ZERO
 * writes — in particular it must never audit, because it runs on every
 * protected request of every doctor and auditing a standing degradation here
 * would insert a row on every page view. The reason travels on the returned
 * {@see DoctorEffectiveBranch} instead; the write hook audits a refusal once
 * per attempt — `ClinicVisitService::auditEffectiveBranchWriteRefusal()`, which
 * runs only on the refusal path and only outside a transaction, so the trail
 * survives the ValidationException that rolls the attempted write back.
 *
 * NULL IS AN ANSWER, NEVER AN ERROR. A null user, a non-doctor, an exempt
 * governance account, an unlinked doctor, a doctor with no lock row and a
 * doctor whose locked branch has been retired all resolve to null, and every
 * one of them means "behave exactly as this system did before this sprint".
 *
 * WHAT IT IS NOT ALLOWED TO NARROW. Per-record authorization and the legacy
 * archive stay cross-branch (owner decision O3): a doctor must still be able to
 * open the Rekam Medis of the patient in front of them when that patient's
 * earliest visit happened at another branch. Consumers hook the LIST scope and
 * the WRITE chokepoint, never the per-record policy.
 */
class DoctorEffectiveBranchResolver
{
    public const FLAG_SINGLE_ACTIVE_SESSION = 'doctor.single_active_session';

    public const FLAG_BRANCH_LOCK = 'doctor.branch_lock';

    public function __construct(
        private readonly DoctorBranchLockRepositoryInterface $locks,
        private readonly DoctorBranchCoverRepositoryInterface $covers,
        private readonly DoctorIdentityResolver $identities,
        private readonly UserOnlineContextService $onlineContext,
        private readonly BranchService $branches,
        private readonly FeatureFlagService $flags,
        private readonly IncumbentSessionProbe $sessionProbe,
    ) {}

    /**
     * Is the branch lock armed?
     *
     * THREE CONDITIONS, ALL ENFORCED HERE IN CODE.
     *
     * 1. `doctor.branch_lock` — the capability itself.
     *
     * 2. `doctor.single_active_session` — declared as a dependency on the flag
     *    and enforced here because NOTHING in the registry reads a flag
     *    dependencies array: it is required metadata and it is hydrated, and no
     *    caller consumes it, so the declaration alone is decorative. Branch lock
     *    armed on its own would be a branch that can change underneath an
     *    already-authenticated session, because session invalidation on cover
     *    start, cover expiry and permanent transfer IS the lease mechanism.
     *
     * 3. The incumbent-session probe is observable. Cover expiry is enforced by
     *    the lease middleware comparing the branch a session was established
     *    under against the branch that is effective now. If the lease engine
     *    disarmed for an unobservable session driver while this stayed armed,
     *    an EXPIRED cover would keep granting authority with nothing left to
     *    invalidate the session. So the two arm and disarm together.
     *
     * Read through FeatureFlagService and NEVER through the config helper with
     * a dotted flag key. A flag KEY itself contains dots, so a dotted config
     * lookup addresses a nested structure the registry never writes and never
     * reads: it answers null, and the flag reads as off while it is on. The
     * same warning is recorded at
     * app/Modules/LegacyRme/Support/LegacyRmeFeatureGuard.php:51.
     */
    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG_BRANCH_LOCK)
            && $this->flags->enabled(self::FLAG_SINGLE_ACTIVE_SESSION)
            && $this->sessionProbe->observable();
    }

    /**
     * The branch this doctor may operate in right now, or null.
     *
     * The primary contract. Null for a null user, a non-doctor, an exempt
     * account, an unlinked doctor, an UNSET doctor and a degraded lock alike —
     * every one of which means "do not narrow anything".
     */
    public function branchIdFor(?User $user): ?int
    {
        return $this->resolve($user)->branchId();
    }

    /**
     * The same answer with its reason attached.
     *
     * @param  CarbonImmutable|null  $at  the instant to judge a cover against.
     *                                    Defaults to now. A caller that is
     *                                    making a decision AND rendering the
     *                                    screen that explains it should pass one
     *                                    instant to both, so the two can never
     *                                    disagree because a second ticked over.
     */
    public function resolve(?User $user, ?CarbonImmutable $at = null): DoctorEffectiveBranch
    {
        if ($user === null || ! $this->enabled()) {
            return DoctorEffectiveBranch::notApplicable();
        }

        // requiresDoctorContext, not hasRole('Doctor'), on purpose: it is
        // hasRole('Doctor') AND NOT exempt, and exempt is Owner / Super Admin /
        // Supervisor RME. A governance account that also carries the Doctor
        // role must never be branch-locked — locking one would break the very
        // approvers this capability depends on.
        if (! $this->onlineContext->requiresDoctorContext($user)) {
            return DoctorEffectiveBranch::notApplicable();
        }

        // DoctorIdentityResolver, deliberately not DoctorUserResolver: the
        // latter also returns null for an inactive doctor, which would silently
        // UNLOCK a deactivated one. Identity and "may practise now" are
        // separate questions owned by separate callers.
        $doctor = $this->identities->resolveForUser($user);

        if ($doctor === null) {
            return DoctorEffectiveBranch::unlinked();
        }

        $doctorId = (int) $doctor->id;

        // CarbonImmutable::now(), not ClinicalClock::now(). starts_at and
        // ends_at are INSTANTS in the application's UTC frame, and comparing an
        // instant to an instant is timezone-agnostic. ClinicalClock::timezone()
        // throws on a misconfigured clinical timezone — the right answer for a
        // clinical eligibility DATE, but this runs on every protected doctor
        // request, so a config typo would become a total doctor outage. The
        // clinical clock IS the authority where a WALL CLOCK is meant: parsing
        // the period an approver types, and rendering it back to them.
        // Carbon::setTestNow() controls this, so tests still drive the clock.
        $at ??= CarbonImmutable::now();

        $cover = $this->covers->activeApprovedForDoctor($doctorId, $at);
        $homeBranchId = $this->locks->lockedBranchIdFor($doctorId);

        // UNSET SHORT-CIRCUIT, and it is a real saving rather than a tidy-up.
        // With no cover and no lock the verdict is UNSET whatever the branch
        // table says, so reading it would be a query per protected request for
        // an answer that cannot change the outcome — and EVERY doctor in the
        // fleet is UNSET the moment this capability is armed, which is exactly
        // the population that would pay it. `rmeEnabledIds()` is not cached.
        if ($cover === null && $homeBranchId === null) {
            return DoctorEffectiveBranch::notSet();
        }

        $rmeBranchIds = $this->branches->rmeEnabledIds();

        if ($cover !== null) {
            $targetBranchId = (int) $cover->target_branch_id;

            if (in_array($targetBranchId, $rmeBranchIds, true)) {
                return DoctorEffectiveBranch::cover($targetBranchId, (int) $cover->id, $homeBranchId);
            }

            // A dead cover degrades to UNSET. It deliberately does NOT fall
            // through to home: that would move a covering doctor back to their
            // home branch in the middle of a cover, with no audit and no
            // session invalidation — a silent branch switch, which the cover
            // design forbids outright.
            return DoctorEffectiveBranch::degraded(
                DoctorEffectiveBranch::REASON_COVER_BRANCH_NOT_RME_ENABLED,
                $homeBranchId,
                (int) $cover->id,
            );
        }

        // A home branch is present here by construction: the pair being null was
        // answered above, and the cover arm returns in every case.
        if (in_array($homeBranchId, $rmeBranchIds, true)) {
            return DoctorEffectiveBranch::home($homeBranchId);
        }

        // A locked branch that has lost is_active or is_rme_enabled is a
        // HANDLED degradation, never an exception. Throwing here would stop the
        // doctor dead on every write path; degrading lets the pre-sprint
        // behaviour resume while the standing condition is reported elsewhere.
        return DoctorEffectiveBranch::degraded(
            DoctorEffectiveBranch::REASON_HOME_BRANCH_NOT_RME_ENABLED,
            $homeBranchId,
        );
    }

    /**
     * Is this account bound to a usable branch right now?
     *
     * TRUE only for HOME and COVER. False for a degraded lock, so a consumer
     * that asks this question narrows nothing when the locked branch has been
     * retired.
     */
    public function isLocked(?User $user): bool
    {
        return $this->resolve($user)->isLocked();
    }

    /**
     * Does this doctor hold a cover that is current at `$at`?
     *
     * For the permanent-transfer guard: a transfer approved while a cover is in
     * force would leave an ambiguous effective branch, so approval is blocked
     * until the cover ends or is cancelled. Keyed on the DOCTOR rather than on
     * a user, because the approver is deciding about a doctor record and the
     * subject may not be signed in at all.
     *
     * A merely SCHEDULED (future) cover does not answer true: only an ACTIVE
     * one blocks.
     */
    public function hasActiveCoverForDoctor(int $doctorId, ?CarbonImmutable $at = null): bool
    {
        return $this->covers->activeApprovedForDoctor($doctorId, $at ?? CarbonImmutable::now()) !== null;
    }
}
