<?php

namespace App\Modules\DoctorDevice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Requests\DoctorDeviceWebAuthnRegistrationRequest;
use App\Modules\DoctorDevice\Requests\DoctorDeviceWebAuthnRevokeRequest;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnRegistrationService;
use App\Modules\DoctorDevice\Support\WebAuthnCeremonyFactory;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — enrolling a browser onto a clinic device.
 *
 * Thin by contract: every decision is in the service, every authorization is a
 * policy check, and this class only translates HTTP into those.
 *
 * Authorization reuses the existing DoctorDevice policy rather than inventing a
 * permission. The actor who may enrol a credential onto a device is exactly the
 * actor who may create, approve, disable and revoke that device — inventing a
 * second permission for the same human would be a label, not a control.
 */
class DoctorDeviceWebAuthnController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DoctorDeviceWebAuthnRegistrationService $registrations,
    ) {}

    /** The enrolment page, opened ON the tablet being enrolled. */
    public function create(DoctorDevice $doctorDevice): View
    {
        $this->authorize('update', $doctorDevice);

        $relyingParty = WebAuthnRelyingParty::fromConfig();

        return view('settings.doctor-devices.webauthn', [
            'device' => $doctorDevice,
            'credentials' => $doctorDevice->webAuthnCredentials()->orderBy('id')->get(),
            'configurationFailure' => $relyingParty->usabilityFailure(),
            'requiresDeviceBound' => (bool) config('webauthn.device_binding.require_device_bound', true),
        ]);
    }

    /**
     * Options for `navigator.credentials.create()`.
     *
     * Serialized by the library so the wire format is the spec's, not ours.
     */
    public function options(DoctorDevice $doctorDevice, Request $request): JsonResponse
    {
        $this->authorize('update', $doctorDevice);

        $result = $this->registrations->creationOptions($doctorDevice, $request->user(), $request);

        $factory = new WebAuthnCeremonyFactory(WebAuthnRelyingParty::fromConfig());

        return response()->json(
            json_decode(
                $factory->serializer()->serialize($result['options'], 'json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            )
        );
    }

    public function store(
        DoctorDevice $doctorDevice,
        DoctorDeviceWebAuthnRegistrationRequest $request,
    ): RedirectResponse {
        $this->authorize('update', $doctorDevice);

        $credential = $this->registrations->register(
            $doctorDevice,
            $request->user(),
            $request,
            $request->credentialPayload(),
        );

        return redirect()
            ->route('settings.doctor-devices.webauthn.create', $doctorDevice)
            ->with('success', 'Kredensial perangkat berhasil didaftarkan ('.$credential->device_bound_verdict.').');
    }

    public function revoke(
        DoctorDevice $doctorDevice,
        DoctorDeviceWebAuthnCredential $credential,
        DoctorDeviceWebAuthnRevokeRequest $request,
    ): RedirectResponse {
        $this->authorize('update', $doctorDevice);

        abort_unless((int) $credential->doctor_device_id === (int) $doctorDevice->id, 404);

        $this->registrations->revoke($credential, $request->user(), $request->string('reason')->toString());

        return redirect()
            ->route('settings.doctor-devices.webauthn.create', $doctorDevice)
            ->with('success', 'Kredensial dicabut. Pendaftaran ulang diperlukan untuk memakai perangkat ini lagi.');
    }
}
