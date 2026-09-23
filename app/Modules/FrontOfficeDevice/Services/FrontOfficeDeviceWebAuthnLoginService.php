<?php

namespace App\Modules\FrontOfficeDevice\Services;

use App\Models\User;
use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceWebAuthnCredentialRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnChallenge;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnChallengeService;
use App\Modules\DoctorDevice\Support\WebAuthnCeremonyFactory;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Modules\LabOrder\Services\AuditLogService;
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
 * REVISION-FRONT-OFFICE-BRANCH-DEVICE-LOCK-1 — how a front-desk browser proves
 * WHICH TABLET it is.
 *
 * WHY THIS IS NOT A SECOND DEVICE-SECURITY TRUTH.
 *
 * Every authority this class consults already existed and is unchanged:
 * `mst_doctor_devices` is still the only device registry, its `branch_id` is
 * still the only device branch ownership, `trx_doctor_device_webauthn_credentials`
 * is still the only credential store, and the challenge service, relying party
 * and ceremony factory are the doctor programme's own. Nothing is duplicated and
 * no table is added.
 *
 * What differs is ONE thing: how the candidate credentials are chosen.
 *
 *   Doctor path:  doctor -> authorization -> device -> credentials
 *   This path:    required branch -> approved devices -> credentials
 *
 * That substitution is sound because THE CREDENTIAL NEVER NAMED A CLINICIAN.
 * `trx_doctor_device_webauthn_credentials` has no doctor column; it is keyed by
 * `doctor_device_id`, and the WebAuthn user handle it stores is the DEVICE uuid.
 * The credential has always identified the tablet. The doctor-shaped lookup was
 * the doctor programme's way of asking "which tablets may this clinician use?",
 * and a front-desk account answers that question from its pinned branch instead
 * of from an authorization row it has no reason to own.
 *
 * WHICH ALSO MEANS THE PASSWORD IS DOING REAL WORK.
 *
 * The assertion proves possession of a key held by an approved tablet in the
 * right branch. It does NOT prove who is holding the tablet — a device proof
 * cannot. The account is established by the password step that ran before this
 * ceremony, and the two together are the owner's rule: ACCOUNT + DEVICE +
 * BRANCH. Neither half is sufficient, which is why neither is skipped.
 *
 * ENROLMENT IS NOT HERE, DELIBERATELY.
 *
 * This class only ASSERTS an existing credential. Registering one on a
 * front-desk browser is a supervised ceremony on real hardware, and the owner
 * has withheld authorization for it until after this ships inert. So there is
 * no code path here that can create a credential, and none that can register a
 * device.
 */
class FrontOfficeDeviceWebAuthnLoginService
{
    public const SESSION_PENDING_USER_ID = 'front_office_device_webauthn.pending_user_id';

    public const SESSION_PENDING_EXPIRES_AT = 'front_office_device_webauthn.pending_expires_at';

    /**
     * Long enough for a person to find the tablet and touch the sensor, short
     * enough that an abandoned ceremony stops being a standing invitation.
     */
    public const PENDING_TTL_SECONDS = 300;

    public function __construct(
        private readonly FrontOfficeBranchDeviceLockService $lock,
        private readonly DoctorDeviceWebAuthnChallengeService $challenges,
        private readonly DoctorDeviceWebAuthnCredentialRepositoryInterface $credentials,
        private readonly BranchRepositoryInterface $branches,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Can this account be asked for an assertion at all?
     *
     * False while the lock is off, false for an account that is not armed, and
     * false for an armed account with no usable credential on any approved
     * device in its branch — because sending someone to a ceremony that cannot
     * succeed is worse than telling them plainly that it cannot.
     */
    public function canAssert(User $user): bool
    {
        if (! $this->lock->appliesTo($user)) {
            return false;
        }

        return $this->candidateCredentials($user)->isNotEmpty();
    }

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
     * The account that passed the password step and is waiting at the sensor.
     *
     * This marker grants NOTHING on its own: the privileged session was torn
     * down before it was written, so what survives is a note of which account
     * passed a password, which cannot read a patient record.
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
     * The devices an armed account may prove itself on: approved hardware
     * registered to the branch that account is pinned to.
     *
     * Resolved server-side from the cohort mapping and `mst_doctor_devices`,
     * never from anything the browser sent.
     *
     * @return Collection<int, DoctorDevice>
     */
    public function approvedDevicesFor(User $user): Collection
    {
        $branchId = $this->lock->requiredBranchIdFor($user);

        if ($branchId === null) {
            return collect();
        }

        return DoctorDevice::query()
            ->where('branch_id', $branchId)
            ->where('status', DoctorDevice::STATUS_ACTIVE)
            ->where('identity_state', DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED)
            ->where('enrollment_status', DoctorDevice::ENROLLMENT_VERIFIED)
            ->orderBy('id')
            ->get();
    }

    /**
     * Candidate credentials, gathered per approved device through the canonical
     * repository. A credential on a device in another branch is never a
     * candidate, so a wrong-branch tablet is refused before any cryptography
     * happens — and refused again after it, in completeLogin.
     *
     * @return Collection<int, DoctorDeviceWebAuthnCredential>
     */
    public function candidateCredentials(User $user): Collection
    {
        $credentials = collect();

        foreach ($this->approvedDevicesFor($user) as $device) {
            foreach ($this->credentials->usableForDevice((int) $device->id) as $credential) {
                if (WebAuthnDeviceBinding::isAcceptable($credential->device_bound_verdict)) {
                    $credentials->push($credential);
                }
            }
        }

        return $credentials->values();
    }

    /**
     * @return array{options: PublicKeyCredentialRequestOptions, challenge: DoctorDeviceWebAuthnChallenge}
     */
    public function requestOptions(User $user, Request $request): array
    {
        $this->assertArmed($user);

        $relyingParty = WebAuthnRelyingParty::fromConfig();
        $relyingParty->assertUsable();

        $usable = $this->candidateCredentials($user);

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
     * Verify the assertion and, only if everything still holds, establish the
     * device-bound session.
     *
     * Every condition checked in requestOptions() is checked AGAIN here against
     * the credential the browser actually returned. The allow-list sent to the
     * browser is a convenience for the authenticator, never a security
     * boundary: a browser may return any credential it likes, so the one it
     * returns is re-resolved to its device and re-tested for approval and
     * branch from scratch.
     */
    public function completeLogin(User $user, Request $request, array $credentialPayload): User
    {
        $this->assertArmed($user);

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

        // The challenge is claimed once, bound to this ceremony, this session
        // and this user id. A replayed or cross-session challenge is not
        // claimable.
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

        // Unknown credential, and revoked credential, both land here.
        if ($credential === null || ! $credential->isUsable()) {
            $this->deny($credential, $user, 'credential_not_usable');
        }

        if (! WebAuthnDeviceBinding::isAcceptable($credential->device_bound_verdict)) {
            $this->deny($credential, $user, 'device_binding_policy');
        }

        $device = DoctorDevice::query()->find($credential->doctor_device_id);

        if ($device === null) {
            $this->deny($credential, $user, 'device_missing');
        }

        // Re-assert approval on the device the browser actually used, not on
        // the ones we offered.
        if (! $device->isActive()
            || ! $device->isCryptographicallyVerified()
            || ! $device->isEnrollmentVerified()) {
            $this->deny($credential, $user, 'device_not_usable');
        }

        // THE BRANCH COMPARISON, made before the session exists. A credential
        // asserted from another branch's approved tablet is a valid credential
        // on a device this account may not use, and it is refused here rather
        // than being bound and then rejected by the gate.
        $requiredBranchId = $this->lock->requiredBranchIdFor($user);

        if ($requiredBranchId === null) {
            $this->deny($credential, $user, 'account_branch_invalid');
        }

        if ($device->branch_id === null || (int) $device->branch_id !== $requiredBranchId) {
            $this->deny($credential, $user, 'device_branch_mismatch');
        }

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            Base64UrlSafe::decodeNoPadding($challenge->challenge),
            $relyingParty->id(),
            $this->descriptorsFor($this->candidateCredentials($user)),
            $relyingParty->userVerification(),
            (int) config('webauthn.ceremony.timeout_milliseconds', 90000),
        );

        try {
            $verified = $factory->assertionValidator()->check(
                $this->credentialRecordFor($credential, $device),
                $response,
                $requestOptions,
                $relyingParty->id(),
                (string) $device->uuid,
            );
        } catch (Throwable) {
            $this->deny($credential, $user, 'assertion_rejected');
        }

        if ($relyingParty->requiresUserVerification()
            && ! $response->authenticatorData->isUserVerified()) {
            $this->deny($credential, $user, 'user_verification_missing');
        }

        // Clone-detection counter, advanced monotonically.
        DB::transaction(function () use ($credential, $verified) {
            $credential->forceFill([
                'signature_counter' => max((int) $credential->signature_counter, (int) $verified->counter),
                'last_used_at' => now(),
            ])->save();
        });

        Auth::guard('web')->login($user);

        // Session fixation defence, exactly as the ordinary login does. The
        // binding is written AFTER regeneration so it lands in the new session.
        $request->session()->regenerate();

        $this->lock->bind($request, (int) $device->id, (int) $credential->id);

        $this->forgetPending($request);

        $device->forceFill(['last_seen_at' => now()])->save();

        $this->auditLogs->log(
            'trx_doctor_device_webauthn_credentials',
            (int) $credential->id,
            'FRONT_OFFICE_DEVICE_WEBAUTHN_LOGIN_SUCCESS',
            null,
            [
                'doctor_device_id' => $device->id,
                'branch_id' => $device->branch_id,
            ],
            $user,
        );

        return $user;
    }

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
    private function descriptorsFor(Collection $credentials): array
    {
        return $credentials
            ->map(fn (DoctorDeviceWebAuthnCredential $credential) => PublicKeyCredentialDescriptor::create(
                'public-key',
                Base64UrlSafe::decodeNoPadding($credential->credential_id),
                $credential->transports ?? [],
            ))
            ->values()
            ->all();
    }

    /**
     * The ceremony is reachable only for an armed account. With the lock off
     * this refuses outright, so switching the flag off can never leave a usable
     * side door that mints device bindings.
     */
    private function assertArmed(User $user): void
    {
        if (! $this->lock->appliesTo($user)) {
            throw ValidationException::withMessages([
                'credential' => 'Login perangkat Front Office belum aktif untuk akun ini.',
            ]);
        }
    }

    private function deny(?DoctorDeviceWebAuthnCredential $credential, User $user, string $reason): never
    {
        $this->auditLogs->log(
            'trx_doctor_device_webauthn_credentials',
            $credential === null ? null : (int) $credential->id,
            'FRONT_OFFICE_DEVICE_WEBAUTHN_LOGIN_REJECTED',
            null,
            ['reason' => $reason],
            $user,
        );

        throw ValidationException::withMessages([
            'credential' => 'Perangkat ini belum terdaftar atau tidak diizinkan untuk akun Front Office ini.',
        ]);
    }
}
