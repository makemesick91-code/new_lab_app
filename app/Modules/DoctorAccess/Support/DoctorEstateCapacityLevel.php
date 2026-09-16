<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * REVISION-DOCTOR-TRUSTED-DEVICE-ESTATE-CAPACITY-POLICY-1 — three requirements
 * that were one gate.
 *
 * WHAT WENT WRONG, AND IT WAS NOT A WRONG MEASUREMENT.
 * `DOCTOR-ACCESS-TRUSTED-DEVICE-ESTATE-RESILIENCE-1` measured
 * `spare_device_available_per_branch` correctly under the only definition the
 * codebase owned — the device-loss runbook's `concurrent Doctor stations + 1
 * spare` — and reported FAIL. That verdict stands and is not revised here.
 *
 * What it could not do was distinguish three DIFFERENT questions the owner had
 * been asking with one word:
 *
 *   LEVEL 1  ACTIVATION TEST COVERAGE  can every staffed branch be TESTED at
 *                                      all? (>= 1 usable trusted device)
 *   LEVEL 2  ROOM CAPACITY             can every active Doctor room be worked
 *                                      normally? (>= 1 device per room)
 *   LEVEL 3  FAILURE RESILIENCE        does the branch survive LOSING one?
 *                                      (stations + 1 — unchanged)
 *
 * They are NESTED MATURITY LEVELS, not synonyms, and the estate can sit at
 * three different answers at once. Today it does: two of three staffed branches
 * clear Level 1, none clears Level 3, and collapsing that into one FAIL told
 * the owner "buy four tablets" when the sentence they needed was "one tablet at
 * TLK1 unblocks testing".
 *
 * THE RULE THAT MAKES THIS SAFE. Separating the levels must never turn a
 * measured falsehood into a PASS. Lowering what ACTIVATION TESTING requires
 * does not lower what HIGH AVAILABILITY requires, and Level 3 goes on reporting
 * FAIL beside a passing Level 1 for as long as the hardware says so. A reader
 * who wants one number gets the worst of every gate, which is never greener
 * than Level 3.
 *
 * WHY A FOURTH AND FIFTH STATUS. `PARTIAL` exists because Level 2 is the only
 * level where "some, not all" is a real and actionable state — a branch running
 * three rooms on one tablet is neither working normally nor unable to open.
 * `NOT_APPLICABLE` exists because an unstaffed branch is not a shortfall, and
 * the previous sprint has already shipped one gate that read an empty
 * population as success. Neither is ever a PASS, and neither is ever handed to
 * {@see DoctorEstateResilienceVerdict::worst()} — that vocabulary is
 * deliberately three words wide and fails closed on a fourth.
 */
final class DoctorEstateCapacityLevel
{
    /**
     * LEVEL 1 — the only capacity level that gates controlled activation
     * testing.
     *
     * `>= 1` trusted device that can actually be logged into, at every branch
     * that staffs a doctor. Named rather than described because the owner's
     * decision to defer activation is recorded against a capacity level, and a
     * decision recorded against prose drifts.
     */
    public const ACTIVATION_TEST_COVERAGE = 'trusted_device_activation_test_coverage';

    /**
     * LEVEL 2 — the normal-production target, reported and never a blocker of
     * activation testing.
     *
     * Hardware rollout is deliberately incremental, so a branch part-way
     * through it is PARTIAL rather than broken.
     */
    public const ROOM_CAPACITY = 'trusted_device_room_capacity';

    /**
     * LEVEL 3 — high availability, and the historical meaning of
     * `spare_device_available_per_branch`, unchanged.
     *
     * Kept under its original key so every citation, attestation, runbook line
     * and test that names it goes on meaning what it meant. This class does not
     * redefine it; it stops other questions borrowing its answer.
     */
    public const FAILURE_RESILIENCE = DoctorEstateResilienceVerdict::GATE_SPARE_DEVICE;

    /** Measured true at this level. */
    public const PASS = 'PASS';

    /**
     * Measured short, but not at zero. Level 2 only.
     *
     * A capacity target reached in part. It is NOT `UNVERIFIED` — the shortfall
     * is measured, not undecidable — and it is NOT `PASS`.
     */
    public const PARTIAL = 'PARTIAL';

    /** Measured false. */
    public const FAIL = 'FAIL';

    /** The deciding input is not held by the system. Never a pass. */
    public const UNVERIFIED = 'UNVERIFIED';

    /**
     * Outside this level's population.
     *
     * An unstaffed branch has no clinic day to protect, so it is not a
     * shortfall — and it is not a satisfied requirement either. It contributes
     * NOTHING in either direction.
     */
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    /**
     * Worst-of for a capacity level, over its own population.
     *
     * Order: FAIL > UNVERIFIED > PARTIAL > PASS. `UNVERIFIED` outranks
     * `PARTIAL` because a measured shortfall can be planned against and an
     * unknown cannot.
     *
     * NOT_APPLICABLE is DISCARDED, not ranked — that is what "outside the
     * population" means. An input array holding nothing else therefore arrives
     * here empty, and an empty population is FAIL, never PASS. The previous
     * sprint shipped a gate that took the worst over every branch and reported
     * PASS on an estate of unstaffed branches holding zero usable tablets; the
     * population is named here so that cannot recur.
     *
     * @param  list<string>  $statuses
     */
    public static function worst(array $statuses): string
    {
        $ranked = array_values(array_filter(
            $statuses,
            static fn (string $status): bool => $status !== self::NOT_APPLICABLE,
        ));

        if ($ranked === []) {
            return self::FAIL;
        }

        foreach ([self::FAIL, self::UNVERIFIED, self::PARTIAL] as $status) {
            if (in_array($status, $ranked, true)) {
                return $status;
            }
        }

        /*
         * FAILS CLOSED ON ANYTHING OUTSIDE THE VOCABULARY, for the same reason
         * the sibling class does: a typo, a case slip or a future sixth status
         * must never be reported as a satisfied level.
         */
        foreach ($ranked as $status) {
            if ($status !== self::PASS) {
                return self::FAIL;
            }
        }

        return self::PASS;
    }

    /**
     * The three-word vocabulary {@see DoctorEstateResilienceVerdict::worst()}
     * accepts, from the five this class uses.
     *
     * PARTIAL is a measured shortfall, so it narrows to FAIL rather than to
     * UNVERIFIED — calling it "not decidable from data the system holds" would
     * be false, and this programme has already paid for one gate whose message
     * outlived its verdict. NOT_APPLICABLE never reaches a gate array at all
     * and is rejected here rather than silently mapped.
     */
    public static function toGateVerdict(string $status): string
    {
        return match ($status) {
            self::PASS => DoctorEstateResilienceVerdict::PASS,
            self::UNVERIFIED => DoctorEstateResilienceVerdict::UNVERIFIED,
            self::PARTIAL, self::FAIL => DoctorEstateResilienceVerdict::FAIL,
            default => DoctorEstateResilienceVerdict::FAIL,
        };
    }
}
