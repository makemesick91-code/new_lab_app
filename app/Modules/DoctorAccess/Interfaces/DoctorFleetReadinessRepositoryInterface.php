<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-FLEET-ROLLOUT-READINESS-1 — the one read this sprint adds:
 * durable proof that a named doctor completed a real device login.
 *
 * WHY THE AUDIT TRAIL AND NOT A COLUMN. Three candidates carry a login, and
 * only one of them can answer the question honestly:
 *
 *   mst_doctor_device_authorizations.last_authorized_login_at
 *       Per (doctor, device) and beautifully cheap — but written at exactly one
 *       site, DoctorDeviceAuthorizationService::markAuthorizedLogin(), reached
 *       only from the Android ticket redemption. A doctor who logs in through
 *       the PWA forever reads NULL. Structurally under-reports, so a readiness
 *       gate built on it would call proven doctors unproven.
 *
 *   trx_doctor_device_webauthn_credentials.last_used_at
 *       Written by the PWA path — but the credentials table has no doctor_id
 *       and deliberately never will (a credential belongs to a TABLET, rule
 *       152 GR-R5). On a tablet two doctors share, this column cannot say which
 *       of them used it. Structurally UNATTRIBUTABLE, which is worse than
 *       under-reporting: it would let one doctor's login mark another ready.
 *
 *   sys_audit_logs, the two success actions
 *       Covers BOTH paths, names the doctor twice over (performed_by is the
 *       user, new_values.doctor_id is the record), is append-only, and is
 *       written only AFTER the session exists. That is the source.
 *
 * READ-ONLY, and there will never be a write method here. A readiness engine
 * that can write is a readiness engine that can manufacture its own evidence.
 */
interface DoctorFleetReadinessRepositoryInterface
{
    /**
     * The two audit actions that mean "this doctor reached a clinical session
     * through a trusted device".
     *
     * Both are written after Auth::login() and session regeneration, never
     * before — an assertion that verified and then failed to establish a
     * session writes neither.
     *
     * Failure and ATTEMPT actions are deliberately absent. DOCTOR_DEVICE_-
     * LOGIN_REQUESTED in particular looks like a login and is not one: it is
     * written on every Android attempt that got past the password, including
     * the ones that were then refused.
     */
    public const PROOF_ACTIONS = [
        'DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS',
        'DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS',
    ];

    /**
     * Successful device logins for the given users, keyed by user id.
     *
     * Each value is:
     *   [
     *     'count'      => int,            // successful logins on record
     *     'last_at'    => string|null,    // ISO-8601, or null if unparseable
     *     'actions'    => list<string>,   // which paths were exercised
     *     'device_ids' => list<int>,      // devices this doctor proved, ascending
     *   ]
     *
     * A user with no proof is ABSENT from the collection rather than present
     * with a zero. The caller must treat a missing key as "not proven"; an
     * engine that reads a zero and an absence differently is an engine with two
     * definitions of unproven.
     *
     * `device_ids` is decoded from new_values in PHP rather than extracted in
     * SQL on purpose: `->>` and json_extract are spelled differently on
     * PostgreSQL and SQLite, and the production gate runs on one while the
     * local suite runs on the other.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, array{count:int,last_at:string|null,actions:list<string>,device_ids:list<int>}>
     */
    public function deviceLoginProofForUsers(array $userIds): Collection;
}
