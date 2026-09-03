<?php

namespace App\Modules\DoctorDevice\Api;

use App\Http\Controllers\Controller;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceEnrollment;
use App\Modules\DoctorDevice\Requests\DeviceChallengeRequest;
use App\Modules\DoctorDevice\Requests\DeviceEnrollmentRequest;
use App\Modules\DoctorDevice\Requests\DeviceProofRequest;
use App\Modules\DoctorDevice\Requests\DoctorAppLoginChallengeRequest;
use App\Modules\DoctorDevice\Requests\DoctorAppLoginRequest;
use App\Modules\DoctorDevice\Services\DoctorAppLoginService;
use App\Modules\DoctorDevice\Services\DoctorDeviceEnrollmentService;
use App\Modules\DoctorDevice\Services\DoctorDeviceProofService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3 — device channel.
 *
 * Thin. All protocol rules live in DoctorDeviceEnrollmentService and
 * DoctorDeviceProofService.
 *
 * RESPONSE DISCIPLINE
 *  - Never echo key material, signatures, nonces beyond the one just issued,
 *    pairing codes beyond the single issuing response, or anything clinical.
 *  - Never confirm or deny the existence of a device to an unproven caller: the
 *    challenge endpoint answers identically whether the fingerprint is unknown,
 *    disabled or revoked, so it cannot be used to enumerate the estate.
 *  - NOTHING here authenticates a Doctor.
 */
class DoctorDeviceApiController extends Controller
{
    public function __construct(
        private readonly DoctorDeviceEnrollmentService $enrollments,
        private readonly DoctorDeviceProofService $proofs,
        private readonly DoctorAppLoginService $logins,
    ) {}

    /** Step 1 — pairing request. The pairing code is returned exactly once. */
    public function requestEnrollment(DeviceEnrollmentRequest $request): JsonResponse
    {
        ['enrollment' => $enrollment, 'pairing_code' => $code] = $this->enrollments->request($request->validated());

        return response()->json([
            'enrollment_uuid' => $enrollment->uuid,
            // Shown on the device screen for the administrator to match. Stored
            // server-side only as a hash — this is the only time it exists in a
            // response body.
            'pairing_code' => $code,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
            'status' => $enrollment->status,
        ], 201);
    }

    /**
     * Step 2 — the device polls its own enrolment by unguessable uuid.
     *
     * Returns the coarse state the Android client needs to pick a screen
     * (pending / approved / rejected, and once bound, the device's
     * administrative status so it can render blocked vs revoked).
     */
    public function enrollmentStatus(string $uuid): JsonResponse
    {
        $enrollment = DoctorDeviceEnrollment::query()->where('uuid', $uuid)->first();

        if ($enrollment === null) {
            return response()->json(['message' => 'Pendaftaran tidak ditemukan.'], 404);
        }

        $device = $enrollment->device;

        return response()->json([
            'enrollment_uuid' => $enrollment->uuid,
            'status' => $enrollment->isExpired() && $enrollment->isPending() ? 'expired' : $enrollment->status,
            'expires_at' => $enrollment->expires_at?->toIso8601String(),
            'device' => $device === null ? null : [
                'uuid' => $device->uuid,
                'device_name' => $device->device_name,
                'status' => $device->status,
                'enrollment_status' => $device->enrollment_status,
                'trustworthy' => $this->proofs->isTrustworthy($device),
            ],
        ]);
    }

    /**
     * Step 3 — request a nonce to sign.
     *
     * A device is located by its own public-key fingerprint. Every failure mode
     * (unknown, unbound, disabled, revoked) returns the SAME 422 so the endpoint
     * leaks nothing about the estate.
     */
    public function challenge(DeviceChallengeRequest $request): JsonResponse
    {
        $device = DoctorDevice::query()
            ->where('public_key_fingerprint', $request->validated()['fingerprint'])
            ->first();

        if ($device === null) {
            return $this->opaqueDenial();
        }

        try {
            $challenge = $this->proofs->issueChallenge($device);
        } catch (ValidationException) {
            return $this->opaqueDenial();
        }

        return response()->json([
            'nonce' => $challenge->nonce,
            'purpose' => $challenge->purpose,
            'expires_at' => $challenge->expires_at?->toIso8601String(),
        ]);
    }

    /** Step 4 — submit the signature. Success proves registered clinic hardware. */
    public function proof(DeviceProofRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $device = $this->proofs->verifyProof($data['nonce'], $data['signature']);
        } catch (ValidationException) {
            return $this->opaqueDenial();
        }

        return response()->json([
            'verified' => true,
            'device' => [
                'uuid' => $device->uuid,
                'device_name' => $device->device_name,
                'status' => $device->status,
                'enrollment_status' => $device->enrollment_status,
                'identity_state' => $device->identity_state,
                'branch' => $device->branch?->name,
            ],
            // Said plainly so no client author mistakes device trust for a login.
            'note' => 'Device identity verified. This does not authenticate a user session.',
        ]);
    }

    /**
     * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — a nonce for a
     * doctor login attempt.
     *
     * Separate from `challenge` above because the first login from new hardware
     * happens before any device row exists, so this one is keyed by the public
     * key fingerprint. Same opaque-denial discipline: unknown, revoked and
     * malformed all answer identically.
     */
    public function loginChallenge(DoctorAppLoginChallengeRequest $request): JsonResponse
    {
        try {
            $challenge = $this->logins->issueChallenge($request->validated()['fingerprint']);
        } catch (ValidationException) {
            return $this->opaqueDenial();
        }

        return response()->json([
            'nonce' => $challenge->nonce,
            'purpose' => $challenge->purpose,
            'expires_at' => $challenge->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * A doctor login attempt from the Clinic App.
     *
     * WHAT THIS RESPONSE IS NOT: a session. No cookie is set and no guard is
     * logged in. `login_ticket` appears only when enforcement is on and the
     * doctor/device pair is already ACTIVE, and it is a single-use, seconds-long
     * receipt the WebView redeems — never a credential the client asserts.
     *
     * The doctor's own name is echoed so the app can show who it is waiting for.
     * Nothing clinical, no KTP/NIK, no key material, no other doctor's data.
     */
    public function doctorLogin(DoctorAppLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->logins->attempt($request->validated());
        } catch (ValidationException) {
            return $this->opaqueDenial();
        }

        return response()->json([
            'outcome' => $result['outcome'],
            'authorization_uuid' => $result['authorization']->uuid,
            'doctor' => ['name' => $result['doctor']->name],
            'device' => [
                'uuid' => $result['device']->uuid,
                'device_name' => $result['device']->device_name,
                'status' => $result['device']->status,
                'branch' => $result['device']->branch?->name,
            ],
            // Said plainly so no client author mistakes capability for rollout.
            'enforcement_active' => $result['enforcement_active'],
            'login_ticket' => $result['login_ticket'],
            'login_ticket_expires_at' => $result['login_ticket_expires_at']?->toIso8601String(),
        ]);
    }

    /**
     * The app polls its own authorization by its unguessable uuid. Coarse state
     * only — enough to choose a screen, never enough to learn about anyone else.
     */
    public function doctorAuthorizationStatus(string $uuid): JsonResponse
    {
        $authorization = $this->logins->statusByUuid($uuid);

        if ($authorization === null) {
            return response()->json(['message' => 'Otorisasi tidak ditemukan.'], 404);
        }

        return response()->json([
            'authorization_uuid' => $authorization->uuid,
            'status' => $authorization->status,
            'device' => $authorization->device === null ? null : [
                'uuid' => $authorization->device->uuid,
                'device_name' => $authorization->device->device_name,
                'status' => $authorization->device->status,
            ],
        ]);
    }

    private function opaqueDenial(): JsonResponse
    {
        return response()->json(['verified' => false, 'message' => 'Verifikasi perangkat gagal.'], 422);
    }
}
