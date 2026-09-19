<?php

declare(strict_types=1);

namespace App\Modules\DoctorDevice\Interfaces;

use Illuminate\Support\Collection;

/**
 * FIX-DOCTOR-WEBAUTHN-READINESS-LIVE-PROOF-1 — where "this browser leg actually
 * worked" comes from.
 *
 * THE SOURCE, AND WHY IT IS NOT THE OBVIOUS ONE
 *
 * `trx_doctor_device_webauthn_credentials.last_used_at` IS written at assertion
 * success, so it looks like the natural answer. It is refused here, and rules
 * 156/157 already refuse it for the sibling engine: that column has no doctor
 * column by design, so on a shared tablet one doctor's login would mark another
 * ready. It also cannot distinguish the credential that was used from the
 * credential that has since been revoked.
 *
 * The source is `sys_audit_logs`, action DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS,
 * which DoctorDeviceWebAuthnLoginService writes exactly once, AFTER
 * `assertionValidator()->check()` returned, AFTER the user-verification policy
 * was satisfied, and AFTER Auth::login() and session regeneration. An assertion
 * that verified and then failed to establish a session writes nothing. It is
 * append-only, and it carries the correlation this engine needs and the scalar
 * columns lack:
 *
 *     entity_id                   the credential row that signed
 *     new_values.doctor_device_id the device it signed on
 *     new_values.doctor_id        the doctor record
 *     performed_by                the authenticated user
 *
 * DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS — the Android Keystore path — is
 * DELIBERATELY ABSENT from this file. The two proofs share no code path (0
 * references each way), and letting an Android login satisfy a WebAuthn
 * liveness question is the exact false green this sprint exists to remove. A
 * single constant naming one action is how that stays true by construction
 * rather than by review.
 *
 * READ-ONLY, and there will never be a write method here. A readiness engine
 * that can write is a readiness engine that can manufacture its own evidence.
 */
interface DoctorWebAuthnLiveProofRepositoryInterface
{
    /**
     * The ONE audit action that means "a WebAuthn assertion verified and a
     * doctor session was established from it".
     *
     * Never widen this to an attempt, a request or a rejection, and never add
     * the Android action beside it.
     */
    public const WEBAUTHN_PROOF_ACTION = 'DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS';

    /**
     * Latest qualifying assertion per CREDENTIAL, for the given credential ids.
     *
     * Keyed by credential id. A credential with no qualifying assertion is
     * ABSENT rather than present with a null, so the caller cannot read "never
     * proven" and "proven at an unreadable time" as the same thing.
     *
     * Each value is:
     *   [
     *     'last_at'    => string,   // ISO-8601 UTC
     *     'count'      => int,      // qualifying assertions on record
     *     'device_ids' => list<int> // devices this credential signed on
     *   ]
     *
     * `device_ids` exists so the caller can refuse a proof whose recorded
     * device is not the device the credential now belongs to. A credential is
     * bound to one device, so that list should hold exactly one id; if it ever
     * holds another, the correct response is to distrust the proof rather than
     * to average it away.
     *
     * @param  list<int>  $credentialIds
     * @return Collection<int, array{last_at:string,count:int,device_ids:list<int>}>
     */
    public function latestWebAuthnProofForCredentials(array $credentialIds): Collection;
}
