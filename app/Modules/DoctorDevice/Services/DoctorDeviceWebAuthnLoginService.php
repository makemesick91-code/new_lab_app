<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceWebAuthnCredentialRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnChallenge;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use App\Modules\DoctorDevice\Support\WebAuthnCeremonyFactory;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — letting a BROWSER write the device-bound session that
 * previously only the Clinic App could write.
 *
 * WHERE THIS SITS IN THE EXISTING DESIGN
 *
 * `DoctorAppLoginGate` denies an ordinary browser login for one reason: the
 * absence of `doctor_device.device_id` in the session, and until now only
 * Android ticket redemption ever wrote that key. Nothing about the gate says
 * "must be an Android app" — it says "must have a server-verified device
 * binding", and it re-checks the device and the authorization behind that
 * binding on every protected request.
 *
 * So this service does not add a second enforcement authority, and deliberately
 * does not touch the gate. It adds a SECOND WAY TO EARN THE SAME BINDING, with
 * proof of equivalent strength: a signature over a server-issued single-use
 * challenge, verified against a public key registered to an approved device.
 *
 * The consequence is that revocation already works for browser sessions on the
 * day this ships. Revoking the device, the authorization or the credential
 * stops the open browser session at its next request, through machinery that
 * was written and tested for the Android path.
 *
 * TWO INDEPENDENT SWITCHES, AND WHY THAT MATTERS
 *
 * This path is reachable only when BOTH are true:
 *
 *   doctor.trusted_device_enforcement  — otherwise a doctor's browser is never
 *                                        denied, so nothing ever asks for an
 *                                        assertion
 *   doctor.pwa_webauthn_device_login   — this sprint's own flag
 *
 * With the second flag off, a denied doctor is denied exactly as they are
 * today. Turning it on cannot admit anybody who does not already hold a
 * registered credential on an APPROVED device with an ACTIVE authorization, so
 * on a fleet with no credentials registered it changes nothing at all.
 *
 * WHAT IS RE-ASSERTED AT ASSERTION TIME
 *
 * All of it, again. The credential list handed to the browser was computed a
 * moment ago, and a device can be revoked in that moment. "It was allowed when
 * we offered it" is not "it is allowed now".
 */
class DoctorDeviceWebAuthnLoginService
{
    /** This sprint's own flag. Default false; see config/feature_flags.php. */
    public const FLAG = 'doctor.pwa_webauthn_device_login';

    /** Pre-authentication session keys. Hold no privilege on their own. */
    public const SESSION_PENDING_USER_ID = 'doctor_device_webauthn.pending_user_id';

    public const SESSION_PENDING_EXPIRES_AT = 'doctor_device_webauthn.pending_expires_at';

    /** Seconds a half-finished login may sit waiting for a biometric prompt. */
    private const PENDING_TTL_SECONDS = 300;

    public function __construct(
        private readonly DoctorAppLoginGate $gate,
        private readonly DoctorDeviceWebAuthnChallengeService $challenges,
        private readonly DoctorDeviceWebAuthnCredentialRepositoryInterface $credentials,
        private readonly DoctorDeviceSessionService $sessions,
        private readonly DoctorIdentityResolver $doctors,
        private readonly FeatureFlagService $flags,
        private readonly AuditLogService $auditLogs,
    ) {}

    public function enabled(): bool
    {
        return $this->flags->enabled(self::FLAG);
    }

    /**
     * Could this user finish a login by asserting a credential?
     *
     * Used to decide whether a denied doctor is sent to the WebAuthn step or
     * simply refused. A doctor with no usable credential must be REFUSED rather
     * than shown a ceremony that cannot succeed — a dead-end prompt is worse
     * than a clear denial.
     */
    public function canAssert(User $user): bool
    {
        if (! $this->enabled() || ! $this->gate->enforcementEnabled()) {
            return false;
        }

        $doctor = $this->doctors->resolveForUser($user);

        if ($doctor === null) {
            return false;
        }

        return $this->credentials->usableForDoctor((int) $doctor->id)->isNotEmpty();
    }

    /** Park a password-verified login while the device proves itself. */
    public function beginPending(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_PENDING_USER_ID, (int) $user->id);
        $request->session()->put(
            self::SESSION_PENDING_EXPIRES_AT,
            now()->addSeconds(self::PENDING_TTL_SECONDS)->getTimestamp(),
        );
    }

    public function forgetPending(Request $request): void
    {
        $request->session()->forget(self::SESSION_PENDING_USER_ID);
        $request->session()->forget(self::SESSION_PENDING_EXPIRES_AT);
    }

    /**
     * The user whose password step succeeded, if that step is still live.
     *
     * Returns null once the window closes. The marker carries NO privilege on
     * its own: holding it lets a visitor request a challenge for that account,
     * nothing more, and the assertion still has to verify.
     */
    public function pendingUser(Request $request): ?User
    {
        if (! $request->hasSession()) {
            return null;
        }

        $userId = $request->session()->get(self::SESSION_PENDING_USER_ID);
        $expiresAt = $request->session()->get(self::SESSION_PENDING_EXPIRES_AT);

        if (! is_int($userId) || ! is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt < now()->getTimestamp()) {
            $this->forgetPending($request);

            return null;
        }

        return User::query()->find($userId);
    }

    /**
     * Options for `navigator.credentials.get()`.
     *
     * @return array{options: PublicKeyCredentialRequestOptions, challenge: DoctorDeviceWebAuthnChallenge}
     */
    public function requestOptions(User $user, Request $request): array
    {
        $this->assertEnabled();

        $relyingParty = WebAuthnRelyingParty::fromConfig();
        $relyingParty->assertUsable();

        $doctor = $this->doctors->resolveForUser($user);

        if ($doctor === null) {
            $this->deny(null, $user, 'doctor_not_linked');
        }

        $usable = $this->credentials->usableForDoctor((int) $doctor->id);

        if ($usable->isEmpty()) {
            $this->deny(null, $user, 'no_usable_credential');
        }

        $challenge = $this->challenges->issue(
            DoctorDeviceWebAuthnChallenge::CEREMONY_ASSERTION,
            $request,
            (int) $user->id,
        );

        $options = PublicKeyCredentialRequestOptions::create(
            Base64UrlSafe::decodeNoPadding($challenge->challenge),
            $relyingParty->id(),
            $this->descriptorsFor($usable),
            $relyingParty->userVerification(),
            (int) config('webauthn.ceremony.timeout_milliseconds', 90000),
        );

        return ['options' => $options, 'challenge' => $challenge];
    }

    /**
     * Verify an assertion and establish the device-bound session.
     *
     * @param  array<string,mixed>  $credentialPayload  raw `navigator.credentials.get()` result
     *
     * @throws ValidationException on any failure, always with one opaque message
     */
    public function completeLogin(User $user, Request $request, array $credentialPayload): User
    {
        $this->assertEnabled();

        // Belt and braces. A browser session may only be established through
        // this path while the doctor device lock is actually armed; otherwise
        // there is nothing to unlock and no reason to mint a device binding.
        if (! $this->gate->enforcementEnabled()) {
            $this->deny(null, $user, 'enforcement_disabled');
        }

        $relyingParty = WebAuthnRelyingParty::fromConfig();
        $relyingParty->assertUsable();

        $factory = new WebAuthnCeremonyFactory($relyingParty);

        try {
            $publicKeyCredential = $factory->serializer()->deserialize(
                json_encode($credentialPayload, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json',
            );
        } catch (Throwable) {
            $this->deny(null, $user, 'malformed_credential_payload');
        }

        /** @var PublicKeyCredential $publicKeyCredential */
        $response = $publicKeyCredential->response;

        if (! $response instanceof AuthenticatorAssertionResponse) {
            $this->deny(null, $user, 'not_an_assertion_response');
        }

        // Burn first, verify second. See DoctorDeviceWebAuthnChallengeService.
        $challenge = $this->challenges->claim(
            Base64UrlSafe::encodeUnpadded($response->clientDataJSON->challenge),
            DoctorDeviceWebAuthnChallenge::CEREMONY_ASSERTION,
            $request,
            (int) $user->id,
        );

        if ($challenge === null) {
            $this->deny(null, $user, 'challenge_not_claimable');
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);
        $credential = $this->credentials->findByCredentialId($credentialId);

        if ($credential === null || ! $credential->isUsable()) {
            $this->deny($credential, $user, 'credential_not_usable');
        }

        if (! WebAuthnDeviceBinding::isAcceptable($credential->device_bound_verdict)) {
            // A credential registered under a looser policy must not keep
            // working after the policy is tightened.
            $this->deny($credential, $user, 'device_binding_policy');
        }

        $device = DoctorDevice::query()->find($credential->doctor_device_id);

        // DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — the proof this login is about
        // to write, asserted through the SAME predicate the per-request check
        // will use. Login-time and per-request agreeing by construction is the
        // point: a session that could be established but not kept, or kept but
        // not established, is two rules pretending to be one.
        $proof = DoctorSessionProof::webAuthn((int) $credential->id);

        if ($device === null || ! $this->gate->deviceUsableForProof($device, $proof)) {
            $this->deny($credential, $user, 'device_not_usable');
        }

        $doctor = $this->doctors->resolveForUser($user);

        if ($doctor === null) {
            $this->deny($credential, $user, 'doctor_not_linked');
        }

        $authorization = DoctorDeviceAuthorization::query()
            ->where('doctor_id', $doctor->id)
            ->where('doctor_device_id', $device->id)
            ->first();

        if ($authorization === null || ! $authorization->isActive()) {
            // The DEVICE may be perfectly trusted and this doctor still refused
            // on it. That separation is the point of the authorization table.
            $this->deny($credential, $user, 'authorization_not_active');
        }

        $usable = $this->credentials->usableForDoctor((int) $doctor->id);

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            Base64UrlSafe::decodeNoPadding($challenge->challenge),
            $relyingParty->id(),
            $this->descriptorsFor($usable),
            $relyingParty->userVerification(),
            (int) config('webauthn.ceremony.timeout_milliseconds', 90000),
        );

        try {
            $verified = $factory->assertionValidator()->check(
                $this->credentialRecordFor($credential, $device),
                $response,
                $requestOptions,
                $relyingParty->id(),
                // The handle the credential was issued to is the DEVICE uuid,
                // so this is an extra binding between the signature and the
                // device row we just loaded.
                (string) $device->uuid,
            );
        } catch (Throwable) {
            $this->deny($credential, $user, 'assertion_rejected');
        }

        if ($relyingParty->requiresUserVerification()
            && ! $response->authenticatorData->isUserVerified()) {
            $this->deny($credential, $user, 'user_verification_missing');
        }

        DB::transaction(function () use ($credential, $verified) {
            $credential->forceFill([
                // A counter that stays at zero is normal for many platform
                // authenticators; the library refuses a counter that goes
                // backwards on one that was previously incrementing.
                'signature_counter' => max((int) $credential->signature_counter, (int) $verified->counter),
                'last_used_at' => now(),
            ])->save();
        });

        Auth::guard('web')->login($user);

        // Session fixation defence, then the binding, in that order — the
        // binding must land in the NEW session, exactly as ticket redemption
        // does it.
        $request->session()->regenerate();

        $this->sessions->bind(
            $request,
            (int) $device->id,
            (int) $authorization->id,
            (int) $doctor->id,
            $proof,
        );

        $this->forgetPending($request);

        $device->forceFill(['last_seen_at' => now()])->save();

        $this->auditLogs->log(
            'trx_doctor_device_webauthn_credentials',
            (int) $credential->id,
            'DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS',
            null,
            [
                'doctor_device_id' => $device->id,
                'doctor_id' => $doctor->id,
                'authorization_id' => $authorization->id,
            ],
            $user,
        );

        return $user;
    }

    /**
     * Rebuild the library's view of a stored credential.
     *
     * Attestation is not retained (we requested `none`), so the trust path is
     * empty and the attestation type is whatever was recorded. Neither is used
     * to decide anything — the signature check needs the public key, the
     * counter and the backup flags, and those are what this carries.
     */
    private function credentialRecordFor(
        DoctorDeviceWebAuthnCredential $credential,
        DoctorDevice $device,
    ): CredentialRecord {
        return CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($credential->credential_id),
            'public-key',
            $credential->transports ?? [],
            $credential->attestation_format ?? 'none',
            EmptyTrustPath::create(),
            // The nil uuid, not a fresh random one: the aaguid is not consulted
            // by the assertion check, and inventing a different value on every
            // call would make an unused field look like it carried meaning.
            Uuid::fromString($credential->aaguid ?: '00000000-0000-0000-0000-000000000000'),
            (string) base64_decode($credential->public_key, true),
            (string) $device->uuid,
            (int) $credential->signature_counter,
            null,
            $credential->backup_eligible,
            $credential->backup_state,
            $credential->user_verified,
        );
    }

    /**
     * @param  Collection<int, DoctorDeviceWebAuthnCredential>  $credentials
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptorsFor($credentials): array
    {
        return $credentials
            ->map(static fn (DoctorDeviceWebAuthnCredential $credential) => PublicKeyCredentialDescriptor::create(
                'public-key',
                Base64UrlSafe::decodeNoPadding($credential->credential_id),
                $credential->transports ?? [],
            ))
            ->values()
            ->all();
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'credential' => 'Login perangkat tepercaya belum aktif.',
            ]);
        }
    }

    /**
     * One message for every failure.
     *
     * The reason code goes to the audit trail, never to the client. A client
     * that could tell "unknown credential" from "device revoked" from "you are
     * not authorized on this tablet" could map the clinic's device estate from
     * the login page.
     */
    private function deny(?DoctorDeviceWebAuthnCredential $credential, User $user, string $reason): never
    {
        $this->auditLogs->log(
            'trx_doctor_device_webauthn_credentials',
            $credential === null ? null : (int) $credential->id,
            'DOCTOR_DEVICE_WEBAUTHN_LOGIN_REJECTED',
            null,
            ['reason' => $reason],
            $user,
        );

        throw ValidationException::withMessages([
            'credential' => 'Perangkat ini tidak dapat digunakan untuk masuk.',
        ]);
    }
}
