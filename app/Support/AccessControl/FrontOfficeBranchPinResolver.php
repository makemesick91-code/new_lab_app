<?php

namespace App\Support\AccessControl;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\FrontOfficeDevice\Services\FrontOfficeBranchDeviceLockService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Services\Foundation\FeatureFlagService;

/**
 * REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — is this account pinned to a branch,
 * and to which one.
 *
 * THE NAMED INVARIANT THIS CLASS EXISTS TO HOLD:
 *
 *     BRANCH CONTEXT LOCK != DEVICE PROOF REQUIREMENT
 *     DEVICE LOCK IMPLIES BRANCH PIN
 *
 * Two separate questions, two separate predicates, and conflating them is the
 * specific defect this class was written to remove:
 *
 *     branchPinApplies(user)  = in cohort AND (context_lock ON OR device_lock ON)
 *     deviceProofRequired(user) = in cohort AND device_lock ON
 *
 * Before this sprint there was only ONE predicate —
 * `FrontOfficeBranchDeviceLockService::appliesTo()` — and it gated on the DEVICE
 * flag. Every branch-context consumer hung off it: the `BranchContext` pin and
 * the four online-context selection guards. So the only way to obtain a branch
 * pin was to arm the device lock, and arming the device lock ALSO demands a
 * WebAuthn assertion from the browser. For a front desk holding no credential
 * that is a lockout. Branch context and device proof are different security
 * layers with different blast radii, and they must be armable independently.
 *
 * WHY THE `OR` AND NOT AN `AND`.
 *
 * `DEVICE LOCK IMPLIES BRANCH PIN` is load-bearing, not a convenience. The
 * already-GO device capability promises that an armed account's branch context
 * is pinned so the selector cannot widen it. Reading the context flag alone here
 * would silently retract that promise for any deployment that armed the device
 * lock and left the context flag off — a weakening of a shipped capability by
 * omission. So either flag pins, and the device flag additionally demands proof.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO.
 *
 * It never touches a device row, a credential, a session binding or a request.
 * It cannot cause a credential lookup, an assertion ceremony, a device-cookie
 * requirement, or a login denial for a missing credential. Those all remain
 * reachable ONLY through `FrontOfficeBranchDeviceLockService`, which still gates
 * on the device flag exactly as it did when it shipped. That separation is
 * structural rather than documented: the login controller, the session
 * middleware and the WebAuthn controller do not reference this class at all.
 *
 * THE COHORT IS NOT RE-IMPLEMENTED HERE.
 *
 * `FrontOfficeBranchDeviceCohort` stays the ONLY user->branch source of truth —
 * one env key, one ceiling of four, one branch allowlist. A second cohort would
 * be a second answer to "which branch is user 30 pinned to", and two answers is
 * one too many. The env key's name is historical: it predates this split, and it
 * now arms a cohort shared by two independently-flagged layers.
 */
final class FrontOfficeBranchPinResolver
{
    /**
     * The BRANCH-CONTEXT enforcement switch. A feature flag rather than a bare
     * config value so it carries the governance registry's risk level and
     * recorded rollback, exactly as its device-layer sibling does.
     */
    public const FLAG = 'front_office.branch_context_lock';

    /**
     * Per-user memo, scoped to this instance.
     *
     * `BranchContext::forUser()` asks this object twice — once for the pin and
     * once, only on a miss, for the misconfiguration — and `forUser()` is on a
     * hot path (BranchContext is injected in 28 places). One resolution per user
     * per instance keeps the second question free.
     *
     * @var array<int, array{applies: bool, branch: int|null}>
     */
    private array $memo = [];

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly FrontOfficeBranchDeviceCohort $cohort,
        private readonly BranchRepositoryInterface $branches,
    ) {}

    /**
     * Does ANY layer pin branches in this deployment?
     *
     * The device flag is read through its owner's constant rather than by
     * repeating the string, so the two layers cannot drift apart on the name.
     */
    public function pinningEnabled(): bool
    {
        return $this->flags->enabled(self::FLAG)
            || $this->flags->enabled(FrontOfficeBranchDeviceLockService::FLAG);
    }

    /**
     * Is this account's branch pinned?
     *
     * Role AND cohort, never either alone — the same two-part test the device
     * layer applies, for the same reason: the role check keeps a mistyped id
     * from pinning a doctor, and the cohort check keeps the pin off the four
     * Front Office accounts the owner did not approve.
     *
     * The four out-of-scope accounts are unchanged because they are ABSENT from
     * the cohort, not because anything names them. There is no allowlist
     * exception for them anywhere in this class.
     */
    public function appliesTo(User $user): bool
    {
        return $this->resolve($user)['applies'];
    }

    /**
     * The one resolution, memoised.
     *
     * ORDER MATTERS FOR COST, NOT FOR MEANING. The cohort is an in-memory parse
     * of a short config string and answers "no" for every account that is not
     * armed; `hasRole()` reaches for the permission registry. Asking the cohort
     * FIRST means the four armed accounts pay for a role check and nobody else
     * does — which matters because this runs inside `BranchContext::forUser()`
     * on ordinary requests by every role in the estate. The predicate is an AND,
     * so evaluating it in this order cannot change any answer.
     *
     * @return array{applies: bool, branch: int|null}
     */
    private function resolve(User $user): array
    {
        $userId = (int) $user->id;

        if (isset($this->memo[$userId])) {
            return $this->memo[$userId];
        }

        return $this->memo[$userId] = $this->compute($user, $userId);
    }

    /** @return array{applies: bool, branch: int|null} */
    private function compute(User $user, int $userId): array
    {
        $miss = ['applies' => false, 'branch' => null];

        // Flag check: no query, and the whole method while both layers are off.
        if (! $this->pinningEnabled()) {
            return $miss;
        }

        // In-memory cohort parse: still no query.
        if (! $this->cohort->covers($userId)) {
            return $miss;
        }

        // Only the armed accounts reach the registry. The role check keeps a
        // mistyped id from pinning a doctor.
        $requiredRole = (string) config('front_office_device_lock.policy.required_role', 'Front Office');

        if (! $user->hasRole($requiredRole)) {
            return $miss;
        }

        /*
         * A GOVERNANCE ACCOUNT EXEMPT FROM THE ONLINE CONTEXT IS NOT PINNED.
         *
         * `Owner`, `Super Admin` and `Supervisor RME` are exempt from the whole
         * online-context mechanism, so `activeContextBranchId()` returns null for
         * them by design. Pinning such an account would therefore mean: every
         * branch read resolves to null, `hasSatisfiedContext()` sends them to the
         * selector, and the selector 403s them because their role requires no
         * context — a total lockout, reachable only by arming a dual-role
         * account that also holds `Front Office`.
         *
         * Excluding them is also what the owner's scope asks for in as many
         * words: "Other roles must remain unchanged — Super Admin, Supervisor
         * RME…". And it follows the precedent already set by the doctor branch
         * lock, whose resolver treats an "exempt governance account" as one of
         * its explicit non-locking states.
         *
         * This cannot be used to escape a pin: an ordinary front-desk account
         * does not hold any of those three roles, and granting one to escape the
         * pin would be a far larger privilege change than the pin itself.
         */
        if (app(UserOnlineContextService::class)->isExemptFromContext($user)) {
            return $miss;
        }

        $code = $this->cohort->requiredBranchCodeFor($userId);

        if ($code === null) {
            return ['applies' => true, 'branch' => null];
        }

        $branch = $this->branches->findByCode($code);

        // A branch that has lost is_active, or that is not RME-enabled, cannot
        // host a front desk. MAIN is the one this most often means in practice:
        // it is the fallback a branchless account lands on and it is not
        // RME-enabled, so it can never satisfy the pin.
        if ($branch === null || ! $branch->is_active || ! $branch->is_rme_enabled) {
            return ['applies' => true, 'branch' => null];
        }

        return ['applies' => true, 'branch' => (int) $branch->id];
    }

    /**
     * The branch this account is pinned to, or NULL when it is not pinned or its
     * mapping is unusable.
     *
     * A NULL is NOT self-evidently safe here, which is why
     * `isMisconfiguredFor()` exists beside it — see that method.
     */
    public function requiredBranchIdFor(User $user): ?int
    {
        return $this->resolve($user)['branch'];
    }

    /**
     * Is this account ARMED but UNDECIDABLE — duplicated, naming a branch
     * outside the committed allowlist, sitting in an oversized cohort, or
     * pointing at a branch that is gone, inactive or not RME-enabled?
     *
     * WHY THIS IS A SEPARATE QUESTION AND NOT JUST A NULL.
     *
     * The device layer could treat a NULL as "do not pin" safely, because a
     * covered-but-undecidable account had ALREADY been denied login by
     * `evaluate()` — an account that cannot log in cannot widen a branch. The
     * branch-context layer has NO login denial: it is deliberately not allowed
     * to deny a login (that would be the device layer's blast radius). So a NULL
     * treated as "do not pin" here would let a misconfigured entry fall through
     * to ordinary resolution and silently UNPIN an armed account — fail OPEN,
     * which is exactly backwards from the cohort's stated contract.
     *
     * So the two states are distinguished, and a caller that resolves a branch
     * must fail closed on this one rather than widening.
     */
    public function isMisconfiguredFor(User $user): bool
    {
        $resolved = $this->resolve($user);

        return $resolved['applies'] && $resolved['branch'] === null;
    }
}
