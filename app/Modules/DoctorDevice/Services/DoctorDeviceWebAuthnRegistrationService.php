<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\DoctorDevice\Interfaces\DoctorDeviceWebAuthnCredentialRepositoryInterface;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnChallenge;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Support\WebAuthnCeremonyFactory;
use App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — enrolling a browser onto an already-registered
 * clinic device.
 *
 * THE WEBAUTHN "USER" HERE IS THE DEVICE, NOT THE DOCTOR
 *
 * This looks surprising and is the most important modelling decision in the
 * sprint, so it is worth spelling out.
 *
 * WebAuthn requires a user entity, and the obvious thing to put there is the
 * doctor. Doing that would bind the credential to one doctor and quietly make a
 * shared clinic tablet impossible: a second doctor at the same tablet would
 * need a second credential, a third a third, and revoking a doctor would mean
 * hunting credentials rather than revoking an authorization.
 *
 * A WebAuthn credential answers exactly one question — WHICH PIECE OF HARDWARE
 * IS PRESENT — so the entity it is issued to is the device. The `userHandle`
 * carried back in every assertion is the device uuid, which gives us a second,
 * independent binding between the signature and the device row.
 *
 * WHICH DOCTOR may use that hardware stays where it already lives:
 * `mst_doctor_device_authorizations`, which has supported many doctors per
 * device since Phase 3. One tablet, several doctors, revocable independently.
 *
 * REGISTRATION IS NOT APPROVAL
 *
 * Nothing here changes a device's status. A credential registered against a
 * `pending_approval` device is inert until a human approves the device through
 * the existing Approval → Device Dokter surface, and inert again the moment the
 * device is disabled or revoked. See DoctorDeviceWebAuthnLoginService, which
 * re-asserts all of it.
 *
 * WHY THERE IS NO SEPARATE "APPROVE THIS CREDENTIAL" STEP
 *
 * Because it would be theatre. The operator permitted to enrol a credential is
 * `manage_doctor_devices`, and that is the same permission that approves
 * devices and authorizations. A second approval an actor can grant themselves
 * is not a control. What protects against a rogue operator here is the audit
 * trail and the fact that the device, the authorization and the credential are
 * three separately revocable records — not a checkbox.
 */
class DoctorDeviceWebAuthnRegistrationService
{
    public function __construct(
        private readonly DoctorDeviceWebAuthnChallengeService $challenges,
        private readonly DoctorDeviceWebAuthnCredentialRepositoryInterface $credentials,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Build the options a browser needs for `navigator.credentials.create()`.
     *
     * @return array{options: PublicKeyCredentialCreationOptions, challenge: DoctorDeviceWebAuthnChallenge}
     */
    public function creationOptions(DoctorDevice $device, User $operator, Request $request): array
    {
        $relyingParty = WebAuthnRelyingParty::fromConfig();
        $relyingParty->assertUsable();

        if ($device->isRevoked()) {
            throw ValidationException::withMessages([
                'device' => 'Perangkat ini sudah dicabut dan tidak dapat didaftarkan lagi.',
            ]);
        }

        $challenge = $this->challenges->issue(
            DoctorDeviceWebAuthnChallenge::CEREMONY_REGISTRATION,
            $request,
            (int) $operator->id,
            (int) $device->id,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($relyingParty->name(), $relyingParty->id()),
            $this->userEntityFor($device),
            Base64UrlSafe::decodeNoPadding($challenge->challenge),
            $this->algorithmParameters(),
            AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: $this->attachment(),
                userVerification: $relyingParty->userVerification(),
                // A discoverable credential is not needed: the assertion is
                // always preceded by a password, so the server already knows
                // which credentials to allow and can send an explicit list.
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED,
            ),
            // See WebAuthnCeremonyFactory for why attestation is not collected.
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->excludeCredentialsFor($device),
            (int) config('webauthn.ceremony.timeout_milliseconds', 90000),
        );

        $this->auditLogs->log(
            'mst_doctor_devices',
            (int) $device->id,
            'DOCTOR_DEVICE_WEBAUTHN_REGISTRATION_INITIATED',
            null,
            ['challenge_uuid' => $challenge->uuid],
            $operator,
        );

        return ['options' => $options, 'challenge' => $challenge];
    }

    /**
     * Verify an attestation response and store the credential.
     *
     * @param  array<string,mixed>  $credentialPayload  raw `navigator.credentials.create()` result
     *
     * @throws ValidationException on any failure
     */
    public function register(
        DoctorDevice $device,
        User $operator,
        Request $request,
        array $credentialPayload,
    ): DoctorDeviceWebAuthnCredential {
        $relyingParty = WebAuthnRelyingParty::fromConfig();
        $relyingParty->assertUsable();

        $factory = new WebAuthnCeremonyFactory($relyingParty);

        $publicKeyCredential = $this->deserialize($factory, $credentialPayload, $device, $operator);

        $response = $publicKeyCredential->response;

        if (! $response instanceof AuthenticatorAttestationResponse) {
            $this->deny($device, $operator, 'not_an_attestation_response');
        }

        // Burn the nonce BEFORE verifying. A rolled-back burn would turn this
        // into an unlimited retry oracle — see the challenge service.
        $challenge = $this->challenges->claim(
            $this->clientDataChallenge($response),
            DoctorDeviceWebAuthnChallenge::CEREMONY_REGISTRATION,
            $request,
            (int) $operator->id,
        );

        if ($challenge === null || (int) $challenge->doctor_device_id !== (int) $device->id) {
            $this->deny($device, $operator, 'challenge_not_claimable');
        }

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($relyingParty->name(), $relyingParty->id()),
            $this->userEntityFor($device),
            Base64UrlSafe::decodeNoPadding($challenge->challenge),
            $this->algorithmParameters(),
            AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: $this->attachment(),
                userVerification: $relyingParty->userVerification(),
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_DISCOURAGED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->excludeCredentialsFor($device),
            (int) config('webauthn.ceremony.timeout_milliseconds', 90000),
        );

        try {
            $record = $factory->attestationValidator()->check(
                $response,
                $options,
                $relyingParty->id(),
            );
        } catch (Throwable) {
            // The exception text can describe which check failed. That is for
            // the audit trail, not for a client that may be probing.
            $this->deny($device, $operator, 'attestation_rejected');
        }

        $verdict = WebAuthnDeviceBinding::verdict($record->backupEligible);

        if (! WebAuthnDeviceBinding::isAcceptable($verdict)) {
            $this->auditLogs->log(
                'mst_doctor_devices',
                (int) $device->id,
                'DOCTOR_DEVICE_WEBAUTHN_REGISTRATION_REJECTED',
                null,
                ['reason' => 'device_binding_policy', 'verdict' => $verdict],
                $operator,
            );

            throw ValidationException::withMessages([
                'credential' => 'Kredensial ini dapat disinkronkan ke perangkat lain, sehingga tidak memenuhi syarat perangkat klinik tepercaya.',
            ]);
        }

        if ($relyingParty->requiresUserVerification() && $record->uvInitialized !== true) {
            $this->deny($device, $operator, 'user_verification_missing');
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);

        // A credential id identifies one key pair on one authenticator. The
        // same id under two devices would mean one of the records is wrong
        // about which hardware is present, so this is refused rather than
        // reassigned.
        if ($this->credentials->findByCredentialId($credentialId) !== null) {
            $this->deny($device, $operator, 'credential_already_registered');
        }

        $credential = DB::transaction(function () use ($device, $operator, $record, $credentialId, $verdict) {
            return DoctorDeviceWebAuthnCredential::query()->create([
                'uuid' => (string) Str::uuid(),
                'doctor_device_id' => $device->id,
                'credential_id' => $credentialId,
                'public_key' => base64_encode($record->credentialPublicKey),
                'signature_counter' => $record->counter,
                'aaguid' => $record->aaguid->__toString(),
                'transports' => $record->transports,
                'attachment' => $this->attachment(),
                'user_verified' => $record->uvInitialized === true,
                'backup_eligible' => $record->backupEligible,
                'backup_state' => $record->backupStatus,
                'device_bound_verdict' => $verdict,
                'attestation_format' => $record->attestationType,
                'registered_at' => now(),
                'registered_by' => $operator->id,
            ]);
        });

        $this->auditLogs->log(
            'trx_doctor_device_webauthn_credentials',
            (int) $credential->id,
            'DOCTOR_DEVICE_WEBAUTHN_REGISTERED',
            null,
            [
                'doctor_device_id' => $device->id,
                'device_status' => $device->status,
                'device_bound_verdict' => $verdict,
                'user_verified' => $credential->user_verified,
            ],
            $operator,
        );

        return $credential;
    }

    /**
     * Revoke a credential. Fail-closed and irreversible by design: recovery is
     * a fresh enrolment, not an un-revoke, so a revocation cannot be quietly
     * undone by whoever caused it.
     */
    public function revoke(
        DoctorDeviceWebAuthnCredential $credential,
        User $actor,
        string $reason,
    ): DoctorDeviceWebAuthnCredential {
        if ($credential->isRevoked()) {
            return $credential;
        }

        DB::transaction(function () use ($credential, $actor, $reason) {
            $credential->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoked_reason' => $reason,
            ])->save();
        });

        $this->auditLogs->log(
            'trx_doctor_device_webauthn_credentials',
            (int) $credential->id,
            'DOCTOR_DEVICE_WEBAUTHN_REVOKED',
            null,
            ['doctor_device_id' => $credential->doctor_device_id, 'reason' => $reason],
            $actor,
        );

        return $credential->refresh();
    }

    /**
     * The device, expressed as a WebAuthn user entity.
     *
     * The handle is the device uuid rather than its integer id: a uuid is not
     * guessable, and the handle travels back to us in every assertion.
     */
    private function userEntityFor(DoctorDevice $device): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            $device->device_name,
            (string) $device->uuid,
            $device->device_name,
        );
    }

    /** @return list<PublicKeyCredentialParameters> */
    private function algorithmParameters(): array
    {
        $algorithms = (array) config('webauthn.algorithms', [-7, -257]);

        return array_map(
            static fn ($alg) => PublicKeyCredentialParameters::create('public-key', (int) $alg),
            array_values($algorithms),
        );
    }

    /**
     * Credentials the authenticator should refuse to duplicate.
     *
     * Without this an operator re-running enrolment on a tablet that is already
     * enrolled silently creates a second credential for the same authenticator,
     * and the registry stops matching the hardware.
     *
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function excludeCredentialsFor(DoctorDevice $device): array
    {
        return $this->credentials->usableForDevice((int) $device->id)
            ->map(static fn (DoctorDeviceWebAuthnCredential $credential) => PublicKeyCredentialDescriptor::create(
                'public-key',
                Base64UrlSafe::decodeNoPadding($credential->credential_id),
            ))
            ->values()
            ->all();
    }

    private function attachment(): ?string
    {
        $value = trim((string) config('webauthn.ceremony.authenticator_attachment', 'platform'));

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $payload */
    private function deserialize(
        WebAuthnCeremonyFactory $factory,
        array $payload,
        DoctorDevice $device,
        User $operator,
    ): PublicKeyCredential {
        try {
            return $factory->serializer()->deserialize(
                json_encode($payload, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json',
            );
        } catch (Throwable) {
            $this->deny($device, $operator, 'malformed_credential_payload');
        }
    }

    /**
     * The challenge the CLIENT says it signed, read from clientDataJSON.
     *
     * This value is untrusted — it is only used to look up which nonce to burn.
     * Whether it is the nonce that was actually signed is decided by the
     * library's own challenge check against the options we rebuild from the
     * database row.
     */
    private function clientDataChallenge(AuthenticatorAttestationResponse $response): string
    {
        return Base64UrlSafe::encodeUnpadded($response->clientDataJSON->challenge);
    }

    private function deny(DoctorDevice $device, User $operator, string $reason): never
    {
        $this->auditLogs->log(
            'mst_doctor_devices',
            (int) $device->id,
            'DOCTOR_DEVICE_WEBAUTHN_REGISTRATION_REJECTED',
            null,
            ['reason' => $reason],
            $operator,
        );

        throw ValidationException::withMessages([
            'credential' => 'Pendaftaran perangkat tidak dapat diselesaikan.',
        ]);
    }
}
