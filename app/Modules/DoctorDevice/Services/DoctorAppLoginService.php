<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Models\DoctorDeviceLoginChallenge;
use App\Modules\DoctorDevice\Models\DoctorDeviceLoginTicket;
use App\Modules\DoctorDevice\Support\DeviceKeyMaterial;
use App\Modules\DoctorDevice\Support\DeviceProofMessage;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — the doctor login
 * attempt as seen from the Clinic App.
 *
 * THE ORDER OF OPERATIONS IS THE SECURITY PROPERTY
 *
 *   1. the device signs a server-issued nonce   → cryptographic possession
 *   2. the nonce is burned in its own committed transaction
 *   3. the signature is verified against the enrolled public key
 *   4. the credentials are validated (NO session is created)
 *   5. the account is resolved to a linked, active Doctor
 *   6. the device row is resolved, or provisioned as `pending_approval`
 *   7. the (doctor, device) authorization is resolved or requested
 *   8. a login ticket is minted ONLY if enforcement is on and everything is ACTIVE
 *
 * Nothing is written before step 4 succeeds. So a wrong password creates
 * nothing, a forged signature creates nothing, and an account that is not a
 * doctor creates nothing — which is what stops this endpoint from becoming a
 * way to spray pending requests at an approver using stolen email addresses.
 *
 * Step 2 is separate from step 3 for the reason Phase 3 documents: burning the
 * nonce inside the same transaction as the verification means a denial rolls
 * the burn back, and the attacker may grind signatures against one fixed nonce
 * forever.
 *
 * WHAT A SUCCESSFUL CALL IS NOT
 *
 * It is not a session. No cookie is set, no guard is logged in. The only thing
 * that can become a session is a redeemed ticket, and while enforcement is off
 * no ticket is ever minted.
 */
class DoctorAppLoginService
{
    public const CHALLENGE_TTL_SECONDS = 120;

    /** Seconds. Long enough for a WebView to navigate, short enough to matter. */
    public const TICKET_TTL_SECONDS = 60;

    private const NONCE_BYTES = 32;

    private const TICKET_BYTES = 32;

    /** What the app should do next. Mirrored by the Kotlin state machine. */
    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_ACTIVE = 'active';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_REVOKED = 'revoked';

    public function __construct(
        private readonly DoctorIdentityResolver $doctors,
        private readonly DoctorDeviceAuthorizationService $authorizations,
        private readonly DoctorAppLoginGate $gate,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Issue a nonce for a public-key fingerprint.
     *
     * Keyed by FINGERPRINT rather than by a device row, because the first login
     * from new hardware happens before any device exists. The fingerprint is
     * what the signed message commits to anyway, so a challenge issued for one
     * key cannot be answered by another.
     *
     * The fingerprint must correspond to something the server has actually seen
     * — an enrolment or a device — otherwise anyone could mint nonces for
     * arbitrary strings. The denial is opaque and identical either way, so the
     * endpoint cannot be used to test whether a key is known.
     */
    public function issueChallenge(string $fingerprint): DoctorDeviceLoginChallenge
    {
        $fingerprint = strtolower(trim($fingerprint));

        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            $this->deny('malformed_fingerprint');
        }

        $device = DoctorDevice::query()->where('public_key_fingerprint', $fingerprint)->first();
        $enrollment = $device === null
            ? DoctorDeviceEnrollment::query()->where('public_key_fingerprint', $fingerprint)->latest('id')->first()
            : null;

        if ($device === null && $enrollment === null) {
            $this->deny('unknown_fingerprint');
        }

        // A revoked device gets nothing, not even a nonce. Revocation is
        // terminal and there is no reason to let it back into the protocol.
        if ($device !== null && $device->isRevoked()) {
            $this->deny('device_revoked');
        }

        $challenge = new DoctorDeviceLoginChallenge;
        $challenge->forceFill([
            'uuid' => (string) Str::uuid(),
            'public_key_fingerprint' => $fingerprint,
            'doctor_device_id' => $device?->id,
            'nonce' => bin2hex(random_bytes(self::NONCE_BYTES)),
            'purpose' => DeviceProofMessage::PURPOSE_DOCTOR_LOGIN,
            'expires_at' => now()->addSeconds(self::CHALLENGE_TTL_SECONDS),
        ])->save();

        return $challenge->refresh();
    }

    /**
     * A doctor login attempt from the Clinic App.
     *
     * @param  array{email: string, password: string, nonce: string, signature: string}  $data
     * @return array{
     *     outcome: string,
     *     authorization: DoctorDeviceAuthorization,
     *     device: DoctorDevice,
     *     doctor: Doctor,
     *     enforcement_active: bool,
     *     login_ticket: string|null,
     *     login_ticket_expires_at: Carbon|null,
     * }
     */
    public function attempt(array $data): array
    {
        // ---- 1/2/3: cryptographic possession, before anything else happens.
        $challenge = $this->claimChallenge((string) ($data['nonce'] ?? ''));
        $publicKey = $this->resolvePublicKey((string) $challenge->public_key_fingerprint);

        $this->verifySignature($challenge, $publicKey, (string) ($data['signature'] ?? ''));

        // ---- 4: credentials. Validated WITHOUT establishing a session.
        $user = $this->validateCredentials(
            (string) ($data['email'] ?? ''),
            (string) ($data['password'] ?? ''),
        );

        // ---- 5: this must be a linked, active doctor. Identity comes from
        // mst_doctors.user_id only — never from a name or an email match.
        if (! $this->gate->appliesTo($user)) {
            $this->deny('not_a_doctor_account');
        }

        $doctor = $this->doctors->resolveForUser($user);

        if ($doctor === null) {
            $this->deny('doctor_not_linked');
        }

        if ($doctor->is_active !== true) {
            $this->deny('doctor_inactive');
        }

        // ---- 6: resolve or provision the physical device.
        $device = $this->resolveOrProvisionDevice($challenge, $publicKey, $doctor);

        // ---- 7: resolve or request the (doctor, device) authorization.
        $authorization = $this->authorizations->resolveOrRequest(
            $doctor,
            $device,
            $user,
            DoctorDeviceAuthorization::SOURCE_APP_LOGIN,
        );

        $this->auditLogs->log(
            'mst_doctor_device_authorizations',
            (int) $authorization->id,
            'DOCTOR_DEVICE_LOGIN_REQUESTED',
            null,
            [
                'status' => $authorization->status,
                'doctor_id' => $doctor->id,
                'doctor_device_id' => $device->id,
                'device_status' => $device->status,
            ],
            $user,
        );

        $outcome = $this->outcomeFor($authorization);

        // ---- 8: a ticket is a receipt for a decision already made, and it is
        // only ever made when enforcement is on AND everything is ACTIVE.
        $ticket = null;
        $ticketExpiry = null;

        if ($outcome === self::OUTCOME_ACTIVE
            && $this->gate->enforcementEnabled()
            && $this->gate->deviceUsable($device)) {
            [$ticket, $ticketExpiry] = $this->mintLoginTicket($user, $doctor, $device, $authorization);
        }

        if ($outcome !== self::OUTCOME_ACTIVE) {
            $this->auditLogs->log(
                'mst_doctor_device_authorizations',
                (int) $authorization->id,
                'DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED',
                null,
                ['status' => $authorization->status, 'outcome' => $outcome],
                $user,
            );
        }

        return [
            'outcome' => $outcome,
            'authorization' => $authorization,
            'device' => $device,
            'doctor' => $doctor,
            'enforcement_active' => $this->gate->enforcementEnabled(),
            'login_ticket' => $ticket,
            'login_ticket_expires_at' => $ticketExpiry,
        ];
    }

    /**
     * The app polls its own authorization by uuid. Coarse state only — enough
     * to pick a screen, never enough to learn about anyone else.
     */
    public function statusByUuid(string $uuid): ?DoctorDeviceAuthorization
    {
        return DoctorDeviceAuthorization::query()->with(['device'])->where('uuid', $uuid)->first();
    }

    public function hashTicket(string $token): string
    {
        // Keyed hash so redemption is one indexed lookup while the plaintext is
        // never stored. APP_KEY is the key material.
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /**
     * Claim the nonce in its OWN committed transaction, under a row lock, and
     * only then let verification proceed. See the class docblock.
     */
    private function claimChallenge(string $nonce): DoctorDeviceLoginChallenge
    {
        if ($nonce === '') {
            $this->deny('missing_nonce');
        }

        $claim = DB::transaction(function () use ($nonce) {
            $challenge = DoctorDeviceLoginChallenge::query()
                ->lockForUpdate()
                ->where('nonce', $nonce)
                ->first();

            if ($challenge === null) {
                return null;
            }

            if (! $challenge->isUsable()) {
                return ['challenge' => $challenge, 'claimed' => false];
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            return ['challenge' => $challenge, 'claimed' => true];
        });

        if ($claim === null) {
            $this->deny('unknown_challenge');
        }

        if ($claim['claimed'] !== true) {
            $this->deny($claim['challenge']->isConsumed() ? 'challenge_replayed' : 'challenge_expired');
        }

        return $claim['challenge'];
    }

    /** The enrolled public key for a fingerprint: the device's, or its enrolment's. */
    private function resolvePublicKey(string $fingerprint): string
    {
        $device = DoctorDevice::query()->where('public_key_fingerprint', $fingerprint)->first();

        if ($device !== null) {
            if ($device->isRevoked()) {
                $this->deny('device_revoked');
            }

            if ($device->public_key === null) {
                $this->deny('device_missing_key');
            }

            return (string) $device->public_key;
        }

        $enrollment = DoctorDeviceEnrollment::query()
            ->where('public_key_fingerprint', $fingerprint)
            ->latest('id')
            ->first();

        if ($enrollment === null) {
            $this->deny('unknown_fingerprint');
        }

        // A pairing the administrator explicitly refused does not get to become
        // a doctor login attempt.
        if ($enrollment->status === DoctorDeviceEnrollment::STATUS_REJECTED) {
            $this->deny('enrollment_rejected');
        }

        return (string) $enrollment->public_key;
    }

    private function verifySignature(
        DoctorDeviceLoginChallenge $challenge,
        string $publicKey,
        string $signatureBase64,
    ): void {
        $pem = DeviceKeyMaterial::toPem($publicKey);

        if ($pem === null) {
            $this->deny('unreadable_public_key');
        }

        $message = DeviceProofMessage::build(
            (string) $challenge->purpose,
            (string) $challenge->nonce,
            (string) $challenge->public_key_fingerprint,
        );

        $signature = base64_decode(preg_replace('/\s+/', '', $signatureBase64) ?? '', true);

        if ($signature === false || $signature === '') {
            $this->deny('malformed_signature');
        }

        // Delegated to OpenSSL; 1 means valid, 0 invalid, -1 error. Only 1 passes.
        if (openssl_verify($message, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            $this->deny('signature_invalid');
        }
    }

    /**
     * Validate credentials without logging anybody in.
     *
     * `Hash::check` against a looked-up user rather than `Auth::attempt`,
     * because attempting would start a session on a stateless channel. The
     * dummy hash on the miss path keeps the timing of "no such account" and
     * "wrong password" comparable, so this endpoint is not a user-enumeration
     * oracle.
     */
    private function validateCredentials(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            Hash::check($password, '$2y$12$'.str_repeat('x', 53));
            $this->deny('invalid_credentials');
        }

        if (! Hash::check($password, (string) $user->password)) {
            $this->deny('invalid_credentials');
        }

        return $user;
    }

    /**
     * The device row for this key, provisioning one when the hardware is new.
     *
     * A provisioned device is `pending_approval`: registered, key-proven, and
     * trusted by nothing. `identity_state` becomes
     * `cryptographically_verified` because that records a FACT — this hardware
     * holds the private key — and a fact is not a permission. Every gate that
     * matters asks for `status === active`, which only an approval grants.
     */
    private function resolveOrProvisionDevice(
        DoctorDeviceLoginChallenge $challenge,
        string $publicKey,
        Doctor $doctor,
    ): DoctorDevice {
        $fingerprint = (string) $challenge->public_key_fingerprint;

        $existing = DoctorDevice::query()->where('public_key_fingerprint', $fingerprint)->first();

        if ($existing !== null) {
            if ($existing->isRevoked()) {
                $this->deny('device_revoked');
            }

            if ($existing->isDisabled()) {
                $this->deny('device_disabled');
            }

            // Proof of possession is worth recording even on an unapproved
            // device: it is what lets one approval cover both halves later.
            if (! $existing->isCryptographicallyVerified()) {
                $existing->forceFill([
                    'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
                    'verified_at' => $existing->verified_at ?? now(),
                ])->save();
            }

            $existing->forceFill(['last_verified_at' => now(), 'last_seen_at' => now()])->save();

            return $existing->refresh();
        }

        $enrollment = DoctorDeviceEnrollment::query()
            ->where('public_key_fingerprint', $fingerprint)
            ->latest('id')
            ->first();

        $branchId = $this->resolveDeviceBranchId($doctor);

        return DB::transaction(function () use ($fingerprint, $publicKey, $enrollment, $branchId, $doctor) {
            // Re-read under the transaction: two first-logins from the same
            // tablet must not both provision a device row. `public_key_
            // fingerprint` is UNIQUE, so at worst one insert loses and we adopt
            // the winner.
            $raced = DoctorDevice::query()->lockForUpdate()
                ->where('public_key_fingerprint', $fingerprint)->first();

            if ($raced !== null) {
                return $raced;
            }

            $device = new DoctorDevice;
            $device->forceFill([
                'uuid' => (string) Str::uuid(),
                'device_name' => $this->provisionalDeviceName($enrollment, $fingerprint),
                'branch_id' => $branchId,
                // NOT active. Registered and key-proven, trusted by nothing.
                'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
                'identity_state' => DoctorDevice::IDENTITY_CRYPTOGRAPHICALLY_VERIFIED,
                'enrollment_status' => DoctorDevice::ENROLLMENT_PENDING,
                'platform' => $enrollment?->platform,
                'device_model' => $enrollment?->device_model,
                'os_version' => $enrollment?->os_version,
                'app_version' => $enrollment?->app_version,
                'public_key' => $publicKey,
                'public_key_fingerprint' => $fingerprint,
                'key_algorithm' => $enrollment?->key_algorithm ?? DeviceKeyMaterial::ALGORITHM_EC_P256_SHA256,
                'enrollment_requested_at' => $enrollment?->created_at ?? now(),
                'registered_at' => now(),
                'verified_at' => now(),
                'last_verified_at' => now(),
                'last_seen_at' => now(),
            ])->save();

            if ($enrollment !== null && $enrollment->doctor_device_id === null) {
                $enrollment->forceFill(['doctor_device_id' => $device->id])->save();
            }

            $this->auditLogs->log('mst_doctor_devices', (int) $device->id, 'DOCTOR_DEVICE_AUTO_REGISTERED', null, [
                'status' => $device->status,
                'branch_id' => $device->branch_id,
                'fingerprint' => substr($fingerprint, 0, 12),
                'requested_for_doctor_id' => $doctor->id,
            ], null);

            return $device->refresh();
        });
    }

    /**
     * The branch a provisional device is filed under.
     *
     * Server-resolved from the doctor's own practising branches intersected
     * with active, RME-enabled branches. A request value is never consulted, so
     * a device cannot be filed into a branch by asking.
     *
     * When a doctor practises at several branches the earliest is used as the
     * INTENDED branch. That is a placement, not a permission: the device is
     * `pending_approval`, the approval screen shows the branch, and Master Data
     * → Device Dokter can correct it before anyone approves. Guessing here can
     * only ever mis-file a device that grants nothing.
     */
    private function resolveDeviceBranchId(Doctor $doctor): int
    {
        $branchId = $doctor->branches()
            ->where('mst_branches.is_active', true)
            ->where('mst_branches.is_rme_enabled', true)
            ->orderBy('mst_branches.id')
            ->value('mst_branches.id');

        if ($branchId === null) {
            // No RME branch means no legitimate place for this hardware. Refuse
            // rather than invent a branch — MAIN is not a clinic.
            $this->deny('doctor_without_rme_branch');
        }

        return (int) $branchId;
    }

    /**
     * A name unique inside its branch without asking anyone to type one.
     * The fingerprint prefix carries the uniqueness (the fingerprint itself is
     * globally unique), the model carries the recognisability.
     */
    private function provisionalDeviceName(?DoctorDeviceEnrollment $enrollment, string $fingerprint): string
    {
        $model = trim((string) ($enrollment?->device_model ?? ''));
        $label = $model === '' ? 'Perangkat Klinik' : $model;

        return mb_substr($label, 0, 130).' ('.substr($fingerprint, 0, 12).')';
    }

    /**
     * @return array{0: string, 1: Carbon}
     */
    private function mintLoginTicket(
        User $user,
        Doctor $doctor,
        DoctorDevice $device,
        DoctorDeviceAuthorization $authorization,
    ): array {
        $token = bin2hex(random_bytes(self::TICKET_BYTES));
        $expiresAt = now()->addSeconds(self::TICKET_TTL_SECONDS);

        $ticket = new DoctorDeviceLoginTicket;
        $ticket->forceFill([
            'uuid' => (string) Str::uuid(),
            'token_hash' => $this->hashTicket($token),
            'user_id' => $user->id,
            'doctor_id' => $doctor->id,
            'doctor_device_id' => $device->id,
            'doctor_device_authorization_id' => $authorization->id,
            'expires_at' => $expiresAt,
        ])->save();

        return [$token, $expiresAt];
    }

    private function outcomeFor(DoctorDeviceAuthorization $authorization): string
    {
        return match (true) {
            $authorization->isActive() => self::OUTCOME_ACTIVE,
            $authorization->isRejected() => self::OUTCOME_REJECTED,
            $authorization->isRevoked() => self::OUTCOME_REVOKED,
            default => self::OUTCOME_PENDING,
        };
    }

    /**
     * One opaque failure for every reason.
     *
     * The reason lives only in the audit trail. A client that could tell
     * "wrong password" from "unknown device" from "not a doctor" would be a
     * free reconnaissance tool for the whole estate.
     */
    private function deny(string $reason): never
    {
        $this->auditLogs->log(
            'trx_doctor_device_login_challenges',
            null,
            'DOCTOR_APP_LOGIN_DEVICE_PROOF_REJECTED',
            null,
            ['reason' => $reason],
            null,
        );

        throw ValidationException::withMessages([
            'login' => 'Login perangkat gagal.',
        ]);
    }
}
