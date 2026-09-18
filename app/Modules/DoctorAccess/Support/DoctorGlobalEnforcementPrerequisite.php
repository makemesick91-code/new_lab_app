<?php

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-GLOBAL-DEVICE-ENFORCEMENT-READINESS-1 — the vocabulary of the
 * five Half-B prerequisites, and the one rule that governs all of them.
 *
 * WHAT WAS WRONG BEFORE THIS CLASS EXISTED.
 *
 * `android_release.enforcement.global_prerequisites` has been five strings
 * since Phase 3.5, and `global_prerequisites_attested` five hand-signed
 * booleans beside them. `Phase4aPilotPreparationScanner::globalPrerequisiteCheck()`
 * compares the two lists and asserts that somebody SIGNED for each name. That
 * is the whole of it: the check reads no measurement, so
 *
 *     attested true + measured false  ===>  PASS
 *
 * which is the exact false green this programme has spent several sprints
 * removing everywhere else. `DoctorEstateResilienceService::attestation()`
 * already closes it for ONE prerequisite — `spare_device_available_per_branch`
 * — by comparing the signature against the gate that measures it. Four of five
 * had no measurement to be compared against at all, and one of those four
 * (`rollback_to_browser_login_proven`) had no producer anywhere under `app/`.
 *
 * THE RULE, STATED ONCE.
 *
 * A signature never overrides a measurement. An attestation recorded `true`
 * beside a measurement that is anything other than {@see self::PASS} is a
 * CONTRADICTION and fails. {@see self::UNVERIFIED} contradicts a `true`
 * signature exactly as {@see self::FAIL} does: "nobody measured it" is not
 * evidence that it holds, and a prerequisite that cannot be measured is
 * precisely the one a signature must not be allowed to settle.
 *
 * THE ABSENCE of a signature fails nothing here. That is deliberate and
 * matches the sibling engine: this vocabulary exists to stop a false green,
 * not to force a signature that nobody is ready to give.
 */
final class DoctorGlobalEnforcementPrerequisite
{
    /** Measured, and the measurement holds. */
    public const PASS = 'PASS';

    /** Measured, and the measurement does not hold. */
    public const FAIL = 'FAIL';

    /**
     * Not measurable right now, and therefore NOT a pass.
     *
     * Reported when the producer could not reach what it needs — an empty
     * population, a missing evidence artifact, a repository that threw. Never
     * used to mean "probably fine".
     */
    public const UNVERIFIED = 'UNVERIFIED';

    /**
     * The status vocabulary, as an allow-list.
     *
     * {@see self::worst()} folds over this and over nothing else. A previous
     * sibling in this codebase returned PASS by fallthrough for `'BANANA'`,
     * `'pass'` and `null`; the lesson was paid for once and is not re-learned
     * here.
     */
    public const STATUSES = [self::PASS, self::UNVERIFIED, self::FAIL];

    /** The pilot rung actually completed on real hardware, by real doctors. */
    public const REAL_DEVICE_PILOT_PASSED = 'real_device_pilot_passed';

    /** Every doctor Half B would enforce can reach a trusted device today. */
    public const EVERY_DOCTOR_HAS_ACTIVE_DEVICE = 'every_enforced_doctor_has_an_active_device';

    /** Survive losing one tablet: Level 3 of the estate capacity policy. */
    public const SPARE_DEVICE_PER_BRANCH = 'spare_device_available_per_branch';

    /** The device-loss runbook has actually been rehearsed, and it is evidenced. */
    public const DEVICE_LOSS_RUNBOOK_REHEARSED = 'device_loss_runbook_rehearsed';

    /** Global enforcement can be taken back off, proven by execution. */
    public const ROLLBACK_TO_BROWSER_LOGIN_PROVEN = 'rollback_to_browser_login_proven';

    /**
     * Where the declarations and the signatures live.
     *
     * Constants rather than string literals at each call site, for the reason
     * the sibling engine records: the producer and the consumer used to be two
     * copies of one literal, and a rename would have left the consumer reading
     * a key nobody publishes — falling through to a default that is `!== PASS`,
     * which a contradiction test also accepts. Silent in exactly the way this
     * class exists to prevent.
     */
    public const CONFIG_DECLARED = 'android_release.enforcement.global_prerequisites';

    public const CONFIG_ATTESTED = 'android_release.enforcement.global_prerequisites_attested';

    /**
     * Worst of many, failing closed on anything outside the vocabulary.
     *
     * An EMPTY set is {@see self::UNVERIFIED}, never PASS. "Zero rows, zero
     * problems" is arithmetically a pass and operationally a broken query, and
     * this programme has already shipped one gate that reported PASS while the
     * estate held zero usable tablets.
     *
     * @param  list<string>  $statuses
     */
    public static function worst(array $statuses): string
    {
        if ($statuses === []) {
            return self::UNVERIFIED;
        }

        $worst = self::PASS;

        foreach ($statuses as $status) {
            if (! in_array($status, self::STATUSES, true)) {
                return self::FAIL;
            }

            if ($status === self::FAIL) {
                return self::FAIL;
            }

            if ($status === self::UNVERIFIED) {
                $worst = self::UNVERIFIED;
            }
        }

        return $worst;
    }

    /**
     * Does this signature stand against this measurement?
     *
     * The single place the rule at the top of this file is expressed. Every
     * consumer asks here rather than re-deriving `=== true && !== PASS`, so
     * there is one line to change if the rule ever moves and one line to
     * mutate when a test wants to prove the rule is load-bearing.
     */
    public static function contradicts(bool $attested, string $measured): bool
    {
        return $attested && $measured !== self::PASS;
    }
}
