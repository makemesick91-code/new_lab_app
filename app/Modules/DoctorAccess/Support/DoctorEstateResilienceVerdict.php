<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1 — the vocabulary of estate
 * resilience, kept apart from the verdicts of readiness and provisioning.
 *
 * THREE ENGINES, THREE QUESTIONS, AND THE NAMES MUST NOT BLUR:
 *
 *   DoctorGlobalRolloutReadinessService — "is a trusted path PROVISIONED for
 *                                         every doctor?"
 *   DoctorFleetReadinessService         — "has that path been EXERCISED?"
 *   DoctorEstateResilienceService       — "does the HARDWARE survive losing a
 *                                         tablet?" (this sprint)
 *
 * The first two are satisfied today: 45/45 authorizations, 15/15 doctors with a
 * proven login. Neither of them looks at whether a branch owns a second tablet,
 * and both would keep saying READY on the morning the only tablet at a branch
 * is dropped down a stairwell. That is the question this engine exists for, and
 * it is the reason `spare_device_available_per_branch` is a prerequisite of
 * global activation rather than a footnote.
 *
 * WHY THERE IS AN `UNVERIFIED` AND NOT JUST PASS/FAIL. The only definition of
 * "spare" the codebase owns is prose in the device-loss runbook:
 *
 *     devices per branch = concurrent Doctor stations + 1 spare
 *
 * `concurrent Doctor stations (peak, not average)` is an operational count that
 * exists in no table. An engine that silently assumed a number for it would be
 * manufacturing the input to its own gate. So when the station count decides
 * the answer, this engine says UNVERIFIED and names the missing input.
 *
 * What it CAN decide without that input is the lower bound, and the lower bound
 * is what the estate fails on today: for any station count >= 1 the requirement
 * is at least 2 eligible devices, so a branch holding 0 or 1 FAILS under every
 * reading. Undecidability starts at 2, not at 0.
 */
final class DoctorEstateResilienceVerdict
{
    /** Measured true. */
    public const PASS = 'PASS';

    /** Measured false. Not "unknown", not "pending hardware" — false. */
    public const FAIL = 'FAIL';

    /**
     * Not decidable from data the system holds, and deliberately NOT a pass.
     *
     * This programme has already shipped one gate that read silence as success.
     * An UNVERIFIED never contributes to a passing tally and always carries the
     * name of the input that would settle it.
     */
    public const UNVERIFIED = 'UNVERIFIED';

    /**
     * The branch universe this engine measures: every branch that either holds
     * a registered Doctor device or is some doctor's HOME branch.
     *
     * `per branch` is undefined in source — the string
     * `spare_device_available_per_branch` has never had an implementation, only
     * a declaration and a hand-signed boolean. So the reading is NAMED here
     * rather than assumed, and printed with every report.
     *
     * A branch that neither holds hardware nor is anybody's home has no Doctor
     * service to protect, so including it would invent a failure. MAIN (the
     * non-RME fallback) and soft-deleted synthetic branches fall out naturally
     * by this rule rather than by a hardcoded exclusion list.
     */
    public const BRANCH_SCOPE = 'branches_participating_in_doctor_service';

    /**
     * The minimum eligible devices a participating branch needs under ANY
     * station count >= 1: one to work on, one spare.
     */
    public const MINIMUM_DEVICES_FOR_ANY_SPARE = 2;

    /** No eligible device at a branch that has home doctors. */
    public const GAP_NO_LOCAL_DEVICE = 'no_local_eligible_device';

    /** Exactly one eligible device: it works, until it does not. */
    public const GAP_NO_SPARE = 'no_spare_eligible_device';

    /** An eligible device with no usable credential cannot be logged into. */
    public const GAP_DEVICE_WITHOUT_CREDENTIAL = 'eligible_device_without_usable_credential';

    /**
     * Two or more eligible devices, so whether that is enough depends on the
     * station count nobody has recorded.
     */
    public const GAP_STATION_COUNT_UNDECLARED = 'concurrent_station_count_undeclared';

    /**
     * Worst-of, in this order. UNVERIFIED outranks PASS because an undecided
     * gate must never be reported as a satisfied one; FAIL outranks both
     * because a measured falsehood is the strongest thing we know.
     *
     * @param  list<string>  $verdicts
     */
    public static function worst(array $verdicts): string
    {
        /*
         * An EMPTY set lands on FAIL, not PASS. "Zero branches, zero gaps" is
         * arithmetically a pass and operationally a broken query.
         */
        if ($verdicts === []) {
            return self::FAIL;
        }

        if (in_array(self::FAIL, $verdicts, true)) {
            return self::FAIL;
        }

        if (in_array(self::UNVERIFIED, $verdicts, true)) {
            return self::UNVERIFIED;
        }

        /*
         * FAILS CLOSED ON ANYTHING OUTSIDE THE VOCABULARY.
         *
         * The earlier form returned PASS by fallthrough, so `worst(['BANANA'])`,
         * `worst(['pass'])` and `worst([null])` were all satisfied verdicts — a
         * typo, a case slip or a future fourth status would have been reported
         * as everything being fine. Unreachable from today's producers and
         * exactly the wrong default for a class whose whole argument is that an
         * unrecognised state is never a satisfied one.
         */
        foreach ($verdicts as $verdict) {
            if ($verdict !== self::PASS) {
                return self::FAIL;
            }
        }

        return self::PASS;
    }
}
