<?php

namespace App\Modules\DoctorDevice\Services;

use App\Models\User;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceAuthorization;
use App\Modules\DoctorDevice\Models\DoctorDeviceLoginTicket;
use App\Modules\DoctorDevice\Support\DoctorSessionProof;
use App\Modules\LabOrder\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — turning a ticket into
 * a device-bound session, and tearing one down when the trust behind it goes.
 *
 * REDEMPTION RE-ASSERTS EVERYTHING
 *
 * A ticket is minted only when enforcement is on and every gate is ACTIVE, and
 * then all of it is checked AGAIN here. That matters because the two moments are
 * different moments: a device can be revoked in the second between minting and
 * redemption, and the honest answer to "was it allowed a moment ago?" is not
 * "so it is allowed now".
 *
 * With enforcement OFF redemption always fails closed. It cannot succeed by
 * accident, because no ticket exists to redeem — and even if one did, the first
 * check would refuse it.
 */
class DoctorDeviceSessionService
{
    public function __construct(
        private readonly DoctorAppLoginGate $gate,
        private readonly DoctorAppLoginService $logins,
        private readonly DoctorDeviceAuthorizationService $authorizations,
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * Redeem a one-time login ticket and establish a device-bound session.
     *
     * @throws ValidationException on any failure, always with the same message
     */
    public function redeem(string $token, Request $request): User
    {
        if (! $this->gate->enforcementEnabled()) {
            // Not merely "no ticket will exist": redemption is refused outright
            // so that switching the flag off can never leave a usable side door.
            $this->deny(null, 'enforcement_disabled');
        }

        $token = trim($token);

        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->deny(null, 'malformed_ticket');
        }

        $hash = $this->logins->hashTicket($token);

        /** @var array{ticket: DoctorDeviceLoginTicket, claimed: bool}|null $claim */
        $claim = DB::transaction(function () use ($hash) {
            $ticket = DoctorDeviceLoginTicket::query()->lockForUpdate()->where('token_hash', $hash)->first();

            if ($ticket === null) {
                return null;
            }

            if (! $ticket->isUsable()) {
                return ['ticket' => $ticket, 'claimed' => false];
            }

            $ticket->forceFill(['consumed_at' => now()])->save();

            return ['ticket' => $ticket, 'claimed' => true];
        });

        if ($claim === null) {
            $this->deny(null, 'unknown_ticket');
        }

        /** @var DoctorDeviceLoginTicket $ticket */
        $ticket = $claim['ticket'];

        if ($claim['claimed'] !== true) {
            $this->deny($ticket, $ticket->isConsumed() ? 'ticket_replayed' : 'ticket_expired');
        }

        $user = User::query()->find($ticket->user_id);
        $device = DoctorDevice::query()->find($ticket->doctor_device_id);
        $authorization = DoctorDeviceAuthorization::query()->find($ticket->doctor_device_authorization_id);

        if ($user === null || $device === null || $authorization === null) {
            $this->deny($ticket, 'ticket_subject_missing');
        }

        // The Clinic App's own proof, asserted as itself. A WebAuthn
        // credential on this tablet is a different proof for a different path
        // and never redeems an Android ticket.
        $proof = DoctorSessionProof::androidKeystore();

        if (! $this->gate->deviceUsableForProof($device, $proof)) {
            $this->deny($ticket, 'device_not_usable');
        }

        if (! $authorization->isActive()
            || (int) $authorization->doctor_id !== (int) $ticket->doctor_id
            || (int) $authorization->doctor_device_id !== (int) $device->id) {
            $this->deny($ticket, 'authorization_not_active');
        }

        Auth::guard('web')->login($user);

        // Session fixation defence, exactly as the ordinary login does. The
        // binding is written AFTER regeneration so it lands in the new session.
        $request->session()->regenerate();

        $this->bind($request, (int) $device->id, (int) $authorization->id, (int) $ticket->doctor_id, $proof);

        $this->authorizations->markAuthorizedLogin($authorization);

        $this->auditLogs->log(
            'mst_doctor_device_authorizations',
            (int) $authorization->id,
            'DOCTOR_APP_LOGIN_AUTHORIZATION_SUCCESS',
            null,
            ['doctor_device_id' => $device->id, 'doctor_id' => $ticket->doctor_id],
            $user,
        );

        return $user;
    }

    /**
     * DOCTOR-PWA-WEBAUTHN-1 — park a password-verified login while the BROWSER
     * proves which device it is running on.
     *
     * Lives here, next to `bind()` and `invalidate()`, because this class is
     * the sanctioned owner of doctor session writes. The login controller may
     * consult the gate and act through this service, and nothing else — see
     * `authentication_coupled_only_through_the_gate`.
     *
     * The caller must have torn the authenticated session down FIRST. This
     * writes a note of which account passed its password; it is not a session,
     * it grants nothing, and the assertion still has to verify.
     */
    public function beginDeviceCredentialLogin(Request $request, User $user): void
    {
        app(DoctorDeviceWebAuthnLoginService::class)->beginPending($request, $user);
    }

    /**
     * Write the server-side binding, including WHICH proof earned it.
     *
     * DOCTOR-PWA-WEBAUTHN-PROOF-BINDING-1 — the proof is a required argument,
     * not an optional extra with a permissive default. A caller that forgets it
     * does not silently produce a session validated by the wrong rules; it does
     * not compile. That is the point: on a dual-proof device the difference
     * between "the proof that ran" and "a proof the device holds" is the
     * difference between a kill switch that works and one that does not.
     */
    public function bind(
        Request $request,
        int $deviceId,
        int $authorizationId,
        int $doctorId,
        DoctorSessionProof $proof,
    ): void {
        $request->session()->put(DoctorAppLoginGate::SESSION_DEVICE_ID, $deviceId);
        $request->session()->put(DoctorAppLoginGate::SESSION_AUTHORIZATION_ID, $authorizationId);
        $request->session()->put(DoctorAppLoginGate::SESSION_DOCTOR_ID, $doctorId);
        $request->session()->put(DoctorAppLoginGate::SESSION_BOUND_AT, now()->toIso8601String());
        $request->session()->put(DoctorAppLoginGate::SESSION_PROOF_TYPE, $proof->type());
        $request->session()->put(
            DoctorAppLoginGate::SESSION_WEBAUTHN_CREDENTIAL_ID,
            $proof->webAuthnCredentialId(),
        );
    }

    /**
     * Stop a doctor session whose device or authorization is no longer valid.
     *
     * Scoped to the one session in front of us. There is deliberately no
     * "log everyone out" here: a revocation is about one tablet, and a blunt
     * global invalidation would turn a security action into an outage.
     */
    public function invalidate(Request $request, ?User $user, string $reason): void
    {
        if ($user !== null) {
            $this->auditLogs->log(
                'users',
                (int) $user->id,
                'DOCTOR_SESSION_DEVICE_INVALIDATED',
                null,
                ['reason' => $reason],
                $user,
            );
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    private function deny(?DoctorDeviceLoginTicket $ticket, string $reason): never
    {
        $this->auditLogs->log(
            'trx_doctor_device_login_tickets',
            $ticket === null ? null : (int) $ticket->id,
            'DOCTOR_APP_LOGIN_AUTHORIZATION_REJECTED',
            null,
            ['reason' => $reason],
            null,
        );

        throw ValidationException::withMessages([
            'login' => 'Sesi perangkat tidak dapat dibuat.',
        ]);
    }
}
