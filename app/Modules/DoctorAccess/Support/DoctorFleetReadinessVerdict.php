<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — the vocabulary of fleet readiness,
 * kept apart from the verdicts of the PROVISIONING engine it composes.
 *
 * Two engines now answer two different questions and the names must not blur:
 *
 *   DoctorGlobalRolloutReadinessService  — "is a trusted path PROVISIONED for
 *                                          every doctor?" (rule 152, GR-R4)
 *   DoctorFleetReadinessService          — "has that path been EXERCISED, and
 *                                          does every doctor have a home
 *                                          branch?" (this sprint)
 *
 * The distinction is not academic. On 2026-09-12 a bulk authorization run wrote
 * 45 rows and the provisioning engine moved from 3 ready doctors to 15 without
 * a single clinician logging in. Every one of those 45 rows was correct; the
 * fleet was no readier than the day before. A readiness claim that cannot tell
 * those two states apart is worse than no claim at all, because it is the claim
 * an activation sprint will read.
 */
final class DoctorFleetReadinessVerdict
{
    /**
     * Every eligible doctor is locked to a home branch, holds a provisioned
     * trusted path, and has exercised it at least once — and every eligible
     * trusted device carries at least one successful proof.
     */
    public const READY = 'READY';

    /** Some doctors clear every gate, some do not. A rollout in progress. */
    public const PARTIAL = 'PARTIAL';

    /**
     * Nobody clears every gate, or there is nothing to measure.
     *
     * An EMPTY fleet lands here on purpose. "Zero doctors, zero blockers" is
     * arithmetically a pass and operationally a broken query, and this
     * programme has already shipped one gate that read silence as success.
     */
    public const NO_GO = 'NO-GO';

    /** This doctor clears every fleet gate. */
    public const STATE_READY = 'READY';

    /** This doctor does not. The blocker list says why, and is never empty. */
    public const STATE_NOT_READY = 'NOT_READY';

    /**
     * No `mst_doctor_branch_locks` row. The compatibility state from owner
     * decision O1 — legitimate for a running clinic, disqualifying for fleet
     * readiness, and never repaired by inference.
     */
    public const BLOCKER_HOME_BRANCH_UNSET = 'home_branch_unset';

    /**
     * No successful device login has ever been recorded for this doctor.
     *
     * Distinct from a provisioning gap: the path may be complete and simply
     * never walked. That is precisely the state 12 doctors are in today.
     */
    public const BLOCKER_LOGIN_NOT_PROVEN = 'real_device_login_not_proven';

    /**
     * The provisioning engine refuses this doctor. Its own reason codes are
     * carried through verbatim rather than restated, so the two reports agree
     * by construction instead of by maintenance.
     */
    public const BLOCKER_PATH_INCOMPLETE = 'trusted_path_incomplete';

    /**
     * This doctor holds no ACTIVE authorization on at least one eligible
     * trusted tablet.
     *
     * Stricter than the provisioning engine on purpose. GR-R3 asks for ONE
     * complete path, which is the right test for "can this doctor log in at
     * all". Fleet readiness asks for the whole row, because a clinician who
     * can only reach one of three tablets is a rota constraint the estate has
     * not been told about — and because the gap must reappear the moment a
     * FOURTH tablet is trusted, rather than being silently inherited.
     */
    public const BLOCKER_AUTHORIZATION_GAP = 'authorization_matrix_incomplete';

    /**
     * An eligible trusted device that no doctor has ever completed a readiness
     * login on. Reported against the FLEET, not against a doctor — a device
     * nobody has proven is an estate finding.
     */
    public const FINDING_DEVICE_UNPROVEN = 'trusted_device_without_readiness_proof';

    /**
     * The fleet holds no eligible doctor, or no eligible trusted device.
     *
     * Reported as a finding as well as forcing NO-GO, because a verdict alone
     * would leave an operator guessing whether the estate is empty or the
     * query is broken.
     */
    public const FINDING_NOTHING_TO_MEASURE = 'no_eligible_population_to_measure';

    /**
     * Ordered most-significant first. The table in an operator's terminal has
     * one column for a reason, and "which blocker do I fix first" should not
     * depend on hash order.
     *
     * Branch first: it is an owner decision with no clinical prerequisite, so
     * it can be cleared from a desk. A login proof needs a clinician standing
     * at a tablet.
     */
    public const BLOCKER_PRECEDENCE = [
        self::BLOCKER_PATH_INCOMPLETE,
        self::BLOCKER_AUTHORIZATION_GAP,
        self::BLOCKER_HOME_BRANCH_UNSET,
        self::BLOCKER_LOGIN_NOT_PROVEN,
    ];

    /**
     * The single blocker an operator should act on first, or null when the
     * doctor is ready.
     *
     * An unrecognised blocker sorts LAST rather than being dropped. Dropping it
     * would let a future code path add a reason that silently never surfaces.
     *
     * @param  list<string>  $blockers
     */
    public static function primary(array $blockers): ?string
    {
        if ($blockers === []) {
            return null;
        }

        foreach (self::BLOCKER_PRECEDENCE as $candidate) {
            if (in_array($candidate, $blockers, true)) {
                return $candidate;
            }
        }

        return $blockers[0];
    }
}
