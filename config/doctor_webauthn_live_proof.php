<?php

declare(strict_types=1);

/**
 * FIX-DOCTOR-WEBAUTHN-READINESS-LIVE-PROOF-1 — the freshness policy for
 * "has the browser leg actually worked recently".
 *
 * WHY A NUMBER LIVES HERE AT ALL
 *
 * Before this sprint there was NO freshness duration for doctor WebAuthn proof
 * anywhere in the codebase. What existed was a deliberate stance in
 * DoctorFleetReadinessService: a proof does not expire with time, and what
 * invalidates it is the path underneath being withdrawn.
 *
 * That stance is correct and is NOT changed here, because it answers a
 * different question. Fleet readiness asks "has this doctor ever demonstrated
 * they can use a trusted path" — a COVERAGE question, and a demonstration does
 * not become untrue because time passed. This file answers "is the browser leg
 * working right now" — a LIVENESS question, and liveness is the one property
 * that genuinely does decay with silence.
 *
 * THE CALIBRATION CASE, measured, not assumed: between 2026-09-09 and
 * 2026-09-19 the browser leg produced no successful assertion at all while
 * `webauthn:readiness` reported ARMED every day. Seven days puts that outage
 * into STALE on day eight. Fourteen would never have fired.
 *
 * The owner chose seven days explicitly. Do not change it to make a red gate
 * green — that inverts the whole point of the gate.
 */
return [

    /*
     * Days after which a qualifying assertion is STALE rather than fresh.
     *
     * STALE is its own state. It is never FAIL (the credential is still valid
     * and the doctor is not locked out) and it is never PASS (nobody has shown
     * the leg works lately). Collapsing it into either is the defect this
     * sprint exists to remove.
     *
     * A null, zero or negative value means NO freshness opinion is configured,
     * and freshness is then reported UNVERIFIED rather than silently passing —
     * "we were not told" is not "it is fresh".
     */
    'freshness_window_days' => env('DOCTOR_WEBAUTHN_PROOF_FRESHNESS_DAYS', 7),

    /*
     * The clinic-local offset used ONLY for display beside the UTC value.
     *
     * Internal authority is UTC and every comparison above is made in UTC. This
     * exists so an operator reading the report at a branch does not have to do
     * the arithmetic, and it is always rendered with its label so an unlabelled
     * timestamp can never be mistaken for local time.
     */
    'display_timezone' => env('DOCTOR_WEBAUTHN_PROOF_DISPLAY_TZ', 'Asia/Makassar'),
    'display_timezone_label' => 'WITA (UTC+8)',
];
