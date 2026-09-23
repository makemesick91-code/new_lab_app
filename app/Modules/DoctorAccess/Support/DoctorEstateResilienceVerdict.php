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
 * Neither of the first two looks at whether a branch owns a second tablet, and
 * both would keep saying READY on the morning the only tablet at a branch is
 * dropped down a stairwell. That is the question this engine exists for, and it
 * is the reason `spare_device_available_per_branch` is a prerequisite of global
 * activation rather than a footnote.
 *
 * No authorization or doctor tally is quoted here on purpose. The live figures
 * are in the report's own `authorization_matrix`, recomputed against the
 * CURRENT estate every build; a docblock that names a count goes stale the hour
 * a tablet is added, and rule 158 already forbids carrying a stale `45/45`
 * forward because that is exactly how a new tablet becomes invisible.
 *
 * WHY THERE IS AN `UNVERIFIED` AND NOT JUST PASS/FAIL. The only definition of
 * "spare" the codebase owns is prose in the device-loss runbook:
 *
 *     devices per branch = concurrent Doctor stations + 1 spare
 *
 * `concurrent Doctor stations (peak, not average)` is an operational count
 * nobody has recorded. `mst_clinic_rooms` holds a branch-scoped treatment-room
 * inventory and is the nearest candidate, but a room is not a staffed station in
 * either direction — three rooms with one doctor on shift is one station, one
 * room with two chairs is two — so it is REPORTED as advisory and decides
 * nothing HERE. (REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 gave
 * rooms a decision of their own at LEVEL 2, which is a different question with a
 * different room set; the station count below is still never substituted.)
 * An engine that silently assumed a number for this would be
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

    /**
     * The gate key the attestation cross-check reads.
     *
     * A constant because the producer and the consumer used to be two copies of
     * the same string literal, and this sprint had already renamed one sibling
     * gate. A rename would have made `attestation()` fall through to its
     * UNVERIFIED default in silence — and the contradiction test would have
     * stayed green, because UNVERIFIED is also `!== PASS`.
     */
    public const GATE_SPARE_DEVICE = 'spare_device_available_per_branch';

    /**
     * REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1.
     *
     * The gate the ACTIVATION-TESTING prerequisite reads — Level 1, and a
     * different question from the one above. Held here beside its sibling for
     * the same reason the sibling is held here: the producer and the consumer
     * must not be two copies of a string literal.
     */
    public const GATE_ACTIVATION_TEST_COVERAGE = DoctorEstateCapacityLevel::ACTIVATION_TEST_COVERAGE;

    /**
     * The gate that FAILS when a signature contradicts a measurement.
     *
     * Named for exactly what it decides. `attestation_consistency` would have
     * read as "the attestations are in order", which it does not check — an
     * estate where nothing at all is signed satisfies this gate.
     */
    public const GATE_ATTESTATION_NO_CONTRADICTION = 'attestation_does_not_contradict_measurement';

    /**
     * Where the ACTIVATION-TESTING prerequisite list and its signatures live.
     *
     * Config paths, not gate keys, and deliberately separate from
     * `global_prerequisites`: that list is the Phase-5 precondition for
     * fleet-wide enforcement and is NOT weakened by this revision.
     */
    public const CONFIG_ACTIVATION_TEST_PREREQUISITES = 'android_release.enforcement.activation_test_prerequisites';

    public const CONFIG_ACTIVATION_TEST_ATTESTED = 'android_release.enforcement.activation_test_prerequisites_attested';

    public const CONFIG_GLOBAL_PREREQUISITES_ATTESTED = 'android_release.enforcement.global_prerequisites_attested';

    /** No eligible device at a branch that has home doctors. */
    public const GAP_NO_LOCAL_DEVICE = 'no_local_eligible_device';

    /** Exactly one eligible device: it works, until it does not. */
    public const GAP_NO_SPARE = 'no_spare_eligible_device';

    /** An eligible device with no usable credential cannot be logged into. */
    public const GAP_DEVICE_WITHOUT_CREDENTIAL = 'eligible_device_without_usable_credential';

    /**
     * REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 — Level 1's own
     * gap, and NOT a synonym of {@see self::GAP_NO_LOCAL_DEVICE}.
     *
     * A staffed branch holding one eligible tablet whose only credential is
     * revoked has a local eligible device and cannot be tested on it. Level 1
     * counts devices that can be LOGGED INTO, so this gap fires where the older
     * one does not, and an adversarial review found exactly that hole: a
     * credential-less tablet at TLK1 would have turned the activation-testing
     * prerequisite green at a branch where no doctor can sign in.
     */
    public const GAP_NO_LOCALLY_USABLE_DEVICE = 'no_locally_usable_trusted_device';

    /**
     * The staffed population is incomplete, so no verdict over it is safe.
     *
     * Raised when a doctor belongs to no branch: an UNSET doctor is invisible
     * to every per-branch count, so a shrinking population could otherwise turn
     * Level 1 green with no hardware bought.
     */
    public const GAP_POPULATION_INCOMPLETE = 'staffed_population_incomplete';

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
