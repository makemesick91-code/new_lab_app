<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceAuthorizationRepositoryInterface;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceWebAuthnCredentialRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use App\Services\Foundation\FeatureFlagService;
use App\Support\Android\AndroidDoctorEnforcementScope;
use Illuminate\Http\Request;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the ONE authority on
 * whether a doctor's session may exist.
 *
 * There is exactly one of these on purpose. Enforcement that lives in two
 * places is enforcement that will one day disagree with itself, and the
 * disagreement will be discovered by a doctor who cannot see their patients.
 *
 * ENFORCEMENT IS OFF IN PRODUCTION AFTER THIS REVISION.
 *
 * `enforcementEnabled()` reads the canonical flag `doctor.trusted_device_
 * enforcement`, which defaults to false. While it is false:
 *
 *  - `assertMayEstablishBrowserSession()` returns before touching the database,
 *    so an empty authorization table cannot deny anybody;
 *  - the session middleware returns on its first line;
 *  - no login ticket is ever minted, so the app-only path cannot succeed either.
 *
 * That is why a doctor working today keeps working exactly as they did.
 *
 * WHAT COUNTS AS DEVICE TRUST
 *
 * A signature over a server-issued single-use nonce, verified against the
 * enrolled public key. Never a `User-Agent`, never a static header, never a MAC
 * address, an IMEI, an Android ID or an installation UUID. This class does not
 * inspect a single request header to decide trust — it reads the session
 * binding that the redemption of a server-minted ticket wrote.
 */
class DoctorAppLoginGate
{
    /** The canonical enforcement flag. Default false; see config/feature_flags.php. */
    public const ENFORCEMENT_FLAG = 'doctor.trusted_device_enforcement';

    /** Session keys written only by a redeemed login ticket. */
    public const SESSION_DEVICE_ID = 'doctor_device.device_id';

    public const SESSION_AUTHORIZATION_ID = 'doctor_device.authorization_id';

    public const SESSION_DOCTOR_ID = 'doctor_device.doctor_id';

    public const SESSION_BOUND_AT = 'doctor_device.bound_at';

    /**
     * DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — WHICH proof authenticated this
     * session, and for WebAuthn, which credential specifically.
     *
     * Written at authentication success by whichever path ran, and never
     * derived afterwards from what the device happens to hold. See
     * {@see DoctorSessionProof}.
     */
    public const SESSION_PROOF_TYPE = 'doctor_device.proof_type';

    public const SESSION_WEBAUTHN_CREDENTIAL_ID = 'doctor_device.webauthn_credential_id';

    /** Why a doctor session was refused. Stable codes; the audit trail uses them. */
    public const DENY_NO_DEVICE_SESSION = 'no_device_session';

    public const DENY_DEVICE_NOT_USABLE = 'device_not_usable';

    public const DENY_AUTHORIZATION_NOT_ACTIVE = 'authorization_not_active';

    public const DENY_DOCTOR_MISMATCH = 'doctor_mismatch';

    public const DENY_DOCTOR_NOT_LINKED = 'doctor_not_linked';

    /**
     * The binding does not say how it was earned, or names a proof this build
     * cannot verify. Every session bound before this sprint looks like this.
     */
    public const DENY_SESSION_PROOF_UNKNOWN = 'session_proof_unknown';

    /** A WebAuthn session, on a deployment that has switched WebAuthn off. */
    public const DENY_WEBAUTHN_LOGIN_DISABLED = 'webauthn_login_disabled';

    /** Revoked, moved to another device, or no longer meeting the bound policy. */
    public const DENY_WEBAUTHN_CREDENTIAL_NOT_USABLE = 'webauthn_credential_not_usable';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly DoctorIdentityResolver $doctors,
        private readonly DoctorDeviceAuthorizationRepositoryInterface $authorizations,
        private readonly AndroidDoctorEnforcementScope $scope,
    ) {}

    /**
     * Is device enforcement switched on?
     *
     * Read through FeatureFlagService, never `config('feature_flags.flags…')`:
     * the flag keys contain dots, so a dotted config lookup silently traverses
     * into nothing and returns null — which would read as "off" today and as an
     * unpredictable value the moment a key changed shape.
     */
    public function enforcementEnabled(): bool
    {
        return $this->flags->enabled(self::ENFORCEMENT_FLAG);
    }

    /**
     * Does this account fall under the doctor device rules at all?
     *
     * Role, not permissions: the rule is about who is physically at a tablet.
     * A Super Admin who also happens to hold the Doctor role is included
     * deliberately — the whole point is that the hardware is trusted, and a
     * powerful account is not a reason to skip that.
     */
    public function appliesTo(User $user): bool
    {
        return $user->hasRole('Doctor');
    }

    /**
     * Does enforcement apply to THIS doctor, on this deployment?
     *
     * PHASE4A-DOCTOR-ANDROID-PILOT-PREPARATION-1. `appliesTo()` answers "is
     * this account subject to the doctor device rules at all", which is a
     * question about the role. This answers "is enforcement switched on for
     * them", which is a question about the rollout — and until now there was no
     * way to ask it. One boolean meant a pilot could only be run by denying
     * browser login to every doctor in every branch at once.
     *
     * A Phase 4A pilot on a tablet that was not wiped needs this. Lock task was
     * what physically kept a doctor away from a browser; without it, app-only
     * is exactly this decision and nothing else. Enforcing it for one doctor is
     * the difference between a pilot and a fleet-wide clinical lockout.
     *
     * Composed with `appliesTo()` rather than folded into it, because the role
     * question has other callers whose meaning must not change.
     *
     * Decided from the user id alone. No query: `denyBrowserSessionReason()`
     * promises to return before touching the database, and a scope check that
     * loaded a doctor record would quietly break that promise for every
     * request.
     */
    public function inEnforcementScope(User $user): bool
    {
        if (! $this->appliesTo($user)) {
            return false;
        }

        return $this->scope->coversUser((int) $user->id);
    }

    /**
     * May this user hold an ordinary browser session?
     *
     * Returns null when yes, or a deny code when no. The caller is responsible
     * for tearing the session down — this class decides, it does not act.
     */
    public function denyBrowserSessionReason(User $user, Request $request): ?string
    {
        if (! $this->enforcementEnabled()) {
            return null;
        }

        // Role AND rollout scope. A doctor outside the declared scope keeps
        // browser login exactly as they have it today, which is what makes a
        // one-doctor pilot possible at all.
        if (! $this->inEnforcementScope($user)) {
            return null;
        }

        // A browser has no device binding, because only ticket redemption
        // writes one. So under enforcement this is the branch that denies an
        // ordinary Chrome/Firefox/Edge login — from the ABSENCE of a
        // server-verified device session, not from sniffing the client.
        if (! $request->hasSession() || $request->session()->get(self::SESSION_DEVICE_ID) === null) {
            return self::DENY_NO_DEVICE_SESSION;
        }

        return $this->denySessionReason($user, $request);
    }

    /**
     * DOCTOR-PWA-WEBAUTHN-1 — could this denial still be answered by a trusted
     * BROWSER, rather than only by the Clinic App?
     *
     * WHY THIS QUESTION IS ASKED HERE AND NOT IN THE LOGIN CONTROLLER
     *
     * Because the login controller is allowed to consult exactly one authority
     * about devices, and this is it. Letting the controller reach for a second
     * collaborator — a proof service, a credential query, its own flag read —
     * is the precise thing `authentication_coupled_only_through_the_gate`
     * forbids, and the reason it forbids it is that a second consultation is
     * how the auth path grows its own quietly-diverging copy of the rules.
     *
     * So the gate answers, as it answers every other "may this session exist"
     * question, and the WebAuthn service stays behind it.
     *
     * Resolved from the container rather than injected: the WebAuthn login
     * service depends on THIS class, and a constructor cycle would be a
     * needless price for a call that only happens on a denied login.
     */
    public function deviceCredentialLoginAvailable(User $user): bool
    {
        return app(DoctorDeviceWebAuthnLoginService::class)->canAssert($user);
    }

    /**
     * Is an already-established doctor session still allowed to be used?
     *
     * Called on every protected request under enforcement, because a
     * login-time check alone would let a session outlive the trust it was built
     * on: revoking a device or an authorization has to stop the session that is
     * open right now, not the next one.
     */
    public function denySessionReason(User $user, Request $request): ?string
    {
        if (! $this->enforcementEnabled()) {
            return null;
        }

        // Same narrowing as the browser path. Checked here too rather than at
        // login only: enforcement that applied at sign-in and then stopped
        // being re-evaluated is enforcement a session outlives.
        if (! $this->inEnforcementScope($user)) {
            return null;
        }

        if (! $request->hasSession()) {
            return self::DENY_NO_DEVICE_SESSION;
        }

        $deviceId = $request->session()->get(self::SESSION_DEVICE_ID);
        $authorizationId = $request->session()->get(self::SESSION_AUTHORIZATION_ID);
        $sessionDoctorId = $request->session()->get(self::SESSION_DOCTOR_ID);

        if (! is_int($deviceId) || ! is_int($authorizationId) || ! is_int($sessionDoctorId)) {
            return self::DENY_NO_DEVICE_SESSION;
        }

        // DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — how did this session get here?
        //
        // Read before anything is validated, because the answer decides WHICH
        // validation is the right one. A binding that cannot say how it was
        // earned is refused rather than interpreted: the permissive reading of
        // an unknown proof is the bug this sprint closes, and a session bound
        // before this build carries exactly that shape.
        $proof = DoctorSessionProof::fromSessionValues(
            $request->session()->get(self::SESSION_PROOF_TYPE),
            $request->session()->get(self::SESSION_WEBAUTHN_CREDENTIAL_ID),
        );

        if ($proof === null) {
            return self::DENY_SESSION_PROOF_UNKNOWN;
        }

        $doctor = $this->doctors->resolveForUser($user);

        if ($doctor === null) {
            return self::DENY_DOCTOR_NOT_LINKED;
        }

        // The session cannot claim to be a different doctor than the account
        // resolves to. Copying another tablet's session values gets you here.
        if ((int) $doctor->id !== $sessionDoctorId) {
            return self::DENY_DOCTOR_MISMATCH;
        }

        $device = DoctorDevice::query()->find($deviceId);

        if ($device === null) {
            return self::DENY_DEVICE_NOT_USABLE;
        }

        // The proof is re-verified AS ITSELF. A WebAuthn session is not rescued
        // by an Android keystore key sitting on the same tablet, and an Android
        // session is not ended by a WebAuthn credential being withdrawn.
        $proofDenial = $this->deviceProofDenyReason($device, $proof);

        if ($proofDenial !== null) {
            return $proofDenial;
        }

        $authorization = DoctorDeviceAuthorization::query()->find($authorizationId);

        if ($authorization === null
            || ! $authorization->isActive()
            || (int) $authorization->doctor_id !== (int) $doctor->id
            || (int) $authorization->doctor_device_id !== (int) $device->id) {
            return self::DENY_AUTHORIZATION_NOT_ACTIVE;
        }

        return null;
    }

    /**
     * May this device carry a session earned by THIS PARTICULAR proof?
     *
     * @see deviceProofDenyReason() for why a proof is refused
     */
    public function deviceUsableForProof(DoctorDevice $device, DoctorSessionProof $proof): bool
    {
        return $this->deviceProofDenyReason($device, $proof) === null;
    }

    /**
     * DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — is the proof that authenticated
     * this session still good, on its own terms?
     *
     * WHAT THIS REPLACED, AND WHY IT HAD TO GO
     *
     * There used to be a `deviceIdentityProven()` that asked "does this device
     * hold SOME acceptable proof?" — Android keystore key, or failing that, a
     * WebAuthn credential. That is the right question for provisioning and the
     * wrong one for a live session, and on a DUAL-PROOF device the difference
     * was a security hole rather than a nuance: the pilot tablet is ACTIVE,
     * `cryptographically_verified` and carries a keystore key, so the Android
     * branch answered "yes" before the WebAuthn flag or the credential was ever
     * consulted. A browser session established by WebAuthn therefore survived
     * both the kill switch and a credential revocation.
     *
     * The permissive method is not documented-around; it is deleted. A method
     * that can substitute one proof for another is a method that will be
     * called by someone who does not know it can.
     *
     * ADMINISTRATIVE STATE IS COMMON TO BOTH PROOFS
     *
     * `pending_approval`, `disabled` and `revoked` fail for either path. That
     * axis is deliberately separate from the identity axis and stays that way.
     */
    public function deviceProofDenyReason(DoctorDevice $device, DoctorSessionProof $proof): ?string
    {
        if (! $device->isActive()) {
            return self::DENY_DEVICE_NOT_USABLE;
        }

        if ($proof->isAndroidKeystore()) {
            // The Clinic App's KEYSTORE enrolment, and only that. A WebAuthn
            // credential on this device is not an Android proof and never
            // stands in for one — which is also why a WebAuthn-only device
            // cannot be reached by the app path at all: it has no fingerprint
            // for `DoctorAppLoginService` to find it by.
            return $device->isCryptographicallyVerified() && $device->public_key !== null
                ? null
                : self::DENY_DEVICE_NOT_USABLE;
        }

        // The flag is a real master switch: turning it off returns the
        // deployment to its previous behaviour, and that has to include a
        // browser session that is open at the time. An Android session is
        // untouched by it, because an Android session is not this proof.
        if (! $this->flags->enabled(DoctorDeviceWebAuthnLoginService::FLAG)) {
            return self::DENY_WEBAUTHN_LOGIN_DISABLED;
        }

        $credentialId = $proof->webAuthnCredentialId();

        if ($credentialId === null) {
            return self::DENY_SESSION_PROOF_UNKNOWN;
        }

        // Scoped to THIS device and filtered to un-revoked credentials, so all
        // three of "revoked", "names nothing" and "belongs to another tablet"
        // land here — a credential from a different device cannot keep this
        // session alive, however valid it is in its own right.
        $credential = app(DoctorDeviceWebAuthnCredentialRepositoryInterface::class)
            ->usableForDevice((int) $device->id)
            ->firstWhere('id', $credentialId);

        if ($credential === null) {
            return self::DENY_WEBAUTHN_CREDENTIAL_NOT_USABLE;
        }

        // Re-checked per request, not only at registration: a credential
        // admitted under a looser device-binding policy must stop working when
        // the policy is tightened, not merely stop being issued.
        if (! WebAuthnDeviceBinding::isAcceptable($credential->device_bound_verdict)) {
            return self::DENY_WEBAUTHN_CREDENTIAL_NOT_USABLE;
        }

        return null;
    }

    /**
     * The active authorization for a pair, or null. Read-only; grants nothing.
     */
    public function activeAuthorizationFor(int $doctorId, int $deviceId): ?DoctorDeviceAuthorization
    {
        $authorization = $this->authorizations->findPair($doctorId, $deviceId);

        return $authorization !== null && $authorization->isActive() ? $authorization : null;
    }

    /** The message a denied doctor sees. Deliberately actionable, never technical. */
    public function denialMessage(string $reason): string
    {
        return match ($reason) {
            self::DENY_DOCTOR_NOT_LINKED => 'Akun dokter belum terhubung ke data dokter. Hubungi administrator.',
            self::DENY_AUTHORIZATION_NOT_ACTIVE => 'Akses dokter untuk perangkat ini belum atau tidak lagi disetujui.',
            self::DENY_DEVICE_NOT_USABLE => 'Perangkat ini tidak lagi diizinkan untuk digunakan.',
            self::DENY_DOCTOR_MISMATCH => 'Sesi perangkat tidak sesuai dengan akun dokter ini.',
            // Deliberately identical wording for all three proof failures. A
            // doctor can act on "sign in again"; telling them WHICH proof was
            // withdrawn tells anyone holding the tablet how the estate is
            // configured, and does not help them.
            self::DENY_SESSION_PROOF_UNKNOWN,
            self::DENY_WEBAUTHN_LOGIN_DISABLED,
            self::DENY_WEBAUTHN_CREDENTIAL_NOT_USABLE => 'Sesi perangkat ini sudah tidak berlaku. Silakan masuk kembali dari perangkat yang disetujui.',
            default => 'Login dokter hanya dapat dilakukan melalui aplikasi klinik DaengtisiaMS pada perangkat yang disetujui.',
        };
    }
}
